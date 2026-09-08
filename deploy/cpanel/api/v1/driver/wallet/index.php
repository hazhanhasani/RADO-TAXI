<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}

$clientId = trim((string)($_GET['client_id'] ?? ''));
try {
    $pdo = rado_db();
    $driver = rado_driver_from_client($pdo, $clientId);
    if ($driver === null) $driver = rado_guest_driver($pdo, $clientId);
    $driverId = (string)$driver['id'];

    $balance = rado_wallet_balance($pdo, $driverId);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='trip_gross' THEN amount ELSE 0 END),0) gross,COALESCE(SUM(CASE WHEN entry_type='platform_commission' THEN -amount ELSE 0 END),0) commission,COALESCE(SUM(amount),0) net FROM ledger_entries WHERE user_id=? AND DATE(created_at)=CURDATE()");
    $stmt->execute([$driverId]);
    $today = $stmt->fetch() ?: ['gross'=>0,'commission'=>0,'net'=>0];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM trips WHERE driver_id=? AND status='completed' AND DATE(completed_at)=CURDATE()");
    $stmt->execute([$driverId]);
    $todayTrips = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id,trip_id,entry_type,amount,balance_after,created_at FROM ledger_entries WHERE user_id=? ORDER BY id DESC LIMIT 30");
    $stmt->execute([$driverId]);
    $entries = [];
    foreach ($stmt->fetchAll() as $row) {
        $entries[] = [
            'id'=>(int)$row['id'],
            'trip_id'=>$row['trip_id'],
            'type'=>(string)$row['entry_type'],
            'amount'=>(int)$row['amount'],
            'balance_after'=>$row['balance_after'] === null ? null : (int)$row['balance_after'],
            'created_at'=>rado_time_payload((string)$row['created_at']),
        ];
    }

    rado_json(200, [
        'ok'=>true,
        'degraded'=>false,
        'wallet'=>[
            'balance'=>$balance,
            'commission_rate'=>(float)($driver['commission_rate'] ?? 0),
            'today_gross'=>(int)$today['gross'],
            'today_commission'=>(int)$today['commission'],
            'today_net'=>(int)$today['net'],
            'today_trips'=>$todayTrips,
            'entries'=>$entries,
        ],
        'server_time'=>rado_time_payload(),
    ]);
} catch (Throwable $e) {
    // Wallet/reporting is non-critical for receiving a trip offer. Return a safe
    // degraded payload so the driver app can still render dispatch state.
    $commission = 0.0;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $driver = rado_driver_from_client($pdo, $clientId);
            $commission = is_array($driver) ? (float)($driver['commission_rate'] ?? 0) : 0.0;
        }
        $dir=rado_root().'/rado-system/state';@mkdir($dir,0755,true);@file_put_contents($dir.'/driver-wallet-errors.log','['.rado_jalali_datetime(null,true).'] '.$e->getMessage()."\n",FILE_APPEND|LOCK_EX);
    } catch (Throwable) {}
    rado_json(200, [
        'ok'=>true,
        'degraded'=>true,
        'wallet'=>[
            'balance'=>0,
            'commission_rate'=>$commission,
            'today_gross'=>0,
            'today_commission'=>0,
            'today_net'=>0,
            'today_trips'=>0,
            'entries'=>[],
        ],
        'server_time'=>rado_time_payload(),
    ]);
}
