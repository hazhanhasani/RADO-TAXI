from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly one match, found {count}')
    return text.replace(old, new, 1)


# Passenger runtime map + live tracking wiring.
p = Path('apps/passenger/lib/runtime_passenger.dart')
s = p.read_text()

s = replace_once(
    s,
    "import 'advanced_passenger.dart' show FareInfo, RideApi, RideRoute, RideTrip, TripPoint, money;\nimport 'platform_features.dart';",
    "import 'advanced_passenger.dart' show FareInfo, RideApi, RideRoute, RideTrip, TripPoint, money;\nimport 'app_notifications.dart';\nimport 'live_trip.dart';\nimport 'platform_features.dart';",
    'passenger imports',
)

s = replace_once(
    s,
    "  final PassengerPlatformApi _platform = PassengerPlatformApi(_apiBase);\n  final Dio _configApi = Dio(BaseOptions(",
    "  final PassengerPlatformApi _platform = PassengerPlatformApi(_apiBase);\n  final PassengerRealtimeApi _realtime = PassengerRealtimeApi(_apiBase);\n  final RadoNotifications _notifications = RadoNotifications.instance;\n  final Dio _configApi = Dio(BaseOptions(",
    'passenger api fields',
)

s = replace_once(
    s,
    "  int _step = 0;\n  Timer? _poller;",
    "  int _step = 0;\n  Timer? _poller;\n  LiveTripSnapshot? _live;\n  RideRoute? _driverRoute;\n  int? _driverRouteEtaMinutes;\n  int _lastEventId = 0;\n  bool _polling = false;\n  DateTime? _lastDriverRouteAt;",
    'passenger live fields',
)

s = replace_once(
    s,
    "    if (mounted) setState(() => _clientId = id);\n    await _loadMapConfig();",
    "    if (mounted) setState(() => _clientId = id);\n    try { await _notifications.init(); } catch (_) {}\n    await _loadMapConfig();",
    'passenger notification bootstrap',
)

s = replace_once(
    s,
    "      setState(() {\n        if (current != null) _center = current;\n        _mapReady = true;\n        _mapMessage = '';\n      });",
    "      setState(() {\n        if (current != null) _center = current;\n        _mapReady = true;\n        _mapMessage = '';\n      });\n      await _syncMapOverlays();",
    'passenger map ready sync',
)

s = replace_once(
    s,
    "        await _calculate();\n      }\n    } catch (e) {",
    "        await _calculate();\n      }\n      await _syncMapOverlays();\n    } catch (e) {",
    'passenger point overlay sync',
)

s = replace_once(
    s,
    "      setState(() {\n        _route = route;\n        _fare = fare;\n      });\n      await _fitRoute(route);",
    "      setState(() {\n        _route = route;\n        _fare = fare;\n      });\n      await _syncMapOverlays();\n      await _fitRoute(route);",
    'passenger route overlay sync',
)

s = replace_once(
    s,
    "    final latPad = max((north - south) * .16, .0012);\n    final lngPad = max((east - west) * .16, .0012);\n    try {\n      await _map.ready.timeout(const Duration(seconds: 3));\n      _map.fitBounds(north + latPad, south - latPad, east + lngPad, west - lngPad);\n    } catch (_) {}",
    "    final latPad = max((north - south) * .34, .0020);\n    final lngPad = max((east - west) * .24, .0016);\n    try {\n      await _map.ready.timeout(const Duration(seconds: 3));\n      _map.fitBounds(north + latPad, south - latPad, east + lngPad, west - lngPad);\n      await Future<void>.delayed(const Duration(milliseconds: 160));\n      final zoom = await _map.getCurrentZoom();\n      // The booking/tracking card covers the lower part of the screen. Shift the\n      // camera slightly south so both endpoints and the full route stay above it.\n      final visualLat = ((north + south) / 2) - max((north - south) * .18, .0007);\n      final visualLng = (east + west) / 2;\n      _map.moveToLocation(visualLat, visualLng, zoom: zoom);\n    } catch (_) {}",
    'passenger route fit padding',
)

