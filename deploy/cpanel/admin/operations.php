<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$searching=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('requested','searching')");
$active=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('driver_assigned','driver_arriving','arrived','in_progress')");
$online=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)");
$offers=ra_table_exists($pdo,'trip_offers')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trip_offers WHERE status='pending' AND expires_at>NOW()"):0;
ra_header('عملیات و Dispatch','operations','مرکز کنترل تخصیص سفر و عملیات زنده');
?>
<div class="grid"><div class="card span3 metric"><small>در جستجوی راننده</small><b><?=$searching?></b></div><div class="card span3 metric"><small>سفر فعال</small><b><?=$active?></b></div><div class="card span3 metric"><small>راننده آنلاین</small><b><?=$online?></b></div><div class="card span3 metric"><small>Offer فعال</small><b><?=$offers?></b></div></div>
<div class="grid">
<a class="card span6 module" href="/admin/dispatch.php"><div class="icon">◎</div><div><b>تنظیم Dispatch</b><small>شعاع مرحله‌ای، timeout، batch، فیلتر راننده و تخصیص سفر</small></div></a>
<a class="card span6 module" href="/admin/live-map.php"><div class="icon">◉</div><div><b>نقشه زنده عملیات</b><small>رانندگان آنلاین، سفرهای فعال و وضعیت لحظه‌ای</small></div></a>
<a class="card span6 module" href="/admin/platform.php"><div class="icon">☎</div><div><b>اپراتور تلفنی و عملیات پیشرفته</b><small>ثبت سفر تلفنی، تخصیص دستی، لغو مدیریتی و بازپرداخت</small></div></a>
<a class="card span6 module" href="/admin/trips.php?status=searching"><div class="icon">↔</div><div><b>صف درخواست‌ها</b><small>سفرهای بدون راننده و درخواست‌های در حال جستجو</small></div></a>
</div>
<?php if($searching>0&&$online===0):?><div class="notice err">سفر در انتظار وجود دارد ولی راننده آنلاین تازه دیده نمی‌شود؛ Presence/GPS راننده را بررسی کن.</div><?php endif;?>
<?php if($searching>0&&$online>0&&$offers===0):?><div class="notice warn">راننده آنلاین و سفر در انتظار داریم ولی Offer فعال صفر است؛ Dispatch log و تنظیمات شعاع/فیلتر را بررسی کن.</div><?php endif;?>
<?php ra_footer();
