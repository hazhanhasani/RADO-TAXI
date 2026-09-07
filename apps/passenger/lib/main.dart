import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';
import 'package:neshan_maps_flutter/map.dart';

void main() => runApp(const RadoPassengerApp());

const _brandYellow = Color(0xFFF7B500);
const _brandBlack = Color(0xFF171717);
const _banehCenter = LatLng(35.9968, 45.8853);
const _neshanMapKey = String.fromEnvironment('NESHAN_MAP_KEY');
const _apiBaseUrl = String.fromEnvironment('RADO_API_BASE_URL');

class RadoPassengerApp extends StatelessWidget {
  const RadoPassengerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'RADO',
      locale: const Locale('fa'),
      theme: ThemeData(
        useMaterial3: true,
        scaffoldBackgroundColor: const Color(0xFFF6F6F4),
        colorScheme: ColorScheme.fromSeed(
          seedColor: _brandYellow,
          primary: _brandBlack,
          brightness: Brightness.light,
        ),
      ),
      home: const PassengerMapPage(),
    );
  }
}

class PassengerMapPage extends StatefulWidget {
  const PassengerMapPage({super.key});

  @override
  State<PassengerMapPage> createState() => _PassengerMapPageState();
}

class _PassengerMapPageState extends State<PassengerMapPage> {
  final _controller = NeshanMapController();
  final _api = RadoMapsApi();

  LatLng _mapCenter = _banehCenter;
  LatLng? _origin;
  LatLng? _destination;
  String _centerAddress = 'نقشه را جابه‌جا کنید';
  String _originLabel = 'مبدا را انتخاب کنید';
  String _destinationLabel = 'مقصد را انتخاب کنید';
  bool _reverseLoading = false;
  bool _routeLoading = false;
  RouteSummary? _route;
  int _selectionStep = 0;

  bool get _hasMapKey => _neshanMapKey.trim().isNotEmpty;

