import 'package:dio/dio.dart';
import 'package:latlong2/latlong.dart';

class PassengerPlatformApi {
  PassengerPlatformApi(String baseUrl)
      : _dio = Dio(BaseOptions(
          baseUrl: baseUrl,
          connectTimeout: const Duration(seconds: 8),
          receiveTimeout: const Duration(seconds: 12),
          headers: const {'Accept': 'application/json'},
          followRedirects: false,
          validateStatus: (status) => status != null && status < 400,
        ));

  final Dio _dio;

  Future<List<PlaceResult>> searchPlaces(String query, LatLng near) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/maps/search/', queryParameters: {
      'q': query,
      'lat': near.latitude,
      'lng': near.longitude,
    });
    return ((r.data?['items'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => PlaceResult.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<List<FavoritePlace>> favorites(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/platform/', queryParameters: {
      'action': 'favorites',
      'client_id': clientId,
    });
    return ((r.data?['favorites'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => FavoritePlace.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<void> saveFavorite({
    required String clientId,
    required String kind,
    required String title,
    required String label,
    required LatLng point,
  }) async {
    await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=favorites', data: {
      'client_id': clientId,
      'op': 'save',
      'kind': kind,
      'title': title,
      'label': label,
      'lat': point.latitude,
      'lng': point.longitude,
    });
  }

  Future<List<HistoryTrip>> history(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/platform/', queryParameters: {
      'action': 'history',
      'client_id': clientId,
    });
    return ((r.data?['trips'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => HistoryTrip.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<WalletSummary> wallet(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/platform/', queryParameters: {
      'action': 'wallet',
      'role': 'passenger',
      'client_id': clientId,
    });
    return WalletSummary.fromJson((r.data?['wallet'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  Future<PromoResult> validatePromo(String clientId, String code, int fare) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=promo', data: {
      'client_id': clientId,
      'code': code,
      'fare': fare,
    });
    return PromoResult(
      code: (r.data?['code'] ?? '').toString(),
      discount: (r.data?['discount_amount'] as num?)?.toInt() ?? 0,
      payable: (r.data?['payable'] as num?)?.toInt() ?? fare,
    );
  }

  Future<String> shareTrip(String clientId, String tripId) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=share', data: {
      'client_id': clientId,
      'trip_id': tripId,
    });
    return (r.data?['url'] ?? '').toString();
  }

  Future<void> rateTrip({
    required String clientId,
    required String tripId,
    required int score,
    String comment = '',
  }) async {
    await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=rating', data: {
      'client_id': clientId,
      'role': 'passenger',
      'trip_id': tripId,
      'score': score,
      'comment': comment,
    });
  }

  Future<List<SupportTicket>> supportTickets(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/platform/', queryParameters: {
      'action': 'support',
      'role': 'passenger',
      'client_id': clientId,
    });
    return ((r.data?['tickets'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => SupportTicket.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<int> createSupportTicket({
    required String clientId,
    required String subject,
    required String message,
    String category = 'general',
    String? tripId,
  }) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=support', data: {
      'client_id': clientId,
      'role': 'passenger',
      'subject': subject,
      'message': message,
      'category': category,
      if (tripId != null) 'trip_id': tripId,
    });
    return (r.data?['ticket_id'] as num?)?.toInt() ?? 0;
  }

  Future<int> loyaltyPoints(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/platform/', queryParameters: {
      'action': 'loyalty',
      'client_id': clientId,
    });
    return (r.data?['points'] as num?)?.toInt() ?? 0;
  }

  Future<int> changeDestination({
    required String clientId,
    required String tripId,
    required PlaceResult place,
    required double distanceMeters,
    required double durationSeconds,
  }) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/trips/change/', data: {
      'client_id': clientId,
      'trip_id': tripId,
      'action': 'destination',
      'lat': place.lat,
      'lng': place.lng,
      'label': place.displayLabel,
      'distance_meters': distanceMeters.round(),
      'duration_seconds': durationSeconds.round(),
    });
    return (r.data?['fare'] as num?)?.toInt() ?? 0;
  }

  Future<void> addStop({
    required String clientId,
    required String tripId,
    required TripStopDraft stop,
  }) async {
    await _dio.post<Map<String, dynamic>>('/api/v1/trips/change/', data: {
      'client_id': clientId,
      'trip_id': tripId,
      'action': 'add_stop',
      'lat': stop.point.latitude,
      'lng': stop.point.longitude,
      'label': stop.label,
      'wait_minutes': stop.waitMinutes,
    });
  }

  String messageFromError(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['message'] != null) return data['message'].toString();
      if (data is Map && data['error'] != null) return 'خطای RADO: ${data['error']}';
      if (error.type == DioExceptionType.connectionTimeout || error.type == DioExceptionType.receiveTimeout) {
        return 'ارتباط با سرور RADO طول کشید.';
      }
    }
    return 'عملیات انجام نشد. دوباره تلاش کنید.';
  }
}

