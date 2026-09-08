from pathlib import Path


def read(path: str) -> str:
    return Path(path).read_text(encoding='utf-8')


def write(path: str, text: str) -> None:
    Path(path).write_text(text, encoding='utf-8')


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        if new in text:
            return text
        raise SystemExit(f'anchor not found: {label}')
    return text.replace(old, new, 1)

# Admin navigation
p='deploy/cpanel/admin/_ui.php'; s=read(p)
s=replace_once(s,
"    'drivers'=>['رانندگان','/admin/drivers.php','🚕'],\n    'passengers'=>['مسافران','/admin/passengers.php','👤'],",
"    'drivers'=>['رانندگان','/admin/drivers.php','🚕'],\n    'verification'=>['احراز هویت','/admin/verification.php','✓'],\n    'passengers'=>['مسافران','/admin/passengers.php','👤'],",
'admin verification nav')
write(p,s)

# Settings module
p='deploy/cpanel/admin/settings.php'; s=read(p)
s=replace_once(s,
"  <a class=\"card span4 module\" href=\"/admin/maps.php\"><div class=\"icon\">⌖</div><div><b>نقشه و Neshan</b><small>کلیدها، تست اتصال و سلامت سرویس‌های مکانی</small></div></a>\n",
"  <a class=\"card span4 module\" href=\"/admin/maps.php\"><div class=\"icon\">⌖</div><div><b>نقشه و Neshan</b><small>کلیدها، تست اتصال و سلامت سرویس‌های مکانی</small></div></a>\n  <a class=\"card span4 module\" href=\"/admin/verification-settings.php\"><div class=\"icon\">✓</div><div><b>API.ir و احراز هویت</b><small>شاهکار، بایومتریک، گواهینامه، خودرو و تطبیق شبا</small></div></a>\n",
'settings verification module')
write(p,s)

# Core approved-driver gate
p='deploy/cpanel/rado-system/lib/app.php'; s=read(p)
old="""function rado_require_approved_driver(PDO $pdo, string $clientId): array
{
    $driver = rado_driver_from_client($pdo, $clientId);
    if ($driver === null) {
        $driver = rado_guest_driver($pdo, $clientId);
    }
    if ((int)($driver['is_active'] ?? 0) !== 1 || (string)($driver['status'] ?? '') !== 'approved') {
        rado_json(403, [
            'ok'=>false,
            'error'=>'driver_not_approved',
            'message'=>'حساب راننده هنوز توسط مدیریت رادو تأیید نشده است.',
            'driver_status'=>(string)($driver['status'] ?? 'pending'),
        ]);
    }
    return $driver;
}
"""
new="""function rado_require_approved_driver(PDO $pdo, string $clientId): array
{
    $driver = rado_driver_from_client($pdo, $clientId);
    if ($driver === null) {
        $driver = rado_guest_driver($pdo, $clientId);
    }
    $baseApproved = (int)($driver['is_active'] ?? 0) === 1 && (string)($driver['status'] ?? '') === 'approved';
    $verificationRequired = (rado_setting($pdo, 'driver_verification_enforced', '1') ?? '1') === '1';
    $verificationApproved = true;
    $verificationStatus = 'not_available';
    if ($verificationRequired) {
        $verificationApproved = false;
        try {
            $q = $pdo->prepare("SELECT review_status FROM driver_verification_profiles WHERE driver_id=? LIMIT 1");
            $q->execute([(string)$driver['id']]);
            $v = $q->fetchColumn();
            $verificationStatus = $v === false ? 'incomplete' : (string)$v;
            $verificationApproved = $verificationStatus === 'approved';
        } catch (Throwable) {
            $verificationStatus = 'schema_missing';
        }
    }
    if (!$baseApproved || !$verificationApproved) {
        rado_json(403, [
            'ok'=>false,
            'error'=>$baseApproved && !$verificationApproved ? 'driver_verification_required' : 'driver_not_approved',
            'message'=>$baseApproved && !$verificationApproved
                ? 'احراز هویت راننده هنوز تأیید نهایی نشده است.'
                : 'حساب راننده هنوز توسط مدیریت رادو تأیید نشده است.',
            'driver_status'=>(string)($driver['status'] ?? 'pending'),
            'verification_status'=>$verificationStatus,
        ]);
    }
    return $driver;
}
"""
s=replace_once(s,old,new,'approved driver verification gate')
write(p,s)

