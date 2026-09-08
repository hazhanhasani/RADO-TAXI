<?php
declare(strict_types=1);

require dirname(__DIR__) . '/rado-system/lib/app.php';

session_name('rado_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/');
    exit;
}

function e_map(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$root = dirname(__DIR__);
$secretsFile = $root . '/rado-system/private/ci-secrets.php';
$message = '';
$error = '';
$csrf = (string)($_SESSION['csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['csrf'] = $csrf;
}

$secrets = is_file($secretsFile) ? require $secretsFile : [];
if (!is_array($secrets)) $secrets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('درخواست نامعتبر است.');
        }

        $webKey = trim((string)($_POST['neshan_web_map_key'] ?? ''));
        $serviceKey = trim((string)($_POST['neshan_service_api_key'] ?? ''));
        if ($webKey === '') {
            throw new RuntimeException('Neshan Web Map Key الزامی است.');
        }
        if (strlen($webKey) < 12) {
            throw new RuntimeException('Web Map Key واردشده معتبر به نظر نمی‌رسد.');
        }

        $secrets['neshan_web_map_key'] = $webKey;
        // Backward compatibility for older RADO builds.
        $secrets['neshan_map_key'] = $webKey;
        if ($serviceKey !== '') {
            if (strlen($serviceKey) < 12) {
                throw new RuntimeException('Service API Key واردشده معتبر به نظر نمی‌رسد.');
            }
            $secrets['neshan_service_api_key'] = $serviceKey;
        }

        $tmp = $secretsFile . '.tmp-' . bin2hex(random_bytes(4));
        $body = "<?php\nreturn " . var_export($secrets, true) . ";\n";
        if (file_put_contents($tmp, $body, LOCK_EX) === false) {
            throw new RuntimeException('ذخیره تنظیمات نقشه ممکن نشد.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $secretsFile)) {
            @unlink($tmp);
            throw new RuntimeException('جایگزینی امن فایل تنظیمات نقشه ممکن نشد.');
        }
        @chmod($secretsFile, 0600);
        $message = 'کلیدهای نشان ذخیره شدند. Web Map Key در Build بعدی Passenger استفاده می‌شود.';
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$secrets = is_file($secretsFile) ? require $secretsFile : [];
if (!is_array($secrets)) $secrets = [];
$hasWeb = trim((string)($secrets['neshan_web_map_key'] ?? $secrets['neshan_map_key'] ?? '')) !== '';
$hasService = trim((string)($secrets['neshan_service_api_key'] ?? $secrets['neshan_reverse_api_key'] ?? '')) !== '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تنظیمات نقشه RADO</title>
<style>
:root{--gold:#f5b400;--black:#171717;--bg:#f4f4f1}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Tahoma,Arial;color:var(--black)}.wrap{max-width:760px;margin:32px auto;padding:16px}.top,.card{background:#fff;border-radius:24px;padding:22px;margin-bottom:14px;box-shadow:0 8px 28px #0000000b}.brand{font-size:34px;font-weight:900}.gold{color:var(--gold)}.muted{color:#666;font-size:12px;line-height:1.9}.status{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.pill{padding:7px 10px;border-radius:99px;background:#eef7ef;color:#17652d;font-size:11px}.pill.off{background:#fff0f0;color:#a31d1d}label{display:block;font-size:12px;font-weight:900;margin-top:14px}input{width:100%;padding:13px;border:1px solid #ddd;border-radius:13px;margin-top:6px;direction:ltr;text-align:left}button{width:100%;margin-top:18px;padding:14px;border:0;border-radius:14px;background:var(--black);color:#fff;font-weight:900;font-size:15px}.warn{background:#fff5cf;color:#6b5200;padding:12px;border-radius:13px;font-size:12px;line-height:1.9}.ok{background:#e9f8ed;color:#17652d;padding:12px;border-radius:13px}.err{background:#fff0f0;color:#a31d1d;padding:12px;border-radius:13px}a{color:#171717;font-weight:800;text-decoration:none}</style>
</head>
<body><div class="wrap">
<div class="top"><div class="brand">R<span class="gold">A</span>DO MAPS</div><div class="muted">تفکیک امن کلید نمایش نقشه و سرویس‌های مسیریابی نشان</div><p><a href="/admin/">← بازگشت به پنل مدیریت</a></p></div>
<?php if($message):?><div class="ok"><?=e_map($message)?></div><?php endif;?>
<?php if($error):?><div class="err"><?=e_map($error)?></div><?php endif;?>
<div class="card">
<div class="status"><span class="pill <?=$hasWeb?'':'off'?>">Web Map Key: <?=$hasWeb?'تنظیم شده ✓':'تنظیم نشده'?></span><span class="pill <?=$hasService?'':'off'?>">Service API Key: <?=$hasService?'تنظیم شده ✓':'تنظیم نشده'?></span></div>
<div class="warn"><b>مهم:</b> پکیج فعلی Flutter نقشه نشان را روی موبایل داخل WebView اجرا می‌کند؛ بنابراین کلید نمایش نقشه باید از نوع <b>Web Key</b> باشد. Service/Android/iOS Key را در فیلد اول وارد نکن.</div>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=e_map($csrf)?>">
<label>Neshan Web Map Key — نمایش نقشه داخل Passenger</label>
<input name="neshan_web_map_key" type="password" required placeholder="Web Map Key">
<label>Neshan Service API Key — جستجو، Reverse Geocoding و مسیریابی سرور</label>
<input name="neshan_service_api_key" type="password" placeholder="اگر خالی بماند مقدار فعلی حفظ می‌شود">
<button type="submit">ذخیره تنظیمات نقشه</button>
</form>
<p class="muted">پس از تغییر Web Map Key باید Passenger دوباره Build شود؛ کلید در سورس عمومی GitHub ذخیره نمی‌شود.</p>
</div>
</div></body></html>
