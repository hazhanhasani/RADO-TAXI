<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$promos=ra_table_exists($pdo,'promo_codes')?$pdo->query("SELECT * FROM promo_codes ORDER BY created_at DESC LIMIT 100")->fetchAll():[];
$missions=ra_table_exists($pdo,'driver_missions')?$pdo->query("SELECT * FROM driver_missions ORDER BY starts_at DESC LIMIT 100")->fetchAll():[];
$corporates=ra_table_exists($pdo,'corporate_accounts')?$pdo->query("SELECT * FROM corporate_accounts ORDER BY id DESC LIMIT 100")->fetchAll():[];
$redeemed=ra_table_exists($pdo,'promo_redemptions')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM promo_redemptions"):0;
$activePromos=ra_table_exists($pdo,'promo_codes')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM promo_codes WHERE active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW())"):0;
$activeMissions=ra_table_exists($pdo,'driver_missions')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM driver_missions WHERE active=1 AND starts_at<=NOW() AND ends_at>=NOW()"):0;
ra_header('رشد و کمپین','growth','تخفیف، مأموریت راننده و حساب سازمانی');
?>
<div class="grid"><div class="card span3 metric"><small>کد تخفیف فعال</small><b><?=$activePromos?></b></div><div class="card span3 metric"><small>استفاده از تخفیف</small><b><?=$redeemed?></b></div><div class="card span3 metric"><small>مأموریت فعال</small><b><?=$activeMissions?></b></div><div class="card span3 metric"><small>حساب سازمانی</small><b><?=count($corporates)?></b></div></div>
<div class="grid"><div class="card span6"><h2 class="section-title">کدهای تخفیف</h2><div class="tablewrap"><table class="table"><tr><th>کد</th><th>عنوان</th><th>نوع</th><th>مقدار</th><th>فعال</th><th>پایان</th></tr><?php foreach($promos as $p):?><tr><td><b><?=ra_e((string)$p['code'])?></b></td><td><?=ra_e((string)$p['title'])?></td><td><?=ra_e((string)$p['discount_type'])?></td><td><?=ra_e((string)$p['discount_value'])?></td><td><?=$p['active']?'✓':'✕'?></td><td><?=ra_e(ra_date($p['ends_at']??null))?></td></tr><?php endforeach;?></table></div></div><div class="card span6"><h2 class="section-title">مأموریت‌های راننده</h2><div class="tablewrap"><table class="table"><tr><th>عنوان</th><th>هدف</th><th>مقدار</th><th>پاداش</th><th>پایان</th></tr><?php foreach($missions as $m):?><tr><td><?=ra_e((string)$m['title'])?></td><td><?=ra_e((string)$m['target_type'])?></td><td><?=ra_e((string)$m['target_value'])?></td><td><?=ra_money($m['reward_amount'])?></td><td><?=ra_e(ra_date($m['ends_at']))?></td></tr><?php endforeach;?></table></div></div></div>
<div class="card" style="margin-top:14px"><h2 class="section-title">حساب‌های سازمانی</h2><div class="tablewrap"><table class="table"><tr><th>عنوان</th><th>تماس</th><th>مدل صورتحساب</th><th>وضعیت</th></tr><?php foreach($corporates as $c):?><tr><td><?=ra_e((string)$c['title'])?></td><td><?=ra_e((string)($c['contact_phone']??'—'))?></td><td><?=ra_e((string)($c['billing_mode']??'—'))?></td><td><span class="badge"><?=!empty($c['active'])?'فعال':'غیرفعال'?></span></td></tr><?php endforeach;?></table></div><p class="muted">ایجاد/ویرایش پیشرفته کمپین‌ها و حساب‌های سازمانی از بخش «تنظیمات پیشرفته» در دسترس است.</p><a class="btn" href="/admin/platform.php">تنظیمات پیشرفته</a></div>
<?php ra_footer();