s = replace_once(
    s,
    "      if (_mapReady) _map.moveToLocation(selected.lat, selected.lng, zoom: 16);\n    }\n  }\n\n  Future<void> _requestTrip() async {",
    "      if (_mapReady) _map.moveToLocation(selected.lat, selected.lng, zoom: 16);\n      await _syncMapOverlays();\n    }\n  }\n\n  Future<void> _requestTrip() async {",
    'passenger searched origin sync',
)

s = replace_once(
    s,
    "      if (!mounted) return;\n      setState(() => _trip = trip);\n      _poller?.cancel();\n      _poller = Timer.periodic(const Duration(seconds: 4), (_) => _poll());\n      await _poll();",
    "      if (!mounted) return;\n      setState(() {\n        _trip = trip;\n        _live = null;\n        _driverRoute = null;\n        _driverRouteEtaMinutes = null;\n        _lastEventId = 0;\n        _lastDriverRouteAt = null;\n      });\n      _poller?.cancel();\n      _poller = Timer.periodic(const Duration(seconds: 3), (_) => _poll());\n      await _syncMapOverlays();\n      await _poll();",
    'passenger live poll start',
)

old_poll = """  Future<void> _poll() async {
    final id = _clientId, trip = _trip;
    if (id == null || trip == null) return;
    try {
      final fresh = await _ride.tripStatus(id, trip.id);
      if (!mounted) return;
      setState(() => _trip = fresh);
      if (fresh.terminal) _poller?.cancel();
    } catch (_) {}
  }
"""
new_poll = """  Future<void> _poll() async {
    final id = _clientId, trip = _trip;
    if (id == null || trip == null || _polling) return;
    _polling = true;
    try {
      final oldStatus = trip.status;
      final fresh = await _ride.tripStatus(id, trip.id);
      LiveTripSnapshot? live;
      try {
        live = await _realtime.snapshot(clientId: id, tripId: trip.id, sinceEventId: _lastEventId);
      } catch (_) {}
      if (!mounted) return;
      setState(() {
        _trip = fresh;
        if (live != null) {
          _live = live;
          if (live.events.isNotEmpty) _lastEventId = max(_lastEventId, live.lastEventId);
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
        body = trip.driver == null ? 'راننده RADO در مسیر مبدا است.' : '${trip.driver!.name} در مسیر مبدا است.';
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
      try { await _notifications.show(title: title, body: body, payload: trip.id); } catch (_) {}
    }
  }

  Future<void> _updateDriverRoute(RideTrip trip) async {
    final pos = _live?.driverPosition;
    if (pos == null || trip.terminal) {
      if (mounted && trip.terminal) setState(() { _driverRoute = null; _driverRouteEtaMinutes = null; });
      return;
    }
    if (!['driver_assigned', 'driver_arriving', 'arrived', 'in_progress'].contains(trip.status)) return;
    final now = DateTime.now();
    if (_lastDriverRouteAt != null && now.difference(_lastDriverRouteAt!).inSeconds < 15 && _driverRoute != null) return;
    final target = trip.status == 'in_progress' ? trip.destination.point : trip.pickup.point;
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
          NeshanMarker(id: 'origin', position: _origin!, color: Colors.green, title: 'مبدا • $_originLabel'),
        if (_destination != null)
          NeshanMarker(id: 'destination', position: _destination!, color: Colors.red, title: 'مقصد • $_destinationLabel'),
        if (_live?.driverPosition != null)
          NeshanMarker(
            id: 'live-driver',
            position: _live!.driverPosition!.point,
            color: _yellow,
            title: _live?.driverProfile?.name ?? _trip?.driver?.name ?? 'راننده RADO',
          ),
      ];

  List<NeshanCircle> _mapCircles() => [
        if (_origin != null)
          NeshanCircle(
            id: 'origin-halo', center: _origin!, radius: 28,
            fillColor: Colors.green, fillOpacity: .12, strokeColor: Colors.green, strokeWidth: 2.5, strokeOpacity: .9,
          ),
        if (_destination != null)
          NeshanCircle(
            id: 'destination-halo', center: _destination!, radius: 28,
            fillColor: Colors.red, fillOpacity: .10, strokeColor: Colors.red, strokeWidth: 2.5, strokeOpacity: .9,
          ),
      ];

  List<NeshanPolyline> _mapPolylines() => [
        if (_route != null && _route!.points.length >= 2)
          NeshanPolyline(id: 'route-shadow', coordinates: _route!.points, color: _black, width: 8, opacity: .72),
        if (_route != null && _route!.points.length >= 2)
          NeshanPolyline(id: 'route-main', coordinates: _route!.points, color: _yellow, width: 5, opacity: 1),
        if (_driverRoute != null && _driverRoute!.points.length >= 2)
          NeshanPolyline(id: 'driver-route-shadow', coordinates: _driverRoute!.points, color: _black, width: 7, opacity: .55),
        if (_driverRoute != null && _driverRoute!.points.length >= 2)
          NeshanPolyline(id: 'driver-route-live', coordinates: _driverRoute!.points, color: Colors.blue, width: 4.5, opacity: .95),
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
"""
s = replace_once(s, old_poll, new_poll, 'passenger poll/live methods')

