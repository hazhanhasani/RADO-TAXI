import 'dart:async';
import 'dart:math';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'driver_platform.dart';

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
const _surface = Color(0xFFF6F6F4);

class AdvancedDriverPage extends StatefulWidget {
  const AdvancedDriverPage({super.key});
  @override
  State<AdvancedDriverPage> createState() => _AdvancedDriverPageState();
}

class _AdvancedDriverPageState extends State<AdvancedDriverPage> {
  final _api = DriverPlatformApi();
  String? _clientId;
  DriverSession? _session;
  DriverWallet? _wallet;
  DriverOffer? _offer;
  DriverTrip? _trip;
  DriverPreferences _prefs = const DriverPreferences();
  List<DriverMission> _missions = const [];
  List<Settlement> _settlements = const [];
  List<DriverDocument> _documents = const [];
  List<DriverSupportTicket> _tickets = const [];
  bool _online = false;
  bool _busy = false;
  bool _loading = true;
  int _tab = 0;
  Timer? _poller;

  bool get _approved => _session?.approved == true;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _poller?.cancel();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    try {
      final p = await SharedPreferences.getInstance();
      var id = p.getString('rado_driver_client_id');
      if (id == null || id.isEmpty) {
        id = '${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
        await p.setString('rado_driver_client_id', id);
      }
      _clientId = id;
      await _refresh(all: true);
      _poller = Timer.periodic(const Duration(seconds: 8), (_) => _tick());
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _tick() async {
    final id = _clientId;
    if (id == null || _busy) return;
    try {
      if (_online && _approved) {
        final p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 10));
        await _api.presence(id, online: true, lat: p.latitude, lng: p.longitude, heading: p.heading.round(), speedKph: max(0, p.speed * 3.6));
      }
      await _refresh();
    } catch (_) {}
  }

  Future<void> _refresh({bool all = false}) async {
    final id = _clientId;
    if (id == null) return;
    final session = await _api.session(id);
    DriverState? state;
    if (session.approved) state = await _api.offers(id);
    final wallet = await _api.wallet(id);
    DriverPreferences? prefs;
    List<DriverMission>? missions;
    List<Settlement>? settlements;
    List<DriverDocument>? documents;
    List<DriverSupportTicket>? tickets;
    if (all && session.approved) {
      try { prefs = await _api.preferences(id); } catch (_) {}
      try { missions = await _api.missions(id); } catch (_) {}
      try { settlements = await _api.settlements(id); } catch (_) {}
      try { documents = await _api.documents(id); } catch (_) {}
      try { tickets = await _api.support(id); } catch (_) {}
    }
    if (!mounted) return;
    setState(() {
      _session = session;
      _online = session.online || _online;
      _wallet = wallet;
      _offer = state?.offer;
      _trip = state?.activeTrip;
      if (prefs != null) _prefs = prefs;
      if (missions != null) _missions = missions;
      if (settlements != null) _settlements = settlements;
      if (documents != null) _documents = documents;
      if (tickets != null) _tickets = tickets;
    });
  }

  Future<bool> _locationPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      _show('برای آنلاین شدن، GPS گوشی را روشن کنید.');
      return false;
    }
    var p = await Geolocator.checkPermission();
    if (p == LocationPermission.denied) p = await Geolocator.requestPermission();
    if (p == LocationPermission.denied || p == LocationPermission.deniedForever) {
      _show('اجازه موقعیت برای دریافت سفر لازم است.');
      return false;
    }
    return true;
  }

  Future<void> _toggleOnline() async {
    final id = _clientId;
    if (id == null || _busy) return;
    if (!_approved) {
      _show('حساب راننده باید ابتدا توسط مدیریت RADO تأیید شود.');
      return;
    }
    setState(() => _busy = true);
    try {
      if (_online) {
        await _api.presence(id, online: false);
        setState(() => _online = false);
      } else {
        if (!await _locationPermission()) return;
        final p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high));
        await _api.presence(id, online: true, lat: p.latitude, lng: p.longitude, heading: p.heading.round(), speedKph: max(0, p.speed * 3.6));
        setState(() => _online = true);
      }
      await _refresh();
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _answer(bool accept) async {
    final id = _clientId, o = _offer;
    if (id == null || o == null || _busy) return;
    setState(() => _busy = true);
    try {
      final t = await _api.answerOffer(id, o.id, accept);
      if (!mounted) return;
      setState(() {
        _offer = null;
        if (t != null) _trip = t;
      });
      _show(accept ? 'سفر پذیرفته شد.' : 'درخواست رد شد.');
      await _refresh();
    } catch (e) {
      _show(_api.message(e));
      await _refresh();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _tripAction(String action) async {
    final id = _clientId, t = _trip;
    if (id == null || t == null || _busy) return;
    setState(() => _busy = true);
    try {
      final r = await _api.tripAction(id, t.id, action);
      if (!mounted) return;
      setState(() => _trip = r.trip);
      if (action == 'complete' && r.finance != null) {
        _show('سفر تمام شد؛ درآمد خالص ${money(r.finance!.driverNet)}، کمیسیون ${money(r.finance!.commission)}');
      } else if (action == 'arrived') {
        _show('رسیدن به مبدا ثبت شد.');
      } else if (action == 'start') {
        _show('سفر شروع شد.');
      }
      await _refresh(all: action == 'complete');
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _navigate(TripPoint p) async {
    final label = Uri.encodeComponent(p.label.isEmpty ? 'RADO' : p.label);
    final uri = Uri.parse('geo:${p.lat},${p.lng}?q=${p.lat},${p.lng}($label)');
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) _show('مسیریاب در دسترس نیست.');
  }

  Future<void> _savePreferences(DriverPreferences p) async {
    final id = _clientId;
    if (id == null) return;
    setState(() => _busy = true);
    try {
      await _api.savePreferences(id, p);
      setState(() => _prefs = p);
      _show('تنظیمات دریافت سفر ذخیره شد.');
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickDestination() async {
    LatLng near = const LatLng(35.9968, 45.8853);
    try {
      if (await _locationPermission()) {
        final p = await Geolocator.getCurrentPosition();
        near = LatLng(p.latitude, p.longitude);
      }
    } catch (_) {}
    if (!mounted) return;
    final place = await showModalBottomSheet<DriverPlace>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => _DriverPlaceSearch(api: _api, near: near));
    if (place != null) {
      await _savePreferences(_prefs.copyWith(destinationLabel: place.label, destinationLat: place.lat, destinationLng: place.lng));
    }
  }

  Future<void> _requestSettlement() async {
    final id = _clientId;
    final wallet = _wallet;
    if (id == null || wallet == null) return;
    final c = TextEditingController(text: wallet.balance.toString());
    final amount = await showDialog<int>(context: context, builder: (ctx) => AlertDialog(title: const Text('درخواست تسویه'), content: TextField(controller: c, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'مبلغ (ریال)', helperText: 'موجودی: ${money(wallet.balance)}')), actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('لغو')), FilledButton(onPressed: () => Navigator.pop(ctx, int.tryParse(c.text)), child: const Text('ثبت درخواست'))]));
    if (amount == null || amount <= 0) return;
    try {
      await _api.requestSettlement(id, amount);
      _show('درخواست تسویه ثبت شد.');
      final x = await _api.settlements(id);
      if (mounted) setState(() => _settlements = x);
    } catch (e) {
      _show(_api.message(e));
    }
  }

  Future<void> _uploadDocument() async {
    final id = _clientId;
    if (id == null) return;
    final picked = await FilePicker.platform.pickFiles(type: FileType.custom, allowedExtensions: const ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
    final path = picked?.files.single.path;
    if (path == null || !mounted) return;
    String type = 'driver_license';
    final number = TextEditingController();
    final expiry = TextEditingController();
    final ok = await showDialog<bool>(context: context, builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) => AlertDialog(title: const Text('ارسال مدرک'), content: Column(mainAxisSize: MainAxisSize.min, children: [DropdownButtonFormField<String>(value: type, items: const [DropdownMenuItem(value: 'driver_license', child: Text('گواهینامه')), DropdownMenuItem(value: 'national_card', child: Text('کارت ملی')), DropdownMenuItem(value: 'vehicle_card', child: Text('کارت خودرو')), DropdownMenuItem(value: 'insurance', child: Text('بیمه')), DropdownMenuItem(value: 'inspection', child: Text('معاینه فنی')), DropdownMenuItem(value: 'other', child: Text('سایر'))], onChanged: (v) => setLocal(() => type = v ?? 'other')), TextField(controller: number, decoration: const InputDecoration(labelText: 'شماره مدرک (اختیاری)')), TextField(controller: expiry, decoration: const InputDecoration(labelText: 'تاریخ انقضا فنی YYYY-MM-DD (اختیاری)'))]), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('لغو')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('ارسال'))])));
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      await _api.uploadDocument(clientId: id, path: path, type: type, number: number.text.trim(), expiry: expiry.text.trim());
      _show('مدرک برای بررسی مدیریت ارسال شد.');
      final docs = await _api.documents(id);
      if (mounted) setState(() => _documents = docs);
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _newSupport() async {
    final id = _clientId;
    if (id == null) return;
    final subject = TextEditingController();
    final text = TextEditingController();
    final ok = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(title: const Text('پشتیبانی رانندگان'), content: Column(mainAxisSize: MainAxisSize.min, children: [TextField(controller: subject, decoration: const InputDecoration(labelText: 'موضوع')), TextField(controller: text, minLines: 3, maxLines: 5, decoration: const InputDecoration(labelText: 'پیام'))]), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('لغو')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('ارسال'))]));
    if (ok == true && subject.text.trim().isNotEmpty && text.text.trim().isNotEmpty) {
      try {
        await _api.createSupport(id, subject.text.trim(), text.text.trim(), tripId: _trip?.id);
        _show('تیکت پشتیبانی ثبت شد.');
        final x = await _api.support(id);
        if (mounted) setState(() => _tickets = x);
      } catch (e) {
        _show(_api.message(e));
      }
    }
  }

  void _show(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      _home(),
      _trips(),
      _earnings(),
      _account(),
    ];
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        backgroundColor: _surface,
        body: SafeArea(child: IndexedStack(index: _tab, children: pages)),
        bottomNavigationBar: NavigationBar(selectedIndex: _tab, onDestinationSelected: (v) { setState(() => _tab = v); if (v > 1) _refresh(all: true); }, destinations: const [NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'خانه'), NavigationDestination(icon: Icon(Icons.route_outlined), selectedIcon: Icon(Icons.route_rounded), label: 'سفر'), NavigationDestination(icon: Icon(Icons.account_balance_wallet_outlined), selectedIcon: Icon(Icons.account_balance_wallet_rounded), label: 'درآمد'), NavigationDestination(icon: Icon(Icons.person_outline_rounded), selectedIcon: Icon(Icons.person_rounded), label: 'حساب')]),
      ),
    );
  }

  Widget _header() => Row(children: [ClipRRect(borderRadius: BorderRadius.circular(18), child: Image.asset('assets/branding/rado-driver.png', width: 58, height: 58)), const SizedBox(width: 11), const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('RADO Driver', style: TextStyle(fontSize: 21, fontWeight: FontWeight.w900)), Text('راننده رادو · بانه', style: TextStyle(fontSize: 11, color: Colors.black54))])), _Badge(_approved ? (_online ? 'آنلاین' : 'آفلاین') : 'در انتظار تأیید', _approved && _online)]);

  Widget _home() => RefreshIndicator(onRefresh: () => _refresh(all: true), child: ListView(padding: const EdgeInsets.all(16), children: [
    _header(), const SizedBox(height: 15),
    if (_loading) const Center(child: Padding(padding: EdgeInsets.all(40), child: CircularProgressIndicator())) else ...[
      if (!_approved) _Box(child: Row(children: [const Icon(Icons.verified_user_outlined), const SizedBox(width: 9), Expanded(child: Text('وضعیت حساب: ${_session?.statusFa ?? 'در انتظار تأیید مدیریت'}', style: const TextStyle(fontWeight: FontWeight.w800)))])),
      const SizedBox(height: 10),
      Container(padding: const EdgeInsets.all(17), decoration: BoxDecoration(color: _black, borderRadius: BorderRadius.circular(25)), child: Row(children: [Icon(_online ? Icons.radar_rounded : Icons.power_settings_new_rounded, color: _yellow, size: 34), const SizedBox(width: 10), Expanded(child: Text(_online ? 'آماده دریافت سفر' : 'برای دریافت سفر آنلاین شو', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900))), FilledButton(onPressed: _busy || !_approved ? null : _toggleOnline, style: FilledButton.styleFrom(backgroundColor: _online ? Colors.white : _yellow, foregroundColor: _black), child: Text(_online ? 'آفلاین' : 'آنلاین'))])),
      const SizedBox(height: 12),
      Row(children: [Expanded(child: _Stat('درآمد امروز', money(_wallet?.todayNet ?? 0), Icons.payments_rounded)), const SizedBox(width: 8), Expanded(child: _Stat('سفر امروز', '${_wallet?.todayTrips ?? 0} سفر', Icons.local_taxi_rounded))]),
      const SizedBox(height: 10),
      if (_trip != null) _activeTrip(_trip!) else if (_offer != null) _offerCard(_offer!) else _Box(child: Column(children: [Icon(_online ? Icons.radar_rounded : Icons.local_taxi_outlined, size: 42), const SizedBox(height: 8), Text(_online ? 'در انتظار درخواست نزدیک' : 'در حال حاضر آفلاین هستی', style: const TextStyle(fontWeight: FontWeight.w900)), if (_online) ...[const SizedBox(height: 10), const LinearProgressIndicator(minHeight: 3)]])),
    ]
  ]));

  Widget _offerCard(DriverOffer o) => _Box(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('درخواست سفر جدید', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)), Text(o.offeredAt, style: const TextStyle(fontSize: 10, color: Colors.black54)), const SizedBox(height: 10), _Loc(Icons.radio_button_checked, Colors.green, o.trip.pickup.label), const SizedBox(height: 7), _Loc(Icons.location_on, Colors.red, o.trip.destination.label), const SizedBox(height: 10), Row(children: [Expanded(child: _Stat('کرایه', money(o.trip.estimatedFare), Icons.payments_outlined)), const SizedBox(width: 7), Expanded(child: _Stat('مسافت', o.trip.distanceLabel, Icons.route_rounded))]), const SizedBox(height: 10), Row(children: [Expanded(child: OutlinedButton(onPressed: _busy ? null : () => _answer(false), child: const Text('رد'))), const SizedBox(width: 8), Expanded(child: FilledButton(onPressed: _busy ? null : () => _answer(true), style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), child: const Text('قبول سفر')))])]));

  Widget _activeTrip(DriverTrip t) {
    final toPickup = t.status == 'driver_assigned' || t.status == 'driver_arriving';
    final inProgress = t.status == 'in_progress';
    final action = toPickup ? 'arrived' : t.status == 'arrived' ? 'start' : inProgress ? 'complete' : null;
    final label = toPickup ? 'رسیدم' : t.status == 'arrived' ? 'شروع سفر' : inProgress ? 'پایان سفر' : '';
    final nav = inProgress ? t.destination : t.pickup;
    return _Box(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(t.statusFa, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)), Text(t.requestedAt, style: const TextStyle(fontSize: 10, color: Colors.black54)), const SizedBox(height: 9), _Loc(Icons.radio_button_checked, Colors.green, t.pickup.label), const SizedBox(height: 7), _Loc(Icons.location_on, Colors.red, t.destination.label), const SizedBox(height: 10), SizedBox(width: double.infinity, child: OutlinedButton.icon(onPressed: () => _navigate(nav), icon: const Icon(Icons.navigation_rounded), label: Text(inProgress ? 'مسیریابی تا مقصد' : 'مسیریابی تا مبدا'))), if (action != null) ...[const SizedBox(height: 7), SizedBox(width: double.infinity, height: 50, child: FilledButton(onPressed: _busy ? null : () => _tripAction(action), style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), child: Text(label, style: const TextStyle(fontWeight: FontWeight.w900))))]]));
  }

  Widget _trips() => ListView(padding: const EdgeInsets.all(18), children: [_header(), const SizedBox(height: 15), const Text('سفر جاری', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 19)), const SizedBox(height: 8), if (_trip != null) _activeTrip(_trip!) else const _Box(child: Text('سفر فعالی نداری.', textAlign: TextAlign.center)), const SizedBox(height: 15), const Text('آخرین تراکنش‌های سفر', style: TextStyle(fontWeight: FontWeight.w900)), ...?_wallet?.entries.take(20).map((e) => ListTile(leading: const Icon(Icons.receipt_long_rounded), title: Text(e.title), subtitle: Text(e.jalali), trailing: Text(_signed(e.amount), style: TextStyle(color: e.amount >= 0 ? Colors.green.shade700 : Colors.red.shade700))))]);

  Widget _earnings() => RefreshIndicator(onRefresh: () => _refresh(all: true), child: ListView(padding: const EdgeInsets.all(18), children: [_header(), const SizedBox(height: 15), Row(children: [Expanded(child: _Stat('موجودی', money(_wallet?.balance ?? 0), Icons.account_balance_wallet_rounded)), const SizedBox(width: 8), Expanded(child: _Stat('کمیسیون', '${_wallet?.commissionRate.toStringAsFixed(1) ?? '0'}٪', Icons.percent_rounded))]), const SizedBox(height: 10), _Box(child: Column(children: [Text('درآمد خالص امروز: ${money(_wallet?.todayNet ?? 0)}', style: const TextStyle(fontWeight: FontWeight.w900)), Text('کمیسیون امروز: ${money(_wallet?.todayCommission ?? 0)}'), const SizedBox(height: 10), SizedBox(width: double.infinity, child: FilledButton.icon(onPressed: (_wallet?.balance ?? 0) > 0 ? _requestSettlement : null, icon: const Icon(Icons.account_balance_rounded), label: const Text('درخواست تسویه')))])), const SizedBox(height: 12), const Text('تسویه‌ها', style: TextStyle(fontWeight: FontWeight.w900)), if (_settlements.isEmpty) const ListTile(title: Text('درخواست تسویه‌ای ثبت نشده است.')), ..._settlements.take(20).map((s) => ListTile(leading: const Icon(Icons.payments_outlined), title: Text(money(s.amount)), subtitle: Text(s.requestedAt), trailing: _Badge(_settlementFa(s.status), s.status == 'paid')))]));

  Widget _account() => RefreshIndicator(onRefresh: () => _refresh(all: true), child: ListView(padding: const EdgeInsets.all(18), children: [_header(), const SizedBox(height: 15), _Box(child: Column(children: [Text(_session?.name ?? 'راننده RADO', style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)), Text('${_session?.vehicle ?? ''} ${_session?.plate ?? ''}', style: const TextStyle(color: Colors.black54))])), const SizedBox(height: 12), _preferencesCard(), const SizedBox(height: 12), _missionsCard(), const SizedBox(height: 12), _documentsCard(), const SizedBox(height: 12), _Box(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('پشتیبانی', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17)), ..._tickets.take(5).map((t) => ListTile(contentPadding: EdgeInsets.zero, dense: true, title: Text(t.subject), subtitle: Text('${t.createdAt} · ${t.status}'))), SizedBox(width: double.infinity, child: OutlinedButton.icon(onPressed: _newSupport, icon: const Icon(Icons.support_agent_rounded), label: const Text('پیام به پشتیبانی')))]))]));

  Widget _preferencesCard() {
    final maxCtrl = TextEditingController(text: _prefs.maxPickupDistanceKm.toStringAsFixed(1));
    final minCtrl = TextEditingController(text: _prefs.minFare.toString());
    final autoRadius = TextEditingController(text: _prefs.autoAcceptRadiusKm.toStringAsFixed(1));
    final autoMin = TextEditingController(text: _prefs.autoAcceptMinFare.toString());
    return _Box(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('فیلتر و مقصد دلخواه', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17)), const SizedBox(height: 8), ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.assistant_direction_rounded), title: Text(_prefs.destinationLabel.isEmpty ? 'مقصد دلخواه خاموش' : _prefs.destinationLabel, maxLines: 2, overflow: TextOverflow.ellipsis), trailing: TextButton(onPressed: _pickDestination, child: Text(_prefs.destinationLabel.isEmpty ? 'انتخاب' : 'تغییر'))), if (_prefs.destinationLabel.isNotEmpty) Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: () => _savePreferences(_prefs.copyWith(clearDestination: true)), child: const Text('حذف مقصد دلخواه'))), Row(children: [Expanded(child: TextField(controller: maxCtrl, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'حداکثر فاصله تا مسافر km'))), const SizedBox(width: 8), Expanded(child: TextField(controller: minCtrl, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'حداقل کرایه')))]), SwitchListTile(contentPadding: EdgeInsets.zero, value: _prefs.autoAccept, onChanged: (v) => _savePreferences(_prefs.copyWith(autoAccept: v)), title: const Text('پذیرش خودکار')), if (_prefs.autoAccept) Row(children: [Expanded(child: TextField(controller: autoRadius, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'شعاع خودکار km'))), const SizedBox(width: 8), Expanded(child: TextField(controller: autoMin, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'حداقل کرایه خودکار')))]), const SizedBox(height: 8), SizedBox(width: double.infinity, child: FilledButton(onPressed: () => _savePreferences(_prefs.copyWith(maxPickupDistanceKm: double.tryParse(maxCtrl.text) ?? _prefs.maxPickupDistanceKm, minFare: int.tryParse(minCtrl.text) ?? _prefs.minFare, autoAcceptRadiusKm: double.tryParse(autoRadius.text) ?? _prefs.autoAcceptRadiusKm, autoAcceptMinFare: int.tryParse(autoMin.text) ?? _prefs.autoAcceptMinFare)), style: FilledButton.styleFrom(backgroundColor: _black), child: const Text('ذخیره فیلترها')))]));
  }

  Widget _missionsCard() => _Box(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('مأموریت و پاداش', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17)), if (_missions.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 10), child: Text('مأموریت فعالی وجود ندارد.')), ..._missions.map((m) => Padding(padding: const EdgeInsets.only(top: 10), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Row(children: [Expanded(child: Text(m.title, style: const TextStyle(fontWeight: FontWeight.w800))), Text(money(m.reward), style: const TextStyle(color: Colors.green, fontSize: 11))]), if (m.description.isNotEmpty) Text(m.description, style: const TextStyle(fontSize: 11, color: Colors.black54)), const SizedBox(height: 5), LinearProgressIndicator(value: m.ratio), Text('${m.progress} / ${m.target} · تا ${m.endsAt}', style: const TextStyle(fontSize: 10, color: Colors.black54))]))]));

  Widget _documentsCard() => _Box(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('مدارک راننده', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17)), ..._documents.take(8).map((d) => ListTile(contentPadding: EdgeInsets.zero, dense: true, leading: const Icon(Icons.badge_outlined), title: Text(_docFa(d.type)), subtitle: Text([if (d.number.isNotEmpty) d.number, if (d.expiry.isNotEmpty) 'انقضا ${d.expiry}'].join(' · ')), trailing: _Badge(_docStatusFa(d.status), d.status == 'approved'))), SizedBox(width: double.infinity, child: OutlinedButton.icon(onPressed: _busy ? null : _uploadDocument, icon: const Icon(Icons.upload_file_rounded), label: const Text('ارسال مدرک جدید')))]));
}

