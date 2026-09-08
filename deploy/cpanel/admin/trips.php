<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$status=trim((string)($_GET['status']??''));
$allowed=['','searching','active','completed','cancelled']; if(!in_array($status,$allowed,true))$status='';
$where='1=1';$params=[];
if($status==='searching')$where="t.status IN ('requested','searching')";
elseif($status==='active')$where="t.status IN ('driver_assigned','driver_arriving','arrived','in_progress')";
elseif($status==='completed')$where="t.status='completed'";
elseif($status==='cancelled')$where="t.status LIKE 'cancelled%'";
$q=$pdo->prepare("SELECT t.id,t.status,t.pickup_label,t.destination_label,t.estimated_fare,t.final_fare,t.requested_at,t.accepted_at,t.completed_at,pu.full_name passenger_name,du.full_name driver_name FROM trips t LEFT JOIN users pu ON pu.id=t.passenger_id LEFT JOIN users du ON du.id=t.driver_id WHERE $where ORDER BY t.requested_at DESC LIMIT 250");$q->execute($params);$trips=$q->fetchAll();
$counts=[
 'searching'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('requested','searching')"),
 'active'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN ('driver_assigned','driver_arriving','arrived','in_progress')"),
 'completed'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status='completed'"),
 'cancelled'=>(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status LIKE 'cancelled%'")
];
ra_header('سفرها','trips','مدیریت چرخه کامل سفر');
?>
<div class="grid"><div class="card span3 metric"><small>در جستجوی راننده</small><b><?=$counts['searching']?></b></div><div class="card span3 metric"><small>فعال</small><b><?=$counts['active']?></b></div><div class="card span3 metric"><small>تکمیل‌شده</small><b><?=$counts['completed']?></b></div><div class="card span3 metric"><small>لغوشده</small><b><?=$counts['cancelled']?></b></div></div>
<div class="card" style="margin-top:14px"><div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px"><a class="btn <?=$status===''?'goldbtn':''?>" href="?">همه</a><a class="btn <?=$status==='searching'?'goldbtn':''?>" href="?status=searching">جستجو</a><a class="btn <?=$status==='active'?'goldbtn':''?>" href="?status=active">فعال</a><a class="btn <?=$status==='completed'?'goldbtn':''?>" href="?status=completed">تکمیل</a><a class="btn <?=$status==='cancelled'?'goldbtn':''?>" href="?status=cancelled">لغو</a></div><div class="tablewrap"><table class="table"><tr><th>زمان درخواست</th><th>مسافر</th><th>مبدا</th><th>مقصد</th><th>راننده</th><th>وضعیت</th><th>کرایه</th></tr><?php foreach($trips as $t):?><tr><td><?=ra_e(ra_date($t['requested_at']))?></td><td><?=ra_e((string)($t['passenger_name']??'مسافر'))?></td><td><?=ra_e((string)$t['pickup_label'])?></td><td><?=ra_e((string)$t['destination_label'])?></td><td><?=ra_e((string)($t['driver_name']??'—'))?></td><td><span class="badge"><?=ra_e(rado_trip_status_fa((string)$t['status']))?></span></td><td><?=ra_money($t['final_fare']??$t['estimated_fare'])?></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
