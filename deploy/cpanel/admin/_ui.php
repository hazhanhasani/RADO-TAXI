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
    'operations'=>['عملیات','/admin/operations.php','◎'],
    'finance'=>['مالی','/admin/finance.php','﷼'],
    'growth'=>['رشد','/admin/growth.php','★'],
    'support'=>['پشتیبانی','/admin/support.php','?'],
    'reports'=>['گزارش‌ها','/admin/reports.php','▤'],
    'maps'=>['نقشه','/admin/maps.php','⌖'],
    'platform'=>['تنظیمات','/admin/settings.php','⚙'],
    'system'=>['سیستم','/admin/system.php','↻'],
]; }
function ra_header(string $title, string $active='dashboard', string $subtitle=''): void {
    $nav=ra_nav(); $name=(string)($_SESSION['admin_name']??'مدیر RADO'); $now=rado_jalali_long();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>'.ra_e($title).' — RADO</title><style>
:root{--gold:#f5b400;--ink:#171717;--bg:#f4f4f1;--muted:#777;--line:#e9e9e4;--green:#16743a;--red:#b11f28;--nav:#151515}*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);font-family:Tahoma,Arial,sans-serif;color:var(--ink)}a{color:inherit}.app{min-height:100vh}.side{position:sticky;top:0;z-index:50;background:var(--nav);color:#fff;border-bottom:1px solid #292929;box-shadow:0 8px 30px #00000018}.navshell{max-width:1500px;margin:auto;display:flex;align-items:center;gap:12px;padding:9px 14px}.brand{display:flex;align-items:center;gap:8px;flex:0 0 auto;padding:0 4px}.logo{font-size:25px;font-weight:900;letter-spacing:1px;line-height:1}.gold{color:var(--gold)}.brand small{display:block;color:#999;font-size:9px;margin-top:3px}.nav{display:flex;align-items:center;gap:6px;overflow-x:auto;overscroll-behavior-x:contain;scrollbar-width:none;-webkit-overflow-scrolling:touch;white-space:nowrap;flex:1;padding:2px 0}.nav::-webkit-scrollbar{display:none}.nav a{flex:0 0 auto;display:flex;gap:6px;align-items:center;text-decoration:none;padding:8px 10px;border-radius:11px;color:#cfcfcf;font-size:11px;border:1px solid transparent;transition:.15s}.nav a:hover{background:#222;color:#fff}.nav a.on{background:#2a2a2a;color:#fff;border-color:#3a3a3a;box-shadow:inset 0 -2px 0 var(--gold)}.nav .ico{width:18px;text-align:center;color:var(--gold);font-weight:900}.main{max-width:1500px;margin:auto;min-width:0;padding:14px 16px 36px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;border-radius:18px;padding:13px 15px;box-shadow:0 7px 24px #00000008}.topbar h1{font-size:19px;margin:0}.topbar .sub{color:var(--muted);font-size:10px;margin-top:4px;line-height:1.8}.adminbox{display:flex;gap:8px;align-items:center;flex:0 0 auto}.pill{display:inline-block;padding:4px 8px;border-radius:99px;background:#fff5cf;color:#6b5200;font-size:9px}.logout{border:0;background:#171717;color:#fff;border-radius:10px;padding:8px 11px;font-size:11px}.grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px;margin-top:12px}.card{background:#fff;border-radius:17px;padding:15px;box-shadow:0 7px 24px #00000008}.span3{grid-column:span 3}.span4{grid-column:span 4}.span6{grid-column:span 6}.span8{grid-column:span 8}.span12{grid-column:1/-1}.metric small{color:var(--muted)}.metric b{display:block;font-size:22px;margin-top:7px}.section-title{font-size:15px;font-weight:900;margin:0 0 9px}.muted{color:var(--muted);font-size:10px;line-height:1.8}.notice{padding:11px 13px;border-radius:13px;margin-top:10px;font-size:11px;line-height:1.9}.ok{background:#eaf7ee;color:var(--green)}.warn{background:#fff5cf;color:#6b5200}.err{background:#fff0f0;color:var(--red)}.tablewrap{overflow:auto;border-radius:12px}.table{border-collapse:collapse;width:100%;min-width:760px}.table th,.table td{text-align:right;padding:9px;border-bottom:1px solid var(--line);font-size:10px;vertical-align:middle}.table th{color:var(--muted);font-weight:800}.badge{display:inline-block;padding:5px 8px;border-radius:99px;background:#f1f1ee;font-size:9px}.green{background:#e8f7ed;color:var(--green)}.red{background:#fff0f0;color:var(--red)}.yellow{background:#fff5cf;color:#6b5200}.formgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.field label{display:block;font-size:10px;font-weight:800;margin-bottom:5px}.field input,.field select,.field textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;background:#fff;font:inherit}.full{grid-column:1/-1}.btn{display:inline-block;border:0;border-radius:10px;padding:9px 13px;background:#171717;color:#fff;font-weight:800;text-decoration:none;font-size:11px}.btn.goldbtn{background:var(--gold);color:#171717}.module{display:flex;gap:11px;align-items:flex-start;text-decoration:none}.module .icon{width:40px;height:40px;display:grid;place-items:center;border-radius:12px;background:#fff5cf;font-size:18px;flex:0 0 auto}.module b{display:block;margin-bottom:4px}.module small{color:var(--muted);line-height:1.7}.subnav{display:flex;gap:7px;overflow-x:auto;white-space:nowrap;scrollbar-width:none;margin:10px 0 0}.subnav::-webkit-scrollbar{display:none}.subnav a{flex:0 0 auto;text-decoration:none;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 10px;font-size:10px}.subnav a.on{background:#171717;color:#fff;border-color:#171717}@media(max-width:900px){.navshell{padding:8px 10px;gap:8px}.brand small{display:none}.logo{font-size:22px}.main{padding:10px 10px 30px}.span3,.span4{grid-column:span 6}.span8,.span6{grid-column:1/-1}}@media(max-width:620px){.side{position:sticky}.navshell{display:block;padding:8px 9px 7px}.brand{height:28px;justify-content:space-between}.brand:after{content:"پنل مدیریت بانه";font-size:9px;color:#888}.nav{margin-top:6px;gap:5px}.nav a{padding:7px 9px;font-size:10px}.nav .ico{width:auto}.main{padding:9px 8px 26px}.topbar{border-radius:15px;padding:11px 12px;align-items:flex-start}.topbar h1{font-size:17px}.topbar .sub{font-size:9px}.adminbox .muted{display:none}.logout{padding:7px 9px}.span3,.span4,.span6,.span8{grid-column:1/-1}.formgrid{grid-template-columns:1fr}.card{border-radius:15px;padding:13px}.metric b{font-size:20px}}
</style></head><body><div class="app"><header class="side"><div class="navshell"><div class="brand"><div><div class="logo">R<span class="gold">A</span>DO</div><small>پنل مدیریت بانه</small></div></div><nav class="nav" aria-label="موضوعات مدیریت">';
    foreach($nav as $key=>$item){ echo '<a class="'.($active===$key?'on':'').'" href="'.ra_e($item[1]).'"><span class="ico">'.ra_e($item[2]).'</span><span>'.ra_e($item[0]).'</span></a>'; }
    echo '</nav></div></header><main class="main"><div class="topbar"><div><h1>'.ra_e($title).'</h1><div class="sub">'.ra_e($subtitle!==''?$subtitle:$now).' <span class="pill">Asia/Tehran</span></div></div><div class="adminbox"><span class="muted">'.ra_e($name).'</span><form method="post" action="/admin/"><input type="hidden" name="csrf" value="'.ra_e(ra_csrf()).'"><input type="hidden" name="action" value="logout"><button class="logout">خروج</button></form></div></div>';
}
function ra_footer(): void { echo '</main></div></body></html>'; }
