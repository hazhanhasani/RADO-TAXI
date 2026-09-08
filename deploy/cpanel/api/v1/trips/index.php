<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rado_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $pickup = is_array($body['pickup'] ?? null) ? $body['pickup'] : [];
    $destination = is_array($body['destination'] ?? null) ? $body['destination'] : [];

    foreach ([['pickup',$pickup],['destination',$destination]] as [$name,$point]) {
        if (!isset($point['lat'],$point['lng']) || !is_numeric($point['lat']) || !is_numeric($point['lng'])) {
            rado_json(422, ['ok'=>false,'error'=>'invalid_'.$name]);
        }
        $lat = (float)$point['lat'];
        $lng = (float)$point['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            rado_json(422, ['ok'=>false,'error'=>'invalid_'.$name]);
        }
    }

    $distance = filter_var($body['distance_meters'] ?? null, FILTER_VALIDATE_INT);
    $duration = filter_var($body['duration_seconds'] ?? null, FILTER_VALIDATE_INT);
    if ($clientId === '' || $distance === false || $duration === false || $distance < 0 || $duration < 0) {
        rado_json(422, ['ok'=>false,'error'=>'invalid_trip_request']);
    }

    $pdo = rado_db();
    $rule = rado_active_pricing_rule($pdo);
    if ($rule === null) {
        rado_json(409, [
            'ok'=>false,
            'error'=>'pricing_not_configured',
            'message'=>'تعرفه سفر هنوز در پنل مدیریت تنظیم نشده است.',
        ]);
    }

    $breakdown = rado_fare_breakdown($rule, (int)$distance, (int)$duration);
    $passengerId = rado_guest_passenger($pdo, $clientId);
    $tripId = rado_uuid4();

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO trips(id,passenger_id,status,pickup_lat,pickup_lng,destination_lat,destination_lng,pickup_label,destination_label,estimated_distance_m,estimated_duration_s,estimated_fare,requested_at) VALUES(?,?,'searching',?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $tripId,
        $passengerId,
        (float)$pickup['lat'],
        (float)$pickup['lng'],
        (float)$destination['lat'],
        (float)$destination['lng'],
        trim((string)($body['pickup_label'] ?? '')) ?: null,
        trim((string)($body['destination_label'] ?? '')) ?: null,
        (int)$distance,
        (int)$duration,
        (int)$breakdown['fare'],
    ]);
    $pdo->commit();

    $notified = rado_dispatch_trip($pdo, $tripId, (float)$pickup['lat'], (float)$pickup['lng']);
    $row = rado_trip_row($pdo, $tripId);
    $payload = $row ? rado_trip_payload($row) : [
        'id'=>$tripId,
        'status'=>'searching',
        'status_fa'=>'در جستجوی راننده',
        'estimated_fare'=>(int)$breakdown['fare'],
        'timezone'=>'Asia/Tehran',
    ];
    $payload['drivers_notified'] = $notified;

    rado_json(201, [
        'ok'=>true,
        'trip'=>$payload,
        'server_time'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rado_json(500, ['ok'=>false,'error'=>'trip_request_failed']);
}
