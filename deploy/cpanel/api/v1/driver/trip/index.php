<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/platform.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/payments.php';

try {
    $pdo=rado_db();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $clientId=trim((string)($_GET['client_id']??''));$driver=rado_require_approved_driver($pdo,$clientId);$driverId=(string)$driver['id'];$stmt=$pdo->prepare("SELECT id FROM trips WHERE driver_id=? AND status IN ('driver_assigned','driver_arriving','arrived','in_progress') ORDER BY accepted_at DESC LIMIT 1");$stmt->execute([$driverId]);$tripId=$stmt->fetchColumn();if($tripId===false)rado_json(200,['ok'=>true,'trip'=>null,'server_time'=>rado_time_payload()]);$row=rado_trip_row($pdo,(string)$tripId);rado_json(200,['ok'=>true,'trip'=>$row?rado_trip_payload($row):null,'server_time'=>rado_time_payload()]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);
    $body=rado_body();$clientId=trim((string)($body['client_id']??''));$tripId=trim((string)($body['trip_id']??''));$action=trim((string)($body['action']??''));$reason=trim((string)($body['reason']??''));if($tripId===''||!in_array($action,['arrived','start','complete','cancel'],true))rado_json(422,['ok'=>false,'error'=>'invalid_trip_action']);
    $driver=rado_require_approved_driver($pdo,$clientId);$driverId=(string)$driver['id'];
    $pdo->beginTransaction();$stmt=$pdo->prepare('SELECT * FROM trips WHERE id=? FOR UPDATE');$stmt->execute([$tripId]);$trip=$stmt->fetch();if(!is_array($trip)){$pdo->rollBack();rado_json(404,['ok'=>false,'error'=>'trip_not_found']);}if((string)($trip['driver_id']??'')!==$driverId){$pdo->rollBack();rado_json(403,['ok'=>false,'error'=>'trip_not_owned_by_driver']);}
    $status=(string)$trip['status'];$finance=null;$platformFinance=null;
    if($action==='arrived'){
        if(!in_array($status,['driver_assigned','driver_arriving'],true)){$pdo->rollBack();rado_json(409,['ok'=>false,'error'=>'invalid_transition','message'=>'در این مرحله امکان ثبت «رسیدم» وجود ندارد.']);}
        $pdo->prepare("UPDATE trips SET status='arrived',arrived_at=NOW(),version=version+1 WHERE id=?")->execute([$tripId]);rado_platform_event($pdo,'trip:'.$tripId,'arrived',['driver_id'=>$driverId]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'راننده رسید','راننده RADO به مبدا رسیده است.','driver_arrived',['trip_id'=>$tripId]);
    }
    if($action==='start'){
        if($status!=='arrived'){$pdo->rollBack();rado_json(409,['ok'=>false,'error'=>'invalid_transition','message'=>'ابتدا باید رسیدن به مبدا ثبت شود.']);}
        $pdo->prepare("UPDATE trips SET status='in_progress',started_at=NOW(),version=version+1 WHERE id=?")->execute([$tripId]);rado_platform_event($pdo,'trip:'.$tripId,'started',['driver_id'=>$driverId]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'سفر شروع شد','سفر شما با RADO شروع شد.','trip_started',['trip_id'=>$tripId]);
    }
    if($action==='complete'){
        if($status!=='in_progress'){
            if($status==='completed'){$pdo->commit();$row=rado_trip_row($pdo,$tripId);rado_json(200,['ok'=>true,'trip'=>$row?rado_trip_payload($row):null,'already_completed'=>true]);}
            $pdo->rollBack();rado_json(409,['ok'=>false,'error'=>'invalid_transition','message'=>'فقط سفر در حال انجام را می‌توان پایان داد.']);
        }
        $finalFare=(int)($trip['estimated_fare']??0);$pdo->prepare("UPDATE trips SET status='completed',final_fare=?,completed_at=NOW(),version=version+1 WHERE id=?")->execute([$finalFare,$tripId]);$trip['final_fare']=$finalFare;$trip['commission_rate']=(float)($driver['commission_rate']??0);$finance=rado_complete_trip_finance($pdo,$trip);rado_sync_wallet_cache($pdo,$driverId);$platformFinance=rado_complete_platform_finance($pdo,$trip);rado_platform_event($pdo,'trip:'.$tripId,'completed',['driver_id'=>$driverId,'fare'=>$finalFare]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'سفر پایان یافت','سفر RADO پایان یافت. می‌توانید به راننده امتیاز بدهید.','trip_completed',['trip_id'=>$tripId]);
    }
    if($action==='cancel'){
        if(!in_array($status,['driver_assigned','driver_arriving','arrived'],true)){$pdo->rollBack();rado_json(409,['ok'=>false,'error'=>'invalid_transition','message'=>'در این مرحله امکان لغو سفر وجود ندارد.']);}
        $reason=$reason!==''?$reason:'لغو توسط راننده';$pdo->prepare("UPDATE trips SET status='cancelled_by_driver',cancelled_at=NOW(),cancellation_reason=?,version=version+1 WHERE id=?")->execute([$reason,$tripId]);$pdo->prepare("INSERT INTO trip_cancellation_events(trip_id,actor_user_id,actor_role,reason_code,reason_text) VALUES(?,?,'driver','driver_cancel',?)")->execute([$tripId,$driverId,$reason]);rado_platform_event($pdo,'trip:'.$tripId,'cancelled',['by'=>'driver','reason'=>$reason]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'سفر لغو شد','راننده سفر را لغو کرد؛ RADO دوباره می‌تواند برای شما راننده پیدا کند.','trip_cancelled',['trip_id'=>$tripId]);
    }
    $pdo->commit();$row=rado_trip_row($pdo,$tripId);rado_json(200,['ok'=>true,'trip'=>$row?rado_trip_payload($row):null,'finance'=>$finance,'platform_finance'=>$platformFinance,'server_time'=>rado_time_payload()]);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$id=substr(bin2hex(random_bytes(8)),0,12);try{$pdo->prepare("INSERT INTO health_events(component,severity,code,message,context_json) VALUES('driver_trip','error','TRIP_ACTION_FAILED',?,?)")->execute([$e->getMessage(),json_encode(['request_id'=>$id],JSON_UNESCAPED_UNICODE)]);}catch(Throwable){}rado_json(500,['ok'=>false,'error'=>'driver_trip_action_failed','request_id'=>$id,'message'=>'عملیات سفر راننده انجام نشد.']);}
