import 'dart:async';
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

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
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
        colorScheme: ColorScheme.fromSeed(seedColor: _yellow, primary: _black),
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
  final _api = PassengerApi();

  LatLng _mapCenter = _banehCenter;
  LatLng? _origin;
  LatLng? _destination;
  String _centerAddress = 'نقشه را جابه‌جا کنید';
  String _originLabel = 'مبدا را انتخاب کنید';
  String _destinationLabel = 'مقصد را انتخاب کنید';
  RouteSummary? _route;
  FareEstimate? _fare;
  String? _fareError;
  String? _clientId;
  PassengerTrip? _activeTrip;
  bool _reverseLoading = false;
  bool _routeLoading = false;
  bool _fareLoading = false;
  bool _requestLoading = false;
  bool _tripActionLoading = false;
  int _selectionStep = 0;
  Timer? _tripPoller;

  bool get _hasMapKey => _neshanMapKey.trim().isNotEmpty;

  @override
  void initState() {
    super.initState();
    _prepareClientId();
  }

  @override
  void dispose() {
    _tripPoller?.cancel();
    super.dispose();
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
      setState(() => _centerAddress = 'موقعیت انتخاب‌شده');
      return;
    }
    setState(() => _reverseLoading = true);
    try {
      final address = await _api.reverse(_mapCenter);
      if (mounted) setState(() => _centerAddress = address);
    } catch (_) {
      if (mounted) setState(() => _centerAddress = 'موقعیت انتخاب‌شده');
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
      });
      return;
    }

    setState(() {
      _destination = _mapCenter;
      _destinationLabel = _centerAddress;
      _fare = null;
      _fareError = null;
    });
    await _calculateRouteAndFare();
  }

  Future<void> _calculateRouteAndFare() async {
    final origin = _origin;
    final destination = _destination;
    if (origin == null || destination == null) return;

    setState(() {
      _routeLoading = true;
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
      setState(() => _fareError = 'برای محاسبه کرایه، اتصال به سرور RADO لازم است.');
      return;
    }

    setState(() => _fareLoading = true);
    try {
      final fare = await _api.estimateFare(route);
      if (mounted) setState(() => _fare = fare);
    } catch (e) {
      if (mounted) setState(() => _fareError = _api.messageFromError(e));
    } finally {
      if (mounted) setState(() => _fareLoading = false);
    }
  }

  Future<void> _requestTrip() async {
    final origin = _origin;
    final destination = _destination;
    final route = _route;
    final fare = _fare;
    var clientId = _clientId;
    if (origin == null || destination == null || route == null || fare == null) return;
    if (clientId == null || clientId.isEmpty) {
      await _prepareClientId();
      clientId = _clientId;
      if (clientId == null) return;
    }

    setState(() => _requestLoading = true);
    try {
      final trip = await _api.requestTrip(
        clientId: clientId,
        origin: origin,
        destination: destination,
        pickupLabel: _originLabel,
        destinationLabel: _destinationLabel,
        route: route,
      );
      if (!mounted) return;
      setState(() => _activeTrip = trip);
      _startTripPolling();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('درخواست سفر ثبت شد و برای راننده‌های نزدیک ارسال شد.')),
      );
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(_api.messageFromError(e))));
    } finally {
      if (mounted) setState(() => _requestLoading = false);
    }
  }

  void _startTripPolling() {
    _tripPoller?.cancel();
    _tripPoller = Timer.periodic(const Duration(seconds: 4), (_) => _pollTrip());
    _pollTrip();
  }

  Future<void> _pollTrip() async {
    final trip = _activeTrip;
    final clientId = _clientId;
    if (trip == null || clientId == null) return;
    try {
      final latest = await _api.tripStatus(clientId, trip.id);
      if (!mounted) return;
      setState(() => _activeTrip = latest);
      if (latest.isTerminal) _tripPoller?.cancel();
    } catch (_) {
      // A temporary polling failure should not erase the active ride.
    }
  }

  Future<void> _cancelTrip() async {
    final trip = _activeTrip;
    final clientId = _clientId;
    if (trip == null || clientId == null || _tripActionLoading) return;
    setState(() => _tripActionLoading = true);
    try {
      final latest = await _api.cancelTrip(clientId, trip.id);
      if (!mounted) return;
      setState(() => _activeTrip = latest);
      _tripPoller?.cancel();
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('سفر لغو شد.')));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(_api.messageFromError(e))));
    } finally {
      if (mounted) setState(() => _tripActionLoading = false);
    }
  }

  void _newTrip() {
    _tripPoller?.cancel();
    setState(() {
      _activeTrip = null;
      _origin = null;
      _destination = null;
      _route = null;
      _fare = null;
      _fareError = null;
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
              if (_activeTrip == null)
                const Positioned(top: 142, left: 0, right: 0, child: IgnorePointer(child: _CenterPin())),
              Positioned(top: 12, left: 12, right: 12, child: _buildHeader()),
              Positioned(
                left: 12,
                right: 12,
                bottom: 14,
                child: _activeTrip == null ? _buildBookingCard() : _buildTrackingCard(_activeTrip!),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildMap() {
    if (!_hasMapKey) {
      return Container(
        color: const Color(0xFFECEBE7),
        child: const Center(child: Icon(Icons.map_outlined, size: 80, color: _black)),
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
        if (_origin != null) NeshanMarker(id: 'origin', position: _origin!, color: Colors.green, title: 'مبدا'),
        if (_destination != null) NeshanMarker(id: 'destination', position: _destination!, color: Colors.red, title: 'مقصد'),
      ],
      onLocationChanged: (lat, lng) => _mapCenter = LatLng(lat, lng),
      onError: (message, exception, stackTrace) => debugPrint('Neshan map error: $message'),
      onLocationError: (message, exception, stackTrace) => debugPrint('Neshan location error: $message'),
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
            ClipRRect(
              borderRadius: BorderRadius.circular(15),
              child: Image.asset('assets/branding/rado-passenger.png', width: 48, height: 48, fit: BoxFit.cover),
            ),
            const SizedBox(width: 12),
            const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('RADO', style: TextStyle(fontSize: 21, fontWeight: FontWeight.w900)), Text('تاکسی اینترنتی بانه', style: TextStyle(fontSize: 12))])),
            IconButton(tooltip: 'سفر جدید', onPressed: _newTrip, icon: const Icon(Icons.refresh_rounded)),
          ],
        ),
      ),
    );
  }

  Widget _buildBookingCard() {
    final isOrigin = _selectionStep == 0;
    final hasDestination = _destination != null;
    final busy = _reverseLoading || _routeLoading || _requestLoading;
    return Material(
      elevation: 14,
      borderRadius: BorderRadius.circular(28),
      color: Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          _PointRow(icon: Icons.radio_button_checked_rounded, color: Colors.green, label: _originLabel, active: isOrigin),
          const SizedBox(height: 9),
          _PointRow(icon: Icons.location_on_rounded, color: Colors.red, label: _destinationLabel, active: !isOrigin && !hasDestination),
          if (_route != null) ...[
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: _InfoBox(label: 'مسافت', value: _route!.distanceLabel)),
              const SizedBox(width: 8),
              Expanded(child: _InfoBox(label: 'زمان تقریبی', value: _route!.durationLabel)),
            ]),
          ],
          if (_fareLoading) ...[const SizedBox(height: 12), const LinearProgressIndicator()],
          if (_fare != null) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(15),
              decoration: BoxDecoration(color: _black, borderRadius: BorderRadius.circular(18)),
              child: Row(children: [const Icon(Icons.payments_rounded, color: _yellow), const SizedBox(width: 10), const Expanded(child: Text('کرایه سفر', style: TextStyle(color: Colors.white70))), Text(_fare!.formattedFare, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 17))]),
            ),
          ],
          if (_fareError != null) ...[
            const SizedBox(height: 10),
            Text(_fareError!, style: const TextStyle(color: Colors.redAccent, fontSize: 12), textAlign: TextAlign.center),
          ],
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            height: 52,
            child: FilledButton(
              onPressed: busy ? null : _selectCurrentPoint,
              style: FilledButton.styleFrom(backgroundColor: _black),
              child: Text(isOrigin ? 'تأیید مبدا' : hasDestination ? 'تغییر مقصد روی نقشه' : 'تأیید مقصد و محاسبه کرایه', style: const TextStyle(fontWeight: FontWeight.w900)),
            ),
          ),
          if (_fare != null) ...[
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              height: 56,
              child: FilledButton.icon(
                onPressed: _requestLoading ? null : _requestTrip,
                style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black),
                icon: const Icon(Icons.local_taxi_rounded),
                label: const Text('درخواست RADO', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              ),
            ),
          ],
        ]),
      ),
    );
  }

  Widget _buildTrackingCard(PassengerTrip trip) {
    final canCancel = ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'arrived'].contains(trip.status);
    return Material(
      elevation: 16,
      borderRadius: BorderRadius.circular(28),
      color: Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Container(width: 46, height: 46, decoration: BoxDecoration(color: const Color(0xFFFFE59A), borderRadius: BorderRadius.circular(15)), child: const Icon(Icons.local_taxi_rounded)),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(trip.statusFa, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)), if (trip.requestedAt.isNotEmpty) Text(trip.requestedAt, style: const TextStyle(color: Colors.black54, fontSize: 11))])),
          ]),
          const SizedBox(height: 14),
          _StatusTimeline(status: trip.status),
          const SizedBox(height: 14),
          _PointRow(icon: Icons.radio_button_checked, color: Colors.green, label: trip.pickup.label, active: false),
          const SizedBox(height: 8),
          _PointRow(icon: Icons.location_on, color: Colors.red, label: trip.destination.label, active: false),
          if (trip.driver != null) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(13),
              decoration: BoxDecoration(color: const Color(0xFFF5F5F2), borderRadius: BorderRadius.circular(17)),
              child: Row(children: [
                const CircleAvatar(backgroundColor: _black, foregroundColor: _yellow, child: Icon(Icons.person_rounded)),
                const SizedBox(width: 10),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(trip.driver!.name, style: const TextStyle(fontWeight: FontWeight.w900)), Text([trip.driver!.vehicle, trip.driver!.plate].where((e) => e.isNotEmpty).join(' · '), style: const TextStyle(fontSize: 11, color: Colors.black54))])),
              ]),
            ),
          ],
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: _InfoBox(label: trip.status == 'completed' ? 'کرایه نهایی' : 'کرایه تخمینی', value: formatMoney(trip.finalFare ?? trip.estimatedFare))),
            const SizedBox(width: 8),
            Expanded(child: _InfoBox(label: 'زمان', value: trip.lastEventTime.isEmpty ? '—' : trip.lastEventTime)),
          ]),
          if (trip.isTerminal) ...[
            const SizedBox(height: 12),
            SizedBox(width: double.infinity, child: FilledButton(onPressed: _newTrip, style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), child: const Text('سفر جدید'))),
          ] else if (canCancel) ...[
            const SizedBox(height: 9),
            SizedBox(width: double.infinity, child: TextButton(onPressed: _tripActionLoading ? null : _cancelTrip, child: const Text('لغو سفر'))),
          ],
        ]),
      ),
    );
  }
}

