import 'dart:async';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:latlong2/latlong.dart';
import 'package:neshan_maps_flutter/map.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'advanced_passenger.dart'
    show FareInfo, RideApi, RideRoute, RideTrip, TripPoint, money;
import 'app_notifications.dart';
import 'live_trip.dart';
import 'platform_features.dart';

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
const _baneh = LatLng(35.9968, 45.8853);
const _apiBase = String.fromEnvironment(
  'RADO_API_BASE_URL',
  defaultValue: 'https://rado-taxi.sbs',
);

class RuntimePassengerPage extends StatefulWidget {
  const RuntimePassengerPage({super.key});

  @override
  State<RuntimePassengerPage> createState() => _RuntimePassengerPageState();
}

class _RuntimePassengerPageState extends State<RuntimePassengerPage> {
  final NeshanMapController _map = NeshanMapController();
  final RideApi _ride = RideApi();
  final PassengerPlatformApi _platform = PassengerPlatformApi(_apiBase);
  final PassengerRealtimeApi _realtime = PassengerRealtimeApi(_apiBase);
  final RadoNotifications _notifications = RadoNotifications.instance;
  final Dio _configApi = Dio(
    BaseOptions(
      baseUrl: _apiBase,
      connectTimeout: const Duration(seconds: 8),
      receiveTimeout: const Duration(seconds: 10),
      headers: const {'Accept': 'application/json'},
    ),
  );

  String _mapKey = '';
  bool _mapConfigLoading = true;
  bool _mapReady = false;
  bool _mapFailed = false;
  String _mapMessage = 'در حال آماده‌سازی نقشه…';

