import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';
import 'package:neshan_maps_flutter/map.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'app_update.dart';

void main() => runApp(
      const RadoUpdateGate(app: 'passenger', child: RadoPassengerApp()),
    );

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
  final _api = RadoApi();

  LatLng _mapCenter = _banehCenter;
  LatLng? _origin;
  LatLng? _destination;
  String _centerAddress = 'نقشه را جابه‌جا کنید';
  String _originLabel = 'مبدا را انتخاب کنید';
  String _destinationLabel = 'مقصد را انتخاب کنید';
  bool _reverseLoading = false;
  bool _routeLoading = false;
  bool _fareLoading = false;
  bool _requestLoading = false;
  RouteSummary? _route;
  FareEstimate? _fare;
  String? _fareError;
  String? _clientId;
  String? _tripId;
  int _selectionStep = 0;

  bool get _hasMapKey => _neshanMapKey.trim().isNotEmpty;

  @override
  void initState() {
    super.initState();
    _prepareClientId();
  }

  Future<void> _prepareClientId() async {
    final prefs = await SharedPreferences.getInstance();
    var id = prefs.getString('rado_passenger_client_id');
    if (id == null || id.trim().isEmpty) {
      id = '${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
      await prefs.setString('rado_passenger_client_id', id);
    }
    if (mounted) setState(() => _clientId = id);
  }

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
        _fare = null;
        _fareError = null;
        _tripId = null;
      });
      return;
    }

    setState(() {
      _destination = _mapCenter;
      _destinationLabel = _centerAddress;
      _fare = null;
      _fareError = null;
      _tripId = null;
    });
    await _calculateRouteAndFare();
  }

  Future<void> _calculateRouteAndFare() async {
    final origin = _origin;
    final destination = _destination;
    if (origin == null || destination == null) return;

    setState(() {
      _routeLoading = true;
      _fareLoading = false;
      _fare = null;
      _fareError = null;
    });

    RouteSummary route;
    try {
      route = _api.enabled
          ? await _api.route(origin, destination)
          : RouteSummary.fallback(origin, destination);
    } catch (_) {
      route = RouteSummary.fallback(origin, destination);
    }

    if (!mounted) return;
    setState(() {
      _route = route;
      _routeLoading = false;
    });

    if (!_api.enabled) {
      setState(() {
        _fareError = 'برای محاسبه کرایه، اتصال اپ به سرور RADO لازم است.';
      });
      return;
    }

    setState(() => _fareLoading = true);
    try {
      final fare = await _api.estimateFare(route);
      if (!mounted) return;
      setState(() => _fare = fare);
    } catch (e) {
      if (!mounted) return;
      setState(() => _fareError = _api.messageFromError(e));
    } finally {
      if (mounted) setState(() => _fareLoading = false);
    }
  }

  Future<void> _requestTrip() async {
    final origin = _origin;
    final destination = _destination;
    final route = _route;
    final fare = _fare;
    final clientId = _clientId;
    if (origin == null || destination == null || route == null || fare == null) {
      return;
    }
    if (clientId == null || clientId.isEmpty) {
      await _prepareClientId();
      return _requestTrip();
    }

    setState(() => _requestLoading = true);
    try {
      final result = await _api.requestTrip(
        clientId: clientId,
        origin: origin,
        destination: destination,
        pickupLabel: _originLabel,
        destinationLabel: _destinationLabel,
        route: route,
      );
      if (!mounted) return;
      setState(() => _tripId = result.id);
      await showModalBottomSheet<void>(
        context: context,
        isDismissible: true,
        showDragHandle: true,
        builder: (context) => Directionality(
          textDirection: TextDirection.rtl,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(22, 8, 22, 30),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 72,
                  height: 72,
                  decoration: const BoxDecoration(
                    color: Color(0xFFFFF0B8),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.local_taxi_rounded, size: 38),
                ),
                const SizedBox(height: 14),
                const Text(
                  'درخواست سفر ثبت شد',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 21),
                ),
                const SizedBox(height: 7),
                Text(
                  result.driversNotified > 0
                      ? 'در حال ارسال درخواست برای راننده‌های نزدیک هستیم.'
                      : 'درخواست ثبت شد؛ فعلاً راننده آنلاین نزدیک پیدا نشد.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey.shade700, height: 1.6),
                ),
                const SizedBox(height: 12),
                Text(
                  'کرایه تخمینی: ${fare.formattedFare}',
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
              ],
            ),
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_api.messageFromError(e))),
      );
    } finally {
      if (mounted) setState(() => _requestLoading = false);
    }
  }

  void _resetTrip() {
    setState(() {
      _origin = null;
      _destination = null;
      _route = null;
      _fare = null;
      _fareError = null;
      _tripId = null;
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
                  'برای نمایش نقشه نشان، NESHAN_MAP_KEY باید در Build تنظیم شود.',
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
      color: Colors.white,
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
    final hasDestination = _destination != null;
    final busy = _routeLoading || _reverseLoading;

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
              active: !isOrigin && !hasDestination,
            ),
            if (_route != null) ...[
              const SizedBox(height: 13),
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
            if (_fareLoading) ...[
              const SizedBox(height: 13),
              const LinearProgressIndicator(minHeight: 3),
              const SizedBox(height: 8),
              const Text('در حال محاسبه کرایه...'),
            ],
            if (_fare != null) ...[
              const SizedBox(height: 13),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(15),
                decoration: BoxDecoration(
                  color: _brandBlack,
                  borderRadius: BorderRadius.circular(18),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.payments_rounded, color: _brandYellow),
                    const SizedBox(width: 10),
                    const Expanded(
                      child: Text(
                        'کرایه تخمینی سفر',
                        style: TextStyle(color: Colors.white70),
                      ),
                    ),
                    Text(
                      _fare!.formattedFare,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 17,
                      ),
                    ),
                  ],
                ),
              ),
            ],
            if (_fareError != null) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF0F0),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Text(
                  _fareError!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Color(0xFF9B2525), fontSize: 12),
                ),
              ),
            ],
            const SizedBox(height: 13),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: hasDestination ? const Color(0xFFEDEDEA) : _brandBlack,
                  foregroundColor: hasDestination ? _brandBlack : Colors.white,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(17),
                  ),
                ),
                onPressed: busy || _requestLoading ? null : _selectCurrentPoint,
                child: busy
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(
                        isOrigin
                            ? 'تأیید مبدا'
                            : hasDestination
                                ? 'تغییر مقصد روی نقشه'
                                : 'تأیید مقصد و محاسبه کرایه',
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
              ),
            ),
            if (_fare != null) ...[
              const SizedBox(height: 9),
              SizedBox(
                width: double.infinity,
                height: 58,
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: _brandYellow,
                    foregroundColor: _brandBlack,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(18),
                    ),
                  ),
                  onPressed: _requestLoading || _tripId != null ? null : _requestTrip,
                  icon: _requestLoading
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.local_taxi_rounded),
                  label: Text(
                    _tripId != null ? 'درخواست ثبت شده' : 'درخواست RADO',
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16),
                  ),
                ),
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

