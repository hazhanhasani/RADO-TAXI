from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly one match, found {count}')
    return text.replace(old, new, 1)


# Fix the integer backoff type in both shared-hosting SSE clients.
for rel in [
    'apps/passenger/lib/realtime_stream.dart',
    'apps/driver/lib/realtime_stream.dart',
]:
    p = Path(rel)
    s = p.read_text()
    s = replace_once(
        s,
        "      backoffSeconds = (backoffSeconds * 2).clamp(1, 8);",
        "      backoffSeconds = (backoffSeconds * 2).clamp(1, 8).toInt();",
        f'{rel} integer backoff',
    )
    p.write_text(s)


# Passenger: event-driven trip lifecycle + direct driver GPS samples.
p = Path('apps/passenger/lib/runtime_passenger.dart')
s = p.read_text()
s = replace_once(
    s,
    "import 'live_trip.dart';\nimport 'platform_features.dart';",
    "import 'live_trip.dart';\nimport 'platform_features.dart';\nimport 'realtime_stream.dart';",
    'passenger realtime import',
)
s = replace_once(
    s,
    "  DateTime? _lastDriverRouteAt;",
    "  DateTime? _lastDriverRouteAt;\n  RadoRealtimeStream? _tripStream;",
    'passenger realtime field',
)
s = replace_once(
    s,
    "  void dispose() {\n    _poller?.cancel();\n    _map.dispose();",
    "  void dispose() {\n    _poller?.cancel();\n    _tripStream?.close();\n    _map.dispose();",
    'passenger dispose stream',
)
s = replace_once(
    s,
    "      _poller?.cancel();\n      _poller = Timer.periodic(const Duration(seconds: 3), (_) => _poll());\n      await _syncMapOverlays();\n      await _poll();",
    "      _poller?.cancel();\n      _startTripRealtime(trip.id);\n      _poller = Timer.periodic(const Duration(seconds: 20), (_) => _poll());\n      await _syncMapOverlays();\n      await _poll();",
    'passenger stream start',
)
passenger_stream_methods = r'''  void _startTripRealtime(String tripId) {
    final id = _clientId;
    if (id == null) return;
    _tripStream?.close();
    final stream = RadoRealtimeStream(_apiBase);
    _tripStream = stream;
    unawaited(
      stream.listen(
        query: {
          'role': 'passenger',
          'client_id': id,
          'trip_id': tripId,
        },
        onEvent: (event) async {
          if (!mounted || _trip?.id != tripId) return;
          if (event.name != 'rado_event') return;
          final type = (event.data['event_type'] ?? '').toString();
          final rawPayload = event.data['payload'];
          final payload = rawPayload is Map
              ? rawPayload.cast<String, dynamic>()
              : const <String, dynamic>{};
          if (type == 'driver_location' &&
              payload['lat'] is num &&
              payload['lng'] is num) {
            final current = _live;
            if (current != null) {
              final created = event.data['created_at'];
              final createdAt = created is Map
                  ? (created['jalali'] ?? '').toString()
                  : '';
              setState(() {
                _live = LiveTripSnapshot(
                  status: current.status,
                  driverPosition: LiveDriverPosition(
                    lat: (payload['lat'] as num).toDouble(),
                    lng: (payload['lng'] as num).toDouble(),
                    heading: (payload['heading'] as num?)?.toInt(),
                    speedKph: (payload['speed_kph'] as num?)?.toDouble(),
                    updatedAt: createdAt,
                  ),
                  eta: current.eta,
                  driverProfile: current.driverProfile,
                  events: current.events,
                );
              });
              final trip = _trip;
              if (trip != null) await _updateDriverRoute(trip);
              await _syncMapOverlays();
              return;
            }
          }
          await _poll();
        },
      ),
    );
  }

'''
s = replace_once(
    s,
    "  Future<void> _poll() async {",
    passenger_stream_methods + "  Future<void> _poll() async {",
    'passenger stream methods',
)
s = replace_once(
    s,
    "      if (fresh.terminal) _poller?.cancel();",
    "      if (fresh.terminal) {\n        _poller?.cancel();\n        _tripStream?.close();\n        _tripStream = null;\n      }",
    'passenger terminal stream close',
)
s = replace_once(
    s,
    "      setState(() => _trip = fresh);\n      _poller?.cancel();\n    } catch (e) {",
    "      setState(() => _trip = fresh);\n      _poller?.cancel();\n      _tripStream?.close();\n      _tripStream = null;\n    } catch (e) {",
    'passenger cancel stream close',
)
s = replace_once(
    s,
    "  void _reset() {\n    _poller?.cancel();\n    setState(() {",
    "  void _reset() {\n    _poller?.cancel();\n    _tripStream?.close();\n    _tripStream = null;\n    setState(() {",
    'passenger reset stream close',
)
p.write_text(s)


