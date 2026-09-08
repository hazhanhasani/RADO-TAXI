<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/rado-system/lib/tick.php';

try {
    $result = rado_run_platform_tick();
    echo 'RADO tick OK ' . rado_jalali_datetime(null, true) . ' | ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'RADO tick failed: ' . $e->getMessage() . "\n");
    exit(1);
}