class _DriverPlaceSearch extends StatefulWidget {
  const _DriverPlaceSearch({required this.api, required this.near});
  final DriverPlatformApi api; final LatLng near;
  @override State<_DriverPlaceSearch> createState()=>_DriverPlaceSearchState();
}
class _DriverPlaceSearchState extends State<_DriverPlaceSearch>{
  final c=TextEditingController();Timer? timer;List<DriverPlace> items=const[];bool loading=false;
  @override void dispose(){timer?.cancel();c.dispose();super.dispose();}
  void search(String q){timer?.cancel();timer=Timer(const Duration(milliseconds:450),()async{if(q.trim().length<2)return;setState(()=>loading=true);try{final x=await widget.api.searchPlaces(q.trim(),widget.near);if(mounted)setState(()=>items=x);}catch(_){}finally{if(mounted)setState(()=>loading=false);}});}
  @override Widget build(BuildContext context)=>Directionality(textDirection:TextDirection.rtl,child:Padding(padding:EdgeInsets.only(left:16,right:16,top:16,bottom:MediaQuery.of(context).viewInsets.bottom+12),child:SizedBox(height:MediaQuery.of(context).size.height*.7,child:Column(children:[const Text('مقصد دلخواه',style:TextStyle(fontWeight:FontWeight.w900,fontSize:20)),TextField(controller:c,autofocus:true,onChanged:search,decoration:const InputDecoration(prefixIcon:Icon(Icons.search),hintText:'نام محله یا مکان...')),if(loading)const LinearProgressIndicator(),Expanded(child:ListView.builder(itemCount:items.length,itemBuilder:(_,i){final p=items[i];return ListTile(leading:const Icon(Icons.place,color:Colors.red),title:Text(p.title),subtitle:Text(p.address),onTap:()=>Navigator.pop(context,p));}))]))));
}

