import 'dart:async';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:latlong2/latlong.dart';
import 'package:neshan_maps_flutter/map.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'platform_features.dart';

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
const _baneh = LatLng(35.9968, 45.8853);
const _mapKey = String.fromEnvironment('NESHAN_MAP_KEY');
const _base = String.fromEnvironment('RADO_API_BASE_URL');

class AdvancedPassengerPage extends StatefulWidget {
  const AdvancedPassengerPage({super.key});
  @override
  State<AdvancedPassengerPage> createState() => _AdvancedPassengerPageState();
}

class _AdvancedPassengerPageState extends State<AdvancedPassengerPage> {
  final _map = NeshanMapController();
  final _api = RideApi();
  late final PassengerPlatformApi _platform = PassengerPlatformApi(_base);
  LatLng _center = _baneh;
  LatLng? _origin;
  LatLng? _destination;
  String _originLabel = 'مبدا را انتخاب کنید';
  String _destinationLabel = 'مقصد را انتخاب کنید';
  RideRoute? _route;
  FareInfo? _fare;
  String? _error;
  String? _clientId;
  bool _busy = false;
  int _step = 0;
  RideOptions _options = const RideOptions();
  RideTrip? _trip;
  Timer? _poller;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _poller?.cancel();
    _map.dispose();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    final p = await SharedPreferences.getInstance();
    var id = p.getString('rado_passenger_client_id');
    if (id == null || id.isEmpty) {
      id = '${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
      await p.setString('rado_passenger_client_id', id);
    }
    if (mounted) setState(() => _clientId = id);
  }

  Future<LatLng> _exactCenter() async {
    if (_mapKey.isEmpty) return _center;
    try {
      await _map.ready.timeout(const Duration(seconds: 4));
      final p = await _map.getCurrentLocation();
      if (p != null) return _center = p;
    } catch (_) {}
    return _center;
  }

  Future<void> _selectMapPoint() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final point = await _exactCenter();
      final label = await _api.reverse(point);
      if (_step == 0) {
        setState(() {
          _origin = point;
          _originLabel = label;
          _destination = null;
          _destinationLabel = 'مقصد را انتخاب کنید';
          _route = null;
          _fare = null;
          _step = 1;
        });
      } else {
        setState(() {
          _destination = point;
          _destinationLabel = label;
        });
        await _calculate();
      }
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _calculate() async {
    final a = _origin, b = _destination;
    if (a == null || b == null) return;
    setState(() {
      _error = null;
      _fare = null;
    });
    try {
      final r = await _api.route(a, b);
      final f = await _api.fare(
        r,
        origin: a,
        destination: b,
        clientId: _clientId ?? '',
        promoCode: _options.promoCode,
        serviceType: _options.serviceType,
      );
      if (mounted) setState(() {
        _route = r;
        _fare = f;
      });
    } catch (e) {
      if (mounted) setState(() => _error = _api.message(e));
    }
  }

  Future<void> _openPlaceSearch({required bool destination, bool asStop = false}) async {
    final selected = await showModalBottomSheet<PlaceResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _PlaceSearchSheet(platform: _platform, near: _center),
    );
    if (selected == null || !mounted) return;
    if (asStop) {
      final list = [..._options.stops, TripStopDraft(point: selected.point, label: selected.displayLabel)];
      setState(() => _options = _options.copyWith(stops: list));
      _show('توقف بین راه اضافه شد.');
      return;
    }
    if (destination) {
      setState(() {
        _destination = selected.point;
        _destinationLabel = selected.displayLabel;
        _center = selected.point;
        _step = 1;
      });
      await _calculate();
    } else {
      setState(() {
        _origin = selected.point;
        _originLabel = selected.displayLabel;
        _center = selected.point;
        _step = 1;
        _destination = null;
        _fare = null;
      });
    }
  }

  Future<void> _favorites() async {
    final id = _clientId;
    if (id == null) return;
    try {
      final items = await _platform.favorites(id);
      if (!mounted) return;
      await showModalBottomSheet<void>(
        context: context,
        useSafeArea: true,
        builder: (ctx) => Directionality(
          textDirection: TextDirection.rtl,
          child: ListView(
            padding: const EdgeInsets.all(18),
            children: [
              const Text('مکان‌های منتخب', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
              const SizedBox(height: 12),
              if (items.isEmpty) const Text('هنوز مکان منتخبی ذخیره نشده است.'),
              ...items.map((f) => ListTile(
                    leading: Icon(f.kind == 'home' ? Icons.home_rounded : f.kind == 'work' ? Icons.work_rounded : Icons.star_rounded),
                    title: Text(f.title),
                    subtitle: Text(f.label),
                    onTap: () {
                      Navigator.pop(ctx);
                      setState(() {
                        _destination = f.point;
                        _destinationLabel = f.label;
                        _step = 1;
                      });
                      _calculate();
                    },
                  )),
              const Divider(),
              ListTile(
                leading: const Icon(Icons.add_location_alt_rounded),
                title: const Text('ذخیره نقطه فعلی به عنوان منتخب'),
                onTap: () async {
                  Navigator.pop(ctx);
                  await _saveCurrentFavorite();
                },
              ),
            ],
          ),
        ),
      );
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _saveCurrentFavorite() async {
    final id = _clientId;
    if (id == null) return;
    final point = await _exactCenter();
    String label;
    try {
      label = await _api.reverse(point);
    } catch (_) {
      label = 'مکان منتخب';
    }
    if (!mounted) return;
    final title = TextEditingController();
    String kind = 'favorite';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) => AlertDialog(
        title: const Text('ذخیره مکان'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(label, style: const TextStyle(fontSize: 12)),
          TextField(controller: title, decoration: const InputDecoration(labelText: 'نام مکان')),
          const SizedBox(height: 8),
          DropdownButtonFormField<String>(value: kind, items: const [DropdownMenuItem(value: 'favorite', child: Text('منتخب')), DropdownMenuItem(value: 'home', child: Text('خانه')), DropdownMenuItem(value: 'work', child: Text('محل کار'))], onChanged: (v) => setLocal(() => kind = v ?? 'favorite')),
        ]),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('لغو')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('ذخیره'))],
      )),
    );
    if (ok == true) {
      try {
        await _platform.saveFavorite(clientId: id, kind: kind, title: title.text.trim().isEmpty ? 'مکان منتخب' : title.text.trim(), label: label, point: point);
        _show('مکان ذخیره شد.');
      } catch (e) {
        _show(_platform.messageFromError(e));
      }
    }
  }

  Future<void> _rideOptions() async {
    final updated = await showModalBottomSheet<RideOptions>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _RideOptionsSheet(initial: _options, onAddStop: () async {
        Navigator.pop(context);
        await _openPlaceSearch(destination: true, asStop: true);
      }),
    );
    if (updated != null && mounted) {
      setState(() => _options = updated);
      if (_route != null) await _calculate();
    }
  }

  Future<void> _applyPromo() async {
    final id = _clientId;
    final fare = _fare;
    if (id == null || fare == null) return;
    final c = TextEditingController(text: _options.promoCode);
    final code = await showDialog<String>(context: context, builder: (ctx) => AlertDialog(title: const Text('کد تخفیف'), content: TextField(controller: c, textCapitalization: TextCapitalization.characters), actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('لغو')), FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: const Text('اعمال'))]));
    if (code == null || code.isEmpty) return;
    try {
      final result = await _platform.validatePromo(id, code, fare.preDiscountFare);
      setState(() {
        _options = _options.copyWith(promoCode: result.code);
        _fare = fare.copyWith(fare: result.payable, discount: result.discount);
      });
      _show('تخفیف ${money(result.discount)} اعمال شد.');
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _request() async {
    final id = _clientId, a = _origin, b = _destination, route = _route, fare = _fare;
    if (id == null || a == null || b == null || route == null || fare == null || _busy) return;
    setState(() => _busy = true);
    try {
      final trip = await _api.requestTrip(
        clientId: id,
        origin: a,
        destination: b,
        originLabel: _originLabel,
        destinationLabel: _destinationLabel,
        route: route,
        options: _options,
      );
      if (!mounted) return;
      setState(() => _trip = trip);
      _poller?.cancel();
      _poller = Timer.periodic(const Duration(seconds: 4), (_) => _poll());
      _poll();
      _show(trip.status == 'requested' ? 'سفر برای زمان انتخابی رزرو شد.' : 'درخواست برای رانندگان نزدیک ارسال شد.');
    } catch (e) {
      _show(_api.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _poll() async {
    final id = _clientId, t = _trip;
    if (id == null || t == null) return;
    try {
      final x = await _api.tripStatus(id, t.id);
      if (!mounted) return;
      setState(() => _trip = x);
      if (x.terminal) _poller?.cancel();
    } catch (_) {}
  }

  Future<void> _cancel() async {
    final id = _clientId, t = _trip;
    if (id == null || t == null) return;
    try {
      final x = await _api.cancel(id, t.id);
      setState(() => _trip = x);
      _poller?.cancel();
    } catch (e) {
      _show(_api.message(e));
    }
  }

  Future<void> _share() async {
    final id = _clientId, t = _trip;
    if (id == null || t == null) return;
    try {
      final url = await _platform.shareTrip(id, t.id);
      await Clipboard.setData(ClipboardData(text: url));
      _show('لینک وضعیت سفر کپی شد.');
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _rate() async {
    final id = _clientId, t = _trip;
    if (id == null || t == null) return;
    int score = 5;
    final comment = TextEditingController();
    final ok = await showDialog<bool>(context: context, builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) => AlertDialog(title: const Text('امتیاز به سفر'), content: Column(mainAxisSize: MainAxisSize.min, children: [Wrap(children: List.generate(5, (i) => IconButton(onPressed: () => setLocal(() => score = i + 1), icon: Icon(i < score ? Icons.star_rounded : Icons.star_border_rounded, color: _yellow))),), TextField(controller: comment, decoration: const InputDecoration(labelText: 'نظر شما'))]), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('بعداً')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('ثبت'))])));
    if (ok == true) {
      try {
        await _platform.rateTrip(clientId: id, tripId: t.id, score: score, comment: comment.text.trim());
        _show('ممنون از امتیاز شما.');
      } catch (e) {
        _show(_platform.messageFromError(e));
      }
    }
  }

  Future<void> _accountPanel() async {
    final id = _clientId;
    if (id == null) return;
    try {
      final results = await Future.wait([_platform.history(id), _platform.wallet(id), _platform.loyaltyPoints(id), _platform.supportTickets(id)]);
      final history = results[0] as List<HistoryTrip>;
      final wallet = results[1] as WalletSummary;
      final points = results[2] as int;
      final tickets = results[3] as List<SupportTicket>;
      if (!mounted) return;
      await showModalBottomSheet<void>(context: context, isScrollControlled: true, useSafeArea: true, builder: (ctx) => Directionality(textDirection: TextDirection.rtl, child: DraggableScrollableSheet(expand: false, initialChildSize: .82, minChildSize: .45, maxChildSize: .95, builder: (_, c) => ListView(controller: c, padding: const EdgeInsets.all(18), children: [
        const Text('حساب RADO', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 22)),
        const SizedBox(height: 12),
        Row(children: [Expanded(child: _MiniCard('کیف پول', money(wallet.balance), Icons.account_balance_wallet_rounded)), const SizedBox(width: 8), Expanded(child: _MiniCard('امتیاز وفاداری', '$points امتیاز', Icons.workspace_premium_rounded))]),
        const SizedBox(height: 14),
        ListTile(leading: const Icon(Icons.support_agent_rounded), title: const Text('پشتیبانی'), subtitle: Text('${tickets.length} تیکت'), trailing: const Icon(Icons.chevron_left), onTap: () { Navigator.pop(ctx); _newSupportTicket(); }),
        const Divider(),
        const Text('سفرهای اخیر', style: TextStyle(fontWeight: FontWeight.w900)),
        ...history.take(15).map((h) => ListTile(leading: const Icon(Icons.route_rounded), title: Text('${h.pickup} ← ${h.destination}', maxLines: 1, overflow: TextOverflow.ellipsis), subtitle: Text('${h.requestedAt} · ${h.statusFa}'), trailing: Text(money(h.fare), style: const TextStyle(fontSize: 11)))),
      ]))));
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _newSupportTicket() async {
    final id = _clientId;
    if (id == null) return;
    final subject = TextEditingController();
    final message = TextEditingController();
    final ok = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(title: const Text('پشتیبانی RADO'), content: Column(mainAxisSize: MainAxisSize.min, children: [TextField(controller: subject, decoration: const InputDecoration(labelText: 'موضوع')), TextField(controller: message, minLines: 3, maxLines: 5, decoration: const InputDecoration(labelText: 'توضیحات'))]), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('لغو')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('ارسال'))]));
    if (ok == true && subject.text.trim().isNotEmpty && message.text.trim().isNotEmpty) {
      try {
        await _platform.createSupportTicket(clientId: id, subject: subject.text.trim(), message: message.text.trim(), tripId: _trip?.id);
        _show('درخواست پشتیبانی ثبت شد.');
      } catch (e) {
        _show(_platform.messageFromError(e));
      }
    }
  }

  void _reset() {
    _poller?.cancel();
    setState(() {
      _origin = null;
      _destination = null;
      _originLabel = 'مبدا را انتخاب کنید';
      _destinationLabel = 'مقصد را انتخاب کنید';
      _route = null;
      _fare = null;
      _trip = null;
      _step = 0;
      _options = const RideOptions();
      _error = null;
    });
  }

  void _show(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          body: SafeArea(
            child: Stack(children: [
              Positioned.fill(child: _mapWidget()),
              if (_trip == null) const Positioned(top: 144, left: 0, right: 0, child: IgnorePointer(child: _Pin())),
              Positioned(top: 12, left: 12, right: 12, child: _header()),
              Positioned(left: 12, right: 12, bottom: 12, child: _trip == null ? _bookingCard() : _trackingCard(_trip!)),
            ]),
          ),
        ),
      );

  Widget _mapWidget() {
    if (_mapKey.isEmpty) return Container(color: const Color(0xFFECEBE7), child: const Center(child: Icon(Icons.map_rounded, size: 70)));
    return NeshanMap(
      mapKey: _mapKey,
      controller: _map,
      config: const NeshanMapConfig(initialCenter: _baneh, initialZoom: 15, mapType: NeshanMapType.neshanVector, showTraffic: true, showPoi: true, showCurrentLocationButton: true),
      markers: [if (_origin != null) NeshanMarker(id: 'o', position: _origin!, color: Colors.green, title: 'مبدا'), if (_destination != null) NeshanMarker(id: 'd', position: _destination!, color: Colors.red, title: 'مقصد')],
      onLocationChanged: (lat, lng) => _center = LatLng(lat, lng),
      onError: (m, e, s) => debugPrint('Neshan: $m'),
      onLocationError: (m, e, s) => debugPrint('Neshan location: $m'),
    );
  }

  Widget _header() => Material(
        elevation: 7,
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 10),
          child: Row(children: [
            ClipRRect(borderRadius: BorderRadius.circular(14), child: Image.asset('assets/branding/rado-passenger.png', width: 48, height: 48)),
            const SizedBox(width: 10),
            const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('RADO', style: TextStyle(fontSize: 21, fontWeight: FontWeight.w900)), Text('تاکسی اینترنتی بانه', style: TextStyle(fontSize: 11))])),
            IconButton(tooltip: 'جستجوی مکان', onPressed: () => _openPlaceSearch(destination: _step > 0), icon: const Icon(Icons.search_rounded)),
            IconButton(tooltip: 'منتخب‌ها', onPressed: _favorites, icon: const Icon(Icons.star_outline_rounded)),
            IconButton(tooltip: 'حساب', onPressed: _accountPanel, icon: const Icon(Icons.person_outline_rounded)),
          ]),
        ),
      );

  Widget _bookingCard() {
    final fare = _fare;
    final hasDest = _destination != null;
    return Material(
      elevation: 16,
      color: Colors.white,
      borderRadius: BorderRadius.circular(28),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          _PlaceLine(Icons.radio_button_checked_rounded, Colors.green, _originLabel, onTap: () => _openPlaceSearch(destination: false)),
          const SizedBox(height: 8),
          _PlaceLine(Icons.location_on_rounded, Colors.red, _destinationLabel, onTap: () => _openPlaceSearch(destination: true)),
          if (_options.stops.isNotEmpty) ...[
            const SizedBox(height: 7),
            ..._options.stops.map((s) => Padding(padding: const EdgeInsets.only(bottom: 5), child: _PlaceLine(Icons.more_vert_rounded, Colors.orange, 'توقف: ${s.label}'))),
          ],
          if (_route != null) ...[
            const SizedBox(height: 10),
            Row(children: [Expanded(child: _MiniCard('مسافت', _route!.distanceLabel, Icons.route_rounded)), const SizedBox(width: 8), Expanded(child: _MiniCard('زمان', _route!.durationLabel, Icons.schedule_rounded))]),
          ],
          if (fare != null) ...[
            const SizedBox(height: 10),
            Container(padding: const EdgeInsets.all(14), decoration: BoxDecoration(color: _black, borderRadius: BorderRadius.circular(18)), child: Row(children: [const Icon(Icons.payments_rounded, color: _yellow), const SizedBox(width: 8), const Expanded(child: Text('کرایه سفر', style: TextStyle(color: Colors.white70))), Column(crossAxisAlignment: CrossAxisAlignment.end, children: [Text(money(fare.fare), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 17)), if (fare.discount > 0) Text('تخفیف ${money(fare.discount)}', style: const TextStyle(color: _yellow, fontSize: 10))])])),
            const SizedBox(height: 7),
            Row(children: [Expanded(child: OutlinedButton.icon(onPressed: _rideOptions, icon: const Icon(Icons.tune_rounded), label: const Text('گزینه‌های سفر'))), const SizedBox(width: 7), Expanded(child: OutlinedButton.icon(onPressed: _applyPromo, icon: const Icon(Icons.discount_rounded), label: Text(_options.promoCode.isEmpty ? 'کد تخفیف' : _options.promoCode)))]),
            if (_options.pickupNote.isNotEmpty || _options.silentTrip || _options.scheduledAt != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text([if (_options.silentTrip) 'سفر در سکوت', if (_options.pickupNote.isNotEmpty) 'توضیح مبدا', if (_options.scheduledAt != null) 'رزرو زمانی', _paymentFa(_options.paymentMethod)].join(' · '), style: const TextStyle(fontSize: 10, color: Colors.black54))),
          ],
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.redAccent, fontSize: 11))),
          const SizedBox(height: 10),
          SizedBox(width: double.infinity, height: 52, child: FilledButton(onPressed: _busy ? null : _selectMapPoint, style: FilledButton.styleFrom(backgroundColor: _black), child: Text(_step == 0 ? 'تأیید مبدا روی نقشه' : hasDest ? 'تغییر مقصد روی نقشه' : 'تأیید مقصد و محاسبه کرایه', style: const TextStyle(fontWeight: FontWeight.w900)))),
          if (fare != null) ...[
            const SizedBox(height: 7),
            SizedBox(width: double.infinity, height: 55, child: FilledButton.icon(onPressed: _busy ? null : _request, style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), icon: const Icon(Icons.local_taxi_rounded), label: Text(_options.scheduledAt == null ? 'درخواست RADO' : 'رزرو RADO', style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)))),
          ],
        ]),
      ),
    );
  }

  Widget _trackingCard(RideTrip t) {
    final canCancel = ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'arrived'].contains(t.status);
    return Material(
      elevation: 16,
      color: Colors.white,
      borderRadius: BorderRadius.circular(28),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [Container(width: 45, height: 45, decoration: BoxDecoration(color: const Color(0xFFFFE59A), borderRadius: BorderRadius.circular(14)), child: const Icon(Icons.local_taxi_rounded)), const SizedBox(width: 9), Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(t.statusFa, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)), if (t.time.isNotEmpty) Text(t.time, style: const TextStyle(fontSize: 10, color: Colors.black54))])), IconButton(onPressed: _share, icon: const Icon(Icons.share_rounded))]),
          const SizedBox(height: 10),
          _PlaceLine(Icons.radio_button_checked, Colors.green, t.pickup.label),
          const SizedBox(height: 6),
          _PlaceLine(Icons.location_on, Colors.red, t.destination.label),
          if (t.driver != null) ...[const SizedBox(height: 9), Container(width: double.infinity, padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: const Color(0xFFF5F5F2), borderRadius: BorderRadius.circular(16)), child: Text('${t.driver!.name} · ${t.driver!.vehicle} · ${t.driver!.plate}', style: const TextStyle(fontWeight: FontWeight.w800)))],
          const SizedBox(height: 10),
          Row(children: [Expanded(child: _MiniCard(t.status == 'completed' ? 'کرایه نهایی' : 'کرایه', money(t.finalFare ?? t.estimatedFare), Icons.payments_rounded)), const SizedBox(width: 8), Expanded(child: _MiniCard('وضعیت', t.statusFa, Icons.timeline_rounded))]),
          if (!t.terminal && ['driver_assigned', 'driver_arriving', 'arrived', 'in_progress'].contains(t.status)) ...[
            const SizedBox(height: 7),
            OutlinedButton.icon(onPressed: () => _changeDestinationDuringTrip(t), icon: const Icon(Icons.edit_location_alt_rounded), label: const Text('تغییر مقصد / بازنگری کرایه')),
          ],
          if (canCancel) Align(alignment: Alignment.center, child: TextButton(onPressed: _cancel, child: const Text('لغو سفر'))),
          if (t.status == 'completed') ...[const SizedBox(height: 7), Row(children: [Expanded(child: FilledButton(onPressed: _rate, style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), child: const Text('امتیاز به سفر'))), const SizedBox(width: 7), Expanded(child: FilledButton(onPressed: _reset, style: FilledButton.styleFrom(backgroundColor: _black), child: const Text('سفر جدید')))])],
          if (t.terminal && t.status != 'completed') SizedBox(width: double.infinity, child: FilledButton(onPressed: _reset, style: FilledButton.styleFrom(backgroundColor: _black), child: const Text('سفر جدید'))),
        ]),
      ),
    );
  }

  Future<void> _changeDestinationDuringTrip(RideTrip t) async {
    final id = _clientId;
    if (id == null) return;
    final p = await showModalBottomSheet<PlaceResult>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => _PlaceSearchSheet(platform: _platform, near: t.destination.point));
    if (p == null) return;
    try {
      final r = await _api.route(t.pickup.point, p.point);
      final newFare = await _platform.changeDestination(clientId: id, tripId: t.id, place: p, distanceMeters: r.distanceMeters, durationSeconds: r.durationSeconds);
      _show('مقصد تغییر کرد؛ کرایه جدید ${money(newFare)} است.');
      await _poll();
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }
}

