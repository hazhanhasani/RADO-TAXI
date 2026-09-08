<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

function rado_root(): string
{
    return dirname(__DIR__, 2);
}

function rado_tehran_timezone(): DateTimeZone
{
    static $tz = null;
    if (!$tz instanceof DateTimeZone) {
        $tz = new DateTimeZone('Asia/Tehran');
    }
    return $tz;
}

function rado_fa_digits(string $value): string
{
    return strtr($value, [
        '0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴',
        '5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',
    ]);
}

function rado_gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
    if ($gy > 1600) {
        $jy = 979;
        $gy -= 1600;
    } else {
        $jy = 0;
        $gy -= 621;
    }
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 365 * $gy
        + intdiv($gy2 + 3, 4)
        - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400)
        - 80
        + $gd
        + $gdm[$gm - 1];
    $jy += 33 * intdiv($days, 12053);
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function rado_tehran_datetime(DateTimeInterface|string|null $value = null): DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(rado_tehran_timezone());
    }
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return new DateTimeImmutable('now', rado_tehran_timezone());
    }
    try {
        if (preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/', $raw) === 1) {
            return (new DateTimeImmutable($raw))->setTimezone(rado_tehran_timezone());
        }
        return new DateTimeImmutable($raw, rado_tehran_timezone());
    } catch (Throwable) {
        return new DateTimeImmutable('now', rado_tehran_timezone());
    }
}

function rado_jalali_date(DateTimeInterface|string|null $value = null, bool $persianDigits = true): string
{
    $dt = rado_tehran_datetime($value);
    [$jy, $jm, $jd] = rado_gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('n'), (int)$dt->format('j'));
    $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    return $persianDigits ? rado_fa_digits($out) : $out;
}

function rado_jalali_datetime(DateTimeInterface|string|null $value = null, bool $seconds = false, bool $persianDigits = true): string
{
    $dt = rado_tehran_datetime($value);
    $out = rado_jalali_date($dt, false) . '، ' . $dt->format($seconds ? 'H:i:s' : 'H:i');
    return $persianDigits ? rado_fa_digits($out) : $out;
}

function rado_jalali_long(DateTimeInterface|string|null $value = null, bool $persianDigits = true): string
{
    $dt = rado_tehran_datetime($value);
    [$jy, $jm, $jd] = rado_gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('n'), (int)$dt->format('j'));
    $weekdays = [1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه',6=>'شنبه',7=>'یکشنبه'];
    $months = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
    $out = $weekdays[(int)$dt->format('N')] . '، ' . $jd . ' ' . $months[$jm] . ' ' . $jy . ' — ساعت ' . $dt->format('H:i');
    return $persianDigits ? rado_fa_digits($out) : $out;
}

function rado_time_payload(DateTimeInterface|string|null $value = null): array
{
    $dt = rado_tehran_datetime($value);
    return [
        'timezone' => 'Asia/Tehran',
        'jalali' => rado_jalali_datetime($dt),
        'jalali_long' => rado_jalali_long($dt),
    ];
}

function rado_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rado_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        rado_json(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    return $data;
}

function rado_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $file = rado_root() . '/rado-system/private/database.php';
    if (!is_file($file)) {
        throw new RuntimeException('database_not_configured');
    }
    $cfg = require $file;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) ($cfg['host'] ?? 'localhost'),
        (int) ($cfg['port'] ?? 3306),
        (string) ($cfg['database'] ?? ''),
        (string) ($cfg['charset'] ?? 'utf8mb4')
    );
    $pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    try {
        $pdo->exec("SET time_zone = '+03:30'");
    } catch (Throwable) {
    }
    rado_ensure_runtime_schema($pdo);
    return $pdo;
}

function rado_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function rado_ensure_runtime_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (!rado_column_exists($pdo, 'trips', 'arrived_at')) {
        $pdo->exec('ALTER TABLE trips ADD COLUMN arrived_at DATETIME NULL AFTER accepted_at');
    }
    $pdo->exec("INSERT INTO system_settings(setting_key,setting_value,is_secret) VALUES('default_driver_commission_rate','10.00',0) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $pdo->exec("INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.3.0-trip-lifecycle') ON DUPLICATE KEY UPDATE version=VALUES(version)");
}

