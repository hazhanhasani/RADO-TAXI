<?php
declare(strict_types=1);
require dirname(__DIR__) . '/rado-system/lib/app.php';

session_name('rado_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function money(int|float|string|null $v): string { return number_format((int)($v ?? 0)) . ' ریال'; }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['csrf'];
}
function require_csrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419); exit('درخواست نامعتبر است.');
    }
}

$pdo = rado_db();
$error = '';
$message = '';

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE phone=? AND is_active=1 LIMIT 1');
    $stmt->execute([$phone]);
    $admin = $stmt->fetch();
    if (is_array($admin) && password_verify($password, (string)$admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (string)$admin['id'];
        $_SESSION['admin_name'] = (string)$admin['full_name'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: /admin/'); exit;
    }
    $error = 'شماره موبایل یا رمز عبور اشتباه است.';
}

if (empty($_SESSION['admin_id'])) {
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود مدیریت RADO</title><style>body{margin:0;font-family:Tahoma,Arial;background:#111;color:#171717}.wrap{min-height:100vh;display:grid;place-items:center;padding:20px}.box{width:min(420px,100%);background:#fff;border-radius:28px;padding:30px;box-sizing:border-box;box-shadow:0 24px 80px #0006}.logo{font-size:46px;font-weight:900}.gold{color:#f5b400}h2{margin:8px 0 24px}label{font-size:13px;font-weight:800}input{width:100%;box-sizing:border-box;padding:14px;margin:7px 0 16px;border:1px solid #ddd;border-radius:14px;font-size:15px}button{width:100%;padding:15px;border:0;border-radius:15px;background:#171717;color:#fff;font-size:16px;font-weight:900}.err{background:#fff0f0;color:#a11;padding:12px;border-radius:12px;margin-bottom:14px}</style></head><body><div class="wrap"><form class="box" method="post" autocomplete="off"><div class="logo">R<span class="gold">A</span>DO</div><h2>پنل مدیریت</h2><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?><input type="hidden" name="action" value="login"><label>شماره موبایل مدیر</label><input name="phone" inputmode="tel" required autofocus><label>رمز عبور</label><input name="password" type="password" required><button>ورود به پنل</button></form></div></body></html><?php exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'logout') {
            $_SESSION = []; session_destroy(); header('Location: /admin/'); exit;
        }
        if ($action === 'save_pricing') {
            $title = trim((string)($_POST['title'] ?? 'تعرفه اصلی بانه')) ?: 'تعرفه اصلی بانه';
            $fields = ['base_fare','per_km','per_minute','waiting_per_minute','minimum_fare'];
            $values = [];
            foreach ($fields as $field) {
                $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < 0) throw new RuntimeException('مبالغ تعرفه باید عدد صحیح و صفر یا بیشتر باشند.');
                $values[$field] = $value;
            }
            $surge = (float)($_POST['surge_multiplier'] ?? 1);
            if ($surge < 1 || $surge > 5) throw new RuntimeException('ضریب شلوغی باید بین ۱ تا ۵ باشد.');
            $pdo->beginTransaction();
            $pdo->exec('UPDATE pricing_rules SET active=0,effective_to=NOW() WHERE active=1');
            $stmt = $pdo->prepare('INSERT INTO pricing_rules(title,base_fare,per_km,per_minute,waiting_per_minute,minimum_fare,surge_multiplier,active,effective_from) VALUES(?,?,?,?,?,?,?,1,NOW())');
            $stmt->execute([$title,$values['base_fare'],$values['per_km'],$values['per_minute'],$values['waiting_per_minute'],$values['minimum_fare'],$surge]);
            $pdo->commit();
            $message = 'تعرفه جدید فعال شد. از همین لحظه محاسبه کرایه با این اعداد انجام می‌شود.';
        }
        if ($action === 'save_default_commission') {
            $rate = (float)($_POST['default_commission_rate'] ?? 10);
            if ($rate < 0 || $rate > 100) throw new RuntimeException('کمیسیون باید بین ۰ تا ۱۰۰ درصد باشد.');
            rado_set_setting($pdo, 'default_driver_commission_rate', number_format($rate, 2, '.', ''));
            $message = 'کمیسیون پیش‌فرض ذخیره شد.';
        }
        if ($action === 'save_driver_commission') {
            $driverId = trim((string)($_POST['driver_id'] ?? ''));
            $rate = (float)($_POST['commission_rate'] ?? -1);
            if ($driverId === '' || $rate < 0 || $rate > 100) throw new RuntimeException('اطلاعات کمیسیون راننده معتبر نیست.');
            $stmt = $pdo->prepare('UPDATE drivers SET commission_rate=? WHERE user_id=?');
            $stmt->execute([$rate,$driverId]);
            $message = 'کمیسیون اختصاصی راننده بروزرسانی شد.';
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $ex->getMessage();
    }
}

