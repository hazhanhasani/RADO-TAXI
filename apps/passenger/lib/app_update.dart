import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:url_launcher/url_launcher.dart';

class RadoUpdateGate extends StatefulWidget {
  const RadoUpdateGate({super.key, required this.app, required this.child});
  final String app;
  final Widget child;

  @override
  State<RadoUpdateGate> createState() => _RadoUpdateGateState();
}

class _RadoUpdateGateState extends State<RadoUpdateGate> {
  static const _updateRoot = 'https://rado-taxi.sbs/api/app-updates';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _check());
  }

  Future<void> _check() async {
    try {
      final info = await PackageInfo.fromPlatform();
      final currentCode = int.tryParse(info.buildNumber) ?? 0;
      final response = await Dio(BaseOptions(connectTimeout: const Duration(seconds: 6), receiveTimeout: const Duration(seconds: 8)))
          .get<Map<String, dynamic>>('$_updateRoot/${widget.app}');
      final data = response.data;
      if (!mounted || data == null) return;
      final remoteCode = (data['version_code'] as num?)?.toInt() ?? 0;
      if (remoteCode <= currentCode) return;
      final version = (data['version'] ?? '').toString();
      final mandatory = data['mandatory'] == true || currentCode < ((data['minimum_supported_code'] as num?)?.toInt() ?? 0);
      final url = Uri.tryParse((data['download_url'] ?? '').toString());
      if (url == null) return;
      await showDialog<void>(
        context: context,
        barrierDismissible: !mandatory,
        builder: (context) => PopScope(
          canPop: !mandatory,
          child: AlertDialog(
            title: const Text('نسخه جدید RADO آماده است'),
            content: Text(version.isEmpty ? 'برای ادامه، برنامه را به‌روزرسانی کنید.' : 'نسخه $version آماده نصب است.'),
            actions: [
              if (!mandatory) TextButton(onPressed: () => Navigator.pop(context), child: const Text('بعداً')),
              FilledButton(
                onPressed: () async {
                  await launchUrl(url, mode: LaunchMode.externalApplication);
                  if (context.mounted && !mandatory) Navigator.pop(context);
                },
                child: const Text('دانلود و نصب'),
              ),
            ],
          ),
        ),
      );
    } catch (_) {
      // Update checks must never prevent normal app startup while offline.
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
