<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$root = dirname(__DIR__, 2);
$lock = $root . '/rado-system/private/installed.lock';
$dbFile = $root . '/rado-system/private/database.php';
$result = [
    'ok' => false,
    'service' => 'RADO',
    'domain' => 'rado-taxi.sbs',
    'installed' => is_file($lock),
    'php' => PHP_VERSION,
    'time' => gmdate('c'),
    'database' => 'not_configured',
    'updater' => [
        'last_success_at' => is_file($root.'/rado-system/state/last_success_at') ? trim((string)file_get_contents($root.'/rado-system/state/last_success_at')) : null,
        'current_tag' => is_file($root.'/rado-system/state/current_tag') ? trim((string)file_get_contents($root.'/rado-system/state/current_tag')) : null,
    ],
];
if (is_file($dbFile)) {
    try {
        $db = require $dbFile;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int)$db['port'], $db['database']);
        $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
        $pdo->query('SELECT 1');
        $result['database'] = 'ok';
    } catch (Throwable $e) {
        $result['database'] = 'error';
    }
}
$result['ok'] = $result['installed'] && $result['database'] === 'ok';
http_response_code($result['ok'] ? 200 : 503);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
