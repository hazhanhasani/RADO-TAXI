<?php
declare(strict_types=1);
$root = __DIR__;
$private = $root . '/rado-system/private';
@mkdir($private, 0700, true);
$lock = $private . '/installed.lock';
$bootstrap = $private . '/bootstrap-signing.php';
$message = '';
$error = '';

function readSigningZip(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is not enabled.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Invalid signing ZIP.');
    $cred = $zip->getFromName('RADO-SIGNING-CREDENTIALS.txt');
    $key = $zip->getFromName('rado-release.keystore');
    $zip->close();
    if ($cred === false || $key === false) throw new RuntimeException('Signing ZIP is incomplete.');
    if (!preg_match('/ANDROID_KEYSTORE_PASSWORD=(.+)/', $cred, $m1)) throw new RuntimeException('Store password missing.');
    if (!preg_match('/ANDROID_KEY_ALIAS=(.+)/', $cred, $m2)) throw new RuntimeException('Key alias missing.');
    if (!preg_match('/ANDROID_KEY_PASSWORD=(.+)/', $cred, $m3)) throw new RuntimeException('Key password missing.');
    return [
        'store_password' => trim($m1[1]),
        'key_alias' => trim($m2[1]),
        'key_password' => trim($m3[1]),
        'keystore' => $key,
    ];
}

if (is_file($lock)) {
    $message = 'RADO already configured. Setup is locked.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $neshan = trim((string)($_POST['neshan_map_key'] ?? ''));
        if ($neshan === '') throw new RuntimeException('کلید نشان را وارد کنید.');
        if (is_file($bootstrap) && is_file($private . '/rado-release.keystore')) {
            $sign = require $bootstrap;
            $sign['keystore'] = (string)file_get_contents($private . '/rado-release.keystore');
        } else {
            if (!isset($_FILES['signing_zip']) || ($_FILES['signing_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('فایل RADO Android permanent signing ZIP را انتخاب کنید.');
            }
            $sign = readSigningZip($_FILES['signing_zip']['tmp_name']);
            file_put_contents($private . '/rado-release.keystore', $sign['keystore'], LOCK_EX);
        }
        $data = "<?php\nreturn " . var_export([
            'store_password' => (string)$sign['store_password'],
            'key_alias' => (string)$sign['key_alias'],
            'key_password' => (string)$sign['key_password'],
            'neshan_map_key' => $neshan,
        ], true) . ";\n";
        if (file_put_contents($private . '/ci-secrets.php', $data, LOCK_EX) === false) throw new RuntimeException('Cannot save secure configuration.');
        @chmod($private . '/ci-secrets.php', 0600);
        @chmod($private . '/rado-release.keystore', 0600);
        @unlink($bootstrap);
        file_put_contents($lock, gmdate('c') . "\n", LOCK_EX);
        @chmod($lock, 0600);
        $message = 'RADO امن فعال شد. GitHub Actions از این پس با OIDC امضای ثابت و کلید نشان را دریافت می‌کند.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$needsUpload = !is_file($bootstrap);
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RADO Setup</title><style>body{font-family:Tahoma,Arial;background:#f5f5f3;color:#171717;margin:0}.box{max-width:620px;margin:8vh auto;background:#fff;padding:28px;border-radius:24px;box-shadow:0 12px 40px #0001}.logo{font-size:42px;font-weight:900}.gold{color:#d99b00}input,button{width:100%;box-sizing:border-box;padding:14px 16px;border-radius:14px;font-size:16px}input{border:1px solid #ddd;margin:10px 0 18px}button{border:0;background:#171717;color:#fff;font-weight:700}.ok{background:#e8f7ec;padding:14px;border-radius:14px}.err{background:#fff0f0;padding:14px;border-radius:14px}small{color:#666;line-height:1.9;display:block}</style></head><body><div class="box"><div class="logo">R<span class="gold">A</span>DO</div><h2>راه‌اندازی امن RADO</h2><?php if($message):?><p class="ok"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><?php elseif($error):?><p class="err"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></p><?php endif;?><?php if(!is_file($lock)):?><form method="post" enctype="multipart/form-data" autocomplete="off"><label>API Key نقشه نشان</label><input name="neshan_map_key" type="password" required><?php if($needsUpload):?><label>RADO Android permanent signing ZIP</label><input name="signing_zip" type="file" accept=".zip" required><?php endif;?><small>اطلاعات فقط در پوشه محافظت‌شده هاست ذخیره می‌شوند و داخل GitHub عمومی قرار نمی‌گیرند.</small><p><button type="submit">فعال‌سازی RADO</button></p></form><?php endif;?><small>پس از فعال‌سازی، setup.php را حذف کنید. Cron: php -q <?=htmlspecialchars($root . '/rado-system/bin/rado-update.php',ENT_QUOTES,'UTF-8')?></small></div></body></html>
