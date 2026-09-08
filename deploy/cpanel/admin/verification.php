<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
require_once dirname(__DIR__).'/rado-system/lib/api_ir.php';
require_once dirname(__DIR__).'/rado-system/lib/platform.php';
ra_require_admin();
$pdo=rado_db();
$message='';$error='';
function rv_admin_mask(string $v,int $left=3,int $right=2):string{$v=trim($v);$n=mb_strlen($v,'UTF-8');if($n<=$left+$right)return $v;return mb_substr($v,0,$left,'UTF-8').str_repeat('•',max(3,$n-$left-$right)).mb_substr($v,-$right,null,'UTF-8');}
function rv_admin_status(string $s):string{return match($s){'passed','approved'=>'تأیید','failed','rejected'=>'رد','review','under_review'=>'بررسی','submitted'=>'ارسال‌شده','needs_correction'=>'نیاز به اصلاح','suspended'=>'تعلیق','pending'=>'در انتظار','expired'=>'منقضی','missing'=>'ثبت نشده',default=>'شروع نشده'};}
function rv_admin_badge(string $s):string{$c=in_array($s,['passed','approved'],true)?'green':(in_array($s,['failed','rejected','suspended','expired'],true)?'red':(in_array($s,['pending','review','under_review','submitted','needs_correction'],true)?'yellow':''));return '<span class="badge '.$c.'">'.ra_e(rv_admin_status($s)).'</span>';}

