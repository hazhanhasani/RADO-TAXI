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
  Map<String, dynamic>? _update;
  bool _mandatory = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _check());
  }

  Future<void> _check() async {
    try {
      final info = await PackageInfo.fromPlatform();
      final currentCode = int.tryParse(info.buildNumber) ?? 0;
      final response = await Dio(
        BaseOptions(
          connectTimeout: const Duration(seconds: 6),
          receiveTimeout: const Duration(seconds: 8),
        ),
      ).get<Map<String, dynamic>>('$_updateRoot/${widget.app}');
      final data = response.data;
      if (!mounted || data == null) return;
      final remoteCode = (data['version_code'] as num?)?.toInt() ?? 0;
      if (remoteCode <= currentCode) return;
      final minimumCode = (data['minimum_supported_code'] as num?)?.toInt() ?? 0;
      setState(() {
        _update = data;
        _mandatory = data['mandatory'] == true || currentCode < minimumCode;
      });
    } catch (_) {
      // Offline/update-server failures must never block normal startup.
    }
  }

  Future<void> _install() async {
    final url = Uri.tryParse((_update?['download_url'] ?? '').toString());
    if (url == null) return;
    await launchUrl(url, mode: LaunchMode.externalApplication);
    if (mounted && !_mandatory) setState(() => _update = null);
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        widget.child,
        if (_update != null) ...[
          const ModalBarrier(dismissible: false, color: Color(0x99000000)),
          Center(
            child: Theme(
              data: ThemeData(useMaterial3: true, brightness: Brightness.light),
              child: Directionality(
                textDirection: TextDirection.rtl,
                child: Material(
                  elevation: 24,
                  borderRadius: BorderRadius.circular(24),
                  color: Colors.white,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 360),
                    child: Padding(
                      padding: const EdgeInsets.all(22),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.system_update_rounded, size: 48),
                          const SizedBox(height: 12),
                          const Text(
                            'نسخه جدید RADO آماده است',
                            textAlign: TextAlign.center,
                            style: TextStyle(fontSize: 19, fontWeight: FontWeight.w900),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'نسخه ${(_update?['version'] ?? '').toString()} از سرور رسمی RADO آماده دانلود است.',
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 18),
                          Row(
                            children: [
                              if (!_mandatory) ...[
                                Expanded(
                                  child: TextButton(
                                    onPressed: () => setState(() => _update = null),
                                    child: const Text('بعداً'),
                                  ),
                                ),
                                const SizedBox(width: 8),
                              ],
                              Expanded(
                                child: FilledButton(
                                  onPressed: _install,
                                  child: const Text('دانلود و نصب'),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}
