#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
runtime = ROOT / 'apps/passenger/lib/runtime_passenger.dart'
advanced = ROOT / 'apps/passenger/lib/advanced_passenger.dart'


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        if new in text:
            return text
        raise SystemExit(f'Patch anchor not found: {label}')
    return text.replace(old, new, 1)


r = runtime.read_text(encoding='utf-8')

# 1) The visual picker must point to the exact map center. Previously it was
# hard-coded at top:150 while reverse-geocoding used the real map centre.
r = replace_once(
    r,
    "              if (_trip == null && _mapReady) const Positioned(top: 150, left: 0, right: 0, child: IgnorePointer(child: _Pin())),",
    """              if (_trip == null && _mapReady)\n                Positioned.fill(\n                  child: IgnorePointer(\n                    child: Align(\n                      alignment: Alignment.center,\n                      child: _SelectionPin(destination: _step > 0),\n                    ),\n                  ),\n                ),""",
    'center picker',
)

# 2) Draw distinct origin/destination overlays and the actual Neshan route.
old_map_overlays = """          markers: [\n            if (_origin != null) NeshanMarker(id: 'origin', position: _origin!, color: Colors.green, title: 'مبدا'),\n            if (_destination != null) NeshanMarker(id: 'destination', position: _destination!, color: Colors.red, title: 'مقصد'),\n          ],"""
new_map_overlays = """          markers: [\n            if (_origin != null)\n              NeshanMarker(id: 'origin', position: _origin!, color: Colors.green, title: 'مبدا • $_originLabel'),\n            if (_destination != null)\n              NeshanMarker(id: 'destination', position: _destination!, color: Colors.red, title: 'مقصد • $_destinationLabel'),\n          ],\n          circles: [\n            if (_origin != null)\n              NeshanCircle(\n                id: 'origin-halo',\n                center: _origin!,\n                radius: 28,\n                fillColor: Colors.green,\n                fillOpacity: .12,\n                strokeColor: Colors.green,\n                strokeWidth: 2.5,\n                strokeOpacity: .9,\n              ),\n            if (_destination != null)\n              NeshanCircle(\n                id: 'destination-halo',\n                center: _destination!,\n                radius: 28,\n                fillColor: Colors.red,\n                fillOpacity: .10,\n                strokeColor: Colors.red,\n                strokeWidth: 2.5,\n                strokeOpacity: .9,\n              ),\n          ],\n          polylines: [\n            if (_route != null && _route!.points.length >= 2)\n              NeshanPolyline(\n                id: 'route-shadow',\n                coordinates: _route!.points,\n                color: _black,\n                width: 8,\n                opacity: .72,\n              ),\n            if (_route != null && _route!.points.length >= 2)\n              NeshanPolyline(\n                id: 'route-main',\n                coordinates: _route!.points,\n                color: _yellow,\n                width: 5,\n                opacity: 1,\n              ),\n          ],"""
r = replace_once(r, old_map_overlays, new_map_overlays, 'map overlays')

# 3) After route calculation, fit both endpoints and the geometry on screen.
old_calc = """      setState(() {\n        _route = route;\n        _fare = fare;\n      });"""
new_calc = """      setState(() {\n        _route = route;\n        _fare = fare;\n      });\n      await _fitRoute(route);"""
r = replace_once(r, old_calc, new_calc, 'route fit call')

anchor = """  Future<void> _searchPlace({required bool destination, bool addStop = false}) async {"""
fit_method = """  Future<void> _fitRoute(RideRoute route) async {\n    if (!_mapReady || route.points.length < 2) return;\n    final points = <LatLng>[...route.points];\n    if (_origin != null) points.add(_origin!);\n    if (_destination != null) points.add(_destination!);\n    var north = points.first.latitude;\n    var south = points.first.latitude;\n    var east = points.first.longitude;\n    var west = points.first.longitude;\n    for (final p in points.skip(1)) {\n      north = max(north, p.latitude);\n      south = min(south, p.latitude);\n      east = max(east, p.longitude);\n      west = min(west, p.longitude);\n    }\n    final latPad = max((north - south) * .16, .0012);\n    final lngPad = max((east - west) * .16, .0012);\n    try {\n      await _map.ready.timeout(const Duration(seconds: 3));\n      _map.fitBounds(north + latPad, south - latPad, east + lngPad, west - lngPad);\n    } catch (_) {}\n  }\n\n"""
if fit_method not in r:
    if anchor not in r:
        raise SystemExit('Patch anchor not found: fit route method')
    r = r.replace(anchor, fit_method + anchor, 1)

# 4) Search errors must be visible instead of silently returning an empty list.
r = replace_once(
    r,
    """  bool _loading = false;\n  List<PlaceResult> _items = const [];""",
    """  bool _loading = false;\n  String? _searchError;\n  List<PlaceResult> _items = const [];""",
    'search error state',
)

