import 'package:flutter/material.dart';

void main() => runApp(const RadoPassengerApp());

class RadoPassengerApp extends StatelessWidget {
  const RadoPassengerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'RADO',
      theme: ThemeData(useMaterial3: true, brightness: Brightness.light),
      home: const Scaffold(
        body: SafeArea(
          child: Center(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text('RADO', style: TextStyle(fontSize: 44, fontWeight: FontWeight.w900)),
              SizedBox(height: 8),
              Text('رادو — تاکسی اینترنتی بانه'),
              SizedBox(height: 24),
              Text('Passenger MVP bootstrap'),
            ]),
          ),
        ),
      ),
    );
  }
}
