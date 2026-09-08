<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$tickets=[];$counts=['open'=>0,'urgent'=>0,'waiting'=>0,'closed'=>0];
if(ra_table_exists($pdo,'support_tickets')){
 $tickets=$pdo->query("SELECT st.id,st.subject,st.category,st.status,st.priority,st.created_at,st.updated_at,u.full_name,u.role,st.trip_id FROM support_tickets st JOIN users u ON u.id=st.user_id ORDER BY FIELD(st.priority,'urgent','high','normal','low'),st.updated_at DESC LIMIT 250")->fetchAll();
 $counts['open']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status<>'closed'");
 $counts['urgent']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE priority='urgent' AND status<>'closed'");
 $counts['waiting']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status IN ('waiting_admin','waiting_user')");
 $counts['closed']=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status='closed'");
}
ra_header('پشتیبانی','support','تیکت‌های مسافر و راننده');
?>
<div class="grid"><div class="card span3 metric"><small>باز</small><b><?=$counts['open']?></b></div><div class="card span3 metric"><small>فوری</small><b><?=$counts['urgent']?></b></div><div class="card span3 metric"><small>در انتظار پاسخ</small><b><?=$counts['waiting']?></b></div><div class="card span3 metric"><small>بسته‌شده</small><b><?=$counts['closed']?></b></div></div>
<div class="card" style="margin-top:14px"><div class="tablewrap"><table class="table"><tr><th>شماره</th><th>کاربر</th><th>موضوع</th><th>دسته</th><th>اولویت</th><th>وضعیت</th><th>آخرین تغییر</th><th>سفر</th></tr><?php foreach($tickets as $t):?><tr><td>#<?=(int)$t['id']?></td><td><?=ra_e((string)$t['full_name'])?> <span class="badge"><?=ra_e((string)$t['role'])?></span></td><td><?=ra_e((string)$t['subject'])?></td><td><?=ra_e((string)$t['category'])?></td><td><span class="badge <?=$t['priority']==='urgent'?'red':($t['priority']==='high'?'yellow':'')?>"><?=ra_e((string)$t['priority'])?></span></td><td><?=ra_e((string)$t['status'])?></td><td><?=ra_e(ra_date($t['updated_at']))?></td><td><?=ra_e((string)($t['trip_id']??'—'))?></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