class _PlaceSearchSheet extends StatefulWidget {
  const _PlaceSearchSheet({required this.platform, required this.near});
  final PassengerPlatformApi platform;
  final LatLng near;
  @override
  State<_PlaceSearchSheet> createState() => _PlaceSearchSheetState();
}

class _PlaceSearchSheetState extends State<_PlaceSearchSheet> {
  final _c = TextEditingController();
  List<PlaceResult> _items = const [];
  bool _loading = false;
  Timer? _debounce;
  @override
  void dispose() { _debounce?.cancel(); _c.dispose(); super.dispose(); }
  void _changed(String q) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 450), () async {
      if (q.trim().length < 2) return;
      setState(() => _loading = true);
      try { final x = await widget.platform.searchPlaces(q.trim(), widget.near); if (mounted) setState(() => _items = x); } catch (_) {} finally { if (mounted) setState(() => _loading = false); }
    });
  }
  @override
  Widget build(BuildContext context) => Directionality(textDirection: TextDirection.rtl, child: Padding(padding: EdgeInsets.only(left: 16, right: 16, top: 16, bottom: MediaQuery.of(context).viewInsets.bottom + 12), child: SizedBox(height: MediaQuery.of(context).size.height * .75, child: Column(children: [
    const Text('جستجوی مکان در بانه', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
    const SizedBox(height: 10),
    TextField(controller: _c, autofocus: true, onChanged: _changed, decoration: const InputDecoration(prefixIcon: Icon(Icons.search_rounded), hintText: 'مثلاً هتل ستاره طلایی، بیمارستان، پاساژ...', border: OutlineInputBorder())),
    if (_loading) const LinearProgressIndicator(),
    const SizedBox(height: 8),
    Expanded(child: ListView.builder(itemCount: _items.length, itemBuilder: (_, i) { final p = _items[i]; return ListTile(leading: const Icon(Icons.place_rounded, color: Colors.red), title: Text(p.title, style: const TextStyle(fontWeight: FontWeight.w800)), subtitle: Text(p.address), onTap: () => Navigator.pop(context, p)); })),
  ]))));
}

