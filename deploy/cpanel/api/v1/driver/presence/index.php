<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}

try {
    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $online = filter_var($body['online'] ?? false, FILTER_VALIDATE_BOOL);

    $pdo = rado_db();
    $driver = rado_require_approved_driver($pdo, $clientId);
    $driverId = (string)$driver['id'];

    $stmt = $pdo->prepare("INSERT INTO driver_presence(driver_id,is_online,last_seen_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE is_online=VALUES(is_online),last_seen_at=NOW()");
    $stmt->execute([$driverId,$online?1:0]);

    rado_json(200, [
        'ok'=>true,
        'online'=>$online,
        'driver_id'=>$driverId,
        'updated_at'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    rado_json(500, ['ok'=>false,'error'=>'driver_presence_failed']);
}
