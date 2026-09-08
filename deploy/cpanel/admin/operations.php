<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
require_once dirname(__DIR__).'/rado-system/lib/platform.php';
ra_require_admin();
$pdo=rado_db();$message='';$error='';

function ra_ops_audit(PDO $pdo,string $action,string $type,string $id,array $after=[]):void{
  try{$pdo->prepare("INSERT INTO audit_logs(actor_user_id,actor_role,action,entity_type,entity_id,after_json,ip_address) VALUES(?,'admin',?,?,?,?,?)")->execute([(string)$_SESSION['admin_id'],$action,$type,$id,json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)($_SERVER['REMOTE_ADDR']??'')]);}catch(Throwable){}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  ra_require_csrf();
  try{
    $action=(string)($_POST['action']??'');
    if($action==='save_dispatch'){
      foreach(['dispatch_radius_1_km','dispatch_radius_2_km','dispatch_radius_3_km','dispatch_batch_size','dispatch_offer_timeout_seconds','realtime_poll_seconds'] as $k){
        if(isset($_POST[$k]))rado_set_setting($pdo,$k,trim((string)$_POST[$k]));
      }
      $message='تنظیمات Dispatch ذخیره شد.';ra_ops_audit($pdo,$action,'settings','dispatch');
    }
    if($action==='manual_assign'){
      $tripId=trim((string)($_POST['trip_id']??''));$driverId=trim((string)($_POST['driver_id']??''));
      $pdo->beginTransaction();
      $q=$pdo->prepare("SELECT status FROM trips WHERE id=? FOR UPDATE");$q->execute([$tripId]);$status=$q->fetchColumn();
      if(!in_array($status,['requested','searching'],true))throw new RuntimeException('این سفر دیگر قابل تخصیص نیست.');
      $d=$pdo->prepare("SELECT COUNT(*) FROM drivers WHERE user_id=? AND status='approved'");$d->execute([$driverId]);if((int)$d->fetchColumn()!==1)throw new RuntimeException('راننده تأییدشده نیست.');
      $u=$pdo->prepare("UPDATE trips SET driver_id=?,status='driver_assigned',accepted_at=NOW(),version=version+1 WHERE id=? AND driver_id IS NULL AND status IN('requested','searching')");$u->execute([$driverId,$tripId]);if($u->rowCount()!==1)throw new RuntimeException('سفر همزمان تخصیص داده شده؛ صفحه را تازه کنید.');
      $pdo->prepare("UPDATE trip_offers SET accepted=0,responded_at=COALESCE(responded_at,NOW()) WHERE trip_id=? AND driver_id<>? AND accepted IS NULL")->execute([$tripId,$driverId]);
      $pdo->commit();rado_platform_notify($pdo,$driverId,'سفر توسط اپراتور تخصیص یافت','مدیریت RADO یک سفر را به شما اختصاص داد.','trip_assigned',['trip_id'=>$tripId]);rado_platform_event($pdo,'trip:'.$tripId,'manual_assigned',['driver_id'=>$driverId]);ra_ops_audit($pdo,$action,'trip',$tripId,['driver_id'=>$driverId]);$message='راننده به سفر تخصیص یافت.';
    }
    if($action==='cancel_trip'){
      $tripId=trim((string)($_POST['trip_id']??''));$reason=mb_substr(trim((string)($_POST['reason']??'')),0,500,'UTF-8');$refund=max(0,(int)($_POST['refund_amount']??0));
      $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM trips WHERE id=? FOR UPDATE');$q->execute([$tripId]);$trip=$q->fetch();
      if(!is_array($trip)||in_array((string)$trip['status'],['completed','cancelled_by_admin','cancelled_by_passenger','cancelled_by_driver','expired'],true))throw new RuntimeException('سفر قابل لغو نیست.');
      $pdo->prepare("UPDATE trips SET status='cancelled_by_admin',cancelled_at=NOW(),version=version+1 WHERE id=?")->execute([$tripId]);
      if(ra_table_exists($pdo,'trip_cancellation_events'))$pdo->prepare("INSERT INTO trip_cancellation_events(trip_id,actor_user_id,actor_role,reason_code,reason_text,refund_amount) VALUES(?,?,'admin','admin_cancel',?,?)")->execute([$tripId,(string)$_SESSION['admin_id'],$reason?:null,$refund]);
      if($refund>0){$key='refund:trip:'.$tripId.':admin';$ins=$pdo->prepare("INSERT IGNORE INTO ledger_entries(user_id,trip_id,entry_type,amount,idempotency_key,metadata_json) VALUES(?,?,'refund',?,?,?)");$ins->execute([(string)$trip['passenger_id'],$tripId,$refund,$key,json_encode(['reason'=>$reason],JSON_UNESCAPED_UNICODE)]);if($ins->rowCount()===1&&function_exists('rado_sync_wallet_cache'))rado_sync_wallet_cache($pdo,(string)$trip['passenger_id']);if(ra_table_exists($pdo,'refunds'))$pdo->prepare("INSERT IGNORE INTO refunds(trip_id,user_id,amount,reason,status,idempotency_key,created_by_admin_id,completed_at) VALUES(?,?,?,?,'completed',?,?,NOW())")->execute([$tripId,(string)$trip['passenger_id'],$refund,$reason?:null,$key,(string)$_SESSION['admin_id']]);}
      $pdo->commit();if(!empty($trip['driver_id']))rado_platform_notify($pdo,(string)$trip['driver_id'],'سفر لغو شد','این سفر توسط مدیریت RADO لغو شد.','trip_cancelled',['trip_id'=>$tripId]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'سفر لغو شد','سفر شما توسط پشتیبانی RADO لغو شد.','trip_cancelled',['trip_id'=>$tripId]);ra_ops_audit($pdo,$action,'trip',$tripId,['refund'=>$refund]);$message='سفر لغو شد'.($refund>0?' و بازپرداخت ثبت شد.':'.');
    }
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$searching=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('requested','searching')");
$active=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('driver_assigned','driver_arriving','arrived','in_progress')");
$online=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)");
$offers=ra_table_exists($pdo,'trip_offers')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trip_offers WHERE status='pending' AND expires_at>NOW()"):0;
$waiting=$pdo->query("SELECT t.id,t.pickup_label,t.destination_label,t.estimated_fare,t.requested_at,pu.full_name passenger_name FROM trips t LEFT JOIN users pu ON pu.id=t.passenger_id WHERE t.status IN('requested','searching') ORDER BY t.requested_at ASC LIMIT 40")->fetchAll();
$activeTrips=$pdo->query("SELECT t.id,t.status,t.pickup_label,t.destination_label,t.estimated_fare,t.final_fare,t.driver_id,du.full_name driver_name FROM trips t LEFT JOIN users du ON du.id=t.driver_id WHERE t.status IN('driver_assigned','driver_arriving','arrived','in_progress') ORDER BY t.requested_at DESC LIMIT 40")->fetchAll();
$drivers=$pdo->query("SELECT d.user_id,u.full_name,d.plate_number,p.is_online,p.last_seen_at FROM drivers d JOIN users u ON u.id=d.user_id LEFT JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' ORDER BY p.is_online DESC,p.last_seen_at DESC LIMIT 200")->fetchAll();
$setting=fn(string $k,string $d='')=>(string)(rado_setting($pdo,$k,$d)??$d);
ra_header('عملیات و Dispatch','operations','کنترل تخصیص سفر، صف درخواست‌ها و عملیات زنده');
if($message)echo '<div class="notice ok">'.ra_e($message).'</div>';if($error)echo '<div class="notice err">'.ra_e($error).'</div>';
?>
<div class="grid"><div class="card span3 metric"><small>در جستجوی راننده</small><b><?=$searching?></b></div><div class="card span3 metric"><small>سفر فعال</small><b><?=$active?></b></div><div class="card span3 metric"><small>راننده آنلاین</small><b><?=$online?></b></div><div class="card span3 metric"><small>Offer فعال</small><b><?=$offers?></b></div></div>
<div class="subnav"><a class="on" href="/admin/operations.php">کنترل عملیات</a><a href="/admin/live-map.php">نقشه زنده</a><a href="/admin/trips.php?status=searching">همه درخواست‌ها</a><a href="/admin/drivers.php">رانندگان</a></div>
<?php if($searching>0&&$online===0):?><div class="notice err">سفر در انتظار وجود دارد ولی راننده آنلاین تازه دیده نمی‌شود؛ Presence/GPS راننده را بررسی کن.</div><?php endif;?>
<?php if($searching>0&&$online>0&&$offers===0):?><div class="notice warn">راننده آنلاین و سفر در انتظار داریم ولی Offer فعال صفر است؛ Dispatch و فیلتر رانندگان را بررسی کن.</div><?php endif;?>
<div class="grid">
 <div class="card span6"><h2 class="section-title">تنظیم Dispatch</h2><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="save_dispatch"><div class="formgrid"><?php foreach(['dispatch_radius_1_km'=>'شعاع مرحله ۱ (km)','dispatch_radius_2_km'=>'شعاع مرحله ۲','dispatch_radius_3_km'=>'شعاع مرحله ۳','dispatch_batch_size'=>'راننده در هر Batch','dispatch_offer_timeout_seconds'=>'مهلت قبول (ثانیه)','realtime_poll_seconds'=>'Polling fallback (ثانیه)'] as $k=>$label):?><div class="field"><label><?=$label?></label><input name="<?=$k?>" value="<?=ra_e($setting($k))?>"></div><?php endforeach;?><div class="full"><button class="btn goldbtn">ذخیره Dispatch</button></div></div></form></div>
 <div class="card span6"><h2 class="section-title">راهنمای سریع</h2><p class="muted">تخصیص دستی فقط برای سفرهای در حالت «درخواست/جستجو» انجام می‌شود. لغو مدیریتی برای سفر تکمیل‌شده مجاز نیست. بازپرداخت در صورت واردکردن مبلغ، در Ledger ثبت می‌شود.</p><div class="subnav"><a href="/admin/live-map.php">مشاهده نقشه زنده</a><a href="/admin/system.php">سلامت سیستم</a></div></div>
