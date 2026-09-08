<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$passengers=$pdo->query("SELECT u.id,u.full_name,u.phone,u.created_at,(SELECT COUNT(*) FROM trips t WHERE t.passenger_id=u.id) trip_count,(SELECT COALESCE(SUM(le.amount),0) FROM ledger_entries le WHERE le.user_id=u.id) wallet_balance,(SELECT MAX(t.requested_at) FROM trips t WHERE t.passenger_id=u.id) last_trip FROM users u WHERE u.role='passenger' ORDER BY u.created_at DESC LIMIT 300")->fetchAll();
$total=count($passengers);$withTrips=0;$walletTotal=0;foreach($passengers as $p){if((int)$p['trip_count']>0)$withTrips++;$walletTotal+=(int)$p['wallet_balance'];}
$openTickets=ra_table_exists($pdo,'support_tickets')?(int)ra_scalar($pdo,"SELECT COUNT(*) FROM support_tickets st JOIN users u ON u.id=st.user_id WHERE u.role='passenger' AND st.status<>'closed'"):0;
ra_header('مسافران','passengers','حساب‌ها، سفرها و کیف پول مسافر');
?>
<div class="grid"><div class="card span3 metric"><small>مسافران</small><b><?=$total?></b></div><div class="card span3 metric"><small>مسافر دارای سفر</small><b><?=$withTrips?></b></div><div class="card span3 metric"><small>مجموع کیف پول</small><b><?=ra_money($walletTotal)?></b></div><div class="card span3 metric"><small>تیکت باز</small><b><?=$openTickets?></b></div></div>
<div class="card" style="margin-top:14px"><div class="tablewrap"><table class="table"><tr><th>نام</th><th>موبایل</th><th>عضویت</th><th>تعداد سفر</th><th>آخرین سفر</th><th>کیف پول</th></tr><?php foreach($passengers as $p):?><tr><td><?=ra_e((string)($p['full_name']??'مسافر'))?></td><td><?=ra_e((string)($p['phone']??'—'))?></td><td><?=ra_e(ra_date($p['created_at']))?></td><td><?=(int)$p['trip_count']?></td><td><?=ra_e(ra_date($p['last_trip']??null))?></td><td><?=ra_money($p['wallet_balance'])?></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
