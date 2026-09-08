import 'dart:async';
import 'dart:math';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'app_notifications.dart';
import 'driver_platform.dart';
import 'realtime_stream.dart';

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
const _surface = Color(0xFFF6F6F4);
const _apiBase = String.fromEnvironment(
  'RADO_API_BASE_URL',
  defaultValue: 'https://rado-taxi.sbs',
);

class AdvancedDriverPage extends StatefulWidget {
  const AdvancedDriverPage({super.key});

  @override
  State<AdvancedDriverPage> createState() => _AdvancedDriverPageState();
}

class _AdvancedDriverPageState extends State<AdvancedDriverPage> {
  final DriverPlatformApi _api = DriverPlatformApi();
  final RadoNotifications _notifications = RadoNotifications.instance;
  int _tab = 0;
  bool _loading = true;
  bool _busy = false;
  bool _online = false;
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
  Timer? _timer;
  int? _lastNotifiedOfferId;
  String? _lastNotifiedTripStatus;
  bool _refreshing = false;
  DateTime? _lastFallbackRefresh;
  RadoRealtimeStream? _driverStream;
  RadoRealtimeStream? _tripStream;
  String? _streamTripId;

  bool get _approved => _session?.approved == true;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _driverStream?.close();
    _tripStream?.close();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    try {
      final p = await SharedPreferences.getInstance();
      var id = p.getString('rado_driver_client_id');
      if (id == null || id.isEmpty) {
        id =
            '${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
        await p.setString('rado_driver_client_id', id);
      }
      _clientId = id;
      try {
        await _notifications.init();
      } catch (_) {}
      await _refresh(all: true);
      _startDriverRealtime();
      _scheduleTick();
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _scheduleTick() {
    _timer?.cancel();
    final delay = _trip == null
        ? const Duration(seconds: 5)
        : const Duration(seconds: 2);
    _timer = Timer(delay, () async {
      await _tick();
      if (mounted) _scheduleTick();
    });
  }

  void _startDriverRealtime() {
    final id = _clientId;
    if (id == null) return;
    _driverStream?.close();
    final stream = RadoRealtimeStream(_apiBase);
    _driverStream = stream;
    unawaited(
      stream.listen(
        query: {'role': 'driver', 'client_id': id, 'scope': 'driver'},
        onEvent: (event) async {
          if (!mounted || event.name != 'rado_event') return;
          final type = (event.data['event_type'] ?? '').toString();
          if (type == 'presence') return;
          await _refresh();
        },
      ),
    );
  }

  void _syncTripRealtime(DriverTrip? trip) {
    final id = _clientId;
    final tripId = trip?.id;
    if (id == null || tripId == _streamTripId) return;
    _tripStream?.close();
    _tripStream = null;
    _streamTripId = tripId;
    if (tripId == null || tripId.isEmpty) return;
    final stream = RadoRealtimeStream(_apiBase);
    _tripStream = stream;
    unawaited(
      stream.listen(
        query: {'role': 'driver', 'client_id': id, 'trip_id': tripId},
        onEvent: (event) async {
          if (!mounted || _streamTripId != tripId) return;
          if (event.name != 'rado_event') return;
          final type = (event.data['event_type'] ?? '').toString();
          if (type == 'driver_location' || type == 'passenger_location') return;
          await _refresh(all: type == 'completed');
        },
      ),
    );
  }

  Future<bool> _locationPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      _show('برای آنلاین شدن، GPS گوشی را روشن کنید.');
      return false;
    }
    var p = await Geolocator.checkPermission();
    if (p == LocationPermission.denied) {
      p = await Geolocator.requestPermission();
    }
    if (p == LocationPermission.denied ||
        p == LocationPermission.deniedForever) {
      _show('دسترسی موقعیت برای دریافت سفر لازم است.');
      return false;
    }
    return true;
  }

  Future<void> _tick() async {
    final id = _clientId;
    if (id == null || _busy) return;
    try {
      if (_online && _approved && await _locationPermission()) {
        final p = await Geolocator.getCurrentPosition(
          locationSettings: LocationSettings(
            accuracy: LocationAccuracy.high,
            distanceFilter: _trip == null ? 12 : 3,
          ),
        );
        await _api.presence(
          id,
          online: true,
          lat: p.latitude,
          lng: p.longitude,
          heading: p.heading.round(),
          speedKph: max(0, p.speed * 3.6),
        );
      }
      final now = DateTime.now();
      if (_lastFallbackRefresh == null ||
          now.difference(_lastFallbackRefresh!).inSeconds >= 20) {
        _lastFallbackRefresh = now;
        await _refresh();
      }
    } catch (_) {
      // Temporary location/network failures must not close the driver app.
    }
  }

  Future<void> _refresh({bool all = false}) async {
    final id = _clientId;
    if (id == null || _refreshing) return;
    _refreshing = true;
    try {
      final session = await _api.session(id);
      final state = session.approved
          ? await _api.offers(id)
          : const DriverState();
      final wallet = await _api.wallet(id);
      List<DriverNotification> serverNotifications = const [];
      if (session.approved) {
        try {
          serverNotifications = await _api.notifications(id);
        } catch (_) {}
      }
      DriverPreferences prefs = _prefs;
      List<DriverMission> missions = _missions;
      List<Settlement> settlements = _settlements;
      List<DriverDocument> documents = _documents;
      List<DriverSupportTicket> tickets = _tickets;
      if (all && session.approved) {
        prefs = await _api.preferences(id);
        missions = await _api.missions(id);
        settlements = await _api.settlements(id);
        documents = await _api.documents(id);
        tickets = await _api.support(id);
      }
      if (!mounted) return;
      final incomingOffer = state.offer;
      final incomingTrip = state.activeTrip;
      setState(() {
        _session = session;
        _online = session.online || _online;
        _wallet = wallet;
        _offer = incomingOffer;
        _trip = incomingTrip;
        _prefs = prefs;
        _missions = missions;
        _settlements = settlements;
        _documents = documents;
        _tickets = tickets;
      });
      _syncTripRealtime(incomingTrip);
      final unread = serverNotifications
          .where((n) => !n.read)
          .take(3)
          .toList()
          .reversed;
      for (final notice in unread) {
        try {
          if (notice.type != 'trip_offer') {
            await _notifications.show(
              title: notice.title,
              body: notice.body,
              payload: notice.id.toString(),
            );
          }
          await _api.markNotificationRead(id, notice.id);
        } catch (_) {}
      }

      if (incomingOffer != null && incomingOffer.id != _lastNotifiedOfferId) {
        _lastNotifiedOfferId = incomingOffer.id;
        try {
          await _notifications.show(
            title: 'درخواست سفر جدید',
            body:
                '${incomingOffer.trip.distanceLabel} · ${money(incomingOffer.trip.estimatedFare)}',
            payload: incomingOffer.trip.id,
          );
        } catch (_) {}
      }
      final status = incomingTrip?.status;
      if (status != null &&
          _lastNotifiedTripStatus != null &&
          status != _lastNotifiedTripStatus) {
        String? title;
        String? body;
        if (status == 'cancelled_by_passenger') {
          title = 'مسافر سفر را لغو کرد';
          body = 'این سفر دیگر فعال نیست.';
        }
        if (status == 'cancelled_by_admin') {
          title = 'سفر توسط پشتیبانی لغو شد';
          body = 'وضعیت سفر تغییر کرد.';
        }
        if (title != null && body != null) {
          try {
            await _notifications.show(
              title: title,
              body: body,
              payload: incomingTrip!.id,
            );
          } catch (_) {}
        }
      }
      if (status != null) _lastNotifiedTripStatus = status;
    } catch (e) {
      if (all) _show(_api.message(e));
    } finally {
      _refreshing = false;
    }
  }

  Future<void> _toggleOnline() async {
    final id = _clientId;
    if (id == null || _busy) return;
    if (!_approved) {
      _show('ابتدا حساب راننده باید در پنل مدیریت تأیید شود.');
      return;
    }
    setState(() => _busy = true);
    try {
      if (_online) {
        await _api.presence(id, online: false);
        if (mounted) setState(() => _online = false);
      } else {
        if (!await _locationPermission()) return;
        final p = await Geolocator.getCurrentPosition(
          locationSettings: const LocationSettings(
            accuracy: LocationAccuracy.high,
          ),
        );
        await _api.presence(
          id,
          online: true,
          lat: p.latitude,
          lng: p.longitude,
          heading: p.heading.round(),
          speedKph: max(0, p.speed * 3.6),
        );
        if (mounted) setState(() => _online = true);
      }
      await _refresh();
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _answer(bool accept) async {
    final id = _clientId;
    final offer = _offer;
    if (id == null || offer == null || _busy) return;
    setState(() => _busy = true);
    try {
      final trip = await _api.answerOffer(id, offer.id, accept);
      if (mounted) {
        setState(() {
          _offer = null;
          if (trip != null) _trip = trip;
        });
      }
      _show(accept ? 'سفر پذیرفته شد.' : 'درخواست رد شد.');
      if (accept && trip != null) {
        try {
          await _notifications.show(
            title: 'سفر پذیرفته شد',
            body: 'مسیر رسیدن به مسافر فعال شد.',
            payload: trip.id,
          );
        } catch (_) {}
      }
      await _refresh();
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _tripAction(String action) async {
    final id = _clientId;
    final trip = _trip;
    if (id == null || trip == null || _busy) return;
    setState(() => _busy = true);
    try {
      final r = await _api.tripAction(id, trip.id, action);
      if (mounted) setState(() => _trip = r.trip);
      if (action == 'complete' && r.finance != null) {
        _show('سفر پایان یافت؛ سهم شما ${money(r.finance!.driverNet)}');
      } else if (action == 'arrived') {
        _show('رسیدن به مبدا ثبت شد.');
      } else if (action == 'start') {
        _show('سفر شروع شد.');
      }
      try {
        String? noticeTitle;
        String? noticeBody;
        if (action == 'arrived') {
          noticeTitle = 'رسیدن به مبدا ثبت شد';
          noticeBody = 'مسافر از رسیدن شما باخبر شد.';
        } else if (action == 'start') {
          noticeTitle = 'سفر شروع شد';
          noticeBody = 'مسیریابی به مقصد سفر فعال است.';
        } else if (action == 'complete') {
          noticeTitle = 'سفر پایان یافت';
          noticeBody = 'پایان سفر با موفقیت ثبت شد.';
        }
        if (noticeTitle != null && noticeBody != null) {
          await _notifications.show(
            title: noticeTitle,
            body: noticeBody,
            payload: trip.id,
          );
        }
      } catch (_) {}
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
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      _show('برنامه مسیریاب در دسترس نیست.');
    }
  }

  Future<void> _savePreferences(DriverPreferences value) async {
    final id = _clientId;
    if (id == null || _busy) return;
    setState(() => _busy = true);
    try {
      await _api.savePreferences(id, value);
      if (mounted) setState(() => _prefs = value);
      _show('تنظیمات دریافت سفر ذخیره شد.');
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickDestination() async {
    var near = const LatLng(35.9968, 45.8853);
    try {
      if (await _locationPermission()) {
        final p = await Geolocator.getCurrentPosition();
        near = LatLng(p.latitude, p.longitude);
      }
    } catch (_) {}
    if (!mounted) return;
    final selected = await showModalBottomSheet<DriverPlace>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _DriverPlaceSearch(api: _api, near: near),
    );
    if (selected != null) {
      await _savePreferences(
        _prefs.copyWith(
          destinationLabel: selected.label,
          destinationLat: selected.lat,
          destinationLng: selected.lng,
        ),
      );
    }
  }

  Future<void> _editFilters() async {
    final maxCtrl = TextEditingController(
      text: _prefs.maxPickupDistanceKm.toStringAsFixed(1),
    );
    final minCtrl = TextEditingController(text: _prefs.minFare.toString());
    final radiusCtrl = TextEditingController(
      text: _prefs.autoAcceptRadiusKm.toStringAsFixed(1),
    );
    final autoMinCtrl = TextEditingController(
      text: _prefs.autoAcceptMinFare.toString(),
    );
    var auto = _prefs.autoAccept;
    final result = await showModalBottomSheet<DriverPreferences>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Directionality(
          textDirection: TextDirection.rtl,
          child: Padding(
            padding: EdgeInsets.only(
              left: 18,
              right: 18,
              top: 18,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 18,
            ),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text(
                    'فیلتر دریافت سفر',
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
                  ),
                  TextField(
                    controller: maxCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'حداکثر فاصله تا مسافر (km)',
                    ),
                  ),
                  TextField(
                    controller: minCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'حداقل کرایه'),
                  ),
                  const SizedBox(height: 14),
                  const Align(
                    alignment: Alignment.centerRight,
                    child: Text(
                      'حالت انتخاب مسافر',
                      style: TextStyle(fontWeight: FontWeight.w900),
                    ),
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: double.infinity,
                    child: SegmentedButton<bool>(
                      segments: const [
                        ButtonSegment<bool>(
                          value: false,
                          icon: Icon(Icons.touch_app_rounded),
                          label: Text('دستی'),
                        ),
                        ButtonSegment<bool>(
                          value: true,
                          icon: Icon(Icons.bolt_rounded),
                          label: Text('خودکار'),
                        ),
                      ],
                      selected: {auto},
                      onSelectionChanged: (value) =>
                          setLocal(() => auto = value.first),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.only(top: 8, bottom: 4),
                    child: Text(
                      auto
                          ? 'در حالت خودکار، RADO فقط سفرهایی را که با شرط‌های زیر هماهنگ باشند برای شما می‌پذیرد.'
                          : 'در حالت دستی، درخواست‌های نزدیک نمایش داده می‌شوند و انتخاب نهایی با شماست.',
                      style: const TextStyle(
                        fontSize: 12,
                        color: Colors.black54,
                      ),
                    ),
                  ),
                  if (auto) ...[
                    TextField(
                      controller: radiusCtrl,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'شعاع پذیرش خودکار (km)',
                      ),
                    ),
                    TextField(
                      controller: autoMinCtrl,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'حداقل کرایه پذیرش خودکار',
                      ),
                    ),
                  ],
                  const SizedBox(height: 12),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: () => Navigator.pop(
                        ctx,
                        _prefs.copyWith(
                          maxPickupDistanceKm:
                              double.tryParse(maxCtrl.text) ??
                              _prefs.maxPickupDistanceKm,
                          minFare: int.tryParse(minCtrl.text) ?? _prefs.minFare,
                          autoAccept: auto,
                          autoAcceptRadiusKm:
                              double.tryParse(radiusCtrl.text) ??
                              _prefs.autoAcceptRadiusKm,
                          autoAcceptMinFare:
                              int.tryParse(autoMinCtrl.text) ??
                              _prefs.autoAcceptMinFare,
                        ),
                      ),
                      child: const Text('ذخیره'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    if (result != null) await _savePreferences(result);
  }

  Future<void> _requestSettlement() async {
    final id = _clientId;
    final balance = _wallet?.balance ?? 0;
    if (id == null || balance <= 0 || _busy) return;
    final ctrl = TextEditingController(text: balance.toString());
    final amount = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('درخواست تسویه'),
        content: TextField(
          controller: ctrl,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'مبلغ ریال'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('لغو'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, int.tryParse(ctrl.text)),
            child: const Text('ثبت'),
          ),
        ],
      ),
    );
    if (amount == null || amount <= 0 || amount > balance) return;
    setState(() => _busy = true);
    try {
      await _api.requestSettlement(id, amount);
      _settlements = await _api.settlements(id);
      if (mounted) setState(() {});
      _show('درخواست تسویه ثبت شد.');
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _uploadDocument() async {
    final id = _clientId;
    if (id == null || _busy) return;
    final picked = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: const ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    );
    final path = picked?.files.single.path;
    if (path == null || !mounted) return;
    var type = 'driver_license';
    final number = TextEditingController();
    final expiry = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('ارسال مدرک'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<String>(
                initialValue: type,
                items: const [
                  DropdownMenuItem(
                    value: 'driver_license',
                    child: Text('گواهینامه'),
                  ),
                  DropdownMenuItem(
                    value: 'national_card',
                    child: Text('کارت ملی'),
                  ),
                  DropdownMenuItem(
                    value: 'vehicle_card',
                    child: Text('کارت خودرو'),
                  ),
                  DropdownMenuItem(value: 'insurance', child: Text('بیمه')),
                  DropdownMenuItem(
                    value: 'inspection',
                    child: Text('معاینه فنی'),
                  ),
                  DropdownMenuItem(value: 'other', child: Text('سایر')),
                ],
                onChanged: (v) => setLocal(() => type = v ?? 'other'),
              ),
              TextField(
                controller: number,
                decoration: const InputDecoration(labelText: 'شماره مدرک'),
              ),
              TextField(
                controller: expiry,
                decoration: const InputDecoration(
                  labelText: 'تاریخ انقضا YYYY-MM-DD',
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('لغو'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('ارسال'),
            ),
          ],
        ),
      ),
    );
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      await _api.uploadDocument(
        clientId: id,
        path: path,
        type: type,
        number: number.text.trim(),
        expiry: expiry.text.trim(),
      );
      final docs = await _api.documents(id);
      if (mounted) setState(() => _documents = docs);
      _show('مدرک برای بررسی ارسال شد.');
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
    final body = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('پیام به پشتیبانی'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: subject,
              decoration: const InputDecoration(labelText: 'موضوع'),
            ),
            TextField(
              controller: body,
              maxLines: 4,
              decoration: const InputDecoration(labelText: 'پیام'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('لغو'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('ارسال'),
          ),
        ],
      ),
    );
    if (ok != true || subject.text.trim().isEmpty || body.text.trim().isEmpty)
      return;
    try {
      await _api.createSupport(
        id,
        subject.text.trim(),
        body.text.trim(),
        tripId: _trip?.id,
      );
      _tickets = await _api.support(id);
      if (mounted) setState(() {});
      _show('پیام ارسال شد.');
    } catch (e) {
      _show(_api.message(e));
    }
  }

  void _show(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) {
    final pages = [_home(), _tripsPage(), _earningsPage(), _accountPage()];
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        backgroundColor: _surface,
        body: SafeArea(
          child: IndexedStack(index: _tab, children: pages),
        ),
        bottomNavigationBar: NavigationBar(
          selectedIndex: _tab,
          onDestinationSelected: (v) => setState(() => _tab = v),
          destinations: const [
            NavigationDestination(
              icon: Icon(Icons.home_outlined),
              selectedIcon: Icon(Icons.home_rounded),
              label: 'خانه',
            ),
            NavigationDestination(
              icon: Icon(Icons.route_outlined),
              selectedIcon: Icon(Icons.route_rounded),
              label: 'سفرها',
            ),
            NavigationDestination(
              icon: Icon(Icons.account_balance_wallet_outlined),
              selectedIcon: Icon(Icons.account_balance_wallet_rounded),
              label: 'درآمد',
            ),
            NavigationDestination(
              icon: Icon(Icons.person_outline),
              selectedIcon: Icon(Icons.person),
              label: 'حساب',
            ),
          ],
        ),
      ),
    );
  }

  Widget _home() => RefreshIndicator(
    onRefresh: () => _refresh(all: true),
    child: ListView(
      padding: const EdgeInsets.all(18),
      children: [
        _header(),
        const SizedBox(height: 14),
        if (_loading)
          const Center(
            child: Padding(
              padding: EdgeInsets.all(30),
              child: CircularProgressIndicator(),
            ),
          ),
        if (!_loading && !_approved) _approvalCard(),
        if (!_loading) ...[
          _onlineCard(),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _Stat(
                  'درآمد امروز',
                  money(_wallet?.todayNet ?? 0),
                  Icons.payments_outlined,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _Stat(
                  'سفر امروز',
                  '${_wallet?.todayTrips ?? 0}',
                  Icons.local_taxi_outlined,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (_trip != null)
            _activeTripCard(_trip!)
          else if (_offer != null)
            _offerCard(_offer!)
          else
            _waitingCard(),
        ],
      ],
    ),
  );

  Widget _header() => Row(
    children: [
      ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: Image.asset(
          'assets/branding/rado-driver.png',
          width: 58,
          height: 58,
          fit: BoxFit.cover,
        ),
      ),
      const SizedBox(width: 12),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _session?.name ?? 'RADO Driver',
              style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 20),
            ),
            Text(
              _approved
                  ? (_online ? 'آنلاین و آماده سفر' : 'آفلاین')
                  : (_session?.statusFa ?? 'در انتظار تأیید'),
              style: const TextStyle(color: Colors.black54, fontSize: 12),
            ),
          ],
        ),
      ),
      IconButton(
        onPressed: () => _refresh(all: true),
        icon: const Icon(Icons.refresh_rounded),
      ),
    ],
  );

  Widget _approvalCard() => _Box(
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

  Widget _onlineCard() => Container(
    padding: const EdgeInsets.all(17),
    decoration: BoxDecoration(
      color: _black,
      borderRadius: BorderRadius.circular(24),
    ),
    child: Row(
      children: [
        Icon(
          _online
              ? Icons.wifi_tethering_rounded
              : Icons.power_settings_new_rounded,
          color: _yellow,
          size: 32,
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            _online ? 'آماده دریافت درخواست' : 'برای دریافت سفر آنلاین شو',
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
            ),
          ),
        ),
        FilledButton(
          onPressed: _busy || !_approved ? null : _toggleOnline,
          style: FilledButton.styleFrom(
            backgroundColor: _online ? Colors.white : _yellow,
            foregroundColor: _black,
          ),
          child: Text(_online ? 'آفلاین' : 'آنلاین'),
        ),
      ],
    ),
  );

  Widget _waitingCard() => _Box(
    child: Column(
      children: [
        Icon(
          _online ? Icons.radar_rounded : Icons.local_taxi_outlined,
          size: 46,
        ),
        const SizedBox(height: 8),
        Text(
          _online ? 'در انتظار درخواست سفر' : 'در حال حاضر آفلاین هستی',
          style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17),
        ),
        if (_online) ...[
          const SizedBox(height: 12),
          const LinearProgressIndicator(),
        ],
      ],
    ),
  );

  Widget _offerCard(DriverOffer offer) => _Box(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'درخواست سفر جدید',
          style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18),
        ),
        Text(
          offer.offeredAt,
          style: const TextStyle(color: Colors.black54, fontSize: 11),
        ),
        const SizedBox(height: 10),
        _location(
          Icons.radio_button_checked,
          Colors.green,
          offer.trip.pickup.label,
        ),
        _location(Icons.location_on, Colors.red, offer.trip.destination.label),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(child: _Info('کرایه', money(offer.trip.estimatedFare))),
            const SizedBox(width: 8),
            Expanded(child: _Info('مسافت', offer.trip.distanceLabel)),
          ],
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: _busy ? null : () => _answer(false),
                child: const Text('رد'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: FilledButton(
                onPressed: _busy ? null : () => _answer(true),
                style: FilledButton.styleFrom(
                  backgroundColor: _yellow,
                  foregroundColor: _black,
                ),
                child: const Text('قبول سفر'),
              ),
            ),
          ],
        ),
      ],
    ),
  );

  Widget _activeTripCard(DriverTrip trip) {
    final toPickup =
        trip.status == 'driver_assigned' || trip.status == 'driver_arriving';
    final inProgress = trip.status == 'in_progress';
    final action = toPickup
        ? 'arrived'
        : trip.status == 'arrived'
        ? 'start'
        : inProgress
        ? 'complete'
        : null;
    final label = toPickup
        ? 'رسیدم'
        : trip.status == 'arrived'
        ? 'شروع سفر'
        : inProgress
        ? 'پایان سفر'
        : '';
    final nav = inProgress ? trip.destination : trip.pickup;
    return _Box(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            trip.statusFa,
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18),
          ),
          Text(
            trip.requestedAt,
            style: const TextStyle(color: Colors.black54, fontSize: 11),
          ),
          const SizedBox(height: 10),
          _location(
            Icons.radio_button_checked,
            Colors.green,
            trip.pickup.label,
          ),
          _location(Icons.location_on, Colors.red, trip.destination.label),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: _Info(
                  'کرایه',
                  money(trip.finalFare ?? trip.estimatedFare),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(child: _Info('وضعیت', trip.statusFa)),
            ],
          ),
          const SizedBox(height: 10),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: () => _navigate(nav),
              icon: const Icon(Icons.navigation_rounded),
              label: Text(inProgress ? 'مسیریابی تا مقصد' : 'مسیریابی تا مبدا'),
            ),
          ),
          if (action != null) ...[
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _busy ? null : () => _tripAction(action),
                style: FilledButton.styleFrom(
                  backgroundColor: _yellow,
                  foregroundColor: _black,
                ),
                child: Text(label),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _tripsPage() => RefreshIndicator(
    onRefresh: () => _refresh(all: true),
    child: ListView(
      padding: const EdgeInsets.all(18),
      children: [
        _header(),
        const SizedBox(height: 14),
        const Text(
          'سفر جاری',
          style: TextStyle(fontSize: 19, fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 8),
        if (_trip != null)
          _activeTripCard(_trip!)
        else
          const _Box(
            child: Text('سفر فعالی نداری.', textAlign: TextAlign.center),
          ),
        const SizedBox(height: 14),
        const Text(
          'آخرین تراکنش‌ها',
          style: TextStyle(fontWeight: FontWeight.w900),
        ),
        ...?_wallet?.entries
            .take(20)
            .map(
              (e) => ListTile(
                title: Text(e.title),
                subtitle: Text(e.jalali),
                trailing: Text(_signed(e.amount)),
              ),
            ),
      ],
    ),
  );

  Widget _earningsPage() => RefreshIndicator(
    onRefresh: () => _refresh(all: true),
    child: ListView(
      padding: const EdgeInsets.all(18),
      children: [
        _header(),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: _Stat(
                'موجودی',
                money(_wallet?.balance ?? 0),
                Icons.account_balance_wallet_rounded,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _Stat(
                'کمیسیون',
                '${_wallet?.commissionRate.toStringAsFixed(1) ?? '0'}٪',
                Icons.percent_rounded,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        _Box(
          child: Column(
            children: [
              Text(
                'درآمد خالص امروز: ${money(_wallet?.todayNet ?? 0)}',
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
              Text('کمیسیون امروز: ${money(_wallet?.todayCommission ?? 0)}'),
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: (_wallet?.balance ?? 0) > 0
                      ? _requestSettlement
                      : null,
                  icon: const Icon(Icons.account_balance_rounded),
                  label: const Text('درخواست تسویه'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        const Text('تسویه‌ها', style: TextStyle(fontWeight: FontWeight.w900)),
        if (_settlements.isEmpty)
          const ListTile(title: Text('تسویه‌ای ثبت نشده است.')),
        ..._settlements
            .take(20)
            .map(
              (s) => ListTile(
                title: Text(money(s.amount)),
                subtitle: Text(s.requestedAt),
                trailing: Text(_settlementFa(s.status)),
              ),
            ),
      ],
    ),
  );

  Widget _accountPage() => RefreshIndicator(
    onRefresh: () => _refresh(all: true),
    child: ListView(
      padding: const EdgeInsets.all(18),
      children: [
        _header(),
        const SizedBox(height: 14),
        _Box(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'تنظیمات دریافت سفر',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17),
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.assistant_direction_rounded),
                title: Text(
                  _prefs.destinationLabel.isEmpty
                      ? 'مقصد دلخواه خاموش'
                      : _prefs.destinationLabel,
                ),
                trailing: TextButton(
                  onPressed: _pickDestination,
                  child: Text(
                    _prefs.destinationLabel.isEmpty ? 'انتخاب' : 'تغییر',
                  ),
                ),
              ),
              if (_prefs.destinationLabel.isNotEmpty)
                TextButton(
                  onPressed: () =>
                      _savePreferences(_prefs.copyWith(clearDestination: true)),
                  child: const Text('حذف مقصد دلخواه'),
                ),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton(
                  onPressed: _editFilters,
                  child: const Text('فیلتر فاصله و پذیرش خودکار'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        _missionsCard(),
        const SizedBox(height: 12),
        _documentsCard(),
        const SizedBox(height: 12),
        _supportCard(),
      ],
    ),
  );

  Widget _missionsCard() => _Box(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'مأموریت و پاداش',
          style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17),
        ),
        if (_missions.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 10),
            child: Text('مأموریت فعالی وجود ندارد.'),
          ),
        ..._missions.map(
          (m) => Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        m.title,
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                    ),
                    Text(
                      money(m.reward),
                      style: const TextStyle(color: Colors.green, fontSize: 11),
                    ),
                  ],
                ),
                if (m.description.isNotEmpty)
                  Text(
                    m.description,
                    style: const TextStyle(fontSize: 11, color: Colors.black54),
                  ),
                const SizedBox(height: 5),
                LinearProgressIndicator(value: m.ratio),
                Text(
                  '${m.progress} / ${m.target} · تا ${m.endsAt}',
                  style: const TextStyle(fontSize: 10, color: Colors.black54),
                ),
              ],
            ),
          ),
        ),
      ],
    ),
  );

  Widget _documentsCard() => _Box(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'مدارک راننده',
          style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17),
        ),
        ..._documents
            .take(8)
            .map(
              (d) => ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: const Icon(Icons.badge_outlined),
                title: Text(_docFa(d.type)),
                subtitle: Text(
                  [
                    if (d.number.isNotEmpty) d.number,
                    if (d.expiry.isNotEmpty) 'انقضا ${d.expiry}',
                  ].join(' · '),
                ),
                trailing: Text(_docStatusFa(d.status)),
              ),
            ),
        SizedBox(
          width: double.infinity,
          child: OutlinedButton.icon(
            onPressed: _busy ? null : _uploadDocument,
            icon: const Icon(Icons.upload_file_rounded),
            label: const Text('ارسال مدرک جدید'),
          ),
        ),
      ],
    ),
  );

  Widget _supportCard() => _Box(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'پشتیبانی',
          style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17),
        ),
        ..._tickets
            .take(5)
            .map(
              (t) => ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                title: Text(t.subject),
                subtitle: Text('${t.createdAt} · ${t.status}'),
              ),
            ),
        SizedBox(
          width: double.infinity,
          child: OutlinedButton.icon(
            onPressed: _newSupport,
            icon: const Icon(Icons.support_agent_rounded),
            label: const Text('پیام به پشتیبانی'),
          ),
        ),
      ],
    ),
  );

  Widget _location(IconData icon, Color color, String text) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      children: [
        Icon(icon, color: color),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            text.isEmpty ? 'آدرس ثبت نشده' : text,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
      ],
    ),
  );
}

