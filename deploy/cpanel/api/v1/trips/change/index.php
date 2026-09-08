<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);
try{
  $b=rado_body();$clientId=trim((string)($b['client_id']??''));$tripId=trim((string)($b['trip_id']??''));$action=trim((string)($b['action']??''));
  $pdo=rado_db();$passengerId=rado_passenger_from_client($pdo,$clientId);if(!$passengerId||$tripId==='')rado_json(403,['ok'=>false,'error'=>'passenger_not_found']);
  $stmt=$pdo->prepare('SELECT * FROM trips WHERE id=? AND passenger_id=? LIMIT 1');$stmt->execute([$tripId,$passengerId]);$trip=$stmt->fetch();if(!is_array($trip))rado_json(404,['ok'=>false,'error'=>'trip_not_found']);
  if(in_array($trip['status'],['completed','cancelled_by_passenger','cancelled_by_driver','cancelled_by_admin','expired'],true))rado_json(409,['ok'=>false,'error'=>'trip_not_changeable']);
  if($action==='destination'){
    $lat=$b['lat']??null;$lng=$b['lng']??null;$label=trim((string)($b['label']??''));$distance=filter_var($b['distance_meters']??null,FILTER_VALIDATE_INT);$duration=filter_var($b['duration_seconds']??null,FILTER_VALIDATE_INT);
    if(!is_numeric($lat)||!is_numeric($lng)||$label===''||$distance===false||$duration===false)rado_json(422,['ok'=>false,'error'=>'invalid_destination_change']);
    $rule=rado_active_pricing_rule($pdo);if(!$rule)rado_json(409,['ok'=>false,'error'=>'pricing_not_configured']);$fare=rado_fare_breakdown($rule,(int)$distance,(int)$duration)['fare'];
    $before=['label'=>$trip['destination_label'],'lat'=>(float)$trip['destination_lat'],'lng'=>(float)$trip['destination_lng'],'fare'=>(int)$trip['estimated_fare']];
    $pdo->beginTransaction();$pdo->prepare('UPDATE trips SET destination_lat=?,destination_lng=?,destination_label=?,estimated_distance_m=?,estimated_duration_s=?,estimated_fare=?,version=version+1 WHERE id=?')->execute([(float)$lat,(float)$lng,$label,(int)$distance,(int)$duration,(int)$fare,$tripId]);
    $pdo->prepare('INSERT INTO trip_preferences(trip_id,original_fare,repriced_fare) VALUES(?,?,?) ON DUPLICATE KEY UPDATE original_fare=COALESCE(original_fare,VALUES(original_fare)),repriced_fare=VALUES(repriced_fare)')->execute([$tripId,(int)$trip['estimated_fare'],(int)$fare]);
    $pdo->prepare("INSERT INTO trip_changes(trip_id,change_type,old_value_json,new_value_json,fare_before,fare_after,changed_by_user_id) VALUES(?,'destination',?,?,?,?,?)")->execute([$tripId,json_encode($before,JSON_UNESCAPED_UNICODE),json_encode(['label'=>$label,'lat'=>(float)$lat,'lng'=>(float)$lng],JSON_UNESCAPED_UNICODE),(int)$trip['estimated_fare'],(int)$fare,$passengerId]);$pdo->commit();
    rado_json(200,['ok'=>true,'fare'=>(int)$fare,'message'=>'مقصد و کرایه جدید ثبت شد.','server_time'=>rado_time_payload()]);
  }
  if($action==='add_stop'){
    $lat=$b['lat']??null;$lng=$b['lng']??null;$label=trim((string)($b['label']??''));$wait=max(0,min(180,(int)($b['wait_minutes']??0)));if(!is_numeric($lat)||!is_numeric($lng)||$label==='')rado_json(422,['ok'=>false,'error'=>'invalid_stop']);
    $s=$pdo->prepare('SELECT COALESCE(MAX(sequence_no),0)+1 FROM trip_stops WHERE trip_id=?');$s->execute([$tripId]);$seq=(int)$s->fetchColumn();$pdo->prepare('INSERT INTO trip_stops(trip_id,sequence_no,label,latitude,longitude,wait_minutes) VALUES(?,?,?,?,?,?)')->execute([$tripId,$seq,$label,(float)$lat,(float)$lng,$wait]);
    $pdo->prepare("INSERT INTO trip_changes(trip_id,change_type,new_value_json,changed_by_user_id) VALUES(?,'stop_added',?,?)")->execute([$tripId,json_encode(['sequence'=>$seq,'label'=>$label,'lat'=>(float)$lat,'lng'=>(float)$lng,'wait_minutes'=>$wait],JSON_UNESCAPED_UNICODE),$passengerId]);rado_json(201,['ok'=>true,'sequence'=>$seq]);
  }
  if($action==='remove_stop'){$seq=(int)($b['sequence']??0);$pdo->prepare('DELETE FROM trip_stops WHERE trip_id=? AND sequence_no=?')->execute([$tripId,$seq]);$pdo->prepare("INSERT INTO trip_changes(trip_id,change_type,new_value_json,changed_by_user_id) VALUES(?,'stop_removed',?,?)")->execute([$tripId,json_encode(['sequence'=>$seq]),$passengerId]);rado_json(200,['ok'=>true]);}
  if($action==='payment_method'){$method=(string)($b['payment_method']??'cash');if(!in_array($method,['cash','wallet','online','corporate'],true))rado_json(422,['ok'=>false,'error'=>'invalid_payment_method']);$pdo->prepare('INSERT INTO trip_preferences(trip_id,payment_method) VALUES(?,?) ON DUPLICATE KEY UPDATE payment_method=VALUES(payment_method)')->execute([$tripId,$method]);rado_json(200,['ok'=>true]);}
  if($action==='pickup_note'){$note=mb_substr(trim((string)($b['pickup_note']??'')),0,500,'UTF-8');$pdo->prepare('INSERT INTO trip_preferences(trip_id,pickup_note) VALUES(?,?) ON DUPLICATE KEY UPDATE pickup_note=VALUES(pickup_note)')->execute([$tripId,$note?:null]);rado_json(200,['ok'=>true]);}
  rado_json(422,['ok'=>false,'error'=>'unknown_trip_change']);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$id=substr(bin2hex(random_bytes(8)),0,12);rado_json(500,['ok'=>false,'error'=>'trip_change_failed','request_id'=>$id]);}
