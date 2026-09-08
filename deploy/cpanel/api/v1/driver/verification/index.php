<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/rado-system/lib/app.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/api_ir.php';
require_once dirname(__DIR__, 4) . '/rado-system/lib/platform.php';

$pdo = rado_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$input = $method === 'POST' && str_contains($contentType, 'multipart/form-data') ? $_POST : ($method === 'POST' ? rado_body() : $_GET);
$clientId = trim((string)($input['client_id'] ?? $_GET['client_id'] ?? $_POST['client_id'] ?? ''));
if ($clientId === '') rado_json(422,['ok'=>false,'error'=>'client_id_required']);
$driver = rado_driver_from_client($pdo,$clientId);
if (!$driver) $driver = rado_guest_driver($pdo,$clientId);
$driverId = (string)$driver['id'];
rado_verification_profile($pdo,$driverId);

if ($method === 'GET') {
    rado_json(200,['ok'=>true,'verification'=>rado_verification_summary($pdo,$driverId),'server_time'=>rado_time_payload()]);
}
if ($method !== 'POST') rado_json(405,['ok'=>false,'error'=>'method_not_allowed']);
$action = trim((string)($input['action'] ?? ''));

function rv_fail(int $status, string $error, string $message): never {
    rado_json($status,['ok'=>false,'error'=>$error,'message'=>$message]);
}
function rv_profile(PDO $pdo, string $driverId): array { return rado_verification_profile($pdo,$driverId); }
function rv_digits(string $value): string { return preg_replace('/\D+/', '', $value) ?? ''; }
function rv_check_prereq(array $p, array $fields): void {
    foreach ($fields as $key=>$label) if (trim((string)($p[$key] ?? '')) === '') rv_fail(422,'verification_profile_incomplete',$label.' را تکمیل کنید.');
}
function rv_api_result(PDO $pdo, string $driverId, string $checkType, string $column, array $result, bool $passed, array $summary = [], string $failedMessage = 'استعلام تأیید نشد.'): void {
    $status = $passed ? 'passed' : (($result['ok'] ?? false) ? 'failed' : 'error');
    rado_verification_set_check($pdo,$driverId,$column,$status,$passed ? null : ($failedMessage . (($result['error'] ?? '') !== '' ? ' — '.$result['error'] : '')));
    rado_verification_log_check($pdo,$driverId,$checkType,$status,$result,$summary);
}