# Prevent manual admin bypass
p='deploy/cpanel/admin/drivers.php'; s=read(p)
s=replace_once(s,
"require __DIR__.'/_ui.php';\nra_require_admin();",
"require __DIR__.'/_ui.php';\nrequire_once dirname(__DIR__).'/rado-system/lib/api_ir.php';\nra_require_admin();",
'drivers api_ir include')
s=replace_once(s,
"    if($id===''||!in_array($status,['pending','approved','suspended','rejected'],true)||$rate<0||$rate>100)throw new RuntimeException('اطلاعات راننده معتبر نیست.');\n    $pdo->beginTransaction();",
"    if($id===''||!in_array($status,['pending','approved','suspended','rejected'],true)||$rate<0||$rate>100)throw new RuntimeException('اطلاعات راننده معتبر نیست.');\n    if($status==='approved'){\n      if(!ra_table_exists($pdo,'driver_verification_profiles'))throw new RuntimeException('ابتدا Migration احراز هویت را اجرا کنید.');\n      $missing=rado_verification_missing($pdo,$id,true);\n      if($missing!==[])throw new RuntimeException('تأیید مستقیم مجاز نیست؛ پرونده احراز هویت ناقص است: '.implode('، ',$missing));\n    }\n    $pdo->beginTransaction();",
'block manual approval bypass')
s=replace_once(s,
"<td><details><summary class=\"btn\">ویرایش</summary>",
"<td><a class=\"btn goldbtn\" href=\"/admin/verification.php?driver_id=<?=ra_e((string)$d['user_id'])?>\">احراز هویت</a> <details style=\"display:inline-block\"><summary class=\"btn\">ویرایش</summary>",
'driver verification link')
write(p,s)

# Driver session model fields
p='apps/driver/lib/driver_platform.dart'; s=read(p)
old="""class DriverSession {
  const DriverSession({
    required this.name,
    required this.status,
    required this.approved,
    required this.online,
    required this.commissionRate,
    required this.plate,
    required this.vehicle,
  });
  final String name, status, plate, vehicle;
  final bool approved, online;
  final double commissionRate;
  String get statusFa => switch (status) {
    'approved' => 'تأییدشده',
    'suspended' => 'تعلیق‌شده',
    'rejected' => 'ردشده',
    _ => 'در انتظار تأیید',
  };
  factory DriverSession.fromJson(Map<String, dynamic> j) => DriverSession(
    name: (j['name'] ?? 'راننده RADO').toString(),
    status: (j['status'] ?? 'pending').toString(),
    approved: j['approved'] == true,
    online: j['online'] == true,
    commissionRate: (j['commission_rate'] as num?)?.toDouble() ?? 0,
    plate: (j['plate'] ?? '').toString(),
    vehicle: (j['vehicle'] ?? '').toString(),
  );
}
"""
new="""class DriverSession {
  const DriverSession({
    required this.name,
    required this.status,
    required this.approved,
    required this.online,
    required this.commissionRate,
    required this.plate,
    required this.vehicle,
    required this.verificationRequired,
    required this.verificationStatus,
    required this.verificationProgress,
  });
  final String name, status, plate, vehicle, verificationStatus;
  final bool approved, online, verificationRequired;
  final double commissionRate;
  final int verificationProgress;
  String get statusFa {
    if (verificationRequired && verificationStatus != 'approved') {
      return switch (verificationStatus) {
        'submitted' => 'پرونده ارسال شده',
        'under_review' => 'در حال بررسی احراز هویت',
        'needs_correction' => 'احراز هویت نیاز به اصلاح دارد',
        'rejected' => 'احراز هویت رد شده',
        'suspended' => 'احراز هویت تعلیق شده',
        _ => 'احراز هویت کامل نشده',
      };
    }
    return switch (status) {
      'approved' => 'تأییدشده',
      'suspended' => 'تعلیق‌شده',
      'rejected' => 'ردشده',
      _ => 'در انتظار تأیید',
    };
  }
  factory DriverSession.fromJson(Map<String, dynamic> j) => DriverSession(
    name: (j['name'] ?? 'راننده RADO').toString(),
    status: (j['status'] ?? 'pending').toString(),
    approved: j['approved'] == true,
    online: j['online'] == true,
    commissionRate: (j['commission_rate'] as num?)?.toDouble() ?? 0,
    plate: (j['plate'] ?? '').toString(),
    vehicle: (j['vehicle'] ?? '').toString(),
    verificationRequired: j['verification_required'] != false,
    verificationStatus: (j['verification_status'] ?? 'incomplete').toString(),
    verificationProgress: (j['verification_progress'] as num?)?.toInt() ?? 0,
  );
}
"""
s=replace_once(s,old,new,'driver session verification fields')
write(p,s)