old_changed = """  void _changed(String value) {\n    _timer?.cancel();\n    _timer = Timer(const Duration(milliseconds: 420), () async {\n      final q = value.trim();\n      if (q.length < 2) return;\n      setState(() => _loading = true);\n      try {\n        final list = await widget.platform.searchPlaces(q, widget.near);\n        if (mounted) setState(() => _items = list);\n      } catch (_) {\n        if (mounted) setState(() => _items = const []);\n      } finally {\n        if (mounted) setState(() => _loading = false);\n      }\n    });\n  }"""
new_changed = """  void _changed(String value) {\n    _timer?.cancel();\n    _timer = Timer(const Duration(milliseconds: 420), () async {\n      final q = value.trim();\n      if (q.length < 2) {\n        if (mounted) setState(() { _items = const []; _searchError = null; });\n        return;\n      }\n      setState(() { _loading = true; _searchError = null; });\n      try {\n        final list = await widget.platform.searchPlaces(q, widget.near);\n        if (mounted) {\n          setState(() {\n            _items = list;\n            _searchError = list.isEmpty ? 'نتیجه‌ای در اطراف بانه پیدا نشد.' : null;\n          });\n        }\n      } catch (e) {\n        if (mounted) {\n          setState(() {\n            _items = const [];\n            _searchError = widget.platform.messageFromError(e);\n          });\n        }\n      } finally {\n        if (mounted) setState(() => _loading = false);\n      }\n    });\n  }"""
r = replace_once(r, old_changed, new_changed, 'search changed handler')

old_search_ui = """              if (_loading) const LinearProgressIndicator(),\n              const SizedBox(height: 8),\n              Expanded(child: ListView.builder("""
new_search_ui = """              if (_loading) const LinearProgressIndicator(),\n              if (_searchError != null)\n                Container(\n                  width: double.infinity,\n                  margin: const EdgeInsets.only(top: 10),\n                  padding: const EdgeInsets.all(12),\n                  decoration: BoxDecoration(color: const Color(0xFFFFF2F2), borderRadius: BorderRadius.circular(14)),\n                  child: Row(children: [\n                    const Icon(Icons.info_outline_rounded, color: Colors.redAccent),\n                    const SizedBox(width: 8),\n                    Expanded(child: Text(_searchError!, style: const TextStyle(fontSize: 12))),\n                  ]),\n                ),\n              const SizedBox(height: 8),\n              Expanded(child: ListView.builder("""
r = replace_once(r, old_search_ui, new_search_ui, 'search error UI')

# 5) Replace the taxi picker with distinct origin/destination picker designs.
old_pin = """class _Pin extends StatelessWidget {\n  const _Pin();\n\n  @override\n  Widget build(BuildContext context) => Transform.translate(\n        offset: const Offset(0, -24),\n        child: Column(mainAxisSize: MainAxisSize.min, children: [\n          Container(\n            width: 52,\n            height: 52,\n            decoration: BoxDecoration(color: _yellow, shape: BoxShape.circle, border: Border.all(color: _black, width: 4)),\n            child: const Icon(Icons.local_taxi_rounded, size: 28),\n          ),\n          Container(width: 4, height: 20, color: _black),\n        ]),\n      );\n}"""
new_pin = """class _SelectionPin extends StatelessWidget {\n  const _SelectionPin({required this.destination});\n  final bool destination;\n\n  @override\n  Widget build(BuildContext context) {\n    final color = destination ? Colors.red : Colors.green;\n    final icon = destination ? Icons.location_on_rounded : Icons.radio_button_checked_rounded;\n    final label = destination ? 'مقصد' : 'مبدا';\n    return Transform.translate(\n      offset: const Offset(0, -38),\n      child: Column(mainAxisSize: MainAxisSize.min, children: [\n        Container(\n          padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),\n          decoration: BoxDecoration(color: _black, borderRadius: BorderRadius.circular(14)),\n          child: Text(label, style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w900)),\n        ),\n        const SizedBox(height: 4),\n        Container(\n          width: 54,\n          height: 54,\n          decoration: BoxDecoration(\n            color: Colors.white,\n            shape: BoxShape.circle,\n            border: Border.all(color: color, width: 5),\n            boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 9, offset: Offset(0, 4))],\n          ),\n          child: Icon(icon, color: color, size: destination ? 30 : 27),\n        ),\n        Container(width: 4, height: 18, color: color),\n        Container(width: 10, height: 10, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),\n      ]),\n    );\n  }\n}"""
r = replace_once(r, old_pin, new_pin, 'selection pin')

runtime.write_text(r, encoding='utf-8')

# Extend route model/parser shared by RuntimePassengerPage.
a = advanced.read_text(encoding='utf-8')
old_route_parser = """    return RideRoute(distanceMeters: (r.data?['distance_meters'] as num?)?.toDouble() ?? 0, durationSeconds: (r.data?['duration_seconds'] as num?)?.toDouble() ?? 0);"""
new_route_parser = """    final points = ((r.data?['route_points'] as List?) ?? const [])\n        .whereType<Map>()\n        .map((e) => e.cast<String, dynamic>())\n        .map((e) => LatLng(\n              (e['lat'] as num?)?.toDouble() ?? 0,\n              (e['lng'] as num?)?.toDouble() ?? 0,\n            ))\n        .where((p) => p.latitude.abs() <= 90 && p.longitude.abs() <= 180)\n        .toList(growable: false);\n    return RideRoute(\n      distanceMeters: (r.data?['distance_meters'] as num?)?.toDouble() ?? 0,\n      durationSeconds: (r.data?['duration_seconds'] as num?)?.toDouble() ?? 0,\n      points: points.length >= 2 ? points : [a, b],\n    );"""
a = replace_once(a, old_route_parser, new_route_parser, 'RideApi route parser')

old_route_model = """class RideRoute {\n  const RideRoute({required this.distanceMeters, required this.durationSeconds});\n  final double distanceMeters, durationSeconds;"""
new_route_model = """class RideRoute {\n  const RideRoute({required this.distanceMeters, required this.durationSeconds, this.points = const []});\n  final double distanceMeters, durationSeconds;\n  final List<LatLng> points;"""
a = replace_once(a, old_route_model, new_route_model, 'RideRoute geometry')
advanced.write_text(a, encoding='utf-8')

print('Passenger map source patch applied successfully.')
