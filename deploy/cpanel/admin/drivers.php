<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
require_once dirname(__DIR__).'/rado-system/lib/api_ir.php';
ra_require_admin();
$pdo=rado_db();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  ra_require_csrf();
  try{
    $id=trim((string)($_POST['driver_id']??''));$status=trim((string)($_POST['status']??''));$rate=(float)($_POST['commission_rate']??-1);
    if($id===''||!in_array($status,['pending','approved','suspended','rejected'],true)||$rate<0||$rate>100)throw new RuntimeException('اطلاعات راننده معتبر نیست.');
    if($status==='approved'){
      if(!ra_table_exists($pdo,'driver_verification_profiles'))throw new RuntimeException('ابتدا Migration احراز هویت را اجرا کنید.');
      $missing=rado_verification_missing($pdo,$id,true);
      if($missing!==[])throw new RuntimeException('تأیید مستقیم مجاز نیست؛ پرونده احراز هویت ناقص است: '.implode('، ',$missing));
    }
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET full_name=? WHERE id=? AND role='driver'")->execute([trim((string)($_POST['full_name']??''))?:'راننده رادو',$id]);
    $pdo->prepare("UPDATE drivers SET status=?,commission_rate=?,plate_number=?,vehicle_make=?,vehicle_model=?,vehicle_color=?,approved_at=CASE WHEN ?='approved' THEN COALESCE(approved_at,NOW()) ELSE approved_at END WHERE user_id=?")->execute([$status,$rate,trim((string)($_POST['plate_number']??''))?:null,trim((string)($_POST['vehicle_make']??''))?:null,trim((string)($_POST['vehicle_model']??''))?:null,trim((string)($_POST['vehicle_color']??''))?:null,$status,$id]);
    if($status!=='approved')$pdo->prepare('UPDATE driver_presence SET is_online=0 WHERE driver_id=?')->execute([$id]);
    $pdo->commit();$message='اطلاعات راننده بروزرسانی شد — '.rado_jalali_datetime();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$drivers=$pdo->query("SELECT d.user_id,d.status,d.commission_rate,d.plate_number,d.vehicle_make,d.vehicle_model,d.vehicle_color,u.full_name,u.phone,p.is_online,p.last_seen_at,(SELECT COALESCE(SUM(le.amount),0) FROM ledger_entries le WHERE le.user_id=d.user_id) wallet_balance FROM drivers d JOIN users u ON u.id=d.user_id LEFT JOIN driver_presence p ON p.driver_id=d.user_id ORDER BY FIELD(d.status,'pending','approved','suspended','rejected'),u.created_at DESC LIMIT 250")->fetchAll();
$counts=['pending'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers WHERE status='pending'"),'approved'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers WHERE status='approved'"),'online'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)"),'docs'=>ra_table_exists($pdo,'driver_documents')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM driver_documents WHERE status='pending'"):0];
ra_header('رانندگان','drivers','تأیید، خودرو، کمیسیون و وضعیت آنلاین');
if($message)echo '<div class="notice ok">'.ra_e($message).'</div>';if($error)echo '<div class="notice err">'.ra_e($error).'</div>';
?>
<div class="grid"><div class="card span3 metric"><small>تأییدشده</small><b><?=$counts['approved']?></b></div><div class="card span3 metric"><small>آنلاین</small><b><?=$counts['online']?></b></div><div class="card span3 metric"><small>در انتظار تأیید</small><b><?=$counts['pending']?></b></div><div class="card span3 metric"><small>مدرک در انتظار</small><b><?=$counts['docs']?></b></div></div>
<div class="card" style="margin-top:14px"><div class="tablewrap"><table class="table"><tr><th>راننده</th><th>تماس</th><th>خودرو</th><th>پلاک</th><th>آنلاین</th><th>کیف پول</th><th>ویرایش</th></tr><?php foreach($drivers as $d):?><tr><td><?=ra_e((string)$d['full_name'])?><br><span class="badge"><?=ra_e((string)$d['status'])?></span></td><td><?=ra_e((string)($d['phone']??'—'))?></td><td><?=ra_e(trim((string)($d['vehicle_make']??'').' '.(string)($d['vehicle_model']??''))?:'—')?></td><td><?=ra_e((string)($d['plate_number']??'—'))?></td><td><span class="badge <?=!empty($d['is_online'])?'green':''?>"><?=!empty($d['is_online'])?'آنلاین':'آفلاین'?></span><br><small><?=ra_e(ra_date($d['last_seen_at']??null))?></small></td><td><?=ra_money($d['wallet_balance'])?></td><td><a class="btn goldbtn" href="/admin/verification.php?driver_id=<?=ra_e((string)$d['user_id'])?>">احراز هویت</a> <details style="display:inline-block"><summary class="btn">ویرایش</summary><form method="post" style="min-width:280px;margin-top:10px"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="driver_id" value="<?=ra_e((string)$d['user_id'])?>"><div class="formgrid"><div class="field full"><label>نام</label><input name="full_name" value="<?=ra_e((string)$d['full_name'])?>"></div><div class="field"><label>وضعیت</label><select name="status"><?php foreach(['pending'=>'در انتظار','approved'=>'تأیید','suspended'=>'تعلیق','rejected'=>'رد'] as $k=>$v):?><option value="<?=$k?>" <?=$d['status']===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><div class="field"><label>کمیسیون %</label><input name="commission_rate" type="number" step="0.1" value="<?=ra_e((string)$d['commission_rate'])?>"></div><div class="field"><label>برند</label><input name="vehicle_make" value="<?=ra_e((string)($d['vehicle_make']??''))?>"></div><div class="field"><label>مدل</label><input name="vehicle_model" value="<?=ra_e((string)($d['vehicle_model']??''))?>"></div><div class="field"><label>رنگ</label><input name="vehicle_color" value="<?=ra_e((string)($d['vehicle_color']??''))?>"></div><div class="field"><label>پلاک</label><input name="plate_number" value="<?=ra_e((string)($d['plate_number']??''))?>"></div><div class="full"><button class="btn goldbtn">ذخیره</button></div></div></form></details></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
