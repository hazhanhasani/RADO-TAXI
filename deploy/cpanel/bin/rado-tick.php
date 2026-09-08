<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$root=dirname(__DIR__,2);require $root.'/rado-system/lib/app.php';require_once $root.'/rado-system/lib/platform.php';
try{
  $pdo=rado_db();
  $pdo->beginTransaction();
  // Credit up to five online minutes per platform tick to drivers with a fresh heartbeat.
  $online=$pdo->query("SELECT driver_id FROM driver_presence WHERE is_online=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 6 MINUTE)")->fetchAll(PDO::FETCH_COLUMN);
  foreach($online as $driverId){$pdo->prepare('INSERT INTO driver_online_daily(driver_id,activity_date,online_minutes) VALUES(?,CURDATE(),5) ON DUPLICATE KEY UPDATE online_minutes=LEAST(1440,online_minutes+5)')->execute([(string)$driverId]);}
  $pdo->exec("UPDATE driver_presence SET is_online=0 WHERE is_online=1 AND (last_seen_at IS NULL OR last_seen_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE))");
  $pdo->exec("UPDATE driver_documents SET status='expired' WHERE expires_at IS NOT NULL AND expires_at<CURDATE() AND status='approved'");
  $pdo->exec("DELETE FROM realtime_events WHERE expires_at<NOW()");
  $pdo->exec("UPDATE trip_offers SET accepted=0,responded_at=COALESCE(responded_at,NOW()) WHERE accepted IS NULL AND expires_at<NOW()");
  $pdo->commit();

  // Scheduled rides enter dispatch shortly before their requested time.
  $stmt=$pdo->query("SELECT t.id,t.pickup_lat,t.pickup_lng FROM trips t JOIN trip_preferences p ON p.trip_id=t.id WHERE t.status='requested' AND p.scheduled_for IS NOT NULL AND p.scheduled_for<=DATE_ADD(NOW(),INTERVAL 2 MINUTE) ORDER BY p.scheduled_for LIMIT 30");
  foreach($stmt->fetchAll() as $row){$claim=$pdo->prepare("UPDATE trips SET status='searching',version=version+1 WHERE id=? AND status='requested'");$claim->execute([(string)$row['id']]);if($claim->rowCount()===1){$n=rado_dispatch_trip_v2($pdo,(string)$row['id'],(float)$row['pickup_lat'],(float)$row['pickup_lng']);rado_platform_event($pdo,'trip:'.$row['id'],'scheduled_dispatched',['drivers_notified'=>$n]);}}

  // Re-dispatch searching trips every tick. rado_dispatch_trip_v2 expands radius by trip age and never offers twice to the same driver.
  $waiting=$pdo->query("SELECT id,pickup_lat,pickup_lng FROM trips WHERE status='searching' AND requested_at>=DATE_SUB(NOW(),INTERVAL 20 MINUTE) ORDER BY requested_at ASC LIMIT 100")->fetchAll();
  foreach($waiting as $row)rado_dispatch_trip_v2($pdo,(string)$row['id'],(float)$row['pickup_lat'],(float)$row['pickup_lng']);
  $pdo->exec("UPDATE trips SET status='expired',version=version+1 WHERE status='searching' AND requested_at<DATE_SUB(NOW(),INTERVAL 20 MINUTE)");

  // Mission progress is derived from completed trips, revenue, or accumulated online minutes.
  $missions=$pdo->query("SELECT * FROM driver_missions WHERE active=1 AND starts_at<=NOW() AND ends_at>NOW()")->fetchAll();
  foreach($missions as $m){
    $drivers=$pdo->query("SELECT user_id FROM drivers WHERE status='approved'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($drivers as $driverId){
      $value=0;
      if($m['target_type']==='trips'){$q=$pdo->prepare("SELECT COUNT(*) FROM trips WHERE driver_id=? AND status='completed' AND completed_at BETWEEN ? AND ?");$q->execute([$driverId,$m['starts_at'],$m['ends_at']]);$value=(int)$q->fetchColumn();}
      elseif($m['target_type']==='revenue'){$q=$pdo->prepare("SELECT COALESCE(SUM(final_fare),0) FROM trips WHERE driver_id=? AND status='completed' AND completed_at BETWEEN ? AND ?");$q->execute([$driverId,$m['starts_at'],$m['ends_at']]);$value=(int)$q->fetchColumn();}
      elseif($m['target_type']==='online_minutes'){$q=$pdo->prepare('SELECT COALESCE(SUM(online_minutes),0) FROM driver_online_daily WHERE driver_id=? AND activity_date BETWEEN DATE(?) AND DATE(?)');$q->execute([$driverId,$m['starts_at'],$m['ends_at']]);$value=(int)$q->fetchColumn();}
      $complete=$value>=(int)$m['target_value'];
      $pdo->prepare('INSERT INTO driver_mission_progress(mission_id,driver_id,progress_value,completed_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE progress_value=VALUES(progress_value),completed_at=COALESCE(completed_at,VALUES(completed_at))')->execute([(int)$m['id'],$driverId,$value,$complete?radoTickNow():null]);
      if($complete){$p=$pdo->prepare('SELECT rewarded_at FROM driver_mission_progress WHERE mission_id=? AND driver_id=?');$p->execute([(int)$m['id'],$driverId]);$rewarded=$p->fetchColumn();if($rewarded===null||$rewarded===false){$key='mission:'.$m['id'].':driver:'.$driverId;$ins=$pdo->prepare("INSERT IGNORE INTO ledger_entries(user_id,entry_type,amount,idempotency_key,metadata_json) VALUES(?,'mission_reward',?,?,?)");$ins->execute([$driverId,(int)$m['reward_amount'],$key,json_encode(['mission_id'=>(int)$m['id']],JSON_UNESCAPED_UNICODE)]);if($ins->rowCount()===1){rado_sync_wallet_cache($pdo,(string)$driverId);$pdo->prepare('UPDATE driver_mission_progress SET rewarded_at=NOW() WHERE mission_id=? AND driver_id=?')->execute([(int)$m['id'],$driverId]);rado_platform_notify($pdo,(string)$driverId,'پاداش مأموریت','پاداش مأموریت «'.$m['title'].'» به کیف پول شما اضافه شد.','mission_reward',['mission_id'=>(int)$m['id']]);}}}
    }
  }
  echo 'RADO tick OK '.rado_jalali_datetime(null,true)."\n";
}catch(Throwable $e){fwrite(STDERR,'RADO tick failed: '.$e->getMessage()."\n");exit(1);}
function radoTickNow():string{return(new DateTimeImmutable('now',rado_tehran_timezone()))->format('Y-m-d H:i:s');}
