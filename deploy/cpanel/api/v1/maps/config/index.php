<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}

$root = rado_root();
$file = $root . '/rado-system/private/ci-secrets.php';
if (!is_file($file)) {
    rado_json(503, ['ok'=>false,'error'=>'neshan_not_configured','message'=>'تنظیمات نقشه نشان کامل نشده است.']);
}
$secrets = require $file;
if (!is_array($secrets)) $secrets = [];
$webKey = trim((string)($secrets['neshan_web_map_key'] ?? ''));
$legacyKey = trim((string)($secrets['neshan_map_key'] ?? ''));
$usingLegacy = false;
if ($webKey === '' && $legacyKey !== '') {
    $webKey = $legacyKey;
    $usingLegacy = true;
}
if ($webKey === '') {
    rado_json(503, [
        'ok'=>false,
        'error'=>'neshan_web_map_key_missing',
        'message'=>'Web Map Key نشان در پنل مدیریت تنظیم نشده است.',
        'admin_path'=>'/admin/maps.php',
    ]);
}

header('Cache-Control: public, max-age=300');
rado_json(200, [
    'ok'=>true,
    'provider'=>'neshan',
    'engine'=>'web_sdk',
    'map_key'=>$webKey,
    'legacy_key'=>$usingLegacy,
    'timezone'=>'Asia/Tehran',
]);
