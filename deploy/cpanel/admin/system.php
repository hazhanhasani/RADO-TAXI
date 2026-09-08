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

// Only show the CURRENT updater error. Historical logs must not keep the panel red forever.
$currentErrorFile=$root.'/rado-system/state/last_error_current.log';
$lastError=is_file($currentErrorFile)?trim((string)@file_get_contents($currentErrorFile)):'';
$bootstrapErrorFile=$root.'/rado-system/state/bootstrap_error_current.log';
$bootstrapError=is_file($bootstrapErrorFile)?trim((string)@file_get_contents($bootstrapErrorFile)):'';

$remote=$target!==''?$target:'نامشخص';
$remotePublished='—';
$remoteError='';
try{
  $cfg=require $root.'/rado-system/config.php';
  $repo=(string)($cfg['repo']??'hazhanhasani/RADO-TAXI');
  $api=rtrim((string)($cfg['github_api']??'https://api.github.com'),'/').'/repos/'.$repo.'/releases/latest';
  $lastHttpError='';
  for($attempt=1;$attempt<=3;$attempt++){
    $ch=curl_init($api);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_FOLLOWLOCATION=>true,
      CURLOPT_CONNECTTIMEOUT=>6,
      CURLOPT_TIMEOUT=>20,
      CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','User-Agent: RADO-Admin/1.0'],
    ]);
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if($body!==false&&$code>=200&&$code<300){
      $j=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);
      $remote=(string)($j['tag_name']??$remote);
      $remotePublished=(string)($j['published_at']??'—');
      $lastHttpError='';
      break;
    }
    $lastHttpError='HTTP '.$code.($err!==''?' — '.$err:'');
    if($attempt<3) usleep(250000*$attempt);
  }
  if($lastHttpError!=='') throw new RuntimeException($lastHttpError);
}catch(Throwable $e){
  // GitHub timeout is not equivalent to updater failure; fall back to cached target/current state.
  $remoteError=$e->getMessage();
  if($remote==='')$remote=$target!==''?$target:$current;
}
$pending=$remote!=='نامشخص'&&$current!==$remote;
$cronStale=false;
if($lastSuccessIso!==''){try{$last=new DateTimeImmutable($lastSuccessIso);$cronStale=(time()-$last->getTimestamp())>900;}catch(Throwable){}}
$passMeta=$root.'/downloads/'.str_replace('v','RADO-Passenger-v',$remote).'.apk';
$driverMeta=$root.'/downloads/'.str_replace('v','RADO-Driver-v',$remote).'.apk';
$passExists=is_file($passMeta);$driverExists=is_file($driverMeta);
$dbOk=true;try{$pdo->query('SELECT 1')->fetchColumn();}catch(Throwable){$dbOk=false;}
$pricingOk=rado_active_pricing_rule($pdo)!==null;
$updaterHealthy=!$cronStale&&$status!=='error'&&$lastError==='';
ra_header('سیستم و آپدیت','system','سلامت سرویس، Cron و همگام‌سازی Release');
?>
<div class="grid">
 <div class="card span3 metric"><small>نسخه cPanel</small><b><?=ra_e($current)?></b><span class="muted">وضعیت <?=ra_e($status)?></span></div>
 <div class="card span3 metric"><small>آخرین Release شناخته‌شده</small><b><?=ra_e($remote)?></b><span class="muted"><?=$pending?'آپدیت در انتظار':'همگام'?></span></div>
 <div class="card span3 metric"><small>آخرین Cron موفق</small><b style="font-size:14px"><?=ra_e($lastSuccess)?></b><span class="muted"><?=$cronStale?'قدیمی / نیاز به بررسی':'تازه'?></span></div>
 <div class="card span3 metric"><small>Updater</small><b><?=$updaterHealthy?'سالم':'نیاز به بررسی'?></b><span class="muted">DB <?=$dbOk?'✓':'✕'?> · Pricing <?=$pricingOk?'✓':'✕'?></span></div>