class _Box extends StatelessWidget { const _Box({required this.child}); final Widget child; @override Widget build(BuildContext context)=>Container(padding:const EdgeInsets.all(16),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(22),boxShadow:const[BoxShadow(color:Colors.black12,blurRadius:14,offset:Offset(0,7))]),child:child); }
class _Stat extends StatelessWidget { const _Stat(this.title,this.value,this.icon); final String title,value;final IconData icon;@override Widget build(BuildContext context)=>Container(padding:const EdgeInsets.all(13),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(19)),child:Row(children:[Icon(icon),const SizedBox(width:7),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(title,style:const TextStyle(fontSize:10,color:Colors.black54)),Text(value,maxLines:1,overflow:TextOverflow.ellipsis,style:const TextStyle(fontWeight:FontWeight.w900,fontSize:12))]))])); }
class _Loc extends StatelessWidget { const _Loc(this.icon,this.color,this.text); final IconData icon;final Color color;final String text;@override Widget build(BuildContext context)=>Row(children:[Icon(icon,color:color),const SizedBox(width:8),Expanded(child:Text(text.isEmpty?'آدرس ثبت نشده':text,style:const TextStyle(fontWeight:FontWeight.w700)))]); }
class _Badge extends StatelessWidget { const _Badge(this.text,this.active);final String text;final bool active;@override Widget build(BuildContext context)=>Container(padding:const EdgeInsets.symmetric(horizontal:9,vertical:6),decoration:BoxDecoration(color:active?const Color(0xFFE6F7EC):const Color(0xFFF0F0EE),borderRadius:BorderRadius.circular(99)),child:Text(text,style:TextStyle(fontSize:9,fontWeight:FontWeight.w800,color:active?Colors.green.shade800:Colors.black54))); }
String _signed(int v)=>'${v>=0?'+':'-'}${money(v.abs())}';
String _settlementFa(String s)=>switch(s){'requested'=>'در انتظار','approved'=>'تأییدشده','paid'=>'پرداخت شد','rejected'=>'ردشده','cancelled'=>'لغوشده',_=>s};
String _docFa(String s)=>switch(s){'national_card'=>'کارت ملی','driver_license'=>'گواهینامه','vehicle_card'=>'کارت خودرو','insurance'=>'بیمه','inspection'=>'معاینه فنی',_=>'مدرک'};
String _docStatusFa(String s)=>switch(s){'approved'=>'تأیید','rejected'=>'رد','expired'=>'منقضی','pending'=>'در بررسی',_=>s};
