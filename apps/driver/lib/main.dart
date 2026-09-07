import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'app_update.dart';

void main() => runApp(const RadoDriverApp());

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
const _surface = Color(0xFFF6F6F4);

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
        scaffoldBackgroundColor: _surface,
        colorScheme: ColorScheme.fromSeed(
          seedColor: _yellow,
          primary: _black,
          brightness: Brightness.light,
        ),
        navigationBarTheme: const NavigationBarThemeData(
          indicatorColor: Color(0xFFFFE59A),
        ),
      ),
      home: const RadoUpdateGate(app: 'driver', child: DriverShell()),
    );
  }
}

class DriverShell extends StatefulWidget {
  const DriverShell({super.key});

  @override
  State<DriverShell> createState() => _DriverShellState();
}

class _DriverShellState extends State<DriverShell> {
  int _tab = 0;
  bool _online = false;
  bool _changingStatus = false;

  Future<void> _toggleOnline() async {
    if (_changingStatus) return;
    if (_online) {
      setState(() => _online = false);
      return;
    }

    setState(() => _changingStatus = true);
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        _message('برای آنلاین شدن، موقعیت مکانی گوشی را روشن کنید.');
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        _message('اجازه دسترسی به موقعیت برای دریافت سفر لازم است.');
        return;
      }

      setState(() => _online = true);
    } catch (_) {
      _message('فعلاً امکان فعال‌کردن وضعیت آنلاین نیست. دوباره تلاش کنید.');
    } finally {
      if (mounted) setState(() => _changingStatus = false);
    }
  }

  void _message(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      DriverHome(
        online: _online,
        changingStatus: _changingStatus,
        onToggleOnline: _toggleOnline,
      ),
      const DriverTrips(),
      const DriverEarnings(),
      const DriverAccount(),
    ];

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        body: SafeArea(child: IndexedStack(index: _tab, children: pages)),
        bottomNavigationBar: NavigationBar(
          selectedIndex: _tab,
          onDestinationSelected: (value) => setState(() => _tab = value),
          destinations: const [
            NavigationDestination(
              icon: Icon(Icons.home_outlined),
              selectedIcon: Icon(Icons.home_rounded),
              label: 'خانه',
            ),
            NavigationDestination(
              icon: Icon(Icons.route_outlined),
              selectedIcon: Icon(Icons.route_rounded),
              label: 'سفرها',
            ),
            NavigationDestination(
              icon: Icon(Icons.account_balance_wallet_outlined),
              selectedIcon: Icon(Icons.account_balance_wallet_rounded),
              label: 'درآمد',
            ),
            NavigationDestination(
              icon: Icon(Icons.person_outline_rounded),
              selectedIcon: Icon(Icons.person_rounded),
              label: 'حساب',
            ),
          ],
        ),
      ),
    );
  }
}

class DriverHome extends StatelessWidget {
  const DriverHome({
    super.key,
    required this.online,
    required this.changingStatus,
    required this.onToggleOnline,
  });

  final bool online;
  final bool changingStatus;
  final VoidCallback onToggleOnline;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
      children: [
        _DriverHeader(online: online),
        const SizedBox(height: 18),
        _OnlineCard(
          online: online,
          changingStatus: changingStatus,
          onTap: onToggleOnline,
        ),
        const SizedBox(height: 16),
        const Row(
          children: [
            Expanded(child: _StatCard(title: 'درآمد امروز', value: '۰ تومان', icon: Icons.payments_outlined)),
            SizedBox(width: 10),
            Expanded(child: _StatCard(title: 'سفر امروز', value: '۰ سفر', icon: Icons.local_taxi_outlined)),
          ],
        ),
        const SizedBox(height: 10),
        const Row(
          children: [
            Expanded(child: _StatCard(title: 'امتیاز', value: '—', icon: Icons.star_outline_rounded)),
            SizedBox(width: 10),
            Expanded(child: _StatCard(title: 'کیف پول', value: '۰ تومان', icon: Icons.account_balance_wallet_outlined)),
          ],
        ),
        const SizedBox(height: 18),
        _RequestCard(online: online),
        const SizedBox(height: 18),
        const Text('دسترسی سریع', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
        const SizedBox(height: 10),
        const Row(
          children: [
            Expanded(child: _QuickAction(icon: Icons.support_agent_rounded, title: 'پشتیبانی')),
            SizedBox(width: 10),
            Expanded(child: _QuickAction(icon: Icons.receipt_long_rounded, title: 'گزارش مالی')),
            SizedBox(width: 10),
            Expanded(child: _QuickAction(icon: Icons.directions_car_filled_outlined, title: 'خودرو')),
          ],
        ),
      ],
    );
  }
}

class _DriverHeader extends StatelessWidget {
  const _DriverHeader({required this.online});
  final bool online;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(18),
          child: Image.asset('assets/branding/rado-driver.png', width: 58, height: 58, fit: BoxFit.cover),
        ),
        const SizedBox(width: 12),
        const Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('RADO Driver', style: TextStyle(fontSize: 21, fontWeight: FontWeight.w900)),
              SizedBox(height: 2),
              Text('راننده رادو · بانه', style: TextStyle(color: Colors.black54, fontSize: 12)),
            ],
          ),
        ),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          decoration: BoxDecoration(
            color: online ? const Color(0xFFE6F7EC) : const Color(0xFFF0F0EE),
            borderRadius: BorderRadius.circular(99),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(color: online ? Colors.green : Colors.grey, shape: BoxShape.circle),
              ),
              const SizedBox(width: 6),
              Text(online ? 'آنلاین' : 'آفلاین', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
            ],
          ),
        ),
      ],
    );
  }
}

