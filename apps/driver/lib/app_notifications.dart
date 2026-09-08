import 'package:flutter_local_notifications/flutter_local_notifications.dart';

class RadoNotifications {
  RadoNotifications._();
  static final RadoNotifications instance = RadoNotifications._();

  final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  bool _ready = false;
  int _serial = 2000;

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
      'rado_driver_events',
      'درخواست‌های RADO Driver',
      channelDescription: 'درخواست سفر جدید و تغییرات وضعیت سفر راننده',
      importance: Importance.max,
      priority: Priority.high,
      playSound: true,
      enableVibration: true,
      ticker: 'RADO Driver',
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
