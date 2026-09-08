from pathlib import Path
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly 1 match, got {count}")
    return text.replace(old, new, 1)


def regex_once(text: str, pattern: str, replacement: str, label: str) -> str:
    out, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly 1 match, got {count}")
    return out


passenger = Path("apps/passenger/lib/runtime_passenger.dart")
s = passenger.read_text()

# 1) Keep the confirmed origin visible when switching to destination selection.
s = replace_once(
    s,
    """        await _calculate();
      }
      await _syncMapOverlays();
    } catch (e) {""",
    """        await _calculate();
      }
      await _syncMapOverlays();
      if (_step == 1 && _destination == null && _origin != null) {
        await _revealOriginForDestinationSelection();
      }
    } catch (e) {""",
    "origin reveal after map selection",
)

s = replace_once(
    s,
    """  Future<void> _calculate() async {""",
    """  Future<void> _revealOriginForDestinationSelection() async {
    final origin = _origin;
    if (!_mapReady || origin == null || _destination != null) return;
    try {
      await _map.ready.timeout(const Duration(seconds: 3));
      final zoom = (await _map.getCurrentZoom()) ?? 16.0;
      final targetZoom = zoom.clamp(15.0, 17.0).toDouble();
      // Move the camera slightly south. The saved origin then stays visibly
      // above the center destination selector instead of being hidden under it.
      _map.moveToLocation(
        origin.latitude - .0017,
        origin.longitude,
        zoom: targetZoom,
      );
    } catch (_) {}
  }

  Future<void> _calculate() async {""",
    "origin reveal helper",
)

s = replace_once(
    s,
    """      if (_mapReady) _map.moveToLocation(selected.lat, selected.lng, zoom: 16);
      await _syncMapOverlays();
    }
  }

  Future<void> _requestTrip() async {""",
    """      if (_mapReady) _map.moveToLocation(selected.lat, selected.lng, zoom: 16);
      await _syncMapOverlays();
      await _revealOriginForDestinationSelection();
    }
  }

  Future<void> _requestTrip() async {""",
    "origin reveal after place search",
)

# 2) Give the route substantially more visual clearance above the booking card.
s = replace_once(
    s,
    """      final visualLat =
          ((north + south) / 2) - max((north - south) * .18, .0007);
      final visualLng = (east + west) / 2;""",
    """      final cameraShift = min(
        max((north - south) * .34, .0023),
        .0062,
      );
      final visualLat = ((north + south) / 2) - cameraShift;
      final visualLng = (east + west) / 2;""",
    "route camera clearance",
)

# 3) Notify the passenger immediately after a request has been registered.
s = replace_once(
    s,
    """      await _poll();
      _show('درخواست برای رانندگان نزدیک ارسال شد.');""",
    """      await _poll();
      try {
        await _notifications.show(
          title: 'درخواست سفر ثبت شد',
          body: 'درخواست شما برای رانندگان نزدیک RADO ارسال شد.',
          payload: trip.id,
        );
      } catch (_) {}
      _show('درخواست برای رانندگان نزدیک ارسال شد.');""",
    "passenger request notification",
)

# 4) Use the real Neshan route from the moving driver more frequently for ETA.
s = replace_once(
    s,
    """        now.difference(_lastDriverRouteAt!).inSeconds < 15 &&""",
    """        now.difference(_lastDriverRouteAt!).inSeconds < 9 &&""",
    "driver route cadence",
)

