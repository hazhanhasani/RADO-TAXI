<?php
declare(strict_types=1);
require dirname(__DIR__, 5) . '/rado-system/lib/app.php';

try {
    $pdo = rado_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $clientId = trim((string)($_GET['client_id'] ?? ''));
        $tripId = trim((string)($_GET['trip_id'] ?? ''));
        $passengerId = rado_passenger_from_client($pdo, $clientId);
        if ($passengerId === null || $tripId === '') {
            rado_json(404, ['ok'=>false,'error'=>'trip_not_found']);
        }

        $stmt = $pdo->prepare('SELECT status,requested_at FROM trips WHERE id=? AND passenger_id=? LIMIT 1');
        $stmt->execute([$tripId,$passengerId]);
        $ownership = $stmt->fetch();
        if (!is_array($ownership)) rado_json(404, ['ok'=>false,'error'=>'trip_not_found']);

        if ((string)$ownership['status'] === 'searching' && strtotime((string)$ownership['requested_at']) < time() - 600) {
            $expire = $pdo->prepare("UPDATE trips SET status='expired',version=version+1 WHERE id=? AND passenger_id=? AND status='searching'");
            $expire->execute([$tripId,$passengerId]);
        }

        $row = rado_trip_row($pdo, $tripId);
        rado_json(200, [
            'ok'=>true,
            'trip'=>$row ? rado_trip_payload($row) : null,
            'server_time'=>rado_time_payload(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
    }

    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $tripId = trim((string)($body['trip_id'] ?? ''));
    $action = trim((string)($body['action'] ?? ''));
    if ($action !== 'cancel' || $tripId === '') {
        rado_json(422, ['ok'=>false,'error'=>'invalid_trip_action']);
    }
    $passengerId = rado_passenger_from_client($pdo, $clientId);
    if ($passengerId === null) rado_json(404, ['ok'=>false,'error'=>'trip_not_found']);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM trips WHERE id=? AND passenger_id=? FOR UPDATE');
    $stmt->execute([$tripId,$passengerId]);
    $trip = $stmt->fetch();
    if (!is_array($trip)) {
        $pdo->rollBack();
        rado_json(404, ['ok'=>false,'error'=>'trip_not_found']);
    }
    $status = (string)$trip['status'];
    if (!in_array($status, ['requested','searching','driver_assigned','driver_arriving','arrived'], true)) {
        $pdo->rollBack();
        rado_json(409, ['ok'=>false,'error'=>'cannot_cancel_trip','message'=>'بعد از شروع سفر امکان لغو از اپ مسافر وجود ندارد.']);
    }
    $stmt = $pdo->prepare("UPDATE trips SET status='cancelled_by_passenger',cancelled_at=NOW(),cancellation_reason='لغو توسط مسافر',version=version+1 WHERE id=?");
    $stmt->execute([$tripId]);
    $pdo->commit();

    $row = rado_trip_row($pdo, $tripId);
    rado_json(200, ['ok'=>true,'trip'=>$row ? rado_trip_payload($row) : null,'server_time'=>rado_time_payload()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    rado_json(500, ['ok'=>false,'error'=>'passenger_trip_status_failed']);
}
