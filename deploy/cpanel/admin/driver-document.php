<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
$id=(int)($_GET['id']??0);
if($id<1){http_response_code(404);exit('Not found');}
$stmt=$pdo->prepare('SELECT file_path,document_type FROM driver_documents WHERE id=? LIMIT 1');
$stmt->execute([$id]);$row=$stmt->fetch();
if(!is_array($row)||trim((string)($row['file_path']??''))===''){http_response_code(404);exit('Not found');}
$relative=ltrim((string)$row['file_path'],'/');
$base=realpath(dirname(__DIR__).'/rado-system/private');
$path=realpath(dirname(__DIR__).'/rado-system/private/'.$relative);
if($base===false||$path===false||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path)){http_response_code(404);exit('Not found');}
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($path);
$allowed=['image/jpeg','image/png','image/webp','application/pdf'];
if(!in_array($mime,$allowed,true)){http_response_code(415);exit('Unsupported');}
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="rado-driver-document-'.$id.'.'.($mime==='application/pdf'?'pdf':'jpg').'"');
readfile($path);
