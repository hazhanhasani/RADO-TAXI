<?php
declare(strict_types=1);
$root = __DIR__;
$lock = $root . '/rado-system/private/installed.lock';
if (!is_file($lock)) {
    header('Location: /setup.php', true, 302);
    exit;
}
$state = $root . '/rado-system/state/install.json';
$data = is_file($state) ? json_decode((string)file_get_contents($state), true) : [];
$installedAt = (string)($data['installed_at'] ?? '');
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RADO</title><style>body{margin:0;font-family:Tahoma,Arial;background:#f5f5f3;color:#171717}.box{max-width:680px;margin:10vh auto;padding:32px;background:#fff;border-radius:28px;box-shadow:0 16px 50px #0001}.logo{font-size:48px;font-weight:900}.gold{color:#d99b00}.ok{margin:18px 0;padding:16px;border-radius:16px;background:#eaf8ee}.row{display:flex;gap:12px;flex-wrap:wrap}.card{flex:1;min-width:180px;background:#f7f7f5;border-radius:18px;padding:16px}a{color:#171717;font-weight:700}</style></head><body><div class="box"><div class="logo">R<span class="gold">A</span>DO</div><h2>سامانه رادو فعال است</h2><div class="ok">نصب اولیه انجام شده و دامنه <b>rado-taxi.sbs</b> آماده سرویس‌دهی است.</div><div class="row"><div class="card"><b>API وضعیت</b><br><a href="/api/health/">/api/health/</a></div><div class="card"><b>آپدیت مسافر</b><br><a href="/api/app-updates/passenger">Passenger</a></div><div class="card"><b>آپدیت راننده</b><br><a href="/api/app-updates/driver">Driver</a></div></div><?php if($installedAt!==''):?><p style="color:#666">زمان نصب: <?=htmlspecialchars($installedAt,ENT_QUOTES,'UTF-8')?></p><?php endif;?></div></body></html>
