import 'package:flutter_local_notifications/flutter_local_notifications.dart';

class RadoNotifications {
  RadoNotifications._();
  static final RadoNotifications instance = RadoNotifications._();

  final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  bool _ready = false;
  int _serial = 1000;

  Future<void> init() async {
    if (_ready) return;
    const android = AndroidInitializationSettings('@mipmap/ic_launcher');
    const settings = InitializationSettings(android: android);
    await _plugin.initialize(settings);
    await _plugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.requestNotificationsPermission();
    _ready = true;
  }

  Future<void> show({required String title, required String body, String? payload}) async {
    if (!_ready) await init();
    const android = AndroidNotificationDetails(
      'rado_trip_events',
      'سفرهای RADO',
      channelDescription: 'درخواست سفر و تغییرات زنده وضعیت سفر',
      importance: Importance.max,
      priority: Priority.high,
      playSound: true,
      enableVibration: true,
      ticker: 'RADO',
    );
    await _plugin.show(
      _serial++,
      title,
      body,
      const NotificationDetails(android: android),
      payload: payload,
    );
  }
}
