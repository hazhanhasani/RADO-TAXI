<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__, 2);
$dbFile = $root . '/rado-system/private/database.php';
$schemaFile = $root . '/sql/schema.sql';
$migrationsDir = $root . '/sql/migrations';

if (!is_file($dbFile)) {
    fwrite(STDERR, "RADO database configuration is missing.\n");
    exit(2);
}
if (!is_file($schemaFile)) {
    fwrite(STDERR, "RADO schema.sql is missing.\n");
    exit(3);
}

function radoRunSqlFile(PDO $pdo, string $path): void {
    $sql = (string)file_get_contents($path);
    $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $pdo->exec($statement);
    }
}

try {
    $cfg = require $dbFile;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string)($cfg['host'] ?? 'localhost'),
        (int)($cfg['port'] ?? 3306),
        (string)($cfg['database'] ?? ''),
        (string)($cfg['charset'] ?? 'utf8mb4')
    );
    $pdo = new PDO($dsn, (string)($cfg['username'] ?? ''), (string)($cfg['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    try { $pdo->exec("SET time_zone = '+03:30'"); } catch (Throwable) {}

    radoRunSqlFile($pdo, $schemaFile);

    if (is_dir($migrationsDir)) {
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) radoRunSqlFile($pdo, $file);
    }

    // CREATE TABLE IF NOT EXISTS does not add new columns to existing tables.
    $column = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $column->execute(['trips', 'arrived_at']);
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE trips ADD COLUMN arrived_at DATETIME NULL AFTER accepted_at');
    }

    $pdo->exec("INSERT INTO system_settings(setting_key,setting_value,is_secret) VALUES('default_driver_commission_rate','10.00',0) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $pdo->exec("INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.4.0-migration-runner') ON DUPLICATE KEY UPDATE version=VALUES(version)");

    echo "RADO database schema and migrations are ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'RADO database migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
