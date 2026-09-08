<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$root=dirname(__DIR__,2);require $root.'/rado-system/lib/app.php';
try{
  $pdo=rado_db();
  $pdo->beginTransaction();
  $pdo->exec("UPDATE driver_presence SET is_online=0 WHERE is_online=1 AND (last_seen_at IS NULL OR last_seen_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE))");
  $pdo->exec("UPDATE driver_documents SET status='expired' WHERE expires_at IS NOT NULL AND expires_at<CURDATE() AND status='approved'");
  $pdo->exec("DELETE FROM realtime_events WHERE expires_at<NOW()");
  $pdo->exec("UPDATE trip_offers SET accepted=0,responded_at=COALESCE(responded_at,NOW()) WHERE accepted IS NULL AND expires_at<NOW()");
  $pdo->commit();

  $stmt=$pdo->query("SELECT t.id,t.pickup_lat,t.pickup_lng FROM trips t JOIN trip_preferences p ON p.trip_id=t.id WHERE t.status='requested' AND p.scheduled_for IS NOT NULL AND p.scheduled_for<=DATE_ADD(NOW(),INTERVAL 2 MINUTE) ORDER BY p.scheduled_for LIMIT 30");
  foreach($stmt->fetchAll() as $row){
    $claim=$pdo->prepare("UPDATE trips SET status='searching',version=version+1 WHERE id=? AND status='requested'");$claim->execute([(string)$row['id']]);
    if($claim->rowCount()===1){$n=rado_dispatch_trip($pdo,(string)$row['id'],(float)$row['pickup_lat'],(float)$row['pickup_lng']);try{$pdo->prepare('INSERT INTO realtime_events(channel,event_type,payload_json,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 2 HOUR))')->execute(['trip:'.$row['id'],'scheduled_dispatched',json_encode(['drivers_notified'=>$n],JSON_UNESCAPED_UNICODE)]);}catch(Throwable){}}
  }

  // Mission progress is derived from completed trips and driver ledger activity.
  $missions=$pdo->query("SELECT * FROM driver_missions WHERE active=1 AND starts_at<=NOW() AND ends_at>NOW()")->fetchAll();
  foreach($missions as $m){
    $drivers=$pdo->prepare("SELECT DISTINCT driver_id FROM trips WHERE driver_id IS NOT NULL AND completed_at BETWEEN ? AND ?");$drivers->execute([$m['starts_at'],$m['ends_at']]);
    foreach($drivers->fetchAll(PDO::FETCH_COLUMN) as $driverId){
      $value=0;
      if($m['target_type']==='trips'){$q=$pdo->prepare("SELECT COUNT(*) FROM trips WHERE driver_id=? AND status='completed' AND completed_at BETWEEN ? AND ?");$q->execute([$driverId,$m['starts_at'],$m['ends_at']]);$value=(int)$q->fetchColumn();}
      elseif($m['target_type']==='revenue'){$q=$pdo->prepare("SELECT COALESCE(SUM(final_fare),0) FROM trips WHERE driver_id=? AND status='completed' AND completed_at BETWEEN ? AND ?");$q->execute([$driverId,$m['starts_at'],$m['ends_at']]);$value=(int)$q->fetchColumn();}
      elseif($m['target_type']==='online_minutes'){$value=0;}
      $complete=$value>=(int)$m['target_value'];
      $pdo->prepare('INSERT INTO driver_mission_progress(mission_id,driver_id,progress_value,completed_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE progress_value=VALUES(progress_value),completed_at=COALESCE(completed_at,VALUES(completed_at))')->execute([(int)$m['id'],$driverId,$value,$complete?platformNowCompat():null]);
      if($complete){
        $p=$pdo->prepare('SELECT rewarded_at FROM driver_mission_progress WHERE mission_id=? AND driver_id=?');$p->execute([(int)$m['id'],$driverId]);$rewarded=$p->fetchColumn();
        if($rewarded===null||$rewarded===false){$key='mission:'.$m['id'].':driver:'.$driverId;$ins=$pdo->prepare("INSERT IGNORE INTO ledger_entries(user_id,entry_type,amount,idempotency_key,metadata_json) VALUES(?,'mission_reward',?,?,?)");$ins->execute([$driverId,(int)$m['reward_amount'],$key,json_encode(['mission_id'=>(int)$m['id']],JSON_UNESCAPED_UNICODE)]);if($ins->rowCount()===1){$pdo->prepare('INSERT INTO user_wallets(user_id,balance) VALUES(?,?) ON DUPLICATE KEY UPDATE balance=balance+VALUES(balance)')->execute([$driverId,(int)$m['reward_amount']]);$pdo->prepare('UPDATE driver_mission_progress SET rewarded_at=NOW() WHERE mission_id=? AND driver_id=?')->execute([(int)$m['id'],$driverId]);}}
      }
    }
  }
  echo 'RADO tick OK '.rado_jalali_datetime(null,true)."\n";
}catch(Throwable $e){fwrite(STDERR,'RADO tick failed: '.$e->getMessage()."\n");exit(1);}
function platformNowCompat(): string{return (new DateTimeImmutable('now',rado_tehran_timezone()))->format('Y-m-d H:i:s');}
