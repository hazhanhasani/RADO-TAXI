import 'dart:io';

import 'package:dio/dio.dart';
import 'package:latlong2/latlong.dart';

const _base = String.fromEnvironment('RADO_API_BASE_URL');

class DriverPlatformApi {
  DriverPlatformApi()
    : _dio = Dio(
        BaseOptions(
          baseUrl: _base,
          connectTimeout: const Duration(seconds: 8),
          receiveTimeout: const Duration(seconds: 15),
          headers: const {'Accept': 'application/json'},
          followRedirects: false,
          validateStatus: (s) => s != null && s < 400,
        ),
      );
  final Dio _dio;

  Future<DriverSession> session(String clientId) async {
    final r = await _dio.post<Map<String, dynamic>>(
      '/api/v1/driver/session/',
      data: {'client_id': clientId},
    );
    return DriverSession.fromJson(
      (r.data?['driver'] as Map?)?.cast<String, dynamic>() ?? const {},
    );
  }

  Future<void> presence(
    String clientId, {
    required bool online,
    double? lat,
    double? lng,
    int? heading,
    double? speedKph,
  }) async {
    await _dio.post<Map<String, dynamic>>(
      '/api/v1/driver/presence/',
      data: {
        'client_id': clientId,
        'online': online,
        if (lat != null) 'lat': lat,
        if (lng != null) 'lng': lng,
        if (heading != null) 'heading': heading,
        if (speedKph != null) 'speed_kph': speedKph,
      },
    );
  }