# Driver app entry points
p='apps/driver/lib/advanced_driver.dart'; s=read(p)
s=replace_once(s,
"import 'driver_platform.dart';\nimport 'realtime_stream.dart';",
"import 'driver_platform.dart';\nimport 'driver_verification.dart';\nimport 'realtime_stream.dart';",
'driver verification import')
anchor="""  void _show(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
"""
insert="""  void _show(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  Future<void> _openVerificationCenter() async {
    final id = _clientId;
    if (id == null || !mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => DriverVerificationPage(clientId: id),
      ),
    );
    await _refresh(all: true);
  }

  @override
"""
s=replace_once(s,anchor,insert,'verification navigation method')
old="""  Widget _approvalCard() => _Box(
    child: Row(
      children: [
        const Icon(Icons.verified_user_outlined),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            'حساب راننده ${_session?.statusFa ?? 'در انتظار تأیید'} است. پس از تأیید مدیریت می‌توانی آنلاین شوی.',
          ),
        ),
      ],
    ),
  );
"""
new="""  Widget _approvalCard() => _Box(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Icon(Icons.verified_user_outlined),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                _session?.statusFa ?? 'احراز هویت کامل نشده',
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
            ),
            if ((_session?.verificationProgress ?? 0) > 0)
              Text('${_session!.verificationProgress}٪'),
          ],
        ),
        const SizedBox(height: 8),
        const Text(
          'برای آنلاین‌شدن، هویت، موبایل، گواهینامه، خودرو، شبا، بایومتریک و مدارک باید تأیید شوند.',
          style: TextStyle(fontSize: 12, color: Colors.black54),
        ),
        const SizedBox(height: 10),
        SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            onPressed: _openVerificationCenter,
            style: FilledButton.styleFrom(
              backgroundColor: _yellow,
              foregroundColor: _black,
            ),
            icon: const Icon(Icons.fact_check_rounded),
            label: const Text('تکمیل احراز هویت'),
          ),
        ),
      ],
    ),
  );
"""
s=replace_once(s,old,new,'approval card KYC CTA')
old="""        _header(),
        const SizedBox(height: 14),
        _Box(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'تنظیمات دریافت سفر',
"""
new="""        _header(),
        const SizedBox(height: 14),
        _Box(
          child: ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.verified_user_rounded, color: _yellow),
            title: const Text('احراز هویت راننده', style: TextStyle(fontWeight: FontWeight.w900)),
            subtitle: Text(_session?.statusFa ?? 'مشاهده و تکمیل پرونده'),
            trailing: const Icon(Icons.chevron_left_rounded),
            onTap: _openVerificationCenter,
          ),
        ),
        const SizedBox(height: 12),
        _Box(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'تنظیمات دریافت سفر',
"""
s=replace_once(s,old,new,'account verification card')
write(p,s)