if ($action === 'save_profile') {
    $old = rv_profile($pdo,$driverId);
    $fullName = trim((string)($input['full_name'] ?? ''));
    $mobile = rado_normalize_mobile((string)($input['mobile'] ?? ''));
    $national = rv_digits((string)($input['national_code'] ?? ''));
    $birth = trim((string)($input['birth_date_jalali'] ?? ''));
    $serial = trim((string)($input['national_card_serial'] ?? ''));
    $license = trim((string)($input['license_number'] ?? ''));
    $iban = rado_normalize_iban((string)($input['iban'] ?? ''));
    $ownerNational = rv_digits((string)($input['vehicle_owner_national_code'] ?? ''));
    $relation = trim((string)($input['vehicle_owner_relation'] ?? 'self'));
    $p1 = trim((string)($input['plate_part1'] ?? ''));
    $letter = trim((string)($input['plate_letter'] ?? ''));
    $p2 = trim((string)($input['plate_part2'] ?? ''));
    $p3 = trim((string)($input['plate_part3'] ?? ''));
    $make = trim((string)($input['vehicle_make'] ?? ''));
    $model = trim((string)($input['vehicle_model'] ?? ''));
    $color = trim((string)($input['vehicle_color'] ?? ''));

    if (mb_strlen($fullName,'UTF-8') < 3 || mb_strlen($fullName,'UTF-8') > 160) rv_fail(422,'invalid_full_name','نام و نام خانوادگی معتبر وارد کنید.');
    if (!preg_match('/^09\d{9}$/',$mobile)) rv_fail(422,'invalid_mobile','شماره موبایل باید با 09 و 11 رقم باشد.');
    if (!rado_national_code_valid($national)) rv_fail(422,'invalid_national_code','کد ملی معتبر نیست.');
    if (!preg_match('/^(13|14)\d{2}\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/',$birth)) rv_fail(422,'invalid_birth_date','تاریخ تولد را به شکل 1370/1/1 وارد کنید.');
    if (mb_strlen($serial,'UTF-8') < 3 || mb_strlen($serial,'UTF-8') > 48) rv_fail(422,'invalid_national_card_serial','سریال کارت ملی/کد رهگیری معتبر نیست.');
    if (mb_strlen($license,'UTF-8') < 4 || mb_strlen($license,'UTF-8') > 64) rv_fail(422,'invalid_license_number','شماره گواهینامه معتبر نیست.');
    if (!preg_match('/^IR\d{24}$/',$iban)) rv_fail(422,'invalid_iban','شماره شبا باید با IR و 24 رقم ادامه پیدا کند.');
    if (!rado_national_code_valid($ownerNational)) rv_fail(422,'invalid_vehicle_owner_national_code','کد ملی مالک خودرو معتبر نیست.');
    if (!in_array($relation,['self','family','other'],true)) $relation='other';
    if ($p1==='' || $letter==='' || $p2==='' || $p3==='') rv_fail(422,'invalid_plate','اجزای پلاک خودرو را کامل وارد کنید.');

    $dup=$pdo->prepare('SELECT driver_id FROM driver_verification_profiles WHERE mobile=? AND driver_id<>? AND mobile_verified_at IS NOT NULL LIMIT 1');
    $dup->execute([$mobile,$driverId]);
    if ($dup->fetchColumn()) rv_fail(409,'mobile_already_verified','این شماره موبایل قبلاً برای راننده دیگری تأیید شده است.');

    $pdo->prepare('UPDATE driver_verification_profiles SET full_name=?,mobile=?,national_code=?,birth_date_jalali=?,national_card_serial=?,license_number=?,iban=?,vehicle_owner_national_code=?,vehicle_owner_relation=?,plate_part1=?,plate_letter=?,plate_part2=?,plate_part3=?,vehicle_make=?,vehicle_model=?,vehicle_color=? WHERE driver_id=?')
        ->execute([$fullName,$mobile,$national,$birth,$serial,$license,$iban,$ownerNational,$relation,$p1,$letter,$p2,$p3,$make?:null,$model?:null,$color?:null,$driverId]);
    $pdo->prepare("UPDATE users SET full_name=? WHERE id=? AND role='driver'")->execute([$fullName,$driverId]);

    if ((string)($old['mobile'] ?? '') !== $mobile) {
        $pdo->prepare("UPDATE driver_verification_profiles SET mobile_verified_at=NULL,shahkar_status='not_started',license_status='not_started',driving_score_status='not_started',active_plates_status='not_started' WHERE driver_id=?")->execute([$driverId]);
    }
    if ((string)($old['national_code'] ?? '') !== $national || (string)($old['birth_date_jalali'] ?? '') !== $birth || (string)($old['national_card_serial'] ?? '') !== $serial) {
        $pdo->prepare("UPDATE driver_verification_profiles SET shahkar_status='not_started',biometric_status='not_started',license_status='not_started',driving_score_status='not_started',active_plates_status='not_started',iban_status='not_started' WHERE driver_id=?")->execute([$driverId]);
    }
    if ((string)($old['license_number'] ?? '') !== $license) {
        $pdo->prepare("UPDATE driver_verification_profiles SET license_status='not_started',driving_score_status='not_started' WHERE driver_id=?")->execute([$driverId]);
    }
    if ((string)($old['iban'] ?? '') !== $iban) $pdo->prepare("UPDATE driver_verification_profiles SET iban_status='not_started' WHERE driver_id=?")->execute([$driverId]);
    if ((string)($old['vehicle_owner_national_code'] ?? '') !== $ownerNational || (string)($old['plate_part1'] ?? '') !== $p1 || (string)($old['plate_letter'] ?? '') !== $letter || (string)($old['plate_part2'] ?? '') !== $p2 || (string)($old['plate_part3'] ?? '') !== $p3) {
        $pdo->prepare("UPDATE driver_verification_profiles SET vehicle_status='not_started',active_plates_status='not_started' WHERE driver_id=?")->execute([$driverId]);
    }
    rado_json(200,['ok'=>true,'verification'=>rado_verification_summary($pdo,$driverId)]);
}

