import 'package:flutter/material.dart';

import 'app_update.dart';
import 'runtime_passenger.dart';

void main() => runApp(
      const RadoUpdateGate(app: 'passenger', child: RadoPassengerApp()),
    );

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
          seedColor: const Color(0xFFF7B500),
          primary: const Color(0xFF171717),
        ),
      ),
      home: const RuntimePassengerPage(),
    );
  }
}
