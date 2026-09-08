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

function ae(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }
function acsrf(): string { if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return (string)$_SESSION['csrf']; }
$error='';
$pdo=rado_db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'');
    if($action==='logout'){
        if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(419);exit('درخواست نامعتبر است.');}
        $_SESSION=[];session_destroy();header('Location: /admin/');exit;
    }
    if($action==='login'){
        $phone=trim((string)($_POST['phone']??''));$password=(string)($_POST['password']??'');
        $stmt=$pdo->prepare('SELECT id,full_name,password_hash FROM admin_users WHERE phone=? AND is_active=1 LIMIT 1');$stmt->execute([$phone]);$admin=$stmt->fetch();
        if(is_array($admin)&&password_verify($password,(string)$admin['password_hash'])){
            session_regenerate_id(true);$_SESSION['admin_id']=(string)$admin['id'];$_SESSION['admin_name']=(string)$admin['full_name'];$_SESSION['csrf']=bin2hex(random_bytes(24));header('Location: /admin/dashboard.php');exit;
        }
        $error='شماره موبایل یا رمز عبور اشتباه است.';
    }
}
if(!empty($_SESSION['admin_id'])){header('Location: /admin/dashboard.php');exit;}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود مدیریت RADO</title><style>*{box-sizing:border-box}body{margin:0;background:#111;font-family:Tahoma,Arial;color:#171717}.wrap{min-height:100vh;display:grid;place-items:center;padding:18px;background:radial-gradient(circle at 70% 15%,#353015 0,#111 36%)}.box{width:min(440px,100%);background:#fff;border-radius:30px;padding:30px;box-shadow:0 30px 80px #0008}.logo{font-size:46px;font-weight:900;letter-spacing:2px}.gold{color:#f5b400}h1{font-size:22px;margin:8px 0 4px}.muted{color:#777;font-size:11px;line-height:1.8}.tag{display:inline-block;background:#fff4c9;color:#6c5200;padding:5px 9px;border-radius:99px;margin:8px 0 20px}label{display:block;font-size:12px;font-weight:900;margin:10px 0 5px}input{width:100%;padding:14px;border:1px solid #ddd;border-radius:13px;font-size:15px}button{width:100%;border:0;border-radius:14px;background:#171717;color:#fff;padding:14px;font-weight:900;font-size:15px;margin-top:18px}.err{background:#fff0f0;color:#a11;padding:12px;border-radius:12px;margin:10px 0}</style></head><body><div class="wrap"><form class="box" method="post"><div class="logo">R<span class="gold">A</span>DO</div><h1>پنل مدیریت</h1><div class="muted">مرکز عملیات تاکسی اینترنتی بانه</div><span class="tag"><?=ae(rado_jalali_long())?> · Asia/Tehran</span><?php if($error):?><div class="err"><?=ae($error)?></div><?php endif;?><input type="hidden" name="action" value="login"><label>شماره موبایل مدیر</label><input name="phone" inputmode="tel" autocomplete="username" required autofocus><label>رمز عبور</label><input name="password" type="password" autocomplete="current-password" required><button>ورود به داشبورد RADO</button></form></div></body></html>
