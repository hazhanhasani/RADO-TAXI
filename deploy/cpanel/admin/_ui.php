<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/rado-system/lib/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('rado_admin');
    session_set_cookie_params([
        'httponly'=>true,
        'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite'=>'Strict',
        'path'=>'/',
    ]);
    session_start();
}

function ra_e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function ra_money(int|float|string|null $v): string { return number_format((int)($v ?? 0)) . ' ریال'; }
function ra_date(?string $v): string { return $v ? rado_jalali_datetime($v) : '—'; }
function ra_csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return (string)$_SESSION['csrf']; }
function ra_require_csrf(): void { if (!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))) { http_response_code(419); exit('درخواست نامعتبر است.'); } }
function ra_require_admin(): void { if (empty($_SESSION['admin_id'])) { header('Location: /admin/'); exit; } }
function ra_state(string $name, string $fallback='—'): string {
    $path=dirname(__DIR__).'/rado-system/state/'.$name;
    if (!is_file($path)) return $fallback;
    $v=trim((string)@file_get_contents($path));
    return $v!==''?$v:$fallback;
}
function ra_table_exists(PDO $pdo, string $table): bool {
    try { $s=$pdo->prepare('SHOW TABLES LIKE ?'); $s->execute([$table]); return (bool)$s->fetchColumn(); } catch(Throwable) { return false; }
}
function ra_scalar(PDO $pdo, string $sql, int|float $fallback=0): int|float {
    try { $v=$pdo->query($sql)->fetchColumn(); return is_numeric($v)?$v:$fallback; } catch(Throwable) { return $fallback; }
}
function ra_nav(): array { return [
    'dashboard'=>['داشبورد','/admin/dashboard.php','⌂'],
    'trips'=>['سفرها','/admin/trips.php','↔'],
    'drivers'=>['رانندگان','/admin/drivers.php','🚕'],
    'passengers'=>['مسافران','/admin/passengers.php','👤'],
    'operations'=>['عملیات و Dispatch','/admin/operations.php','◎'],
    'live'=>['نقشه زنده','/admin/live-map.php','◉'],
    'finance'=>['مالی و تعرفه','/admin/finance.php','﷼'],
    'growth'=>['رشد و کمپین','/admin/growth.php','★'],
    'support'=>['پشتیبانی','/admin/support.php','?'],
    'reports'=>['گزارش‌ها','/admin/reports.php','▤'],
    'maps'=>['نقشه و Neshan','/admin/maps.php','⌖'],
    'platform'=>['تنظیمات پیشرفته','/admin/platform.php','⚙'],
    'system'=>['سیستم و آپدیت','/admin/system.php','↻'],
]; }
function ra_header(string $title, string $active='dashboard', string $subtitle=''): void {
    $nav=ra_nav(); $name=(string)($_SESSION['admin_name']??'مدیر RADO'); $now=rado_jalali_long();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.ra_e($title).' — RADO</title><style>
    :root{--gold:#f5b400;--ink:#171717;--bg:#f4f4f1;--muted:#777;--line:#e9e9e4;--green:#16743a;--red:#b11f28}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Tahoma,Arial;color:var(--ink)}a{color:inherit}.app{display:grid;grid-template-columns:260px minmax(0,1fr);min-height:100vh}.side{position:sticky;top:0;height:100vh;background:#151515;color:#fff;padding:18px 14px;overflow:auto}.brand{padding:8px 10px 18px}.logo{font-size:34px;font-weight:900;letter-spacing:1px}.gold{color:var(--gold)}.brand small{color:#aaa}.nav a{display:flex;gap:10px;align-items:center;text-decoration:none;padding:11px 12px;border-radius:13px;color:#d5d5d5;margin:3px 0;font-size:13px}.nav a:hover,.nav a.on{background:#292929;color:#fff}.nav .ico{width:24px;text-align:center;color:var(--gold);font-weight:900}.main{min-width:0;padding:18px 22px 40px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;border-radius:20px;padding:16px 18px;box-shadow:0 8px 26px #00000008}.topbar h1{font-size:20px;margin:0}.topbar .sub{color:var(--muted);font-size:11px;margin-top:5px}.adminbox{display:flex;gap:10px;align-items:center}.pill{display:inline-block;padding:5px 9px;border-radius:99px;background:#fff5cf;color:#6b5200;font-size:10px}.logout{border:0;background:#171717;color:#fff;border-radius:11px;padding:9px 12px}.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px;margin-top:14px}.card{background:#fff;border-radius:20px;padding:17px;box-shadow:0 8px 28px #00000008}.span3{grid-column:span 3}.span4{grid-column:span 4}.span6{grid-column:span 6}.span8{grid-column:span 8}.span12{grid-column:1/-1}.metric small{color:var(--muted)}.metric b{display:block;font-size:23px;margin-top:8px}.section-title{font-size:16px;font-weight:900;margin:0 0 10px}.muted{color:var(--muted);font-size:11px;line-height:1.8}.notice{padding:12px 14px;border-radius:14px;margin-top:12px}.ok{background:#eaf7ee;color:var(--green)}.warn{background:#fff5cf;color:#6b5200}.err{background:#fff0f0;color:var(--red)}.tablewrap{overflow:auto}.table{border-collapse:collapse;width:100%;min-width:850px}.table th,.table td{text-align:right;padding:10px;border-bottom:1px solid var(--line);font-size:11px;vertical-align:middle}.table th{color:var(--muted)}.badge{display:inline-block;padding:5px 8px;border-radius:99px;background:#f1f1ee;font-size:10px}.green{background:#e8f7ed;color:var(--green)}.red{background:#fff0f0;color:var(--red)}.yellow{background:#fff5cf;color:#6b5200}.formgrid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.field label{display:block;font-size:11px;font-weight:800;margin-bottom:5px}.field input,.field select,.field textarea{width:100%;padding:11px;border:1px solid #ddd;border-radius:11px;background:#fff}.full{grid-column:1/-1}.btn{display:inline-block;border:0;border-radius:11px;padding:10px 14px;background:#171717;color:#fff;font-weight:800;text-decoration:none}.btn.goldbtn{background:var(--gold);color:#171717}.module{display:flex;gap:12px;align-items:flex-start;text-decoration:none}.module .icon{width:42px;height:42px;display:grid;place-items:center;border-radius:13px;background:#fff5cf;font-size:20px}.module b{display:block;margin-bottom:5px}.module small{color:var(--muted);line-height:1.7}@media(max-width:1050px){.app{grid-template-columns:1fr}.side{position:relative;height:auto}.nav{display:grid;grid-template-columns:repeat(3,1fr)}.main{padding:12px}.span3{grid-column:span 6}.span4{grid-column:span 6}.span8,.span6{grid-column:1/-1}}@media(max-width:620px){.nav{grid-template-columns:repeat(2,1fr)}.span3,.span4,.span6,.span8{grid-column:1/-1}.topbar{align-items:flex-start}.adminbox{flex-direction:column;align-items:flex-end}.formgrid{grid-template-columns:1fr}}
    </style></head><body><div class="app"><aside class="side"><div class="brand"><div class="logo">R<span class="gold">A</span>DO</div><small>پنل مدیریت بانه</small></div><div class="nav">';
    foreach($nav as $key=>$item){ echo '<a class="'.($active===$key?'on':'').'" href="'.ra_e($item[1]).'"><span class="ico">'.ra_e($item[2]).'</span><span>'.ra_e($item[0]).'</span></a>'; }
    echo '</div></aside><main class="main"><div class="topbar"><div><h1>'.ra_e($title).'</h1><div class="sub">'.ra_e($subtitle!==''?$subtitle:$now).' <span class="pill">Asia/Tehran</span></div></div><div class="adminbox"><span class="muted">'.ra_e($name).'</span><form method="post" action="/admin/"><input type="hidden" name="csrf" value="'.ra_e(ra_csrf()).'"><input type="hidden" name="action" value="logout"><button class="logout">خروج</button></form></div></div>';
}
function ra_footer(): void { echo '</main></div></body></html>'; }