class _StatusTimeline extends StatelessWidget {
  const _StatusTimeline({required this.status});
  final String status;
  int get step => switch (status) {'driver_assigned' || 'driver_arriving' => 1, 'arrived' => 2, 'in_progress' => 3, 'completed' => 4, _ => 0};
  @override
  Widget build(BuildContext context) {
    const labels = ['درخواست', 'راننده در راه', 'رسید', 'در سفر', 'پایان'];
    return Row(children: List.generate(labels.length, (i) => Expanded(child: Column(children: [Container(width: 13, height: 13, decoration: BoxDecoration(shape: BoxShape.circle, color: i <= step ? _yellow : Colors.black12, border: Border.all(color: _black))), const SizedBox(height: 4), Text(labels[i], textAlign: TextAlign.center, style: TextStyle(fontSize: 9, fontWeight: i <= step ? FontWeight.w800 : FontWeight.normal))]))));
  }
}

class _PointRow extends StatelessWidget {
  const _PointRow({required this.icon, required this.color, required this.label, required this.active});
  final IconData icon;
  final Color color;
  final String label;
  final bool active;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
        decoration: BoxDecoration(color: active ? const Color(0xFFFFFAE9) : const Color(0xFFF7F7F7), borderRadius: BorderRadius.circular(17), border: Border.all(color: active ? _yellow : Colors.transparent)),
        child: Row(children: [Icon(icon, color: color), const SizedBox(width: 9), Expanded(child: Text(label.isEmpty ? 'آدرس در دسترس نیست' : label, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12)))]),
      );
}

