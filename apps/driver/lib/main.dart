import 'package:flutter/material.dart';

import 'advanced_driver.dart';
import 'app_update.dart';

void main() => runApp(const RadoDriverApp());

class RadoDriverApp extends StatelessWidget {
  const RadoDriverApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'RADO Driver',
      locale: const Locale('fa'),
      theme: ThemeData(
        useMaterial3: true,
        scaffoldBackgroundColor: const Color(0xFFF6F6F4),
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFFF7B500),
          primary: const Color(0xFF171717),
        ),
        navigationBarTheme: const NavigationBarThemeData(
          indicatorColor: Color(0xFFFFE59A),
        ),
      ),
      home: const RadoUpdateGate(
        app: 'driver',
        child: AdvancedDriverPage(),
      ),
    );
  }
}
