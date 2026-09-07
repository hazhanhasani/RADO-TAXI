<?php
declare(strict_types=1);
$root = __DIR__;
$private = $root . '/rado-system/private';
$state = $root . '/rado-system/state';
@mkdir($private, 0700, true);
@mkdir($state, 0755, true);
$lock = $private . '/installed.lock';
$bootstrap = $private . '/bootstrap-signing.php';
$dbConfigFile = $private . '/database.php';
$ciConfigFile = $private . '/ci-secrets.php';
$message = '';
$error = '';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function uuid4(): string {
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function readSigningZip(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('افزونه ZipArchive روی هاست فعال نیست.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('فایل امضای RADO معتبر نیست.');
    $cred = $zip->getFromName('RADO-SIGNING-CREDENTIALS.txt');
    $key = $zip->getFromName('rado-release.keystore');
    $zip->close();
    if ($cred === false || $key === false) throw new RuntimeException('فایل امضای RADO ناقص است.');
    if (!preg_match('/ANDROID_KEYSTORE_PASSWORD=(.+)/', $cred, $m1)) throw new RuntimeException('Store password پیدا نشد.');
    if (!preg_match('/ANDROID_KEY_ALIAS=(.+)/', $cred, $m2)) throw new RuntimeException('Key alias پیدا نشد.');
    if (!preg_match('/ANDROID_KEY_PASSWORD=(.+)/', $cred, $m3)) throw new RuntimeException('Key password پیدا نشد.');
    return ['store_password'=>trim($m1[1]),'key_alias'=>trim($m2[1]),'key_password'=>trim($m3[1]),'keystore'=>$key];
}
function writePhpArray(string $path, array $data): void {
    $body = "<?php\nreturn " . var_export($data, true) . ";\n";
    if (file_put_contents($path, $body, LOCK_EX) === false) throw new RuntimeException('امکان ذخیره تنظیمات روی هاست وجود ندارد.');
    @chmod($path, 0600);
}
function importSchema(PDO $pdo, string $path): void {
    if (!is_file($path)) throw new RuntimeException('فایل schema.sql داخل بسته نصب پیدا نشد.');
    $sql = (string)file_get_contents($path);
    $parts = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
    foreach ($parts as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $pdo->exec($statement);
    }
}

$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'cURL' => extension_loaded('curl'),
    'OpenSSL' => extension_loaded('openssl'),
    'ZipArchive' => class_exists('ZipArchive'),
    'پوشه private قابل نوشتن' => is_writable($private),
    'پوشه state قابل نوشتن' => is_writable($state),
];
$systemOk = !in_array(false, $checks, true);

if (is_file($lock)) {
    $message = 'RADO قبلاً نصب شده است. نصب‌کننده برای امنیت قفل شده.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$systemOk) throw new RuntimeException('ابتدا موارد قرمز در بررسی سرور را برطرف کنید.');
        $host = trim((string)($_POST['db_host'] ?? 'localhost')) ?: 'localhost';
        $port = (int)($_POST['db_port'] ?? 3306); if ($port < 1 || $port > 65535) $port = 3306;
        $database = trim((string)($_POST['db_name'] ?? ''));
        $username = trim((string)($_POST['db_user'] ?? ''));
        $password = (string)($_POST['db_pass'] ?? '');
        $neshan = trim((string)($_POST['neshan_map_key'] ?? ''));
        $adminName = trim((string)($_POST['admin_name'] ?? 'مدیر رادو')) ?: 'مدیر رادو';
        $adminPhone = trim((string)($_POST['admin_phone'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        if ($database === '' || $username === '') throw new RuntimeException('نام دیتابیس و نام کاربری دیتابیس الزامی است.');
        if ($neshan === '') throw new RuntimeException('API Key نشان را وارد کنید.');
        if ($adminPhone === '') throw new RuntimeException('شماره مدیر را وارد کنید.');
        if (strlen($adminPassword) < 8) throw new RuntimeException('رمز مدیر باید حداقل ۸ کاراکتر باشد.');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->query('SELECT 1');
        importSchema($pdo, $root . '/sql/schema.sql');

        $adminId = uuid4();
        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users(id,phone,role,full_name,is_active) VALUES(?,?,'admin',?,1) ON DUPLICATE KEY UPDATE role='admin',full_name=VALUES(full_name),is_active=1");
        $stmt->execute([$adminId,$adminPhone,$adminName]);
        $existing = $pdo->prepare('SELECT id FROM users WHERE phone=? LIMIT 1'); $existing->execute([$adminPhone]); $adminId = (string)($existing->fetchColumn() ?: $adminId);
        $stmt = $pdo->prepare('INSERT INTO admin_users(id,phone,full_name,password_hash,is_active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),password_hash=VALUES(password_hash),is_active=1');
        $stmt->execute([$adminId,$adminPhone,$adminName,$hash]);

        writePhpArray($dbConfigFile, ['driver'=>'mysql','host'=>$host,'port'=>$port,'database'=>$database,'username'=>$username,'password'=>$password,'charset'=>'utf8mb4']);

        if (is_file($bootstrap) && is_file($private . '/rado-release.keystore')) {
            $sign = require $bootstrap;
        } else {
            if (!isset($_FILES['signing_zip']) || ($_FILES['signing_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('فایل امضای دائمی RADO را انتخاب کنید.');
            $sign = readSigningZip($_FILES['signing_zip']['tmp_name']);
            if (file_put_contents($private . '/rado-release.keystore', $sign['keystore'], LOCK_EX) === false) throw new RuntimeException('ذخیره keystore ممکن نشد.');
        }
        writePhpArray($ciConfigFile, ['store_password'=>(string)$sign['store_password'],'key_alias'=>(string)$sign['key_alias'],'key_password'=>(string)$sign['key_password'],'neshan_map_key'=>$neshan]);
        @chmod($private . '/rado-release.keystore', 0600);
        @unlink($bootstrap);

        $install = ['installed_at'=>gmdate('c'),'version'=>'0.2.3','database'=>'mysql','domain'=>'https://rado-taxi.sbs'];
        file_put_contents($state . '/install.json', json_encode($install, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
        file_put_contents($lock, gmdate('c') . "\n", LOCK_EX); @chmod($lock, 0600);
        $message = 'نصب کامل شد: دیتابیس ساخته شد، مدیر اولیه ثبت شد، نشان و امضای ثابت فعال شدند.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$needsSigningUpload = !is_file($bootstrap) || !is_file($private . '/rado-release.keystore');
$cron = '*/5 * * * * /usr/local/bin/php ' . $root . '/rado-system/bin/rado-update.php >/dev/null 2>&1';
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب سریع RADO</title><style>body{font-family:Tahoma,Arial;background:#f4f4f1;color:#171717;margin:0}.wrap{max-width:760px;margin:5vh auto;padding:18px}.box{background:#fff;padding:28px;border-radius:28px;box-shadow:0 16px 50px #0001;margin-bottom:16px}.logo{font-size:44px;font-weight:900}.gold{color:#d99b00}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}label{font-weight:700;font-size:13px}input{width:100%;box-sizing:border-box;padding:13px 14px;border:1px solid #ddd;border-radius:13px;margin:7px 0 12px;font-size:15px}button{width:100%;padding:15px;border:0;border-radius:15px;background:#171717;color:#fff;font-weight:800;font-size:16px}.ok,.err{padding:14px;border-radius:14px}.ok{background:#e8f7ec}.err{background:#fff0f0}.check{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #eee}.yes{color:#16863c}.no{color:#c62828}.hint{color:#666;font-size:12px;line-height:1.9}.code{direction:ltr;text-align:left;background:#171717;color:#fff;padding:13px;border-radius:13px;overflow:auto;font-size:12px}@media(max-width:600px){.grid{grid-template-columns:1fr}.full{grid-column:auto}.box{padding:20px}.wrap{margin:1vh auto}}</style></head><body><div class="wrap"><div class="box"><div class="logo">R<span class="gold">A</span>DO</div><h2>نصب سریع رادو</h2><p class="hint">دیتابیس را یک‌بار در cPanel بساز؛ بقیه جدول‌ها و تنظیمات را این نصب‌کننده خودش انجام می‌دهد.</p><?php if($message):?><div class="ok"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?></div><div class="box"><h3>بررسی سرور</h3><?php foreach($checks as $name=>$ok):?><div class="check"><span><?=e($name)?></span><b class="<?=$ok?'yes':'no'?>"><?=$ok?'آماده ✓':'نیاز به بررسی ✕'?></b></div><?php endforeach;?></div><?php if(!is_file($lock)):?><form method="post" enctype="multipart/form-data" autocomplete="off"><div class="box"><h3>۱. اتصال دیتابیس MySQL / MariaDB</h3><div class="grid"><div><label>DB Host</label><input name="db_host" value="<?=e((string)($_POST['db_host']??'localhost'))?>" required></div><div><label>DB Port</label><input name="db_port" type="number" value="<?=e((string)($_POST['db_port']??'3306'))?>" required></div><div><label>نام دیتابیس</label><input name="db_name" value="<?=e((string)($_POST['db_name']??''))?>" required></div><div><label>نام کاربری دیتابیس</label><input name="db_user" value="<?=e((string)($_POST['db_user']??''))?>" required></div><div class="full"><label>رمز دیتابیس</label><input name="db_pass" type="password" required></div></div></div><div class="box"><h3>۲. مدیر اولیه</h3><div class="grid"><div><label>نام مدیر</label><input name="admin_name" value="<?=e((string)($_POST['admin_name']??'مدیر رادو'))?>" required></div><div><label>شماره موبایل مدیر</label><input name="admin_phone" inputmode="tel" value="<?=e((string)($_POST['admin_phone']??''))?>" required></div><div class="full"><label>رمز ورود مدیر</label><input name="admin_password" type="password" minlength="8" required></div></div></div><div class="box"><h3>۳. سرویس نقشه و Build</h3><label>API Key نشان</label><input name="neshan_map_key" type="password" required><?php if($needsSigningUpload):?><label>فایل امضای دائمی RADO</label><input name="signing_zip" type="file" accept=".zip" required><?php else:?><p class="ok">کلید امضای دائمی داخل بسته First Install شناسایی شد ✓</p><?php endif;?><p class="hint">کلیدها داخل پوشه محافظت‌شده هاست ذخیره می‌شوند و وارد GitHub عمومی نمی‌شوند.</p><button type="submit" <?=$systemOk?'':'disabled'?>>نصب و راه‌اندازی کامل RADO</button></div></form><?php else:?><div class="box"><h3>نصب انجام شد ✅</h3><p><a href="/">رفتن به صفحه اصلی RADO</a> — <a href="/api/health/">تست سلامت سیستم</a></p><p class="hint">Cron زیر را در cPanel → Cron Jobs ثبت کن تا cPanel هر ۵ دقیقه GitHub را بررسی کند:</p><div class="code"><?=e($cron)?></div></div><?php endif;?></div></body></html>