class PlaceResult {
  const PlaceResult({required this.title, required this.address, required this.lat, required this.lng});
  final String title;
  final String address;
  final double lat;
  final double lng;
  LatLng get point => LatLng(lat, lng);
  String get displayLabel => title.isEmpty ? address : address.isEmpty ? title : '$title، $address';
  factory PlaceResult.fromJson(Map<String, dynamic> j) => PlaceResult(
        title: (j['title'] ?? '').toString(),
        address: (j['address'] ?? '').toString(),
        lat: (j['lat'] as num?)?.toDouble() ?? 0,
        lng: (j['lng'] as num?)?.toDouble() ?? 0,
      );
}

class FavoritePlace {
  const FavoritePlace({required this.id, required this.kind, required this.title, required this.label, required this.lat, required this.lng});
  final int id;
  final String kind;
  final String title;
  final String label;
  final double lat;
  final double lng;
  LatLng get point => LatLng(lat, lng);
  factory FavoritePlace.fromJson(Map<String, dynamic> j) => FavoritePlace(
        id: (j['id'] as num?)?.toInt() ?? 0,
        kind: (j['kind'] ?? 'favorite').toString(),
        title: (j['title'] ?? '').toString(),
        label: (j['label'] ?? '').toString(),
        lat: (j['latitude'] as num?)?.toDouble() ?? double.tryParse('${j['latitude']}') ?? 0,
        lng: (j['longitude'] as num?)?.toDouble() ?? double.tryParse('${j['longitude']}') ?? 0,
      );
}

class HistoryTrip {
  const HistoryTrip({required this.id, required this.statusFa, required this.pickup, required this.destination, required this.fare, required this.requestedAt});
  final String id;
  final String statusFa;
  final String pickup;
  final String destination;
  final int fare;
  final String requestedAt;
  factory HistoryTrip.fromJson(Map<String, dynamic> j) => HistoryTrip(
        id: (j['id'] ?? '').toString(),
        statusFa: (j['status_fa'] ?? '').toString(),
        pickup: (j['pickup_label'] ?? '').toString(),
        destination: (j['destination_label'] ?? '').toString(),
        fare: (j['final_fare'] as num?)?.toInt() ?? (j['estimated_fare'] as num?)?.toInt() ?? 0,
        requestedAt: (j['requested_at_jalali'] ?? '').toString(),
      );
}

class WalletSummary {
  const WalletSummary({required this.balance});
  final int balance;
  factory WalletSummary.fromJson(Map<String, dynamic> j) => WalletSummary(balance: (j['balance'] as num?)?.toInt() ?? 0);
}

class PromoResult {
  const PromoResult({required this.code, required this.discount, required this.payable});
  final String code;
  final int discount;
  final int payable;
}

class SupportTicket {
  const SupportTicket({required this.id, required this.subject, required this.status, required this.createdAt});
  final int id;
  final String subject;
  final String status;
  final String createdAt;
  factory SupportTicket.fromJson(Map<String, dynamic> j) => SupportTicket(
        id: (j['id'] as num?)?.toInt() ?? 0,
        subject: (j['subject'] ?? '').toString(),
        status: (j['status'] ?? '').toString(),
        createdAt: (j['created_at_jalali'] ?? '').toString(),
      );
}

class TripStopDraft {
  const TripStopDraft({required this.point, required this.label, this.waitMinutes = 0});
  final LatLng point;
  final String label;
  final int waitMinutes;
  Map<String, dynamic> toJson() => {
        'lat': point.latitude,
        'lng': point.longitude,
        'label': label,
        'wait_minutes': waitMinutes,
      };
}

class RideOptions {
  const RideOptions({
    this.pickupNote = '',
    this.silentTrip = false,
    this.paymentMethod = 'cash',
    this.serviceType = 'economy',
    this.promoCode = '',
    this.scheduledAt,
    this.stops = const [],
  });
  final String pickupNote;
  final bool silentTrip;
  final String paymentMethod;
  final String serviceType;
  final String promoCode;
  final DateTime? scheduledAt;
  final List<TripStopDraft> stops;

  RideOptions copyWith({
    String? pickupNote,
    bool? silentTrip,
    String? paymentMethod,
    String? serviceType,
    String? promoCode,
    DateTime? scheduledAt,
    bool clearSchedule = false,
    List<TripStopDraft>? stops,
  }) =>
      RideOptions(
        pickupNote: pickupNote ?? this.pickupNote,
        silentTrip: silentTrip ?? this.silentTrip,
        paymentMethod: paymentMethod ?? this.paymentMethod,
        serviceType: serviceType ?? this.serviceType,
        promoCode: promoCode ?? this.promoCode,
        scheduledAt: clearSchedule ? null : scheduledAt ?? this.scheduledAt,
        stops: stops ?? this.stops,
      );
}