class _OnlineCard extends StatelessWidget {
  const _OnlineCard({required this.online, required this.changingStatus, required this.onTap});
  final bool online;
  final bool changingStatus;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: _black,
        borderRadius: BorderRadius.circular(26),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 18, offset: Offset(0, 10))],
      ),
      child: Row(
        children: [
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(color: online ? Colors.green : _yellow, borderRadius: BorderRadius.circular(18)),
            child: Icon(online ? Icons.wifi_tethering_rounded : Icons.power_settings_new_rounded, color: online ? Colors.white : _black, size: 30),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  online ? 'آماده دریافت سفر' : 'در حال حاضر آفلاین هستی',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 17),
                ),
                const SizedBox(height: 4),
                Text(
                  online ? 'درخواست‌های نزدیک بانه برایت ارسال می‌شوند.' : 'برای دریافت درخواست سفر، وضعیت را آنلاین کن.',
                  style: const TextStyle(color: Colors.white70, fontSize: 11, height: 1.6),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          FilledButton(
            onPressed: changingStatus ? null : onTap,
            style: FilledButton.styleFrom(
              backgroundColor: online ? Colors.white : _yellow,
              foregroundColor: _black,
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            ),
            child: changingStatus
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : Text(online ? 'آفلاین' : 'آنلاین', style: const TextStyle(fontWeight: FontWeight.w900)),
          ),
        ],
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.title, required this.value, required this.icon});
  final String title;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(21)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 23, color: _black),
          const SizedBox(height: 12),
          Text(title, style: const TextStyle(color: Colors.black54, fontSize: 11)),
          const SizedBox(height: 3),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
        ],
      ),
    );
  }
}

class _RequestCard extends StatelessWidget {
  const _RequestCard({required this.online});
  final bool online;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: online ? const [Color(0xFFFFF3C1), Color(0xFFFFFAE8)] : const [Colors.white, Color(0xFFF7F7F5)],
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: online ? _yellow : Colors.black12),
      ),
      child: Column(
        children: [
          Icon(online ? Icons.radar_rounded : Icons.local_taxi_outlined, size: 44, color: _black),
          const SizedBox(height: 10),
          Text(
            online ? 'در انتظار درخواست سفر' : 'برای شروع آنلاین شو',
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17),
          ),
          const SizedBox(height: 5),
          Text(
            online ? 'به محض دریافت سفر جدید، اطلاعات مبدا و مقصد اینجا نمایش داده می‌شود.' : 'بعد از آنلاین شدن، درخواست‌های نزدیک برایت نمایش داده می‌شوند.',
            textAlign: TextAlign.center,
            style: const TextStyle(color: Colors.black54, fontSize: 11, height: 1.7),
          ),
          if (online) ...[
            const SizedBox(height: 14),
            const LinearProgressIndicator(minHeight: 3, borderRadius: BorderRadius.all(Radius.circular(99))),
          ],
        ],
      ),
    );
  }
}

class _QuickAction extends StatelessWidget {
  const _QuickAction({required this.icon, required this.title});
  final IconData icon;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 15, horizontal: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(19)),
      child: Column(
        children: [
          Icon(icon, color: _black),
          const SizedBox(height: 7),
          Text(title, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
        ],
      ),
    );
  }
}

class DriverTrips extends StatelessWidget {
  const DriverTrips({super.key});
  @override
  Widget build(BuildContext context) => const _EmptyPage(
        title: 'سفرهای من',
        subtitle: 'سفرهای انجام‌شده و لغوشده اینجا نمایش داده می‌شوند.',
        icon: Icons.route_rounded,
      );
}

class DriverEarnings extends StatelessWidget {
  const DriverEarnings({super.key});
  @override
  Widget build(BuildContext context) => const _EmptyPage(
        title: 'درآمد و کیف پول',
        subtitle: 'درآمد سفرها، کمیسیون و تسویه‌ها را از این بخش می‌بینی.',
        icon: Icons.account_balance_wallet_rounded,
      );
}

class DriverAccount extends StatelessWidget {
  const DriverAccount({super.key});
  @override
  Widget build(BuildContext context) => const _EmptyPage(
        title: 'حساب راننده',
        subtitle: 'اطلاعات شخصی، خودرو، مدارک و پشتیبانی در این بخش قرار می‌گیرند.',
        icon: Icons.person_rounded,
      );
}

class _EmptyPage extends StatelessWidget {
  const _EmptyPage({required this.title, required this.subtitle, required this.icon});
  final String title;
  final String subtitle;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 78,
              height: 78,
              decoration: BoxDecoration(color: const Color(0xFFFFE59A), borderRadius: BorderRadius.circular(26)),
              child: Icon(icon, size: 38, color: _black),
            ),
            const SizedBox(height: 18),
            Text(title, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 22)),
            const SizedBox(height: 8),
            Text(subtitle, textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54, height: 1.7)),
          ],
        ),
      ),
    );
  }
}
