<?php
declare(strict_types=1);

if (!function_exists('rado_db')) require_once __DIR__ . '/app.php';

function rado_api_ir_secrets(): array
{
    $path = rado_root() . '/rado-system/private/ci-secrets.php';
    if (!is_file($path)) return [];
    $data = require $path;
    return is_array($data) ? $data : [];
}

function rado_api_ir_token(): string
{
    $s = rado_api_ir_secrets();
    return trim((string)($s['api_ir_token'] ?? ''));
}

function rado_api_ir_configured(): bool
{
    return rado_api_ir_token() !== '' && function_exists('curl_init');
}

function rado_api_ir_allowed_services(): array
{
    return [
        'SmsOTP','CallOTP','CallOTPalt','Shahkar','ShahkarLite',
        'VideoMatch','VideoLive','VideoVerify','FaceMatch','FaceMatchLite',
        'DrivingLisense','DrivingScore','ActivePlates','PlateHistory','VehicleInfo',
        'IbanMatch','IbanMatchPro','IbanInfo','CardMatch','PersonImage',
    ];
}

function rado_api_ir_call(string $service, array $payload, int $timeout = 30): array
{
    if (!in_array($service, rado_api_ir_allowed_services(), true)) {
        return ['ok'=>false,'http'=>0,'json'=>null,'data'=>null,'error'=>'unsupported_api_ir_service','sha256'=>null];
    }
    $token = rado_api_ir_token();
    if ($token === '') {
        return ['ok'=>false,'http'=>0,'json'=>null,'data'=>null,'error'=>'api_ir_not_configured','sha256'=>null];
    }
    if (!function_exists('curl_init')) {
        return ['ok'=>false,'http'=>0,'json'=>null,'data'=>null,'error'=>'curl_extension_missing','sha256'=>null];
    }

    $url = 'https://s.api.ir/api/sw1/' . rawurlencode($service);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok'=>false,'http'=>0,'json'=>null,'data'=>null,'error'=>'api_ir_payload_encode_failed','sha256'=>null];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_TIMEOUT => max(10, min(75, $timeout)),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: RADO-TAXI/driver-verification',
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok'=>false,'http'=>$http,'json'=>null,'data'=>null,'error'=>$curlError !== '' ? $curlError : 'api_ir_transport_failed','sha256'=>null];
    }

    $hash = hash('sha256', (string)$raw);
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok'=>false,'http'=>$http,'json'=>null,'data'=>null,'error'=>'api_ir_invalid_json','sha256'=>$hash];
    }

    $success = ($http >= 200 && $http < 300 && ($json['success'] ?? false) === true);
    $err = '';
    if (!$success) {
        $err = trim((string)($json['message'] ?? $json['error'] ?? 'api_ir_request_failed'));
        if ($err === '') $err = 'api_ir_request_failed';
    }
    return [
        'ok'=>$success,
        'http'=>$http,
        'json'=>$json,
        'data'=>$json['data'] ?? null,
        'provider_code'=>isset($json['code']) ? (string)$json['code'] : null,
        'error'=>$err,
        'sha256'=>$hash,
    ];
}

function rado_national_code_valid(string $code): bool
{
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== 10 || preg_match('/^(\d)\1{9}$/', $code)) return false;
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += ((int)$code[$i]) * (10 - $i);
    $r = $sum % 11;
    $check = $r < 2 ? $r : 11 - $r;
    return $check === (int)$code[9];
}

function rado_normalize_mobile(string $mobile): string
{
    $mobile = preg_replace('/\s+/', '', $mobile) ?? '';
    if (str_starts_with($mobile, '+98')) $mobile = '0' . substr($mobile, 3);
    elseif (str_starts_with($mobile, '98') && strlen($mobile) === 12) $mobile = '0' . substr($mobile, 2);
    return $mobile;
}

function rado_normalize_iban(string $iban): string
{
    return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
}

