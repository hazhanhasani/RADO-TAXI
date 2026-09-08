<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/rado-system/lib/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$app = $_GET['app'] ?? '';
if (!in_array($app, ['passenger', 'driver'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_app'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$file = $root . '/rado-system/state/release.json';
if (!is_file($file)) {
    http_response_code(503);
    echo json_encode(['error' => 'release_manifest_not_ready'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$data = json_decode((string) file_get_contents($file), true);
if (!is_array($data) || !isset($data['apps'][$app])) {
    http_response_code(503);
    echo json_encode(['error' => 'release_manifest_invalid'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = require $root . '/rado-system/config.php';
$item = $data['apps'][$app];
$item['download_url'] = rtrim($config['domain'], '/') . '/downloads/' . basename((string)($item['asset'] ?? ''));
$item['checked_at'] = rado_jalali_datetime(null, true);
$item['checked_at_long'] = rado_jalali_long();
$item['timezone'] = 'Asia/Tehran';

echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
