<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__, 2);
$config = require $root . '/rado-system/config.php';
$stateDir = $root . '/rado-system/state';
$coreFile = __DIR__ . '/rado-update-core.php';
@mkdir($stateDir, 0755, true);

$bootstrapLock = fopen($stateDir . '/bootstrap-update.lock', 'c+');
if ($bootstrapLock && flock($bootstrapLock, LOCK_EX | LOCK_NB)) {
    try {
        $repo = (string)$config['repo'];
        $api = rtrim((string)$config['github_api'], '/');
        $ua = (string)($config['user_agent'] ?? 'RADO-Updater-Bootstrap');

        $get = static function (string $url) use ($ua): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: ' . $ua],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                throw new RuntimeException("Bootstrap HTTP $code: $err");
            }
            return (string)$body;
        };

        $download = static function (string $url, string $dest) use ($ua): void {
            $fp = fopen($dest, 'wb');
            if (!$fp) throw new RuntimeException('Bootstrap cannot create temporary file');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_HTTPHEADER => ['User-Agent: ' . $ua],
            ]);
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if (!$ok || $code < 200 || $code >= 300) {
                @unlink($dest);
                throw new RuntimeException("Bootstrap download failed ($code): $err");
            }
        };

        $release = json_decode($get($api . '/repos/' . $repo . '/releases/latest'), true, 512, JSON_THROW_ON_ERROR);
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $metaAsset = null;
        $updaterAsset = null;
        foreach ($assets as $asset) {
            if (($asset['name'] ?? '') === 'RADO-release.json') $metaAsset = $asset;
            if (($asset['name'] ?? '') === 'RADO-updater.php') $updaterAsset = $asset;
        }

        if ($metaAsset && $updaterAsset) {
            $tmpMeta = tempnam(sys_get_temp_dir(), 'rado-bootstrap-meta-');
            $download((string)$metaAsset['browser_download_url'], $tmpMeta);
            $meta = json_decode((string)file_get_contents($tmpMeta), true, 512, JSON_THROW_ON_ERROR);
            @unlink($tmpMeta);

            $expected = trim((string)($meta['updater']['sha256'] ?? ''));
            if ($expected === '') throw new RuntimeException('Updater checksum missing from release manifest');

            $tmpCore = tempnam(sys_get_temp_dir(), 'rado-bootstrap-core-');
            $download((string)$updaterAsset['browser_download_url'], $tmpCore);
            $actual = (string)hash_file('sha256', $tmpCore);
            if (!hash_equals($expected, $actual)) {
                @unlink($tmpCore);
                throw new RuntimeException('Updater SHA256 mismatch');
            }
            $head = (string)file_get_contents($tmpCore, false, null, 0, 64);
            if (!str_starts_with(ltrim($head), '<?php')) {
                @unlink($tmpCore);
                throw new RuntimeException('Updater payload is not PHP');
            }

            $newCore = $coreFile . '.new';
            if (!copy($tmpCore, $newCore)) {
                @unlink($tmpCore);
                throw new RuntimeException('Cannot stage updater core');
            }
            @unlink($tmpCore);
            if (!rename($newCore, $coreFile)) {
                @unlink($newCore);
                throw new RuntimeException('Cannot activate updater core');
            }
            file_put_contents($stateDir . '/bootstrap_version', (string)($release['tag_name'] ?? '') . "\n", LOCK_EX);
            @unlink($stateDir . '/bootstrap_error.log');
        }
    } catch (Throwable $e) {
        @file_put_contents($stateDir . '/bootstrap_error.log', '[' . date('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        // A transient bootstrap failure must not prevent the last known-good core from running.
    } finally {
        flock($bootstrapLock, LOCK_UN);
        fclose($bootstrapLock);
    }
}

if (!is_file($coreFile)) {
    fwrite(STDERR, "RADO updater core is missing. Run the one-time recovery updater.\n");
    exit(1);
}

require $coreFile;