function rado_verification_profile(PDO $pdo, string $driverId): array
{
    $pdo->prepare('INSERT IGNORE INTO driver_verification_profiles(driver_id) VALUES(?)')->execute([$driverId]);
    $s = $pdo->prepare('SELECT * FROM driver_verification_profiles WHERE driver_id=? LIMIT 1');
    $s->execute([$driverId]);
    $row = $s->fetch();
    return is_array($row) ? $row : [];
}

function rado_verification_log_check(PDO $pdo, string $driverId, string $type, string $status, array $result, array $summary = []): void
{
    $pdo->prepare('INSERT INTO driver_verification_checks(driver_id,check_type,provider,status,http_code,provider_code,result_summary_json,response_sha256,error_message) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([
            $driverId,
            $type,
            'api.ir',
            $status,
            (int)($result['http'] ?? 0) ?: null,
            $result['provider_code'] ?? null,
            $summary === [] ? null : json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $result['sha256'] ?? null,
            $status === 'passed' ? null : mb_substr((string)($result['error'] ?? 'verification_failed'), 0, 700, 'UTF-8'),
        ]);
}

function rado_verification_required_docs(): array
{
    return [
        'national_card_front'=>'روی کارت ملی',
        'national_card_back'=>'پشت کارت ملی',
        'driver_license_front'=>'روی گواهینامه',
        'driver_license_back'=>'پشت گواهینامه',
        'vehicle_card_front'=>'روی کارت خودرو',
        'vehicle_card_back'=>'پشت کارت خودرو',
        'insurance'=>'بیمه شخص ثالث',
        'profile_photo'=>'عکس چهره راننده',
        'vehicle_front'=>'عکس جلوی خودرو',
    ];
}

function rado_verification_latest_docs(PDO $pdo, string $driverId): array
{
    $s = $pdo->prepare('SELECT d.* FROM driver_documents d JOIN (SELECT document_type,MAX(id) id FROM driver_documents WHERE driver_id=? GROUP BY document_type) x ON x.id=d.id WHERE d.driver_id=?');
    $s->execute([$driverId, $driverId]);
    $out = [];
    foreach ($s->fetchAll() as $row) $out[(string)$row['document_type']] = $row;
    return $out;
}

function rado_verification_open_corrections(PDO $pdo, string $driverId): array
{
    $s = $pdo->prepare("SELECT id,field_key,message,created_at FROM driver_verification_corrections WHERE driver_id=? AND status='open' ORDER BY id DESC");
    $s->execute([$driverId]);
    return $s->fetchAll() ?: [];
}