# 5) Frame driver + current target without forcing the user to stay locked to it.
anchor = """  List<NeshanMarker> _mapMarkers() => ["""
helper = """  Future<void> _fitLiveDriver(RideTrip trip) async {
    final pos = _live?.driverPosition;
    if (!_mapReady || pos == null || trip.terminal) return;
    final target = trip.status == 'in_progress'
        ? trip.destination.point
        : trip.pickup.point;
    final north = max(pos.lat, target.latitude);
    final south = min(pos.lat, target.latitude);
    final east = max(pos.lng, target.longitude);
    final west = min(pos.lng, target.longitude);
    final latSpan = north - south;
    final lngSpan = east - west;
    try {
      await _map.ready.timeout(const Duration(seconds: 3));
      _map.fitBounds(
        north + max(latSpan * .48, .0019),
        south - max(latSpan * .48, .0019),
        east + max(lngSpan * .35, .0015),
        west - max(lngSpan * .35, .0015),
      );
      await Future<void>.delayed(const Duration(milliseconds: 140));
      final zoom = (await _map.getCurrentZoom()) ?? 16.0;
      final shift = min(max(latSpan * .30, .0018), .0048);
      _map.moveToLocation(
        ((north + south) / 2) - shift,
        (east + west) / 2,
        zoom: zoom,
      );
    } catch (_) {}
  }

  List<NeshanMarker> _mapMarkers() => ["""
if s.count(anchor) != 1:
    raise SystemExit(f"live driver frame anchor: expected 1 match, got {s.count(anchor)}")
s = s.replace(anchor, helper, 1)

# Frame the live scene only on lifecycle changes; live GPS itself remains smooth
# and does not fight with manual panning.
s = replace_once(
    s,
    """      if (oldStatus != fresh.status) await _notifyTripTransition(fresh);
      await _updateDriverRoute(fresh);""",
    """      if (oldStatus != fresh.status) {
        await _notifyTripTransition(fresh);
        if (['driver_assigned', 'driver_arriving', 'arrived', 'in_progress']
            .contains(fresh.status)) {
          unawaited(_fitLiveDriver(fresh));
        }
      }
      await _updateDriverRoute(fresh);""",
    "frame driver on lifecycle change",
)

# 6) Stronger visual presence for the moving driver.
s = replace_once(
    s,
    """    if (_destination != null)
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
  ];""",
    """    if (_destination != null)
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
    if (_live?.driverPosition != null)
      NeshanCircle(
        id: 'live-driver-halo',
        center: _live!.driverPosition!.point,
        radius: 38,
        fillColor: _yellow,
        fillOpacity: .18,
        strokeColor: _yellow,
        strokeWidth: 3,
        strokeOpacity: .95,
      ),
  ];""",
    "driver live halo",
)

# The passenger trip route is the Neshan suggested route: make it unmistakable.
s = replace_once(
    s,
    """        id: 'route-main',
        coordinates: _route!.points,
        color: _yellow,
        width: 5,
        opacity: 1,""",
    """        id: 'route-main',
        coordinates: _route!.points,
        color: const Color(0xFF1565C0),
        width: 6.5,
        opacity: 1,""",
    "Neshan suggested route styling",
)

# Driver-to-target live route gets the RADO brand color.
s = replace_once(
    s,
    """        id: 'driver-route-live',
        coordinates: _driverRoute!.points,
        color: Colors.blue,
        width: 4.5,
        opacity: .95,""",
    """        id: 'driver-route-live',
        coordinates: _driverRoute!.points,
        color: _yellow,
        width: 5.5,
        opacity: 1,""",
    "driver live route styling",
)

# 7) User-controlled recenter button for the live driver scene.
s = replace_once(
    s,
    """            Positioned(top: 12, left: 12, right: 12, child: _header()),
            Positioned(
              left: 12,""",
    """            Positioned(top: 12, left: 12, right: 12, child: _header()),
            if (_trip != null && _live?.driverPosition != null)
              Positioned(
                top: 94,
                left: 16,
                child: Material(
                  elevation: 7,
                  color: Colors.white,
                  shape: const CircleBorder(),
                  child: IconButton(
                    tooltip: 'نمایش راننده روی نقشه',
                    onPressed: () => _fitLiveDriver(_trip!),
                    icon: const Icon(Icons.my_location_rounded),
                  ),
                ),
              ),
            Positioned(
              left: 12,""",
    "live driver recenter button",
)

# 8) Explain to the user what the blue geometry represents.
s = replace_once(
    s,
    """              Row(
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
            ],""",
    """              Row(
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
              const SizedBox(height: 5),
              const Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    Icons.alt_route_rounded,
                    size: 15,
                    color: Color(0xFF1565C0),
                  ),
                  SizedBox(width: 5),
                  Flexible(
                    child: Text(
                      'مسیر پیشنهادی نشان با خط آبی روی نقشه نمایش داده شده است.',
                      style: TextStyle(fontSize: 10, color: Colors.black54),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              ),
            ],""",
    "route legend",
)

