<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/platform.php';

try {
    $pdo = rado_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $clientId = trim((string)($_GET['client_id'] ?? ''));
        $driver = rado_require_approved_driver($pdo, $clientId);
        $driverId = (string)$driver['id'];

        $presenceStmt = $pdo->prepare('SELECT * FROM driver_presence WHERE driver_id=? LIMIT 1');
        $presenceStmt->execute([$driverId]);
        $presence = $presenceStmt->fetch();
        if (is_array($presence) && (int)$presence['is_online'] === 1) {
            if ($presence['latitude'] !== null && $presence['longitude'] !== null) {
                rado_offer_waiting_trip_to_driver_v2($pdo, $driverId, (float)$presence['latitude'], (float)$presence['longitude']);
            } else {
                rado_reoffer_expired_to_driver_v2($pdo, $driverId);
            }
        }

        $activeStmt = $pdo->prepare("SELECT id FROM trips WHERE driver_id=? AND status IN ('driver_assigned','driver_arriving','arrived','in_progress') ORDER BY accepted_at DESC LIMIT 1");
        $activeStmt->execute([$driverId]);
        $activeId = $activeStmt->fetchColumn();
        $activeTrip = $activeId === false ? null : rado_trip_row($pdo, (string)$activeId);

        $stmt = $pdo->prepare("SELECT o.id offer_id,o.offered_at,o.expires_at,t.* FROM trip_offers o JOIN trips t ON t.id=o.trip_id WHERE o.driver_id=? AND o.accepted IS NULL AND o.responded_at IS NULL AND o.expires_at>NOW() AND t.status='searching' ORDER BY o.offered_at ASC LIMIT 5");
        $stmt->execute([$driverId]);
        $offers = [];
        foreach ($stmt->fetchAll() as $row) {
            $offers[] = [
                'offer_id'=>(int)$row['offer_id'],
                'trip'=>rado_trip_payload($row),
                'offered_at'=>rado_time_payload((string)$row['offered_at']),
                'expires_at'=>rado_time_payload((string)$row['expires_at']),
            ];
        }

        rado_json(200, [
            'ok'=>true,
            'offers'=>$offers,
            'active_trip'=>$activeTrip ? rado_trip_payload($activeTrip) : null,
            'online'=>is_array($presence) && (int)$presence['is_online'] === 1,
            'last_seen'=>is_array($presence) && !empty($presence['last_seen_at']) ? rado_time_payload((string)$presence['last_seen_at']) : null,
            'server_time'=>rado_time_payload(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
    }

    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $offerId = filter_var($body['offer_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string)($body['action'] ?? '');
    if ($offerId === false || $offerId < 1 || !in_array($action, ['accept','reject'], true)) {
        rado_json(422, ['ok'=>false,'error'=>'invalid_offer_action']);
    }

    $driver = rado_require_approved_driver($pdo, $clientId);
    $driverId = (string)$driver['id'];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM trip_offers WHERE id=? AND driver_id=? FOR UPDATE');
    $stmt->execute([$offerId,$driverId]);
    $offer = $stmt->fetch();
    if (!is_array($offer)) {
        $pdo->rollBack();
        rado_json(404, ['ok'=>false,'error'=>'offer_not_found']);
    }
    if ($offer['responded_at'] !== null || $offer['accepted'] !== null) {
        $pdo->rollBack();
        rado_json(409, ['ok'=>false,'error'=>'offer_already_answered','message'=>'این درخواست قبلاً پاسخ داده شده است.']);
    }
    if (strtotime((string)$offer['expires_at']) < time()) {
        $pdo->rollBack();
        rado_json(409, ['ok'=>false,'error'=>'offer_expired','message'=>'زمان این درخواست به پایان رسیده است.']);
    }

    $tripId = (string)$offer['trip_id'];
    $stmt = $pdo->prepare('SELECT * FROM trips WHERE id=? FOR UPDATE');
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch();
    if (!is_array($trip)) {
        $pdo->rollBack();
        rado_json(404, ['ok'=>false,'error'=>'trip_not_found']);
    }

    if ($action === 'reject') {
        $stmt = $pdo->prepare('UPDATE trip_offers SET responded_at=NOW(),accepted=0 WHERE id=?');
        $stmt->execute([$offerId]);
        $pdo->commit();
        rado_json(200, ['ok'=>true,'rejected'=>true,'server_time'=>rado_time_payload()]);
    }

    if ((string)$trip['status'] !== 'searching' || $trip['driver_id'] !== null) {
        $stmt = $pdo->prepare('UPDATE trip_offers SET responded_at=NOW(),accepted=0 WHERE id=?');
        $stmt->execute([$offerId]);
        $pdo->commit();
        rado_json(409, ['ok'=>false,'error'=>'trip_taken','message'=>'این سفر توسط راننده دیگری پذیرفته شده است.']);
    }

    $stmt = $pdo->prepare("UPDATE trips SET driver_id=?,status='driver_arriving',accepted_at=NOW(),version=version+1 WHERE id=? AND status='searching' AND driver_id IS NULL");
    $stmt->execute([$driverId,$tripId]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        rado_json(409, ['ok'=>false,'error'=>'trip_taken','message'=>'این سفر توسط راننده دیگری پذیرفته شده است.']);
    }

    $stmt = $pdo->prepare('UPDATE trip_offers SET responded_at=NOW(),accepted=1 WHERE id=?');
    $stmt->execute([$offerId]);
    $stmt = $pdo->prepare('UPDATE trip_offers SET responded_at=COALESCE(responded_at,NOW()),accepted=COALESCE(accepted,0) WHERE trip_id=? AND id<>?');
    $stmt->execute([$tripId,$offerId]);
    $pdo->commit();

    rado_platform_event($pdo,'trip:'.$tripId,'driver_assigned',['driver_id'=>$driverId,'auto'=>false]);
    $row = rado_trip_row($pdo, $tripId);
    rado_json(200, [
        'ok'=>true,
        'accepted'=>true,
        'trip'=>$row ? rado_trip_payload($row) : null,
        'server_time'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $id=substr(bin2hex(random_bytes(8)),0,12);
    try{$dir=rado_root().'/rado-system/state';@mkdir($dir,0755,true);@file_put_contents($dir.'/driver-offers-errors.log','['.rado_jalali_datetime(null,true).'] '.$id.' '.$e->getMessage()."\n",FILE_APPEND|LOCK_EX);}catch(Throwable){}
    rado_json(500, ['ok'=>false,'error'=>'driver_offers_failed','request_id'=>$id]);
}
