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
ra_header('تنظیمات','platform','مرکز تنظیمات RADO؛ همه تنظیمات از یک قالب مشترک');
?>
<div class="grid">
  <div class="card span4 metric"><small>نسخه سیستم</small><b><?=ra_e($current)?></b><span class="muted">cPanel / API</span></div>
  <div class="card span4 metric"><small>نقشه نشان</small><b><?=$mapWeb&&$mapService?'آماده':'نیاز به تنظیم'?></b><span class="muted">Web <?=$mapWeb?'✓':'✕'?> · Service <?=$mapService?'✓':'✕'?></span></div>
  <div class="card span4 metric"><small>تعرفه</small><b><?=$pricing?'فعال':'تنظیم نشده'?></b><span class="muted">محاسبه کرایه سمت سرور</span></div>
</div>
<div class="grid">
  <a class="card span4 module" href="/admin/maps.php"><div class="icon">⌖</div><div><b>نقشه و Neshan</b><small>Web Map Key، Service API Key، تست اتصال و سلامت سرویس‌های مکانی</small></div></a>
  <a class="card span4 module" href="/admin/finance.php"><div class="icon">﷼</div><div><b>تعرفه و کمیسیون</b><small>قیمت پایه، کیلومتر، زمان، انتظار، حداقل کرایه و کمیسیون راننده</small></div></a>
  <a class="card span4 module" href="/admin/system.php"><div class="icon">↻</div><div><b>سیستم و آپدیت</b><small>نسخه، Cron، همگام‌سازی Release، خطاها و سلامت سرویس</small></div></a>
  <a class="card span6 module" href="/admin/platform.php"><div class="icon">⚙</div><div><b>تنظیمات عملیاتی پیشرفته</b><small>سیاست‌های پلتفرم، محدودیت‌ها و تنظیمات تخصصی که کمتر تغییر می‌کنند</small></div></a>
  <a class="card span6 module" href="/admin/operations.php"><div class="icon">◎</div><div><b>عملیات و Dispatch</b><small>شعاع تخصیص، رانندگان آنلاین، سفرهای فعال و کنترل عملیاتی</small></div></a>
</div>
<?php ra_footer();
