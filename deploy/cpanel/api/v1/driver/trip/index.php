<?php
declare(strict_types=1);
require dirname(__DIR__, 5) . '/rado-system/lib/app.php';

try {
    $pdo = rado_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $clientId = trim((string)($_GET['client_id'] ?? ''));
        $driver = rado_require_approved_driver($pdo, $clientId);
        $driverId = (string)$driver['id'];
        $stmt = $pdo->prepare("SELECT id FROM trips WHERE driver_id=? AND status IN ('driver_assigned','driver_arriving','arrived','in_progress') ORDER BY accepted_at DESC LIMIT 1");
        $stmt->execute([$driverId]);
        $tripId = $stmt->fetchColumn();
        if ($tripId === false) {
            rado_json(200, ['ok'=>true,'trip'=>null,'server_time'=>rado_time_payload()]);
        }
        $row = rado_trip_row($pdo, (string)$tripId);
        rado_json(200, ['ok'=>true,'trip'=>$row ? rado_trip_payload($row) : null,'server_time'=>rado_time_payload()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
    }

    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $tripId = trim((string)($body['trip_id'] ?? ''));
    $action = trim((string)($body['action'] ?? ''));
    $reason = trim((string)($body['reason'] ?? ''));
    if ($tripId === '' || !in_array($action, ['arrived','start','complete','cancel'], true)) {
        rado_json(422, ['ok'=>false,'error'=>'invalid_trip_action']);
    }

    $driver = rado_require_approved_driver($pdo, $clientId);
    $driverId = (string)$driver['id'];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM trips WHERE id=? FOR UPDATE');
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch();
    if (!is_array($trip)) {
        $pdo->rollBack();
        rado_json(404, ['ok'=>false,'error'=>'trip_not_found']);
    }
    if ((string)($trip['driver_id'] ?? '') !== $driverId) {
        $pdo->rollBack();
        rado_json(403, ['ok'=>false,'error'=>'trip_not_owned_by_driver']);
    }

    $status = (string)$trip['status'];
    $finance = null;

    if ($action === 'arrived') {
        if (!in_array($status, ['driver_assigned','driver_arriving'], true)) {
            $pdo->rollBack();
            rado_json(409, ['ok'=>false,'error'=>'invalid_transition','message'=>'در این مرحله امکان ثبت «رسیدم» وجود ندارد.']);
        }
        $stmt = $pdo->prepare("UPDATE trips SET status='arrived',arrived_at=NOW(),version=version+1 WHERE id=?");
        $stmt->execute([$tripId]);
    }

    if ($action === 'start') {
        if ($status !== 'arrived') {
            $pdo->rollBack();
            rado_json(409, ['ok'=>false,'error'=>'invalid_transition','message'=>'ابتدا باید رسیدن به مبدا ثبت شود.']);
        }
        $stmt = $pdo->prepare("UPDATE trips SET status='in_progress',started_at=NOW(),version=version+1 WHERE id=?");
        $stmt->execute([$tripId]);
    }

    if ($action === 'complete') {
        if ($status !== 'in_progress') {
            if ($status === 'completed') {
                $pdo->commit();
                $row = rado_trip_row($pdo, $tripId);
                rado_json(200, ['ok'=>true,'trip'=>$row ? rado_trip_payload($row) : null,'already_completed'=>true]);
            }
            $pdo->rollBack();
            rado_json(409, ['ok'=>false,'error'=>'invalid_transition','message'=>'فقط سفر در حال انجام را می‌توان پایان داد.']);
        }
        $finalFare = (int)($trip['estimated_fare'] ?? 0);
        $stmt = $pdo->prepare("UPDATE trips SET status='completed',final_fare=?,completed_at=NOW(),version=version+1 WHERE id=?");
        $stmt->execute([$finalFare,$tripId]);
        $trip['final_fare'] = $finalFare;
        $trip['commission_rate'] = (float)($driver['commission_rate'] ?? 0);
        $finance = rado_complete_trip_finance($pdo, $trip);
    }

    if ($action === 'cancel') {
        if (!in_array($status, ['driver_assigned','driver_arriving','arrived'], true)) {
            $pdo->rollBack();
            rado_json(409, ['ok'=>false,'error'=>'invalid_transition','message'=>'در این مرحله امکان لغو سفر وجود ندارد.']);
        }
        $stmt = $pdo->prepare("UPDATE trips SET status='cancelled_by_driver',cancelled_at=NOW(),cancellation_reason=?,version=version+1 WHERE id=?");
        $stmt->execute([$reason !== '' ? $reason : 'لغو توسط راننده',$tripId]);
    }

    $pdo->commit();
    $row = rado_trip_row($pdo, $tripId);
    rado_json(200, [
        'ok'=>true,
        'trip'=>$row ? rado_trip_payload($row) : null,
        'finance'=>$finance,
        'server_time'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    rado_json(500, ['ok'=>false,'error'=>'driver_trip_action_failed']);
}
