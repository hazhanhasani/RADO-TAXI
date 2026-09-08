<?php
declare(strict_types=1);
require dirname(__DIR__, 5) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}

try {
    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $online = filter_var($body['online'] ?? false, FILTER_VALIDATE_BOOL);
    $lat = $body['lat'] ?? null;
    $lng = $body['lng'] ?? null;
    $heading = isset($body['heading']) && is_numeric($body['heading']) ? (int)$body['heading'] : null;
    $speed = isset($body['speed_kph']) && is_numeric($body['speed_kph']) ? max(0, (float)$body['speed_kph']) : null;

    if ($online && (!is_numeric($lat) || !is_numeric($lng))) {
        rado_json(422, ['ok'=>false,'error'=>'location_required','message'=>'برای آنلاین شدن، موقعیت مکانی لازم است.']);
    }

    $pdo = rado_db();
    $driver = rado_require_approved_driver($pdo, $clientId);
    $driverId = (string)$driver['id'];

    $latitude = is_numeric($lat) ? (float)$lat : null;
    $longitude = is_numeric($lng) ? (float)$lng : null;
    if ($latitude !== null && ($latitude < -90 || $latitude > 90)) rado_json(422, ['ok'=>false,'error'=>'invalid_latitude']);
    if ($longitude !== null && ($longitude < -180 || $longitude > 180)) rado_json(422, ['ok'=>false,'error'=>'invalid_longitude']);

    $stmt = $pdo->prepare("INSERT INTO driver_presence(driver_id,is_online,latitude,longitude,heading,speed_kph,last_seen_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE is_online=VALUES(is_online),latitude=COALESCE(VALUES(latitude),latitude),longitude=COALESCE(VALUES(longitude),longitude),heading=VALUES(heading),speed_kph=VALUES(speed_kph),last_seen_at=NOW()");
    $stmt->execute([$driverId,$online?1:0,$latitude,$longitude,$heading,$speed]);

    if ($online && $latitude !== null && $longitude !== null) {
        rado_offer_waiting_trip_to_driver($pdo, $driverId, $latitude, $longitude);
    }

    rado_json(200, [
        'ok'=>true,
        'online'=>$online,
        'driver_id'=>$driverId,
        'updated_at'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    rado_json(500, ['ok'=>false,'error'=>'driver_presence_failed']);
}
