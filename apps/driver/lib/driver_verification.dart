import 'dart:io';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
const _apiBase = String.fromEnvironment(
  'RADO_API_BASE_URL',
  defaultValue: 'https://rado-taxi.sbs',
);

class DriverVerificationPage extends StatefulWidget {
  const DriverVerificationPage({super.key, required this.clientId});
  final String clientId;

  @override
  State<DriverVerificationPage> createState() => _DriverVerificationPageState();
}

class _DriverVerificationPageState extends State<DriverVerificationPage> {
  final _api = _VerificationApi();
  final _fullName = TextEditingController();
  final _mobile = TextEditingController();
  final _nationalCode = TextEditingController();
  final _birthDate = TextEditingController();
  final _nationalSerial = TextEditingController();
  final _licenseNumber = TextEditingController();
  final _iban = TextEditingController();
  final _ownerNational = TextEditingController();
  final _plate1 = TextEditingController();
  final _plateLetter = TextEditingController();
  final _plate2 = TextEditingController();
  final _plate3 = TextEditingController();
  final _vehicleMake = TextEditingController();
  final _vehicleModel = TextEditingController();
  final _vehicleColor = TextEditingController();
  final _otp = TextEditingController();

  Map<String, dynamic>? _verification;
  bool _loading = true;
  bool _busy = false;
  String _ownerRelation = 'self';
  bool _hydrated = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [
      _fullName,
      _mobile,
      _nationalCode,
      _birthDate,
      _nationalSerial,
      _licenseNumber,
      _iban,
      _ownerNational,
      _plate1,
      _plateLetter,
      _plate2,
      _plate3,
      _vehicleMake,
      _vehicleModel,
      _vehicleColor,
      _otp,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load({bool hydrate = true}) async {
    try {
      final data = await _api.get(widget.clientId);
      if (!mounted) return;
      setState(() {
        _verification = data;
        _loading = false;
      });
      if (hydrate && !_hydrated) {
        _hydrate(data);
        _hydrated = true;
      }
    } catch (e) {
      if (mounted) {
        setState(() => _loading = false);
        _show(_api.message(e));
      }
    }
  }

  void _hydrate(Map<String, dynamic> data) {
    final p = _map(data['profile']);
    _fullName.text = _s(p['full_name']);
    _mobile.text = _s(p['mobile']);
    _nationalCode.text = _s(p['national_code']);
    _birthDate.text = _s(p['birth_date_jalali']);
    _nationalSerial.text = _s(p['national_card_serial']);
    _licenseNumber.text = _s(p['license_number']);
    _iban.text = _s(p['iban']);
    _ownerNational.text = _s(p['vehicle_owner_national_code']);
    _ownerRelation = ['self', 'family', 'other'].contains(_s(p['vehicle_owner_relation']))
        ? _s(p['vehicle_owner_relation'])
        : 'self';
    _plate1.text = _s(p['plate_part1']);
    _plateLetter.text = _s(p['plate_letter']);
    _plate2.text = _s(p['plate_part2']);
    _plate3.text = _s(p['plate_part3']);
    _vehicleMake.text = _s(p['vehicle_make']);
    _vehicleModel.text = _s(p['vehicle_model']);
    _vehicleColor.text = _s(p['vehicle_color']);
  }

  Future<void> _run(Future<Map<String, dynamic>> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final data = await action();
      if (!mounted) return;
      setState(() => _verification = data);
    } catch (e) {
      _show(_api.message(e));
      await _load(hydrate: false);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _saveIdentity() => _run(() async {
        final data = await _api.action(widget.clientId, 'save_profile', {
          'full_name': _fullName.text.trim(),
          'mobile': _mobile.text.trim(),
          'national_code': _nationalCode.text.trim(),
          'birth_date_jalali': _birthDate.text.trim(),
          'national_card_serial': _nationalSerial.text.trim(),
          'license_number': _licenseNumber.text.trim(),
          'iban': _iban.text.trim(),
          'vehicle_owner_national_code': _ownerNational.text.trim(),
          'vehicle_owner_relation': _ownerRelation,
          'plate_part1': _plate1.text.trim(),
          'plate_letter': _plateLetter.text.trim(),
          'plate_part2': _plate2.text.trim(),
          'plate_part3': _plate3.text.trim(),
          'vehicle_make': _vehicleMake.text.trim(),
          'vehicle_model': _vehicleModel.text.trim(),
          'vehicle_color': _vehicleColor.text.trim(),
        });
        _show('اطلاعات هویتی ذخیره شد.');
        return data;
      });

  Future<void> _sendOtp({bool voice = false}) async {
    await _run(() async {
      final data = await _api.action(
        widget.clientId,
        voice ? 'send_voice_otp' : 'send_otp',
      );
      _show(voice ? 'کد از طریق تماس ارسال شد.' : 'کد تأیید پیامک شد.');
      return data;
    });
  }

  Future<void> _verifyOtp() async {
    final code = _otp.text.trim();
    if (code.length != 6) {
      _show('کد ۶ رقمی را وارد کنید.');
      return;
    }
    await _run(() async {
      final data = await _api.action(widget.clientId, 'verify_otp', {'code': code});
      _otp.clear();
      _show('شماره موبایل تأیید شد.');
      return data;
    });
  }

  Future<void> _check(String action, String success) async {
    await _run(() async {
      final data = await _api.action(widget.clientId, action);
      _show(success);
      return data;
    });
  }

  Future<void> _recordBiometric() async {
    if (_busy) return;
    try {
      final picker = ImagePicker();
      final video = await picker.pickVideo(
        source: ImageSource.camera,
        preferredCameraDevice: CameraDevice.front,
        maxDuration: const Duration(seconds: 8),
      );
      if (video == null || !mounted) return;
      final size = await File(video.path).length();
      if (size > 5 * 1024 * 1024) {
        _show('حجم ویدئو بیشتر از ۵ مگابایت است؛ یک ویدئوی کوتاه‌تر بگیر.');
        return;
      }
      setState(() => _busy = true);
      final data = await _api.uploadVideo(widget.clientId, video.path);
      if (!mounted) return;
      setState(() => _verification = data);
      _show('احراز چهره و زنده‌سنجی انجام شد.');
    } catch (e) {
      _show(_api.message(e));
      await _load(hydrate: false);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _uploadDocument(Map<String, dynamic> doc) async {
    if (_busy) return;
    final picked = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: const ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    );
    final path = picked?.files.single.path;
    if (path == null || !mounted) return;

    final type = _s(doc['type']);
    final number = TextEditingController();
    final expiry = TextEditingController();
    final requiresExpiry = type == 'insurance';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('ارسال ${_s(doc['label'])}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: number,
              decoration: const InputDecoration(labelText: 'شماره مدرک (اختیاری)'),
            ),
            if (requiresExpiry || type.contains('license'))
              TextField(
                controller: expiry,
                keyboardType: TextInputType.datetime,
                decoration: InputDecoration(
                  labelText: requiresExpiry
                      ? 'تاریخ انقضا YYYY-MM-DD (اجباری)'
                      : 'تاریخ انقضا YYYY-MM-DD',
                ),
              ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('لغو')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('ارسال')),
        ],
      ),
    );
    if (ok != true) return;
    if (requiresExpiry && expiry.text.trim().isEmpty) {
      _show('تاریخ انقضای بیمه را وارد کنید.');
      return;
    }

    setState(() => _busy = true);
    try {
      await _api.uploadDocument(
        clientId: widget.clientId,
        path: path,
        type: type,
        number: number.text.trim(),
        expiry: expiry.text.trim(),
      );
      await _load(hydrate: false);
      _show('مدرک برای بررسی ارسال شد.');
    } catch (e) {
      _show(_api.message(e));
    } finally {
      number.dispose();
      expiry.dispose();
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _acceptTerms() async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تعهد و قوانین راننده'),
        content: const SingleChildScrollView(
          child: Text(
            'با تأیید این مرحله اعلام می‌کنم اطلاعات و مدارک ثبت‌شده متعلق به پرونده واقعی من است، حساب راننده را در اختیار شخص دیگری قرار نمی‌دهم، هنگام فعالیت قوانین ایمنی و مقررات RADO را رعایت می‌کنم و با بررسی دوره‌ای اعتبار مدارک موافقم.',
            style: TextStyle(height: 1.8),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('فعلاً نه')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('می‌پذیرم')),
        ],
      ),
    );
    if (accepted != true) return;
    await _run(() => _api.action(widget.clientId, 'accept_terms'));
  }

  Future<void> _submit() async {
    await _run(() async {
      final data = await _api.action(widget.clientId, 'submit');
      _show('پرونده برای بررسی مدیریت ارسال شد.');
      return data;
    });
  }

  void _show(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) {
    final v = _verification;
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        backgroundColor: const Color(0xFFF6F6F4),
        appBar: AppBar(
          title: const Text('احراز هویت راننده'),
          actions: [IconButton(onPressed: _busy ? null : () => _load(hydrate: false), icon: const Icon(Icons.refresh_rounded))],
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : v == null
                ? const Center(child: Text('اطلاعات احراز هویت دریافت نشد.'))
                : ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      _progressCard(v),
                      const SizedBox(height: 12),
                      _corrections(v),
                      _identityCard(v),
                      const SizedBox(height: 12),
                      _mobileCard(v),
                      const SizedBox(height: 12),
                      _checksCard(v),
                      const SizedBox(height: 12),
                      _biometricCard(v),
                      const SizedBox(height: 12),
                      _documentsCard(v),
                      const SizedBox(height: 12),
                      _termsCard(v),
                      const SizedBox(height: 12),
                      _submitCard(v),
                      const SizedBox(height: 28),
                    ],
                  ),
      ),
    );
  }

  Widget _progressCard(Map<String, dynamic> v) {
    final progress = (v['progress'] as num?)?.toInt() ?? 0;
    final status = _s(v['review_status']);
    return _Card(
      dark: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.verified_user_rounded, color: _yellow, size: 34),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('پرونده راننده RADO', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 18)),
                    Text(_reviewFa(status), style: const TextStyle(color: Colors.white70)),
                  ],
                ),
              ),
              Text('$progress٪', style: const TextStyle(color: _yellow, fontSize: 22, fontWeight: FontWeight.w900)),
            ],
          ),
          const SizedBox(height: 12),
          LinearProgressIndicator(value: progress / 100, minHeight: 8),
          if (!(v['api_ir_configured'] == true)) ...[
            const SizedBox(height: 10),
            const Text('اتصال API.ir هنوز توسط مدیریت تنظیم نشده است.', style: TextStyle(color: Colors.orangeAccent, fontSize: 12)),
          ],
        ],
      ),
    );
  }

  Widget _corrections(Map<String, dynamic> v) {
    final items = _list(v['corrections']);
    if (items.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: _Card(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('موارد نیازمند اصلاح', style: TextStyle(fontWeight: FontWeight.w900, color: Colors.red)),
            const SizedBox(height: 8),
            ...items.map((e) => Padding(
                  padding: const EdgeInsets.only(bottom: 7),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Icon(Icons.error_outline_rounded, color: Colors.red, size: 18),
                    const SizedBox(width: 7),
                    Expanded(child: Text(_s(e['message']))),
                  ]),
                )),
          ],
        ),
      ),
    );
  }

  Widget _identityCard(Map<String, dynamic> v) => _Card(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _title(Icons.badge_rounded, '۱. اطلاعات هویتی و خودرو'),
            _field(_fullName, 'نام و نام خانوادگی'),
            _field(_mobile, 'شماره موبایل', keyboard: TextInputType.phone),
            _field(_nationalCode, 'کد ملی', keyboard: TextInputType.number),
            _field(_birthDate, 'تاریخ تولد شمسی — مثال 1370/1/1', keyboard: TextInputType.datetime),
            _field(_nationalSerial, 'سریال پشت کارت ملی / کد رهگیری'),
            _field(_licenseNumber, 'شماره گواهینامه'),
            _field(_iban, 'شماره شبا — IR...'),
            const SizedBox(height: 6),
            const Text('مالک خودرو', style: TextStyle(fontWeight: FontWeight.w800)),
            DropdownButtonFormField<String>(
              initialValue: _ownerRelation,
              items: const [
                DropdownMenuItem(value: 'self', child: Text('خودم')),
                DropdownMenuItem(value: 'family', child: Text('عضو خانواده')),
                DropdownMenuItem(value: 'other', child: Text('شخص دیگر')),
              ],
              onChanged: _busy ? null : (x) => setState(() => _ownerRelation = x ?? 'self'),
            ),
            _field(_ownerNational, 'کد ملی مالک خودرو', keyboard: TextInputType.number),
            const SizedBox(height: 8),
            const Text('پلاک خودرو', style: TextStyle(fontWeight: FontWeight.w800)),
            Row(children: [
              Expanded(child: _field(_plate1, 'بخش ۱', keyboard: TextInputType.number)),
              const SizedBox(width: 6),
              Expanded(child: _field(_plateLetter, 'حرف')),
              const SizedBox(width: 6),
              Expanded(child: _field(_plate2, 'بخش ۲', keyboard: TextInputType.number)),
              const SizedBox(width: 6),
              Expanded(child: _field(_plate3, 'ایران', keyboard: TextInputType.number)),
            ]),
            _field(_vehicleMake, 'برند خودرو (در صورت اطلاع)'),
            _field(_vehicleModel, 'مدل خودرو (در صورت اطلاع)'),
            _field(_vehicleColor, 'رنگ خودرو (در صورت اطلاع)'),
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _busy ? null : _saveIdentity,
                style: FilledButton.styleFrom(backgroundColor: _black, foregroundColor: Colors.white),
                icon: const Icon(Icons.save_rounded),
                label: const Text('ذخیره اطلاعات'),
              ),
            ),
          ],
        ),
      );

  Widget _mobileCard(Map<String, dynamic> v) {
    final p = _map(v['profile']);
    final verified = p['mobile_verified'] == true;
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _title(Icons.sms_rounded, '۲. تأیید شماره موبایل'),
          Text(verified ? 'شماره ${_s(p['mobile'])} تأیید شده است.' : 'کد تأیید به شماره ثبت‌شده ارسال می‌شود.'),
          const SizedBox(height: 8),
          if (!verified) ...[
            Row(children: [
              Expanded(child: OutlinedButton(onPressed: _busy ? null : () => _sendOtp(), child: const Text('ارسال پیامک'))),
              const SizedBox(width: 8),
              Expanded(child: OutlinedButton(onPressed: _busy ? null : () => _sendOtp(voice: true), child: const Text('تماس صوتی'))),
            ]),
            Row(children: [
              Expanded(child: _field(_otp, 'کد ۶ رقمی', keyboard: TextInputType.number)),
              const SizedBox(width: 8),
              FilledButton(onPressed: _busy ? null : _verifyOtp, child: const Text('تأیید')),
            ]),
          ] else
            const _Passed(text: 'مالکیت شماره موبایل تأیید شده'),
        ],
      ),
    );
  }

  Widget _checksCard(Map<String, dynamic> v) {
    final c = _map(v['checks']);
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _title(Icons.fact_check_rounded, '۳. استعلام‌های API.ir'),
          _checkRow('شاهکار — تطبیق کد ملی و موبایل', _s(c['shahkar']), () => _check('run_shahkar', 'شاهکار تأیید شد.')),
          _checkRow('گواهینامه رانندگی', _s(c['license']), () => _check('run_license', 'گواهینامه تأیید شد.')),
          _checkRow('نمره منفی گواهینامه', _s(c['driving_score']), () => _check('run_driving_score', 'استعلام نمره منفی انجام شد.')),
          _checkRow('خودرو و پلاک', _s(c['vehicle']), () => _check('run_vehicle', 'خودرو تأیید شد.')),
          _checkRow('پلاک‌های فعال', _s(c['active_plates']), () => _check('run_active_plates', 'پلاک‌های فعال بررسی شد.'), optional: true),
          _checkRow('تطبیق کد ملی با شبا', _s(c['iban']), () => _check('run_iban', 'شماره شبا تأیید شد.')),
        ],
      ),
    );
  }

  Widget _biometricCard(Map<String, dynamic> v) {
    final c = _map(v['checks']);
    final mode = _s(v['biometric_mode']);
    final speech = _s(v['speech_text']);
    final scores = _map(v['scores']);
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _title(Icons.face_retouching_natural_rounded, '۴. احراز چهره و زنده‌سنجی'),
          if (_s(c['biometric']) == 'passed')
            _Passed(text: 'چهره و زنده‌بودن تأیید شد · Match ${_s(scores['matching'])} · Live ${_s(scores['liveness'])}')
          else ...[
            const Text('در نور مناسب، بدون عینک آفتابی و با دوربین جلوی گوشی ویدئوی کوتاه بگیر.'),
            if (mode == 'VideoVerify' && speech.isNotEmpty) ...[
              const SizedBox(height: 8),
              const Text('متن زیر را هنگام ضبط بخوان:', style: TextStyle(fontWeight: FontWeight.w800)),
              Container(width: double.infinity, margin: const EdgeInsets.only(top: 6), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: const Color(0xFFFFF5CF), borderRadius: BorderRadius.circular(12)), child: Text(speech)),
            ],
            const SizedBox(height: 10),
            SizedBox(width: double.infinity, child: FilledButton.icon(onPressed: _busy ? null : _recordBiometric, icon: const Icon(Icons.videocam_rounded), label: const Text('ضبط و ارسال ویدئوی احراز هویت'))),
            const SizedBox(height: 5),
            const Text('ویدئو فقط برای استعلام ارسال می‌شود و RADO آن را در پرونده ذخیره نمی‌کند.', style: TextStyle(fontSize: 11, color: Colors.black54)),
          ],
        ],
      ),
    );
  }

  Widget _documentsCard(Map<String, dynamic> v) {
    final docs = _list(v['documents']);
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _title(Icons.folder_copy_rounded, '۵. مدارک لازم'),
          ...docs.map((d) => ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: Icon(_docIcon(_s(d['status'])), color: _docColor(_s(d['status']))),
                title: Text(_s(d['label']), style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: _s(d['note']).isEmpty ? Text(_docStatusFa(_s(d['status']))) : Text('${_docStatusFa(_s(d['status']))} · ${_s(d['note'])}'),
                trailing: TextButton(onPressed: _busy ? null : () => _uploadDocument(d), child: Text(_s(d['status']) == 'missing' ? 'ارسال' : 'ارسال مجدد')),
              )),
        ],
      ),
    );
  }

  Widget _termsCard(Map<String, dynamic> v) => _Card(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _title(Icons.gavel_rounded, '۶. تعهد راننده'),
            if (v['consent_accepted'] == true)
              _Passed(text: 'نسخه قوانین ${_s(v['terms_version'])} پذیرفته شده است')
            else
              SizedBox(width: double.infinity, child: OutlinedButton.icon(onPressed: _busy ? null : _acceptTerms, icon: const Icon(Icons.check_circle_outline), label: const Text('مطالعه و پذیرش قوانین'))),
          ],
        ),
      );

  Widget _submitCard(Map<String, dynamic> v) {
    final missing = (_list(v['missing'])).map((e) => e.toString()).toList();
    final ready = v['ready_to_submit'] == true;
    final status = _s(v['review_status']);
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _title(Icons.send_rounded, '۷. ارسال برای بررسی نهایی'),
          if (status == 'approved')
            const _Passed(text: 'پرونده شما تأیید نهایی شده است')
          else if (status == 'submitted' || status == 'under_review')
            const Text('پرونده ارسال شده و در صف بررسی مدیریت RADO است.', style: TextStyle(fontWeight: FontWeight.w800))
          else ...[
            if (!ready) ...[
              const Text('موارد باقی‌مانده:', style: TextStyle(fontWeight: FontWeight.w800)),
              const SizedBox(height: 5),
              ...missing.take(12).map((x) => Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('• $x', style: const TextStyle(fontSize: 12)))),
            ],
            const SizedBox(height: 10),
            SizedBox(width: double.infinity, child: FilledButton(onPressed: ready && !_busy ? _submit : null, style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), child: const Text('ارسال پرونده برای تأیید'))),
          ],
        ],
      ),
    );
  }

  Widget _checkRow(String title, String status, VoidCallback run, {bool optional = false}) => ListTile(
        contentPadding: EdgeInsets.zero,
        leading: Icon(status == 'passed' ? Icons.verified_rounded : Icons.manage_search_rounded, color: status == 'passed' ? Colors.green : _black),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
        subtitle: Text('${_checkFa(status)}${optional ? ' · اختیاری طبق تنظیم مدیریت' : ''}'),
        trailing: status == 'passed' ? const Icon(Icons.check_circle, color: Colors.green) : TextButton(onPressed: _busy ? null : run, child: const Text('استعلام')),
      );

  Widget _title(IconData icon, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 9),
        child: Row(children: [Icon(icon, color: _yellow), const SizedBox(width: 7), Expanded(child: Text(text, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17)))]),
      );

  Widget _field(TextEditingController c, String label, {TextInputType? keyboard}) => Padding(
        padding: const EdgeInsets.only(top: 7),
        child: TextField(controller: c, keyboardType: keyboard, enabled: !_busy, decoration: InputDecoration(labelText: label, border: const OutlineInputBorder())),
      );
}