function rado_verification_missing(PDO $pdo, string $driverId, bool $forApproval = false): array
{
    $p = rado_verification_profile($pdo, $driverId);
    $missing = [];
    $fieldMap = [
        'full_name'=>'نام و نام خانوادگی',
        'mobile'=>'شماره موبایل',
        'national_code'=>'کد ملی',
        'birth_date_jalali'=>'تاریخ تولد',
        'national_card_serial'=>'سریال کارت ملی',
        'license_number'=>'شماره گواهینامه',
        'iban'=>'شماره شبا',
        'vehicle_owner_national_code'=>'کد ملی مالک خودرو',
        'plate_part1'=>'پلاک خودرو',
        'plate_letter'=>'حرف پلاک',
        'plate_part2'=>'پلاک خودرو',
        'plate_part3'=>'ایران پلاک',
    ];
    foreach ($fieldMap as $key=>$label) if (trim((string)($p[$key] ?? '')) === '') $missing[] = $label;
    if (empty($p['mobile_verified_at'])) $missing[] = 'تأیید OTP موبایل';
    if (($p['shahkar_status'] ?? '') !== 'passed') $missing[] = 'تطبیق شاهکار';
    if (($p['biometric_status'] ?? '') !== 'passed') $missing[] = 'احراز چهره و زنده‌سنجی';
    if (($p['license_status'] ?? '') !== 'passed') $missing[] = 'استعلام گواهینامه';
    if (($p['vehicle_status'] ?? '') !== 'passed') $missing[] = 'استعلام خودرو';
    if (($p['iban_status'] ?? '') !== 'passed') $missing[] = 'تطبیق شبا';
    if ((rado_setting($pdo,'driver_verification_require_driving_score','1') ?? '1') === '1' && ($p['driving_score_status'] ?? '') !== 'passed') $missing[] = 'استعلام نمره منفی';
    if ((rado_setting($pdo,'driver_verification_require_active_plates','0') ?? '0') === '1' && ($p['active_plates_status'] ?? '') !== 'passed') $missing[] = 'استعلام پلاک‌های فعال';
    if (empty($p['consent_at'])) $missing[] = 'پذیرش قوانین راننده';

    $docs = rado_verification_latest_docs($pdo, $driverId);
    foreach (rado_verification_required_docs() as $type=>$label) {
        if (!isset($docs[$type])) {
            $missing[] = 'مدرک: ' . $label;
            continue;
        }
        $status = (string)($docs[$type]['status'] ?? 'pending');
        if ($status === 'rejected' || $status === 'expired') $missing[] = 'اصلاح مدرک: ' . $label;
        if ($forApproval && $status !== 'approved') $missing[] = 'تأیید مدیریت: ' . $label;
    }
    if ($forApproval && rado_verification_open_corrections($pdo,$driverId) !== []) $missing[] = 'موارد اصلاحی باز';
    return array_values(array_unique($missing));
}

