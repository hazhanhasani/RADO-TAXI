<?php
declare(strict_types=1);
require dirname(__DIR__) . '/rado-system/lib/app.php';

session_name('rado_admin');
session_set_cookie_params([
    'httponly'=>true,
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite'=>'Strict',
    'path'=>'/',
]);
session_start();
if (empty($_SESSION['admin_id'])) { header('Location: /admin/'); exit; }

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['csrf'];
}
function write_secret_array(string $path, array $data): void {
    $body = "<?php\nreturn " . var_export($data, true) . ";\n";
    if (file_put_contents($path, $body, LOCK_EX) === false) throw new RuntimeException('ذخیره تنظیمات ممکن نشد.');
    @chmod($path, 0600);
}
function test_service_key(string $key): array {
    $url='https://api.neshan.org/v2/reverse?lat=35.9968&lng=45.8853';
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Api-Key: '.$key,'User-Agent: RADO-TAXI/1.0'],
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if($body===false||$status<200||$status>=300)return [false,$status,$err!==''?$err:'Neshan service rejected the key'];
    $json=json_decode((string)$body,true);
    return [is_array($json),$status,is_array($json)?'OK':'Invalid JSON'];
}

$root=dirname(__DIR__);
$file=$root.'/rado-system/private/ci-secrets.php';
$secrets=is_file($file)?require $file:[];
if(!is_array($secrets))$secrets=[];
$message='';$error='';$testResult=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(419);exit('درخواست نامعتبر است.');}
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
            if(empty($secrets['neshan_service_api_key']) && empty($secrets['neshan_reverse_api_key']) && empty($secrets['neshan_map_key']))throw new RuntimeException('Service API Key نشان را وارد کنید.');
            write_secret_array($file,$secrets);
            $message='کلیدهای نشان ذخیره شدند — '.rado_jalali_datetime();
        }
        if($action==='test_service'){
            $candidate=trim((string)($_POST['neshan_service_api_key']??''));
            if($candidate==='')$candidate=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??$secrets['neshan_map_key']??''));
            if($candidate==='')throw new RuntimeException('Service API Key تنظیم نشده است.');
            $testResult=test_service_key($candidate);
            if(!$testResult[0])throw new RuntimeException('تست Service Key ناموفق بود. HTTP '.$testResult[1].' — '.$testResult[2]);
            $message='Service API Key با Reverse Geocoding واقعی تست شد و سالم است.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
    $secrets=is_file($file)?require $file:$secrets;if(!is_array($secrets))$secrets=[];
}

$webConfigured=trim((string)($secrets['neshan_web_map_key']??''))!=='';
$serviceConfigured=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??''))!=='';
$legacyConfigured=trim((string)($secrets['neshan_map_key']??''))!=='';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نقشه RADO</title><style>
body{margin:0;background:#f4f4f1;color:#171717;font-family:Tahoma,Arial}.wrap{max-width:760px;margin:4vh auto;padding:16px}.card{background:#fff;border-radius:24px;padding:22px;margin-bottom:14px;box-shadow:0 10px 30px #0000000b}.top{background:#171717;color:#fff}.logo{font-size:36px;font-weight:900}.gold{color:#f5b400}.muted{color:#777;font-size:12px;line-height:1.9}.status{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #eee}.ok{color:#17763b}.bad{color:#b21f1f}.notice{padding:12px;border-radius:13px;margin:12px 0}.success{background:#e9f8ed;color:#17652d}.error{background:#fff0f0;color:#a31d1d}.warn{background:#fff5cf;color:#6b5200}label{display:block;font-weight:800;font-size:13px;margin-top:12px}input{width:100%;box-sizing:border-box;padding:13px;border:1px solid #ddd;border-radius:13px;margin-top:6px}button,.btn{display:inline-block;border:0;background:#171717;color:#fff;border-radius:13px;padding:12px 16px;font-weight:900;text-decoration:none;margin-top:12px}.secondary{background:#f5b400;color:#171717}.row{display:flex;gap:8px;flex-wrap:wrap}
</style></head><body><div class="wrap"><div class="card top"><div class="logo">R<span class="gold">A</span>DO MAPS</div><div>تنظیم مستقل کلیدهای نقشه نشان</div><div class="muted" style="color:#ccc"><?=h(rado_jalali_long())?> — Asia/Tehran</div></div>
<?php if($message):?><div class="notice success"><?=h($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif;?>
<div class="card"><h3>وضعیت فعلی</h3><div class="status"><span>Web Map Key — نمایش نقشه داخل اپ</span><b class="<?=$webConfigured?'ok':'bad'?>"><?=$webConfigured?'تنظیم شده ✓':'تنظیم نشده ✕'?></b></div><div class="status"><span>Service API Key — مسیر، جستجو و آدرس</span><b class="<?=$serviceConfigured?'ok':'bad'?>"><?=$serviceConfigured?'تنظیم شده ✓':'تنظیم نشده ✕'?></b></div><?php if($legacyConfigured):?><div class="notice warn">یک کلید قدیمی با نام عمومی <code>neshan_map_key</code> پیدا شد. برای جلوگیری از اشتباه نوع کلید، Web Key و Service Key را جداگانه ذخیره کن.</div><?php endif;?><p class="muted">برای پکیج فعلی Flutter، کلید نمایش نقشه باید مشخصاً Web Key باشد. Service Key در APK قرار نمی‌گیرد و فقط روی cPanel استفاده می‌شود.</p></div>
<div class="card"><h3>تنظیم کلیدها</h3><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save"><label>Neshan Web Map Key</label><input type="password" name="neshan_web_map_key" placeholder="برای تغییر، Web Key جدید را وارد کنید"><div class="muted">فقط برای نمایش نقشه. این نوع کلید در WebView اپ استفاده می‌شود.</div><label>Neshan Service API Key</label><input type="password" name="neshan_service_api_key" placeholder="برای تغییر، Service API Key جدید را وارد کنید"><div class="muted">برای Search / Reverse Geocoding / Direction. فقط روی سرور نگهداری می‌شود.</div><button>ذخیره تنظیمات نقشه</button></form><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="test_service"><button class="secondary">تست واقعی Service Key</button></form></div>
<div class="card"><div class="row"><a class="btn" href="/admin/">بازگشت به مدیریت</a><a class="btn secondary" href="/api/health/" target="_blank">Health</a></div></div></div></body></html>