# Driver camera/mic permissions in production release only
p='.github/workflows/release.yml'; s=read(p)
marker='      - name: Build Driver APK\n'
if marker not in s: raise SystemExit('driver release segment missing')
head, tail=s.split(marker,1)
tail=replace_once(tail,
"    <uses-permission android:name=\"android.permission.ACCESS_COARSE_LOCATION\"/>'''",
"    <uses-permission android:name=\"android.permission.ACCESS_COARSE_LOCATION\"/>\n    <uses-permission android:name=\"android.permission.CAMERA\"/>\n    <uses-permission android:name=\"android.permission.RECORD_AUDIO\"/>'''",
'release driver camera permissions')
s=head+marker+tail
write(p,s)

# Standalone driver build permissions
p='.github/workflows/android-driver.yml'; s=read(p)
s=replace_once(s,
"    <uses-permission android:name=\"android.permission.ACCESS_COARSE_LOCATION\"/>'''",
"    <uses-permission android:name=\"android.permission.ACCESS_COARSE_LOCATION\"/>\n    <uses-permission android:name=\"android.permission.CAMERA\"/>\n    <uses-permission android:name=\"android.permission.RECORD_AUDIO\"/>'''",
'android driver camera permissions')
write(p,s)

# Suspend verified drivers when a required document expires
p='deploy/cpanel/rado-system/lib/tick.php'; s=read(p)
s=replace_once(s,
"    $pdo = rado_db();\n    $pdo->beginTransaction();",
"    $pdo = rado_db();\n    $expiredVerificationDrivers = [];\n    $pdo->beginTransaction();",
'expired verification init')
s=replace_once(s,
"        $pdo->exec(\"UPDATE driver_documents SET status='expired' WHERE expires_at IS NOT NULL AND expires_at<CURDATE() AND status='approved'\");\n        $pdo->exec(\"DELETE FROM realtime_events WHERE expires_at<NOW()\");",
"        $pdo->exec(\"UPDATE driver_documents SET status='expired' WHERE expires_at IS NOT NULL AND expires_at<CURDATE() AND status='approved'\");\n        try {\n            $expiredVerificationDrivers = $pdo->query(\"SELECT DISTINCT d.driver_id FROM driver_documents d JOIN drivers r ON r.user_id=d.driver_id JOIN driver_verification_profiles v ON v.driver_id=d.driver_id WHERE d.status='expired' AND d.document_type IN ('national_card_front','national_card_back','driver_license_front','driver_license_back','vehicle_card_front','vehicle_card_back','insurance','profile_photo','vehicle_front') AND r.status='approved' AND v.review_status='approved'\")->fetchAll(PDO::FETCH_COLUMN);\n            foreach ($expiredVerificationDrivers as $expiredDriverId) {\n                $pdo->prepare(\"UPDATE drivers SET status='suspended' WHERE user_id=?\")->execute([(string)$expiredDriverId]);\n                $pdo->prepare(\"UPDATE driver_verification_profiles SET review_status='suspended',review_note='یکی از مدارک الزامی منقضی شده است',reviewed_at=NOW() WHERE driver_id=?\")->execute([(string)$expiredDriverId]);\n                $pdo->prepare('UPDATE driver_presence SET is_online=0 WHERE driver_id=?')->execute([(string)$expiredDriverId]);\n            }\n        } catch (Throwable) {}\n        $pdo->exec(\"DELETE FROM realtime_events WHERE expires_at<NOW()\");",
'expired document suspension')
s=replace_once(s,
"    $scheduled = 0;",
"    foreach ($expiredVerificationDrivers as $expiredDriverId) {\n        try { rado_platform_notify($pdo, (string)$expiredDriverId, 'مدرک راننده منقضی شده', 'برای ادامه فعالیت، مدرک منقضی‌شده را در بخش احراز هویت دوباره ارسال کنید.', 'verification_expired', []); } catch (Throwable) {}\n    }\n\n    $scheduled = 0;",
'expired document notification')
write(p,s)

