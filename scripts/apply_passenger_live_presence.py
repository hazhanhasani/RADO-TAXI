from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly one match, found {count}')
    return text.replace(old, new, 1)


# Passenger app: publish precise position only while the trip needs pickup tracking.
p = Path('apps/passenger/lib/runtime_passenger.dart')
s = p.read_text()
s = replace_once(
    s,
    "import 'package:flutter/services.dart';\nimport 'package:latlong2/latlong.dart';",
    "import 'package:flutter/services.dart';\nimport 'package:geolocator/geolocator.dart';\nimport 'package:latlong2/latlong.dart';",
    'passenger geolocator import',
)
s = replace_once(
    s,
    "  RadoRealtimeStream? _tripStream;",
    "  RadoRealtimeStream? _tripStream;\n  StreamSubscription<Position>? _passengerPositionSub;\n  Timer? _passengerPresenceHeartbeat;\n  Position? _lastPassengerPosition;\n  DateTime? _lastPassengerPresenceAt;",
    'passenger presence fields',
)
s = replace_once(
    s,
    "    _poller?.cancel();\n    _tripStream?.close();\n    _map.dispose();",
    "    _poller?.cancel();\n    _tripStream?.close();\n    _passengerPresenceHeartbeat?.cancel();\n    _passengerPositionSub?.cancel();\n    _map.dispose();",
    'passenger dispose presence',
)
s = replace_once(
    s,
    "      _startTripRealtime(trip.id);\n      _poller = Timer.periodic(const Duration(seconds: 20), (_) => _poll());",
    "      _startTripRealtime(trip.id);\n      await _startPassengerPresence(trip.id);\n      _poller = Timer.periodic(const Duration(seconds: 20), (_) => _poll());",
    'passenger start presence',
)
methods = r'''  Future<void> _startPassengerPresence(String tripId) async {
    await _stopPassengerPresence();
    final id = _clientId;
    if (id == null) return;
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return;
      }
      final first = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
        ),
      );
      _lastPassengerPosition = first;
      await _publishPassengerPresence(tripId, first, force: true);
      _passengerPositionSub = Geolocator.getPositionStream(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          distanceFilter: 4,
        ),
      ).listen((position) {
        _lastPassengerPosition = position;
        unawaited(_publishPassengerPresence(tripId, position));
      });
      _passengerPresenceHeartbeat = Timer.periodic(
        const Duration(seconds: 20),
        (_) {
          final position = _lastPassengerPosition;
          if (position != null) {
            unawaited(
              _publishPassengerPresence(tripId, position, force: true),
            );
          }
        },
      );
    } catch (_) {
      // Live passenger presence is additive; booking must keep working without it.
    }
  }

  Future<void> _publishPassengerPresence(
    String tripId,
    Position position, {
    bool force = false,
  }) async {
    final id = _clientId;
    final trip = _trip;
    if (id == null || trip == null || trip.id != tripId || trip.terminal) return;
    if (trip.status == 'in_progress') return;
    final now = DateTime.now();
    if (!force &&
        _lastPassengerPresenceAt != null &&
        now.difference(_lastPassengerPresenceAt!).inMilliseconds < 1800) {
      return;
    }
    _lastPassengerPresenceAt = now;
    try {
      await _realtime.publishPassengerLocation(
        clientId: id,
        tripId: tripId,
        lat: position.latitude,
        lng: position.longitude,
        accuracyMeters: position.accuracy,
        heading: position.heading >= 0 ? position.heading : null,
        speedKph: position.speed >= 0 ? position.speed * 3.6 : null,
      );
    } catch (_) {}
  }

  Future<void> _stopPassengerPresence() async {
    _passengerPresenceHeartbeat?.cancel();
    _passengerPresenceHeartbeat = null;
    await _passengerPositionSub?.cancel();
    _passengerPositionSub = null;
    _lastPassengerPosition = null;
    _lastPassengerPresenceAt = null;
  }

'''
s = replace_once(
    s,
    "  void _startTripRealtime(String tripId) {",
    methods + "  void _startTripRealtime(String tripId) {",
    'passenger presence methods',
)
s = replace_once(
    s,
    "          final type = (event.data['event_type'] ?? '').toString();\n          final rawPayload = event.data['payload'];",
    "          final type = (event.data['event_type'] ?? '').toString();\n          if (type == 'passenger_location') return;\n          final rawPayload = event.data['payload'];",
    'passenger ignore own location event',
)
s = replace_once(
    s,
    "      if (fresh.terminal) {\n        _poller?.cancel();\n        _tripStream?.close();\n        _tripStream = null;\n      }",
    "      if (fresh.terminal || fresh.status == 'in_progress') {\n        await _stopPassengerPresence();\n      }\n      if (fresh.terminal) {\n        _poller?.cancel();\n        _tripStream?.close();\n        _tripStream = null;\n      }",
    'passenger stop presence lifecycle',
)
s = replace_once(
    s,
    "      _poller?.cancel();\n      _tripStream?.close();\n      _tripStream = null;\n    } catch (e) {",
    "      _poller?.cancel();\n      _tripStream?.close();\n      _tripStream = null;\n      await _stopPassengerPresence();\n    } catch (e) {",
    'passenger cancel presence',
)
s = replace_once(
    s,
    "  void _reset() {\n    _poller?.cancel();\n    _tripStream?.close();",
    "  void _reset() {\n    _poller?.cancel();\n    unawaited(_stopPassengerPresence());\n    _tripStream?.close();",
    'passenger reset presence',
)
p.write_text(s)


