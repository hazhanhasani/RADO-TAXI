<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  ra_require_csrf();
  try{
    $action=(string)($_POST['action']??'');$id=(int)($_POST['id']??0);
    if($id<1)throw new RuntimeException('تیکت نامعتبر است.');
    if($action==='reply'){
      $text=mb_substr(trim((string)($_POST['message']??'')),0,2000,'UTF-8');if($text==='')throw new RuntimeException('متن پاسخ خالی است.');
      $pdo->prepare("INSERT INTO support_messages(ticket_id,sender_user_id,sender_role,message) VALUES(?,?,'admin',?)")->execute([$id,(string)$_SESSION['admin_id'],$text]);
      $pdo->prepare("UPDATE support_tickets SET status='waiting_user',updated_at=NOW() WHERE id=?")->execute([$id]);$message='پاسخ ارسال شد.';
    }elseif($action==='close'){
      $pdo->prepare("UPDATE support_tickets SET status='closed',updated_at=NOW() WHERE id=?")->execute([$id]);$message='تیکت بسته شد.';
    }
  }catch(Throwable $e){$error=$e->getMessage();}
}
$tickets=[];$counts=['open'=>0,'urgent'=>0,'waiting'=>0,'closed'=>0];
if(ra_table_exists($pdo,'support_tickets')){
 $tickets=$pdo->query("SELECT st.id,st.subject,st.category,st.status,st.priority,st.created_at,st.updated_at,u.full_name,u.role,st.trip_id,(SELECT sm.message FROM support_messages sm WHERE sm.ticket_id=st.id ORDER BY sm.id DESC LIMIT 1) last_message FROM support_tickets st JOIN users u ON u.id=st.user_id ORDER BY FIELD(st.priority,'urgent','high','normal','low'),st.updated_at DESC LIMIT 250")->fetchAll();
 $counts['open']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status<>'closed'");
 $counts['urgent']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE priority='urgent' AND status<>'closed'");
 $counts['waiting']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status IN ('waiting_admin','waiting_user')");
 $counts['closed']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status='closed'");
}
ra_header('پشتیبانی','support','تیکت‌های مسافر و راننده');
if($message)echo '<div class="notice ok">'.ra_e($message).'</div>';if($error)echo '<div class="notice err">'.ra_e($error).'</div>';
?>
<div class="grid"><div class="card span3 metric"><small>باز</small><b><?=$counts['open']?></b></div><div class="card span3 metric"><small>فوری</small><b><?=$counts['urgent']?></b></div><div class="card span3 metric"><small>در انتظار پاسخ</small><b><?=$counts['waiting']?></b></div><div class="card span3 metric"><small>بسته‌شده</small><b><?=$counts['closed']?></b></div></div>
<div class="card" style="margin-top:14px"><div class="tablewrap"><table class="table"><tr><th>شماره</th><th>کاربر</th><th>موضوع</th><th>آخرین پیام</th><th>اولویت</th><th>وضعیت</th><th>آخرین تغییر</th><th>عملیات</th></tr><?php foreach($tickets as $t):?><tr><td>#<?=(int)$t['id']?></td><td><?=ra_e((string)$t['full_name'])?> <span class="badge"><?=ra_e((string)$t['role'])?></span></td><td><b><?=ra_e((string)$t['subject'])?></b><br><span class="muted"><?=ra_e((string)$t['category'])?><?=!empty($t['trip_id'])?' · سفر '.ra_e((string)$t['trip_id']):''?></span></td><td style="max-width:280px;white-space:normal"><?=ra_e(mb_substr((string)($t['last_message']??'—'),0,180,'UTF-8'))?></td><td><span class="badge <?=$t['priority']==='urgent'?'red':($t['priority']==='high'?'yellow':'')?>"><?=ra_e((string)$t['priority'])?></span></td><td><?=ra_e((string)$t['status'])?></td><td><?=ra_e(ra_date($t['updated_at']))?></td><td><?php if($t['status']!=='closed'):?><form method="post" style="display:flex;gap:5px;min-width:310px"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><input name="message" placeholder="پاسخ مدیریت"><button class="btn goldbtn" name="action" value="reply">پاسخ</button><button class="btn" name="action" value="close">بستن</button></form><?php else:?><span class="badge green">بسته</span><?php endif;?></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
