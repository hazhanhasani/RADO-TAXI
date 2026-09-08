<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';

$pdo=rado_db();
$method=$_SERVER['REQUEST_METHOD']??'GET';
$clientId=trim((string)($_GET['client_id']??$_POST['client_id']??''));
$driver=rado_driver_from_client($pdo,$clientId);
if(!$driver)rado_json(403,['ok'=>false,'error'=>'driver_not_found']);
$driverId=(string)$driver['id'];

if($method==='GET'){
  $stmt=$pdo->prepare('SELECT id,document_type,document_number,expires_at,status,note,created_at,updated_at FROM driver_documents WHERE driver_id=? ORDER BY id DESC');
  $stmt->execute([$driverId]);$rows=$stmt->fetchAll();
  foreach($rows as &$r){$r['created_at_jalali']=rado_jalali_datetime((string)$r['created_at']);$r['expires_at_jalali']=$r['expires_at']?rado_jalali_date((string)$r['expires_at']):null;unset($r['created_at'],$r['updated_at']);}
  rado_json(200,['ok'=>true,'documents'=>$rows]);
}
if($method!=='POST')rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);
$type=(string)($_POST['document_type']??'other');
if(!in_array($type,['national_card','driver_license','vehicle_card','insurance','inspection','other'],true))rado_json(422,['ok'=>false,'error'=>'invalid_document_type']);
$number=mb_substr(trim((string)($_POST['document_number']??'')),0,120,'UTF-8');
$expires=trim((string)($_POST['expires_at']??''));
if($expires!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expires))rado_json(422,['ok'=>false,'error'=>'invalid_expiry']);
if(!isset($_FILES['document'])||($_FILES['document']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)rado_json(422,['ok'=>false,'error'=>'document_file_required']);
$file=$_FILES['document'];$size=(int)($file['size']??0);if($size<1||$size>8*1024*1024)rado_json(413,['ok'=>false,'error'=>'document_size_invalid','message'=>'حجم مدرک باید کمتر از ۸ مگابایت باشد.']);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file((string)$file['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];if(!isset($allowed[$mime]))rado_json(415,['ok'=>false,'error'=>'document_type_not_allowed']);
$dir=rado_root().'/rado-system/private/driver-documents/'.$driverId;@mkdir($dir,0700,true);$name=$type.'-'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];$dest=$dir.'/'.$name;
if(!move_uploaded_file((string)$file['tmp_name'],$dest))rado_json(500,['ok'=>false,'error'=>'document_store_failed']);@chmod($dest,0600);
$stmt=$pdo->prepare("INSERT INTO driver_documents(driver_id,document_type,file_path,document_number,expires_at,status) VALUES(?,?,?,?,?,'pending')");$stmt->execute([$driverId,$type,'driver-documents/'.$driverId.'/'.$name,$number?:null,$expires?:null]);$id=(int)$pdo->lastInsertId();
try{$pdo->prepare('INSERT INTO audit_logs(actor_user_id,actor_role,action,entity_type,entity_id,after_json,ip_address) VALUES(?,\'driver\',\'document_upload\',\'driver_document\',?,?,?)')->execute([$driverId,(string)$id,json_encode(['type'=>$type,'mime'=>$mime,'size'=>$size],JSON_UNESCAPED_UNICODE),(string)($_SERVER['REMOTE_ADDR']??'')]);}catch(Throwable){}
rado_json(201,['ok'=>true,'id'=>$id,'status'=>'pending','created_at'=>rado_time_payload()]);