class _RideOptionsSheet extends StatefulWidget {
  const _RideOptionsSheet({required this.initial, required this.onAddStop});
  final RideOptions initial;
  final Future<void> Function() onAddStop;
  @override
  State<_RideOptionsSheet> createState() => _RideOptionsSheetState();
}

class _RideOptionsSheetState extends State<_RideOptionsSheet> {
  late bool silent = widget.initial.silentTrip;
  late String payment = widget.initial.paymentMethod;
  late String service = widget.initial.serviceType;
  late final TextEditingController note = TextEditingController(text: widget.initial.pickupNote);
  DateTime? scheduled;
  late List<TripStopDraft> stops = [...widget.initial.stops];
  @override
  void initState() { super.initState(); scheduled = widget.initial.scheduledAt; }
  @override
  void dispose() { note.dispose(); super.dispose(); }
  Future<void> _schedule() async {
    final choice = await showModalBottomSheet<int>(context: context, builder: (ctx) => Directionality(textDirection: TextDirection.rtl, child: SafeArea(child: Column(mainAxisSize: MainAxisSize.min, children: [const ListTile(title: Text('رزرو سفر', style: TextStyle(fontWeight: FontWeight.w900))), ListTile(title: const Text('۳۰ دقیقه دیگر'), onTap: () => Navigator.pop(ctx, 30)), ListTile(title: const Text('۱ ساعت دیگر'), onTap: () => Navigator.pop(ctx, 60)), ListTile(title: const Text('۲ ساعت دیگر'), onTap: () => Navigator.pop(ctx, 120)), ListTile(title: const Text('لغو رزرو زمانی'), onTap: () => Navigator.pop(ctx, 0))]))));
    if (choice != null) setState(() => scheduled = choice == 0 ? null : DateTime.now().add(Duration(minutes: choice)));
  }
  @override
  Widget build(BuildContext context) => Directionality(textDirection: TextDirection.rtl, child: Padding(padding: EdgeInsets.only(left: 16, right: 16, top: 16, bottom: MediaQuery.of(context).viewInsets.bottom + 16), child: SingleChildScrollView(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
    const Text('گزینه‌های سفر', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 21)),
    SwitchListTile(value: silent, onChanged: (v) => setState(() => silent = v), title: const Text('سفر در سکوت'), subtitle: const Text('ترجیح می‌دهم گفت‌وگوی غیرضروری نداشته باشیم.')),
    TextField(controller: note, maxLength: 500, decoration: const InputDecoration(labelText: 'توضیح محل سوارشدن', hintText: 'مثلاً ورودی اصلی هتل، روبه‌روی داروخانه...')),
    const Text('روش پرداخت', style: TextStyle(fontWeight: FontWeight.w800)),
    DropdownButtonFormField<String>(value: payment, items: const [DropdownMenuItem(value: 'cash', child: Text('نقدی')), DropdownMenuItem(value: 'wallet', child: Text('کیف پول')), DropdownMenuItem(value: 'online', child: Text('آنلاین')), DropdownMenuItem(value: 'corporate', child: Text('سازمانی'))], onChanged: (v) => setState(() => payment = v ?? 'cash')),
    const SizedBox(height: 10),
    const Text('نوع سرویس', style: TextStyle(fontWeight: FontWeight.w800)),
    DropdownButtonFormField<String>(value: service, items: const [DropdownMenuItem(value: 'economy', child: Text('اقتصادی')), DropdownMenuItem(value: 'special', child: Text('ویژه')), DropdownMenuItem(value: 'phone', child: Text('تلفنی/اپراتور'))], onChanged: (v) => setState(() => service = v ?? 'economy')),
    const SizedBox(height: 8),
    ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.schedule_rounded), title: Text(scheduled == null ? 'سفر همین حالا' : 'رزرو برای حدود ${scheduled!.hour.toString().padLeft(2, '0')}:${scheduled!.minute.toString().padLeft(2, '0')}'), trailing: const Icon(Icons.chevron_left), onTap: _schedule),
    ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.add_location_alt_rounded), title: const Text('افزودن توقف / مقصد دوم'), subtitle: Text('${stops.length} توقف'), onTap: widget.onAddStop),
    if (stops.isNotEmpty) ...stops.asMap().entries.map((e) => ListTile(contentPadding: EdgeInsets.zero, dense: true, title: Text(e.value.label), trailing: IconButton(icon: const Icon(Icons.close), onPressed: () => setState(() => stops.removeAt(e.key))))),
    const SizedBox(height: 10),
    SizedBox(width: double.infinity, child: FilledButton(onPressed: () => Navigator.pop(context, RideOptions(pickupNote: note.text.trim(), silentTrip: silent, paymentMethod: payment, serviceType: service, promoCode: widget.initial.promoCode, scheduledAt: scheduled, stops: stops)), style: FilledButton.styleFrom(backgroundColor: _black), child: const Text('ثبت گزینه‌ها'))),
  ]))));
}

