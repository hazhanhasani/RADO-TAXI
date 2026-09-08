<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/rado-system/lib/app.php';
require_once dirname(__DIR__, 3) . '/rado-system/lib/platform.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rado_json(405, ['ok'=>false,'error'=>'method_not_allowed']);

try {
    $body=rado_body();
    $clientId=trim((string)($body['client_id']??''));
    $pickup=is_array($body['pickup']??null)?$body['pickup']:[];
    $destination=is_array($body['destination']??null)?$body['destination']:[];
    foreach([['pickup',$pickup],['destination',$destination]] as [$name,$point]){
        if(!isset($point['lat'],$point['lng'])||!is_numeric($point['lat'])||!is_numeric($point['lng']))rado_json(422,['ok'=>false,'error'=>'invalid_'.$name]);
        $lat=(float)$point['lat'];$lng=(float)$point['lng'];
        if($lat < -90||$lat > 90||$lng < -180||$lng > 180)rado_json(422,['ok'=>false,'error'=>'invalid_'.$name]);
    }
    $distance=filter_var($body['distance_meters']??null,FILTER_VALIDATE_INT);
    $duration=filter_var($body['duration_seconds']??null,FILTER_VALIDATE_INT);
    if($clientId===''||$distance===false||$duration===false||$distance<0||$duration<0)rado_json(422,['ok'=>false,'error'=>'invalid_trip_request']);

    $pickupNote=mb_substr(trim((string)($body['pickup_note']??'')),0,500,'UTF-8');
    $silent=!empty($body['silent_trip']);
    $payment=in_array(($body['payment_method']??'cash'),['cash','wallet','online','corporate'],true)?(string)$body['payment_method']:'cash';
    $serviceType=preg_replace('/[^a-z0-9_-]/i','',(string)($body['service_type']??'economy'))?:'economy';
    $promoCode=strtoupper(trim((string)($body['promo_code']??'')));
    $stops=is_array($body['stops']??null)?array_slice($body['stops'],0,3):[];
    $scheduledFor=null;
    if(!empty($body['scheduled_at'])){
        try{$scheduledDt=rado_tehran_datetime((string)$body['scheduled_at']);}catch(Throwable){rado_json(422,['ok'=>false,'error'=>'invalid_schedule']);}
        if($scheduledDt < new DateTimeImmutable('-1 minute',rado_tehran_timezone())||$scheduledDt > new DateTimeImmutable('+30 days',rado_tehran_timezone()))rado_json(422,['ok'=>false,'error'=>'schedule_out_of_range','message'=>'زمان رزرو باید از اکنون تا ۳۰ روز آینده باشد.']);
        $scheduledFor=$scheduledDt->format('Y-m-d H:i:s');
    }

    $pdo=rado_db();
    $rule=rado_active_pricing_rule($pdo);
    if($rule===null)rado_json(409,['ok'=>false,'error'=>'pricing_not_configured','message'=>'تعرفه سفر هنوز در پنل مدیریت تنظیم نشده است.']);
    $breakdown=rado_fare_breakdown($rule,(int)$distance,(int)$duration);
    $passengerId=rado_guest_passenger($pdo,$clientId);
    $originalFare=(int)$breakdown['fare'];$discount=0;$promoId=null;
    if($promoCode!==''){
        $stmt=$pdo->prepare("SELECT * FROM promo_codes WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>NOW()) LIMIT 1");$stmt->execute([$promoCode]);$promo=$stmt->fetch();
        if(!is_array($promo))rado_json(409,['ok'=>false,'error'=>'promo_invalid','message'=>'کد تخفیف معتبر نیست یا منقضی شده است.']);
        if($originalFare<(int)$promo['min_fare'])rado_json(409,['ok'=>false,'error'=>'promo_min_fare','message'=>'مبلغ این سفر برای کد تخفیف کافی نیست.']);
        $cnt=$pdo->prepare('SELECT COUNT(*) FROM promo_redemptions WHERE promo_id=? AND user_id=?');$cnt->execute([(int)$promo['id'],$passengerId]);if((int)$cnt->fetchColumn()>=(int)$promo['per_user_limit'])rado_json(409,['ok'=>false,'error'=>'promo_user_limit']);
        if($promo['total_limit']!==null){$tc=$pdo->prepare('SELECT COUNT(*) FROM promo_redemptions WHERE promo_id=?');$tc->execute([(int)$promo['id']]);if((int)$tc->fetchColumn()>=(int)$promo['total_limit'])rado_json(409,['ok'=>false,'error'=>'promo_total_limit']);}
        $discount=$promo['discount_type']==='percent'?(int)round($originalFare*((float)$promo['discount_value']/100)):(int)$promo['discount_value'];if($promo['max_discount']!==null)$discount=min($discount,(int)$promo['max_discount']);$discount=max(0,min($discount,$originalFare));$promoId=(int)$promo['id'];
    }
    $fare=max(0,$originalFare-$discount);
    if($payment==='wallet'){
        $w=$pdo->prepare('SELECT COALESCE(balance,(SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id=?)) FROM user_wallets WHERE user_id=?');$w->execute([$passengerId,$passengerId]);$balance=$w->fetchColumn();if($balance===false){$s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id=?');$s->execute([$passengerId]);$balance=$s->fetchColumn();}
        if((int)$balance<$fare)rado_json(409,['ok'=>false,'error'=>'wallet_insufficient','message'=>'موجودی کیف پول برای این سفر کافی نیست.']);
    }
    $corporateId=is_numeric($body['corporate_account_id']??null)?(int)$body['corporate_account_id']:null;
    if($payment==='corporate'){
        if(!$corporateId)rado_json(422,['ok'=>false,'error'=>'corporate_account_required']);$m=$pdo->prepare('SELECT COUNT(*) FROM corporate_members cm JOIN corporate_accounts ca ON ca.id=cm.corporate_account_id WHERE cm.corporate_account_id=? AND cm.user_id=? AND cm.active=1 AND ca.active=1');$m->execute([$corporateId,$passengerId]);if((int)$m->fetchColumn()!==1)rado_json(403,['ok'=>false,'error'=>'corporate_access_denied']);
    }

    $tripId=rado_uuid4();
    $dispatchNow=$scheduledFor===null||rado_tehran_datetime($scheduledFor)<=new DateTimeImmutable('+2 minutes',rado_tehran_timezone());
    $status=$dispatchNow?'searching':'requested';
    $pdo->beginTransaction();
    $stmt=$pdo->prepare("INSERT INTO trips(id,passenger_id,status,pickup_lat,pickup_lng,destination_lat,destination_lng,pickup_label,destination_label,estimated_distance_m,estimated_duration_s,estimated_fare,requested_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([$tripId,$passengerId,$status,(float)$pickup['lat'],(float)$pickup['lng'],(float)$destination['lat'],(float)$destination['lng'],trim((string)($body['pickup_label']??''))?:null,trim((string)($body['destination_label']??''))?:null,(int)$distance,(int)$duration,$fare]);
    $stmt=$pdo->prepare('INSERT INTO trip_preferences(trip_id,pickup_note,silent_trip,payment_method,service_type,scheduled_for,promo_code,corporate_account_id,original_fare,discount_amount) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$tripId,$pickupNote?:null,$silent?1:0,$payment,$serviceType,$scheduledFor,$promoCode?:null,$corporateId,$originalFare,$discount]);
    $seq=1;foreach($stops as $stop){if(!is_array($stop)||!is_numeric($stop['lat']??null)||!is_numeric($stop['lng']??null))continue;$label=trim((string)($stop['label']??''));if($label==='')continue;$wait=max(0,min(180,(int)($stop['wait_minutes']??0)));$pdo->prepare('INSERT INTO trip_stops(trip_id,sequence_no,label,latitude,longitude,wait_minutes) VALUES(?,?,?,?,?,?)')->execute([$tripId,$seq++,$label,(float)$stop['lat'],(float)$stop['lng'],$wait]);}
    if($promoId!==null)$pdo->prepare('INSERT INTO promo_redemptions(promo_id,user_id,trip_id,discount_amount) VALUES(?,?,?,?)')->execute([$promoId,$passengerId,$tripId,$discount]);
    $pdo->commit();

    $notified=$dispatchNow?rado_dispatch_trip_v2($pdo,$tripId,(float)$pickup['lat'],(float)$pickup['lng']):0;
    $row=rado_trip_row($pdo,$tripId);$payload=$row?rado_trip_payload($row):['id'=>$tripId,'status'=>$status,'status_fa'=>$status==='requested'?'زمان‌بندی‌شده':'در جستجوی راننده','estimated_fare'=>$fare,'timezone'=>'Asia/Tehran'];
    if(!$dispatchNow)$payload['status_fa']='سفر زمان‌بندی‌شده';
    $payload['drivers_notified']=$notified;$payload['preferences']=['pickup_note'=>$pickupNote,'silent_trip'=>$silent,'payment_method'=>$payment,'service_type'=>$serviceType,'scheduled_for'=>$scheduledFor?rado_time_payload($scheduledFor):null,'promo_code'=>$promoCode?:null,'original_fare'=>$originalFare,'discount_amount'=>$discount,'stops_count'=>$seq-1];
    rado_platform_event($pdo,'trip:'.$tripId,'trip_created',['status'=>$status,'scheduled_for'=>$scheduledFor,'fare'=>$fare]);
    rado_json(201,['ok'=>true,'trip'=>$payload,'server_time'=>rado_time_payload()]);
} catch(Throwable $e){
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    $id=substr(bin2hex(random_bytes(8)),0,12);try{$dir=rado_root().'/rado-system/state';@mkdir($dir,0755,true);@file_put_contents($dir.'/trip-errors.log','['.rado_jalali_datetime(null,true).'] '.$id.' '.$e->getMessage()."\n",FILE_APPEND|LOCK_EX);}catch(Throwable){}
    rado_json(500,['ok'=>false,'error'=>'trip_request_failed','request_id'=>$id,'message'=>'ثبت درخواست سفر روی سرور RADO انجام نشد.']);
}
