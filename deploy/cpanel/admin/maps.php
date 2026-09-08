<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();

function ra_write_secret_array(string $path, array $data): void {
    $body = "<?php\nreturn " . var_export($data, true) . ";\n";
    if (file_put_contents($path, $body, LOCK_EX) === false) throw new RuntimeException('ذخیره تنظیمات ممکن نشد.');
    @chmod($path, 0600);
}
function ra_test_neshan_service(string $key): array {
    $url='https://api.neshan.org/v2/reverse?lat=35.9968&lng=45.8853';
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Api-Key: '.$key,'User-Agent: RADO-TAXI/1.0'],
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if($body===false||$status<200||$status>=300)return [false,$status,$err!==''?$err:'Neshan service rejected the key'];
    $json=json_decode((string)$body,true);
    return [is_array($json),$status,is_array($json)?'OK':'Invalid JSON'];
}

$root=dirname(__DIR__);
$file=$root.'/rado-system/private/ci-secrets.php';
$secrets=is_file($file)?require $file:[]; if(!is_array($secrets))$secrets=[];
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    ra_require_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='save'){
            $web=trim((string)($_POST['neshan_web_map_key']??''));
            $service=trim((string)($_POST['neshan_service_api_key']??''));
            if($web!=='')$secrets['neshan_web_map_key']=$web;
            if($service!==''){
                $secrets['neshan_service_api_key']=$service;
                $secrets['neshan_reverse_api_key']=$service;
            }
            if(empty($secrets['neshan_web_map_key']))throw new RuntimeException('Web Map Key نشان را وارد کنید.');
            if(empty($secrets['neshan_service_api_key'])&&empty($secrets['neshan_reverse_api_key'])&&empty($secrets['neshan_map_key']))throw new RuntimeException('Service API Key نشان را وارد کنید.');
            ra_write_secret_array($file,$secrets);
            $message='کلیدهای نشان ذخیره شدند — '.rado_jalali_datetime();
        } elseif($action==='test_service'){
            $candidate=trim((string)($_POST['neshan_service_api_key']??''));
            if($candidate==='')$candidate=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??$secrets['neshan_map_key']??''));
            if($candidate==='')throw new RuntimeException('Service API Key تنظیم نشده است.');
            [$ok,$status,$detail]=ra_test_neshan_service($candidate);
            if(!$ok)throw new RuntimeException('تست Service Key ناموفق بود. HTTP '.$status.' — '.$detail);
            $message='Service API Key با Reverse Geocoding واقعی تست شد و سالم است.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
    $secrets=is_file($file)?require $file:$secrets; if(!is_array($secrets))$secrets=[];
}
$webConfigured=trim((string)($secrets['neshan_web_map_key']??''))!=='';
$serviceConfigured=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??''))!=='';
$legacyConfigured=trim((string)($secrets['neshan_map_key']??''))!=='';
ra_header('نقشه و Neshan','maps','تنظیم کلیدها و سلامت سرویس‌های نقشه');
?>
<?php if($message!==''):?><div class="notice ok"><?=ra_e($message)?></div><?php endif;?>
<?php if($error!==''):?><div class="notice err"><?=ra_e($error)?></div><?php endif;?>
<div class="grid">
  <div class="card span4 metric"><small>Web Map Key</small><b><?=$webConfigured?'فعال':'تنظیم نشده'?></b><span class="muted">نمایش نقشه داخل Passenger</span></div>
  <div class="card span4 metric"><small>Service API Key</small><b><?=$serviceConfigured?'فعال':'تنظیم نشده'?></b><span class="muted">Search / Reverse / Direction</span></div>
  <div class="card span4 metric"><small>وضعیت کلی</small><b><?=$webConfigured&&$serviceConfigured?'آماده':'نیاز به تنظیم'?></b><span class="muted">کلیدهای سرویس جدا نگهداری می‌شوند</span></div>
</div>
<?php if($legacyConfigured):?><div class="notice warn">کلید قدیمی عمومی <code>neshan_map_key</code> هنوز وجود دارد. برای جلوگیری از اشتباه نوع کلید، Web Key و Service Key را جدا نگه دار.</div><?php endif;?>
<div class="grid">
 <div class="card span8"><h2 class="section-title">تنظیم کلیدهای نشان</h2><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="save"><div class="formgrid"><div class="field full"><label>Neshan Web Map Key</label><input type="password" name="neshan_web_map_key" placeholder="فقط برای تغییر، Web Key جدید را وارد کنید"><div class="muted">برای نمایش نقشه داخل اپ. تغییر این کلید در Runtime انجام می‌شود و نیاز به Build جدید APK ندارد.</div></div><div class="field full"><label>Neshan Service API Key</label><input type="password" name="neshan_service_api_key" placeholder="فقط برای تغییر، Service API Key جدید را وارد کنید"><div class="muted">برای جستجو، آدرس دقیق و مسیر. فقط روی cPanel نگهداری می‌شود.</div></div></div><div style="margin-top:12px"><button class="btn goldbtn">ذخیره تنظیمات</button></div></form></div>
 <div class="card span4"><h2 class="section-title">تست سرویس</h2><p class="muted">تست واقعی Reverse Geocoding روی بانه انجام می‌شود و مشخص می‌کند Service Key معتبر است یا نه.</p><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="test_service"><button class="btn">تست Service Key</button></form><div class="subnav"><a href="/api/health/" target="_blank">Health API</a><a href="/admin/operations.php">عملیات</a><a href="/admin/system.php">سیستم</a></div></div>
</div>
<?php ra_footer();