class _VerificationApi {
  _VerificationApi()
      : _dio = Dio(BaseOptions(
          baseUrl: _apiBase,
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 80),
          headers: const {'Accept': 'application/json'},
          validateStatus: (s) => s != null && s < 500,
        ));
  final Dio _dio;

  Future<Map<String, dynamic>> get(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/driver/verification/', queryParameters: {'client_id': clientId});
    return _verification(r);
  }

  Future<Map<String, dynamic>> action(String clientId, String action, [Map<String, dynamic> extra = const {}]) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/driver/verification/', data: {'client_id': clientId, 'action': action, ...extra});
    return _verification(r);
  }

  Future<Map<String, dynamic>> uploadVideo(String clientId, String path) async {
    final form = FormData.fromMap({
      'client_id': clientId,
      'action': 'run_biometric',
      'video': await MultipartFile.fromFile(path, filename: path.split(Platform.pathSeparator).last),
    });
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/driver/verification/', data: form, options: Options(contentType: 'multipart/form-data', receiveTimeout: const Duration(seconds: 90)));
    return _verification(r);
  }

  Future<void> uploadDocument({required String clientId, required String path, required String type, String number = '', String expiry = ''}) async {
    final form = FormData.fromMap({
      'client_id': clientId,
      'document_type': type,
      'document_number': number,
      'expires_at': expiry,
      'document': await MultipartFile.fromFile(path, filename: path.split(Platform.pathSeparator).last),
    });
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/driver/documents/', data: form, options: Options(contentType: 'multipart/form-data'));
    _ensure(r);
  }

  Map<String, dynamic> _verification(Response<Map<String, dynamic>> r) {
    _ensure(r);
    final data = r.data?['verification'];
    if (data is Map<String, dynamic>) return data;
    if (data is Map) return data.cast<String, dynamic>();
    throw const _VerificationException('پاسخ احراز هویت ناقص است.');
  }

  void _ensure(Response<Map<String, dynamic>> r) {
    final d = r.data;
    final ok = (r.statusCode ?? 500) >= 200 && (r.statusCode ?? 500) < 300 && d?['ok'] != false;
    if (ok) return;
    final msg = d?['message']?.toString() ?? d?['error']?.toString() ?? 'عملیات احراز هویت انجام نشد.';
    throw _VerificationException(msg);
  }

  String message(Object e) {
    if (e is _VerificationException) return e.message;
    if (e is DioException) {
      final d = e.response?.data;
      if (d is Map && d['message'] != null) return d['message'].toString();
      if (e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.receiveTimeout) return 'ارتباط با سرویس احراز هویت طول کشید؛ دوباره تلاش کنید.';
      if (e.type == DioExceptionType.connectionError) return 'ارتباط با سرور RADO برقرار نشد.';
    }
    return 'عملیات احراز هویت انجام نشد؛ دوباره تلاش کنید.';
  }
}

