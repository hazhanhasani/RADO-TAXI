<?php
declare(strict_types=1);

// RADO one-time cPanel recovery updater.
// Upload this file to the RADO document root, open it in a browser,
// sign in with the existing RADO admin account, then run the update.
// On success the script deletes itself.

@set_time_limit(0);
@ini_set('memory_limit', '256M');

$root = __DIR__;
$appFile = $root . '/rado-system/lib/app.php';
$configFile = $root . '/rado-system/config.php';

if (!is_file($appFile) || !is_file($configFile)) {
    http_response_code(500);
    exit('RADO installation was not found in this directory.');
}

require $appFile;
$config = require $configFile;

session_name('rado_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

function rb_e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function rb_asset(array $assets, string $name): ?array { foreach ($assets as $a) if (($a['name'] ?? '') === $name) return $a; return null; }
function rb_http_get(string $url, array $config): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: ' . ($config['user_agent'] ?? 'RADO-cPanel-Bootstrap')],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException("HTTP $code: $err");
    return (string)$body;
}
function rb_download(string $url, string $dest, array $config): void {
    $fp = fopen($dest, 'wb');
    if (!$fp) throw new RuntimeException('Cannot create temporary download file.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . ($config['user_agent'] ?? 'RADO-cPanel-Bootstrap')],
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code < 200 || $code >= 300) {
        @unlink($dest);
        throw new RuntimeException("Download failed ($code): $err");
    }
}
function rb_preserve(string $rel, array $config): bool {
    foreach (($config['preserve'] ?? ['.env','storage','rado-system/state','rado-system/private']) as $path) {
        $path = trim((string)$path, '/');
        if ($rel === $path || str_starts_with($rel, $path . '/')) return true;
    }
    return false;
}
function rb_remove_tree(string $path): void {
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($path);
}
function rb_csrf(): string {
    if (empty($_SESSION['rado_bootstrap_csrf'])) $_SESSION['rado_bootstrap_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['rado_bootstrap_csrf'];
}

$error = '';
$success = '';

try {
    $pdo = rado_db();
} catch (Throwable $e) {
    http_response_code(500);
    exit('RADO database is not available.');
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id,password_hash FROM admin_users WHERE phone=? AND is_active=1 LIMIT 1');
    $stmt->execute([$phone]);
    $admin = $stmt->fetch();
    if (is_array($admin) && password_verify($password, (string)$admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['rado_bootstrap_admin'] = (string)$admin['id'];
        $_SESSION['rado_bootstrap_csrf'] = bin2hex(random_bytes(24));
        header('Location: ' . basename(__FILE__));
        exit;
    }
    $error = 'شماره موبایل یا رمز مدیریت صحیح نیست.';
}

if (!empty($_SESSION['rado_bootstrap_admin']) && isset($_POST['action']) && $_POST['action'] === 'update') {
    if (!hash_equals((string)($_SESSION['rado_bootstrap_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Invalid request.');
    }

    $tmpZip = null;
    $tmpDir = null;
    try {
        $repo = (string)($config['repo'] ?? 'hazhanhasani/RADO-TAXI');
        $api = rtrim((string)($config['github_api'] ?? 'https://api.github.com'), '/');
        $release = json_decode(rb_http_get($api . '/repos/' . $repo . '/releases/latest', $config), true, 512, JSON_THROW_ON_ERROR);
        $tag = trim((string)($release['tag_name'] ?? ''));
        if ($tag === '') throw new RuntimeException('Latest release has no tag.');
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];

        $metaAsset = rb_asset($assets, 'RADO-release.json');
        if (!$metaAsset) throw new RuntimeException('Release manifest is missing.');
        $tmpMeta = tempnam(sys_get_temp_dir(), 'rado-bootstrap-meta-');
        rb_download((string)$metaAsset['browser_download_url'], $tmpMeta, $config);
        $meta = json_decode((string)file_get_contents($tmpMeta), true, 512, JSON_THROW_ON_ERROR);
        @unlink($tmpMeta);

        $cpName = basename((string)($meta['cpanel']['asset'] ?? ''));
        if ($cpName === '') throw new RuntimeException('cPanel package is missing from manifest.');
        $cpAsset = rb_asset($assets, $cpName);
        if (!$cpAsset) throw new RuntimeException('cPanel release asset is missing.');

        $tmpZip = tempnam(sys_get_temp_dir(), 'rado-bootstrap-cp-');
        rb_download((string)$cpAsset['browser_download_url'], $tmpZip, $config);
        $expected = trim((string)($meta['cpanel']['sha256'] ?? ''));
        if ($expected !== '' && !hash_equals($expected, (string)hash_file('sha256', $tmpZip))) {
            throw new RuntimeException('cPanel SHA256 validation failed.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) throw new RuntimeException('Cannot open cPanel package.');
        $tmpDir = sys_get_temp_dir() . '/rado-bootstrap-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) throw new RuntimeException('Cannot create extraction directory.');
        if (!$zip->extractTo($tmpDir)) throw new RuntimeException('Cannot extract cPanel package.');
        $zip->close();
        @unlink($tmpZip); $tmpZip = null;

        foreach (['index.php','rado-system/lib/app.php','rado-system/lib/migrate.php','rado-system/bin/rado-update.php'] as $required) {
            if (!is_file($tmpDir . '/' . $required)) throw new RuntimeException('Invalid cPanel package: ' . $required);
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($tmpDir) + 1);
            if (rb_preserve($rel, $config)) continue;
            $dest = $root . '/' . $rel;
            if ($item->isDir()) {
                @mkdir($dest, 0755, true);
            } else {
                @mkdir(dirname($dest), 0755, true);
                if (!copy($item->getPathname(), $dest)) throw new RuntimeException('Cannot install: ' . $rel);
            }
        }
        rb_remove_tree($tmpDir); $tmpDir = null;

        require_once $root . '/rado-system/lib/migrate.php';
        rado_run_database_migrations($root);

        $stateDir = $root . '/rado-system/state';
        @mkdir($stateDir, 0755, true);
        file_put_contents($stateDir . '/release.json.tmp', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        rename($stateDir . '/release.json.tmp', $stateDir . '/release.json');
        file_put_contents($stateDir . '/current_tag', $tag . "\n", LOCK_EX);
        file_put_contents($stateDir . '/target_tag', $tag . "\n", LOCK_EX);
        file_put_contents($stateDir . '/update_status', "ok\n", LOCK_EX);
        file_put_contents($stateDir . '/last_success_at', rado_jalali_datetime(null, true) . "\n", LOCK_EX);
        if (function_exists('rado_now_iso_tehran')) file_put_contents($stateDir . '/last_success_iso', rado_now_iso_tehran() . "\n", LOCK_EX);
        @unlink($stateDir . '/last_error_current.log');

        $success = 'RADO با موفقیت به ' . $tag . ' ارتقا یافت. فایل بازیابی نیز حذف شد.';
        $_SESSION['rado_bootstrap_done'] = $success;
        @unlink(__FILE__);
    } catch (Throwable $e) {
        if ($tmpZip) @unlink($tmpZip);
        if ($tmpDir) rb_remove_tree($tmpDir);
        $error = $e->getMessage();
    }
}

if (!empty($_SESSION['rado_bootstrap_done'])) {
    $success = (string)$_SESSION['rado_bootstrap_done'];
    unset($_SESSION['rado_bootstrap_done']);
}

?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RADO Recovery Update</title><style>body{margin:0;background:#111;font-family:Tahoma,Arial;color:#171717}.wrap{min-height:100vh;display:grid;place-items:center;padding:20px}.box{width:min(520px,100%);background:#fff;border-radius:28px;padding:28px;box-sizing:border-box}.logo{font-size:42px;font-weight:900}.gold{color:#f5b400}.msg{padding:12px;border-radius:12px;margin:14px 0}.err{background:#fff0f0;color:#9e1b1b}.ok{background:#eaf8ee;color:#17652d}input{width:100%;box-sizing:border-box;padding:13px;margin:6px 0 14px;border:1px solid #ddd;border-radius:12px}button{width:100%;border:0;border-radius:14px;padding:14px;background:#171717;color:#fff;font-size:15px;font-weight:900}.note{font-size:12px;color:#666;line-height:1.8}</style></head><body><div class="wrap"><div class="box"><div class="logo">R<span class="gold">A</span>DO</div><h2>بازیابی بروزرسانی cPanel</h2><p class="note">این ابزار فقط بسته cPanel آخرین Release رسمی را دریافت و با حفظ دیتابیس، state و کلیدهای خصوصی نصب می‌کند.</p><?php if($error):?><div class="msg err"><?=rb_e($error)?></div><?php endif;?><?php if($success):?><div class="msg ok"><?=rb_e($success)?></div><?php endif;?><?php if(empty($_SESSION['rado_bootstrap_admin'])):?><form method="post"><input type="hidden" name="action" value="login"><label>شماره موبایل مدیر</label><input name="phone" inputmode="tel" required><label>رمز عبور مدیر</label><input name="password" type="password" required><button>ورود امن</button></form><?php elseif(!$success):?><form method="post"><input type="hidden" name="action" value="update"><input type="hidden" name="csrf" value="<?=rb_e(rb_csrf())?>"><button>نصب آخرین نسخه رسمی RADO</button></form><?php endif;?></div></div></body></html>