$pricing = rado_active_pricing_rule($pdo);
$defaultCommission = (float)(rado_setting($pdo, 'default_driver_commission_rate', '10.00') ?? '10.00');
$stats = [
    'today_trips' => (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE DATE(requested_at)=CURDATE()")->fetchColumn(),
    'active_drivers' => (int)$pdo->query("SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)")->fetchColumn(),
    'completed' => (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status='completed'")->fetchColumn(),
    'gross' => (int)$pdo->query("SELECT COALESCE(SUM(COALESCE(final_fare,estimated_fare)),0) FROM trips WHERE status='completed'")->fetchColumn(),
];
$drivers = $pdo->query("SELECT d.user_id,d.status,d.commission_rate,u.full_name,u.phone,d.plate_number,d.vehicle_make,d.vehicle_model FROM drivers d JOIN users u ON u.id=d.user_id ORDER BY FIELD(d.status,'pending','approved','suspended','rejected'),u.created_at DESC LIMIT 100")->fetchAll();
$trips = $pdo->query("SELECT t.id,t.status,t.pickup_label,t.destination_label,t.estimated_fare,t.final_fare,t.requested_at,pu.full_name passenger_name,du.full_name driver_name FROM trips t LEFT JOIN users pu ON pu.id=t.passenger_id LEFT JOIN users du ON du.id=t.driver_id ORDER BY t.requested_at DESC LIMIT 50")->fetchAll();
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مدیریت RADO</title><style>:root{--gold:#f5b400;--black:#171717;--bg:#f4f4f1;--muted:#6b6b6b}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--black);font-family:Tahoma,Arial}.shell{max-width:1240px;margin:auto;padding:18px}.top{display:flex;align-items:center;justify-content:space-between;background:#171717;color:#fff;border-radius:24px;padding:18px 22px;gap:15px}.logo{font-size:35px;font-weight:900}.gold{color:var(--gold)}.top small{color:#bbb}.logout button{background:#2b2b2b;color:#fff;border:0;border-radius:12px;padding:10px 14px;cursor:pointer}.notice{padding:13px 15px;border-radius:14px;margin:14px 0}.ok{background:#e9f8ed;color:#17652d}.err{background:#fff0f0;color:#a31d1d}.warn{background:#fff5cf;color:#6b5200}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0}.stat,.card{background:#fff;border-radius:22px;padding:18px;box-shadow:0 8px 28px #0000000b}.stat b{font-size:27px;display:block;margin-top:8px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.card h3{margin-top:0}.fields{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.field label{font-size:12px;font-weight:800}.field input{width:100%;padding:12px;border:1px solid #ddd;border-radius:12px;margin-top:5px}.field.full{grid-column:1/-1}.primary{width:100%;padding:13px;border:0;border-radius:13px;background:#171717;color:#fff;font-weight:900;cursor:pointer}.tables{margin-top:14px}.tablewrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:760px}.table th,.table td{text-align:right;padding:11px;border-bottom:1px solid #eee;font-size:13px}.table th{font-size:12px;color:var(--muted)}.mini{display:flex;gap:6px;align-items:center}.mini input{width:82px;padding:8px;border:1px solid #ddd;border-radius:9px}.mini button{border:0;background:#171717;color:#fff;border-radius:9px;padding:8px 10px}.badge{display:inline-block;padding:5px 9px;border-radius:99px;background:#f1f1ef;font-size:11px}.section-title{display:flex;align-items:center;justify-content:space-between;margin:22px 2px 10px}.muted{color:var(--muted);font-size:12px}@media(max-width:850px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}.fields{grid-template-columns:1fr}.field.full{grid-column:auto}}@media(max-width:520px){.stats{grid-template-columns:1fr}.top{align-items:flex-start}.shell{padding:10px}}</style></head><body><div class="shell"><div class="top"><div><div class="logo">R<span class="gold">A</span>DO <small>ADMIN</small></div><small>سلام <?=e((string)($_SESSION['admin_name'] ?? 'مدیر'))?> — مدیریت تاکسی اینترنتی بانه</small></div><form class="logout" method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="logout"><button>خروج</button></form></div><?php if($message):?><div class="notice ok"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice err"><?=e($error)?></div><?php endif;?><?php if(!$pricing):?><div class="notice warn"><b>تعرفه فعال وجود ندارد.</b> تا وقتی تعرفه را ذخیره نکنی، اپ مسافر اجازه درخواست سفر نمی‌دهد.</div><?php endif;?><div class="stats"><div class="stat"><span>سفرهای امروز</span><b><?=$stats['today_trips']?></b></div><div class="stat"><span>راننده آنلاین</span><b><?=$stats['active_drivers']?></b></div><div class="stat"><span>سفر تکمیل‌شده</span><b><?=$stats['completed']?></b></div><div class="stat"><span>فروش سفرهای تکمیل‌شده</span><b><?=money($stats['gross'])?></b></div></div><div class="grid"><div class="card"><h3>تعرفه و محاسبه کرایه</h3><p class="muted">کرایه سمت سرور محاسبه می‌شود؛ اپ مسافر نمی‌تواند مبلغ را تغییر دهد.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_pricing"><div class="fields"><div class="field full"><label>نام تعرفه</label><input name="title" value="<?=e((string)($pricing['title'] ?? 'تعرفه اصلی بانه'))?>"></div><div class="field"><label>ورودی / مبلغ پایه (ریال)</label><input name="base_fare" type="number" min="0" value="<?=e((string)($pricing['base_fare'] ?? 0))?>" required></div><div class="field"><label>هر کیلومتر (ریال)</label><input name="per_km" type="number" min="0" value="<?=e((string)($pricing['per_km'] ?? 0))?>" required></div><div class="field"><label>هر دقیقه مسیر (ریال)</label><input name="per_minute" type="number" min="0" value="<?=e((string)($pricing['per_minute'] ?? 0))?>" required></div><div class="field"><label>هر دقیقه انتظار (ریال)</label><input name="waiting_per_minute" type="number" min="0" value="<?=e((string)($pricing['waiting_per_minute'] ?? 0))?>" required></div><div class="field"><label>حداقل کرایه (ریال)</label><input name="minimum_fare" type="number" min="0" value="<?=e((string)($pricing['minimum_fare'] ?? 0))?>" required></div><div class="field"><label>ضریب شلوغی</label><input name="surge_multiplier" type="number" min="1" max="5" step="0.05" value="<?=e((string)($pricing['surge_multiplier'] ?? '1.000'))?>" required></div><div class="field full"><button class="primary">ذخیره و فعال‌سازی تعرفه</button></div></div></form></div><div class="card"><h3>کمیسیون رانندگان</h3><p class="muted">درصدی که رادو از کرایه سفر دریافت می‌کند. برای هر راننده هم می‌توانی نرخ جدا داشته باشی.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_default_commission"><div class="fields"><div class="field full"><label>کمیسیون پیش‌فرض (%)</label><input name="default_commission_rate" type="number" min="0" max="100" step="0.25" value="<?=e((string)$defaultCommission)?>" required></div><div class="field full"><button class="primary">ذخیره کمیسیون پیش‌فرض</button></div></div></form><div style="margin-top:18px" class="notice warn">کمیسیون اختصاصی هر راننده در جدول پایین قابل تغییر است. نرخ راننده هنگام تسویه سفر استفاده خواهد شد.</div></div></div><div class="section-title"><h3>رانندگان</h3><span class="muted"><?=count($drivers)?> رکورد</span></div><div class="card tables"><div class="tablewrap"><table class="table"><thead><tr><th>راننده</th><th>موبایل</th><th>خودرو / پلاک</th><th>وضعیت</th><th>کمیسیون</th></tr></thead><tbody><?php foreach($drivers as $d):?><tr><td><?=e((string)($d['full_name'] ?: 'بدون نام'))?></td><td><?=e((string)$d['phone'])?></td><td><?=e(trim((string)($d['vehicle_make'].' '.$d['vehicle_model'].' '.$d['plate_number'])))?></td><td><span class="badge"><?=e((string)$d['status'])?></span></td><td><form class="mini" method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_driver_commission"><input type="hidden" name="driver_id" value="<?=e((string)$d['user_id'])?>"><input name="commission_rate" type="number" min="0" max="100" step="0.25" value="<?=e((string)$d['commission_rate'])?>"><button>ذخیره</button></form></td></tr><?php endforeach;?><?php if(!$drivers):?><tr><td colspan="5">هنوز راننده‌ای ثبت نشده است.</td></tr><?php endif;?></tbody></table></div></div><div class="section-title"><h3>آخرین سفرها</h3><span class="muted">۵۰ سفر آخر</span></div><div class="card tables"><div class="tablewrap"><table class="table"><thead><tr><th>زمان</th><th>مسافر</th><th>مبدا</th><th>مقصد</th><th>کرایه</th><th>راننده</th><th>وضعیت</th></tr></thead><tbody><?php foreach($trips as $t):?><tr><td><?=e((string)$t['requested_at'])?></td><td><?=e((string)($t['passenger_name'] ?: 'مسافر'))?></td><td><?=e((string)($t['pickup_label'] ?: '—'))?></td><td><?=e((string)($t['destination_label'] ?: '—'))?></td><td><?=money($t['final_fare'] ?? $t['estimated_fare'])?></td><td><?=e((string)($t['driver_name'] ?: 'در انتظار'))?></td><td><span class="badge"><?=e((string)$t['status'])?></span></td></tr><?php endforeach;?><?php if(!$trips):?><tr><td colspan="7">هنوز سفری ثبت نشده است.</td></tr><?php endif;?></tbody></table></div></div></div></body></html>