# Dynamic progress should honor optional checks
p='deploy/cpanel/rado-system/lib/api_ir.php'; s=read(p)
old="""    $total = 8;
    $done = 0;
    if (!empty($p['mobile_verified_at'])) $done++;
    foreach (['shahkar_status','biometric_status','license_status','vehicle_status','iban_status','driving_score_status'] as $k) if (($p[$k] ?? '') === 'passed') $done++;
    if (empty($p['consent_at']) === false) $done++;
    $progress = (int)round(($done / $total) * 100);
"""
new="""    $requiredChecks = ['shahkar_status','biometric_status','license_status','vehicle_status','iban_status'];
    if ((rado_setting($pdo,'driver_verification_require_driving_score','1') ?? '1') === '1') $requiredChecks[] = 'driving_score_status';
    if ((rado_setting($pdo,'driver_verification_require_active_plates','0') ?? '0') === '1') $requiredChecks[] = 'active_plates_status';
    $total = 2 + count($requiredChecks);
    $done = 0;
    if (!empty($p['mobile_verified_at'])) $done++;
    foreach ($requiredChecks as $k) if (($p[$k] ?? '') === 'passed') $done++;
    if (empty($p['consent_at']) === false) $done++;
    $progress = (int)round(($done / max(1,$total)) * 100);
"""
s=replace_once(s,old,new,'dynamic KYC progress')
write(p,s)

# Quality contracts
p='.github/workflows/quality.yml'; s=read(p)
s=replace_once(s,
"          test -f apps/driver/lib/driver_platform.dart\n",
"          test -f apps/driver/lib/driver_platform.dart\n          test -f apps/driver/lib/driver_verification.dart\n",
'quality driver verification file')
s=replace_once(s,
"          test -f deploy/cpanel/sql/migrations/041_operations.sql\n",
"          test -f deploy/cpanel/sql/migrations/041_operations.sql\n          test -f deploy/cpanel/sql/migrations/043_driver_verification.sql\n",
'quality verification migration')
s=replace_once(s,
"          test -f deploy/cpanel/rado-system/lib/tick.php\n",
"          test -f deploy/cpanel/rado-system/lib/tick.php\n          test -f deploy/cpanel/rado-system/lib/api_ir.php\n",
'quality api_ir helper')
s=replace_once(s,
"          test -f deploy/cpanel/admin/drivers.php\n",
"          test -f deploy/cpanel/admin/drivers.php\n          test -f deploy/cpanel/admin/verification.php\n          test -f deploy/cpanel/admin/verification-settings.php\n          test -f deploy/cpanel/admin/driver-document.php\n",
'quality verification admin')
s=replace_once(s,
"          test -f deploy/cpanel/api/v1/driver/documents/index.php\n",
"          test -f deploy/cpanel/api/v1/driver/documents/index.php\n          test -f deploy/cpanel/api/v1/driver/verification/index.php\n",
'quality verification api')
s=replace_once(s,
"          grep -q \"/admin/operations.php\" deploy/cpanel/admin/_ui.php\n",
"          grep -q \"/admin/operations.php\" deploy/cpanel/admin/_ui.php\n          grep -q \"/admin/verification.php\" deploy/cpanel/admin/_ui.php\n",
'quality verification nav')
s=replace_once(s,
"          grep -q \"CREATE TABLE IF NOT EXISTS driver_online_daily\" deploy/cpanel/sql/migrations/041_operations.sql\n",
"          grep -q \"CREATE TABLE IF NOT EXISTS driver_online_daily\" deploy/cpanel/sql/migrations/041_operations.sql\n          grep -q \"CREATE TABLE IF NOT EXISTS driver_verification_profiles\" deploy/cpanel/sql/migrations/043_driver_verification.sql\n          grep -q \"driver_verification_enforced\" deploy/cpanel/sql/migrations/043_driver_verification.sql\n          grep -q \"Authorization: Bearer\" deploy/cpanel/rado-system/lib/api_ir.php\n          grep -q \"driver_verification_required\" deploy/cpanel/rado-system/lib/app.php\n",
'quality verification contracts')
write(p,s)

print('driver verification integration patch applied')
