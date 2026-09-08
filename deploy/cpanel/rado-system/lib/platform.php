<?php
declare(strict_types=1);
if(!function_exists('rado_db')) require __DIR__.'/app.php';

function rado_platform_event(PDO $pdo,string $channel,string $type,array $payload,int $ttlSeconds=7200):void{
  try{$expires=(new DateTimeImmutable('+'.$ttlSeconds.' seconds',rado_tehran_timezone()))->format('Y-m-d H:i:s');$pdo->prepare('INSERT INTO realtime_events(channel,event_type,payload_json,expires_at) VALUES(?,?,?,?)')->execute([$channel,$type,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$expires]);}catch(Throwable){}
}
function rado_platform_notify(PDO $pdo,string $userId,string $title,string $body,string $type='general',array $data=[]):void{
  try{$pdo->prepare('INSERT INTO notifications(user_id,title,body,type,data_json) VALUES(?,?,?,?,?)')->execute([$userId,$title,$body,$type,$data?json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);}catch(Throwable){}
}
function rado_dispatch_settings(PDO $pdo):array{
  return [
    'r1'=>max(.2,(float)(rado_setting($pdo,'dispatch_radius_1_km','1.5')??'1.5')),
    'r2'=>max(.2,(float)(rado_setting($pdo,'dispatch_radius_2_km','3')??'3')),
    'r3'=>max(.2,(float)(rado_setting($pdo,'dispatch_radius_3_km','5')??'5')),
    'batch'=>max(1,min(20,(int)(rado_setting($pdo,'dispatch_batch_size','4')??'4'))),
    'timeout'=>max(10,min(180,(int)(rado_setting($pdo,'dispatch_offer_timeout_seconds','25')??'25'))),
    'destination_tolerance'=>max(1,(float)(rado_setting($pdo,'driver_destination_tolerance_km','5')??'5')),
  ];
}
function rado_dispatch_trip_v2(PDO $pdo,string $tripId,float $pickupLat,float $pickupLng):int{
  $tripStmt=$pdo->prepare('SELECT id,status,destination_lat,destination_lng,estimated_fare,requested_at FROM trips WHERE id=? LIMIT 1');$tripStmt->execute([$tripId]);$trip=$tripStmt->fetch();
  if(!is_array($trip)||!in_array((string)$trip['status'],['searching','requested'],true))return 0;
  $cfg=rado_dispatch_settings($pdo);$requested=rado_tehran_datetime((string)$trip['requested_at']);$age=max(0,time()-$requested->getTimestamp());$stage=$age<$cfg['timeout']?1:($age<$cfg['timeout']*2?2:3);$radius=[$cfg['r1'],$cfg['r2'],$cfg['r3']][$stage-1]*1000;
  $sql="SELECT d.user_id,p.latitude,p.longitude,dp.destination_lat pref_lat,dp.destination_lng pref_lng,dp.max_pickup_distance_km,dp.min_fare,dp.auto_accept,dp.auto_accept_radius_km,dp.auto_accept_min_fare FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id LEFT JOIN driver_preferences dp ON dp.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) AND NOT EXISTS(SELECT 1 FROM trip_offers o WHERE o.trip_id=? AND o.driver_id=d.user_id) LIMIT 200";
  $stmt=$pdo->prepare($sql);$stmt->execute([$tripId]);$c=[];$fare=(int)($trip['estimated_fare']??0);
  foreach($stmt->fetchAll() as $row){
    $pickupDistance=rado_distance_m($pickupLat,$pickupLng,(float)$row['latitude'],(float)$row['longitude']);$maxPickup=$radius;if($row['max_pickup_distance_km']!==null)$maxPickup=min($maxPickup,max(.2,(float)$row['max_pickup_distance_km'])*1000);if($pickupDistance>$maxPickup)continue;if($row['min_fare']!==null&&$fare<(int)$row['min_fare'])continue;
    if($row['pref_lat']!==null&&$row['pref_lng']!==null){$destDistance=rado_distance_m((float)$trip['destination_lat'],(float)$trip['destination_lng'],(float)$row['pref_lat'],(float)$row['pref_lng']);if($destDistance>$cfg['destination_tolerance']*1000)continue;$row['destination_match_m']=$destDistance;}else$row['destination_match_m']=null;
    $row['distance_m']=$pickupDistance;$c[]=$row;
  }
  usort($c,function(array $a,array $b):int{$ad=$a['destination_match_m'];$bd=$b['destination_match_m'];if($ad!==null&&$bd===null)return -1;if($ad===null&&$bd!==null)return 1;return $a['distance_m']<=>$b['distance_m'];});$c=array_slice($c,0,$cfg['batch']);
  $count=0;
  foreach($c as $x){$driverId=(string)$x['user_id'];$auto=(int)($x['auto_accept']??0)===1;$autoRadius=max(.2,(float)($x['auto_accept_radius_km']??0))*1000;$autoMin=(int)($x['auto_accept_min_fare']??0);
    if($auto&&$x['distance_m']<=$autoRadius&&$fare>=$autoMin){
      $pdo->beginTransaction();try{$lock=$pdo->prepare("SELECT status FROM trips WHERE id=? FOR UPDATE");$lock->execute([$tripId]);$status=(string)$lock->fetchColumn();if($status==='searching'||$status==='requested'){$upd=$pdo->prepare("UPDATE trips SET driver_id=?,status='driver_assigned',accepted_at=NOW(),version=version+1 WHERE id=? AND status IN('searching','requested') AND driver_id IS NULL");$upd->execute([$driverId,$tripId]);if($upd->rowCount()===1){$pdo->prepare('INSERT INTO trip_offers(trip_id,driver_id,offered_at,expires_at,responded_at,accepted) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? SECOND),NOW(),1) ON DUPLICATE KEY UPDATE responded_at=NOW(),accepted=1')->execute([$tripId,$driverId,$cfg['timeout']]);$pdo->commit();rado_platform_notify($pdo,$driverId,'سفر خودکار پذیرفته شد','یک سفر مطابق تنظیمات پذیرش خودکار برای شما ثبت شد.','trip_assigned',['trip_id'=>$tripId]);rado_platform_event($pdo,'trip:'.$tripId,'driver_assigned',['driver_id'=>$driverId,'auto'=>true]);return 1;}}$pdo->rollBack();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();}
    }
    $ins=$pdo->prepare('INSERT IGNORE INTO trip_offers(trip_id,driver_id,offered_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? SECOND))');$ins->execute([$tripId,$driverId,$cfg['timeout']]);if($ins->rowCount()>0){$count++;rado_platform_notify($pdo,$driverId,'درخواست سفر جدید','یک درخواست سفر نزدیک شما موجود است.','trip_offer',['trip_id'=>$tripId]);rado_platform_event($pdo,'driver:'.$driverId,'trip_offer',['trip_id'=>$tripId,'expires_seconds'=>$cfg['timeout']]);}
  }
  rado_platform_event($pdo,'trip:'.$tripId,'dispatch',['stage'=>$stage,'radius_m'=>$radius,'drivers_notified'=>$count]);return $count;
}
function rado_offer_waiting_trip_to_driver_v2(PDO $pdo,string $driverId,float $lat,float $lng):void{
  $stmt=$pdo->query("SELECT id,pickup_lat,pickup_lng FROM trips WHERE status='searching' AND requested_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) ORDER BY requested_at ASC LIMIT 50");
  foreach($stmt->fetchAll() as $row){$d=rado_distance_m($lat,$lng,(float)$row['pickup_lat'],(float)$row['pickup_lng']);if($d<=((float)(rado_setting($pdo,'dispatch_radius_3_km','5')??'5'))*1000){rado_dispatch_trip_v2($pdo,(string)$row['id'],(float)$row['pickup_lat'],(float)$row['pickup_lng']);break;}}
}
function rado_sync_wallet_cache(PDO $pdo,string $userId):int{$balance=rado_wallet_balance($pdo,$userId);try{$pdo->prepare('INSERT INTO user_wallets(user_id,balance) VALUES(?,?) ON DUPLICATE KEY UPDATE balance=VALUES(balance)')->execute([$userId,$balance]);}catch(Throwable){}return $balance;}
