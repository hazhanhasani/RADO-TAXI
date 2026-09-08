<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$root=dirname(__DIR__);
$secretsFile=$root.'/rado-system/private/ci-secrets.php';
$secrets=is_file($secretsFile)?require $secretsFile:[]; if(!is_array($secrets))$secrets=[];
$mapWeb=trim((string)($secrets['neshan_web_map_key']??''))!=='';
$mapService=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??''))!=='';
$pricing=rado_active_pricing_rule($pdo)!==null;
$current=ra_state('current_tag','نامشخص');
ra_header('تنظیمات','platform','مرکز تنظیمات RADO؛ همه بخش‌ها با یک رابط مشترک');
?>
<div class="grid">
  <div class="card span4 metric"><small>نسخه سیستم</small><b><?=ra_e($current)?></b><span class="muted">cPanel / API</span></div>
  <div class="card span4 metric"><small>نقشه نشان</small><b><?=$mapWeb&&$mapService?'آماده':'نیاز به تنظیم'?></b><span class="muted">Web <?=$mapWeb?'✓':'✕'?> · Service <?=$mapService?'✓':'✕'?></span></div>
  <div class="card span4 metric"><small>تعرفه</small><b><?=$pricing?'فعال':'تنظیم نشده'?></b><span class="muted">محاسبه کرایه سمت سرور</span></div>
</div>
<div class="grid">
  <a class="card span4 module" href="/admin/maps.php"><div class="icon">⌖</div><div><b>نقشه و Neshan</b><small>کلیدها، تست اتصال و سلامت سرویس‌های مکانی</small></div></a>
  <a class="card span4 module" href="/admin/verification-settings.php"><div class="icon">✓</div><div><b>API.ir و احراز هویت</b><small>شاهکار، بایومتریک، گواهینامه، خودرو و تطبیق شبا</small></div></a>
  <a class="card span4 module" href="/admin/finance.php"><div class="icon">﷼</div><div><b>تعرفه و کمیسیون</b><small>قیمت‌گذاری، کمیسیون، تسویه و بازپرداخت</small></div></a>
  <a class="card span4 module" href="/admin/system.php"><div class="icon">↻</div><div><b>سیستم و آپدیت</b><small>نسخه، Cron، Release، خطاها و سلامت سرویس</small></div></a>
  <a class="card span4 module" href="/admin/operations.php"><div class="icon">◎</div><div><b>عملیات و Dispatch</b><small>شعاع تخصیص، صف درخواست‌ها، تخصیص دستی و لغو مدیریتی</small></div></a>
  <a class="card span4 module" href="/admin/growth.php"><div class="icon">★</div><div><b>رشد و کمپین</b><small>کد تخفیف، مأموریت راننده و حساب سازمانی</small></div></a>
  <a class="card span4 module" href="/admin/support.php"><div class="icon">?</div><div><b>پشتیبانی</b><small>تیکت‌ها، پاسخ مدیر و بستن درخواست‌ها</small></div></a>
</div>
<?php ra_footer();