class _VerificationException implements Exception {
  const _VerificationException(this.message);
  final String message;
}

class _Card extends StatelessWidget {
  const _Card({required this.child, this.dark = false});
  final Widget child;
  final bool dark;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: dark ? _black : Colors.white, borderRadius: BorderRadius.circular(22), boxShadow: const [BoxShadow(color: Color(0x11000000), blurRadius: 18, offset: Offset(0, 6))]),
        child: child,
      );
}

class _Passed extends StatelessWidget {
  const _Passed({required this.text});
  final String text;
  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: const Color(0xFFEAF7EE), borderRadius: BorderRadius.circular(12)),
        child: Row(children: [const Icon(Icons.check_circle_rounded, color: Colors.green), const SizedBox(width: 7), Expanded(child: Text(text, style: const TextStyle(fontWeight: FontWeight.w800, color: Colors.green)))]),
      );
}

Map<String, dynamic> _map(dynamic v) => v is Map<String, dynamic> ? v : v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};
List<Map<String, dynamic>> _list(dynamic v) => v is List ? v.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList() : <Map<String, dynamic>>[];
String _s(dynamic v) => v?.toString() ?? '';
String _reviewFa(String s) => switch (s) {
      'approved' => 'تأیید نهایی',
      'submitted' => 'ارسال‌شده برای بررسی',
      'under_review' => 'در حال بررسی مدیریت',
      'needs_correction' => 'نیاز به اصلاح',
      'rejected' => 'ردشده',
      'suspended' => 'تعلیق‌شده',
      _ => 'در حال تکمیل',
    };
String _checkFa(String s) => switch (s) {
      'passed' => 'تأیید شده',
      'failed' => 'تأیید نشد',
      'error' => 'خطای سرویس',
      'review' => 'نیاز به بررسی',
      'pending' => 'در حال بررسی',
      _ => 'انجام نشده',
    };
String _docStatusFa(String s) => switch (s) {
      'approved' => 'تأیید مدیریت',
      'pending' => 'در انتظار بررسی',
      'rejected' => 'رد شده — دوباره ارسال کن',
      'expired' => 'منقضی شده',
      _ => 'ثبت نشده',
    };
IconData _docIcon(String s) => switch (s) { 'approved' => Icons.verified_rounded, 'pending' => Icons.hourglass_top_rounded, 'rejected' => Icons.error_rounded, 'expired' => Icons.event_busy_rounded, _ => Icons.upload_file_rounded };
Color _docColor(String s) => switch (s) { 'approved' => Colors.green, 'pending' => Colors.orange, 'rejected' => Colors.red, 'expired' => Colors.red, _ => Colors.black54 };