</div>
<?php if($pending):?><div class="notice warn">Release جدید <?=ra_e($remote)?> شناخته شده ولی cPanel هنوز روی <?=ra_e($current)?> است. Cron در اجرای بعدی باید آن را دریافت کند.</div><?php endif;?>
<?php if($remoteError!==''):?><div class="notice warn"><b>GitHub موقتاً پاسخ نداده است.</b><br>پنل از وضعیت ذخیره‌شده روی cPanel استفاده می‌کند؛ این مورد به‌تنهایی خطای updater محسوب نمی‌شود.<br><span class="muted"><?=ra_e($remoteError)?></span></div><?php endif;?>
<?php if($cronStale):?><div class="notice err">بیش از ۱۵ دقیقه از آخرین اجرای موفق updater گذشته است؛ Cron یا اتصال شبکه هاست باید بررسی شود.</div><?php endif;?>
<?php if($lastError!==''):?><div class="notice err"><b>خطای جاری updater</b><br><code style="white-space:pre-wrap;word-break:break-word"><?=ra_e(mb_substr($lastError,0,1200))?></code></div><?php endif;?>
<?php if($bootstrapError!==''):?><div class="notice warn"><b>خطای جاری Bootstrap</b><br><code style="white-space:pre-wrap;word-break:break-word"><?=ra_e(mb_substr($bootstrapError,0,800))?></code></div><?php endif;?>
<div class="grid">
 <div class="card span6"><h2 class="section-title">وضعیت همگام‌سازی</h2><div class="tablewrap"><table class="table" style="min-width:0"><tr><th>بخش</th><th>وضعیت</th></tr><tr><td>GitHub latest / cached target</td><td><?=ra_e($remote)?></td></tr><tr><td>cPanel current</td><td><?=ra_e($current)?></td></tr><tr><td>target_tag</td><td><?=ra_e($target)?></td></tr><tr><td>update_status</td><td><?=ra_e($status)?></td></tr><tr><td>Passenger mirror</td><td><?=$passExists?'موجود روی هاست':'در انتظار Mirror'?></td></tr><tr><td>Driver mirror</td><td><?=$driverExists?'موجود روی هاست':'در انتظار Mirror'?></td></tr></table></div></div>
 <div class="card span6"><h2 class="section-title">Cron</h2><p class="muted">Updater باید هر ۵ دقیقه اجرا شود. خطاهای موقت GitHub در اجرای بعدی دوباره امتحان می‌شوند و دیگر تاریخچه خطا به‌عنوان خطای جاری نمایش داده نمی‌شود.</p><div style="background:#171717;color:#f7d15c;padding:12px;border-radius:11px;direction:ltr;overflow:auto;font-size:10px"><code>*/5 * * * * php -q /FULL/PATH/TO/DOCUMENT_ROOT/rado-system/bin/rado-update.php &gt;/dev/null 2&gt;&amp;1</code></div></div>
</div>
<div class="grid"><div class="card span12"><h2 class="section-title">عیب‌یابی سریع</h2><div class="grid" style="margin-top:0"><div class="card span3"><b>Database</b><div class="<?=$dbOk?'notice ok':'notice err'?>"><?=$dbOk?'OK':'ERROR'?></div></div><div class="card span3"><b>Pricing</b><div class="<?=$pricingOk?'notice ok':'notice err'?>"><?=$pricingOk?'OK':'NOT CONFIGURED'?></div></div><div class="card span3"><b>Updater</b><div class="<?=$updaterHealthy?'notice ok':'notice err'?>"><?=$updaterHealthy?'OK':ra_e($status)?></div></div><div class="card span3"><b>زمان</b><div class="notice ok">Asia/Tehran<br><?=ra_e(rado_jalali_datetime())?></div></div></div></div></div>
<?php ra_footer();