# 9) ETA should prefer the real Neshan route from driver to pickup/destination.
s = regex_once(
    s,
    r"  Widget _liveEtaCard\(RideTrip trip\) \{.*?\n  \}\n\n  Widget _trackingCard",
    """  Widget _liveEtaCard(RideTrip trip) {
    final eta = _live?.eta;
    final route = _driverRoute;
    final minutes = _driverRouteEtaMinutes ?? eta?.minutes;
    final approaching = trip.status != 'in_progress';
    final title = approaching ? 'رسیدن راننده' : 'زمان تا مقصد';
    final value = trip.status == 'arrived'
        ? 'راننده رسیده است'
        : minutes == null
        ? 'در حال محاسبه مسیر زنده…'
        : 'حدود $minutes دقیقه';
    final distance = route?.distanceLabel ?? eta?.distanceLabel ?? 'مسیر زنده';
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
            distance,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }

  Widget _trackingCard""",
    "route based ETA card",
)

s = replace_once(
    s,
    """            if (_live?.eta != null && !trip.terminal) ...[""",
    """            if ((_live?.eta != null || _driverRoute != null) && !trip.terminal) ...[""",
    "ETA visibility",
)

# 10) Make the driver card visibly live and show telemetry when available.
s = replace_once(
    s,
    """          if (rating != null)
            Container(""",
    """          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
                decoration: BoxDecoration(
                  color: const Color(0xFFE8F7ED),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.circle, color: Colors.green, size: 8),
                    SizedBox(width: 4),
                    Text(
                      'زنده',
                      style: TextStyle(fontSize: 9, fontWeight: FontWeight.w900),
                    ),
                  ],
                ),
              ),
              if (_live?.driverPosition?.speedKph != null)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    '${_live!.driverPosition!.speedKph!.round()} km/h',
                    style: const TextStyle(fontSize: 9, color: Colors.black54),
                  ),
                ),
            ],
          ),
          if (rating != null) ...[
            const SizedBox(width: 6),
            Container(""",
    "driver live telemetry opening",
)

# Close the spread list created above after the rating container.
s = replace_once(
    s,
    """                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _liveEtaCard""",
    """                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _liveEtaCard""",
    "driver live telemetry closing",
)

passenger.write_text(s)

# Driver-side lifecycle notifications.
driver = Path("apps/driver/lib/advanced_driver.dart")
t = driver.read_text()

t = replace_once(
    t,
    """      _show(accept ? 'سفر پذیرفته شد.' : 'درخواست رد شد.');
      await _refresh();""",
    """      _show(accept ? 'سفر پذیرفته شد.' : 'درخواست رد شد.');
      if (accept && trip != null) {
        try {
          await _notifications.show(
            title: 'سفر پذیرفته شد',
            body: 'مسیر رسیدن به مسافر فعال شد.',
            payload: trip.id,
          );
        } catch (_) {}
      }
      await _refresh();""",
    "driver accept notification",
)

t = replace_once(
    t,
    """      } else if (action == 'start') {
        _show('سفر شروع شد.');
      }
      await _refresh(all: action == 'complete');""",
    """      } else if (action == 'start') {
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
      await _refresh(all: action == 'complete');""",
    "driver lifecycle notifications",
)

driver.write_text(t)

# Admin TV: some Neshan SDK builds do not export NavigationControl as a class.
admin = Path("deploy/cpanel/admin/live-map.php")
u = admin.read_text()
u = replace_once(
    u,
    """    map.addControl(new maplibregl.NavigationControl({showCompass:true,showZoom:true}),'top-left');""",
    """    if(typeof maplibregl.NavigationControl==='function'){
      map.addControl(new maplibregl.NavigationControl({showCompass:true,showZoom:true}),'top-left');
    }""",
    "optional NavigationControl",
)
admin.write_text(u)

print("RADO passenger/live hotfix applied")