class _InfoBox extends StatelessWidget {
  const _InfoBox({required this.label, required this.value});
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: const Color(0xFFFFF7DA), borderRadius: BorderRadius.circular(15)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label, style: const TextStyle(fontSize: 10, color: Colors.black54)), const SizedBox(height: 3), Text(value, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900))]));
}

class _CenterPin extends StatelessWidget {
  const _CenterPin();
  @override
  Widget build(BuildContext context) => Transform.translate(offset: const Offset(0, -24), child: Column(mainAxisSize: MainAxisSize.min, children: [Container(width: 52, height: 52, decoration: BoxDecoration(color: _yellow, shape: BoxShape.circle, border: Border.all(color: _black, width: 4)), child: const Icon(Icons.local_taxi_rounded, size: 28)), Container(width: 4, height: 20, color: _black)]));
}

class PassengerApi {
  PassengerApi()
      : _dio = Dio(BaseOptions(
          baseUrl: _apiBaseUrl,
          connectTimeout: const Duration(seconds: 8),
          receiveTimeout: const Duration(seconds: 12),
          headers: const {'Accept': 'application/json'},
        ));
  final Dio _dio;
  bool get enabled => _apiBaseUrl.trim().isNotEmpty;

  Future<String> reverse(LatLng point) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/maps/reverse', queryParameters: {'lat': point.latitude, 'lng': point.longitude});
    return (r.data?['formatted_address'] ?? 'موقعیت انتخاب‌شده').toString();
  }

  Future<RouteSummary> route(LatLng origin, LatLng destination) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/maps/route', queryParameters: {'origin': '${origin.latitude},${origin.longitude}', 'destination': '${destination.latitude},${destination.longitude}'});
    final d = r.data ?? const <String, dynamic>{};
    return RouteSummary(distanceMeters: (d['distance_meters'] as num?)?.toDouble() ?? 0, durationSeconds: (d['duration_seconds'] as num?)?.toDouble() ?? 0);
  }

  Future<FareEstimate> estimateFare(RouteSummary route) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/fare/estimate', data: {'distance_meters': route.distanceMeters.round(), 'duration_seconds': route.durationSeconds.round()});
    final d = r.data ?? const <String, dynamic>{};
    return FareEstimate(fare: (d['fare'] as num?)?.toInt() ?? 0);
  }

  Future<PassengerTrip> requestTrip({required String clientId, required LatLng origin, required LatLng destination, required String pickupLabel, required String destinationLabel, required RouteSummary route}) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/trips', data: {
      'client_id': clientId,
      'pickup': {'lat': origin.latitude, 'lng': origin.longitude},
      'destination': {'lat': destination.latitude, 'lng': destination.longitude},
      'pickup_label': pickupLabel,
      'destination_label': destinationLabel,
      'distance_meters': route.distanceMeters.round(),
      'duration_seconds': route.durationSeconds.round(),
    });
    final trip = (r.data?['trip'] as Map?)?.cast<String, dynamic>() ?? const {};
    final id = (trip['id'] ?? '').toString();
    return PassengerTrip(
      id: id,
      status: (trip['status'] ?? 'searching').toString(),
      statusFa: 'در جستجوی راننده',
      pickup: TripPoint(lat: origin.latitude, lng: origin.longitude, label: pickupLabel),
      destination: TripPoint(lat: destination.latitude, lng: destination.longitude, label: destinationLabel),
      estimatedFare: (trip['estimated_fare'] as num?)?.toInt() ?? 0,
      finalFare: null,
      requestedAt: '',
      lastEventTime: '',
      driver: null,
    );
  }

  Future<PassengerTrip> tripStatus(String clientId, String tripId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/trips/status', queryParameters: {'client_id': clientId, 'trip_id': tripId});
    return PassengerTrip.fromJson((r.data?['trip'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  Future<PassengerTrip> cancelTrip(String clientId, String tripId) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/trips/status', data: {'client_id': clientId, 'trip_id': tripId, 'action': 'cancel'});
    return PassengerTrip.fromJson((r.data?['trip'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  String messageFromError(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['message'] != null) return data['message'].toString();
      if (error.type == DioExceptionType.connectionTimeout || error.type == DioExceptionType.receiveTimeout) return 'ارتباط با سرور RADO طول کشید.';
    }
    return 'امکان انجام درخواست وجود ندارد. دوباره تلاش کنید.';
  }
}

class RouteSummary {
  const RouteSummary({required this.distanceMeters, required this.durationSeconds});
  final double distanceMeters;
  final double durationSeconds;
  factory RouteSummary.fallback(LatLng origin, LatLng destination) {
    const distance = Distance();
    final meters = distance.as(LengthUnit.Meter, origin, destination);
    return RouteSummary(distanceMeters: meters, durationSeconds: (meters / 1000) / 28 * 3600);
  }
  String get distanceLabel => distanceMeters < 1000 ? '${distanceMeters.round()} متر' : '${(distanceMeters / 1000).toStringAsFixed(1)} کیلومتر';
  String get durationLabel => '${(durationSeconds / 60).round().clamp(1, 999)} دقیقه';
}

class FareEstimate {
  const FareEstimate({required this.fare});
  final int fare;
  String get formattedFare => formatMoney(fare);
}

class PassengerTrip {
  const PassengerTrip({required this.id, required this.status, required this.statusFa, required this.pickup, required this.destination, required this.estimatedFare, required this.finalFare, required this.requestedAt, required this.lastEventTime, required this.driver});
  final String id;
  final String status;
  final String statusFa;
  final TripPoint pickup;
  final TripPoint destination;
  final int estimatedFare;
  final int? finalFare;
  final String requestedAt;
  final String lastEventTime;
  final TripDriver? driver;
  bool get isTerminal => ['completed', 'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_admin', 'expired'].contains(status);

  factory PassengerTrip.fromJson(Map<String, dynamic> j) {
    final times = (j['times'] as Map?)?.cast<String, dynamic>() ?? const {};
    String jalali(String key) => (((times[key] as Map?)?['jalali']) ?? '').toString();
    final event = [jalali('completed'), jalali('started'), jalali('arrived'), jalali('accepted'), jalali('requested')].firstWhere((e) => e.isNotEmpty, orElse: () => '');
    return PassengerTrip(
      id: (j['id'] ?? '').toString(),
      status: (j['status'] ?? '').toString(),
      statusFa: (j['status_fa'] ?? '').toString(),
      pickup: TripPoint.fromJson((j['pickup'] as Map?)?.cast<String, dynamic>() ?? const {}),
      destination: TripPoint.fromJson((j['destination'] as Map?)?.cast<String, dynamic>() ?? const {}),
      estimatedFare: (j['estimated_fare'] as num?)?.toInt() ?? 0,
      finalFare: (j['final_fare'] as num?)?.toInt(),
      requestedAt: jalali('requested'),
      lastEventTime: event,
      driver: j['driver'] is Map ? TripDriver.fromJson((j['driver'] as Map).cast<String, dynamic>()) : null,
    );
  }
}

class TripPoint {
  const TripPoint({required this.lat, required this.lng, required this.label});
  final double lat;
  final double lng;
  final String label;
  factory TripPoint.fromJson(Map<String, dynamic> j) => TripPoint(lat: (j['lat'] as num?)?.toDouble() ?? 0, lng: (j['lng'] as num?)?.toDouble() ?? 0, label: (j['label'] ?? '').toString());
}

class TripDriver {
  const TripDriver({required this.name, required this.plate, required this.vehicle});
  final String name;
  final String plate;
  final String vehicle;
  factory TripDriver.fromJson(Map<String, dynamic> j) => TripDriver(name: (j['name'] ?? 'راننده رادو').toString(), plate: (j['plate'] ?? '').toString(), vehicle: (j['vehicle'] ?? '').toString());
}

String formatMoney(int value) {
  final s = value.toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(',');
    b.write(s[i]);
  }
  return '${b.toString()} ریال';
}
