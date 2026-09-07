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
    $lat = (float)$parts[0]; $lng = (float)$parts[1];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return [$lat,$lng];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(405, ['ok'=>false,'error'=>'method_not_allowed']);
if (!is_file($secretsFile)) respond(503, ['ok'=>false,'error'=>'neshan_not_configured']);
$origin = parsePoint((string)($_GET['origin'] ?? ''));
$destination = parsePoint((string)($_GET['destination'] ?? ''));
if (!$origin || !$destination) respond(422, ['ok'=>false,'error'=>'invalid_coordinates']);

$secrets = require $secretsFile;
$apiKey = trim((string)($secrets['neshan_service_api_key'] ?? $secrets['neshan_reverse_api_key'] ?? $secrets['neshan_map_key'] ?? ''));
if ($apiKey === '') respond(503, ['ok'=>false,'error'=>'neshan_api_key_missing']);

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
    respond(502, ['ok'=>false,'error'=>'neshan_route_failed','upstream_status'=>$status,'detail'=>$error ?: null]);
}
$data = json_decode((string)$body, true);
if (!is_array($data) || empty($data['routes'][0])) respond(502, ['ok'=>false,'error'=>'invalid_neshan_response']);
$route = $data['routes'][0];
$distance = 0; $duration = 0;
foreach (($route['legs'] ?? []) as $leg) {
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
respond(200, ['ok'=>true,'distance_meters'=>$distance,'duration_seconds'=>$duration,'source'=>'neshan']);