if(!ra_table_exists($pdo,'driver_verification_profiles')){
  ra_header('احراز هویت رانندگان','verification','Driver Verification Center');
  echo '<div class="notice err">Migration احراز هویت هنوز روی دیتابیس اجرا نشده است. از بخش سیستم، Migration/Update را اجرا کنید.</div>';
  ra_footer();exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  ra_require_csrf();
  try{
    $action=(string)($_POST['action']??'');
    $driverId=trim((string)($_POST['driver_id']??''));
    if($driverId==='')throw new RuntimeException('راننده مشخص نشده است.');
    $profile=rado_verification_profile($pdo,$driverId);
    $adminId=(string)($_SESSION['admin_id']??'');
    if($action==='document_review'){
      $docId=(int)($_POST['document_id']??0);$status=(string)($_POST['document_status']??'');$note=mb_substr(trim((string)($_POST['note']??'')),0,400,'UTF-8');
      if($docId<1||!in_array($status,['approved','rejected'],true))throw new RuntimeException('وضعیت مدرک معتبر نیست.');
      $q=$pdo->prepare('SELECT id FROM driver_documents WHERE id=? AND driver_id=? LIMIT 1');$q->execute([$docId,$driverId]);if(!$q->fetchColumn())throw new RuntimeException('مدرک متعلق به این راننده نیست.');
      $pdo->prepare('UPDATE driver_documents SET status=?,note=? WHERE id=? AND driver_id=?')->execute([$status,$note?:null,$docId,$driverId]);
      if($status==='rejected'){
        $type=(string)($_POST['field_key']??'document');
        $txt=$note!==''?$note:'مدرک خوانا یا معتبر نیست؛ دوباره ارسال کنید.';
        $pdo->prepare("INSERT INTO driver_verification_corrections(driver_id,field_key,message,status,created_by_admin_id) VALUES(?,?,?,'open',?)")->execute([$driverId,$type,$txt,$adminId?:null]);
        $pdo->prepare("UPDATE driver_verification_profiles SET review_status='needs_correction',review_note=?,reviewer_admin_id=?,reviewed_at=NOW() WHERE driver_id=?")->execute([$txt,$adminId?:null,$driverId]);
      }
      $message='وضعیت مدرک ذخیره شد.';
    }elseif($action==='request_correction'){
      $field=mb_substr(trim((string)($_POST['field_key']??'general')),0,80,'UTF-8');$text=mb_substr(trim((string)($_POST['message']??'')),0,700,'UTF-8');
      if($text==='')throw new RuntimeException('توضیح مورد اصلاحی را وارد کنید.');
      $pdo->prepare("INSERT INTO driver_verification_corrections(driver_id,field_key,message,status,created_by_admin_id) VALUES(?,?,?,'open',?)")->execute([$driverId,$field?:'general',$text,$adminId?:null]);
      $pdo->prepare("UPDATE driver_verification_profiles SET review_status='needs_correction',review_note=?,reviewer_admin_id=?,reviewed_at=NOW() WHERE driver_id=?")->execute([$text,$adminId?:null,$driverId]);
      $pdo->prepare("UPDATE drivers SET status='pending' WHERE user_id=? AND status<>'suspended'")->execute([$driverId]);
      try{rado_platform_notify($pdo,$driverId,'احراز هویت نیاز به اصلاح دارد',$text,'verification_correction',['field'=>$field]);}catch(Throwable){}
      $message='مورد اصلاحی برای راننده ارسال شد.';
    }elseif($action==='set_review_status'){
      $status=(string)($_POST['review_status']??'');$note=mb_substr(trim((string)($_POST['note']??'')),0,700,'UTF-8');
      if(!in_array($status,['under_review','rejected','suspended'],true))throw new RuntimeException('وضعیت بررسی معتبر نیست.');
      $pdo->prepare('UPDATE driver_verification_profiles SET review_status=?,review_note=?,reviewer_admin_id=?,reviewed_at=NOW() WHERE driver_id=?')->execute([$status,$note?:null,$adminId?:null,$driverId]);
      if($status==='rejected'){$pdo->prepare("UPDATE drivers SET status='rejected' WHERE user_id=?")->execute([$driverId]);$pdo->prepare('UPDATE driver_presence SET is_online=0 WHERE driver_id=?')->execute([$driverId]);}
      if($status==='suspended'){$pdo->prepare("UPDATE drivers SET status='suspended' WHERE user_id=?")->execute([$driverId]);$pdo->prepare('UPDATE driver_presence SET is_online=0 WHERE driver_id=?')->execute([$driverId]);}
      if($status==='under_review')$pdo->prepare("UPDATE drivers SET status='pending' WHERE user_id=? AND status='pending'")->execute([$driverId]);
      try{rado_platform_notify($pdo,$driverId,'وضعیت احراز هویت تغییر کرد',rv_admin_status($status).($note!==''?' — '.$note:''),'verification_review',['status'=>$status]);}catch(Throwable){}
      $message='وضعیت پرونده بروزرسانی شد.';
    }elseif($action==='approve'){
      $missing=rado_verification_missing($pdo,$driverId,true);if($missing!==[])throw new RuntimeException('امکان تأیید نهایی نیست: '.implode('، ',$missing));
      $p=rado_verification_profile($pdo,$driverId);$plate=trim((string)$p['plate_part1'].' '.(string)$p['plate_letter'].' '.(string)$p['plate_part2'].' ایران '.(string)$p['plate_part3']);
      $pdo->beginTransaction();
      try{
        $pdo->prepare("UPDATE driver_verification_profiles SET review_status='approved',review_note=NULL,reviewer_admin_id=?,reviewed_at=NOW() WHERE driver_id=?")->execute([$adminId?:null,$driverId]);
        $pdo->prepare("UPDATE users SET full_name=?,is_active=1 WHERE id=? AND role='driver'")->execute([(string)$p['full_name'],$driverId]);
        $pdo->prepare("UPDATE drivers SET status='approved',national_id=?,license_number=?,plate_number=?,vehicle_make=?,vehicle_model=?,vehicle_color=?,approved_at=COALESCE(approved_at,NOW()) WHERE user_id=?")->execute([(string)$p['national_code'],(string)$p['license_number'],$plate,(string)($p['vehicle_make']??''),(string)($p['vehicle_model']??''),(string)($p['vehicle_color']??''),$driverId]);
        $pdo->prepare("UPDATE driver_verification_corrections SET status='resolved',resolved_at=COALESCE(resolved_at,NOW()) WHERE driver_id=? AND status='open'")->execute([$driverId]);
        $pdo->commit();
      }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
      try{rado_platform_notify($pdo,$driverId,'حساب راننده تأیید شد','احراز هویت شما با موفقیت تأیید شد و اکنون می‌توانید آنلاین شوید.','verification_approved',[]);}catch(Throwable){}
      $message='راننده با موفقیت تأیید شد.';
    }else throw new RuntimeException('عملیات ناشناخته است.');
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

$detailId=trim((string)($_GET['driver_id']??$_POST['driver_id']??''));
$counts=[
 'submitted'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM driver_verification_profiles WHERE review_status IN ('submitted','under_review')"),
 'correction'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM driver_verification_profiles WHERE review_status='needs_correction'"),
 'approved'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM driver_verification_profiles WHERE review_status='approved'"),
 'api_errors'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM driver_verification_checks WHERE status='error' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)"),
];
$drivers=$pdo->query("SELECT d.user_id,d.status driver_status,u.full_name,u.phone,v.mobile,v.review_status,v.updated_at,v.submitted_at,v.matching_score,v.liveness_score FROM drivers d JOIN users u ON u.id=d.user_id LEFT JOIN driver_verification_profiles v ON v.driver_id=d.user_id ORDER BY FIELD(COALESCE(v.review_status,'incomplete'),'submitted','under_review','needs_correction','incomplete','approved','rejected','suspended'),COALESCE(v.updated_at,u.updated_at) DESC LIMIT 300")->fetchAll();
ra_header('احراز هویت رانندگان','verification','API.ir · مدارک · بایومتریک · بررسی نهایی');
if($message)echo '<div class="notice ok">'.ra_e($message).'</div>';if($error)echo '<div class="notice err">'.ra_e($error).'</div>';
?>
<div class="grid">
 <div class="card span3 metric"><small>منتظر بررسی</small><b><?=$counts['submitted']?></b></div>
 <div class="card span3 metric"><small>نیاز به اصلاح</small><b><?=$counts['correction']?></b></div>
 <div class="card span3 metric"><small>تأیید کامل</small><b><?=$counts['approved']?></b></div>
 <div class="card span3 metric"><small>خطای API.ir در 24h</small><b><?=$counts['api_errors']?></b></div>
</div>
<div class="subnav"><a class="on" href="/admin/verification.php">پرونده‌ها</a><a href="/admin/verification-settings.php">تنظیمات API.ir</a><a href="/admin/drivers.php">رانندگان</a></div>

<?php if($detailId!==''):
 $q=$pdo->prepare("SELECT u.full_name,u.phone,d.status driver_status,d.commission_rate FROM users u JOIN drivers d ON d.user_id=u.id WHERE u.id=? AND u.role='driver' LIMIT 1");$q->execute([$detailId]);$driverRow=$q->fetch();
 if(is_array($driverRow)):$summary=rado_verification_summary($pdo,$detailId);$p=$summary['profile'];
 $docs=$pdo->prepare('SELECT id,document_type,document_number,expires_at,status,note,created_at FROM driver_documents WHERE driver_id=? ORDER BY id DESC LIMIT 40');$docs->execute([$detailId]);$docRows=$docs->fetchAll();
 $checks=$pdo->prepare('SELECT check_type,status,http_code,provider_code,result_summary_json,error_message,created_at FROM driver_verification_checks WHERE driver_id=? ORDER BY id DESC LIMIT 30');$checks->execute([$detailId]);$checkRows=$checks->fetchAll();
?>
<div class="grid">
 <div class="card span8"><h2 class="section-title"><?=ra_e((string)$driverRow['full_name'])?></h2><div class="muted">شناسه پرونده: <?=ra_e($detailId)?></div>
  <div class="grid">
   <div class="span4"><b>موبایل</b><div><?=ra_e(rv_admin_mask((string)$p['mobile'],4,3))?> <?=$p['mobile_verified']?'<span class="badge green">OTP ✓</span>':''?></div></div>
   <div class="span4"><b>کد ملی</b><div><?=ra_e(rv_admin_mask((string)$p['national_code'],3,2))?></div></div>
   <div class="span4"><b>گواهینامه</b><div><?=ra_e(rv_admin_mask((string)$p['license_number'],2,3))?></div></div>
   <div class="span4"><b>شبا</b><div><?=ra_e(rv_admin_mask((string)$p['iban'],4,4))?></div></div>
   <div class="span4"><b>مالک خودرو</b><div><?=ra_e(rv_admin_mask((string)$p['vehicle_owner_national_code'],3,2))?> · <?=ra_e((string)$p['vehicle_owner_relation'])?></div></div>
   <div class="span4"><b>پلاک</b><div><?=ra_e((string)$p['plate_part1'].' '.(string)$p['plate_letter'].' '.(string)$p['plate_part2'].' ایران '.(string)$p['plate_part3'])?></div></div>
  </div>
 </div>
 <div class="card span4"><h2 class="section-title">وضعیت نهایی</h2><div><?=rv_admin_badge((string)$summary['review_status'])?></div><div style="margin-top:10px">پیشرفت خودکار: <b><?=ra_e((string)$summary['progress'])?>%</b></div><div class="muted">Match: <?=ra_e((string)($summary['scores']['matching']??'—'))?> · Liveness: <?=ra_e((string)($summary['scores']['liveness']??'—'))?> · نمره منفی: <?=ra_e((string)($summary['scores']['driving_negative']??'—'))?></div></div>
</div>
<div class="card" style="margin-top:12px"><h2 class="section-title">استعلام‌های خودکار</h2><div style="display:flex;gap:8px;flex-wrap:wrap">
<?php foreach(['shahkar'=>'شاهکار','biometric'=>'بایومتریک','license'=>'گواهینامه','driving_score'=>'نمره منفی','active_plates'=>'پلاک‌های فعال','vehicle'=>'خودرو','iban'=>'شبا'] as $k=>$label):?><div><?=$label?> <?=rv_admin_badge((string)($summary['checks'][$k]??'not_started'))?></div><?php endforeach;?></div></div>

<div class="grid"><div class="card span8"><h2 class="section-title">مدارک پرونده</h2><div class="tablewrap"><table class="table"><tr><th>نوع</th><th>تاریخ/انقضا</th><th>وضعیت</th><th>مشاهده</th><th>بررسی</th></tr>
<?php foreach($docRows as $d):?><tr><td><?=ra_e((string)$d['document_type'])?><br><small><?=ra_e((string)($d['document_number']??''))?></small></td><td><?=ra_e(ra_date((string)$d['created_at']))?><br><small><?=ra_e((string)($d['expires_at']??''))?></small></td><td><?=rv_admin_badge((string)$d['status'])?><br><small><?=ra_e((string)($d['note']??''))?></small></td><td><a class="btn" target="_blank" href="/admin/driver-document.php?id=<?=(int)$d['id']?>">باز کردن</a></td><td><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="document_review"><input type="hidden" name="driver_id" value="<?=ra_e($detailId)?>"><input type="hidden" name="document_id" value="<?=(int)$d['id']?>"><input type="hidden" name="field_key" value="<?=ra_e((string)$d['document_type'])?>"><input name="note" placeholder="دلیل رد/یادداشت" style="width:150px"><button class="btn goldbtn" name="document_status" value="approved">تأیید</button> <button class="btn" name="document_status" value="rejected">رد</button></form></td></tr><?php endforeach;?>
</table></div></div>
<div class="card span4"><h2 class="section-title">بررسی نهایی</h2>
<?php if($summary['ready_to_approve']):?><div class="notice ok">تمام Gateها و مدارک موردنیاز تأیید شده‌اند.</div><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="driver_id" value="<?=ra_e($detailId)?>"><button class="btn goldbtn" style="width:100%">تأیید نهایی راننده</button></form><?php else:?><div class="notice warn"><b>موارد باقی‌مانده</b><br><?=ra_e(implode('، ',$summary['approval_missing']))?></div><?php endif;?>
<hr style="border:0;border-top:1px solid #eee;margin:14px 0"><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="request_correction"><input type="hidden" name="driver_id" value="<?=ra_e($detailId)?>"><div class="field"><label>بخش نیازمند اصلاح</label><select name="field_key"><option value="identity">هویت</option><option value="mobile">موبایل/شاهکار</option><option value="biometric">بایومتریک</option><option value="license">گواهینامه</option><option value="vehicle">خودرو</option><option value="iban">شبا</option><option value="document">مدارک</option><option value="general">سایر</option></select></div><div class="field"><label>توضیح برای راننده</label><textarea name="message" rows="4" required></textarea></div><button class="btn">ارسال برای اصلاح</button></form>
<hr style="border:0;border-top:1px solid #eee;margin:14px 0"><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="set_review_status"><input type="hidden" name="driver_id" value="<?=ra_e($detailId)?>"><div class="field"><label>اقدام مدیریتی</label><select name="review_status"><option value="under_review">در حال بررسی</option><option value="rejected">رد پرونده</option><option value="suspended">تعلیق راننده</option></select></div><div class="field"><label>یادداشت</label><textarea name="note" rows="2"></textarea></div><button class="btn">ثبت وضعیت</button></form>
</div></div>
<div class="card" style="margin-top:12px"><h2 class="section-title">Audit استعلام‌های API.ir</h2><div class="tablewrap"><table class="table"><tr><th>سرویس</th><th>وضعیت</th><th>HTTP</th><th>خلاصه</th><th>خطا</th><th>زمان</th></tr><?php foreach($checkRows as $c):?><tr><td><?=ra_e((string)$c['check_type'])?></td><td><?=rv_admin_badge((string)$c['status'])?></td><td><?=ra_e((string)($c['http_code']??'—'))?></td><td><small><?=ra_e((string)($c['result_summary_json']??''))?></small></td><td><small><?=ra_e((string)($c['error_message']??''))?></small></td><td><?=ra_e(ra_date((string)$c['created_at']))?></td></tr><?php endforeach;?></table></div></div>
<?php endif; endif;?>

<div class="card" style="margin-top:14px"><h2 class="section-title">همه رانندگان</h2><div class="tablewrap"><table class="table"><tr><th>راننده</th><th>موبایل احراز</th><th>پرونده</th><th>بایومتریک</th><th>وضعیت راننده</th><th>آخرین تغییر</th><th></th></tr>
<?php foreach($drivers as $d):?><tr><td><?=ra_e((string)$d['full_name'])?></td><td><?=ra_e(rv_admin_mask((string)($d['mobile']??''),4,3))?></td><td><?=rv_admin_badge((string)($d['review_status']??'incomplete'))?></td><td><?=(int)($d['matching_score']??0)>0?'M '.(int)$d['matching_score'].' / L '.(int)($d['liveness_score']??0):'—'?></td><td><?=rv_admin_badge((string)$d['driver_status'])?></td><td><?=ra_e(ra_date($d['updated_at']??null))?></td><td><a class="btn" href="/admin/verification.php?driver_id=<?=ra_e((string)$d['user_id'])?>">پرونده</a></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
