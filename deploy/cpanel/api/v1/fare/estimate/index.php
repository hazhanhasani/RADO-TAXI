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
        rado_json(422, [
            'ok' => false,
            'error' => 'invalid_route_metrics',
            'message' => 'اطلاعات مسافت یا زمان مسیر معتبر نیست.',
        ]);
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
        'calculated_at' => rado_jalali_datetime(null, true),
        'timezone' => 'Asia/Tehran',
        'pricing_rule' => [
            'id' => (int) $rule['id'],
            'title' => (string) $rule['title'],
            'effective_from' => !empty($rule['effective_from']) ? rado_jalali_datetime((string)$rule['effective_from']) : null,
        ],
        'breakdown' => $breakdown,
    ]);
} catch (Throwable $e) {
    $stateDir = rado_root() . '/rado-system/state';
    @mkdir($stateDir, 0755, true);
    $requestId = substr(bin2hex(random_bytes(8)), 0, 12);
    @file_put_contents(
        $stateDir . '/fare-errors.log',
        '[' . rado_jalali_datetime(null, true) . '] ' . $requestId . ' ' . get_class($e) . ': ' . $e->getMessage() . "\n",
        FILE_APPEND | LOCK_EX
    );

    $message = 'محاسبه کرایه روی سرور RADO انجام نشد. دیتابیس یا ساختار تعرفه نیاز به بررسی دارد.';
    $error = 'fare_estimate_failed';
    $status = 500;

    if ($e instanceof PDOException) {
        $error = 'database_unavailable';
        $status = 503;
        $message = 'دیتابیس RADO در دسترس نیست یا ساختار آن کامل نشده است. بروزرسانی خودکار سرور باید آن را اصلاح کند.';
    } elseif ($e instanceof RuntimeException && $e->getMessage() === 'database_not_configured') {
        $error = 'database_not_configured';
        $status = 503;
        $message = 'اتصال دیتابیس RADO روی هاست هنوز کامل نشده است.';
    }

    rado_json($status, [
        'ok' => false,
        'error' => $error,
        'message' => $message,
        'request_id' => $requestId,
    ]);
}
