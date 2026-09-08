<?php
declare(strict_types=1);
require dirname(__DIR__) . '/rado-system/lib/app.php';

session_name('rado_admin');
session_set_cookie_params([
    'httponly'=>true,
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite'=>'Strict',
    'path'=>'/',
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
function driver_status_fa(string $s): string {
    return ['pending'=>'در انتظار تایید','approved'=>'تاییدشده','suspended'=>'تعلیق‌شده','rejected'=>'ردشده'][$s] ?? $s;
}
function trip_status_fa(string $s): string { return rado_trip_status_fa($s); }
function jdate(?string $v): string { return $v ? rado_jalali_datetime($v) : '—'; }

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
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود مدیریت RADO</title><style>body{margin:0;font-family:Tahoma,Arial;background:#111;color:#171717}.wrap{min-height:100vh;display:grid;place-items:center;padding:20px}.box{width:min(420px,100%);background:#fff;border-radius:28px;padding:30px;box-sizing:border-box}.logo{font-size:46px;font-weight:900}.gold{color:#f5b400}h2{margin:8px 0 6px}.time{color:#777;font-size:12px;margin-bottom:24px}label{font-size:13px;font-weight:800}input{width:100%;box-sizing:border-box;padding:14px;margin:7px 0 16px;border:1px solid #ddd;border-radius:14px;font-size:15px}button{width:100%;padding:15px;border:0;border-radius:15px;background:#171717;color:#fff;font-size:16px;font-weight:900}.err{background:#fff0f0;color:#a11;padding:12px;border-radius:12px;margin-bottom:14px}</style></head><body><div class="wrap"><form class="box" method="post"><div class="logo">R<span class="gold">A</span>DO</div><h2>پنل مدیریت</h2><div class="time"><?=e(rado_jalali_long())?> — Asia/Tehran</div><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?><input type="hidden" name="action" value="login"><label>شماره موبایل مدیر</label><input name="phone" inputmode="tel" required autofocus><label>رمز عبور</label><input name="password" type="password" required><button>ورود به پنل</button></form></div></body></html><?php exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'logout') {
            $_SESSION=[]; session_destroy(); header('Location: /admin/'); exit;
        }
        if ($action === 'save_pricing') {
            $title = trim((string)($_POST['title'] ?? 'تعرفه اصلی بانه')) ?: 'تعرفه اصلی بانه';
            $values=[];
            foreach (['base_fare','per_km','per_minute','waiting_per_minute','minimum_fare'] as $field) {
                $value=filter_var($_POST[$field] ?? null,FILTER_VALIDATE_INT);
                if ($value===false || $value<0) throw new RuntimeException('مبالغ تعرفه باید عدد صحیح و صفر یا بیشتر باشند.');
                $values[$field]=$value;
            }
            $surge=(float)($_POST['surge_multiplier'] ?? 1);
            if ($surge<1 || $surge>5) throw new RuntimeException('ضریب شلوغی باید بین ۱ تا ۵ باشد.');
            $pdo->beginTransaction();
            $pdo->exec('UPDATE pricing_rules SET active=0,effective_to=NOW() WHERE active=1');
            $stmt=$pdo->prepare('INSERT INTO pricing_rules(title,base_fare,per_km,per_minute,waiting_per_minute,minimum_fare,surge_multiplier,active,effective_from) VALUES(?,?,?,?,?,?,?,1,NOW())');
            $stmt->execute([$title,$values['base_fare'],$values['per_km'],$values['per_minute'],$values['waiting_per_minute'],$values['minimum_fare'],$surge]);
            $pdo->commit();
            $message='تعرفه جدید فعال شد — '.rado_jalali_datetime();
        }
        if ($action === 'save_default_commission') {
            $rate=(float)($_POST['default_driver_commission_rate'] ?? 10);
            if ($rate<0 || $rate>100) throw new RuntimeException('کمیسیون باید بین ۰ تا ۱۰۰ درصد باشد.');
            rado_set_setting($pdo,'default_driver_commission_rate',number_format($rate,2,'.',''));
            $message='کمیسیون پیش‌فرض ذخیره شد.';
        }
        if ($action === 'save_driver') {
            $id=trim((string)($_POST['driver_id'] ?? ''));
            $status=trim((string)($_POST['status'] ?? ''));
            $rate=(float)($_POST['commission_rate'] ?? -1);
            $name=trim((string)($_POST['full_name'] ?? ''));
            $plate=trim((string)($_POST['plate_number'] ?? ''));
            $make=trim((string)($_POST['vehicle_make'] ?? ''));
            $model=trim((string)($_POST['vehicle_model'] ?? ''));
            $color=trim((string)($_POST['vehicle_color'] ?? ''));
            if ($id==='' || !in_array($status,['pending','approved','suspended','rejected'],true) || $rate<0 || $rate>100) throw new RuntimeException('اطلاعات راننده معتبر نیست.');
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('UPDATE users SET full_name=? WHERE id=? AND role=\'driver\'');
            $stmt->execute([$name!==''?$name:'راننده رادو',$id]);
            $stmt=$pdo->prepare("UPDATE drivers SET status=?,commission_rate=?,plate_number=?,vehicle_make=?,vehicle_model=?,vehicle_color=?,approved_at=CASE WHEN ?='approved' THEN COALESCE(approved_at,NOW()) ELSE approved_at END WHERE user_id=?");
            $stmt->execute([$status,$rate,$plate?:null,$make?:null,$model?:null,$color?:null,$status,$id]);
            if ($status!=='approved') {
                $off=$pdo->prepare('UPDATE driver_presence SET is_online=0 WHERE driver_id=?');
                $off->execute([$id]);
            }
            $pdo->commit();
            $message='اطلاعات راننده بروزرسانی شد — '.rado_jalali_datetime();
        }
    } catch(Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=$ex->getMessage();
    }
}

