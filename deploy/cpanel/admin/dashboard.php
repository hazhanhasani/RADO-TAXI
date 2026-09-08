<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();

$todayTrips=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE DATE(requested_at)=CURDATE()");
$searching=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('requested','searching')");
$activeTrips=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('driver_assigned','driver_arriving','arrived','in_progress')");
$onlineDrivers=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)");
$pendingDrivers=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers WHERE status='pending'");
$grossToday=(int)ra_scalar($pdo,"SELECT COALESCE(SUM(final_fare),0) FROM trips WHERE status='completed' AND DATE(completed_at)=CURDATE()");
$commissionToday=(int)ra_scalar($pdo,"SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id IS NULL AND entry_type='platform_commission_income' AND DATE(created_at)=CURDATE()");
$openTickets=ra_table_exists($pdo,'support_tickets')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status<>'closed'"):0;
$pendingSettlements=ra_table_exists($pdo,'driver_settlements')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM driver_settlements WHERE status='requested'"):0;
$pricing=rado_active_pricing_rule($pdo);
$current=ra_state('current_tag','نامشخص');
$target=ra_state('target_tag',$current);
$updateStatus=ra_state('update_status','unknown');
$lastSuccess=ra_state('last_success_at','ثبت نشده');
$mapSecrets=dirname(__DIR__).'/rado-system/private/ci-secrets.php';
$secrets=is_file($mapSecrets)?require $mapSecrets:[]; if(!is_array($secrets))$secrets=[];
$mapWeb=trim((string)($secrets['neshan_web_map_key']??''))!=='';
$mapService=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??''))!=='';

$recent=$pdo->query("SELECT t.id,t.status,t.pickup_label,t.destination_label,t.estimated_fare,t.final_fare,t.requested_at,du.full_name driver_name FROM trips t LEFT JOIN users du ON du.id=t.driver_id ORDER BY t.requested_at DESC LIMIT 8")->fetchAll();

ra_header('داشبورد','dashboard','مرکز کنترل RADO — '.rado_jalali_long());
?>
<div class="grid">
  <div class="card span3 metric"><small>سفر امروز</small><b><?=$todayTrips?></b><span class="muted"><?=$searching?> در جستجو</span></div>
  <div class="card span3 metric"><small>راننده آنلاین</small><b><?=$onlineDrivers?></b><span class="muted"><?=$pendingDrivers?> در انتظار تأیید</span></div>
  <div class="card span3 metric"><small>درآمد سفر امروز</small><b><?=ra_money($grossToday)?></b><span class="muted">کمیسیون <?=ra_money($commissionToday)?></span></div>
  <div class="card span3 metric"><small>عملیات باز</small><b><?=$activeTrips?></b><span class="muted"><?=$openTickets?> تیکت · <?=$pendingSettlements?> تسویه</span></div>
</div>
<?php if(!$pricing):?><div class="notice err">تعرفه فعال وجود ندارد؛ درخواست سفر نمی‌تواند قیمت معتبر بگیرد. <a href="/admin/finance.php">تنظیم تعرفه</a></div><?php endif;?>
<?php if(!$mapWeb||!$mapService):?><div class="notice warn">تنظیمات Neshan کامل نیست. Web Key: <?=$mapWeb?'✓':'✕'?> · Service Key: <?=$mapService?'✓':'✕'?> — <a href="/admin/maps.php">تنظیم نقشه</a></div><?php endif;?>
<?php if($current!==$target||$updateStatus==='error'):?><div class="notice err">آپدیت cPanel نیاز به بررسی دارد: فعلی <?=ra_e($current)?> · هدف <?=ra_e($target)?> · وضعیت <?=ra_e($updateStatus)?> — <a href="/admin/system.php">مرکز سیستم و آپدیت</a></div><?php endif;?>
<div class="grid">
  <div class="card span8"><h2 class="section-title">آخرین سفرها</h2><div class="tablewrap"><table class="table"><tr><th>زمان</th><th>مبدا</th><th>مقصد</th><th>راننده</th><th>وضعیت</th><th>کرایه</th></tr><?php foreach($recent as $t):?><tr><td><?=ra_e(ra_date($t['requested_at']))?></td><td><?=ra_e((string)$t['pickup_label'])?></td><td><?=ra_e((string)$t['destination_label'])?></td><td><?=ra_e((string)($t['driver_name']??'—'))?></td><td><span class="badge"><?=ra_e(rado_trip_status_fa((string)$t['status']))?></span></td><td><?=ra_money($t['final_fare']??$t['estimated_fare'])?></td></tr><?php endforeach;?></table></div><p><a class="btn" href="/admin/trips.php">مدیریت همه سفرها</a></p></div>
  <div class="card span4"><h2 class="section-title">وضعیت سیستم</h2><div class="module"><div class="icon">↻</div><div><b>نسخه cPanel</b><small>فعلی: <?=ra_e($current)?><br>هدف: <?=ra_e($target)?><br>آخرین موفق: <?=ra_e($lastSuccess)?></small></div></div><hr style="border:0;border-top:1px solid #eee"><div class="module"><div class="icon">⌖</div><div><b>Neshan</b><small>Web <?= $mapWeb?'فعال':'ناقص' ?> · Service <?= $mapService?'فعال':'ناقص' ?></small></div></div><p><a class="btn goldbtn" href="/admin/system.php">جزئیات سلامت و آپدیت</a></p></div>
</div>
<div class="grid">
<?php
$mods=[
['سفرها','سفر جاری، جستجو، تکمیل‌شده و لغوشده','/admin/trips.php','↔'],
['رانندگان','تأیید، خودرو، کمیسیون، کیف پول و آنلاین بودن','/admin/drivers.php','🚕'],
['مسافران','حساب‌ها، سفرها، کیف پول و پشتیبانی','/admin/passengers.php','👤'],
['عملیات','Dispatch، اپراتور تلفنی و تخصیص راننده','/admin/dispatch.php','◎'],
['مالی','تعرفه، کمیسیون، تسویه و بازپرداخت','/admin/finance.php','﷼'],
['رشد','تخفیف، مأموریت، وفاداری و سازمانی','/admin/growth.php','★'],
['پشتیبانی','تیکت‌های مسافر و راننده','/admin/support.php','?'],
['گزارش‌ها','خروجی و تحلیل عملیات','/admin/reports.php','▤'],
['نقشه','کلیدهای Neshan و تست سرویس','/admin/maps.php','⌖'],
['سیستم','Cron، نسخه، آپدیت و خطاها','/admin/system.php','↻'],
]; foreach($mods as $m):?>
<a class="card span4 module" href="<?=ra_e($m[2])?>"><div class="icon"><?=ra_e($m[3])?></div><div><b><?=ra_e($m[0])?></b><small><?=ra_e($m[1])?></small></div></a>
<?php endforeach;?>
</div>
<?php ra_footer();