  LatLng _center = _baneh;
  LatLng? _origin;
  LatLng? _destination;
  String _originLabel = 'مبدا را انتخاب کنید';
  String _destinationLabel = 'مقصد را انتخاب کنید';
  RideRoute? _route;
  FareInfo? _fare;
  RideTrip? _trip;
  RideOptions _options = const RideOptions();
  String? _clientId;
  String? _error;
  bool _busy = false;
  int _step = 0;
  Timer? _poller;
  LiveTripSnapshot? _live;
  RideRoute? _driverRoute;
  int? _driverRouteEtaMinutes;
  int _lastEventId = 0;
  bool _polling = false;
  DateTime? _lastDriverRouteAt;

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
    final prefs = await SharedPreferences.getInstance();
    var id = prefs.getString('rado_passenger_client_id');
    if (id == null || id.isEmpty) {
      id =
          '${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
      await prefs.setString('rado_passenger_client_id', id);
    }
    if (mounted) setState(() => _clientId = id);
    try {
      await _notifications.init();
    } catch (_) {}
    await _loadMapConfig();
  }

  Future<void> _loadMapConfig() async {
    if (mounted) {
      setState(() {
        _mapConfigLoading = true;
        _mapFailed = false;
        _mapReady = false;
        _mapMessage = 'در حال آماده‌سازی نقشه…';
      });
    }
    try {
      final response = await _configApi.get<Map<String, dynamic>>(
        '/api/v1/maps/config/',
      );
      final key = (response.data?['map_key'] ?? '').toString().trim();
      if (key.isEmpty) throw StateError('map_key_missing');
      if (!mounted) return;
      setState(() {
        _mapKey = key;
        _mapConfigLoading = false;
      });
      WidgetsBinding.instance.addPostFrameCallback((_) => _verifyMap());
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _mapKey = '';
        _mapConfigLoading = false;
        _mapFailed = true;
        _mapMessage =
            'تنظیمات نقشه نیاز به بررسی دارد. جستجوی مکان همچنان فعال است.';
      });
    }
  }

  Future<void> _verifyMap() async {
    if (_mapKey.isEmpty || _mapFailed) return;
    try {
      await _map.ready.timeout(const Duration(seconds: 10));
      final current = await _map.getCurrentLocation().timeout(
        const Duration(seconds: 4),
      );
      if (!mounted) return;
      setState(() {
        if (current != null) _center = current;
        _mapReady = true;
        _mapMessage = '';
      });
      await _syncMapOverlays();
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _mapFailed = true;
        _mapReady = false;
        _mapMessage =
            'نقشه نشان بارگذاری نشد. Web Map Key را در پنل RADO بررسی کنید.';
      });
    }
  }

  Future<LatLng> _exactCenter() async {
    if (!_mapReady) return _center;
    try {
      final point = await _map.getCurrentLocation().timeout(
        const Duration(seconds: 3),
      );
      if (point != null) _center = point;
    } catch (_) {}
    return _center;
  }

  Future<void> _selectMapPoint() async {
    if (_busy) return;
    if (!_mapReady) {
      _show('نقشه آماده نیست؛ از جستجوی مکان استفاده کنید.');
      return;
    }
    setState(() => _busy = true);
    try {
      final point = await _exactCenter();
      final label = await _ride.reverse(point);
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
      await _syncMapOverlays();
    } catch (e) {
      _show(_ride.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _calculate() async {
    final a = _origin, b = _destination;
    if (a == null || b == null) return;
    setState(() {
      _fare = null;
      _error = null;
    });
    try {
      final route = await _ride.route(a, b);
      final fare = await _ride.fare(
        route,
        origin: a,
        destination: b,
        clientId: _clientId ?? '',
        promoCode: _options.promoCode,
        serviceType: _options.serviceType,
      );
      if (!mounted) return;
      setState(() {
        _route = route;
        _fare = fare;
      });
      await _syncMapOverlays();
      await _fitRoute(route);
    } catch (e) {
      if (mounted) setState(() => _error = _ride.message(e));
    }
  }

  Future<void> _fitRoute(RideRoute route) async {
    if (!_mapReady || route.points.length < 2) return;
    final points = <LatLng>[...route.points];
    if (_origin != null) points.add(_origin!);
    if (_destination != null) points.add(_destination!);
    var north = points.first.latitude;
    var south = points.first.latitude;
    var east = points.first.longitude;
    var west = points.first.longitude;
    for (final p in points.skip(1)) {
      north = max(north, p.latitude);
      south = min(south, p.latitude);
      east = max(east, p.longitude);
      west = min(west, p.longitude);
    }
    final latPad = max((north - south) * .34, .0020);
    final lngPad = max((east - west) * .24, .0016);
    try {
      await _map.ready.timeout(const Duration(seconds: 3));
      _map.fitBounds(
        north + latPad,
        south - latPad,
        east + lngPad,
        west - lngPad,
      );
      await Future<void>.delayed(const Duration(milliseconds: 160));
      final zoom = await _map.getCurrentZoom();
      // The booking/tracking card covers the lower part of the screen. Shift the
      // camera slightly south so both endpoints and the full route stay above it.
      final visualLat =
          ((north + south) / 2) - max((north - south) * .18, .0007);
      final visualLng = (east + west) / 2;
      _map.moveToLocation(visualLat, visualLng, zoom: zoom);
    } catch (_) {}
  }

  Future<void> _searchPlace({
    required bool destination,
    bool addStop = false,
  }) async {
    final selected = await showModalBottomSheet<PlaceResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _PlaceSearchSheet(platform: _platform, near: _center),
    );
    if (selected == null || !mounted) return;
    if (addStop) {
      setState(
        () => _options = _options.copyWith(
          stops: [
            ..._options.stops,
            TripStopDraft(point: selected.point, label: selected.displayLabel),
          ],
        ),
      );
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
      if (_mapReady) _map.moveToLocation(selected.lat, selected.lng, zoom: 16);
      await _calculate();
    } else {
      setState(() {
        _origin = selected.point;
        _originLabel = selected.displayLabel;
        _center = selected.point;
        _step = 1;
        _destination = null;
        _destinationLabel = 'مقصد را انتخاب کنید';
        _route = null;
        _fare = null;
      });
      if (_mapReady) _map.moveToLocation(selected.lat, selected.lng, zoom: 16);
      await _syncMapOverlays();
    }
  }

  Future<void> _requestTrip() async {
    final id = _clientId,
        a = _origin,
        b = _destination,
        route = _route,
        fare = _fare;
    if (id == null ||
        a == null ||
        b == null ||
        route == null ||
        fare == null ||
        _busy)
      return;
    setState(() => _busy = true);
    try {
      final trip = await _ride.requestTrip(
        clientId: id,
        origin: a,
        destination: b,
        originLabel: _originLabel,
        destinationLabel: _destinationLabel,
        route: route,
        options: _options,
      );
      if (!mounted) return;
      setState(() {
        _trip = trip;
        _live = null;
        _driverRoute = null;
        _driverRouteEtaMinutes = null;
        _lastEventId = 0;
        _lastDriverRouteAt = null;
      });
      _poller?.cancel();
      _poller = Timer.periodic(const Duration(seconds: 3), (_) => _poll());
      await _syncMapOverlays();
      await _poll();
      _show('درخواست برای رانندگان نزدیک ارسال شد.');
    } catch (e) {
      _show(_ride.message(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _poll() async {
    final id = _clientId, trip = _trip;
    if (id == null || trip == null || _polling) return;
    _polling = true;
    try {
      final oldStatus = trip.status;
      final fresh = await _ride.tripStatus(id, trip.id);
      LiveTripSnapshot? live;
      try {
        live = await _realtime.snapshot(
          clientId: id,
          tripId: trip.id,
          sinceEventId: _lastEventId,
        );
      } catch (_) {}
      if (!mounted) return;
      setState(() {
        _trip = fresh;
        if (live != null) {
          _live = live;
          if (live.events.isNotEmpty)
            _lastEventId = max(_lastEventId, live.lastEventId);
        }
      });
      if (oldStatus != fresh.status) await _notifyTripTransition(fresh);
      await _updateDriverRoute(fresh);
      await _syncMapOverlays();
      if (fresh.terminal) _poller?.cancel();
    } catch (_) {
      // A transient poll failure must never remove the last known live position.
    } finally {
      _polling = false;
    }
  }

  Future<void> _notifyTripTransition(RideTrip trip) async {
    String? title;
    String? body;
    switch (trip.status) {
      case 'driver_assigned':
      case 'driver_arriving':
        title = 'راننده سفر را پذیرفت';
        body = trip.driver == null
            ? 'راننده RADO در مسیر مبدا است.'
            : '${trip.driver!.name} در مسیر مبدا است.';
        break;
      case 'arrived':
        title = 'راننده رسید';
        body = 'راننده RADO به مبدا رسیده است.';
        break;
      case 'in_progress':
        title = 'سفر شروع شد';
        body = 'سفر RADO به سمت مقصد آغاز شد.';
        break;
      case 'completed':
        title = 'سفر پایان یافت';
        body = 'به مقصد رسیدید. از همراهی شما با RADO ممنونیم.';
        break;
      case 'cancelled_by_driver':
        title = 'سفر توسط راننده لغو شد';
        body = 'وضعیت سفر تغییر کرد؛ برای درخواست بعدی آماده‌ایم.';
        break;
      case 'cancelled_by_admin':
        title = 'سفر توسط پشتیبانی لغو شد';
        body = 'وضعیت سفر شما توسط RADO تغییر کرد.';
        break;
      case 'expired':
        title = 'راننده‌ای پیدا نشد';
        body = 'درخواست این سفر منقضی شد.';
        break;
    }
    if (title != null && body != null) {
      try {
        await _notifications.show(title: title, body: body, payload: trip.id);
      } catch (_) {}
    }
  }

  Future<void> _updateDriverRoute(RideTrip trip) async {
    final pos = _live?.driverPosition;
    if (pos == null || trip.terminal) {
      if (mounted && trip.terminal)
        setState(() {
          _driverRoute = null;
          _driverRouteEtaMinutes = null;
        });
      return;
    }
    if (![
      'driver_assigned',
      'driver_arriving',
      'arrived',
      'in_progress',
    ].contains(trip.status))
      return;
    final now = DateTime.now();
    if (_lastDriverRouteAt != null &&
        now.difference(_lastDriverRouteAt!).inSeconds < 15 &&
        _driverRoute != null)
      return;
    final target = trip.status == 'in_progress'
        ? trip.destination.point
        : trip.pickup.point;
    try {
      final route = await _ride.route(pos.point, target);
      if (!mounted) return;
      setState(() {
        _driverRoute = route;
        _driverRouteEtaMinutes = max(1, (route.durationSeconds / 60).round());
        _lastDriverRouteAt = now;
      });
    } catch (_) {}
  }

  List<NeshanMarker> _mapMarkers() => [
    if (_origin != null)
      NeshanMarker(
        id: 'origin',
        position: _origin!,
        color: Colors.green,
        title: 'مبدا • $_originLabel',
      ),
    if (_destination != null)
      NeshanMarker(
        id: 'destination',
        position: _destination!,
        color: Colors.red,
        title: 'مقصد • $_destinationLabel',
      ),
    if (_live?.driverPosition != null)
      NeshanMarker(
        id: 'live-driver',
        position: _live!.driverPosition!.point,
        color: _yellow,
        title:
            _live?.driverProfile?.name ?? _trip?.driver?.name ?? 'راننده RADO',
      ),
  ];

  List<NeshanCircle> _mapCircles() => [
    if (_origin != null)
      NeshanCircle(
        id: 'origin-halo',
        center: _origin!,
        radius: 28,
        fillColor: Colors.green,
        fillOpacity: .12,
        strokeColor: Colors.green,
        strokeWidth: 2.5,
        strokeOpacity: .9,
      ),
    if (_destination != null)
      NeshanCircle(
        id: 'destination-halo',
        center: _destination!,
        radius: 28,
        fillColor: Colors.red,
        fillOpacity: .10,
        strokeColor: Colors.red,
        strokeWidth: 2.5,
        strokeOpacity: .9,
      ),
  ];

  List<NeshanPolyline> _mapPolylines() => [
    if (_route != null && _route!.points.length >= 2)
      NeshanPolyline(
        id: 'route-shadow',
        coordinates: _route!.points,
        color: _black,
        width: 8,
        opacity: .72,
      ),
    if (_route != null && _route!.points.length >= 2)
      NeshanPolyline(
        id: 'route-main',
        coordinates: _route!.points,
        color: _yellow,
        width: 5,
        opacity: 1,
      ),
    if (_driverRoute != null && _driverRoute!.points.length >= 2)
      NeshanPolyline(
        id: 'driver-route-shadow',
        coordinates: _driverRoute!.points,
        color: _black,
        width: 7,
        opacity: .55,
      ),
    if (_driverRoute != null && _driverRoute!.points.length >= 2)
      NeshanPolyline(
        id: 'driver-route-live',
        coordinates: _driverRoute!.points,
        color: Colors.blue,
        width: 4.5,
        opacity: .95,
      ),
  ];

  Future<void> _syncMapOverlays() async {
    if (!_mapReady) return;
    try {
      await _map.ready.timeout(const Duration(seconds: 3));
      _map.updateMarkers(_mapMarkers());
      _map.updateCircles(_mapCircles());
      _map.updatePolylines(_mapPolylines());
    } catch (_) {}
  }

  void _beginDestinationReselect() {
    setState(() {
      _destination = null;
      _destinationLabel = 'مقصد را روی نقشه انتخاب کنید';
      _route = null;
      _fare = null;
      _error = null;
      _step = 1;
    });
    unawaited(_syncMapOverlays());
  }

  Future<void> _cancel() async {
    final id = _clientId, trip = _trip;
    if (id == null || trip == null) return;
    try {
      final fresh = await _ride.cancel(id, trip.id);
      if (!mounted) return;
      setState(() => _trip = fresh);
      _poller?.cancel();
    } catch (e) {
      _show(_ride.message(e));
    }
  }

  Future<void> _share() async {
    final id = _clientId, trip = _trip;
    if (id == null || trip == null) return;
    try {
      final url = await _platform.shareTrip(id, trip.id);
      await Clipboard.setData(ClipboardData(text: url));
      _show('لینک وضعیت سفر کپی شد.');
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _rate() async {
    final id = _clientId, trip = _trip;
    if (id == null || trip == null) return;
    var score = 5;
    final comment = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('امتیاز به سفر'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Wrap(
                children: List.generate(
                  5,
                  (i) => IconButton(
                    onPressed: () => setLocal(() => score = i + 1),
                    icon: Icon(
                      i < score
                          ? Icons.star_rounded
                          : Icons.star_border_rounded,
                      color: _yellow,
                    ),
                  ),
                ),
              ),
              TextField(
                controller: comment,
                decoration: const InputDecoration(labelText: 'نظر شما'),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('بعداً'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('ثبت'),
            ),
          ],
        ),
      ),
    );
    if (ok == true) {
      try {
        await _platform.rateTrip(
          clientId: id,
          tripId: trip.id,
          score: score,
          comment: comment.text.trim(),
        );
        _show('ممنون از امتیاز شما.');
      } catch (e) {
        _show(_platform.messageFromError(e));
      }
    }
  }

  Future<void> _changeDestination() async {
    final id = _clientId, trip = _trip;
    if (id == null || trip == null) return;
    final place = await showModalBottomSheet<PlaceResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) =>
          _PlaceSearchSheet(platform: _platform, near: trip.destination.point),
    );
    if (place == null) return;
    try {
      final route = await _ride.route(trip.pickup.point, place.point);
      final fare = await _platform.changeDestination(
        clientId: id,
        tripId: trip.id,
        place: place,
        distanceMeters: route.distanceMeters,
        durationSeconds: route.durationSeconds,
      );
      _show('مقصد تغییر کرد؛ کرایه جدید ${money(fare)} است.');
      await _poll();
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _applyPromo() async {
    final id = _clientId, fare = _fare;
    if (id == null || fare == null) return;
    final controller = TextEditingController(text: _options.promoCode);
    final code = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('کد تخفیف'),
        content: TextField(
          controller: controller,
          textCapitalization: TextCapitalization.characters,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('لغو'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: const Text('اعمال'),
          ),
        ],
      ),
    );
    if (code == null || code.isEmpty) return;
    try {
      final promo = await _platform.validatePromo(
        id,
        code,
        fare.preDiscountFare,
      );
      if (!mounted) return;
      setState(() {
        _options = _options.copyWith(promoCode: promo.code);
        _fare = fare.copyWith(fare: promo.payable, discount: promo.discount);
      });
      _show('تخفیف ${money(promo.discount)} اعمال شد.');
    } catch (e) {
      _show(_platform.messageFromError(e));
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
              const Text(
                'مکان‌های منتخب',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
              ),
              if (items.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 20),
                  child: Text('هنوز مکانی ذخیره نشده است.'),
                ),
              ...items.map(
                (f) => ListTile(
                  leading: Icon(
                    f.kind == 'home'
                        ? Icons.home_rounded
                        : f.kind == 'work'
                        ? Icons.work_rounded
                        : Icons.star_rounded,
                  ),
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
                ),
              ),
              const Divider(),
              ListTile(
                leading: const Icon(Icons.add_location_alt_rounded),
                title: const Text('ذخیره نقطه فعلی'),
                onTap: () {
                  Navigator.pop(ctx);
                  _saveCurrentFavorite();
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
      label = await _ride.reverse(point);
    } catch (_) {
      label = 'مکان منتخب';
    }
    if (!mounted) return;
    final title = TextEditingController();
    var kind = 'favorite';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('ذخیره مکان'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(label, style: const TextStyle(fontSize: 12)),
              TextField(
                controller: title,
                decoration: const InputDecoration(labelText: 'نام مکان'),
              ),
              DropdownButtonFormField<String>(
                initialValue: kind,
                items: const [
                  DropdownMenuItem(value: 'favorite', child: Text('منتخب')),
                  DropdownMenuItem(value: 'home', child: Text('خانه')),
                  DropdownMenuItem(value: 'work', child: Text('محل کار')),
                ],
                onChanged: (v) => setLocal(() => kind = v ?? 'favorite'),
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
              child: const Text('ذخیره'),
            ),
          ],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await _platform.saveFavorite(
        clientId: id,
        kind: kind,
        title: title.text.trim().isEmpty ? 'مکان منتخب' : title.text.trim(),
        label: label,
        point: point,
      );
      _show('مکان ذخیره شد.');
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _rideOptions() async {
    final updated = await showModalBottomSheet<RideOptions>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _RideOptionsSheet(
        initial: _options,
        addStop: () async {
          Navigator.pop(context);
          await _searchPlace(destination: true, addStop: true);
        },
      ),
    );
    if (updated != null && mounted) {
      setState(() => _options = updated);
      if (_route != null) await _calculate();
    }
  }

  Future<void> _account() async {
    final id = _clientId;
    if (id == null) return;
    try {
      final values = await Future.wait([
        _platform.history(id),
        _platform.wallet(id),
        _platform.loyaltyPoints(id),
        _platform.supportTickets(id),
      ]);
      final history = values[0] as List<HistoryTrip>;
      final wallet = values[1] as WalletSummary;
      final points = values[2] as int;
      final tickets = values[3] as List<SupportTicket>;
      if (!mounted) return;
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        builder: (ctx) => Directionality(
          textDirection: TextDirection.rtl,
          child: DraggableScrollableSheet(
            expand: false,
            initialChildSize: .82,
            minChildSize: .45,
            maxChildSize: .95,
            builder: (_, scroll) => ListView(
              controller: scroll,
              padding: const EdgeInsets.all(18),
              children: [
                const Text(
                  'حساب RADO',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 22),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: _MiniCard(
                        'کیف پول',
                        money(wallet.balance),
                        Icons.account_balance_wallet_rounded,
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: _MiniCard(
                        'امتیاز',
                        '$points',
                        Icons.workspace_premium_rounded,
                      ),
                    ),
                  ],
                ),
                ListTile(
                  leading: const Icon(Icons.support_agent_rounded),
                  title: const Text('پشتیبانی'),
                  subtitle: Text('${tickets.length} تیکت'),
                  onTap: () {
                    Navigator.pop(ctx);
                    _support();
                  },
                ),
                const Divider(),
                const Text(
                  'سفرهای اخیر',
                  style: TextStyle(fontWeight: FontWeight.w900),
                ),
                ...history
                    .take(15)
                    .map(
                      (h) => ListTile(
                        leading: const Icon(Icons.route_rounded),
                        title: Text(
                          '${h.pickup} ← ${h.destination}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        subtitle: Text('${h.requestedAt} · ${h.statusFa}'),
                        trailing: Text(
                          money(h.fare),
                          style: const TextStyle(fontSize: 10),
                        ),
                      ),
                    ),
              ],
            ),
          ),
        ),
      );
    } catch (e) {
      _show(_platform.messageFromError(e));
    }
  }

  Future<void> _support() async {
    final id = _clientId;
    if (id == null) return;
    final subject = TextEditingController();
    final body = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('پشتیبانی RADO'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: subject,
              decoration: const InputDecoration(labelText: 'موضوع'),
            ),
            TextField(
              controller: body,
              minLines: 3,
              maxLines: 5,
              decoration: const InputDecoration(labelText: 'توضیحات'),
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
      await _platform.createSupportTicket(
        clientId: id,
        subject: subject.text.trim(),
        message: body.text.trim(),
        tripId: _trip?.id,
      );
      _show('پیام برای پشتیبانی ارسال شد.');
    } catch (e) {
      _show(_platform.messageFromError(e));
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
      _live = null;
      _driverRoute = null;
      _driverRouteEtaMinutes = null;
      _lastEventId = 0;
      _lastDriverRouteAt = null;
      _options = const RideOptions();
      _error = null;
      _step = 0;
    });
    unawaited(_syncMapOverlays());
  }

  void _show(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: TextDirection.rtl,
    child: Scaffold(
      backgroundColor: const Color(0xFFF3F3F0),
      body: SafeArea(
        child: Stack(
          children: [
            Positioned.fill(child: _mapLayer()),
            if (_trip == null &&
                _mapReady &&
                (_origin == null || _destination == null))
              Positioned.fill(
                child: IgnorePointer(
                  child: Align(
                    alignment: Alignment.center,
                    child: _SelectionPin(destination: _step > 0),
                  ),
                ),
              ),
            Positioned(top: 12, left: 12, right: 12, child: _header()),
            Positioned(
              left: 12,
              right: 12,
              bottom: 12,
              child: _trip == null ? _bookingCard() : _trackingCard(_trip!),
            ),
          ],
        ),
      ),
    ),
  );

  Widget _mapLayer() {
    if (_mapConfigLoading)
      return _mapPlaceholder('در حال دریافت تنظیمات نقشه…', loading: true);
    if (_mapKey.isEmpty || _mapFailed) return _mapPlaceholder(_mapMessage);
    return Stack(
      children: [
        Positioned.fill(
          child: NeshanMap(
            mapKey: _mapKey,
            controller: _map,
            config: const NeshanMapConfig(
              initialCenter: _baneh,
              initialZoom: 15,
              mapType: NeshanMapType.neshanVector,
              showTraffic: true,
              showPoi: true,
              showCurrentLocationButton: true,
            ),
            markers: _mapMarkers(),
            circles: _mapCircles(),
            polylines: _mapPolylines(),
            onLocationChanged: (lat, lng) => _center = LatLng(lat, lng),
            onError: (message, error, stack) {
              if (!mounted) return;
              setState(() {
                _mapFailed = true;
                _mapReady = false;
                _mapMessage = 'نقشه نشان بارگذاری نشد. تنظیمات Web Map Key را بررسی کنید.';
              });
            },
            onLocationError: (message, error, stack) =>
                debugPrint('RADO map location: $message'),
          ),
        ),
        if (!_mapReady)
          Positioned.fill(
            child: _mapPlaceholder(
              'در حال اتصال امن به نقشه نشان…',
              loading: true,
            ),
          ),
      ],
    );
  }

  Widget _mapPlaceholder(String message, {bool loading = false}) => Container(
    color: const Color(0xFFE9E9E5),
    alignment: Alignment.center,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 34),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (loading)
            const CircularProgressIndicator()
          else
            const Icon(Icons.map_outlined, size: 62, color: Colors.black45),
          const SizedBox(height: 14),
          Text(
            message,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontWeight: FontWeight.w800,
              color: Colors.black54,
            ),
          ),
          const SizedBox(height: 10),
          if (!loading)
            OutlinedButton.icon(
              onPressed: _loadMapConfig,
              icon: const Icon(Icons.refresh),
              label: const Text('تلاش دوباره'),
            ),
        ],
      ),
    ),
  );

  Widget _header() => Material(
    elevation: 7,
    color: Colors.white,
    borderRadius: BorderRadius.circular(24),
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 10),
      child: Row(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(14),
            child: Image.asset(
              'assets/branding/rado-passenger.png',
              width: 48,
              height: 48,
            ),
          ),
          const SizedBox(width: 10),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'RADO',
                  style: TextStyle(fontSize: 21, fontWeight: FontWeight.w900),
                ),
                Text('تاکسی اینترنتی بانه', style: TextStyle(fontSize: 11)),
              ],
            ),
          ),
          IconButton(
            tooltip: 'جستجوی مکان',
            onPressed: () => _searchPlace(destination: _step > 0),
            icon: const Icon(Icons.search_rounded),
          ),
          IconButton(
            tooltip: 'منتخب‌ها',
            onPressed: _favorites,
            icon: const Icon(Icons.star_outline_rounded),
          ),
          IconButton(
            tooltip: 'حساب',
            onPressed: _account,
            icon: const Icon(Icons.person_outline_rounded),
          ),
        ],
      ),
    ),
  );

  Widget _bookingCard() {
    final fare = _fare;
    return Material(
      elevation: 16,
      color: Colors.white,
      borderRadius: BorderRadius.circular(28),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _PlaceLine(
              Icons.radio_button_checked_rounded,
              Colors.green,
              _originLabel,
              onTap: () => _searchPlace(destination: false),
            ),
            const SizedBox(height: 8),
            _PlaceLine(
              Icons.location_on_rounded,
              Colors.red,
              _destinationLabel,
              onTap: () => _searchPlace(destination: true),
            ),
            if (_options.stops.isNotEmpty) ...[
              const SizedBox(height: 6),
              ..._options.stops.map(
                (s) => Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: _PlaceLine(
                    Icons.more_vert_rounded,
                    Colors.orange,
                    'توقف: ${s.label}',
                  ),
                ),
              ),
            ],
            if (_route != null) ...[
              const SizedBox(height: 9),
              Row(
                children: [
                  Expanded(
                    child: _MiniCard(
                      'مسافت',
                      _route!.distanceLabel,
                      Icons.route_rounded,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: _MiniCard(
                      'زمان',
                      _route!.durationLabel,
                      Icons.schedule_rounded,
                    ),
                  ),
                ],
              ),
            ],
            if (fare != null) ...[
              const SizedBox(height: 9),
              Container(
                padding: const EdgeInsets.all(13),
                decoration: BoxDecoration(
                  color: _black,
                  borderRadius: BorderRadius.circular(18),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.payments_rounded, color: _yellow),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text(
                        'کرایه سفر',
                        style: TextStyle(color: Colors.white70),
                      ),
                    ),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          money(fare.fare),
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                            fontSize: 17,
                          ),
                        ),
                        if (fare.discount > 0)
                          Text(
                            'تخفیف ${money(fare.discount)}',
                            style: const TextStyle(
                              color: _yellow,
                              fontSize: 10,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 7),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _rideOptions,
                      icon: const Icon(Icons.tune),
                      label: const Text('گزینه‌ها'),
                    ),
                  ),
                  const SizedBox(width: 7),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _applyPromo,
                      icon: const Icon(Icons.discount),
                      label: Text(
                        _options.promoCode.isEmpty
                            ? 'تخفیف'
                            : _options.promoCode,
                      ),
                    ),
                  ),
                ],
              ),
            ],
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.redAccent, fontSize: 11),
                ),
              ),
            const SizedBox(height: 9),
            SizedBox(
              width: double.infinity,
              height: 51,
              child: FilledButton(
                onPressed: _busy
                    ? null
                    : (_destination != null
                          ? _beginDestinationReselect
                          : _selectMapPoint),
                style: FilledButton.styleFrom(backgroundColor: _black),
                child: Text(
                  _step == 0
                      ? 'تأیید مبدا روی نقشه'
                      : _destination == null
                      ? 'تأیید مقصد و محاسبه کرایه'
                      : 'تغییر مقصد روی نقشه',
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
              ),
            ),
            if (fare != null) ...[
              const SizedBox(height: 7),
              SizedBox(
                width: double.infinity,
                height: 54,
                child: FilledButton.icon(
                  onPressed: _busy ? null : _requestTrip,
                  style: FilledButton.styleFrom(
                    backgroundColor: _yellow,
                    foregroundColor: _black,
                  ),
                  icon: const Icon(Icons.local_taxi_rounded),
                  label: const Text(
                    'درخواست RADO',
                    style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _liveDriverCard(RideTrip trip) {
    final profile = _live?.driverProfile;
    final name = profile?.name ?? trip.driver?.name ?? 'راننده RADO';
    final vehicle = profile?.vehicle ?? trip.driver?.vehicle ?? '';
    final plate = profile?.plate ?? trip.driver?.plate ?? '';
    final color = profile?.color ?? '';
    final rating = profile?.rating;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF5F5F2),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Container(
            width: 45,
            height: 45,
            decoration: BoxDecoration(
              color: _yellow,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Icon(Icons.local_taxi_rounded),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 14,
                  ),
                ),
                Text(
                  [
                    vehicle,
                    color,
                    plate,
                  ].where((e) => e.trim().isNotEmpty).join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11, color: Colors.black54),
                ),
              ],
            ),
          ),
          if (rating != null)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.star_rounded, color: _yellow, size: 18),
                  Text(
                    rating.toStringAsFixed(1),
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _liveEtaCard(RideTrip trip) {
    final eta = _live!.eta!;
    final minutes = _driverRouteEtaMinutes ?? eta.minutes;
    final approaching = trip.status != 'in_progress';
    final title = approaching ? 'رسیدن راننده' : 'زمان تا مقصد';
    final value = trip.status == 'arrived'
        ? 'راننده رسیده است'
        : 'حدود $minutes دقیقه';
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF5CC),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          const Icon(Icons.navigation_rounded, color: _black),
          const SizedBox(width: 9),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(fontSize: 10, color: Colors.black54),
                ),
                Text(
                  value,
                  style: const TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 14,
                  ),
                ),
              ],
            ),
          ),
          Text(
            eta.distanceLabel,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }

  Widget _trackingCard(RideTrip trip) {
    final canCancel = [
      'requested',
      'searching',
      'driver_assigned',
      'driver_arriving',
      'arrived',
    ].contains(trip.status);
    final canChange =
        [
          'driver_assigned',
          'driver_arriving',
          'arrived',
          'in_progress',
        ].contains(trip.status) &&
        !trip.terminal;
    return Material(
      elevation: 16,
      color: Colors.white,
      borderRadius: BorderRadius.circular(28),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: const Color(0xFFFFE59A),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: const Icon(Icons.local_taxi_rounded),
                ),
                const SizedBox(width: 9),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        trip.statusFa,
                        style: const TextStyle(
                          fontWeight: FontWeight.w900,
                          fontSize: 18,
                        ),
                      ),
                      if (trip.time.isNotEmpty)
                        Text(
                          trip.time,
                          style: const TextStyle(
                            fontSize: 10,
                            color: Colors.black54,
                          ),
                        ),
                    ],
                  ),
                ),
                IconButton(
                  onPressed: _share,
                  icon: const Icon(Icons.share_rounded),
                ),
              ],
            ),
            const SizedBox(height: 9),
            _PlaceLine(
              Icons.radio_button_checked,
              Colors.green,
              trip.pickup.label,
            ),
            const SizedBox(height: 6),
            _PlaceLine(Icons.location_on, Colors.red, trip.destination.label),
            if (trip.driver != null || _live?.driverProfile != null) ...[
              const SizedBox(height: 8),
              _liveDriverCard(trip),
            ],
            if (_live?.eta != null && !trip.terminal) ...[
              const SizedBox(height: 8),
              _liveEtaCard(trip),
            ],
            const SizedBox(height: 9),
            Row(
              children: [
                Expanded(
                  child: _MiniCard(
                    trip.status == 'completed' ? 'کرایه نهایی' : 'کرایه',
                    money(trip.finalFare ?? trip.estimatedFare),
                    Icons.payments_rounded,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _MiniCard(
                    'وضعیت',
                    trip.statusFa,
                    Icons.timeline_rounded,
                  ),
                ),
              ],
            ),
            if (canChange) ...[
              const SizedBox(height: 7),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: _changeDestination,
                  icon: const Icon(Icons.edit_location_alt),
                  label: const Text('تغییر مقصد / بازنگری کرایه'),
                ),
              ),
            ],
            if (canCancel)
              Center(
                child: TextButton(
                  onPressed: _cancel,
                  child: const Text('لغو سفر'),
                ),
              ),
            if (trip.status == 'completed') ...[
              const SizedBox(height: 7),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      onPressed: _rate,
                      style: FilledButton.styleFrom(
                        backgroundColor: _yellow,
                        foregroundColor: _black,
                      ),
                      child: const Text('امتیاز'),
                    ),
                  ),
                  const SizedBox(width: 7),
                  Expanded(
                    child: FilledButton(
                      onPressed: _reset,
                      style: FilledButton.styleFrom(backgroundColor: _black),
                      child: const Text('سفر جدید'),
                    ),
                  ),
                ],
              ),
            ] else if (trip.terminal)
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: _reset,
                  style: FilledButton.styleFrom(backgroundColor: _black),
                  child: const Text('سفر جدید'),
                ),
              ),
          ],
        ),
      ),
    );
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
  final TextEditingController _controller = TextEditingController();
  Timer? _timer;
  bool _loading = false;
  String? _searchError;
  List<PlaceResult> _items = const [];

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _changed(String value) {
    _timer?.cancel();
    _timer = Timer(const Duration(milliseconds: 420), () async {
      final q = value.trim();
      if (q.length < 2) {
        if (mounted)
          setState(() {
            _items = const [];
            _searchError = null;
          });
        return;
      }
      setState(() {
        _loading = true;
        _searchError = null;
      });
      try {
        final list = await widget.platform.searchPlaces(q, widget.near);
        if (mounted) {
          setState(() {
            _items = list;
            _searchError = list.isEmpty
                ? 'نتیجه‌ای در اطراف بانه پیدا نشد.'
                : null;
          });
        }
      } catch (e) {
        if (mounted) {
          setState(() {
            _items = const [];
            _searchError = widget.platform.messageFromError(e);
          });
        }
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
        height: MediaQuery.of(context).size.height * .72,
        child: Column(
          children: [
            const Text(
              'جستجوی مکان در بانه',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _controller,
              autofocus: true,
              onChanged: _changed,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'هتل، بیمارستان، پاساژ، خیابان…',
                border: OutlineInputBorder(),
              ),
            ),
            if (_loading) const LinearProgressIndicator(),
            if (_searchError != null)
              Container(
                width: double.infinity,
                margin: const EdgeInsets.only(top: 10),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF2F2),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(
                  children: [
                    const Icon(
                      Icons.info_outline_rounded,
                      color: Colors.redAccent,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _searchError!,
                        style: const TextStyle(fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 8),
            Expanded(
              child: ListView.builder(
                itemCount: _items.length,
                itemBuilder: (_, i) {
                  final p = _items[i];
                  return ListTile(
                    leading: const Icon(Icons.place_rounded, color: Colors.red),
                    title: Text(
                      p.title,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
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

class _RideOptionsSheet extends StatefulWidget {
  const _RideOptionsSheet({required this.initial, required this.addStop});
  final RideOptions initial;
  final Future<void> Function() addStop;

  @override
  State<_RideOptionsSheet> createState() => _RideOptionsSheetState();
}

class _RideOptionsSheetState extends State<_RideOptionsSheet> {
  late bool _silent = widget.initial.silentTrip;
  late String _payment = widget.initial.paymentMethod;
  late String _service = widget.initial.serviceType;
  late DateTime? _scheduled = widget.initial.scheduledAt;
  late List<TripStopDraft> _stops = [...widget.initial.stops];
  late final TextEditingController _note = TextEditingController(
    text: widget.initial.pickupNote,
  );

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _schedule() async {
    final minutes = await showModalBottomSheet<int>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const ListTile(
                title: Text(
                  'رزرو سفر',
                  style: TextStyle(fontWeight: FontWeight.w900),
                ),
              ),
              ListTile(
                title: const Text('۳۰ دقیقه دیگر'),
                onTap: () => Navigator.pop(ctx, 30),
              ),
              ListTile(
                title: const Text('۱ ساعت دیگر'),
                onTap: () => Navigator.pop(ctx, 60),
              ),
              ListTile(
                title: const Text('۲ ساعت دیگر'),
                onTap: () => Navigator.pop(ctx, 120),
              ),
              ListTile(
                title: const Text('سفر همین حالا'),
                onTap: () => Navigator.pop(ctx, 0),
              ),
            ],
          ),
        ),
      ),
    );
    if (minutes != null)
      setState(
        () => _scheduled = minutes == 0
            ? null
            : DateTime.now().toUtc().add(Duration(minutes: minutes)),
      );
  }

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: TextDirection.rtl,
    child: Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom + 16,
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'گزینه‌های سفر',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 21),
            ),
            SwitchListTile(
              value: _silent,
              onChanged: (v) => setState(() => _silent = v),
              title: const Text('سفر در سکوت'),
            ),
            TextField(
              controller: _note,
              maxLength: 500,
              decoration: const InputDecoration(
                labelText: 'توضیح محل سوارشدن',
                hintText: 'مثلاً ورودی اصلی هتل…',
              ),
            ),
            const Text(
              'روش پرداخت',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
            DropdownButtonFormField<String>(
              initialValue: _payment,
              items: const [
                DropdownMenuItem(value: 'cash', child: Text('نقدی')),
                DropdownMenuItem(value: 'wallet', child: Text('کیف پول')),
                DropdownMenuItem(value: 'online', child: Text('آنلاین')),
                DropdownMenuItem(value: 'corporate', child: Text('سازمانی')),
              ],
              onChanged: (v) => setState(() => _payment = v ?? 'cash'),
            ),
            const SizedBox(height: 10),
            const Text(
              'نوع سرویس',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
            DropdownButtonFormField<String>(
              initialValue: _service,
              items: const [
                DropdownMenuItem(value: 'economy', child: Text('اقتصادی')),
                DropdownMenuItem(value: 'special', child: Text('ویژه')),
              ],
              onChanged: (v) => setState(() => _service = v ?? 'economy'),
            ),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.schedule),
              title: Text(
                _scheduled == null ? 'سفر همین حالا' : 'سفر زمان‌بندی‌شده',
              ),
              onTap: _schedule,
            ),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.add_location_alt),
              title: const Text('افزودن توقف / مقصد دوم'),
              subtitle: Text('${_stops.length} توقف'),
              onTap: widget.addStop,
            ),
            ..._stops.asMap().entries.map(
              (e) => ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                title: Text(e.value.label),
                trailing: IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => setState(() => _stops.removeAt(e.key)),
                ),
              ),
            ),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                style: FilledButton.styleFrom(backgroundColor: _black),
                onPressed: () => Navigator.pop(
                  context,
                  RideOptions(
                    pickupNote: _note.text.trim(),
                    silentTrip: _silent,
                    paymentMethod: _payment,
                    serviceType: _service,
                    promoCode: widget.initial.promoCode,
                    scheduledAt: _scheduled,
                    stops: _stops,
                  ),
                ),
                child: const Text('ثبت گزینه‌ها'),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _PlaceLine extends StatelessWidget {
  const _PlaceLine(this.icon, this.color, this.text, {this.onTap});
  final IconData icon;
  final Color color;
  final String text;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => InkWell(
    onTap: onTap,
    borderRadius: BorderRadius.circular(16),
    child: Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 10),
      decoration: BoxDecoration(
        color: const Color(0xFFF7F7F7),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Icon(icon, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
            ),
          ),
          if (onTap != null) const Icon(Icons.chevron_left_rounded, size: 18),
        ],
      ),
    ),
  );
}