if ($action === 'send_otp' || $action === 'send_voice_otp') {
    $p=rv_profile($pdo,$driverId); rv_check_prereq($p,['mobile'=>'شماره موبایل']);
    $mobile=rado_normalize_mobile((string)$p['mobile']);
    $recent=$pdo->prepare("SELECT created_at FROM otp_challenges WHERE phone=? AND purpose='driver_verify' ORDER BY id DESC LIMIT 1");$recent->execute([$mobile]);$last=$recent->fetchColumn();
    if ($last && time()-strtotime((string)$last)<60) rv_fail(429,'otp_rate_limited','برای ارسال دوباره کد یک دقیقه صبر کنید.');
    $hour=$pdo->prepare("SELECT COUNT(*) FROM otp_challenges WHERE phone=? AND purpose='driver_verify' AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");$hour->execute([$mobile]);
    if ((int)$hour->fetchColumn()>=5) rv_fail(429,'otp_hourly_limit','تعداد درخواست کد بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.');
    $code=(string)random_int(100000,999999);
    $service=$action==='send_voice_otp'?'CallOTP':'SmsOTP';
    $payload=$service==='SmsOTP'?['code'=>$code,'mobile'=>$mobile,'template'=>2]:['code'=>$code,'number'=>$mobile];
    $result=rado_api_ir_call($service,$payload,20);
    if (!$result['ok'] || $result['data'] !== true) {
        rado_verification_log_check($pdo,$driverId,$action==='send_voice_otp'?'voice_otp':'sms_otp','error',$result,[]);
        rv_fail(502,'otp_send_failed','ارسال کد تأیید از API.ir انجام نشد.');
    }
    $pdo->prepare("INSERT INTO otp_challenges(phone,purpose,code_hash,attempts,expires_at) VALUES(?,'driver_verify',?,0,DATE_ADD(NOW(),INTERVAL 3 MINUTE))")->execute([$mobile,password_hash($code,PASSWORD_DEFAULT)]);
    rado_verification_log_check($pdo,$driverId,$action==='send_voice_otp'?'voice_otp':'sms_otp','passed',$result,['sent'=>true]);
    rado_json(200,['ok'=>true,'message'=>$action==='send_voice_otp'?'کد از طریق تماس ارسال شد.':'کد پیامکی ارسال شد.','expires_in'=>180]);
}