class RadoApi {
  RadoApi()
      : _dio = Dio(
          BaseOptions(
            baseUrl: _apiBaseUrl,
            connectTimeout: const Duration(seconds: 8),
            receiveTimeout: const Duration(seconds: 12),
            headers: const {'Accept': 'application/json'},
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
      source: (data['source'] ?? 'neshan').toString(),
    );
  }

  Future<FareEstimate> estimateFare(RouteSummary route) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/api/v1/fare/estimate',
      data: {
        'distance_meters': route.distanceMeters.round(),
        'duration_seconds': route.durationSeconds.round(),
      },
    );
    final data = response.data ?? const <String, dynamic>{};
    return FareEstimate(
      fare: (data['fare'] as num?)?.toInt() ?? 0,
      currency: (data['currency'] ?? 'IRR').toString(),
    );
  }

  Future<TripRequestResult> requestTrip({
    required String clientId,
    required LatLng origin,
    required LatLng destination,
    required String pickupLabel,
    required String destinationLabel,
    required RouteSummary route,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/api/v1/trips',
      data: {
        'client_id': clientId,
        'pickup': {'lat': origin.latitude, 'lng': origin.longitude},
        'destination': {
          'lat': destination.latitude,
          'lng': destination.longitude,
        },
        'pickup_label': pickupLabel,
        'destination_label': destinationLabel,
        'distance_meters': route.distanceMeters.round(),
        'duration_seconds': route.durationSeconds.round(),
      },
    );
    final trip = (response.data?['trip'] as Map?)?.cast<String, dynamic>() ??
        const <String, dynamic>{};
    return TripRequestResult(
      id: (trip['id'] ?? '').toString(),
      status: (trip['status'] ?? 'searching').toString(),
      driversNotified: (trip['drivers_notified'] as num?)?.toInt() ?? 0,
    );
  }

  String messageFromError(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['message'] != null) {
        return data['message'].toString();
      }
      final code = data is Map ? data['error']?.toString() : null;
      if (code == 'pricing_not_configured') {
        return 'تعرفه سفر هنوز در پنل مدیریت تنظیم نشده است.';
      }
      if (error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.receiveTimeout) {
        return 'ارتباط با سرور رادو طول کشید. دوباره تلاش کنید.';
      }
    }
    return 'امکان انجام این درخواست وجود ندارد. دوباره تلاش کنید.';
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

class FareEstimate {
  const FareEstimate({required this.fare, required this.currency});

  final int fare;
  final String currency;

  String get formattedFare => '${_formatNumber(fare)} ریال';

  static String _formatNumber(int value) {
    final text = value.toString();
    final buffer = StringBuffer();
    for (var i = 0; i < text.length; i++) {
      if (i > 0 && (text.length - i) % 3 == 0) buffer.write(',');
      buffer.write(text[i]);
    }
    return buffer.toString();
  }
}

class TripRequestResult {
  const TripRequestResult({
    required this.id,
    required this.status,
    required this.driversNotified,
  });

  final String id;
  final String status;
  final int driversNotified;
}
