<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/rado-system/lib/app.php';
$config = require $root . '/rado-system/config.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$file = basename((string)($_GET['file'] ?? ''));
if (!preg_match('/^RADO-(Passenger|Driver)-v([0-9]+\.[0-9]+\.[0-9]+)\.apk$/', $file, $m)) {
    http_response_code(404);
    exit('Not Found');
}

$stateFile = $root . '/rado-system/state/release.json';
if (!is_file($stateFile)) {
    http_response_code(503);
    exit('Release metadata is not ready.');
}

$meta = json_decode((string)file_get_contents($stateFile), true);
if (!is_array($meta)) {
    http_response_code(503);
    exit('Release metadata is invalid.');
}

$app = $m[1] === 'Passenger' ? 'passenger' : 'driver';
$version = $m[2];
$expectedAsset = basename((string)($meta['apps'][$app]['asset'] ?? ''));
$expectedVersion = (string)($meta['apps'][$app]['version'] ?? $meta['release'] ?? '');

if ($expectedAsset !== $file || $expectedVersion !== $version) {
    http_response_code(404);
    exit('Not Found');
}

$local = __DIR__ . '/' . $file;
$expectedSha = trim((string)($meta['apps'][$app]['sha256'] ?? ''));
if (is_file($local) && ($expectedSha === '' || hash_equals($expectedSha, (string)hash_file('sha256', $local)))) {
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($local));
    readfile($local);
    exit;
}

// Emergency fallback: the update decision still comes from RADO cPanel,
// but when the shared host has not finished mirroring the large APK yet,
// send the client to the exact verified asset of the same official release.
$repo = trim((string)($config['repo'] ?? 'hazhanhasani/RADO-TAXI'));
$releaseUrl = 'https://github.com/' . rawurlencode(explode('/', $repo, 2)[0]) . '/' . rawurlencode(explode('/', $repo, 2)[1] ?? '')
    . '/releases/download/v' . rawurlencode($version) . '/' . rawurlencode($file);

header('Location: ' . $releaseUrl, true, 302);
exit;