function rado_verification_summary(PDO $pdo, string $driverId): array
{
    $p = rado_verification_profile($pdo, $driverId);
    $docs = rado_verification_latest_docs($pdo, $driverId);
    $required = rado_verification_required_docs();
    $docPayload = [];
    foreach ($required as $type=>$label) {
        $row = $docs[$type] ?? null;
        $docPayload[] = [
            'type'=>$type,
            'label'=>$label,
            'status'=>$row ? (string)$row['status'] : 'missing',
            'note'=>$row ? (string)($row['note'] ?? '') : '',
            'expires_at'=>$row && !empty($row['expires_at']) ? (string)$row['expires_at'] : null,
        ];
    }
    $missing = rado_verification_missing($pdo,$driverId,false);
    $approvalMissing = rado_verification_missing($pdo,$driverId,true);
    $requiredChecks = ['shahkar_status','biometric_status','license_status','vehicle_status','iban_status'];
    if ((rado_setting($pdo,'driver_verification_require_driving_score','1') ?? '1') === '1') $requiredChecks[] = 'driving_score_status';
    if ((rado_setting($pdo,'driver_verification_require_active_plates','0') ?? '0') === '1') $requiredChecks[] = 'active_plates_status';
    $total = 2 + count($requiredChecks);
    $done = 0;
    if (!empty($p['mobile_verified_at'])) $done++;
    foreach ($requiredChecks as $k) if (($p[$k] ?? '') === 'passed') $done++;
    if (empty($p['consent_at']) === false) $done++;
    $progress = (int)round(($done / max(1,$total)) * 100);

    return [
        'profile'=>[
            'full_name'=>(string)($p['full_name'] ?? ''),
            'mobile'=>(string)($p['mobile'] ?? ''),
            'mobile_verified'=>!empty($p['mobile_verified_at']),
            'national_code'=>(string)($p['national_code'] ?? ''),
            'birth_date_jalali'=>(string)($p['birth_date_jalali'] ?? ''),
            'national_card_serial'=>(string)($p['national_card_serial'] ?? ''),
            'license_number'=>(string)($p['license_number'] ?? ''),
            'iban'=>(string)($p['iban'] ?? ''),
            'vehicle_owner_national_code'=>(string)($p['vehicle_owner_national_code'] ?? ''),
            'vehicle_owner_relation'=>(string)($p['vehicle_owner_relation'] ?? ''),
            'plate_part1'=>(string)($p['plate_part1'] ?? ''),
            'plate_letter'=>(string)($p['plate_letter'] ?? ''),
            'plate_part2'=>(string)($p['plate_part2'] ?? ''),
            'plate_part3'=>(string)($p['plate_part3'] ?? ''),
            'vehicle_make'=>(string)($p['vehicle_make'] ?? ''),
            'vehicle_model'=>(string)($p['vehicle_model'] ?? ''),
            'vehicle_color'=>(string)($p['vehicle_color'] ?? ''),
        ],
        'checks'=>[
            'shahkar'=>(string)($p['shahkar_status'] ?? 'not_started'),
            'biometric'=>(string)($p['biometric_status'] ?? 'not_started'),
            'license'=>(string)($p['license_status'] ?? 'not_started'),
            'driving_score'=>(string)($p['driving_score_status'] ?? 'not_started'),
            'active_plates'=>(string)($p['active_plates_status'] ?? 'not_started'),
            'vehicle'=>(string)($p['vehicle_status'] ?? 'not_started'),
            'iban'=>(string)($p['iban_status'] ?? 'not_started'),
        ],
        'scores'=>[
            'matching'=>$p['matching_score'] === null ? null : (int)$p['matching_score'],
            'liveness'=>$p['liveness_score'] === null ? null : (int)$p['liveness_score'],
            'speech'=>$p['speech_score'] === null ? null : (int)$p['speech_score'],
            'driving_negative'=>$p['driving_negative_score'] === null ? null : (int)$p['driving_negative_score'],
        ],
        'documents'=>$docPayload,
        'corrections'=>rado_verification_open_corrections($pdo,$driverId),
        'review_status'=>(string)($p['review_status'] ?? 'incomplete'),
        'progress'=>$progress,
        'missing'=>$missing,
        'ready_to_submit'=>$missing === [],
        'ready_to_approve'=>$approvalMissing === [],
        'approval_missing'=>$approvalMissing,
        'api_ir_configured'=>rado_api_ir_configured(),
        'biometric_mode'=>(string)(rado_setting($pdo,'api_ir_biometric_mode','VideoLive') ?? 'VideoLive'),
        'speech_text'=>(string)(rado_setting($pdo,'api_ir_speech_text','من با آگاهی کامل قوانین رانندگی رادو را می‌پذیرم') ?? ''),
        'terms_version'=>(string)(rado_setting($pdo,'driver_verification_terms_version','2026-09-08') ?? '2026-09-08'),
        'consent_accepted'=>!empty($p['consent_at']),
        'last_error'=>(string)($p['last_api_ir_error'] ?? ''),
    ];
}

function rado_verification_set_check(PDO $pdo, string $driverId, string $column, string $status, ?string $error = null): void
{
    $allowed = ['shahkar_status','biometric_status','license_status','driving_score_status','active_plates_status','vehicle_status','iban_status'];
    if (!in_array($column,$allowed,true)) throw new InvalidArgumentException('invalid_verification_status_column');
    $sql = "UPDATE driver_verification_profiles SET {$column}=?,last_api_ir_error=?,last_api_ir_check_at=NOW() WHERE driver_id=?";
    $pdo->prepare($sql)->execute([$status,$error,$driverId]);
}

function rado_array_find_first(array $data, array $keys): mixed
{
    foreach ($data as $k=>$v) {
        foreach ($keys as $wanted) if (strcasecmp((string)$k,(string)$wanted) === 0) return $v;
        if (is_array($v)) {
            $found = rado_array_find_first($v,$keys);
            if ($found !== null) return $found;
        }
    }
    return null;
}

function rado_plate_text(array $p): string
{
    return 'ایران ' . trim((string)($p['plate_part1'] ?? '')) . ' - ' . trim((string)($p['plate_part2'] ?? '')) . ' ' . trim((string)($p['plate_letter'] ?? '')) . ' ' . trim((string)($p['plate_part3'] ?? ''));
}