s = replace_once(
    s,
    "      _route = null;\n      _fare = null;\n      _trip = null;\n      _options = const RideOptions();\n      _error = null;\n      _step = 0;\n    });",
    "      _route = null;\n      _fare = null;\n      _trip = null;\n      _live = null;\n      _driverRoute = null;\n      _driverRouteEtaMinutes = null;\n      _lastEventId = 0;\n      _lastDriverRouteAt = null;\n      _options = const RideOptions();\n      _error = null;\n      _step = 0;\n    });\n    unawaited(_syncMapOverlays());",
    'passenger reset live state',
)

s = replace_once(
    s,
    "              if (_trip == null && _mapReady)\n                Positioned.fill(",
    "              if (_trip == null && _mapReady && (_origin == null || _destination == null))\n                Positioned.fill(",
    'passenger selection pin visibility',
)

old_overlays = """          markers: [
            if (_origin != null)
              NeshanMarker(id: 'origin', position: _origin!, color: Colors.green, title: 'مبدا • $_originLabel'),
            if (_destination != null)
              NeshanMarker(id: 'destination', position: _destination!, color: Colors.red, title: 'مقصد • $_destinationLabel'),
          ],
          circles: [
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
          ],
          polylines: [
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
          ],
"""
s = replace_once(
    s,
    old_overlays,
    "          markers: _mapMarkers(),\n          circles: _mapCircles(),\n          polylines: _mapPolylines(),\n",
    'passenger declarative overlays',
)

s = replace_once(
    s,
    "              onPressed: _busy ? null : _selectMapPoint,",
    "              onPressed: _busy ? null : (_destination != null ? _beginDestinationReselect : _selectMapPoint),",
    'passenger destination reselect',
)

old_driver_card = """          if (trip.driver != null) ...[
            const SizedBox(height: 8),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: const Color(0xFFF5F5F2), borderRadius: BorderRadius.circular(16)),
              child: Text('${trip.driver!.name} · ${trip.driver!.vehicle} · ${trip.driver!.plate}', style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
          ],
          const SizedBox(height: 9),
"""
new_driver_card = """          if (trip.driver != null || _live?.driverProfile != null) ...[
            const SizedBox(height: 8),
            _liveDriverCard(trip),
          ],
          if (_live?.eta != null && !trip.terminal) ...[
            const SizedBox(height: 8),
            _liveEtaCard(trip),
          ],
          const SizedBox(height: 9),
"""
s = replace_once(s, old_driver_card, new_driver_card, 'passenger driver tracking card')

