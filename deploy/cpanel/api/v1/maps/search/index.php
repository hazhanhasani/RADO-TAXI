<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);

$q = trim((string)($_GET['q'] ?? ''));
$q = strtr($q, ['ي'=>'ی','ك'=>'ک','ۀ'=>'ه','ة'=>'ه']);
$q = preg_replace('/\s+/u', ' ', $q) ?? $q;
if (mb_strlen($q,'UTF-8') < 2) rado_json(422,['ok'=>false,'error'=>'query_too_short','message'=>'حداقل دو حرف برای جستجو وارد کنید.']);

$lat = is_numeric($_GET['lat'] ?? null) ? (float)$_GET['lat'] : 35.9968;
$lng = is_numeric($_GET['lng'] ?? null) ? (float)$_GET['lng'] : 45.8853;
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    $lat = 35.9968; $lng = 45.8853;
}

$root = rado_root();
$secretsFile = $root . '/rado-system/private/ci-secrets.php';
if (!is_file($secretsFile)) rado_json(503,['ok'=>false,'error'=>'neshan_not_configured','message'=>'تنظیمات Service API نشان در پنل RADO کامل نشده است.']);
$secrets = require $secretsFile;
$key = trim((string)($secrets['neshan_service_api_key'] ?? $secrets['neshan_reverse_api_key'] ?? $secrets['neshan_map_key'] ?? ''));
if ($key === '') rado_json(503,['ok'=>false,'error'=>'neshan_service_api_key_missing','message'=>'Service API Key نشان را از مدیریت ← نقشه و Neshan وارد کنید.']);
if (str_starts_with(strtolower($key), 'web.')) rado_json(503,['ok'=>false,'error'=>'neshan_service_key_type_invalid','message'=>'کلید فعلی Web Map Key است؛ برای جستجو باید Service API Key نشان تنظیم شود.']);

function neshanSearch(string $term, float $lat, float $lng, string $key): array {
    $url = 'https://api.neshan.org/v1/search?term=' . rawurlencode($term) . '&lat=' . rawurlencode((string)$lat) . '&lng=' . rawurlencode((string)$lng);
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Api-Key: '.$key,'User-Agent: RADO-TAXI/1.0'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        rado_json(502,[
            'ok'=>false,
            'error'=>'neshan_search_failed',
            'message'=>$status === 401 || $status === 403
                ? 'Service API Key نشان برای Search معتبر نیست یا دسترسی Search ندارد.'
                : 'سرویس جستجوی نشان پاسخ معتبر نداد.',
            'upstream_status'=>$status,
            'detail'=>$err !== '' ? $err : null,
        ]);
    }
    $data = json_decode((string)$body,true);
    if (!is_array($data)) rado_json(502,['ok'=>false,'error'=>'invalid_neshan_response','message'=>'پاسخ جستجوی نشان قابل خواندن نبود.']);
    return $data;
}

function searchLocation(array $row): ?array {
    $loc = $row['location'] ?? null;
    $x = null; $y = null;
    if (is_array($loc)) {
        if (array_is_list($loc) && count($loc) >= 2) {
            $x = $loc[0]; $y = $loc[1];
        } else {
            $x = $loc['x'] ?? $loc['lng'] ?? $loc['longitude'] ?? null;
            $y = $loc['y'] ?? $loc['lat'] ?? $loc['latitude'] ?? null;
        }
    }
    $x ??= $row['lng'] ?? $row['longitude'] ?? null;
    $y ??= $row['lat'] ?? $row['latitude'] ?? null;
    if (!is_numeric($x) || !is_numeric($y)) return null;
    $lat = (float)$y; $lng = (float)$x;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return [$lat,$lng];
}

function distanceMeters(float $lat1,float $lng1,float $lat2,float $lng2): float {
    $earth=6371000.0;
    $p1=deg2rad($lat1);$p2=deg2rad($lat2);
    $dp=deg2rad($lat2-$lat1);$dl=deg2rad($lng2-$lng1);
    $a=sin($dp/2)**2+cos($p1)*cos($p2)*sin($dl/2)**2;
    return $earth*2*atan2(sqrt($a),sqrt(max(0.0,1-$a)));
}

$data = neshanSearch($q,$lat,$lng,$key);
$rows = is_array($data['items'] ?? null) ? $data['items'] : [];

// A common user habit is to include «بانه» in the query. If Neshan returns no result,
// retry without the city name because lat/lng already anchor the search to Baneh.
if ($rows === [] && mb_stripos($q,'بانه',0,'UTF-8') !== false) {
    $short = trim(preg_replace('/(^|\s)بانه($|\s)/u',' ', $q) ?? '');
    if (mb_strlen($short,'UTF-8') >= 2) {
        $retry = neshanSearch($short,$lat,$lng,$key);
        $rows = is_array($retry['items'] ?? null) ? $retry['items'] : [];
    }
}

$items=[];
foreach($rows as $i=>$row){
    if(!is_array($row)) continue;
    $point = searchLocation($row);
    if ($point === null) continue;
    [$itemLat,$itemLng] = $point;
    $title = trim((string)($row['title'] ?? $row['name'] ?? ''));
    $address = trim((string)($row['address'] ?? $row['formatted_address'] ?? ''));
    if ($address === '') {
        $parts=[];
        foreach(['region','neighbourhood','municipality','city'] as $field){
            $v=trim((string)($row[$field]??''));
            if($v!==''&&!in_array($v,$parts,true))$parts[]=$v;
        }
        $address=implode('، ',$parts);
    }
    if ($title === '' && $address === '') continue;
    $items[]=[
        'id'=>(string)($row['id']??$i),
        'title'=>$title!==''?$title:$address,
        'address'=>$address,
        'lat'=>$itemLat,
        'lng'=>$itemLng,
        'distance_m'=>round(distanceMeters($lat,$lng,$itemLat,$itemLng)),
        'type'=>$row['type']??null,
        'region'=>$row['region']??null,
        'neighbourhood'=>$row['neighbourhood']??null,
    ];
}

usort($items, static fn(array $a,array $b): int => ((int)$a['distance_m']) <=> ((int)$b['distance_m']));
$items=array_slice($items,0,20);

rado_json(200,[
    'ok'=>true,
    'query'=>$q,
    'count'=>count($items),
    'items'=>$items,
    'message'=>$items===[]?'نتیجه‌ای برای این عبارت در اطراف بانه پیدا نشد.':null,
    'timezone'=>'Asia/Tehran',
]);
