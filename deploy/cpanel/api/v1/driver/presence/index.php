<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/platform.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);

try {
    $body=rado_body();
    $clientId=trim((string)($body['client_id']??''));
    $online=filter_var($body['online']??false,FILTER_VALIDATE_BOOL);
    $pdo=rado_db();$driver=rado_require_approved_driver($pdo,$clientId);$driverId=(string)$driver['id'];
    $lat=null;$lng=null;$heading=null;$speed=null;
    if($online){
      if(!is_numeric($body['lat']??null)||!is_numeric($body['lng']??null))rado_json(422,['ok'=>false,'error'=>'location_required','message'=>'موقعیت راننده برای آنلاین شدن لازم است.']);
      $lat=(float)$body['lat'];$lng=(float)$body['lng'];if($lat<-90||$lat>90||$lng<-180||$lng>180)rado_json(422,['ok'=>false,'error'=>'invalid_location']);
      $heading=is_numeric($body['heading']??null)?max(0,min(359,(int)$body['heading'])):null;$speed=is_numeric($body['speed_kph']??null)?max(0,min(300,(float)$body['speed_kph'])):null;
    }
    $stmt=$pdo->prepare("INSERT INTO driver_presence(driver_id,is_online,latitude,longitude,heading,speed_kph,last_seen_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE is_online=VALUES(is_online),latitude=IF(VALUES(is_online)=1,VALUES(latitude),latitude),longitude=IF(VALUES(is_online)=1,VALUES(longitude),longitude),heading=IF(VALUES(is_online)=1,VALUES(heading),heading),speed_kph=IF(VALUES(is_online)=1,VALUES(speed_kph),speed_kph),last_seen_at=NOW()");
    $stmt->execute([$driverId,$online?1:0,$lat,$lng,$heading,$speed]);
    if($online&&$lat!==null&&$lng!==null){
      rado_offer_waiting_trip_to_driver_v2($pdo,$driverId,$lat,$lng);
      $locationPayload=['driver_id'=>$driverId,'online'=>true,'lat'=>$lat,'lng'=>$lng,'heading'=>$heading,'speed_kph'=>$speed];
      rado_platform_event($pdo,'driver:'.$driverId,'presence',$locationPayload,600);

      // Publish the same GPS sample into the assigned trip channel. The passenger
      // can therefore move the driver marker immediately without polling or
      // being authorized to subscribe to the driver's private channel.
      $active=$pdo->prepare("SELECT id FROM trips WHERE driver_id=? AND status IN('driver_assigned','driver_arriving','arrived','in_progress') ORDER BY accepted_at DESC LIMIT 1");
      $active->execute([$driverId]);$activeTripId=$active->fetchColumn();
      if($activeTripId!==false){
        rado_platform_event($pdo,'trip:'.(string)$activeTripId,'driver_location',$locationPayload,90);
      }
    }else{
      rado_platform_event($pdo,'driver:'.$driverId,'presence',['driver_id'=>$driverId,'online'=>false],600);
    }
    rado_json(200,['ok'=>true,'online'=>$online,'driver_id'=>$driverId,'updated_at'=>rado_time_payload()]);
} catch(Throwable $e){$id=substr(bin2hex(random_bytes(8)),0,12);rado_json(500,['ok'=>false,'error'=>'driver_presence_failed','request_id'=>$id]);}
