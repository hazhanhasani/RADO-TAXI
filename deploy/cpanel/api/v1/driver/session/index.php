<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/api_ir.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}

try {
    $body = rado_body();
    $clientId = trim((string)($body['client_id'] ?? ''));
    $pdo = rado_db();
    $driver = rado_guest_driver($pdo, $clientId);
    $driverId = (string)$driver['id'];

    $stmt = $pdo->prepare('SELECT is_online,last_seen_at FROM driver_presence WHERE driver_id=? LIMIT 1');
    $stmt->execute([$driverId]);
    $presence = $stmt->fetch();

    $verificationRequired = (rado_setting($pdo,'driver_verification_enforced','1') ?? '1') === '1';
    $verificationStatus = 'not_available';
    $verificationProgress = 0;
    $verification = null;
    try {
        $verification = rado_verification_summary($pdo,$driverId);
        $verificationStatus = (string)($verification['review_status'] ?? 'incomplete');
        $verificationProgress = (int)($verification['progress'] ?? 0);
    } catch (Throwable) {
        $verification = null;
    }
    $driverApproved = (string)($driver['status'] ?? '') === 'approved';
    $verificationApproved = !$verificationRequired || $verificationStatus === 'approved';
    $approved = $driverApproved && $verificationApproved;

    if (!$approved && is_array($presence) && (int)($presence['is_online'] ?? 0) === 1) {
        $pdo->prepare('UPDATE driver_presence SET is_online=0 WHERE driver_id=?')->execute([$driverId]);
        $presence['is_online'] = 0;
    }

    rado_json(200, [
        'ok'=>true,
        'driver'=>[
            'id'=>$driverId,
            'name'=>(string)($driver['full_name'] ?? 'راننده رادو'),
            'status'=>(string)($driver['status'] ?? 'pending'),
            'approved'=>$approved,
            'verification_required'=>$verificationRequired,
            'verification_status'=>$verificationStatus,
            'verification_progress'=>$verificationProgress,
            'commission_rate'=>(float)($driver['commission_rate'] ?? 0),
            'plate'=>(string)($driver['plate_number'] ?? ''),
            'vehicle'=>trim((string)($driver['vehicle_make'] ?? '') . ' ' . (string)($driver['vehicle_model'] ?? '')),
            'vehicle_color'=>(string)($driver['vehicle_color'] ?? ''),
            'online'=>is_array($presence) && (int)$presence['is_online'] === 1,
            'last_seen'=>is_array($presence) && !empty($presence['last_seen_at']) ? rado_time_payload((string)$presence['last_seen_at']) : null,
            'wallet_balance'=>rado_wallet_balance($pdo, $driverId),
        ],
        'verification'=>$verification,
        'server_time'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    rado_json(500, ['ok'=>false,'error'=>'driver_session_failed']);
}