function rado_uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function rado_active_pricing_rule(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT * FROM pricing_rules WHERE active=1 AND (effective_from IS NULL OR effective_from<=NOW()) AND (effective_to IS NULL OR effective_to>NOW()) ORDER BY COALESCE(effective_from,created_at) DESC,id DESC LIMIT 1");
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function rado_fare_breakdown(array $rule, int $distanceMeters, int $durationSeconds): array
{
    $distanceMeters = max(0, $distanceMeters);
    $durationSeconds = max(0, $durationSeconds);
    $base = (int) ($rule['base_fare'] ?? 0);
    $perKm = (int) ($rule['per_km'] ?? 0);
    $perMinute = (int) ($rule['per_minute'] ?? 0);
    $minimum = (int) ($rule['minimum_fare'] ?? 0);
    $surge = max(1.0, (float) ($rule['surge_multiplier'] ?? 1.0));

    $distanceAmount = (int) round(($distanceMeters / 1000) * $perKm);
    $timeAmount = (int) round(($durationSeconds / 60) * $perMinute);
    $subtotal = $base + $distanceAmount + $timeAmount;
    $afterSurge = (int) round($subtotal * $surge);
    $fare = max($minimum, $afterSurge);

    return [
        'fare' => $fare,
        'currency' => 'IRR',
        'distance_meters' => $distanceMeters,
        'duration_seconds' => $durationSeconds,
        'base_fare' => $base,
        'distance_amount' => $distanceAmount,
        'time_amount' => $timeAmount,
        'minimum_fare' => $minimum,
        'surge_multiplier' => $surge,
        'subtotal' => $subtotal,
    ];
}

function rado_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function rado_set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO system_settings(setting_key,setting_value,is_secret) VALUES(?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0');
    $stmt->execute([$key, $value]);
}

function rado_device_phone(string $prefix, string $clientId): string
{
    $clientId = trim($clientId);
    if ($clientId === '' || strlen($clientId) > 160) {
        throw new InvalidArgumentException('invalid_client_id');
    }
    return $prefix . substr(hash('sha256', $clientId), 0, 18);
}

function rado_guest_passenger(PDO $pdo, string $clientId): string
{
    $phone = rado_device_phone('g', $clientId);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone=? AND role='passenger' LIMIT 1");
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();
    if ($id !== false) return (string)$id;

    $id = rado_uuid4();
    $stmt = $pdo->prepare("INSERT INTO users(id,phone,role,full_name,is_active) VALUES(?,?,'passenger','مسافر رادو',1)");
    $stmt->execute([$id, $phone]);
    return $id;
}

function rado_passenger_from_client(PDO $pdo, string $clientId): ?string
{
    $phone = rado_device_phone('g', $clientId);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone=? AND role='passenger' LIMIT 1");
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (string)$id;
}

function rado_guest_driver(PDO $pdo, string $clientId): array
{
    $phone = rado_device_phone('d', $clientId);
    $stmt = $pdo->prepare("SELECT u.id,u.phone,u.full_name,u.is_active,d.status,d.commission_rate,d.plate_number,d.vehicle_make,d.vehicle_model,d.vehicle_color FROM users u LEFT JOIN drivers d ON d.user_id=u.id WHERE u.phone=? AND u.role='driver' LIMIT 1");
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    if (is_array($row) && !empty($row['id'])) {
        if ($row['status'] === null) {
            $rate = (float)(rado_setting($pdo, 'default_driver_commission_rate', '10.00') ?? '10.00');
            $ins = $pdo->prepare("INSERT IGNORE INTO drivers(user_id,status,commission_rate) VALUES(?,'pending',?)");
            $ins->execute([(string)$row['id'], $rate]);
            return rado_guest_driver($pdo, $clientId);
        }
        return $row;
    }

    $id = rado_uuid4();
    $rate = (float)(rado_setting($pdo, 'default_driver_commission_rate', '10.00') ?? '10.00');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO users(id,phone,role,full_name,is_active) VALUES(?,?,'driver','راننده جدید رادو',1)");
        $stmt->execute([$id, $phone]);
        $stmt = $pdo->prepare("INSERT INTO drivers(user_id,status,commission_rate) VALUES(?,'pending',?)");
        $stmt->execute([$id, $rate]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return rado_guest_driver($pdo, $clientId);
}

function rado_driver_from_client(PDO $pdo, string $clientId): ?array
{
    $phone = rado_device_phone('d', $clientId);
    $stmt = $pdo->prepare("SELECT u.id,u.phone,u.full_name,u.is_active,d.status,d.commission_rate,d.plate_number,d.vehicle_make,d.vehicle_model,d.vehicle_color FROM users u JOIN drivers d ON d.user_id=u.id WHERE u.phone=? AND u.role='driver' LIMIT 1");
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function rado_require_approved_driver(PDO $pdo, string $clientId): array
{
    $driver = rado_driver_from_client($pdo, $clientId);
    if ($driver === null) {
        $driver = rado_guest_driver($pdo, $clientId);
    }
    if ((int)($driver['is_active'] ?? 0) !== 1 || (string)($driver['status'] ?? '') !== 'approved') {
        rado_json(403, [
            'ok'=>false,
            'error'=>'driver_not_approved',
            'message'=>'حساب راننده هنوز توسط مدیریت رادو تأیید نشده است.',
            'driver_status'=>(string)($driver['status'] ?? 'pending'),
        ]);
    }
    return $driver;
}

function rado_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 6371000.0;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function rado_dispatch_trip(PDO $pdo, string $tripId, float $pickupLat, float $pickupLng): int
{
    $stmt = $pdo->query("SELECT d.user_id,p.latitude,p.longitude FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) LIMIT 80");
    $candidates = [];
    foreach ($stmt->fetchAll() as $row) {
        $distance = rado_distance_m($pickupLat, $pickupLng, (float)$row['latitude'], (float)$row['longitude']);
        if ($distance <= 5000) {
            $row['distance_m'] = $distance;
            $candidates[] = $row;
        }
    }
    usort($candidates, fn(array $a, array $b) => $a['distance_m'] <=> $b['distance_m']);
    $candidates = array_slice($candidates, 0, 4);
    $insert = $pdo->prepare('INSERT IGNORE INTO trip_offers(trip_id,driver_id,offered_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL 25 SECOND))');
    $count = 0;
    foreach ($candidates as $candidate) {
        $insert->execute([$tripId, (string)$candidate['user_id']]);
        if ($insert->rowCount() > 0) $count++;
    }
    return $count;
}

function rado_offer_waiting_trip_to_driver(PDO $pdo, string $driverId, float $lat, float $lng): void
{
    $stmt = $pdo->query("SELECT id,pickup_lat,pickup_lng FROM trips WHERE status='searching' AND requested_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE) ORDER BY requested_at ASC LIMIT 40");
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $distance = rado_distance_m($lat, $lng, (float)$row['pickup_lat'], (float)$row['pickup_lng']);
        if ($distance <= 5000) {
            $row['distance_m'] = $distance;
            $items[] = $row;
        }
    }
    usort($items, fn(array $a, array $b) => $a['distance_m'] <=> $b['distance_m']);
    if ($items === []) return;
    $tripId = (string)$items[0]['id'];
    $stmt = $pdo->prepare('INSERT IGNORE INTO trip_offers(trip_id,driver_id,offered_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL 25 SECOND))');
    $stmt->execute([$tripId, $driverId]);
}

function rado_wallet_balance(PDO $pdo, string $userId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id=?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function rado_trip_status_fa(string $status): string
{
    return match ($status) {
        'requested', 'searching' => 'در جستجوی راننده',
        'driver_assigned', 'driver_arriving' => 'راننده در مسیر مبدا',
        'arrived' => 'راننده به مبدا رسید',
        'in_progress' => 'سفر در حال انجام',
        'completed' => 'سفر پایان یافت',
        'cancelled_by_passenger' => 'لغو توسط مسافر',
        'cancelled_by_driver' => 'لغو توسط راننده',
        'cancelled_by_admin' => 'لغو توسط مدیریت',
        'expired' => 'درخواست منقضی شد',
        default => $status,
    };
}

function rado_trip_row(PDO $pdo, string $tripId): ?array
{
    $stmt = $pdo->prepare("SELECT t.*,pu.full_name passenger_name,pu.phone passenger_phone,du.full_name driver_name,d.plate_number,d.vehicle_make,d.vehicle_model,d.vehicle_color,d.commission_rate FROM trips t LEFT JOIN users pu ON pu.id=t.passenger_id LEFT JOIN users du ON du.id=t.driver_id LEFT JOIN drivers d ON d.user_id=t.driver_id WHERE t.id=? LIMIT 1");
    $stmt->execute([$tripId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function rado_trip_payload(array $row): array
{
    $time = static function ($value): ?array {
        if ($value === null || trim((string)$value) === '') return null;
        return rado_time_payload((string)$value);
    };
    return [
        'id'=>(string)$row['id'],
        'status'=>(string)$row['status'],
        'status_fa'=>rado_trip_status_fa((string)$row['status']),
        'pickup'=>[
            'lat'=>(float)$row['pickup_lat'],
            'lng'=>(float)$row['pickup_lng'],
            'label'=>(string)($row['pickup_label'] ?? ''),
        ],
        'destination'=>[
            'lat'=>(float)$row['destination_lat'],
            'lng'=>(float)$row['destination_lng'],
            'label'=>(string)($row['destination_label'] ?? ''),
        ],
        'estimated_distance_m'=>(int)($row['estimated_distance_m'] ?? 0),
        'estimated_duration_s'=>(int)($row['estimated_duration_s'] ?? 0),
        'estimated_fare'=>(int)($row['estimated_fare'] ?? 0),
        'final_fare'=>$row['final_fare'] === null ? null : (int)$row['final_fare'],
        'driver'=>$row['driver_id'] === null ? null : [
            'id'=>(string)$row['driver_id'],
            'name'=>(string)($row['driver_name'] ?? 'راننده رادو'),
            'plate'=>(string)($row['plate_number'] ?? ''),
            'vehicle'=>trim((string)($row['vehicle_make'] ?? '') . ' ' . (string)($row['vehicle_model'] ?? '')),
            'color'=>(string)($row['vehicle_color'] ?? ''),
        ],
        'times'=>[
            'requested'=>$time($row['requested_at'] ?? null),
            'accepted'=>$time($row['accepted_at'] ?? null),
            'arrived'=>$time($row['arrived_at'] ?? null),
            'started'=>$time($row['started_at'] ?? null),
            'completed'=>$time($row['completed_at'] ?? null),
            'cancelled'=>$time($row['cancelled_at'] ?? null),
        ],
        'timezone'=>'Asia/Tehran',
    ];
}

function rado_complete_trip_finance(PDO $pdo, array $trip): array
{
    $tripId = (string)$trip['id'];
    $driverId = (string)($trip['driver_id'] ?? '');
    if ($driverId === '') throw new RuntimeException('trip_has_no_driver');

    $fare = (int)($trip['final_fare'] ?? $trip['estimated_fare'] ?? 0);
    $rate = (float)($trip['commission_rate'] ?? 0);
    if ($rate < 0 || $rate > 100) $rate = 0;
    $commission = (int)round($fare * $rate / 100);
    $net = $fare - $commission;
    $balance = rado_wallet_balance($pdo, $driverId);

    $insert = $pdo->prepare('INSERT IGNORE INTO ledger_entries(user_id,trip_id,entry_type,amount,balance_after,idempotency_key,metadata_json) VALUES(?,?,?,?,?,?,?)');
    $meta = json_encode(['commission_rate'=>$rate,'fare'=>$fare], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $insert->execute([$driverId,$tripId,'trip_gross',$fare,$balance+$fare,"trip:$tripId:gross",$meta]);
    if ($insert->rowCount() > 0) $balance += $fare;

    $insert->execute([$driverId,$tripId,'platform_commission',-$commission,$balance-$commission,"trip:$tripId:commission",$meta]);
    if ($insert->rowCount() > 0) $balance -= $commission;

    $insert->execute([null,$tripId,'platform_commission_income',$commission,null,"trip:$tripId:platform_income",$meta]);

    return [
        'fare'=>$fare,
        'commission_rate'=>$rate,
        'commission'=>$commission,
        'driver_net'=>$net,
        'wallet_balance'=>rado_wallet_balance($pdo, $driverId),
    ];
}
