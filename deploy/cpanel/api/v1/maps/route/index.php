<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$root = dirname(__DIR__, 4);
$secretsFile = $root . '/rado-system/private/ci-secrets.php';

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parsePoint(string $raw): ?array {
    $parts = array_map('trim', explode(',', $raw));
    if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) return null;
    $lat = (float)$parts[0];
    $lng = (float)$parts[1];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return [$lat, $lng];
}

/** Decode Google/Neshan encoded polyline into [lat,lng] points. */
function decodePolyline(string $encoded): array {
    $len = strlen($encoded);
    $index = 0;
    $lat = 0;
    $lng = 0;
    $points = [];

    while ($index < $len && count($points) < 2500) {
        $result = 0;
        $shift = 0;
        do {
            if ($index >= $len) return $points;
            $b = ord($encoded[$index++]) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20 && $shift < 35);
        $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);

        $result = 0;
        $shift = 0;
        do {
            if ($index >= $len) return $points;
            $b = ord($encoded[$index++]) - 63;
            $result |= ($b & 0x1f) << $shift;
            $shift += 5;
        } while ($b >= 0x20 && $shift < 35);
        $lng += ($result & 1) ? ~($result >> 1) : ($result >> 1);

        $points[] = ['lat' => $lat / 1e5, 'lng' => $lng / 1e5];
    }
    return $points;
}

function extractEncodedPolyline(array $route): string {
    $overview = $route['overview_polyline'] ?? $route['overviewPolyline'] ?? null;
    if (is_string($overview) && trim($overview) !== '') return trim($overview);
    if (is_array($overview)) {
        $value = $overview['points'] ?? $overview['encoded_polyline'] ?? $overview['encodedPolyline'] ?? '';
        if (is_string($value) && trim($value) !== '') return trim($value);
    }

    // Some Neshan responses expose geometry at leg/step level.
    $segments = [];
    foreach (($route['legs'] ?? []) as $leg) {
        if (!is_array($leg)) continue;
        foreach (($leg['steps'] ?? []) as $step) {
            if (!is_array($step)) continue;
            $poly = $step['polyline'] ?? $step['overview_polyline'] ?? null;
            if (is_string($poly) && trim($poly) !== '') $segments[] = trim($poly);
            elseif (is_array($poly) && is_string($poly['points'] ?? null) && trim((string)$poly['points']) !== '') $segments[] = trim((string)$poly['points']);
        }
    }
    return $segments === [] ? '' : implode('|', $segments);
}

function simplifiedPoints(array $points, int $limit = 450): array {
    $count = count($points);
    if ($count <= $limit) return $points;
    $step = max(1, (int)ceil($count / $limit));
    $out = [];
    for ($i = 0; $i < $count; $i += $step) $out[] = $points[$i];
    if ($out === [] || end($out) !== $points[$count - 1]) $out[] = $points[$count - 1];
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(405, ['ok'=>false,'error'=>'method_not_allowed']);
if (!is_file($secretsFile)) respond(503, ['ok'=>false,'error'=>'neshan_not_configured','message'=>'Service API Key نشان در پنل RADO تنظیم نشده است.']);

$origin = parsePoint((string)($_GET['origin'] ?? ''));
$destination = parsePoint((string)($_GET['destination'] ?? ''));
if (!$origin || !$destination) respond(422, ['ok'=>false,'error'=>'invalid_coordinates']);

$secrets = require $secretsFile;
$apiKey = trim((string)($secrets['neshan_service_api_key'] ?? $secrets['neshan_reverse_api_key'] ?? $secrets['neshan_map_key'] ?? ''));
if ($apiKey === '') respond(503, ['ok'=>false,'error'=>'neshan_service_api_key_missing','message'=>'Service API Key نشان را از مدیریت ← نقشه و Neshan تنظیم کنید.']);
if (str_starts_with(strtolower($apiKey), 'web.')) respond(503, ['ok'=>false,'error'=>'neshan_service_key_type_invalid','message'=>'برای مسیر و جستجو باید Service API Key نشان تنظیم شود، نه Web Map Key.']);

$url = 'https://api.neshan.org/v4/direction?type=car&origin=' . rawurlencode($origin[0].','.$origin[1]) . '&destination=' . rawurlencode($destination[0].','.$destination[1]);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>6,
    CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>['Accept: application/json','Api-Key: '.$apiKey,'User-Agent: RADO-TAXI/1.0'],
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);
if ($body === false || $status < 200 || $status >= 300) {
    respond(502, ['ok'=>false,'error'=>'neshan_route_failed','message'=>'سرویس مسیریابی نشان پاسخ معتبر نداد.','upstream_status'=>$status,'detail'=>$error ?: null]);
}
$data = json_decode((string)$body, true);
if (!is_array($data) || empty($data['routes'][0]) || !is_array($data['routes'][0])) respond(502, ['ok'=>false,'error'=>'invalid_neshan_response']);
$route = $data['routes'][0];
$distance = 0;
$duration = 0;
foreach (($route['legs'] ?? []) as $leg) {
    if (!is_array($leg)) continue;
    $d = $leg['distance']['value'] ?? $leg['distance'] ?? 0;
    $t = $leg['duration']['value'] ?? $leg['duration'] ?? 0;
    if (is_numeric($d)) $distance += (int)$d;
    if (is_numeric($t)) $duration += (int)$t;
}
if ($distance <= 0) {
    $d = $route['distance']['value'] ?? $route['distance'] ?? 0;
    if (is_numeric($d)) $distance = (int)$d;
}
if ($duration <= 0) {
    $t = $route['duration']['value'] ?? $route['duration'] ?? 0;
    if (is_numeric($t)) $duration = (int)$t;
}
if ($distance <= 0 || $duration <= 0) respond(502, ['ok'=>false,'error'=>'route_metrics_missing']);

$routePoints = [];
$encoded = extractEncodedPolyline($route);
if ($encoded !== '') {
    if (str_contains($encoded, '|')) {
        foreach (explode('|', $encoded) as $segment) {
            foreach (decodePolyline($segment) as $point) {
                if ($routePoints === [] || $routePoints[count($routePoints)-1] !== $point) $routePoints[] = $point;
            }
        }
    } else {
        $routePoints = decodePolyline($encoded);
    }
}
if (count($routePoints) < 2) {
    $routePoints = [
        ['lat'=>$origin[0], 'lng'=>$origin[1]],
        ['lat'=>$destination[0], 'lng'=>$destination[1]],
    ];
}
$routePoints = simplifiedPoints($routePoints);

respond(200, [
    'ok'=>true,
    'distance_meters'=>$distance,
    'duration_seconds'=>$duration,
    'route_points'=>$routePoints,
    'geometry_source'=>$encoded !== '' ? 'neshan_polyline' : 'fallback_endpoints',
    'source'=>'neshan',
]);