insert_before = """  Widget _trackingCard(RideTrip trip) {
"""
helpers = """  Widget _liveDriverCard(RideTrip trip) {
    final profile = _live?.driverProfile;
    final name = profile?.name ?? trip.driver?.name ?? 'راننده RADO';
    final vehicle = profile?.vehicle ?? trip.driver?.vehicle ?? '';
    final plate = profile?.plate ?? trip.driver?.plate ?? '';
    final color = profile?.color ?? '';
    final rating = profile?.rating;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: const Color(0xFFF5F5F2), borderRadius: BorderRadius.circular(16)),
      child: Row(children: [
        Container(width: 45, height: 45, decoration: BoxDecoration(color: _yellow, borderRadius: BorderRadius.circular(14)), child: const Icon(Icons.local_taxi_rounded)),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(name, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 14)),
          Text([vehicle, color, plate].where((e) => e.trim().isNotEmpty).join(' · '), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 11, color: Colors.black54)),
        ])),
        if (rating != null)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.star_rounded, color: _yellow, size: 18), Text(rating.toStringAsFixed(1), style: const TextStyle(fontWeight: FontWeight.w900))]),
          ),
      ]),
    );
  }

  Widget _liveEtaCard(RideTrip trip) {
    final eta = _live!.eta!;
    final minutes = _driverRouteEtaMinutes ?? eta.minutes;
    final approaching = trip.status != 'in_progress';
    final title = approaching ? 'رسیدن راننده' : 'زمان تا مقصد';
    final value = trip.status == 'arrived' ? 'راننده رسیده است' : 'حدود $minutes دقیقه';
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(color: const Color(0xFFFFF5CC), borderRadius: BorderRadius.circular(16)),
      child: Row(children: [
        const Icon(Icons.navigation_rounded, color: _black),
        const SizedBox(width: 9),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: const TextStyle(fontSize: 10, color: Colors.black54)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 14)),
        ])),
        Text(eta.distanceLabel, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
      ]),
    );
  }

"""
s = replace_once(s, insert_before, helpers + insert_before, 'passenger live card helpers')

p.write_text(s)


# Driver native notification hooks + faster active tracking.
p = Path('apps/driver/lib/advanced_driver.dart')
s = p.read_text()
s = replace_once(
    s,
    "import 'driver_platform.dart';",
    "import 'app_notifications.dart';\nimport 'driver_platform.dart';",
    'driver notification import',
)
s = replace_once(
    s,
    "  final DriverPlatformApi _api = DriverPlatformApi();\n  int _tab = 0;",
    "  final DriverPlatformApi _api = DriverPlatformApi();\n  final RadoNotifications _notifications = RadoNotifications.instance;\n  int _tab = 0;",
    'driver notifier field',
)
s = replace_once(
    s,
    "  List<DriverSupportTicket> _tickets = const [];\n  Timer? _timer;",
    "  List<DriverSupportTicket> _tickets = const [];\n  Timer? _timer;\n  int? _lastNotifiedOfferId;\n  String? _lastNotifiedTripStatus;",
    'driver notification state',
)
s = replace_once(
    s,
    "      _clientId = id;\n      await _refresh(all: true);\n      _timer = Timer.periodic(const Duration(seconds: 8), (_) => _tick());",
    "      _clientId = id;\n      try { await _notifications.init(); } catch (_) {}\n      await _refresh(all: true);\n      _timer = Timer.periodic(const Duration(seconds: 5), (_) => _tick());",
    'driver faster polling',
)

old_refresh_tail = """      if (!mounted) return;
      setState(() {
        _session = session;
        _online = session.online || _online;
        _wallet = wallet;
        _offer = state.offer;
        _trip = state.activeTrip;
        _prefs = prefs;
        _missions = missions;
        _settlements = settlements;
        _documents = documents;
        _tickets = tickets;
      });
"""
new_refresh_tail = """      if (!mounted) return;
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
      if (incomingOffer != null && incomingOffer.id != _lastNotifiedOfferId) {
        _lastNotifiedOfferId = incomingOffer.id;
        try {
          await _notifications.show(
            title: 'درخواست سفر جدید',
            body: '${incomingOffer.trip.distanceLabel} · ${money(incomingOffer.trip.estimatedFare)}',
            payload: incomingOffer.trip.id,
          );
        } catch (_) {}
      }
      final status = incomingTrip?.status;
      if (status != null && _lastNotifiedTripStatus != null && status != _lastNotifiedTripStatus) {
        String? title;
        String? body;
        if (status == 'cancelled_by_passenger') { title = 'مسافر سفر را لغو کرد'; body = 'این سفر دیگر فعال نیست.'; }
        if (status == 'cancelled_by_admin') { title = 'سفر توسط پشتیبانی لغو شد'; body = 'وضعیت سفر تغییر کرد.'; }
        if (title != null && body != null) {
          try { await _notifications.show(title: title, body: body, payload: incomingTrip!.id); } catch (_) {}
        }
      }
      if (status != null) _lastNotifiedTripStatus = status;
"""
s = replace_once(s, old_refresh_tail, new_refresh_tail, 'driver refresh notifications')
p.write_text(s)