$pricing=rado_active_pricing_rule($pdo);
$defaultCommission=(float)(rado_setting($pdo,'default_driver_commission_rate','10.00') ?? '10.00');
$stats=[
    'today_trips'=>(int)$pdo->query("SELECT COUNT(*) FROM trips WHERE DATE(requested_at)=CURDATE()")->fetchColumn(),
    'active_drivers'=>(int)$pdo->query("SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)")->fetchColumn(),
    'completed'=>(int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status='completed'")->fetchColumn(),
    'gross'=>(int)$pdo->query("SELECT COALESCE(SUM(final_fare),0) FROM trips WHERE status='completed'")->fetchColumn(),
    'commission'=>(int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id IS NULL AND entry_type='platform_commission_income'")->fetchColumn(),
];
$drivers=$pdo->query("SELECT d.user_id,d.status,d.commission_rate,d.plate_number,d.vehicle_make,d.vehicle_model,d.vehicle_color,d.approved_at,u.full_name,p.is_online,p.last_seen_at,(SELECT COALESCE(SUM(le.amount),0) FROM ledger_entries le WHERE le.user_id=d.user_id) wallet_balance FROM drivers d JOIN users u ON u.id=d.user_id LEFT JOIN driver_presence p ON p.driver_id=d.user_id ORDER BY FIELD(d.status,'pending','approved','suspended','rejected'),u.created_at DESC LIMIT 150")->fetchAll();
$trips=$pdo->query("SELECT t.id,t.status,t.pickup_label,t.destination_label,t.estimated_fare,t.final_fare,t.requested_at,t.accepted_at,t.arrived_at,t.started_at,t.completed_at,pu.full_name passenger_name,du.full_name driver_name FROM trips t LEFT JOIN users pu ON pu.id=t.passenger_id LEFT JOIN users du ON du.id=t.driver_id ORDER BY t.requested_at DESC LIMIT 100")->fetchAll();
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مدیریت RADO</title><style>:root{--gold:#f5b400;--black:#171717;--bg:#f4f4f1;--muted:#6b6b6b}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--black);font-family:Tahoma,Arial}.shell{max-width:1380px;margin:auto;padding:18px}.top{display:flex;align-items:center;justify-content:space-between;background:#171717;color:#fff;border-radius:24px;padding:18px 22px;gap:15px}.logo{font-size:35px;font-weight:900}.gold{color:var(--gold)}.top small{color:#bbb}.clock{margin-top:6px;color:#f6d56a;font-size:12px}.logout button{background:#2b2b2b;color:#fff;border:0;border-radius:12px;padding:10px 14px}.notice{padding:13px 15px;border-radius:14px;margin:14px 0}.ok{background:#e9f8ed;color:#17652d}.err{background:#fff0f0;color:#a31d1d}.warn{background:#fff5cf;color:#6b5200}.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:16px 0}.stat,.card{background:#fff;border-radius:22px;padding:18px;box-shadow:0 8px 28px #0000000b}.stat b{font-size:24px;display:block;margin-top:8px}.grid{display:grid;grid-template-columns:1.4fr 1fr;gap:14px}.card h3{margin-top:0}.fields{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.field label{font-size:12px;font-weight:800}.field input,.field select{width:100%;padding:12px;border:1px solid #ddd;border-radius:12px;margin-top:5px;background:#fff}.field.full{grid-column:1/-1}.primary{width:100%;padding:13px;border:0;border-radius:13px;background:#171717;color:#fff;font-weight:900}.tablewrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:1000px}.table th,.table td{text-align:right;padding:10px;border-bottom:1px solid #eee;font-size:12px;vertical-align:middle}.table th{color:var(--muted);font-size:11px}.driverform{display:grid;grid-template-columns:1.2fr .8fr .8fr .9fr .9fr .9fr .7fr auto;gap:6px;min-width:1050px}.driverform input,.driverform select{padding:8px;border:1px solid #ddd;border-radius:9px;min-width:0}.driverform button{border:0;background:#171717;color:#fff;border-radius:9px;padding:8px 12px}.badge{display:inline-block;padding:5px 9px;border-radius:99px;background:#f1f1ef;font-size:10px}.online{background:#e8f7ec;color:#17652d}.section{margin-top:20px}.section h3{margin:0 2px 10px}.muted{color:var(--muted);font-size:11px}.jalali{white-space:nowrap;font-weight:700}.tz{display:inline-block;background:#fff5cf;color:#6b5200;padding:4px 8px;border-radius:99px;font-size:10px}@media(max-width:900px){.stats{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}.fields{grid-template-columns:1fr}}@media(max-width:520px){.stats{grid-template-columns:1fr}.shell{padding:10px}.top{align-items:flex-start}}</style></head><body><div class="shell">
<div class="top"><div><div class="logo">R<span class="gold">A</span>DO <small>ADMIN</small></div><small>مدیریت تاکسی اینترنتی بانه</small><div class="clock"><?=e(rado_jalali_long())?> <span class="tz">Asia/Tehran</span></div></div><form class="logout" method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="logout"><button>خروج</button></form></div>
<?php if($message):?><div class="notice ok"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice err"><?=e($error)?></div><?php endif;?>
<div class="stats"><div class="stat">سفرهای امروز<b><?=$stats['today_trips']?></b></div><div class="stat">راننده آنلاین<b><?=$stats['active_drivers']?></b></div><div class="stat">سفر تکمیل‌شده<b><?=$stats['completed']?></b></div><div class="stat">گردش سفر<b><?=money($stats['gross'])?></b></div><div class="stat">کمیسیون RADO<b><?=money($stats['commission'])?></b></div></div>
<div class="grid">
<div class="card"><h3>تعرفه و محاسبه کرایه</h3><p class="muted">کرایه فقط سمت سرور محاسبه می‌شود و اپ امکان تغییر مبلغ را ندارد.</p><?php if(!$pricing):?><div class="notice warn">تعرفه فعال وجود ندارد؛ تا ذخیره تعرفه، درخواست سفر ثبت نمی‌شود.</div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_pricing"><div class="fields"><div class="field full"><label>نام تعرفه</label><input name="title" value="<?=e((string)($pricing['title'] ?? 'تعرفه اصلی بانه'))?>"></div><div class="field"><label>مبلغ پایه (ریال)</label><input type="number" min="0" name="base_fare" required value="<?=e((string)($pricing['base_fare'] ?? 0))?>"></div><div class="field"><label>هر کیلومتر</label><input type="number" min="0" name="per_km" required value="<?=e((string)($pricing['per_km'] ?? 0))?>"></div><div class="field"><label>هر دقیقه مسیر</label><input type="number" min="0" name="per_minute" required value="<?=e((string)($pricing['per_minute'] ?? 0))?>"></div><div class="field"><label>هر دقیقه انتظار</label><input type="number" min="0" name="waiting_per_minute" required value="<?=e((string)($pricing['waiting_per_minute'] ?? 0))?>"></div><div class="field"><label>حداقل کرایه</label><input type="number" min="0" name="minimum_fare" required value="<?=e((string)($pricing['minimum_fare'] ?? 0))?>"></div><div class="field"><label>ضریب شلوغی</label><input type="number" min="1" max="5" step="0.05" name="surge_multiplier" required value="<?=e((string)($pricing['surge_multiplier'] ?? 1))?>"></div></div><br><button class="primary">ذخیره و فعال‌سازی تعرفه</button></form></div>
<div class="card"><h3>کمیسیون رانندگان</h3><p class="muted">این مقدار برای راننده جدید استفاده می‌شود؛ پایین‌تر برای هر راننده قابل تغییر است.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_default_commission"><div class="field"><label>کمیسیون پیش‌فرض RADO (%)</label><input type="number" min="0" max="100" step="0.1" name="default_driver_commission_rate" value="<?=e((string)$defaultCommission)?>"></div><br><button class="primary">ذخیره کمیسیون</button></form><hr style="border:0;border-top:1px solid #eee;margin:20px 0"><b>منطق تسویه</b><p class="muted">در پایان سفر: کرایه کامل به کیف پول راننده اضافه و سهم RADO به‌صورت کمیسیون کسر می‌شود؛ هر سفر با کلید یکتا فقط یک‌بار تسویه می‌شود.</p></div>
</div>
<div class="section card"><h3>رانندگان و کیف پول</h3><div class="tablewrap"><table class="table"><thead><tr><th>راننده</th><th>وضعیت</th><th>آنلاین</th><th>خودرو</th><th>کمیسیون</th><th>کیف پول</th><th>آخرین حضور</th><th>ویرایش</th></tr></thead><tbody><?php foreach($drivers as $d):?><tr><td><b><?=e((string)$d['full_name'])?></b></td><td><span class="badge"><?=e(driver_status_fa((string)$d['status']))?></span></td><td><span class="badge <?=((int)($d['is_online']??0)===1)?'online':''?>"><?=((int)($d['is_online']??0)===1)?'آنلاین':'آفلاین'?></span></td><td><?=e(trim((string)($d['vehicle_make']??'').' '.(string)($d['vehicle_model']??'')))?><br><span class="muted"><?=e((string)($d['plate_number']??''))?></span></td><td><?=e((string)$d['commission_rate'])?>٪</td><td><b><?=money($d['wallet_balance'])?></b></td><td class="jalali"><?=e(jdate($d['last_seen_at']??null))?></td><td><form class="driverform" method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_driver"><input type="hidden" name="driver_id" value="<?=e((string)$d['user_id'])?>"><input name="full_name" value="<?=e((string)$d['full_name'])?>" placeholder="نام"><select name="status"><option value="pending" <?=$d['status']==='pending'?'selected':''?>>در انتظار</option><option value="approved" <?=$d['status']==='approved'?'selected':''?>>تایید</option><option value="suspended" <?=$d['status']==='suspended'?'selected':''?>>تعلیق</option><option value="rejected" <?=$d['status']==='rejected'?'selected':''?>>رد</option></select><input name="commission_rate" type="number" min="0" max="100" step="0.1" value="<?=e((string)$d['commission_rate'])?>"><input name="plate_number" value="<?=e((string)($d['plate_number']??''))?>" placeholder="پلاک"><input name="vehicle_make" value="<?=e((string)($d['vehicle_make']??''))?>" placeholder="برند"><input name="vehicle_model" value="<?=e((string)($d['vehicle_model']??''))?>" placeholder="مدل"><input name="vehicle_color" value="<?=e((string)($d['vehicle_color']??''))?>" placeholder="رنگ"><button>ذخیره</button></form></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="section card"><h3>آخرین سفرها</h3><div class="tablewrap"><table class="table"><thead><tr><th>وضعیت</th><th>مسافر</th><th>راننده</th><th>مسیر</th><th>کرایه</th><th>درخواست</th><th>قبول</th><th>رسید</th><th>شروع</th><th>پایان</th></tr></thead><tbody><?php foreach($trips as $t):?><tr><td><span class="badge"><?=e(trip_status_fa((string)$t['status']))?></span></td><td><?=e((string)($t['passenger_name']??'مسافر'))?></td><td><?=e((string)($t['driver_name']??'—'))?></td><td><b><?=e((string)($t['pickup_label']??''))?></b><br><span class="muted">← <?=e((string)($t['destination_label']??''))?></span></td><td><?=money($t['final_fare']??$t['estimated_fare'])?></td><td class="jalali"><?=e(jdate($t['requested_at']??null))?></td><td class="jalali"><?=e(jdate($t['accepted_at']??null))?></td><td class="jalali"><?=e(jdate($t['arrived_at']??null))?></td><td class="jalali"><?=e(jdate($t['started_at']??null))?></td><td class="jalali"><?=e(jdate($t['completed_at']??null))?></td></tr><?php endforeach;?></tbody></table></div></div>
</div></body></html>
