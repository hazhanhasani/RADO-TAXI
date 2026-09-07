<?php
declare(strict_types=1);

function rado_root(): string
{
    return dirname(__DIR__, 2);
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
    return $pdo;
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

function rado_guest_passenger(PDO $pdo, string $clientId): string
{
    $clientId = trim($clientId);
    if ($clientId === '' || strlen($clientId) > 160) {
        throw new InvalidArgumentException('invalid_client_id');
    }
    $phone = 'g' . substr(hash('sha256', $clientId), 0, 18);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone=? AND role='passenger' LIMIT 1");
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (string) $id;
    }

    $id = rado_uuid4();
    $stmt = $pdo->prepare("INSERT INTO users(id,phone,role,full_name,is_active) VALUES(?,?,'passenger','مسافر رادو',1)");
    $stmt->execute([$id, $phone]);
    return $id;
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
    $stmt = $pdo->query("SELECT d.user_id,p.latitude,p.longitude,p.last_seen_at FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) LIMIT 80");
    $candidates = [];
    foreach ($stmt->fetchAll() as $row) {
        $distance = rado_distance_m($pickupLat, $pickupLng, (float) $row['latitude'], (float) $row['longitude']);
        if ($distance <= 5000) {
            $row['distance_m'] = $distance;
            $candidates[] = $row;
        }
    }
    usort($candidates, fn(array $a, array $b) => $a['distance_m'] <=> $b['distance_m']);
    $candidates = array_slice($candidates, 0, 4);
    $insert = $pdo->prepare('INSERT IGNORE INTO trip_offers(trip_id,driver_id,offered_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL 15 SECOND))');
    $count = 0;
    foreach ($candidates as $candidate) {
        $insert->execute([$tripId, (string) $candidate['user_id']]);
        if ($insert->rowCount() > 0) {
            $count++;
        }
    }
    return $count;
}
