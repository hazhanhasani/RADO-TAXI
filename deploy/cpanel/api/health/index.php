<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/rado-system/lib/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonResponse(int $status,array $payload):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);exit;}

if(($_GET['service']??'')==='reverse'){
    if($_SERVER['REQUEST_METHOD']!=='GET')jsonResponse(405,['ok'=>false,'error'=>'method_not_allowed']);
    $latRaw=$_GET['lat']??null;$lngRaw=$_GET['lng']??null;
    if(!is_numeric($latRaw)||!is_numeric($lngRaw))jsonResponse(422,['ok'=>false,'error'=>'invalid_coordinates']);
    $lat=(float)$latRaw;$lng=(float)$lngRaw;
    if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)jsonResponse(422,['ok'=>false,'error'=>'coordinates_out_of_range']);
    $secretsFile=$root.'/rado-system/private/ci-secrets.php';
    if(!is_file($secretsFile))jsonResponse(503,['ok'=>false,'error'=>'neshan_not_configured']);
    $secrets=require $secretsFile;
    $apiKey=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??$secrets['neshan_map_key']??''));
    if($apiKey==='')jsonResponse(503,['ok'=>false,'error'=>'neshan_service_api_key_missing']);
    $url='https://api.neshan.org/v2/reverse?lat='.rawurlencode((string)$lat).'&lng='.rawurlencode((string)$lng);
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Accept: application/json','Api-Key: '.$apiKey,'User-Agent: RADO-TAXI/1.0']]);$body=curl_exec($ch);$upstreamStatus=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$curlError=curl_error($ch);curl_close($ch);
    if($body===false||$upstreamStatus<200||$upstreamStatus>=300)jsonResponse(502,['ok'=>false,'error'=>'neshan_reverse_failed','upstream_status'=>$upstreamStatus,'detail'=>$curlError!==''?$curlError:null]);
    $data=json_decode((string)$body,true);if(!is_array($data))jsonResponse(502,['ok'=>false,'error'=>'invalid_neshan_response']);
    $place=trim((string)($data['place']??$data['title']??$data['name']??''));$address=trim((string)($data['formatted_address']??''));
    if($address===''){$parts=[];foreach(['state','city','neighbourhood','route_name'] as $f){$v=trim((string)($data[$f]??''));if($v!==''&&!in_array($v,$parts,true))$parts[]=$v;}$address=implode('، ',$parts);}
    $display=$address;if($place!=='')$display=$display===''?$place:(mb_stripos($display,$place,0,'UTF-8')===false?$place.'، '.$display:$display);
    jsonResponse(200,['ok'=>true,'formatted_address'=>$display,'place_name'=>$place!==''?$place:null,'address_without_place'=>$address!==''?$address:null,'lat'=>$lat,'lng'=>$lng]);
}

$lock=$root.'/rado-system/private/installed.lock';$dbFile=$root.'/rado-system/private/database.php';$secretsFile=$root.'/rado-system/private/ci-secrets.php';
$readState=static function(string $name)use($root):?string{$f=$root.'/rado-system/state/'.$name;return is_file($f)?trim((string)file_get_contents($f)):null;};
$currentTag=$readState('current_tag');$targetTag=$readState('target_tag');$updateStatus=$readState('update_status');$lastSuccessRaw=$readState('last_success_at');$errorCurrent=$readState('last_error_current.log');
$mapSecrets=is_file($secretsFile)?require $secretsFile:[];if(!is_array($mapSecrets))$mapSecrets=[];
$webMapConfigured=trim((string)($mapSecrets['neshan_web_map_key']??''))!=='';
$serviceConfigured=trim((string)($mapSecrets['neshan_service_api_key']??$mapSecrets['neshan_reverse_api_key']??''))!=='';
$legacyConfigured=trim((string)($mapSecrets['neshan_map_key']??''))!=='';

$result=[
 'ok'=>false,'service'=>'RADO','domain'=>'rado-taxi.sbs','installed'=>is_file($lock),'php'=>PHP_VERSION,'timezone'=>'Asia/Tehran','time'=>rado_jalali_datetime(null,true),'time_long'=>rado_jalali_long(),
 'database'=>'not_configured','pricing'=>'unknown',
 'maps'=>[
   'provider'=>'neshan','web_map_key_configured'=>$webMapConfigured,'service_api_key_configured'=>$serviceConfigured,'legacy_generic_key_present'=>$legacyConfigured,'split_configuration_ok'=>$webMapConfigured&&$serviceConfigured,'admin_path'=>'/admin/maps.php'
 ],
 'dispatch'=>['approved_drivers'=>0,'online_drivers'=>0,'fresh_location_drivers'=>0,'searching_trips'=>0,'active_offers'=>0],
 'updater'=>[
   'status'=>$updateStatus??'unknown','last_success_at'=>$lastSuccessRaw!==null&&$lastSuccessRaw!==''?(preg_match('/^\d{4}-\d{2}-\d{2}T|^\d{4}-\d{2}-\d{2} /',$lastSuccessRaw)?rado_jalali_datetime($lastSuccessRaw,true):$lastSuccessRaw):null,'current_tag'=>$currentTag,'target_tag'=>$targetTag,'update_pending'=>$targetTag!==null&&$targetTag!==''&&$targetTag!==$currentTag,'last_attempt_failed'=>$errorCurrent!==null&&$errorCurrent!==''
 ]
];

if(is_file($dbFile)){
 try{
  $pdo=rado_db();$pdo->query('SELECT 1');$result['database']='ok';$result['pricing']=rado_active_pricing_rule($pdo)!==null?'ok':'not_configured';
  $result['dispatch']['approved_drivers']=(int)$pdo->query("SELECT COUNT(*) FROM drivers WHERE status='approved'")->fetchColumn();
  $result['dispatch']['online_drivers']=(int)$pdo->query("SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1")->fetchColumn();
  $result['dispatch']['fresh_location_drivers']=(int)$pdo->query("SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) AND p.lat IS NOT NULL AND p.lng IS NOT NULL")->fetchColumn();
  $result['dispatch']['searching_trips']=(int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status IN ('requested','searching')")->fetchColumn();
  $result['dispatch']['active_offers']=(int)$pdo->query("SELECT COUNT(*) FROM trip_offers WHERE status='offered' AND expires_at>NOW()")->fetchColumn();
 }catch(Throwable){$result['database']='error';$result['pricing']='unavailable';}
}
$result['ok']=$result['installed']&&$result['database']==='ok';http_response_code($result['ok']?200:503);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
