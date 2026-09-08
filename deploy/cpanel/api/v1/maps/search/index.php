<?php
declare(strict_types=1);
require dirname(__DIR__, 5) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);
$q=trim((string)($_GET['q']??''));
if(mb_strlen($q,'UTF-8')<2) rado_json(422,['ok'=>false,'error'=>'query_too_short']);
$lat=is_numeric($_GET['lat']??null)?(float)$_GET['lat']:35.9968;
$lng=is_numeric($_GET['lng']??null)?(float)$_GET['lng']:45.8853;
$root=rado_root();$secretsFile=$root.'/rado-system/private/ci-secrets.php';
if(!is_file($secretsFile))rado_json(503,['ok'=>false,'error'=>'neshan_not_configured']);
$secrets=require $secretsFile;$key=trim((string)($secrets['neshan_service_api_key']??$secrets['neshan_reverse_api_key']??$secrets['neshan_map_key']??''));
if($key==='')rado_json(503,['ok'=>false,'error'=>'neshan_api_key_missing']);
$url='https://api.neshan.org/v1/search?term='.rawurlencode($q).'&lat='.rawurlencode((string)$lat).'&lng='.rawurlencode((string)$lng);
$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Accept: application/json','Api-Key: '.$key,'User-Agent: RADO-TAXI/1.0']]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
if($body===false||$status<200||$status>=300)rado_json(502,['ok'=>false,'error'=>'neshan_search_failed','upstream_status'=>$status,'detail'=>$err?:null]);
$data=json_decode((string)$body,true);if(!is_array($data))rado_json(502,['ok'=>false,'error'=>'invalid_neshan_response']);
$items=[];foreach(($data['items']??[]) as $i=>$row){if(!is_array($row))continue;$loc=$row['location']??[];$x=$loc['x']??$row['lng']??null;$y=$loc['y']??$row['lat']??null;if(!is_numeric($x)||!is_numeric($y))continue;$title=trim((string)($row['title']??$row['name']??''));$address=trim((string)($row['address']??$row['formatted_address']??''));$items[]=['id'=>(string)($row['id']??$i),'title'=>$title!==''?$title:$address,'address'=>$address,'lat'=>(float)$y,'lng'=>(float)$x,'type'=>$row['type']??null,'region'=>$row['region']??null,'neighbourhood'=>$row['neighbourhood']??null];if(count($items)>=20)break;}
rado_json(200,['ok'=>true,'query'=>$q,'items'=>$items,'timezone'=>'Asia/Tehran']);
