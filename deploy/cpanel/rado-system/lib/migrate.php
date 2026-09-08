<?php
declare(strict_types=1);

function rado_migration_is_ignorable(Throwable $e): bool
{
    if (!$e instanceof PDOException) return false;
    $info = $e->errorInfo ?? [];
    $driver = (int)($info[1] ?? 0);
    return in_array($driver, [1050, 1060, 1061, 1826], true);
}

function rado_migration_exec_file(PDO $pdo, string $path): int
{
    $sql = (string)file_get_contents($path);
    $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
    $count = 0;
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        try {
            $pdo->exec($statement);
            $count++;
        } catch (Throwable $e) {
            if (rado_migration_is_ignorable($e)) continue;
            $preview = preg_replace('/\s+/', ' ', substr($statement, 0, 180));
            throw new RuntimeException(
                'Migration failed in ' . basename($path) . ' statement #' . ($index + 1) . ': ' . $preview . ' :: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    return $count;
}

function rado_run_database_migrations(string $root): array
{
    $dbFile = $root . '/rado-system/private/database.php';
    $schemaFile = $root . '/sql/schema.sql';
    $migrationsDir = $root . '/sql/migrations';

    if (!is_file($dbFile)) throw new RuntimeException('Database configuration is missing.');
    if (!is_file($schemaFile)) throw new RuntimeException('schema.sql is missing.');

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

    $applied = [];
    $applied['schema.sql'] = rado_migration_exec_file($pdo, $schemaFile);

    if (is_dir($migrationsDir)) {
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $applied[basename($file)] = rado_migration_exec_file($pdo, $file);
        }
    }

    $column = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $column->execute(['trips', 'arrived_at']);
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE trips ADD COLUMN arrived_at DATETIME NULL AFTER accepted_at');
    }

    $pdo->exec("INSERT INTO system_settings(setting_key,setting_value,is_secret) VALUES('default_driver_commission_rate','10.00',0) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $pdo->exec("INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.4.1-shared-hosting-safe') ON DUPLICATE KEY UPDATE version=VALUES(version)");

    // Never report a successful KYC migration unless the required tables are really present.
    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['driver_verification_profiles','driver_verification_checks','driver_verification_corrections'] as $requiredTable) {
        $tableCheck->execute([$requiredTable]);
        if ((int)$tableCheck->fetchColumn() === 0) {
            throw new RuntimeException('Migration verification failed; missing table: ' . $requiredTable);
        }
    }

    return ['ok' => true, 'files' => $applied];
}