# Driver: private offer stream + per-trip stream, adaptive GPS cadence, explicit modes.
p = Path('apps/driver/lib/advanced_driver.dart')
s = p.read_text()
s = replace_once(
    s,
    "import 'app_notifications.dart';\nimport 'driver_platform.dart';",
    "import 'app_notifications.dart';\nimport 'driver_platform.dart';\nimport 'realtime_stream.dart';",
    'driver realtime import',
)
s = replace_once(
    s,
    "const _surface = Color(0xFFF6F6F4);",
    "const _surface = Color(0xFFF6F6F4);\nconst _apiBase = String.fromEnvironment(\n  'RADO_API_BASE_URL',\n  defaultValue: 'https://rado-taxi.sbs',\n);",
    'driver api base',
)
s = replace_once(
    s,
    "  String? _lastNotifiedTripStatus;",
    "  String? _lastNotifiedTripStatus;\n  bool _refreshing = false;\n  DateTime? _lastFallbackRefresh;\n  RadoRealtimeStream? _driverStream;\n  RadoRealtimeStream? _tripStream;\n  String? _streamTripId;",
    'driver realtime fields',
)
s = replace_once(
    s,
    "  void dispose() {\n    _timer?.cancel();\n    super.dispose();",
    "  void dispose() {\n    _timer?.cancel();\n    _driverStream?.close();\n    _tripStream?.close();\n    super.dispose();",
    'driver dispose streams',
)
s = replace_once(
    s,
    "      await _refresh(all: true);\n      _timer = Timer.periodic(const Duration(seconds: 5), (_) => _tick());",
    "      await _refresh(all: true);\n      _startDriverRealtime();\n      _scheduleTick();",
    'driver bootstrap realtime',
)
driver_realtime_methods = r'''  void _scheduleTick() {
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
        query: {
          'role': 'driver',
          'client_id': id,
          'trip_id': tripId,
        },
        onEvent: (event) async {
          if (!mounted || _streamTripId != tripId) return;
          if (event.name != 'rado_event') return;
          final type = (event.data['event_type'] ?? '').toString();
          if (type == 'driver_location') return;
          await _refresh(all: type == 'completed');
        },
      ),
    );
  }

'''
s = replace_once(
    s,
    "  Future<bool> _locationPermission() async {",
    driver_realtime_methods + "  Future<bool> _locationPermission() async {",
    'driver realtime methods',
)
s = replace_once(
    s,
    "        final p = await Geolocator.getCurrentPosition(\n          locationSettings: const LocationSettings(\n            accuracy: LocationAccuracy.high,\n            distanceFilter: 10,\n          ),\n        );",
    "        final p = await Geolocator.getCurrentPosition(\n          locationSettings: LocationSettings(\n            accuracy: LocationAccuracy.high,\n            distanceFilter: _trip == null ? 12 : 3,\n          ),\n        );",
    'driver adaptive gps filter',
)
s = replace_once(
    s,
    "      await _refresh();\n    } catch (_) {\n      // Temporary location/network failures must not close the driver app.",
    "      final now = DateTime.now();\n      if (_lastFallbackRefresh == null ||\n          now.difference(_lastFallbackRefresh!).inSeconds >= 20) {\n        _lastFallbackRefresh = now;\n        await _refresh();\n      }\n    } catch (_) {\n      // Temporary location/network failures must not close the driver app.",
    'driver slow polling fallback',
)
s = replace_once(
    s,
    "  Future<void> _refresh({bool all = false}) async {\n    final id = _clientId;\n    if (id == null) return;\n    try {",
    "  Future<void> _refresh({bool all = false}) async {\n    final id = _clientId;\n    if (id == null || _refreshing) return;\n    _refreshing = true;\n    try {",
    'driver refresh guard start',
)
s = replace_once(
    s,
    "      });\n      final unread = serverNotifications",
    "      });\n      _syncTripRealtime(incomingTrip);\n      final unread = serverNotifications",
    'driver sync trip stream',
)
s = replace_once(
    s,
    "    } catch (e) {\n      if (all) _show(_api.message(e));\n    }\n  }\n\n  Future<void> _toggleOnline() async {",
    "    } catch (e) {\n      if (all) _show(_api.message(e));\n    } finally {\n      _refreshing = false;\n    }\n  }\n\n  Future<void> _toggleOnline() async {",
    'driver refresh guard finish',
)
old_mode = r'''                  SwitchListTile(
                    value: auto,
                    onChanged: (v) => setLocal(() => auto = v),
                    title: const Text('پذیرش خودکار'),
                  ),
'''
new_mode = r'''                  const SizedBox(height: 14),
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
                      style: const TextStyle(fontSize: 12, color: Colors.black54),
                    ),
                  ),
'''
s = replace_once(s, old_mode, new_mode, 'driver explicit auto/manual selector')
p.write_text(s)


# Auto/manual operator assignments must wake the driver's private live stream too.
p = Path('deploy/cpanel/rado-system/lib/platform.php')
s = p.read_text()
old_notify = "function rado_platform_notify(PDO $pdo,string $userId,string $title,string $body,string $type='general',array $data=[]):void{\n  try{$pdo->prepare('INSERT INTO notifications(user_id,title,body,type,data_json) VALUES(?,?,?,?,?)')->execute([$userId,$title,$body,$type,$data?json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);}catch(Throwable){}\n}"
new_notify = "function rado_platform_notify(PDO $pdo,string $userId,string $title,string $body,string $type='general',array $data=[]):void{\n  try{$pdo->prepare('INSERT INTO notifications(user_id,title,body,type,data_json) VALUES(?,?,?,?,?)')->execute([$userId,$title,$body,$type,$data?json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);}catch(Throwable){}\n  // Wake a driver's private realtime stream for server-side assignments. Publishing\n  // to a driver:* channel is harmless for passenger IDs because nobody can subscribe\n  // to that channel without a valid driver identity.\n  if($type==='trip_assigned'){rado_platform_event($pdo,'driver:'.$userId,'trip_assigned',$data,600);}\n}"
s = replace_once(s, old_notify, new_notify, 'driver assignment wake event')
p.write_text(s)

print('Realtime dispatch sources patched successfully')
