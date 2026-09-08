<?php
declare(strict_types=1);

require __DIR__.'/_ui.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit("unauthorized\n");
}

// Release the PHP session lock immediately so the admin can keep using the panel
// while this streaming request is open.
session_write_close();

@set_time_limit(30);
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) { @ob_end_flush(); }

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

function rado_sse_send(string $event, array $payload, ?int $id = null): void
{
    if ($id !== null) echo 'id: '.$id."\n";
    echo 'event: '.$event."\n";
    echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
    @flush();
}

try {
    $pdo = rado_db();
    $lastId = max(
        0,
        (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0),
        (int)($_GET['last_event_id'] ?? 0)
    );

    // A brand-new TV/session only needs events that happen after it connected.
    if ($lastId === 0) {
        $lastId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM realtime_events")->fetchColumn();
    }

    rado_sse_send('ready', [
        'ok' => true,
        'last_event_id' => $lastId,
        'server_time' => rado_time_payload(),
    ]);

    $started = microtime(true);
    $heartbeatAt = 0.0;
    $stmt = $pdo->prepare(
        "SELECT id,channel,event_type,payload_json,created_at
         FROM realtime_events
         WHERE id>? AND expires_at>NOW()
           AND (channel LIKE 'trip:%' OR channel LIKE 'driver:%')
         ORDER BY id ASC
         LIMIT 150"
    );

    // Shared-hosting safe: keep each PHP request short. EventSource reconnects
    // automatically and resumes from Last-Event-ID without a visible refresh.
    while (!connection_aborted() && microtime(true) - $started < 24.0) {
        $stmt->execute([$lastId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) $payload = [];
            rado_sse_send('ops', [
                'id' => $id,
                'channel' => (string)$row['channel'],
                'event_type' => (string)$row['event_type'],
                'payload' => $payload,
                'created_at' => rado_time_payload((string)$row['created_at']),
            ], $id);
            $lastId = $id;
        }

        $now = microtime(true);
        if ($now - $heartbeatAt >= 8.0) {
            rado_sse_send('heartbeat', [
                'last_event_id' => $lastId,
                'server_time' => rado_time_payload(),
            ]);
            $heartbeatAt = $now;
        }

        usleep(500000);
    }

    rado_sse_send('reconnect', ['last_event_id' => $lastId]);
} catch (Throwable $e) {
    rado_sse_send('stream_error', [
        'error' => 'live_stream_failed',
        'request_id' => substr(bin2hex(random_bytes(8)), 0, 12),
    ]);
}
