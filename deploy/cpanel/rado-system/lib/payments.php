<?php
declare(strict_types=1);
if(!function_exists('rado_db'))require __DIR__.'/app.php';
require_once __DIR__.'/platform.php';

function rado_trip_payment_preferences(PDO $pdo,string $tripId):array{
  $stmt=$pdo->prepare('SELECT payment_method,corporate_account_id,original_fare,discount_amount FROM trip_preferences WHERE trip_id=? LIMIT 1');$stmt->execute([$tripId]);$r=$stmt->fetch();return is_array($r)?$r:['payment_method'=>'cash','corporate_account_id'=>null,'original_fare'=>null,'discount_amount'=>0];
}
function rado_settle_passenger_payment(PDO $pdo,array $trip):array{
  $tripId=(string)$trip['id'];$passengerId=(string)$trip['passenger_id'];$fare=(int)($trip['final_fare']??$trip['estimated_fare']??0);$pref=rado_trip_payment_preferences($pdo,$tripId);$method=(string)($pref['payment_method']??'cash');$status='paid';$message='';
  if($method==='wallet'){
    $balance=rado_wallet_balance($pdo,$passengerId);
    if($balance<$fare){$status='failed';$message='موجودی کیف پول هنگام پایان سفر کافی نبود.';}
    else{$key='trip:'.$tripId.':passenger_wallet';$ins=$pdo->prepare("INSERT IGNORE INTO ledger_entries(user_id,trip_id,entry_type,amount,balance_after,idempotency_key,metadata_json) VALUES(?,?,'trip_payment',?,?,?,?,?)");$ins->execute([$passengerId,$tripId,-$fare,$balance-$fare,$key,json_encode(['method'=>'wallet','fare'=>$fare],JSON_UNESCAPED_UNICODE)]);if($ins->rowCount()>0)rado_sync_wallet_cache($pdo,$passengerId);}
  }elseif($method==='corporate'){
    $corp=(int)($pref['corporate_account_id']??0);if($corp<1){$status='failed';$message='حساب سازمانی مشخص نیست.';}else{$q=$pdo->prepare('SELECT billing_mode,balance,credit_limit FROM corporate_accounts WHERE id=? AND active=1 FOR UPDATE');$q->execute([$corp]);$c=$q->fetch();if(!is_array($c)){$status='failed';$message='حساب سازمانی در دسترس نیست.';}else{$new=(int)$c['balance']-$fare;if($c['billing_mode']==='prepaid'&&$new<0){$status='failed';$message='اعتبار حساب سازمانی کافی نیست.';}elseif($c['billing_mode']==='postpaid'&&abs(min(0,$new))>(int)$c['credit_limit']){$status='failed';$message='سقف اعتبار حساب سازمانی تکمیل شده است.';}else{$pdo->prepare('UPDATE corporate_accounts SET balance=? WHERE id=?')->execute([$new,$corp]);}}}
  }elseif($method==='online'){
    $q=$pdo->prepare("SELECT status FROM trip_payments WHERE trip_id=? AND method='online' ORDER BY id DESC LIMIT 1");$q->execute([$tripId]);$paid=$q->fetchColumn();if($paid!=='paid'){$status='pending';$message='پرداخت آنلاین هنوز تأیید نشده است.';}
  }
  $key='trip:'.$tripId.':payment:'.$method;
  $stmt=$pdo->prepare('INSERT INTO trip_payments(trip_id,passenger_id,method,amount,status,idempotency_key,paid_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=IF(status=\'paid\',status,VALUES(status)),paid_at=COALESCE(paid_at,VALUES(paid_at))');
  $stmt->execute([$tripId,$passengerId,$method,$fare,$status,$key,$status==='paid'?radoPaymentNow():null]);
  return ['method'=>$method,'amount'=>$fare,'status'=>$status,'message'=>$message];
}
function rado_award_trip_loyalty(PDO $pdo,array $trip):int{
  $points=max(0,(int)(rado_setting($pdo,'loyalty_points_per_trip','10')??'10'));if($points===0)return 0;$userId=(string)$trip['passenger_id'];$tripId=(string)$trip['id'];$key='trip:'.$tripId.':loyalty';$stmt=$pdo->prepare('INSERT IGNORE INTO loyalty_ledger(user_id,points,reason,trip_id,idempotency_key) VALUES(?,?,\'completed_trip\',?,?)');$stmt->execute([$userId,$points,$tripId,$key]);if($stmt->rowCount()>0)rado_platform_notify($pdo,$userId,'امتیاز سفر','از این سفر '.$points.' امتیاز RADO گرفتی.','loyalty',['trip_id'=>$tripId,'points'=>$points]);return $stmt->rowCount()>0?$points:0;
}
function rado_finalize_referral(PDO $pdo,string $passengerId):int{
  $reward=max(0,(int)(rado_setting($pdo,'referral_reward_amount','0')??'0'));if($reward===0)return 0;$q=$pdo->prepare('SELECT id,referrer_user_id,rewarded_at FROM referrals WHERE referred_user_id=? LIMIT 1');$q->execute([$passengerId]);$r=$q->fetch();if(!is_array($r)||$r['rewarded_at']!==null)return 0;$key='referral:'.$r['id'].':reward';$balance=rado_wallet_balance($pdo,(string)$r['referrer_user_id']);$ins=$pdo->prepare("INSERT IGNORE INTO ledger_entries(user_id,entry_type,amount,balance_after,idempotency_key,metadata_json) VALUES(?,'referral_reward',?,?,?,?)");$ins->execute([(string)$r['referrer_user_id'],$reward,$balance+$reward,$key,json_encode(['referred_user_id'=>$passengerId],JSON_UNESCAPED_UNICODE)]);if($ins->rowCount()>0){$pdo->prepare('UPDATE referrals SET rewarded_at=NOW() WHERE id=? AND rewarded_at IS NULL')->execute([(int)$r['id']]);rado_sync_wallet_cache($pdo,(string)$r['referrer_user_id']);return $reward;}return 0;
}
function rado_complete_platform_finance(PDO $pdo,array $trip):array{
  $payment=rado_settle_passenger_payment($pdo,$trip);$points=rado_award_trip_loyalty($pdo,$trip);$referral=rado_finalize_referral($pdo,(string)$trip['passenger_id']);$tripId=(string)$trip['id'];rado_platform_notify($pdo,(string)$trip['passenger_id'],'سفر پایان یافت','سفر RADO با مبلغ '.number_format((int)($trip['final_fare']??$trip['estimated_fare']??0)).' ریال پایان یافت.','trip_completed',['trip_id'=>$tripId]);if(!empty($trip['driver_id']))rado_platform_notify($pdo,(string)$trip['driver_id'],'سفر پایان یافت','درآمد و کمیسیون سفر در کیف پول راننده ثبت شد.','trip_completed',['trip_id'=>$tripId]);rado_platform_event($pdo,'trip:'.$tripId,'completed',['payment'=>$payment,'loyalty_points'=>$points]);return ['payment'=>$payment,'loyalty_points'=>$points,'referral_reward'=>$referral];
}
function radoPaymentNow():string{return(new DateTimeImmutable('now',rado_tehran_timezone()))->format('Y-m-d H:i:s');}