class _MiniCard extends StatelessWidget {
  const _MiniCard(this.label, this.value, this.icon);
  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(11),
    decoration: BoxDecoration(
      color: const Color(0xFFFFF7DA),
      borderRadius: BorderRadius.circular(15),
    ),
    child: Row(
      children: [
        Icon(icon, size: 19),
        const SizedBox(width: 7),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: const TextStyle(fontSize: 9, color: Colors.black54),
              ),
              Text(
                value,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontWeight: FontWeight.w900,
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class _SelectionPin extends StatelessWidget {
  const _SelectionPin({required this.destination});
  final bool destination;

  @override
  Widget build(BuildContext context) {
    final color = destination ? Colors.red : Colors.green;
    final icon = destination
        ? Icons.location_on_rounded
        : Icons.radio_button_checked_rounded;
    final label = destination ? 'مقصد' : 'مبدا';
    return Transform.translate(
      offset: const Offset(0, -38),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
            decoration: BoxDecoration(
              color: _black,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(
              label,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 10,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
          const SizedBox(height: 4),
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              border: Border.all(color: color, width: 5),
              boxShadow: const [
                BoxShadow(
                  color: Colors.black26,
                  blurRadius: 9,
                  offset: Offset(0, 4),
                ),
              ],
            ),
            child: Icon(icon, color: color, size: destination ? 30 : 27),
          ),
          Container(width: 4, height: 18, color: color),
          Container(
            width: 10,
            height: 10,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
        ],
      ),
    );
  }
}