  Future<DriverState> offers(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/driver/offers/',
      queryParameters: {'client_id': clientId},
    );
    final d = r.data ?? const <String, dynamic>{};
    final list = (d['offers'] as List?) ?? const [];
    return DriverState(
      offer: list.isEmpty
          ? null
          : DriverOffer.fromJson((list.first as Map).cast<String, dynamic>()),
      activeTrip: d['active_trip'] is Map
          ? DriverTrip.fromJson(
              (d['active_trip'] as Map).cast<String, dynamic>(),
            )
          : null,
    );
  }

  Future<DriverTrip?> answerOffer(
    String clientId,
    int offerId,
    bool accept,
  ) async {
    final r = await _dio.post<Map<String, dynamic>>(
      '/api/v1/driver/offers/',
      data: {
        'client_id': clientId,
        'offer_id': offerId,
        'action': accept ? 'accept' : 'reject',
      },
    );
    final t = r.data?['trip'];
    return t is Map ? DriverTrip.fromJson(t.cast<String, dynamic>()) : null;
  }

  Future<TripActionResult> tripAction(
    String clientId,
    String tripId,
    String action,
  ) async {
    final r = await _dio.post<Map<String, dynamic>>(
      '/api/v1/driver/trip/',
      data: {'client_id': clientId, 'trip_id': tripId, 'action': action},
    );
    final d = r.data ?? const <String, dynamic>{};
    return TripActionResult(
      trip: d['trip'] is Map
          ? DriverTrip.fromJson((d['trip'] as Map).cast<String, dynamic>())
          : null,
      finance: d['finance'] is Map
          ? TripFinance.fromJson((d['finance'] as Map).cast<String, dynamic>())
          : null,
    );
  }

  Future<DriverWallet> wallet(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/driver/wallet/',
      queryParameters: {'client_id': clientId},
    );
    return DriverWallet.fromJson(
      (r.data?['wallet'] as Map?)?.cast<String, dynamic>() ?? const {},
    );
  }

  Future<DriverPreferences> preferences(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/platform/',
      queryParameters: {'action': 'driver_preferences', 'client_id': clientId},
    );
    return DriverPreferences.fromJson(
      (r.data?['preferences'] as Map?)?.cast<String, dynamic>() ?? const {},
    );
  }

  Future<void> savePreferences(String clientId, DriverPreferences p) async {
    await _dio.post<Map<String, dynamic>>(
      '/api/v1/platform/?action=driver_preferences',
      data: {
        'client_id': clientId,
        'destination_label': p.destinationLabel,
        'destination_lat': p.destinationLat,
        'destination_lng': p.destinationLng,
        'max_pickup_distance_km': p.maxPickupDistanceKm,
        'min_fare': p.minFare,
        'auto_accept': p.autoAccept,
        'auto_accept_radius_km': p.autoAcceptRadiusKm,
        'auto_accept_min_fare': p.autoAcceptMinFare,
      },
    );
  }

  Future<List<DriverMission>> missions(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/platform/',
      queryParameters: {'action': 'missions', 'client_id': clientId},
    );
    return ((r.data?['missions'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DriverMission.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<List<Settlement>> settlements(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/platform/',
      queryParameters: {'action': 'settlements', 'client_id': clientId},
    );
    return ((r.data?['settlements'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => Settlement.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<void> requestSettlement(String clientId, int amount) async {
    await _dio.post<Map<String, dynamic>>(
      '/api/v1/platform/?action=settlements',
      data: {'client_id': clientId, 'amount': amount},
    );
  }

  Future<List<DriverDocument>> documents(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/driver/documents/',
      queryParameters: {'client_id': clientId},
    );
    return ((r.data?['documents'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DriverDocument.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<void> uploadDocument({
    required String clientId,
    required String path,
    required String type,
    String number = '',
    String expiry = '',
  }) async {
    final name = path.split(Platform.pathSeparator).last;
    final form = FormData.fromMap({
      'client_id': clientId,
      'document_type': type,
      'document_number': number,
      'expires_at': expiry,
      'document': await MultipartFile.fromFile(path, filename: name),
    });
    await _dio.post<Map<String, dynamic>>(
      '/api/v1/driver/documents/',
      data: form,
      options: Options(contentType: 'multipart/form-data'),
    );
  }

  Future<List<DriverSupportTicket>> support(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/platform/',
      queryParameters: {
        'action': 'support',
        'role': 'driver',
        'client_id': clientId,
      },
    );
    return ((r.data?['tickets'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DriverSupportTicket.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<void> createSupport(
    String clientId,
    String subject,
    String message, {
    String? tripId,
  }) async {
    await _dio.post<Map<String, dynamic>>(
      '/api/v1/platform/?action=support',
      data: {
        'client_id': clientId,
        'role': 'driver',
        'subject': subject,
        'message': message,
        if (tripId != null) 'trip_id': tripId,
      },
    );
  }

  Future<List<DriverNotification>> notifications(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/platform/',
      queryParameters: {
        'action': 'notifications',
        'role': 'driver',
        'client_id': clientId,
      },
    );
    return ((r.data?['notifications'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DriverNotification.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<void> markNotificationRead(String clientId, int notificationId) async {
    await _dio.post<Map<String, dynamic>>(
      '/api/v1/platform/?action=notifications',
      data: {'client_id': clientId, 'role': 'driver', 'id': notificationId},
    );
  }

  Future<List<DriverPlace>> searchPlaces(String q, LatLng near) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/maps/search/',
      queryParameters: {'q': q, 'lat': near.latitude, 'lng': near.longitude},
    );
    return ((r.data?['items'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DriverPlace.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  String message(Object error) {
    if (error is DioException) {
      final d = error.response?.data;
      if (d is Map && d['message'] != null) return d['message'].toString();
      if (d is Map && d['error'] != null) return 'خطای RADO: ${d['error']}';
      if (error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.receiveTimeout)
        return 'ارتباط با سرور RADO طول کشید.';
      if (error.type == DioExceptionType.connectionError)
        return 'ارتباط با سرور RADO برقرار نشد.';
    }
    return 'عملیات انجام نشد؛ دوباره تلاش کنید.';
  }
}

class DriverNotification {
  const DriverNotification({
    required this.id,
    required this.title,
    required this.body,
    required this.type,
    required this.read,
  });

  final int id;
  final String title;
  final String body;
  final String type;
  final bool read;

  factory DriverNotification.fromJson(Map<String, dynamic> j) =>
      DriverNotification(
        id: (j['id'] as num?)?.toInt() ?? 0,
        title: (j['title'] ?? 'RADO Driver').toString(),
        body: (j['body'] ?? '').toString(),
        type: (j['type'] ?? '').toString(),
        read: j['read_at'] != null,
      );
}

class DriverSession {
  const DriverSession({
    required this.name,
    required this.status,
    required this.approved,
    required this.online,
    required this.commissionRate,
    required this.plate,
    required this.vehicle,
  });
  final String name, status, plate, vehicle;
  final bool approved, online;
  final double commissionRate;
  String get statusFa => switch (status) {
    'approved' => 'تأییدشده',
    'suspended' => 'تعلیق‌شده',
    'rejected' => 'ردشده',
    _ => 'در انتظار تأیید',
  };
  factory DriverSession.fromJson(Map<String, dynamic> j) => DriverSession(
    name: (j['name'] ?? 'راننده RADO').toString(),
    status: (j['status'] ?? 'pending').toString(),
    approved: j['approved'] == true,
    online: j['online'] == true,
    commissionRate: (j['commission_rate'] as num?)?.toDouble() ?? 0,
    plate: (j['plate'] ?? '').toString(),
    vehicle: (j['vehicle'] ?? '').toString(),
  );
}

class DriverState {
  const DriverState({this.offer, this.activeTrip});
  final DriverOffer? offer;
  final DriverTrip? activeTrip;
}

class DriverOffer {
  const DriverOffer({
    required this.id,
    required this.trip,
    required this.offeredAt,
    required this.expiresAt,
  });
  final int id;
  final DriverTrip trip;
  final String offeredAt, expiresAt;
  factory DriverOffer.fromJson(Map<String, dynamic> j) => DriverOffer(
    id: (j['offer_id'] as num?)?.toInt() ?? 0,
    trip: DriverTrip.fromJson(
      (j['trip'] as Map?)?.cast<String, dynamic>() ?? const {},
    ),
    offeredAt: (((j['offered_at'] as Map?)?['jalali']) ?? '').toString(),
    expiresAt: (((j['expires_at'] as Map?)?['jalali']) ?? '').toString(),
  );
}

class DriverTrip {
  const DriverTrip({
    required this.id,
    required this.status,
    required this.statusFa,
    required this.pickup,
    required this.destination,
    required this.estimatedFare,
    required this.finalFare,
    required this.distanceMeters,
    required this.requestedAt,
  });
  final String id, status, statusFa, requestedAt;
  final TripPoint pickup, destination;
  final int estimatedFare, distanceMeters;
  final int? finalFare;
  String get distanceLabel => distanceMeters < 1000
      ? '$distanceMeters متر'
      : '${(distanceMeters / 1000).toStringAsFixed(1)} کیلومتر';
  bool get terminal => [
    'completed',
    'cancelled_by_passenger',
    'cancelled_by_driver',
    'cancelled_by_admin',
    'expired',
  ].contains(status);
  factory DriverTrip.fromJson(Map<String, dynamic> j) => DriverTrip(
    id: (j['id'] ?? '').toString(),
    status: (j['status'] ?? '').toString(),
    statusFa: (j['status_fa'] ?? '').toString(),
    pickup: TripPoint.fromJson(
      (j['pickup'] as Map?)?.cast<String, dynamic>() ?? const {},
    ),
    destination: TripPoint.fromJson(
      (j['destination'] as Map?)?.cast<String, dynamic>() ?? const {},
    ),
    estimatedFare: (j['estimated_fare'] as num?)?.toInt() ?? 0,
    finalFare: (j['final_fare'] as num?)?.toInt(),
    distanceMeters: (j['estimated_distance_m'] as num?)?.toInt() ?? 0,
    requestedAt: (((j['times'] as Map?)?['requested'] as Map?)?['jalali'] ?? '')
        .toString(),
  );
}

class TripPoint {
  const TripPoint({required this.lat, required this.lng, required this.label});
  final double lat, lng;
  final String label;
  LatLng get point => LatLng(lat, lng);
  factory TripPoint.fromJson(Map<String, dynamic> j) => TripPoint(
    lat: (j['lat'] as num?)?.toDouble() ?? 0,
    lng: (j['lng'] as num?)?.toDouble() ?? 0,
    label: (j['label'] ?? '').toString(),
  );
}

class DriverWallet {
  const DriverWallet({
    required this.balance,
    required this.commissionRate,
    required this.todayNet,
    required this.todayCommission,
    required this.todayTrips,
    required this.entries,
  });
  final int balance, todayNet, todayCommission, todayTrips;
  final double commissionRate;
  final List<WalletEntry> entries;
  factory DriverWallet.fromJson(Map<String, dynamic> j) => DriverWallet(
    balance: (j['balance'] as num?)?.toInt() ?? 0,
    commissionRate: (j['commission_rate'] as num?)?.toDouble() ?? 0,
    todayNet: (j['today_net'] as num?)?.toInt() ?? 0,
    todayCommission: (j['today_commission'] as num?)?.toInt() ?? 0,
    todayTrips: (j['today_trips'] as num?)?.toInt() ?? 0,
    entries: ((j['entries'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => WalletEntry.fromJson(e.cast<String, dynamic>()))
        .toList(),
  );
}

class WalletEntry {
  const WalletEntry(this.type, this.amount, this.jalali);
  final String type, jalali;
  final int amount;
  String get title => switch (type) {
    'trip_gross' => 'کرایه سفر',
    'platform_commission' => 'کمیسیون RADO',
    'mission_reward' => 'پاداش مأموریت',
    _ => type,
  };
  factory WalletEntry.fromJson(Map<String, dynamic> j) => WalletEntry(
    (j['type'] ?? '').toString(),
    (j['amount'] as num?)?.toInt() ?? 0,
    (((j['created_at'] as Map?)?['jalali']) ?? '').toString(),
  );
}

class TripActionResult {
  const TripActionResult({this.trip, this.finance});
  final DriverTrip? trip;
  final TripFinance? finance;
}

class TripFinance {
  const TripFinance(this.commission, this.driverNet);
  final int commission, driverNet;
  factory TripFinance.fromJson(Map<String, dynamic> j) => TripFinance(
    (j['commission'] as num?)?.toInt() ?? 0,
    (j['driver_net'] as num?)?.toInt() ?? 0,
  );
}

class DriverPreferences {
  const DriverPreferences({
    this.destinationLabel = '',
    this.destinationLat,
    this.destinationLng,
    this.maxPickupDistanceKm = 5,
    this.minFare = 0,
    this.autoAccept = false,
    this.autoAcceptRadiusKm = 2,
    this.autoAcceptMinFare = 0,
  });
  final String destinationLabel;
  final double? destinationLat, destinationLng;
  final double maxPickupDistanceKm, autoAcceptRadiusKm;
  final int minFare, autoAcceptMinFare;
  final bool autoAccept;
  DriverPreferences copyWith({
    String? destinationLabel,
    double? destinationLat,
    double? destinationLng,
    double? maxPickupDistanceKm,
    int? minFare,
    bool? autoAccept,
    double? autoAcceptRadiusKm,
    int? autoAcceptMinFare,
    bool clearDestination = false,
  }) => DriverPreferences(
    destinationLabel: clearDestination
        ? ''
        : destinationLabel ?? this.destinationLabel,
    destinationLat: clearDestination
        ? null
        : destinationLat ?? this.destinationLat,
    destinationLng: clearDestination
        ? null
        : destinationLng ?? this.destinationLng,
    maxPickupDistanceKm: maxPickupDistanceKm ?? this.maxPickupDistanceKm,
    minFare: minFare ?? this.minFare,
    autoAccept: autoAccept ?? this.autoAccept,
    autoAcceptRadiusKm: autoAcceptRadiusKm ?? this.autoAcceptRadiusKm,
    autoAcceptMinFare: autoAcceptMinFare ?? this.autoAcceptMinFare,
  );
  factory DriverPreferences.fromJson(Map<String, dynamic> j) =>
      DriverPreferences(
        destinationLabel: (j['destination_label'] ?? '').toString(),
        destinationLat: _d(j['destination_lat']),
        destinationLng: _d(j['destination_lng']),
        maxPickupDistanceKm: _d(j['max_pickup_distance_km']) ?? 5,
        minFare: _i(j['min_fare']),
        autoAccept:
            j['auto_accept'] == true ||
            j['auto_accept'] == 1 ||
            j['auto_accept'] == '1',
        autoAcceptRadiusKm: _d(j['auto_accept_radius_km']) ?? 2,
        autoAcceptMinFare: _i(j['auto_accept_min_fare']),
      );
}

class DriverMission {
  const DriverMission({
    required this.id,
    required this.title,
    required this.description,
    required this.targetType,
    required this.target,
    required this.progress,
    required this.reward,
    required this.endsAt,
  });
  final int id, target, progress, reward;
  final String title, description, targetType, endsAt;
  double get ratio => target <= 0 ? 0 : (progress / target).clamp(0, 1);
  factory DriverMission.fromJson(Map<String, dynamic> j) => DriverMission(
    id: _i(j['id']),
    title: (j['title'] ?? '').toString(),
    description: (j['description'] ?? '').toString(),
    targetType: (j['target_type'] ?? '').toString(),
    target: _i(j['target_value']),
    progress: _i(j['progress_value']),
    reward: _i(j['reward_amount']),
    endsAt: (j['ends_at_jalali'] ?? '').toString(),
  );
}

class Settlement {
  const Settlement(this.id, this.amount, this.status, this.requestedAt);
  final int id, amount;
  final String status, requestedAt;
  factory Settlement.fromJson(Map<String, dynamic> j) => Settlement(
    _i(j['id']),
    _i(j['amount']),
    (j['status'] ?? '').toString(),
    (j['requested_at_jalali'] ?? '').toString(),
  );
}

class DriverDocument {
  const DriverDocument(
    this.id,
    this.type,
    this.number,
    this.status,
    this.expiry,
  );
  final int id;
  final String type, number, status, expiry;
  factory DriverDocument.fromJson(Map<String, dynamic> j) => DriverDocument(
    _i(j['id']),
    (j['document_type'] ?? '').toString(),
    (j['document_number'] ?? '').toString(),
    (j['status'] ?? 'pending').toString(),
    (j['expires_at_jalali'] ?? '').toString(),
  );
}

class DriverSupportTicket {
  const DriverSupportTicket(this.id, this.subject, this.status, this.createdAt);
  final int id;
  final String subject, status, createdAt;
  factory DriverSupportTicket.fromJson(Map<String, dynamic> j) =>
      DriverSupportTicket(
        _i(j['id']),
        (j['subject'] ?? '').toString(),
        (j['status'] ?? '').toString(),
        (j['created_at_jalali'] ?? '').toString(),
      );
}

class DriverPlace {
  const DriverPlace(this.title, this.address, this.lat, this.lng);
  final String title, address;
  final double lat, lng;
  String get label => address.isEmpty ? title : '$title، $address';
  factory DriverPlace.fromJson(Map<String, dynamic> j) => DriverPlace(
    (j['title'] ?? '').toString(),
    (j['address'] ?? '').toString(),
    _d(j['lat']) ?? 0,
    _d(j['lng']) ?? 0,
  );
}

int _i(Object? v) => v is num ? v.toInt() : int.tryParse('$v') ?? 0;
double? _d(Object? v) => v is num ? v.toDouble() : double.tryParse('$v');
String money(int v) {
  final s = v.toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(',');
    b.write(s[i]);
  }
  return '${b.toString()} ریال';
}
