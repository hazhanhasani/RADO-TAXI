<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rado_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $body = rado_body();
    $distance = filter_var($body['distance_meters'] ?? null, FILTER_VALIDATE_INT);
    $duration = filter_var($body['duration_seconds'] ?? null, FILTER_VALIDATE_INT);
    if ($distance === false || $duration === false || $distance < 0 || $duration < 0) {
        rado_json(422, ['ok' => false, 'error' => 'invalid_route_metrics']);
    }

    $pdo = rado_db();
    $rule = rado_active_pricing_rule($pdo);
    if ($rule === null) {
        rado_json(409, [
            'ok' => false,
            'error' => 'pricing_not_configured',
            'message' => 'تعرفه سفر هنوز در پنل مدیریت تنظیم نشده است.',
        ]);
    }

    $breakdown = rado_fare_breakdown($rule, (int) $distance, (int) $duration);
    rado_json(200, [
        'ok' => true,
        'fare' => $breakdown['fare'],
        'currency' => 'IRR',
        'pricing_rule' => [
            'id' => (int) $rule['id'],
            'title' => (string) $rule['title'],
        ],
        'breakdown' => $breakdown,
    ]);
} catch (Throwable $e) {
    rado_json(500, ['ok' => false, 'error' => 'fare_estimate_failed']);
}
