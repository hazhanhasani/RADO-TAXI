<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/rado-system/lib/app.php';

if($_SERVER['REQUEST_METHOD']!=='GET')rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);

function rado_realtime_sse_send(string $event,array $payload,?int $id=null):void{
  if($id!==null)echo 'id: '.$id."\n";
  echo 'event: '.$event."\n";
  echo 'data: '.json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
  @flush();
}

function rado_realtime_stream(PDO $pdo,string $channel,int $lastId):never{
  @set_time_limit(30);
  @ini_set('zlib.output_compression','0');
  @ini_set('output_buffering','0');
  while(ob_get_level()>0){@ob_end_flush();}
  header('Content-Type: text/event-stream; charset=utf-8');
  header('Cache-Control: no-cache, no-store, must-revalidate');
  header('Pragma: no-cache');
  header('X-Accel-Buffering: no');
  header('Connection: keep-alive');

  if($lastId===0){
    $mx=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM realtime_events WHERE channel=?');
    $mx->execute([$channel]);
    $lastId=(int)$mx->fetchColumn();
  }

  rado_realtime_sse_send('ready',['ok'=>true,'channel'=>$channel,'last_event_id'=>$lastId,'server_time'=>rado_time_payload()]);
  $stmt=$pdo->prepare('SELECT id,event_type,payload_json,created_at FROM realtime_events WHERE channel=? AND id>? AND expires_at>NOW() ORDER BY id ASC LIMIT 100');
  $started=microtime(true);$heartbeatAt=0.0;
  while(!connection_aborted()&&microtime(true)-$started<24.0){
    $stmt->execute([$channel,$lastId]);
    foreach($stmt->fetchAll() as $row){
      $id=(int)$row['id'];$payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))$payload=[];
      rado_realtime_sse_send('rado_event',['id'=>$id,'event_type'=>(string)$row['event_type'],'payload'=>$payload,'created_at'=>rado_time_payload((string)$row['created_at'])],$id);
      $lastId=$id;
    }
    $now=microtime(true);
    if($now-$heartbeatAt>=8.0){rado_realtime_sse_send('heartbeat',['last_event_id'=>$lastId,'server_time'=>rado_time_payload()]);$heartbeatAt=$now;}
    usleep(500000);
  }
  rado_realtime_sse_send('reconnect',['last_event_id'=>$lastId]);
  exit;
}

try{
 $pdo=rado_db();
 $clientId=trim((string)($_GET['client_id']??''));
 $tripId=trim((string)($_GET['trip_id']??''));
 $role=(string)($_GET['role']??'passenger');
 $scope=(string)($_GET['scope']??'trip');
 if($clientId==='')rado_json(422,['ok'=>false,'error'=>'client_id_required']);

 $trip=null;$userId=null;$channel='';
 if($role==='driver'){
   $driver=rado_driver_from_client($pdo,$clientId);$userId=$driver['id']??null;
   if(!$userId)rado_json(403,['ok'=>false,'error'=>'unauthorized']);
   if($scope==='driver'&&$tripId===''){
     $channel='driver:'.$userId;
   }else{
     if($tripId==='')rado_json(422,['ok'=>false,'error'=>'trip_required']);
     $check=$pdo->prepare('SELECT * FROM trips WHERE id=? AND driver_id=? LIMIT 1');$check->execute([$tripId,$userId]);$trip=$check->fetch();
     if(!is_array($trip))rado_json(404,['ok'=>false,'error'=>'trip_not_found']);
     $channel='trip:'.$tripId;
   }
 }else{
   if($tripId==='')rado_json(422,['ok'=>false,'error'=>'trip_required']);
   $userId=rado_passenger_from_client($pdo,$clientId);
   if(!$userId)rado_json(403,['ok'=>false,'error'=>'unauthorized']);
   $check=$pdo->prepare('SELECT * FROM trips WHERE id=? AND passenger_id=? LIMIT 1');$check->execute([$tripId,$userId]);$trip=$check->fetch();
   if(!is_array($trip))rado_json(404,['ok'=>false,'error'=>'trip_not_found']);
   $channel='trip:'.$tripId;
 }

 if(isset($_GET['stream'])){
   $lastId=max(0,(int)($_SERVER['HTTP_LAST_EVENT_ID']??0),(int)($_GET['since_event_id']??0));
   rado_realtime_stream($pdo,$channel,$lastId);
 }

 $since=max(0,(int)($_GET['since_event_id']??0));
 $ev=$pdo->prepare('SELECT id,event_type,payload_json,created_at FROM realtime_events WHERE channel=? AND id>? AND expires_at>NOW() ORDER BY id ASC LIMIT 100');
 $ev->execute([$channel,$since]);$events=$ev->fetchAll();
 foreach($events as &$x){$x['payload']=json_decode((string)$x['payload_json'],true)?:[];$x['created_at_jalali']=rado_jalali_datetime((string)$x['created_at']);unset($x['payload_json'],$x['created_at']);}
 unset($x);

 if($scope==='driver'&&$role==='driver'&&$tripId===''){
   rado_json(200,['ok'=>true,'channel'=>$channel,'events'=>$events,'server_time'=>rado_time_payload()]);
 }

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

 rado_json(200,[
   'ok'=>true,'trip_id'=>$tripId,'status'=>(string)$trip['status'],
   'driver_position'=>$driverPosition,'driver_profile'=>$driverProfile,'eta'=>$eta,
   'events'=>$events,'server_time'=>rado_time_payload()
 ]);
}catch(Throwable $e){
 $id=substr(bin2hex(random_bytes(8)),0,12);
 try{$dir=rado_root().'/rado-system/state';@mkdir($dir,0755,true);@file_put_contents($dir.'/realtime-errors.log','['.rado_jalali_datetime(null,true).'] '.$id.' '.$e->getMessage()."\n",FILE_APPEND|LOCK_EX);}catch(Throwable){}
 if(isset($_GET['stream'])){
   @header('Content-Type: text/event-stream; charset=utf-8');
   rado_realtime_sse_send('stream_error',['error'=>'realtime_failed','request_id'=>$id]);
   exit;
 }
 rado_json(500,['ok'=>false,'error'=>'realtime_failed','request_id'=>$id]);
}