# Backend notifications for assignment/completion.
p = Path('deploy/cpanel/api/v1/driver/offers/index.php')
s = p.read_text()
s = replace_once(
    s,
    "    rado_platform_event($pdo,'trip:'.$tripId,'driver_assigned',['driver_id'=>$driverId,'auto'=>false]);\n    $row = rado_trip_row($pdo, $tripId);",
    "    rado_platform_event($pdo,'trip:'.$tripId,'driver_assigned',['driver_id'=>$driverId,'auto'=>false]);\n    rado_platform_notify($pdo,(string)$trip['passenger_id'],'راننده سفر را پذیرفت','راننده RADO در مسیر مبدا است.','driver_assigned',['trip_id'=>$tripId,'driver_id'=>$driverId]);\n    $row = rado_trip_row($pdo, $tripId);",
    'passenger accepted notification',
)
p.write_text(s)

p = Path('deploy/cpanel/api/v1/driver/trip/index.php')
s = p.read_text()
s = replace_once(
    s,
    "        $finalFare=(int)($trip['estimated_fare']??0);$pdo->prepare(\"UPDATE trips SET status='completed',final_fare=?,completed_at=NOW(),version=version+1 WHERE id=?\")->execute([$finalFare,$tripId]);$trip['final_fare']=$finalFare;$trip['commission_rate']=(float)($driver['commission_rate']??0);$finance=rado_complete_trip_finance($pdo,$trip);rado_sync_wallet_cache($pdo,$driverId);$platformFinance=rado_complete_platform_finance($pdo,$trip);",
    "        $finalFare=(int)($trip['estimated_fare']??0);$pdo->prepare(\"UPDATE trips SET status='completed',final_fare=?,completed_at=NOW(),version=version+1 WHERE id=?\")->execute([$finalFare,$tripId]);$trip['final_fare']=$finalFare;$trip['commission_rate']=(float)($driver['commission_rate']??0);$finance=rado_complete_trip_finance($pdo,$trip);rado_sync_wallet_cache($pdo,$driverId);$platformFinance=rado_complete_platform_finance($pdo,$trip);rado_platform_event($pdo,'trip:'.$tripId,'completed',['driver_id'=>$driverId,'fare'=>$finalFare]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'سفر پایان یافت','سفر RADO پایان یافت. می‌توانید به راننده امتیاز بدهید.','trip_completed',['trip_id'=>$tripId]);",
    'passenger completion notification',
)
p.write_text(s)

# Auto-accept must also notify the passenger.
p = Path('deploy/cpanel/rado-system/lib/platform.php')
s = p.read_text()
s = replace_once(
    s,
    "$tripStmt=$pdo->prepare('SELECT id,status,destination_lat,destination_lng,estimated_fare,requested_at FROM trips WHERE id=? LIMIT 1');",
    "$tripStmt=$pdo->prepare('SELECT id,status,passenger_id,destination_lat,destination_lng,estimated_fare,requested_at FROM trips WHERE id=? LIMIT 1');",
    'auto accept passenger id',
)
s = replace_once(
    s,
    "rado_platform_notify($pdo,$driverId,'سفر خودکار پذیرفته شد','یک سفر مطابق تنظیمات پذیرش خودکار برای شما ثبت شد.','trip_assigned',['trip_id'=>$tripId]);rado_platform_event($pdo,'trip:'.$tripId,'driver_assigned',['driver_id'=>$driverId,'auto'=>true]);",
    "rado_platform_notify($pdo,$driverId,'سفر خودکار پذیرفته شد','یک سفر مطابق تنظیمات پذیرش خودکار برای شما ثبت شد.','trip_assigned',['trip_id'=>$tripId]);rado_platform_notify($pdo,(string)$trip['passenger_id'],'راننده سفر را پذیرفت','راننده RADO در مسیر مبدا است.','driver_assigned',['trip_id'=>$tripId,'driver_id'=>$driverId]);rado_platform_event($pdo,'trip:'.$tripId,'driver_assigned',['driver_id'=>$driverId,'auto'=>true]);",
    'auto accept passenger notification',
)
p.write_text(s)

print('RADO live trip map patch applied')