class _PlaceLine extends StatelessWidget {
  const _PlaceLine(this.icon, this.color, this.text, {this.onTap});
  final IconData icon; final Color color; final String text; final VoidCallback? onTap;
  @override
  Widget build(BuildContext context) => InkWell(onTap: onTap, borderRadius: BorderRadius.circular(16), child: Container(padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 10), decoration: BoxDecoration(color: const Color(0xFFF7F7F7), borderRadius: BorderRadius.circular(16)), child: Row(children: [Icon(icon, color: color), const SizedBox(width: 8), Expanded(child: Text(text, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700))), if (onTap != null) const Icon(Icons.chevron_left_rounded, size: 18)])));
}

class _MiniCard extends StatelessWidget {
  const _MiniCard(this.label, this.value, this.icon);
  final String label, value; final IconData icon;
  @override
  Widget build(BuildContext context) => Container(padding: const EdgeInsets.all(11), decoration: BoxDecoration(color: const Color(0xFFFFF7DA), borderRadius: BorderRadius.circular(15)), child: Row(children: [Icon(icon, size: 19), const SizedBox(width: 7), Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label, style: const TextStyle(fontSize: 9, color: Colors.black54)), Text(value, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 12))]))]));
}

class _Pin extends StatelessWidget {
  const _Pin();
  @override
  Widget build(BuildContext context) => Transform.translate(offset: const Offset(0, -24), child: Column(mainAxisSize: MainAxisSize.min, children: [Container(width: 52, height: 52, decoration: BoxDecoration(color: _yellow, shape: BoxShape.circle, border: Border.all(color: _black, width: 4)), child: const Icon(Icons.local_taxi_rounded, size: 28)), Container(width: 4, height: 20, color: _black)]));
}

