from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected one match, found {count}')
    return text.replace(old, new, 1)


p = Path('apps/driver/lib/driver_platform.dart')
s = p.read_text()

old = """  Future<void> createSupport(String clientId, String subject, String message, {String? tripId}) async {
    await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=support', data: {'client_id': clientId, 'role': 'driver', 'subject': subject, 'message': message, if (tripId != null) 'trip_id': tripId});
  }

  Future<List<DriverPlace>> searchPlaces(String q, LatLng near) async {
"""
new = """  Future<void> createSupport(String clientId, String subject, String message, {String? tripId}) async {
    await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=support', data: {'client_id': clientId, 'role': 'driver', 'subject': subject, 'message': message, if (tripId != null) 'trip_id': tripId});
  }

  Future<List<DriverNotification>> notifications(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/platform/', queryParameters: {
      'action': 'notifications',
      'role': 'driver',
      'client_id': clientId,
    });
    return ((r.data?['notifications'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DriverNotification.fromJson(e.cast<String, dynamic>()))
        .toList();
  }

  Future<void> markNotificationRead(String clientId, int notificationId) async {
    await _dio.post<Map<String, dynamic>>('/api/v1/platform/?action=notifications', data: {
      'client_id': clientId,
      'role': 'driver',
      'id': notificationId,
    });
  }

  Future<List<DriverPlace>> searchPlaces(String q, LatLng near) async {
"""
s = replace_once(s, old, new, 'driver notification api methods')

s = replace_once(
    s,
    "class DriverSession {",
    """class DriverNotification {
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

  factory DriverNotification.fromJson(Map<String, dynamic> j) => DriverNotification(
        id: (j['id'] as num?)?.toInt() ?? 0,
        title: (j['title'] ?? 'RADO Driver').toString(),
        body: (j['body'] ?? '').toString(),
        type: (j['type'] ?? '').toString(),
        read: j['read_at'] != null,
      );
}

class DriverSession {""",
    'driver notification model',
)
p.write_text(s)

p = Path('apps/driver/lib/advanced_driver.dart')
s = p.read_text()

s = replace_once(
    s,
    """      final wallet = await _api.wallet(id);
      DriverPreferences prefs = _prefs;
""",
    """      final wallet = await _api.wallet(id);
      List<DriverNotification> serverNotifications = const [];
      if (session.approved) {
        try {
          serverNotifications = await _api.notifications(id);
        } catch (_) {}
      }
      DriverPreferences prefs = _prefs;
""",
    'driver fetch server notifications',
)

anchor = """      if (incomingOffer != null && incomingOffer.id != _lastNotifiedOfferId) {
"""
insert = """      final unread = serverNotifications.where((n) => !n.read).take(3).toList().reversed;
      for (final notice in unread) {
        try {
          if (notice.type != 'trip_offer') {
            await _notifications.show(
              title: notice.title,
              body: notice.body,
              payload: notice.id.toString(),
            );
          }
          await _api.markNotificationRead(id, notice.id);
        } catch (_) {}
      }

"""
s = replace_once(s, anchor, insert + anchor, 'driver process server notifications')
p.write_text(s)

print('RADO driver notification queue patch applied')