if ($action === 'verify_otp') {
    $p=rv_profile($pdo,$driverId);$mobile=rado_normalize_mobile((string)($p['mobile']??''));$code=trim((string)($input['code']??''));
    if (!preg_match('/^\d{6}$/',$code)) rv_fail(422,'invalid_otp','کد 6 رقمی را وارد کنید.');
    $s=$pdo->prepare("SELECT * FROM otp_challenges WHERE phone=? AND purpose='driver_verify' AND consumed_at IS NULL ORDER BY id DESC LIMIT 1");$s->execute([$mobile]);$challenge=$s->fetch();
    if (!is_array($challenge) || strtotime((string)$challenge['expires_at'])<time()) rv_fail(410,'otp_expired','کد منقضی شده است.');
    if ((int)$challenge['attempts']>=5) rv_fail(429,'otp_attempts_exceeded','تعداد تلاش بیش از حد مجاز است.');
    if (!password_verify($code,(string)$challenge['code_hash'])) {
        $pdo->prepare('UPDATE otp_challenges SET attempts=attempts+1 WHERE id=?')->execute([(int)$challenge['id']]);
        rv_fail(422,'otp_invalid','کد تأیید اشتباه است.');
    }
    $pdo->beginTransaction();
    try{$pdo->prepare('UPDATE otp_challenges SET consumed_at=NOW() WHERE id=?')->execute([(int)$challenge['id']]);$pdo->prepare('UPDATE driver_verification_profiles SET mobile_verified_at=NOW() WHERE driver_id=?')->execute([$driverId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    rado_json(200,['ok'=>true,'verification'=>rado_verification_summary($pdo,$driverId)]);
}

if ($action === 'accept_terms') {
    $version=(string)(rado_setting($pdo,'driver_verification_terms_version','2026-09-08')??'2026-09-08');
    $pdo->prepare('UPDATE driver_verification_profiles SET consent_version=?,consent_at=NOW(),consent_ip=? WHERE driver_id=?')->execute([$version,mb_substr((string)($_SERVER['REMOTE_ADDR']??''),0,64,'UTF-8'),$driverId]);
    rado_json(200,['ok'=>true,'verification'=>rado_verification_summary($pdo,$driverId)]);
}

if ($action === 'run_shahkar') {
    $p=rv_profile($pdo,$driverId);rv_check_prereq($p,['national_code'=>'کد ملی','mobile'=>'شماره موبایل']);if(empty($p['mobile_verified_at']))rv_fail(409,'mobile_not_verified','ابتدا شماره موبایل را تأیید کنید.');
    $service=(string)(rado_setting($pdo,'api_ir_shahkar_mode','Shahkar')??'Shahkar');if(!in_array($service,['Shahkar','ShahkarLite'],true))$service='Shahkar';
    $payload=['nationalCode'=>(string)$p['national_code'],'mobile'=>(string)$p['mobile']];if($service==='Shahkar')$payload['isCompany']=false;
    $result=rado_api_ir_call($service,$payload,25);$passed=$result['ok']&&$result['data']===true;
    rv_api_result($pdo,$driverId,'shahkar','shahkar_status',$result,$passed,['matched'=>$passed],'کد ملی با مالک سیم‌کارت تطابق ندارد.');
    rado_json($passed?200:422,['ok'=>$passed,'verification'=>rado_verification_summary($pdo,$driverId),'message'=>$passed?'تطبیق شاهکار تأیید شد.':'تطبیق شاهکار تأیید نشد.']);
}

if ($action === 'run_iban') {
    $p=rv_profile($pdo,$driverId);rv_check_prereq($p,['national_code'=>'کد ملی','iban'=>'شماره شبا']);
    $result=rado_api_ir_call('IbanMatchPro',['nationalCode'=>(string)$p['national_code'],'iban'=>(string)$p['iban']],25);$passed=$result['ok']&&$result['data']===true;
    rv_api_result($pdo,$driverId,'iban','iban_status',$result,$passed,['matched'=>$passed],'شماره شبا متعلق به راننده تأیید نشد.');
    rado_json($passed?200:422,['ok'=>$passed,'verification'=>rado_verification_summary($pdo,$driverId),'message'=>$passed?'مالکیت شبا تأیید شد.':'مالکیت شبا تأیید نشد.']);
}

if ($action === 'run_license') {
    $p=rv_profile($pdo,$driverId);rv_check_prereq($p,['national_code'=>'کد ملی','mobile'=>'شماره موبایل','license_number'=>'شماره گواهینامه']);if(empty($p['mobile_verified_at']))rv_fail(409,'mobile_not_verified','ابتدا شماره موبایل را تأیید کنید.');
    $result=rado_api_ir_call('DrivingLisense',['nationalCode'=>(string)$p['national_code'],'mobile'=>(string)$p['mobile']],30);
    $found=is_array($result['data'])?rado_array_find_first($result['data'],['licenseNumber','licenseNo','drivingLicenseNumber']):null;
    $numberMatch=$found===null||rv_digits((string)$found)===rv_digits((string)$p['license_number']);$passed=$result['ok']&&$result['data']!==null&&$result['data']!==false&&$numberMatch;
    rv_api_result($pdo,$driverId,'license','license_status',$result,$passed,['license_number_match'=>$numberMatch],'استعلام گواهینامه تأیید نشد.');
    rado_json($passed?200:422,['ok'=>$passed,'verification'=>rado_verification_summary($pdo,$driverId),'message'=>$passed?'گواهینامه تأیید شد.':'گواهینامه تأیید نشد.']);
}

if ($action === 'run_driving_score') {
    $p=rv_profile($pdo,$driverId);rv_check_prereq($p,['national_code'=>'کد ملی','mobile'=>'شماره موبایل','license_number'=>'شماره گواهینامه']);if(empty($p['mobile_verified_at']))rv_fail(409,'mobile_not_verified','ابتدا شماره موبایل را تأیید کنید.');
    $result=rado_api_ir_call('DrivingScore',['nationalCode'=>(string)$p['national_code'],'mobile'=>(string)$p['mobile'],'licenseNumber'=>(string)$p['license_number']],30);
    $score=is_array($result['data'])?rado_array_find_first($result['data'],['negativeScore','totalNegativeScore','score']):null;$passed=$result['ok']&&$result['data']!==null&&$result['data']!==false;
    if($passed&&is_numeric($score))$pdo->prepare('UPDATE driver_verification_profiles SET driving_negative_score=? WHERE driver_id=?')->execute([(int)$score,$driverId]);
    rv_api_result($pdo,$driverId,'driving_score','driving_score_status',$result,$passed,['negative_score'=>is_numeric($score)?(int)$score:null],'استعلام نمره منفی انجام نشد.');
    rado_json($passed?200:422,['ok'=>$passed,'verification'=>rado_verification_summary($pdo,$driverId),'message'=>$passed?'استعلام نمره منفی ثبت شد.':'استعلام نمره منفی ناموفق بود.']);
}

if ($action === 'run_active_plates') {
    $p=rv_profile($pdo,$driverId);rv_check_prereq($p,['national_code'=>'کد ملی','mobile'=>'شماره موبایل']);if(empty($p['mobile_verified_at']))rv_fail(409,'mobile_not_verified','ابتدا شماره موبایل را تأیید کنید.');
    $result=rado_api_ir_call('ActivePlates',['nationalCode'=>(string)$p['national_code'],'mobile'=>(string)$p['mobile']],30);$passed=$result['ok']&&$result['data']!==null&&$result['data']!==false;
    $count=is_array($result['data'])?count($result['data']):null;rv_api_result($pdo,$driverId,'active_plates','active_plates_status',$result,$passed,['items'=>$count],'استعلام پلاک‌های فعال انجام نشد.');
    rado_json($passed?200:422,['ok'=>$passed,'verification'=>rado_verification_summary($pdo,$driverId)]);
}

if ($action === 'run_vehicle') {
    $p=rv_profile($pdo,$driverId);rv_check_prereq($p,['vehicle_owner_national_code'=>'کد ملی مالک خودرو','plate_part1'=>'پلاک','plate_letter'=>'حرف پلاک','plate_part2'=>'پلاک','plate_part3'=>'ایران پلاک']);
    $plate=rado_plate_text($p);$result=rado_api_ir_call('VehicleInfo',['nationalCode'=>(string)$p['vehicle_owner_national_code'],'plateNumber'=>$plate],30);$passed=$result['ok']&&is_array($result['data'])&&$result['data']!==[];
    $summary=['plate_verified'=>$passed];
    if(is_array($result['data'])){
        $make=rado_array_find_first($result['data'],['brand','make','vehicleBrand']);$model=rado_array_find_first($result['data'],['model','vehicleModel']);$color=rado_array_find_first($result['data'],['color','vehicleColor']);
        if($make!==null||$model!==null||$color!==null)$pdo->prepare('UPDATE driver_verification_profiles SET vehicle_make=COALESCE(?,vehicle_make),vehicle_model=COALESCE(?,vehicle_model),vehicle_color=COALESCE(?,vehicle_color) WHERE driver_id=?')->execute([$make!==null?mb_substr((string)$make,0,80,'UTF-8'):null,$model!==null?mb_substr((string)$model,0,80,'UTF-8'):null,$color!==null?mb_substr((string)$color,0,50,'UTF-8'):null,$driverId]);
        $summary['vehicle_make']=$make;$summary['vehicle_model']=$model;$summary['vehicle_color']=$color;
    }
    rv_api_result($pdo,$driverId,'vehicle','vehicle_status',$result,$passed,$summary,'اطلاعات خودرو با پلاک/مالک تأیید نشد.');
    rado_json($passed?200:422,['ok'=>$passed,'verification'=>rado_verification_summary($pdo,$driverId),'message'=>$passed?'خودرو تأیید شد.':'استعلام خودرو تأیید نشد.']);
}

if ($action === 'run_biometric') {
    $p=rv_profile($pdo,$driverId);rv_check_prereq($p,['national_code'=>'کد ملی','birth_date_jalali'=>'تاریخ تولد','national_card_serial'=>'سریال کارت ملی']);
    if(!isset($_FILES['video'])||($_FILES['video']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)rv_fail(422,'video_required','ویدئوی سلفی لازم است.');
    $file=$_FILES['video'];$size=(int)($file['size']??0);if($size<1024||$size>5*1024*1024)rv_fail(413,'video_size_invalid','حجم ویدئو باید حداکثر 5 مگابایت باشد.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file((string)$file['tmp_name']);$allowed=['video/mp4','video/quicktime','video/webm','application/octet-stream'];if(!in_array($mime,$allowed,true))rv_fail(415,'video_type_invalid','فرمت ویدئو پشتیبانی نمی‌شود.');
    $raw=file_get_contents((string)$file['tmp_name']);if($raw===false)rv_fail(500,'video_read_failed','خواندن ویدئو ممکن نشد.');$base64=base64_encode($raw);unset($raw);
    $mode=(string)(rado_setting($pdo,'api_ir_biometric_mode','VideoLive')??'VideoLive');if(!in_array($mode,['VideoLive','VideoVerify'],true))$mode='VideoLive';
    $match=max(50,min(100,(int)(rado_setting($pdo,'api_ir_matching_threshold','90')??'90')));$live=max(50,min(100,(int)(rado_setting($pdo,'api_ir_liveness_threshold','80')??'80')));$speech=max(1,min(100,(int)(rado_setting($pdo,'api_ir_speech_threshold','50')??'50')));
    $payload=['nationalCode'=>(string)$p['national_code'],'birthDate'=>(string)$p['birth_date_jalali'],'serialNumber'=>(string)$p['national_card_serial'],'videoBase64'=>$base64,'matchingThreshold'=>$match,'livenessThreshold'=>$live];
    if($mode==='VideoVerify'){$payload['speechText']=(string)(rado_setting($pdo,'api_ir_speech_text','من با آگاهی کامل قوانین رانندگی رادو را می‌پذیرم')??'');$payload['speechThreshold']=$speech;}
    $result=rado_api_ir_call($mode,$payload,70);unset($payload['videoBase64'],$base64);
    $data=is_array($result['data'])?$result['data']:[];$matching=(int)($data['matchingScore']??0);$liveness=(int)($data['livenessScore']??0);$speechScore=isset($data['speechScore'])?(int)$data['speechScore']:null;
    $passed=$result['ok']&&($data['isMatch']??false)===true&&($data['isLiveness']??false)===true&&($mode!=='VideoVerify'||($data['isSpeechMatched']??false)===true);
    if($result['ok'])$pdo->prepare('UPDATE driver_verification_profiles SET matching_score=?,liveness_score=?,speech_score=? WHERE driver_id=?')->execute([$matching?:null,$liveness?:null,$speechScore,$driverId]);
    rv_api_result($pdo,$driverId,$mode==='VideoVerify'?'video_verify':'video_live','biometric_status',$result,$passed,['matching_score'=>$matching,'liveness_score'=>$liveness,'speech_score'=>$speechScore],'احراز چهره یا زنده‌سنجی تأیید نشد.');
    rado_json($passed?200:422,['ok'=>$passed,'verification'=>rado_verification_summary($pdo,$driverId),'message'=>$passed?'احراز چهره و زنده‌سنجی تأیید شد.':'احراز چهره تأیید نشد؛ دوباره با نور مناسب تلاش کنید.']);
}

if ($action === 'submit') {
    $missing=rado_verification_missing($pdo,$driverId,false);if($missing!==[])rado_json(422,['ok'=>false,'error'=>'verification_incomplete','message'=>'پرونده هنوز کامل نیست.','missing'=>$missing,'verification'=>rado_verification_summary($pdo,$driverId)]);
    $pdo->beginTransaction();
    try{$pdo->prepare("UPDATE driver_verification_profiles SET review_status='submitted',submitted_at=NOW(),review_note=NULL WHERE driver_id=?")->execute([$driverId]);$pdo->prepare("UPDATE driver_verification_corrections SET status='resolved',resolved_at=NOW() WHERE driver_id=? AND status='open'")->execute([$driverId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    try{rado_platform_notify($pdo,$driverId,'پرونده احراز هویت ارسال شد','مدارک شما برای بررسی نهایی مدیریت RADO ارسال شد.','verification_submitted',[]);}catch(Throwable){}
    rado_json(200,['ok'=>true,'verification'=>rado_verification_summary($pdo,$driverId),'message'=>'پرونده برای بررسی نهایی ارسال شد.']);
}

rado_json(422,['ok'=>false,'error'=>'unknown_verification_action']);