class RideApi {
  RideApi() : _dio = Dio(BaseOptions(baseUrl: _base, connectTimeout: const Duration(seconds: 8), receiveTimeout: const Duration(seconds: 12), headers: const {'Accept': 'application/json'}, followRedirects: false, validateStatus: (s) => s != null && s < 400));
  final Dio _dio;

  Future<String> reverse(LatLng p) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/maps/reverse/', queryParameters: {'lat': p.latitude, 'lng': p.longitude});
    return (r.data?['formatted_address'] ?? 'موقعیت انتخاب‌شده').toString();
  }

  Future<RideRoute> route(LatLng a, LatLng b) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/maps/route/', queryParameters: {'origin': '${a.latitude},${a.longitude}', 'destination': '${b.latitude},${b.longitude}'});
    final points = ((r.data?['route_points'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .map((e) => LatLng(
              (e['lat'] as num?)?.toDouble() ?? 0,
              (e['lng'] as num?)?.toDouble() ?? 0,
            ))
        .where((p) => p.latitude.abs() <= 90 && p.longitude.abs() <= 180)
        .toList(growable: false);
    return RideRoute(
      distanceMeters: (r.data?['distance_meters'] as num?)?.toDouble() ?? 0,
      durationSeconds: (r.data?['duration_seconds'] as num?)?.toDouble() ?? 0,
      points: points.length >= 2 ? points : [a, b],
    );
  }

  Future<FareInfo> fare(RideRoute route, {required LatLng origin, required LatLng destination, required String clientId, required String promoCode, required String serviceType}) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/fare/estimate/', data: {'distance_meters': route.distanceMeters.round(), 'duration_seconds': route.durationSeconds.round(), 'origin_lat': origin.latitude, 'origin_lng': origin.longitude, 'destination_lat': destination.latitude, 'destination_lng': destination.longitude, 'client_id': clientId, 'promo_code': promoCode, 'service_type': serviceType});
    return FareInfo(fare: (r.data?['fare'] as num?)?.toInt() ?? 0, preDiscountFare: (r.data?['pre_discount_fare'] as num?)?.toInt() ?? (r.data?['fare'] as num?)?.toInt() ?? 0, discount: (r.data?['discount_amount'] as num?)?.toInt() ?? 0);
  }

  Future<RideTrip> requestTrip({required String clientId, required LatLng origin, required LatLng destination, required String originLabel, required String destinationLabel, required RideRoute route, required RideOptions options}) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/trips/', data: {'client_id': clientId, 'pickup': {'lat': origin.latitude, 'lng': origin.longitude}, 'destination': {'lat': destination.latitude, 'lng': destination.longitude}, 'pickup_label': originLabel, 'destination_label': destinationLabel, 'distance_meters': route.distanceMeters.round(), 'duration_seconds': route.durationSeconds.round(), 'pickup_note': options.pickupNote, 'silent_trip': options.silentTrip, 'payment_method': options.paymentMethod, 'service_type': options.serviceType, 'promo_code': options.promoCode, if (options.scheduledAt != null) 'scheduled_at': options.scheduledAt!.toIso8601String(), 'stops': options.stops.map((e) => e.toJson()).toList()});
    return RideTrip.fromJson((r.data?['trip'] as Map?)?.cast<String, dynamic>() ?? const {}, fallbackOrigin: TripPoint(origin.latitude, origin.longitude, originLabel), fallbackDestination: TripPoint(destination.latitude, destination.longitude, destinationLabel));
  }

  Future<RideTrip> tripStatus(String clientId, String tripId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/trips/status/', queryParameters: {'client_id': clientId, 'trip_id': tripId});
    return RideTrip.fromJson((r.data?['trip'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  Future<RideTrip> cancel(String clientId, String tripId) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/trips/status/', data: {'client_id': clientId, 'trip_id': tripId, 'action': 'cancel'});
    return RideTrip.fromJson((r.data?['trip'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  String message(Object e) {
    if (e is DioException) {
      final d = e.response?.data;
      if (d is Map && d['message'] != null) return d['message'].toString();
      if (d is Map && d['error'] != null) return 'خطای RADO: ${d['error']}';
      if (e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.receiveTimeout) return 'ارتباط با سرور RADO طول کشید.';
    }
    return 'عملیات انجام نشد. دوباره تلاش کنید.';
  }
}

class RideRoute {
  const RideRoute({required this.distanceMeters, required this.durationSeconds, this.points = const []});
  final double distanceMeters, durationSeconds;
  final List<LatLng> points;
  String get distanceLabel => distanceMeters < 1000 ? '${distanceMeters.round()} متر' : '${(distanceMeters / 1000).toStringAsFixed(1)} کیلومتر';
  String get durationLabel => '${max(1, (durationSeconds / 60).round())} دقیقه';
}

class FareInfo {
  const FareInfo({required this.fare, required this.preDiscountFare, required this.discount});
  final int fare, preDiscountFare, discount;
  FareInfo copyWith({int? fare, int? preDiscountFare, int? discount}) => FareInfo(fare: fare ?? this.fare, preDiscountFare: preDiscountFare ?? this.preDiscountFare, discount: discount ?? this.discount);
}

class TripPoint {
  const TripPoint(this.lat, this.lng, this.label);
  final double lat, lng; final String label;
  LatLng get point => LatLng(lat, lng);
  factory TripPoint.fromJson(Map<String, dynamic> j) => TripPoint((j['lat'] as num?)?.toDouble() ?? 0, (j['lng'] as num?)?.toDouble() ?? 0, (j['label'] ?? '').toString());
}

class TripDriver {
  const TripDriver(this.name, this.plate, this.vehicle);
  final String name, plate, vehicle;
  factory TripDriver.fromJson(Map<String, dynamic> j) => TripDriver((j['name'] ?? 'راننده RADO').toString(), (j['plate'] ?? '').toString(), (j['vehicle'] ?? '').toString());
}

class RideTrip {
  const RideTrip({required this.id, required this.status, required this.statusFa, required this.pickup, required this.destination, required this.estimatedFare, required this.finalFare, required this.time, required this.driver});
  final String id, status, statusFa, time; final TripPoint pickup, destination; final int estimatedFare; final int? finalFare; final TripDriver? driver;
  bool get terminal => ['completed', 'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_admin', 'expired'].contains(status);
  factory RideTrip.fromJson(Map<String, dynamic> j, {TripPoint? fallbackOrigin, TripPoint? fallbackDestination}) {
    final times = (j['times'] as Map?)?.cast<String, dynamic>() ?? const {};
    String jt(String k) => (((times[k] as Map?)?['jalali']) ?? '').toString();
    final time = [jt('completed'), jt('started'), jt('arrived'), jt('accepted'), jt('requested')].firstWhere((x) => x.isNotEmpty, orElse: () => '');
    return RideTrip(id: (j['id'] ?? '').toString(), status: (j['status'] ?? 'searching').toString(), statusFa: (j['status_fa'] ?? 'در جستجوی راننده').toString(), pickup: j['pickup'] is Map ? TripPoint.fromJson((j['pickup'] as Map).cast<String, dynamic>()) : fallbackOrigin ?? const TripPoint(0, 0, ''), destination: j['destination'] is Map ? TripPoint.fromJson((j['destination'] as Map).cast<String, dynamic>()) : fallbackDestination ?? const TripPoint(0, 0, ''), estimatedFare: (j['estimated_fare'] as num?)?.toInt() ?? 0, finalFare: (j['final_fare'] as num?)?.toInt(), time: time, driver: j['driver'] is Map ? TripDriver.fromJson((j['driver'] as Map).cast<String, dynamic>()) : null);
  }
}

String money(int v) { final s = v.toString(); final b = StringBuffer(); for (var i = 0; i < s.length; i++) { if (i > 0 && (s.length - i) % 3 == 0) b.write(','); b.write(s[i]); } return '${b.toString()} ریال'; }
String _paymentFa(String v) => switch (v) {'wallet' => 'کیف پول', 'online' => 'آنلاین', 'corporate' => 'سازمانی', _ => 'نقدی'};