# Driver receives passenger-location events on its trip channel, but does not need
# to refresh the full driver state for every GPS sample.
p = Path('apps/driver/lib/advanced_driver.dart')
s = p.read_text()
s = replace_once(
    s,
    "          if (type == 'driver_location') return;",
    "          if (type == 'driver_location' || type == 'passenger_location') return;",
    'driver ignore live location samples',
)
p.write_text(s)


# Admin TV map: include latest passenger location in snapshots and move it directly
# from passenger_location SSE events, without triggering a full snapshot per GPS sample.
p = Path('deploy/cpanel/admin/live-map.php')
s = p.read_text()
s = replace_once(
    s,
    "        pu.full_name passenger_name,pu.phone passenger_phone,\n        du.full_name driver_name,du.phone driver_phone\n        FROM trips t\n        LEFT JOIN users pu ON pu.id=t.passenger_id\n        LEFT JOIN users du ON du.id=t.driver_id",
    "        pu.full_name passenger_name,pu.phone passenger_phone,\n        du.full_name driver_name,du.phone driver_phone,\n        pp.latitude passenger_lat,pp.longitude passenger_lng,pp.accuracy_m passenger_accuracy_m,pp.last_seen_at passenger_last_seen_at\n        FROM trips t\n        LEFT JOIN users pu ON pu.id=t.passenger_id\n        LEFT JOIN users du ON du.id=t.driver_id\n        LEFT JOIN passenger_presence pp ON pp.trip_id=t.id AND pp.passenger_id=t.passenger_id AND pp.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)",
    'admin passenger presence query',
)
s = replace_once(
    s,
    "        $t['destination_lat']=(float)$t['destination_lat'];$t['destination_lng']=(float)$t['destination_lng'];\n        $t['estimated_fare']=(int)($t['estimated_fare']??0);",
    "        $t['destination_lat']=(float)$t['destination_lat'];$t['destination_lng']=(float)$t['destination_lng'];\n        $t['passenger_lat']=$t['passenger_lat']===null?null:(float)$t['passenger_lat'];\n        $t['passenger_lng']=$t['passenger_lng']===null?null:(float)$t['passenger_lng'];\n        $t['passenger_accuracy_m']=$t['passenger_accuracy_m']===null?null:(float)$t['passenger_accuracy_m'];\n        $t['passenger_last_seen_at_jalali']=$t['passenger_last_seen_at']?rado_jalali_datetime((string)$t['passenger_last_seen_at']):null;\n        unset($t['passenger_last_seen_at']);\n        $t['estimated_fare']=(int)($t['estimated_fare']??0);",
    'admin passenger presence casting',
)
s = replace_once(
    s,
    ".request-pin.active{background:#1565c0}.dest-pin{",
    ".request-pin.active{background:#1565c0}.passenger-live{width:32px;height:32px;display:grid;place-items:center;border-radius:50%;border:3px solid #fff;background:#ff8a00;box-shadow:0 3px 12px #0005;font-size:16px}.dest-pin{",
    'admin passenger marker css',
)
s = replace_once(
    s,
    "const driverMarkers=new Map();\nconst tripLayers=new Map();",
    "const driverMarkers=new Map();\nconst passengerMarkers=new Map();\nconst tripLayers=new Map();",
    'admin passenger marker map',
)
passenger_js = r'''function passengerIcon(){return L.divIcon({className:'',iconSize:[36,36],iconAnchor:[18,18],html:'<div class="passenger-live">👤</div>'});}
function passengerPopup(t){return '<b>'+esc(t.passenger_name||'مسافر RADO')+'</b><br>'+esc(t.passenger_phone||'')+'<br>موقعیت زنده مسافر<br>دقت GPS: '+esc(t.passenger_accuracy_m==null?'—':Math.round(t.passenger_accuracy_m)+' متر')+'<br>آخرین بروزرسانی: '+esc(t.passenger_last_seen_at_jalali||'همین حالا');}
function upsertPassenger(t,lat,lng,animate=true){const id=String(t.id);let m=passengerMarkers.get(id);if(!m){m=L.marker([Number(lat),Number(lng)],{icon:passengerIcon(),zIndexOffset:900}).addTo(map);passengerMarkers.set(id,m);}else{if(animate)smoothMove(m,lat,lng,null);else m.setLatLng([Number(lat),Number(lng)]);}m.bindPopup(passengerPopup(t));}
'''
s = replace_once(
    s,
    "function tripPopup(t){",
    passenger_js + "function tripPopup(t){",
    'admin passenger marker functions',
)
old_render = "function renderSnapshot(d){snapshotState={drivers:d.drivers||[],trips:d.trips||[]};const driverIds=new Set(snapshotState.drivers.map(x=>String(x.user_id)));for(const [id,m] of driverMarkers){if(!driverIds.has(id)){map.removeLayer(m);driverMarkers.delete(id);}}for(const x of snapshotState.drivers)upsertDriver(x,true);const tripIds=new Set(snapshotState.trips.map(x=>String(x.id)));for(const [id,g] of tripLayers){if(!tripIds.has(id)){map.removeLayer(g);tripLayers.delete(id);}}for(const t of snapshotState.trips)upsertTrip(t);updateCounters();document.getElementById('liveTime').textContent=d.time||'';}"
new_render = "function renderSnapshot(d){snapshotState={drivers:d.drivers||[],trips:d.trips||[]};const driverIds=new Set(snapshotState.drivers.map(x=>String(x.user_id)));for(const [id,m] of driverMarkers){if(!driverIds.has(id)){map.removeLayer(m);driverMarkers.delete(id);}}for(const x of snapshotState.drivers)upsertDriver(x,true);const tripIds=new Set(snapshotState.trips.map(x=>String(x.id)));const passengerIds=new Set();for(const [id,g] of tripLayers){if(!tripIds.has(id)){map.removeLayer(g);tripLayers.delete(id);}}for(const t of snapshotState.trips){upsertTrip(t);if(t.passenger_lat!=null&&t.passenger_lng!=null){passengerIds.add(String(t.id));upsertPassenger(t,t.passenger_lat,t.passenger_lng,true);}}for(const [id,m] of passengerMarkers){if(!passengerIds.has(id)){map.removeLayer(m);passengerMarkers.delete(id);}}updateCounters();document.getElementById('liveTime').textContent=d.time||'';}"
s = replace_once(s, old_render, new_render, 'admin render passenger snapshot')
apply_passenger = r'''function applyPassengerPresence(evt){const channel=String(evt.channel||'');const tripId=channel.startsWith('trip:')?channel.slice(5):'';const p=evt.payload||{};if(!tripId||p.lat==null||p.lng==null)return;const t=snapshotState.trips.find(x=>String(x.id)===tripId);if(!t)return;t.passenger_lat=Number(p.lat);t.passenger_lng=Number(p.lng);t.passenger_accuracy_m=p.accuracy_m==null?null:Number(p.accuracy_m);t.passenger_last_seen_at_jalali='همین حالا';upsertPassenger(t,t.passenger_lat,t.passenger_lng,true);}
'''
s = replace_once(
    s,
    "function connectStream(){",
    apply_passenger + "function connectStream(){",
    'admin passenger event handler',
)
s = replace_once(
    s,
    "if(x.event_type==='presence'&&String(x.channel||'').startsWith('driver:')){applyPresence(x);}else{scheduleSnapshot(80);}",
    "if(x.event_type==='presence'&&String(x.channel||'').startsWith('driver:')){applyPresence(x);}else if(x.event_type==='passenger_location'){applyPassengerPresence(x);}else if(x.event_type==='driver_location'){}else{scheduleSnapshot(80);}",
    'admin direct location event routing',
)
s = replace_once(
    s,
    "    <div class=\"legend-row\"><span class=\"legend-swatch\" style=\"background:#e53935\"></span>درخواست منتظر</div>",
    "    <div class=\"legend-row\"><span class=\"legend-swatch\" style=\"background:#e53935\"></span>درخواست منتظر</div>\n    <div class=\"legend-row\"><span class=\"legend-swatch\" style=\"background:#ff8a00\"></span>موقعیت زنده مسافر</div>",
    'admin passenger legend',
)
p.write_text(s)

print('Passenger live presence sources patched successfully')
