<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/platform.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}

try {
    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $tripId = trim((string)($body['trip_id'] ?? ''));
    if ($clientId === '' || $tripId === '') {
        rado_json(422, ['ok'=>false,'error'=>'client_and_trip_required']);
    }
    if (!is_numeric($body['lat'] ?? null) || !is_numeric($body['lng'] ?? null)) {
        rado_json(422, ['ok'=>false,'error'=>'location_required']);
    }

    $lat = (float)$body['lat'];
    $lng = (float)$body['lng'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        rado_json(422, ['ok'=>false,'error'=>'invalid_location']);
    }
    $accuracy = is_numeric($body['accuracy_m'] ?? null)
        ? max(0.0, min(5000.0, (float)$body['accuracy_m']))
        : null;
    $heading = is_numeric($body['heading'] ?? null)
        ? max(0, min(359, (int)$body['heading']))
        : null;
    $speed = is_numeric($body['speed_kph'] ?? null)
        ? max(0.0, min(200.0, (float)$body['speed_kph']))
        : null;

    $pdo = rado_db();
    $passengerId = rado_passenger_from_client($pdo, $clientId);
    if (!$passengerId) {
        rado_json(403, ['ok'=>false,'error'=>'unauthorized']);
    }

    $tripStmt = $pdo->prepare(
        "SELECT id,status FROM trips
         WHERE id=? AND passenger_id=?
           AND status IN('requested','searching','driver_assigned','driver_arriving','arrived','in_progress')
         LIMIT 1"
    );
    $tripStmt->execute([$tripId, $passengerId]);
    $trip = $tripStmt->fetch();
    if (!is_array($trip)) {
        rado_json(409, ['ok'=>false,'error'=>'trip_not_active']);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO passenger_presence(passenger_id,trip_id,latitude,longitude,accuracy_m,heading,speed_kph,last_seen_at)
         VALUES(?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
           trip_id=VALUES(trip_id),latitude=VALUES(latitude),longitude=VALUES(longitude),
           accuracy_m=VALUES(accuracy_m),heading=VALUES(heading),speed_kph=VALUES(speed_kph),last_seen_at=NOW()"
    );
    $stmt->execute([$passengerId,$tripId,$lat,$lng,$accuracy,$heading,$speed]);

    $payload = [
        'passenger_id'=>$passengerId,
        'trip_id'=>$tripId,
        'lat'=>$lat,
        'lng'=>$lng,
        'accuracy_m'=>$accuracy,
        'heading'=>$heading,
        'speed_kph'=>$speed,
    ];
    rado_platform_event($pdo, 'trip:'.$tripId, 'passenger_location', $payload, 90);

    rado_json(200, [
        'ok'=>true,
        'trip_id'=>$tripId,
        'updated_at'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    $id = substr(bin2hex(random_bytes(8)), 0, 12);
    try {
        $dir = rado_root().'/rado-system/state';
        @mkdir($dir,0755,true);
        @file_put_contents(
            $dir.'/passenger-presence-errors.log',
            '['.rado_jalali_datetime(null,true).'] '.$id.' '.$e->getMessage()."\n",
            FILE_APPEND|LOCK_EX
        );
    } catch (Throwable) {}
    rado_json(500, [
        'ok'=>false,
        'error'=>'passenger_presence_failed',
        'request_id'=>$id,
    ]);
}
