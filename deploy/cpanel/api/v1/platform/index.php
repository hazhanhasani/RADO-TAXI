<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/rado-system/lib/app.php';

$pdo = rado_db();
$action = trim((string)($_GET['action'] ?? 'capabilities'));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function platformPassenger(PDO $pdo, string $clientId, bool $create = true): ?string {
    if ($clientId === '') return null;
    return $create ? rado_guest_passenger($pdo, $clientId) : rado_passenger_from_client($pdo, $clientId);
}
function platformDriver(PDO $pdo, string $clientId): ?array {
    if ($clientId === '') return null;
    return rado_driver_from_client($pdo, $clientId);
}
function platformBody(): array { return rado_body(); }
function platformNow(): string { return (new DateTimeImmutable('now', rado_tehran_timezone()))->format('Y-m-d H:i:s'); }
function platformAudit(PDO $pdo, ?string $actor, string $role, string $action, ?string $entityType=null, ?string $entityId=null, ?array $before=null, ?array $after=null): void {
    try {
        $stmt=$pdo->prepare('INSERT INTO audit_logs(actor_user_id,actor_role,action,entity_type,entity_id,before_json,after_json,ip_address) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$actor,$role,$action,$entityType,$entityId,$before?json_encode($before,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$after?json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,(string)($_SERVER['REMOTE_ADDR']??'')]);
    } catch(Throwable) {}
}
function platformWalletBalance(PDO $pdo, string $userId): int {
    $stmt=$pdo->prepare('SELECT balance FROM user_wallets WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $v=$stmt->fetchColumn();
    if ($v!==false) return (int)$v;
    $stmt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id=?');
    $stmt->execute([$userId]);
    $balance=(int)$stmt->fetchColumn();
    $ins=$pdo->prepare('INSERT INTO user_wallets(user_id,balance) VALUES(?,?) ON DUPLICATE KEY UPDATE balance=VALUES(balance)');
    $ins->execute([$userId,$balance]);
    return $balance;
}

try {
    if ($action === 'capabilities' && $method === 'GET') {
        rado_json(200, ['ok'=>true,'version'=>'0.4','timezone'=>'Asia/Tehran','calendar'=>'jalali','features'=>[
            'poi_search'=>true,'favorites'=>true,'pickup_note'=>true,'multi_stop'=>true,'trip_repricing'=>true,'scheduled_rides'=>true,
            'silent_trip'=>true,'cash'=>true,'wallet'=>true,'online_payment'=>true,'ratings'=>true,'promos'=>true,'trip_share'=>true,
            'history'=>true,'driver_destination'=>true,'driver_filters'=>true,'auto_accept'=>true,'settlements'=>true,'missions'=>true,
            'otp'=>true,'driver_documents'=>true,'push_notifications'=>true,'realtime_events'=>true,'service_geofence'=>true,
            'corporate_accounts'=>true,'referrals'=>true,'loyalty'=>true,'operator_booking'=>true,'audit_log'=>true,'health_events'=>true
        ],'server_time'=>rado_time_payload()]);
    }

    if ($action === 'favorites') {
        $clientId = trim((string)($_GET['client_id'] ?? (platformBody()['client_id'] ?? '')));
        $userId = platformPassenger($pdo,$clientId,true);
        if (!$userId) rado_json(422,['ok'=>false,'error'=>'client_id_required']);
        if ($method === 'GET') {
            $stmt=$pdo->prepare('SELECT id,kind,title,label,latitude,longitude,created_at FROM passenger_favorites WHERE passenger_id=? ORDER BY FIELD(kind,\'home\',\'work\',\'favorite\'),updated_at DESC');
            $stmt->execute([$userId]);
            $rows=$stmt->fetchAll();
            foreach($rows as &$r) $r['created_at_jalali']=rado_jalali_datetime((string)$r['created_at']);
            rado_json(200,['ok'=>true,'favorites'=>$rows]);
        }
        if ($method === 'POST') {
            $b=platformBody(); $op=(string)($b['op']??'save');
            if ($op==='delete') {
                $id=(int)($b['id']??0); $stmt=$pdo->prepare('DELETE FROM passenger_favorites WHERE id=? AND passenger_id=?'); $stmt->execute([$id,$userId]);
                platformAudit($pdo,$userId,'passenger','favorite_delete','favorite',(string)$id); rado_json(200,['ok'=>true]);
            }
            $kind=in_array(($b['kind']??'favorite'),['home','work','favorite'],true)?$b['kind']:'favorite';
            $title=trim((string)($b['title']??'')); $label=trim((string)($b['label']??'')); $lat=$b['lat']??null; $lng=$b['lng']??null;
            if ($title===''||$label===''||!is_numeric($lat)||!is_numeric($lng)) rado_json(422,['ok'=>false,'error'=>'invalid_favorite']);
            if (in_array($kind,['home','work'],true)) { $d=$pdo->prepare('DELETE FROM passenger_favorites WHERE passenger_id=? AND kind=?'); $d->execute([$userId,$kind]); }
            $stmt=$pdo->prepare('INSERT INTO passenger_favorites(passenger_id,kind,title,label,latitude,longitude) VALUES(?,?,?,?,?,?)');
            $stmt->execute([$userId,$kind,$title,$label,(float)$lat,(float)$lng]);
            $id=(int)$pdo->lastInsertId(); platformAudit($pdo,$userId,'passenger','favorite_save','favorite',(string)$id,null,$b);
            rado_json(201,['ok'=>true,'id'=>$id]);
        }
    }

    if ($action === 'history' && $method === 'GET') {
        $userId=platformPassenger($pdo,trim((string)($_GET['client_id']??'')),false);
        if (!$userId) rado_json(200,['ok'=>true,'trips'=>[]]);
        $stmt=$pdo->prepare('SELECT id,status,pickup_label,destination_label,estimated_fare,final_fare,requested_at,completed_at FROM trips WHERE passenger_id=? ORDER BY requested_at DESC LIMIT 100');
        $stmt->execute([$userId]); $rows=$stmt->fetchAll();
        foreach($rows as &$r){$r['status_fa']=rado_trip_status_fa((string)$r['status']);$r['requested_at_jalali']=rado_jalali_datetime((string)$r['requested_at']);$r['completed_at_jalali']=$r['completed_at']?rado_jalali_datetime((string)$r['completed_at']):null;}
        rado_json(200,['ok'=>true,'trips'=>$rows]);
    }

    if ($action === 'wallet' && $method === 'GET') {
        $role=(string)($_GET['role']??'passenger'); $clientId=trim((string)($_GET['client_id']??''));
        if ($role==='driver') { $d=platformDriver($pdo,$clientId); $userId=$d['id']??null; } else $userId=platformPassenger($pdo,$clientId,true);
        if (!$userId) rado_json(422,['ok'=>false,'error'=>'client_id_required']);
        $balance=platformWalletBalance($pdo,(string)$userId);
        $stmt=$pdo->prepare('SELECT entry_type,amount,created_at,metadata_json FROM ledger_entries WHERE user_id=? ORDER BY id DESC LIMIT 50');$stmt->execute([$userId]);$rows=$stmt->fetchAll();
        foreach($rows as &$r)$r['created_at_jalali']=rado_jalali_datetime((string)$r['created_at']);
        rado_json(200,['ok'=>true,'wallet'=>['balance'=>$balance,'currency'=>'IRR','entries'=>$rows]]);
    }

    if ($action === 'promo') {
        if ($method!=='POST') rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);
        $b=platformBody(); $clientId=trim((string)($b['client_id']??'')); $code=strtoupper(trim((string)($b['code']??''))); $fare=max(0,(int)($b['fare']??0));
        $userId=platformPassenger($pdo,$clientId,true); if(!$userId||$code==='') rado_json(422,['ok'=>false,'error'=>'invalid_promo_request']);
        $stmt=$pdo->prepare("SELECT * FROM promo_codes WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>NOW()) LIMIT 1");$stmt->execute([$code]);$p=$stmt->fetch();
        if(!is_array($p)) rado_json(404,['ok'=>false,'error'=>'promo_not_found','message'=>'کد تخفیف معتبر نیست یا منقضی شده است.']);
        if($fare<(int)$p['min_fare']) rado_json(409,['ok'=>false,'error'=>'promo_min_fare','message'=>'مبلغ سفر برای این کد تخفیف کافی نیست.']);
        $count=$pdo->prepare('SELECT COUNT(*) FROM promo_redemptions WHERE promo_id=? AND user_id=?');$count->execute([(int)$p['id'],$userId]);
        if((int)$count->fetchColumn()>=(int)$p['per_user_limit']) rado_json(409,['ok'=>false,'error'=>'promo_user_limit','message'=>'سقف استفاده شما از این کد تکمیل شده است.']);
        $discount=$p['discount_type']==='percent'?(int)round($fare*((float)$p['discount_value']/100)):(int)$p['discount_value'];
        if($p['max_discount']!==null)$discount=min($discount,(int)$p['max_discount']);$discount=min($discount,$fare);
        rado_json(200,['ok'=>true,'code'=>$code,'discount_amount'=>$discount,'payable'=>max(0,$fare-$discount)]);
    }

    if ($action === 'rating' && $method === 'POST') {
        $b=platformBody();$clientId=trim((string)($b['client_id']??''));$role=(string)($b['role']??'passenger');$tripId=trim((string)($b['trip_id']??''));$score=(int)($b['score']??0);
        if($score<1||$score>5||$tripId==='') rado_json(422,['ok'=>false,'error'=>'invalid_rating']);
        if($role==='driver'){$d=platformDriver($pdo,$clientId);$userId=$d['id']??null;$target='passenger';}else{$userId=platformPassenger($pdo,$clientId,false);$target='driver';}
        if(!$userId) rado_json(403,['ok'=>false,'error'=>'user_not_found']);
        $stmt=$pdo->prepare('INSERT INTO ratings_extended(trip_id,user_id,target_role,score,tags_json,comment) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score),tags_json=VALUES(tags_json),comment=VALUES(comment)');
        $stmt->execute([$tripId,$userId,$target,$score,json_encode($b['tags']??[],JSON_UNESCAPED_UNICODE),trim((string)($b['comment']??''))?:null]);
        rado_json(200,['ok'=>true]);
    }

    if ($action === 'share' && $method === 'POST') {
        $b=platformBody();$tripId=trim((string)($b['trip_id']??''));$userId=platformPassenger($pdo,trim((string)($b['client_id']??'')),false);
        if(!$userId||$tripId==='') rado_json(422,['ok'=>false,'error'=>'invalid_share_request']);
        $check=$pdo->prepare('SELECT COUNT(*) FROM trips WHERE id=? AND passenger_id=?');$check->execute([$tripId,$userId]);if((int)$check->fetchColumn()!==1)rado_json(404,['ok'=>false,'error'=>'trip_not_found']);
        $token=bin2hex(random_bytes(24));$expires=(new DateTimeImmutable('+12 hours',rado_tehran_timezone()))->format('Y-m-d H:i:s');
        $stmt=$pdo->prepare('INSERT INTO trip_shares(trip_id,token,expires_at) VALUES(?,?,?)');$stmt->execute([$tripId,$token,$expires]);
        rado_json(201,['ok'=>true,'url'=>'https://rado-taxi.sbs/share/'.$token,'expires_at'=>rado_jalali_datetime($expires)]);
    }

    if ($action === 'support') {
        $b=$method==='POST'?platformBody():[];$role=(string)($_GET['role']??($b['role']??'passenger'));$clientId=trim((string)($_GET['client_id']??($b['client_id']??'')));
        if($role==='driver'){$d=platformDriver($pdo,$clientId);$userId=$d['id']??null;}else$userId=platformPassenger($pdo,$clientId,true);
        if(!$userId)rado_json(403,['ok'=>false,'error'=>'user_not_found']);
        if($method==='GET'){$stmt=$pdo->prepare('SELECT id,trip_id,subject,category,status,priority,created_at,updated_at FROM support_tickets WHERE user_id=? ORDER BY updated_at DESC LIMIT 50');$stmt->execute([$userId]);$rows=$stmt->fetchAll();foreach($rows as &$r){$r['created_at_jalali']=rado_jalali_datetime((string)$r['created_at']);$r['updated_at_jalali']=rado_jalali_datetime((string)$r['updated_at']);}rado_json(200,['ok'=>true,'tickets'=>$rows]);}
        $op=(string)($b['op']??'create');
        if($op==='reply'){$id=(int)($b['ticket_id']??0);$message=trim((string)($b['message']??''));if($id<1||$message==='')rado_json(422,['ok'=>false,'error'=>'invalid_reply']);$check=$pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE id=? AND user_id=?');$check->execute([$id,$userId]);if((int)$check->fetchColumn()!==1)rado_json(404,['ok'=>false,'error'=>'ticket_not_found']);$stmt=$pdo->prepare('INSERT INTO support_messages(ticket_id,sender_user_id,sender_role,message) VALUES(?,?,?,?)');$stmt->execute([$id,$userId,$role,$message]);$pdo->prepare("UPDATE support_tickets SET status='waiting_admin' WHERE id=?")->execute([$id]);rado_json(200,['ok'=>true]);}
        $subject=trim((string)($b['subject']??''));$message=trim((string)($b['message']??''));if($subject===''||$message==='')rado_json(422,['ok'=>false,'error'=>'invalid_ticket']);$stmt=$pdo->prepare('INSERT INTO support_tickets(user_id,trip_id,subject,category,status,priority) VALUES(?,?,?,?,\'waiting_admin\',?)');$stmt->execute([$userId,trim((string)($b['trip_id']??''))?:null,$subject,trim((string)($b['category']??'general'))?:'general',in_array(($b['priority']??'normal'),['low','normal','high','urgent'],true)?$b['priority']:'normal']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO support_messages(ticket_id,sender_user_id,sender_role,message) VALUES(?,?,?,?)')->execute([$id,$userId,$role,$message]);rado_json(201,['ok'=>true,'ticket_id'=>$id]);
    }

    if ($action === 'notifications') {
        $b=$method==='POST'?platformBody():[];$role=(string)($_GET['role']??($b['role']??'passenger'));$clientId=trim((string)($_GET['client_id']??($b['client_id']??'')));
        if($role==='driver'){$d=platformDriver($pdo,$clientId);$userId=$d['id']??null;}else$userId=platformPassenger($pdo,$clientId,true);if(!$userId)rado_json(403,['ok'=>false,'error'=>'user_not_found']);
        if($method==='GET'){$stmt=$pdo->prepare('SELECT id,title,body,type,data_json,read_at,created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100');$stmt->execute([$userId]);$rows=$stmt->fetchAll();foreach($rows as &$r)$r['created_at_jalali']=rado_jalali_datetime((string)$r['created_at']);rado_json(200,['ok'=>true,'notifications'=>$rows]);}
        if(($b['op']??'')==='register_device'){$token=trim((string)($b['token']??''));if($token==='')rado_json(422,['ok'=>false,'error'=>'token_required']);$stmt=$pdo->prepare('INSERT INTO device_tokens(user_id,platform,token,active,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),active=1,last_seen_at=NOW()');$stmt->execute([$userId,in_array(($b['platform']??'android'),['android','ios','web'],true)?$b['platform']:'android',$token,1]);rado_json(200,['ok'=>true]);}
        $id=(int)($b['id']??0);if($id>0)$pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?')->execute([$id,$userId]);rado_json(200,['ok'=>true]);
    }

    if ($action === 'driver_preferences') {
        $b=$method==='POST'?platformBody():[];$clientId=trim((string)($_GET['client_id']??($b['client_id']??'')));$d=platformDriver($pdo,$clientId);if(!$d)rado_json(403,['ok'=>false,'error'=>'driver_not_found']);$driverId=(string)$d['id'];
        if($method==='GET'){$stmt=$pdo->prepare('SELECT * FROM driver_preferences WHERE driver_id=?');$stmt->execute([$driverId]);$p=$stmt->fetch()?:[];rado_json(200,['ok'=>true,'preferences'=>$p]);}
        $stmt=$pdo->prepare('INSERT INTO driver_preferences(driver_id,destination_label,destination_lat,destination_lng,max_pickup_distance_km,min_fare,auto_accept,auto_accept_radius_km,auto_accept_min_fare) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE destination_label=VALUES(destination_label),destination_lat=VALUES(destination_lat),destination_lng=VALUES(destination_lng),max_pickup_distance_km=VALUES(max_pickup_distance_km),min_fare=VALUES(min_fare),auto_accept=VALUES(auto_accept),auto_accept_radius_km=VALUES(auto_accept_radius_km),auto_accept_min_fare=VALUES(auto_accept_min_fare)');
        $stmt->execute([$driverId,trim((string)($b['destination_label']??''))?:null,is_numeric($b['destination_lat']??null)?(float)$b['destination_lat']:null,is_numeric($b['destination_lng']??null)?(float)$b['destination_lng']:null,is_numeric($b['max_pickup_distance_km']??null)?(float)$b['max_pickup_distance_km']:null,is_numeric($b['min_fare']??null)?(int)$b['min_fare']:null,!empty($b['auto_accept'])?1:0,is_numeric($b['auto_accept_radius_km']??null)?(float)$b['auto_accept_radius_km']:null,is_numeric($b['auto_accept_min_fare']??null)?(int)$b['auto_accept_min_fare']:null]);
        platformAudit($pdo,$driverId,'driver','driver_preferences_save','driver',$driverId,null,$b);rado_json(200,['ok'=>true]);
    }

    if ($action === 'missions' && $method === 'GET') {
        $d=platformDriver($pdo,trim((string)($_GET['client_id']??'')));if(!$d)rado_json(403,['ok'=>false,'error'=>'driver_not_found']);$driverId=(string)$d['id'];
        $stmt=$pdo->prepare("SELECT m.*,COALESCE(p.progress_value,0) progress_value,p.completed_at,p.rewarded_at FROM driver_missions m LEFT JOIN driver_mission_progress p ON p.mission_id=m.id AND p.driver_id=? WHERE m.active=1 AND m.starts_at<=NOW() AND m.ends_at>NOW() ORDER BY m.ends_at");$stmt->execute([$driverId]);$rows=$stmt->fetchAll();foreach($rows as &$r){$r['starts_at_jalali']=rado_jalali_datetime((string)$r['starts_at']);$r['ends_at_jalali']=rado_jalali_datetime((string)$r['ends_at']);}rado_json(200,['ok'=>true,'missions'=>$rows]);
    }

    if ($action === 'settlements') {
        $b=$method==='POST'?platformBody():[];$d=platformDriver($pdo,trim((string)($_GET['client_id']??($b['client_id']??''))));if(!$d)rado_json(403,['ok'=>false,'error'=>'driver_not_found']);$driverId=(string)$d['id'];
        if($method==='GET'){$stmt=$pdo->prepare('SELECT id,amount,status,note,requested_at,reviewed_at,paid_at FROM driver_settlements WHERE driver_id=? ORDER BY id DESC LIMIT 100');$stmt->execute([$driverId]);$rows=$stmt->fetchAll();foreach($rows as &$r)$r['requested_at_jalali']=rado_jalali_datetime((string)$r['requested_at']);rado_json(200,['ok'=>true,'settlements'=>$rows]);}
        $amount=(int)($b['amount']??0);$balance=platformWalletBalance($pdo,$driverId);if($amount<=0||$amount>$balance)rado_json(422,['ok'=>false,'error'=>'invalid_settlement_amount','message'=>'مبلغ تسویه از موجودی قابل برداشت بیشتر است.']);$stmt=$pdo->prepare("INSERT INTO driver_settlements(driver_id,amount,status,requested_at) VALUES(?,?,'requested',NOW())");$stmt->execute([$driverId,$amount]);rado_json(201,['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'loyalty' && $method === 'GET') {
        $userId=platformPassenger($pdo,trim((string)($_GET['client_id']??'')),true);if(!$userId)rado_json(422,['ok'=>false,'error'=>'client_id_required']);$stmt=$pdo->prepare('SELECT COALESCE(SUM(points),0) FROM loyalty_ledger WHERE user_id=?');$stmt->execute([$userId]);$points=(int)$stmt->fetchColumn();rado_json(200,['ok'=>true,'points'=>$points]);
    }

    if ($action === 'otp' && $method === 'POST') {
        $b=platformBody();$op=(string)($b['op']??'request');$phone=preg_replace('/\D+/','',(string)($b['phone']??''));if(strlen($phone)<10||strlen($phone)>15)rado_json(422,['ok'=>false,'error'=>'invalid_phone']);
        if($op==='verify'){$code=trim((string)($b['code']??''));$stmt=$pdo->prepare("SELECT * FROM otp_challenges WHERE phone=? AND purpose=? AND consumed_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1");$stmt->execute([$phone,trim((string)($b['purpose']??'login'))]);$row=$stmt->fetch();if(!is_array($row)||!password_verify($code,(string)$row['code_hash']))rado_json(401,['ok'=>false,'error'=>'invalid_otp','message'=>'کد تایید صحیح نیست یا منقضی شده است.']);$pdo->prepare('UPDATE otp_challenges SET consumed_at=NOW() WHERE id=?')->execute([(int)$row['id']]);rado_json(200,['ok'=>true,'verified'=>true]);}
        $purpose=trim((string)($b['purpose']??'login'))?:'login';$code=(string)random_int(100000,999999);$ttl=max(60,(int)(rado_setting($pdo,'otp_ttl_seconds','120')??'120'));$expires=(new DateTimeImmutable('+'.$ttl.' seconds',rado_tehran_timezone()))->format('Y-m-d H:i:s');$stmt=$pdo->prepare('INSERT INTO otp_challenges(phone,purpose,code_hash,expires_at) VALUES(?,?,?,?)');$stmt->execute([$phone,$purpose,password_hash($code,PASSWORD_DEFAULT),$expires]);
        $webhook=trim((string)(rado_setting($pdo,'otp_sms_webhook','')??''));$delivery='not_configured';if($webhook!==''){$ch=curl_init($webhook);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>$code,'purpose'=>$purpose],JSON_UNESCAPED_UNICODE)]);curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$delivery=$status>=200&&$status<300?'sent':'failed';}
        $response=['ok'=>true,'delivery'=>$delivery,'expires_in'=>$ttl];if($delivery==='not_configured' && (bool)(rado_setting($pdo,'otp_debug_return_code','0')==='1'))$response['debug_code']=$code;rado_json(200,$response);
    }

    rado_json(404,['ok'=>false,'error'=>'unknown_platform_action']);
} catch(Throwable $e) {
    $id=substr(bin2hex(random_bytes(8)),0,12);try{$pdo->prepare('INSERT INTO health_events(component,severity,code,message,context_json) VALUES(\'platform\',\'error\',\'API_EXCEPTION\',?,?)')->execute([$e->getMessage(),json_encode(['request_id'=>$id,'action'=>$action],JSON_UNESCAPED_UNICODE)]);}catch(Throwable){}
    rado_json(500,['ok'=>false,'error'=>'platform_error','request_id'=>$id,'message'=>'عملیات پلتفرم RADO انجام نشد.']);
}
