<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/rado-system/lib/migrate.php';

try {
    $result = rado_run_database_migrations($root);
    echo 'RADO database schema and migrations are ready (' . count($result['files'] ?? []) . " files).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'RADO database migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
