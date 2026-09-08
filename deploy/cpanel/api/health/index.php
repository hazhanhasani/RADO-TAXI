<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/rado-system/lib/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonResponse(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (($_GET['service'] ?? '') === 'reverse') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    $latRaw = $_GET['lat'] ?? null;
    $lngRaw = $_GET['lng'] ?? null;
    if (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
        jsonResponse(422, ['ok' => false, 'error' => 'invalid_coordinates']);
    }

    $lat = (float)$latRaw;
    $lng = (float)$lngRaw;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        jsonResponse(422, ['ok' => false, 'error' => 'coordinates_out_of_range']);
    }

    $secretsFile = $root . '/rado-system/private/ci-secrets.php';
    if (!is_file($secretsFile)) {
        jsonResponse(503, ['ok' => false, 'error' => 'neshan_not_configured']);
    }

    $secrets = require $secretsFile;
    $apiKey = trim((string)($secrets['neshan_reverse_api_key'] ?? $secrets['neshan_service_api_key'] ?? $secrets['neshan_map_key'] ?? ''));
    if ($apiKey === '') {
        jsonResponse(503, ['ok' => false, 'error' => 'neshan_api_key_missing']);
    }

    // v2 cache key intentionally invalidates old address-only cache entries.
    $cacheDir = $root . '/rado-system/state/reverse-geocoding-v2';
    @mkdir($cacheDir, 0755, true);
    $cacheKey = hash('sha256', 'v2|' . number_format($lat, 5, '.', '') . ',' . number_format($lng, 5, '.', ''));
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 2592000) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['formatted_address'])) {
            $cached['cached'] = true;
            jsonResponse(200, $cached);
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
    $upstreamStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $upstreamStatus < 200 || $upstreamStatus >= 300) {
        jsonResponse(502, [
            'ok' => false,
            'error' => 'neshan_reverse_failed',
            'upstream_status' => $upstreamStatus,
            'detail' => $curlError !== '' ? $curlError : null,
        ]);
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        jsonResponse(502, ['ok' => false, 'error' => 'invalid_neshan_response']);
    }

    $place = trim((string)($data['place'] ?? ''));
    $address = trim((string)($data['formatted_address'] ?? ''));
    if ($address === '') {
        $parts = [];
        foreach (['state', 'city', 'neighbourhood', 'route_name'] as $field) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        $address = implode('، ', $parts);
    }

    // When Neshan recognizes a POI/building, show its exact name first.
    $displayAddress = $address;
    if ($place !== '') {
        if ($displayAddress === '') {
            $displayAddress = $place;
        } elseif (mb_stripos($displayAddress, $place, 0, 'UTF-8') === false) {
            $displayAddress = $place . '، ' . $displayAddress;
        }
    }

    if ($displayAddress === '') {
        jsonResponse(404, ['ok' => false, 'error' => 'address_not_found']);
    }

    $result = [
        'ok' => true,
        'formatted_address' => $displayAddress,
        'place_name' => $place !== '' ? $place : null,
        'address_without_place' => $address !== '' ? $address : null,
        'state' => $data['state'] ?? null,
        'city' => $data['city'] ?? null,
        'neighbourhood' => $data['neighbourhood'] ?? null,
        'route_name' => $data['route_name'] ?? null,
        'municipality_zone' => $data['municipality_zone'] ?? null,
        'cached' => false,
    ];
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    jsonResponse(200, $result);
}

$lock = $root . '/rado-system/private/installed.lock';
$dbFile = $root . '/rado-system/private/database.php';
$lastSuccessRaw = is_file($root.'/rado-system/state/last_success_at')
    ? trim((string)file_get_contents($root.'/rado-system/state/last_success_at'))
    : null;

$result = [
    'ok' => false,
    'service' => 'RADO',
    'domain' => 'rado-taxi.sbs',
    'installed' => is_file($lock),
    'php' => PHP_VERSION,
    'timezone' => 'Asia/Tehran',
    'time' => rado_jalali_datetime(null, true),
    'time_long' => rado_jalali_long(),
    'database' => 'not_configured',
    'pricing' => 'unknown',
    'updater' => [
        'last_success_at' => $lastSuccessRaw !== null && $lastSuccessRaw !== ''
            ? (preg_match('/^\d{4}-\d{2}-\d{2}T|^\d{4}-\d{2}-\d{2} /', $lastSuccessRaw) ? rado_jalali_datetime($lastSuccessRaw, true) : $lastSuccessRaw)
            : null,
        'current_tag' => is_file($root.'/rado-system/state/current_tag') ? trim((string)file_get_contents($root.'/rado-system/state/current_tag')) : null,
    ],
];

if (is_file($dbFile)) {
    try {
        $pdo = rado_db();
        $pdo->query('SELECT 1');
        $result['database'] = 'ok';
        $result['pricing'] = rado_active_pricing_rule($pdo) !== null ? 'ok' : 'not_configured';
    } catch (Throwable $e) {
        $result['database'] = 'error';
        $result['pricing'] = 'unavailable';
    }
}

$result['ok'] = $result['installed'] && $result['database'] === 'ok';
http_response_code($result['ok'] ? 200 : 503);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
