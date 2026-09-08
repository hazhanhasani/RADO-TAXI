<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$root=dirname(__DIR__);
$pdo=rado_db();
$current=ra_state('current_tag','نامشخص');
$target=ra_state('target_tag',$current);
$status=ra_state('update_status','unknown');
$lastSuccess=ra_state('last_success_at','ثبت نشده');
$lastSuccessIso=ra_state('last_success_iso','');
$lastError='';
foreach(['last_error_current.log','last_error.log'] as $f){$p=$root.'/rado-system/state/'.$f;if(is_file($p)&&trim((string)@file_get_contents($p))!==''){$lastError=trim((string)@file_get_contents($p));break;}}
$remote='نامشخص';$remotePublished='—';$remoteError='';
try{
  $cfg=require $root.'/rado-system/config.php';
  $repo=(string)($cfg['repo']??'hazhanhasani/RADO-TAXI');
  $api=rtrim((string)($cfg['github_api']??'https://api.github.com'),'/').'/repos/'.$repo.'/releases/latest';
  $ch=curl_init($api);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','User-Agent: RADO-Admin']]);
  $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
  if($body===false||$code<200||$code>=300)throw new RuntimeException('HTTP '.$code.' '.$err);
  $j=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);$remote=(string)($j['tag_name']??'نامشخص');$remotePublished=(string)($j['published_at']??'—');
}catch(Throwable $e){$remoteError=$e->getMessage();}
$pending=$remote!=='نامشخص'&&$current!==$remote;
$cronStale=false;
if($lastSuccessIso!==''){try{$last=new DateTimeImmutable($lastSuccessIso);$cronStale=(time()-$last->getTimestamp())>900;}catch(Throwable){}}
$passMeta=$root.'/downloads/'.str_replace('v','RADO-Passenger-v',$remote).'.apk';
$driverMeta=$root.'/downloads/'.str_replace('v','RADO-Driver-v',$remote).'.apk';
$passExists=is_file($passMeta);$driverExists=is_file($driverMeta);
$dbOk=true;try{$pdo->query('SELECT 1')->fetchColumn();}catch(Throwable){$dbOk=false;}
$pricingOk=rado_active_pricing_rule($pdo)!==null;
ra_header('سیستم و آپدیت','system','سلامت سرویس، Cron و همگام‌سازی Release');
?>
<div class="grid">
 <div class="card span3 metric"><small>نسخه cPanel</small><b><?=ra_e($current)?></b><span class="muted">وضعیت <?=ra_e($status)?></span></div>
 <div class="card span3 metric"><small>آخرین Release</small><b><?=ra_e($remote)?></b><span class="muted"><?=$pending?'آپدیت در انتظار':'همگام'?></span></div>
 <div class="card span3 metric"><small>آخرین Cron موفق</small><b style="font-size:15px"><?=ra_e($lastSuccess)?></b><span class="muted"><?=$cronStale?'قدیمی / نیاز به بررسی':'تازه'?></span></div>
 <div class="card span3 metric"><small>هسته سرویس</small><b><?=$dbOk&&$pricingOk?'سالم':'نیاز به بررسی'?></b><span class="muted">DB <?=$dbOk?'✓':'✕'?> · Pricing <?=$pricingOk?'✓':'✕'?></span></div>
</div>
<?php if($pending):?><div class="notice warn">Release جدید <?=ra_e($remote)?> منتشر شده ولی cPanel هنوز روی <?=ra_e($current)?> است. اگر بیش از ۱۰–۱۵ دقیقه گذشته، Cron یا updater باید بررسی شود.</div><?php endif;?>
<?php if($cronStale):?><div class="notice err">آخرین اجرای موفق updater بیش از ۱۵ دقیقه قبل بوده؛ این یعنی Cron احتمالاً اجرا نمی‌شود یا قبل از ثبت موفقیت خطا می‌دهد.</div><?php endif;?>
<?php if($lastError!==''):?><div class="notice err"><b>آخرین خطای updater</b><br><code style="white-space:pre-wrap"><?=ra_e(mb_substr($lastError,0,1800))?></code></div><?php endif;?>
<div class="grid">
 <div class="card span6"><h2 class="section-title">وضعیت همگام‌سازی</h2><div class="tablewrap"><table class="table" style="min-width:0"><tr><th>بخش</th><th>وضعیت</th></tr><tr><td>GitHub latest</td><td><?=ra_e($remote)?> <?=$remoteError!==''?'('.ra_e($remoteError).')':''?></td></tr><tr><td>cPanel current</td><td><?=ra_e($current)?></td></tr><tr><td>target_tag</td><td><?=ra_e($target)?></td></tr><tr><td>update_status</td><td><?=ra_e($status)?></td></tr><tr><td>Passenger mirror</td><td><?=$passExists?'موجود روی هاست':'هنوز Mirror نشده'?></td></tr><tr><td>Driver mirror</td><td><?=$driverExists?'موجود روی هاست':'هنوز Mirror نشده'?></td></tr></table></div></div>
 <div class="card span6"><h2 class="section-title">Cron پیشنهادی</h2><p class="muted">این مسیر باید هر ۵ دقیقه اجرا شود. Document Root واقعی دامنه را جایگزین کن؛ خود پنل از همین فایل Bootstrap دائمی استفاده می‌کند.</p><div style="background:#171717;color:#f7d15c;padding:13px;border-radius:12px;direction:ltr;overflow:auto"><code>*/5 * * * * php -q /FULL/PATH/TO/DOCUMENT_ROOT/rado-system/bin/rado-update.php &gt;/dev/null 2&gt;&amp;1</code></div><p class="muted">اگر Cron درست باشد، نیاز به Recovery در بروزرسانی‌های عادی نباید وجود داشته باشد.</p></div>
</div>
<div class="grid"><div class="card span12"><h2 class="section-title">عیب‌یابی سریع</h2><div class="grid" style="margin-top:0"><div class="card span3"><b>Database</b><div class="<?=$dbOk?'notice ok':'notice err'?>"><?=$dbOk?'OK':'ERROR'?></div></div><div class="card span3"><b>Pricing</b><div class="<?=$pricingOk?'notice ok':'notice err'?>"><?=$pricingOk?'OK':'NOT CONFIGURED'?></div></div><div class="card span3"><b>Updater</b><div class="<?=!$cronStale&&$status!=='error'?'notice ok':'notice err'?>"><?=ra_e($status)?></div></div><div class="card span3"><b>زمان</b><div class="notice ok">Asia/Tehran<br><?=ra_e(rado_jalali_datetime())?></div></div></div></div></div>
<?php ra_footer();
