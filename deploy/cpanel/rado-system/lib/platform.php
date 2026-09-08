<?php
declare(strict_types=1);
if(!function_exists('rado_db')) require __DIR__.'/app.php';

function rado_platform_event(PDO $pdo,string $channel,string $type,array $payload,int $ttlSeconds=7200):void{
  try{$expires=(new DateTimeImmutable('+'.$ttlSeconds.' seconds',rado_tehran_timezone()))->format('Y-m-d H:i:s');$pdo->prepare('INSERT INTO realtime_events(channel,event_type,payload_json,expires_at) VALUES(?,?,?,?)')->execute([$channel,$type,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$expires]);}catch(Throwable){}
}
function rado_platform_notify(PDO $pdo,string $userId,string $title,string $body,string $type='general',array $data=[]):void{
  try{$pdo->prepare('INSERT INTO notifications(user_id,title,body,type,data_json) VALUES(?,?,?,?,?)')->execute([$userId,$title,$body,$type,$data?json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);}catch(Throwable){}
  // Wake a driver's private realtime stream for server-side assignments. Publishing
  // to a driver:* channel is harmless for passenger IDs because nobody can subscribe
  // to that channel without a valid driver identity.
  if($type==='trip_assigned'){rado_platform_event($pdo,'driver:'.$userId,'trip_assigned',$data,600);}
}
function rado_dispatch_settings(PDO $pdo):array{
  return [
    'r1'=>max(.2,(float)(rado_setting($pdo,'dispatch_radius_1_km','1.5')??'1.5')),
    'r2'=>max(.2,(float)(rado_setting($pdo,'dispatch_radius_2_km','3')??'3')),
    'r3'=>max(.2,(float)(rado_setting($pdo,'dispatch_radius_3_km','5')??'5')),
    'batch'=>max(1,min(20,(int)(rado_setting($pdo,'dispatch_batch_size','4')??'4'))),
    // Mobile polling must have enough time to survive a slow GPS/network cycle.
    'timeout'=>max(45,min(180,(int)(rado_setting($pdo,'dispatch_offer_timeout_seconds','45')??'45'))),
    'destination_tolerance'=>max(1,(float)(rado_setting($pdo,'driver_destination_tolerance_km','5')??'5')),
    'presence_fresh_seconds'=>max(60,min(600,(int)(rado_setting($pdo,'dispatch_presence_fresh_seconds','180')??'180'))),
  ];
}
function rado_dispatch_log(string $tripId,array $data):void{
  try{$dir=rado_root().'/rado-system/state';@mkdir($dir,0755,true);$line='['.rado_jalali_datetime(null,true).'] '.$tripId.' '.json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";@file_put_contents($dir.'/dispatch.log',$line,FILE_APPEND|LOCK_EX);}catch(Throwable){}
}
function rado_dispatch_candidates(PDO $pdo,string $tripId,array $trip,float $pickupLat,float $pickupLng,array $cfg,float $radius):array{
  $fresh=(int)$cfg['presence_fresh_seconds'];
  $sql="SELECT d.user_id,p.latitude,p.longitude,dp.destination_lat pref_lat,dp.destination_lng pref_lng,dp.max_pickup_distance_km,dp.min_fare,dp.auto_accept,dp.auto_accept_radius_km,dp.auto_accept_min_fare
        FROM drivers d
        JOIN users u ON u.id=d.user_id AND u.is_active=1
        JOIN driver_presence p ON p.driver_id=d.user_id
        LEFT JOIN driver_preferences dp ON dp.driver_id=d.user_id
        WHERE d.status='approved'
          AND p.is_online=1
          AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
          AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL {$fresh} SECOND)
          AND NOT EXISTS(SELECT 1 FROM trips busy WHERE busy.driver_id=d.user_id AND busy.status IN('driver_assigned','driver_arriving','arrived','in_progress'))
        LIMIT 200";
  $rows=$pdo->query($sql)->fetchAll();$out=[];$fare=(int)($trip['estimated_fare']??0);$stats=['seen'=>count($rows),'too_far'=>0,'min_fare'=>0,'destination'=>0];
  foreach($rows as $row){
    $pickupDistance=rado_distance_m($pickupLat,$pickupLng,(float)$row['latitude'],(float)$row['longitude']);
    $maxPickup=$radius;
    if($row['max_pickup_distance_km']!==null)$maxPickup=min($maxPickup,max(.2,(float)$row['max_pickup_distance_km'])*1000);
    if($pickupDistance>$maxPickup){$stats['too_far']++;continue;}
    if($row['min_fare']!==null&&$fare<(int)$row['min_fare']){$stats['min_fare']++;continue;}
    if($row['pref_lat']!==null&&$row['pref_lng']!==null){
      $destDistance=rado_distance_m((float)$trip['destination_lat'],(float)$trip['destination_lng'],(float)$row['pref_lat'],(float)$row['pref_lng']);
      if($destDistance>$cfg['destination_tolerance']*1000){$stats['destination']++;continue;}
      $row['destination_match_m']=$destDistance;
    }else{$row['destination_match_m']=null;}
    $row['distance_m']=$pickupDistance;$out[]=$row;
  }
  usort($out,function(array $a,array $b):int{$ad=$a['destination_match_m'];$bd=$b['destination_match_m'];if($ad!==null&&$bd===null)return -1;if($ad===null&&$bd!==null)return 1;return $a['distance_m']<=>$b['distance_m'];});
  return ['items'=>$out,'stats'=>$stats];
}
function rado_dispatch_trip_v2(PDO $pdo,string $tripId,float $pickupLat,float $pickupLng):int{
  $tripStmt=$pdo->prepare('SELECT id,status,passenger_id,destination_lat,destination_lng,estimated_fare,requested_at FROM trips WHERE id=? LIMIT 1');$tripStmt->execute([$tripId]);$trip=$tripStmt->fetch();
  if(!is_array($trip)||!in_array((string)$trip['status'],['searching','requested'],true))return 0;
  $cfg=rado_dispatch_settings($pdo);

  // Try the configured radii immediately, smallest to largest. This avoids making a passenger
  // wait through several cron/poll cycles when the nearest driver is just outside stage 1.
  $radii=array_values(array_unique([(float)$cfg['r1']*1000,(float)$cfg['r2']*1000,(float)$cfg['r3']*1000]));sort($radii,SORT_NUMERIC);
  $chosen=[];$diag=[];$usedRadius=0.0;
  foreach($radii as $radius){$result=rado_dispatch_candidates($pdo,$tripId,$trip,$pickupLat,$pickupLng,$cfg,$radius);$diag[]=['radius_m'=>(int)$radius]+$result['stats'];if($result['items']!==[]){$chosen=$result['items'];$usedRadius=$radius;break;}}
  $chosen=array_slice($chosen,0,(int)$cfg['batch']);$fare=(int)($trip['estimated_fare']??0);$count=0;

  foreach($chosen as $x){$driverId=(string)$x['user_id'];$auto=(int)($x['auto_accept']??0)===1;$autoRadius=max(.2,(float)($x['auto_accept_radius_km']??0))*1000;$autoMin=(int)($x['auto_accept_min_fare']??0);
    if($auto&&$x['distance_m']<=$autoRadius&&$fare>=$autoMin){
      $pdo->beginTransaction();try{$lock=$pdo->prepare("SELECT status FROM trips WHERE id=? FOR UPDATE");$lock->execute([$tripId]);$status=(string)$lock->fetchColumn();if($status==='searching'||$status==='requested'){$upd=$pdo->prepare("UPDATE trips SET driver_id=?,status='driver_assigned',accepted_at=NOW(),version=version+1 WHERE id=? AND status IN('searching','requested') AND driver_id IS NULL");$upd->execute([$driverId,$tripId]);if($upd->rowCount()===1){$pdo->prepare('INSERT INTO trip_offers(trip_id,driver_id,offered_at,expires_at,responded_at,accepted) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? SECOND),NOW(),1) ON DUPLICATE KEY UPDATE offered_at=NOW(),expires_at=VALUES(expires_at),responded_at=NOW(),accepted=1')->execute([$tripId,$driverId,$cfg['timeout']]);$pdo->commit();rado_platform_notify($pdo,$driverId,'سفر خودکار پذیرفته شد','یک سفر مطابق تنظیمات پذیرش خودکار برای شما ثبت شد.','trip_assigned',['trip_id'=>$tripId]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'راننده سفر را پذیرفت','راننده RADO در مسیر مبدا است.','driver_assigned',['trip_id'=>$tripId,'driver_id'=>$driverId]);rado_platform_event($pdo,'trip:'.$tripId,'driver_assigned',['driver_id'=>$driverId,'auto'=>true]);rado_dispatch_log($tripId,['auto_accept'=>$driverId,'radius_m'=>(int)$usedRadius,'diag'=>$diag]);return 1;}}$pdo->rollBack();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();}
    }

    // Reuse an expired unanswered offer instead of permanently blacklisting the driver for this trip.
    $existing=$pdo->prepare('SELECT id,responded_at,accepted,expires_at FROM trip_offers WHERE trip_id=? AND driver_id=? LIMIT 1');$existing->execute([$tripId,$driverId]);$old=$existing->fetch();
    if(is_array($old)){
      if($old['responded_at']!==null||$old['accepted']!==null)continue;
      if(strtotime((string)$old['expires_at'])<=time()){$renew=$pdo->prepare('UPDATE trip_offers SET offered_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=? AND responded_at IS NULL AND accepted IS NULL');$renew->execute([$cfg['timeout'],(int)$old['id']]);if($renew->rowCount()>0)$count++;}
      else{$count++;}
    }else{
      $ins=$pdo->prepare('INSERT INTO trip_offers(trip_id,driver_id,offered_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? SECOND))');$ins->execute([$tripId,$driverId,$cfg['timeout']]);if($ins->rowCount()>0)$count++;
    }
    if($count>0){rado_platform_notify($pdo,$driverId,'درخواست سفر جدید','یک درخواست سفر نزدیک شما موجود است.','trip_offer',['trip_id'=>$tripId]);rado_platform_event($pdo,'driver:'.$driverId,'trip_offer',['trip_id'=>$tripId,'expires_seconds'=>$cfg['timeout']]);}
  }
  $payload=['radius_m'=>(int)$usedRadius,'drivers_notified'=>$count,'diag'=>$diag];rado_platform_event($pdo,'trip:'.$tripId,'dispatch',$payload);rado_dispatch_log($tripId,$payload);return $count;
}
function rado_reoffer_expired_to_driver_v2(PDO $pdo,string $driverId):void{
  $cfg=rado_dispatch_settings($pdo);
  $stmt=$pdo->prepare("SELECT o.id FROM trip_offers o JOIN trips t ON t.id=o.trip_id WHERE o.driver_id=? AND o.responded_at IS NULL AND o.accepted IS NULL AND o.expires_at<=NOW() AND t.status='searching' AND t.requested_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) ORDER BY t.requested_at ASC LIMIT 1");
  $stmt->execute([$driverId]);$id=$stmt->fetchColumn();if($id!==false)$pdo->prepare('UPDATE trip_offers SET offered_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?')->execute([$cfg['timeout'],(int)$id]);
}
function rado_offer_waiting_trip_to_driver_v2(PDO $pdo,string $driverId,float $lat,float $lng):void{
  rado_reoffer_expired_to_driver_v2($pdo,$driverId);
  $stmt=$pdo->query("SELECT id,pickup_lat,pickup_lng FROM trips WHERE status='searching' AND requested_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) ORDER BY requested_at ASC LIMIT 50");
  foreach($stmt->fetchAll() as $row){rado_dispatch_trip_v2($pdo,(string)$row['id'],(float)$row['pickup_lat'],(float)$row['pickup_lng']);$check=$pdo->prepare('SELECT COUNT(*) FROM trip_offers WHERE trip_id=? AND driver_id=? AND responded_at IS NULL AND accepted IS NULL AND expires_at>NOW()');$check->execute([(string)$row['id'],$driverId]);if((int)$check->fetchColumn()>0)break;}
}
function rado_sync_wallet_cache(PDO $pdo,string $userId):int{$balance=rado_wallet_balance($pdo,$userId);try{$pdo->prepare('INSERT INTO user_wallets(user_id,balance) VALUES(?,?) ON DUPLICATE KEY UPDATE balance=VALUES(balance)')->execute([$userId,$balance]);}catch(Throwable){}return $balance;}
