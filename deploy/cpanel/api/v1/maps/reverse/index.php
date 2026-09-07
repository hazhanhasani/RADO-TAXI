<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$root = dirname(__DIR__, 4);
$secretsFile = $root . '/rado-system/private/ci-secrets.php';
$cacheDir = $root . '/rado-system/state/reverse-geocoding';

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!is_file($secretsFile)) {
    respond(503, ['ok' => false, 'error' => 'neshan_not_configured']);
}

$latRaw = $_GET['lat'] ?? null;
$lngRaw = $_GET['lng'] ?? null;
if (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
    respond(422, ['ok' => false, 'error' => 'invalid_coordinates']);
}

$lat = (float)$latRaw;
$lng = (float)$lngRaw;
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    respond(422, ['ok' => false, 'error' => 'coordinates_out_of_range']);
}

$secrets = require $secretsFile;
$apiKey = trim((string)($secrets['neshan_reverse_api_key'] ?? $secrets['neshan_service_api_key'] ?? $secrets['neshan_map_key'] ?? ''));
if ($apiKey === '') {
    respond(503, ['ok' => false, 'error' => 'neshan_api_key_missing']);
}

@mkdir($cacheDir, 0755, true);
$cacheKey = hash('sha256', number_format($lat, 5, '.', '') . ',' . number_format($lng, 5, '.', ''));
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
if (is_file($cacheFile) && filemtime($cacheFile) > time() - 2592000) {
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cached) && !empty($cached['formatted_address'])) {
        $cached['cached'] = true;
        respond(200, $cached);
    }
}

$url = 'https://api.neshan.org/v2/reverse?lat=' . rawurlencode((string)$lat) . '&lng=' . rawurlencode((string)$lng);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Api-Key: ' . $apiKey,
        'User-Agent: RADO-TAXI/1.0',
    ],
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    respond(502, [
        'ok' => false,
        'error' => 'neshan_reverse_failed',
        'upstream_status' => $status,
        'detail' => $error !== '' ? $error : null,
    ]);
}

$data = json_decode((string)$body, true);
if (!is_array($data)) {
    respond(502, ['ok' => false, 'error' => 'invalid_neshan_response']);
}

$formatted = trim((string)($data['formatted_address'] ?? ''));
if ($formatted === '') {
    $parts = [];
    foreach (['city', 'neighbourhood', 'route_name'] as $field) {
        $value = trim((string)($data[$field] ?? ''));
        if ($value !== '' && !in_array($value, $parts, true)) {
            $parts[] = $value;
        }
    }
    $formatted = implode('، ', $parts);
}

if ($formatted === '') {
    respond(404, ['ok' => false, 'error' => 'address_not_found']);
}

$result = [
    'ok' => true,
    'formatted_address' => $formatted,
    'city' => $data['city'] ?? null,
    'neighbourhood' => $data['neighbourhood'] ?? null,
    'route_name' => $data['route_name'] ?? null,
    'municipality_zone' => $data['municipality_zone'] ?? null,
    'lat' => $lat,
    'lng' => $lng,
    'cached' => false,
];

@file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
respond(200, $result);
