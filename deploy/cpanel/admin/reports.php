<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$today=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE DATE(requested_at)=CURDATE()");
$month=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE YEAR(requested_at)=YEAR(CURDATE()) AND MONTH(requested_at)=MONTH(CURDATE())");
$gross=(int)ra_scalar($pdo,"SELECT COALESCE(SUM(final_fare),0) FROM trips WHERE status='completed' AND YEAR(completed_at)=YEAR(CURDATE()) AND MONTH(completed_at)=MONTH(CURDATE())");
$commission=(int)ra_scalar($pdo,"SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id IS NULL AND entry_type='platform_commission_income' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())");
ra_header('گزارش‌ها','reports','خروجی عملیات، مالی و کاربران');
?>
<div class="grid"><div class="card span3 metric"><small>سفر امروز</small><b><?=$today?></b></div><div class="card span3 metric"><small>سفر این ماه</small><b><?=$month?></b></div><div class="card span3 metric"><small>گردش این ماه</small><b><?=ra_money($gross)?></b></div><div class="card span3 metric"><small>کمیسیون این ماه</small><b><?=ra_money($commission)?></b></div></div>
<div class="grid">
<a class="card span4 module" href="/admin/export.php?type=trips"><div class="icon">↔</div><div><b>گزارش سفرها</b><small>زمان، مبدا، مقصد، راننده، مسافر، وضعیت و کرایه</small></div></a>
<a class="card span4 module" href="/admin/export.php?type=drivers"><div class="icon">🚕</div><div><b>گزارش رانندگان</b><small>وضعیت، کمیسیون، موجودی و فعالیت</small></div></a>
<a class="card span4 module" href="/admin/export.php?type=settlements"><div class="icon">﷼</div><div><b>گزارش تسویه</b><small>درخواست‌ها، وضعیت و مبلغ پرداخت</small></div></a>
<a class="card span4 module" href="/admin/export.php?type=promos"><div class="icon">★</div><div><b>گزارش تخفیف</b><small>کدها و میزان استفاده</small></div></a>
<a class="card span4 module" href="/admin/export.php?type=audit"><div class="icon">▤</div><div><b>Audit Log</b><small>تغییرات مدیریتی و رویدادهای حساس</small></div></a>
<a class="card span4 module" href="/admin/live-map.php"><div class="icon">◉</div><div><b>نقشه عملیات</b><small>نمایش زنده رانندگان و سفرهای فعال</small></div></a>
</div>
<?php ra_footer();
