import 'package:dio/dio.dart';
import 'package:latlong2/latlong.dart';

class PassengerRealtimeApi {
  PassengerRealtimeApi(String baseUrl)
      : _dio = Dio(BaseOptions(
          baseUrl: baseUrl,
          connectTimeout: const Duration(seconds: 7),
          receiveTimeout: const Duration(seconds: 9),
          headers: const {'Accept': 'application/json'},
          followRedirects: false,
          validateStatus: (status) => status != null && status < 400,
        ));

  final Dio _dio;

  Future<LiveTripSnapshot> snapshot({
    required String clientId,
    required String tripId,
    int sinceEventId = 0,
  }) async {
    final r = await _dio.get<Map<String, dynamic>>(
      '/api/v1/realtime/',
      queryParameters: {
        'role': 'passenger',
        'client_id': clientId,
        'trip_id': tripId,
        'since_event_id': sinceEventId,
      },
    );
    return LiveTripSnapshot.fromJson(r.data ?? const {});
  }
}

class LiveTripSnapshot {
  const LiveTripSnapshot({
    required this.status,
    required this.driverPosition,
    required this.eta,
    required this.driverProfile,
    required this.events,
  });

  final String status;
  final LiveDriverPosition? driverPosition;
  final LiveEta? eta;
  final LiveDriverProfile? driverProfile;
  final List<LiveTripEvent> events;

  int get lastEventId => events.isEmpty ? 0 : events.last.id;

  factory LiveTripSnapshot.fromJson(Map<String, dynamic> j) => LiveTripSnapshot(
        status: (j['status'] ?? '').toString(),
        driverPosition: j['driver_position'] is Map
            ? LiveDriverPosition.fromJson((j['driver_position'] as Map).cast<String, dynamic>())
            : null,
        eta: j['eta'] is Map ? LiveEta.fromJson((j['eta'] as Map).cast<String, dynamic>()) : null,
        driverProfile: j['driver_profile'] is Map
            ? LiveDriverProfile.fromJson((j['driver_profile'] as Map).cast<String, dynamic>())
            : null,
        events: ((j['events'] as List?) ?? const [])
            .whereType<Map>()
            .map((e) => LiveTripEvent.fromJson(e.cast<String, dynamic>()))
            .toList(growable: false),
      );
}

class LiveDriverPosition {
  const LiveDriverPosition({
    required this.lat,
    required this.lng,
    required this.heading,
    required this.speedKph,
    required this.updatedAt,
  });

  final double lat;
  final double lng;
  final int? heading;
  final double? speedKph;
  final String updatedAt;
  LatLng get point => LatLng(lat, lng);

  factory LiveDriverPosition.fromJson(Map<String, dynamic> j) {
    final seen = (j['last_seen_at'] as Map?)?.cast<String, dynamic>() ?? const {};
    return LiveDriverPosition(
      lat: (j['lat'] as num?)?.toDouble() ?? 0,
      lng: (j['lng'] as num?)?.toDouble() ?? 0,
      heading: (j['heading'] as num?)?.toInt(),
      speedKph: (j['speed_kph'] as num?)?.toDouble(),
      updatedAt: (seen['jalali'] ?? '').toString(),
    );
  }
}

class LiveEta {
  const LiveEta({required this.distanceMeters, required this.minutes, required this.target});
  final int distanceMeters;
  final int minutes;
  final String target;

  String get distanceLabel => distanceMeters < 1000
      ? '$distanceMeters متر'
      : '${(distanceMeters / 1000).toStringAsFixed(1)} کیلومتر';

  factory LiveEta.fromJson(Map<String, dynamic> j) => LiveEta(
        distanceMeters: (j['distance_meters'] as num?)?.toInt() ?? 0,
        minutes: (j['minutes'] as num?)?.toInt() ?? 0,
        target: (j['target'] ?? '').toString(),
      );
}

class LiveDriverProfile {
  const LiveDriverProfile({
    required this.name,
    required this.plate,
    required this.vehicle,
    required this.color,
    required this.rating,
    required this.ratingCount,
  });
  final String name;
  final String plate;
  final String vehicle;
  final String color;
  final double? rating;
  final int ratingCount;

  factory LiveDriverProfile.fromJson(Map<String, dynamic> j) => LiveDriverProfile(
        name: (j['name'] ?? 'راننده RADO').toString(),
        plate: (j['plate'] ?? '').toString(),
        vehicle: (j['vehicle'] ?? '').toString(),
        color: (j['color'] ?? '').toString(),
        rating: (j['rating'] as num?)?.toDouble(),
        ratingCount: (j['rating_count'] as num?)?.toInt() ?? 0,
      );
}

class LiveTripEvent {
  const LiveTripEvent({required this.id, required this.type, required this.createdAt});
  final int id;
  final String type;
  final String createdAt;

  factory LiveTripEvent.fromJson(Map<String, dynamic> j) => LiveTripEvent(
        id: (j['id'] as num?)?.toInt() ?? 0,
        type: (j['event_type'] ?? '').toString(),
        createdAt: (j['created_at_jalali'] ?? '').toString(),
      );
}