  Future<void> _resolveCenterAddress() async {
    if (!_api.enabled) {
      setState(() {
        _centerAddress =
            '${_mapCenter.latitude.toStringAsFixed(5)}, ${_mapCenter.longitude.toStringAsFixed(5)}';
      });
      return;
    }

    setState(() => _reverseLoading = true);
    try {
      final address = await _api.reverse(_mapCenter);
      if (!mounted) return;
      setState(() => _centerAddress = address);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _centerAddress =
            '${_mapCenter.latitude.toStringAsFixed(5)}, ${_mapCenter.longitude.toStringAsFixed(5)}';
      });
    } finally {
      if (mounted) setState(() => _reverseLoading = false);
    }
  }

  Future<void> _selectCurrentPoint() async {
    await _resolveCenterAddress();
    if (!mounted) return;

    if (_selectionStep == 0) {
      setState(() {
        _origin = _mapCenter;
        _originLabel = _centerAddress;
        _selectionStep = 1;
        _destination = null;
        _route = null;
      });
    } else {
      setState(() {
        _destination = _mapCenter;
        _destinationLabel = _centerAddress;
      });
      await _calculateRoute();
    }
  }

  Future<void> _calculateRoute() async {
    final origin = _origin;
    final destination = _destination;
    if (origin == null || destination == null) return;

    setState(() => _routeLoading = true);
    try {
      final route = _api.enabled
          ? await _api.route(origin, destination)
          : RouteSummary.fallback(origin, destination);
      if (!mounted) return;
      setState(() => _route = route);
    } catch (_) {
      if (!mounted) return;
      setState(() => _route = RouteSummary.fallback(origin, destination));
    } finally {
      if (mounted) setState(() => _routeLoading = false);
    }
  }

  void _resetTrip() {
    setState(() {
      _origin = null;
      _destination = null;
      _route = null;
      _selectionStep = 0;
      _originLabel = 'مبدا را انتخاب کنید';
      _destinationLabel = 'مقصد را انتخاب کنید';
      _centerAddress = 'نقشه را جابه‌جا کنید';
    });
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        body: SafeArea(
          child: Stack(
            children: [
              Positioned.fill(child: _buildMap()),
              const Positioned(
                top: 142,
                left: 0,
                right: 0,
                child: IgnorePointer(child: _CenterPin()),
              ),
              Positioned(top: 12, left: 12, right: 12, child: _buildHeader()),
              Positioned(left: 12, right: 12, bottom: 14, child: _buildTripCard()),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildMap() {
    if (!_hasMapKey) {
      return Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color(0xFFE8E7E2), Color(0xFFF7F6F2)],
          ),
        ),
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.map_outlined, size: 74, color: _brandBlack),
                const SizedBox(height: 16),
                const Text(
                  'نقشه RADO آماده است',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 22),
                ),
                const SizedBox(height: 8),
                Text(
                  'برای نمایش نقشه نشان، Secret با نام NESHAN_MAP_KEY را به GitHub Actions اضافه کنید.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey.shade700, height: 1.6),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return NeshanMap(
      mapKey: _neshanMapKey,
      controller: _controller,
      config: const NeshanMapConfig(
        initialCenter: _banehCenter,
        initialZoom: 15,
        mapType: NeshanMapType.neshanVector,
        showTraffic: true,
        showPoi: true,
        showCurrentLocationButton: true,
      ),
      markers: [
        if (_origin != null)
          NeshanMarker(
            id: 'origin',
            position: _origin!,
            color: Colors.green,
            title: 'مبدا',
          ),
        if (_destination != null)
          NeshanMarker(
            id: 'destination',
            position: _destination!,
            color: Colors.red,
            title: 'مقصد',
          ),
      ],
      onLocationChanged: (lat, lng) {
        _mapCenter = LatLng(lat, lng);
      },
      onError: (message, exception, stackTrace) {
        debugPrint('Neshan map error: $message');
      },
      onLocationError: (message, exception, stackTrace) {
        debugPrint('Neshan location error: $message');
      },
    );
  }

  Widget _buildHeader() {
    return Material(
      elevation: 7,
      borderRadius: BorderRadius.circular(24),
      color: Colors.white.withValues(alpha: 0.96),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: _brandBlack,
                borderRadius: BorderRadius.circular(16),
              ),
              alignment: Alignment.center,
              child: const Text(
                'R',
                style: TextStyle(
                  color: _brandYellow,
                  fontWeight: FontWeight.w900,
                  fontSize: 28,
                ),
              ),
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'RADO',
                    style: TextStyle(fontSize: 21, fontWeight: FontWeight.w900),
                  ),
                  Text('تاکسی اینترنتی بانه', style: TextStyle(fontSize: 12)),
                ],
              ),
            ),
            IconButton(
              tooltip: 'شروع مجدد',
              onPressed: _resetTrip,
              icon: const Icon(Icons.refresh_rounded),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTripCard() {
    final isOrigin = _selectionStep == 0;
    return Material(
      elevation: 14,
      borderRadius: BorderRadius.circular(28),
      color: Colors.white,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 44,
              height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(20),
              ),
            ),
            const SizedBox(height: 14),
            _PointRow(
              icon: Icons.radio_button_checked_rounded,
              iconColor: Colors.green,
              label: _originLabel,
              active: isOrigin,
            ),
            const SizedBox(height: 10),
            _PointRow(
              icon: Icons.location_on_rounded,
              iconColor: Colors.red,
              label: _destinationLabel,
              active: !isOrigin,
            ),
            if (_route != null) ...[
              const SizedBox(height: 14),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF7DA),
                  borderRadius: BorderRadius.circular(18),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: _Metric(
                        title: 'مسافت',
                        value: _route!.distanceLabel,
                        icon: Icons.route_rounded,
                      ),
                    ),
                    Container(width: 1, height: 38, color: Colors.black12),
                    Expanded(
                      child: _Metric(
                        title: 'زمان تقریبی',
                        value: _route!.durationLabel,
                        icon: Icons.schedule_rounded,
                      ),
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              height: 54,
              child: FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: _brandBlack,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(18),
                  ),
                ),
                onPressed: _routeLoading ? null : _selectCurrentPoint,
                child: _routeLoading || _reverseLoading
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(
                        isOrigin
                            ? 'تأیید مبدا'
                            : _destination == null
                                ? 'تأیید مقصد'
                                : 'به‌روزرسانی مقصد',
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
              ),
            ),
            if (!_api.enabled) ...[
              const SizedBox(height: 8),
              Text(
                'حالت پیش‌نمایش: Route/Address پس از تنظیم RADO_API_BASE_URL از Backend خوانده می‌شود.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 10.5, color: Colors.grey.shade600),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _CenterPin extends StatelessWidget {
  const _CenterPin();

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(0, -24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(
              color: _brandYellow,
              shape: BoxShape.circle,
              border: Border.all(color: _brandBlack, width: 4),
              boxShadow: const [
                BoxShadow(color: Colors.black26, blurRadius: 10, offset: Offset(0, 6)),
              ],
            ),
            child: const Icon(Icons.local_taxi_rounded, color: _brandBlack, size: 28),
          ),
          Container(width: 4, height: 20, color: _brandBlack),
          Container(
            width: 12,
            height: 5,
            decoration: BoxDecoration(
              color: Colors.black26,
              borderRadius: BorderRadius.circular(99),
            ),
          ),
        ],
      ),
    );
  }
}