class _DriverPlaceSearch extends StatefulWidget {
  const _DriverPlaceSearch({required this.api, required this.near});
  final DriverPlatformApi api;
  final LatLng near;

  @override
  State<_DriverPlaceSearch> createState() => _DriverPlaceSearchState();
}

class _DriverPlaceSearchState extends State<_DriverPlaceSearch> {
  final TextEditingController _controller = TextEditingController();
  Timer? _timer;
  List<DriverPlace> _items = const [];
  bool _loading = false;

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _search(String q) {
    _timer?.cancel();
    _timer = Timer(const Duration(milliseconds: 450), () async {
      if (q.trim().length < 2) return;
      setState(() => _loading = true);
      try {
        final items = await widget.api.searchPlaces(q.trim(), widget.near);
        if (mounted) setState(() => _items = items);
      } catch (_) {
        if (mounted) setState(() => _items = const []);
      } finally {
        if (mounted) setState(() => _loading = false);
      }
    });
  }

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: TextDirection.rtl,
    child: Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom + 12,
      ),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * .7,
        child: Column(
          children: [
            const Text(
              'مقصد دلخواه',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20),
            ),
            TextField(
              controller: _controller,
              autofocus: true,
              onChanged: _search,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'نام محله یا مکان...',
              ),
            ),
            if (_loading) const LinearProgressIndicator(),
            Expanded(
              child: ListView.builder(
                itemCount: _items.length,
                itemBuilder: (_, i) {
                  final p = _items[i];
                  return ListTile(
                    leading: const Icon(Icons.place, color: Colors.red),
                    title: Text(p.title),
                    subtitle: Text(p.address),
                    onTap: () => Navigator.pop(context, p),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _Box extends StatelessWidget {
  const _Box({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(22),
      boxShadow: const [
        BoxShadow(color: Colors.black12, blurRadius: 14, offset: Offset(0, 6)),
      ],
    ),
    child: child,
  );
}

class _Stat extends StatelessWidget {
  const _Stat(this.title, this.value, this.icon);
  final String title;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => _Box(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 22),
        const SizedBox(height: 8),
        Text(
          title,
          style: const TextStyle(color: Colors.black54, fontSize: 11),
        ),
        Text(value, style: const TextStyle(fontWeight: FontWeight.w900)),
      ],
    ),
  );
}

class _Info extends StatelessWidget {
  const _Info(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(11),
    decoration: BoxDecoration(
      color: const Color(0xFFF4F4F1),
      borderRadius: BorderRadius.circular(14),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(fontSize: 10, color: Colors.black54),
        ),
        Text(value, style: const TextStyle(fontWeight: FontWeight.w900)),
      ],
    ),
  );
}

String _signed(int value) => '${value >= 0 ? '+' : '-'}${money(value.abs())}';
String _settlementFa(String status) => switch (status) {
  'approved' => 'تأییدشده',
  'paid' => 'پرداخت‌شده',
  'rejected' => 'ردشده',
  _ => 'در انتظار',
};
String _docFa(String type) => switch (type) {
  'driver_license' => 'گواهینامه',
  'national_card' => 'کارت ملی',
  'vehicle_card' => 'کارت خودرو',
  'insurance' => 'بیمه',
  'inspection' => 'معاینه فنی',
  _ => 'مدرک',
};
String _docStatusFa(String status) => switch (status) {
  'approved' => 'تأیید',
  'rejected' => 'رد',
  'expired' => 'منقضی',
  _ => 'در انتظار',
};
