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

        $download = static function (string $url, string $dest, int $timeout = 120) use ($ua): void {
            $fp = fopen($dest, 'wb');
            if (!$fp) throw new RuntimeException('Bootstrap cannot create temporary file');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
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
        $tag = trim((string)($release['tag_name'] ?? ''));
        if ($tag === '') throw new RuntimeException('Bootstrap latest release has no tag');
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $findAsset = static function (array $items, string $name): ?array {
            foreach ($items as $item) if (($item['name'] ?? '') === $name) return $item;
            return null;
        };

        $metaAsset = $findAsset($assets, 'RADO-release.json');
        if (!$metaAsset) throw new RuntimeException('Bootstrap release manifest missing');
        $tmpMeta = tempnam(sys_get_temp_dir(), 'rado-bootstrap-meta-');
        $download((string)$metaAsset['browser_download_url'], $tmpMeta, 60);
        $meta = json_decode((string)file_get_contents($tmpMeta), true, 512, JSON_THROW_ON_ERROR);
        @unlink($tmpMeta);

        $cpName = basename((string)($meta['cpanel']['asset'] ?? ''));
        $cpSha = trim((string)($meta['cpanel']['sha256'] ?? ''));
        $cpAsset = $cpName !== '' ? $findAsset($assets, $cpName) : null;
        if (!$cpAsset || $cpSha === '') throw new RuntimeException('Bootstrap cPanel package metadata missing');

        $installedBootstrapTag = is_file($stateDir . '/bootstrap_version') ? trim((string)file_get_contents($stateDir . '/bootstrap_version')) : '';
        if (!is_file($coreFile) || $installedBootstrapTag !== $tag) {
            $tmpZip = tempnam(sys_get_temp_dir(), 'rado-bootstrap-cp-');
            $download((string)$cpAsset['browser_download_url'], $tmpZip, 120);
            $actualSha = (string)hash_file('sha256', $tmpZip);
            if (!hash_equals($cpSha, $actualSha)) {
                @unlink($tmpZip);
                throw new RuntimeException('Bootstrap cPanel SHA256 mismatch');
            }

            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                @unlink($tmpZip);
                throw new RuntimeException('Bootstrap cannot open cPanel ZIP');
            }
            $corePayload = $zip->getFromName('rado-system/bin/rado-update-core.php');
            $zip->close();
            @unlink($tmpZip);
            if (!is_string($corePayload) || !str_starts_with(ltrim($corePayload), '<?php')) {
                throw new RuntimeException('Bootstrap updater core missing from cPanel package');
            }

            $newCore = $coreFile . '.new';
            if (file_put_contents($newCore, $corePayload, LOCK_EX) === false) {
                throw new RuntimeException('Bootstrap cannot stage updater core');
            }
            if (!rename($newCore, $coreFile)) {
                @unlink($newCore);
                throw new RuntimeException('Bootstrap cannot activate updater core');
            }
            file_put_contents($stateDir . '/bootstrap_version', $tag . "\n", LOCK_EX);
            @unlink($stateDir . '/bootstrap_error.log');
        }
    } catch (Throwable $e) {
        @file_put_contents($stateDir . '/bootstrap_error.log', '[' . date('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        // A transient bootstrap failure must not block the last known-good update engine.
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