class _PointRow extends StatelessWidget {
  const _PointRow({
    required this.icon,
    required this.iconColor,
    required this.label,
    required this.active,
  });

  final IconData icon;
  final Color iconColor;
  final String label;
  final bool active;

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 180),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
      decoration: BoxDecoration(
        color: active ? const Color(0xFFFFFAE9) : const Color(0xFFF7F7F7),
        borderRadius: BorderRadius.circular(17),
        border: Border.all(
          color: active ? _brandYellow : Colors.transparent,
          width: 1.5,
        ),
      ),
      child: Row(
        children: [
          Icon(icon, color: iconColor, size: 22),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              label,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontWeight: active ? FontWeight.w800 : FontWeight.w600,
                fontSize: 13,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.title, required this.value, required this.icon});

  final String title;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Icon(icon, size: 21),
        const SizedBox(width: 7),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: const TextStyle(fontSize: 10)),
            Text(value, style: const TextStyle(fontWeight: FontWeight.w900)),
          ],
        ),
      ],
    );
  }
}

class RadoMapsApi {
  RadoMapsApi()
      : _dio = Dio(
          BaseOptions(
            baseUrl: _apiBaseUrl,
            connectTimeout: const Duration(seconds: 8),
            receiveTimeout: const Duration(seconds: 10),
          ),
        );

  final Dio _dio;

  bool get enabled => _apiBaseUrl.trim().isNotEmpty;

  Future<String> reverse(LatLng point) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/api/v1/maps/reverse',
      queryParameters: {'lat': point.latitude, 'lng': point.longitude},
    );
    return (response.data?['formatted_address'] ?? 'موقعیت انتخاب‌شده').toString();
  }

  Future<RouteSummary> route(LatLng origin, LatLng destination) async {
    final response = await _dio.get<Map<String, dynamic>>(
      '/api/v1/maps/route',
      queryParameters: {
        'origin': '${origin.latitude},${origin.longitude}',
        'destination': '${destination.latitude},${destination.longitude}',
      },
    );
    final data = response.data ?? const <String, dynamic>{};
    return RouteSummary(
      distanceMeters: (data['distance_meters'] as num?)?.toDouble() ?? 0,
      durationSeconds: (data['duration_seconds'] as num?)?.toDouble() ?? 0,
      source: 'neshan',
    );
  }
}

class RouteSummary {
  const RouteSummary({
    required this.distanceMeters,
    required this.durationSeconds,
    required this.source,
  });

  final double distanceMeters;
  final double durationSeconds;
  final String source;

  factory RouteSummary.fallback(LatLng origin, LatLng destination) {
    const distance = Distance();
    final meters = distance.as(LengthUnit.Meter, origin, destination);
    final seconds = (meters / 1000) / 28 * 3600;
    return RouteSummary(
      distanceMeters: meters,
      durationSeconds: seconds,
      source: 'preview',
    );
  }

  String get distanceLabel {
    if (distanceMeters < 1000) return '${distanceMeters.round()} متر';
    return '${(distanceMeters / 1000).toStringAsFixed(1)} کیلومتر';
  }

  String get durationLabel {
    final minutes = (durationSeconds / 60).round().clamp(1, 999);
    return '$minutes دقیقه';
  }
}
