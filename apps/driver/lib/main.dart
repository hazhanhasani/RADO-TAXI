import 'package:flutter/material.dart';
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
      theme: ThemeData(useMaterial3: true, brightness: Brightness.light),
      home: const RadoUpdateGate(
        app: 'driver',
        child: Scaffold(
          body: SafeArea(
            child: Center(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Text('RADO', style: TextStyle(fontSize: 44, fontWeight: FontWeight.w900)),
                SizedBox(height: 4),
                Text('DRIVER', style: TextStyle(fontWeight: FontWeight.w700)),
                SizedBox(height: 24),
                Text('راننده رادو — بانه'),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
