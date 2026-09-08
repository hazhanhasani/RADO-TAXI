<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/rado-system/lib/app.php';

if($_SERVER['REQUEST_METHOD']!=='GET')rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);
try{
 $pdo=rado_db();
 $clientId=trim((string)($_GET['client_id']??''));
 $tripId=trim((string)($_GET['trip_id']??''));
 $role=(string)($_GET['role']??'passenger');
 if($clientId===''||$tripId==='')rado_json(422,['ok'=>false,'error'=>'trip_required']);

 if($role==='driver'){
   $driver=rado_driver_from_client($pdo,$clientId);$userId=$driver['id']??null;
   $check=$pdo->prepare('SELECT * FROM trips WHERE id=? AND driver_id=? LIMIT 1');
 }else{
   $userId=rado_passenger_from_client($pdo,$clientId);
   $check=$pdo->prepare('SELECT * FROM trips WHERE id=? AND passenger_id=? LIMIT 1');
 }
 if(!$userId)rado_json(403,['ok'=>false,'error'=>'unauthorized']);
 $check->execute([$tripId,$userId]);$trip=$check->fetch();
 if(!is_array($trip))rado_json(404,['ok'=>false,'error'=>'trip_not_found']);

 $driverPosition=null;$driverProfile=null;$eta=null;
 if(!empty($trip['driver_id'])){
   $driverId=(string)$trip['driver_id'];
   $profile=$pdo->prepare("SELECT u.full_name,d.plate_number,d.vehicle_make,d.vehicle_model,d.vehicle_color,
     (SELECT AVG(re.score) FROM ratings_extended re JOIN trips rt ON rt.id=re.trip_id WHERE re.target_role='driver' AND rt.driver_id=?) driver_rating,
     (SELECT COUNT(*) FROM ratings_extended re JOIN trips rt ON rt.id=re.trip_id WHERE re.target_role='driver' AND rt.driver_id=?) driver_rating_count
     FROM users u JOIN drivers d ON d.user_id=u.id WHERE u.id=? LIMIT 1");
   $profile->execute([$driverId,$driverId,$driverId]);$pr=$profile->fetch();
   if(is_array($pr)){
     $driverProfile=[
       'name'=>(string)($pr['full_name']??'راننده RADO'),
       'plate'=>(string)($pr['plate_number']??''),
       'vehicle'=>trim((string)($pr['vehicle_make']??'').' '.(string)($pr['vehicle_model']??'')),
       'color'=>(string)($pr['vehicle_color']??''),
       'rating'=>$pr['driver_rating']===null?null:round((float)$pr['driver_rating'],1),
       'rating_count'=>(int)($pr['driver_rating_count']??0),
     ];
   }

   $p=$pdo->prepare('SELECT latitude,longitude,heading,speed_kph,last_seen_at FROM driver_presence WHERE driver_id=? AND latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1');
   $p->execute([$driverId]);$pos=$p->fetch();
   if(is_array($pos)&&!empty($pos['last_seen_at'])){
     $fresh=strtotime((string)$pos['last_seen_at'])>=time()-180;
     if($fresh){
       $approaching=in_array($trip['status'],['driver_assigned','driver_arriving','arrived'],true);
       $targetLat=$approaching?(float)$trip['pickup_lat']:(float)$trip['destination_lat'];
       $targetLng=$approaching?(float)$trip['pickup_lng']:(float)$trip['destination_lng'];
       $meters=rado_distance_m((float)$pos['latitude'],(float)$pos['longitude'],$targetLat,$targetLng);
       $speed=max(12,(float)($pos['speed_kph']??0));if($speed<12)$speed=25;
       $minutes=max(1,(int)ceil(($meters/1000)/$speed*60));
       $driverPosition=[
         'lat'=>(float)$pos['latitude'],'lng'=>(float)$pos['longitude'],
         'heading'=>$pos['heading']===null?null:(int)$pos['heading'],
         'speed_kph'=>$pos['speed_kph']===null?null:(float)$pos['speed_kph'],
         'last_seen_at'=>rado_time_payload((string)$pos['last_seen_at'])
       ];
       $eta=['distance_meters'=>(int)round($meters),'minutes'=>$minutes,'target'=>$approaching?'pickup':'destination'];
     }
   }
 }

 $since=max(0,(int)($_GET['since_event_id']??0));
 $ev=$pdo->prepare('SELECT id,event_type,payload_json,created_at FROM realtime_events WHERE channel=? AND id>? AND expires_at>NOW() ORDER BY id ASC LIMIT 100');
 $ev->execute(['trip:'.$tripId,$since]);$events=$ev->fetchAll();
 foreach($events as &$x){$x['payload']=json_decode((string)$x['payload_json'],true)?:[];$x['created_at_jalali']=rado_jalali_datetime((string)$x['created_at']);unset($x['payload_json'],$x['created_at']);}

 rado_json(200,[
   'ok'=>true,'trip_id'=>$tripId,'status'=>(string)$trip['status'],
   'driver_position'=>$driverPosition,'driver_profile'=>$driverProfile,'eta'=>$eta,
   'events'=>$events,'server_time'=>rado_time_payload()
 ]);
}catch(Throwable $e){
 $id=substr(bin2hex(random_bytes(8)),0,12);
 try{$dir=rado_root().'/rado-system/state';@mkdir($dir,0755,true);@file_put_contents($dir.'/realtime-errors.log','['.rado_jalali_datetime(null,true).'] '.$id.' '.$e->getMessage()."\n",FILE_APPEND|LOCK_EX);}catch(Throwable){}
 rado_json(500,['ok'=>false,'error'=>'realtime_failed','request_id'=>$id]);
}