</div>
<div class="card" style="margin-top:12px"><h2 class="section-title">صف سفرهای بدون راننده</h2><div class="tablewrap"><table class="table"><tr><th>زمان</th><th>مسافر</th><th>مسیر</th><th>کرایه</th><th>تخصیص دستی</th><th>لغو</th></tr><?php foreach($waiting as $t):?><tr><td><?=ra_e(ra_date($t['requested_at']))?></td><td><?=ra_e((string)($t['passenger_name']??'مسافر'))?></td><td><?=ra_e((string)$t['pickup_label'])?> ← <?=ra_e((string)$t['destination_label'])?></td><td><?=ra_money($t['estimated_fare'])?></td><td><form method="post" style="display:flex;gap:5px;min-width:270px"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="manual_assign"><input type="hidden" name="trip_id" value="<?=ra_e((string)$t['id'])?>"><select name="driver_id" required style="min-width:170px"><option value="">انتخاب راننده</option><?php foreach($drivers as $d):?><option value="<?=ra_e((string)$d['user_id'])?>"><?=!empty($d['is_online'])?'● ':'○ '?><?=ra_e((string)$d['full_name'])?> — <?=ra_e((string)($d['plate_number']??''))?></option><?php endforeach;?></select><button class="btn goldbtn">تخصیص</button></form></td><td><form method="post" style="display:flex;gap:5px;min-width:260px"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="cancel_trip"><input type="hidden" name="trip_id" value="<?=ra_e((string)$t['id'])?>"><input name="reason" placeholder="علت لغو"><input name="refund_amount" type="number" min="0" value="0" style="max-width:100px"><button class="btn">لغو</button></form></td></tr><?php endforeach;?></table></div></div>
<div class="card" style="margin-top:12px"><h2 class="section-title">سفرهای فعال</h2><div class="tablewrap"><table class="table"><tr><th>راننده</th><th>مسیر</th><th>وضعیت</th><th>کرایه</th><th>لغو مدیریتی</th></tr><?php foreach($activeTrips as $t):?><tr><td><?=ra_e((string)($t['driver_name']??'—'))?></td><td><?=ra_e((string)$t['pickup_label'])?> ← <?=ra_e((string)$t['destination_label'])?></td><td><span class="badge"><?=ra_e(rado_trip_status_fa((string)$t['status']))?></span></td><td><?=ra_money($t['final_fare']??$t['estimated_fare'])?></td><td><form method="post" style="display:flex;gap:5px;min-width:280px"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="cancel_trip"><input type="hidden" name="trip_id" value="<?=ra_e((string)$t['id'])?>"><input name="reason" placeholder="علت لغو"><input name="refund_amount" type="number" min="0" value="0" style="max-width:100px"><button class="btn">لغو</button></form></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
