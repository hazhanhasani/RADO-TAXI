import 'dart:async';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'app_update.dart';

void main() => runApp(const RadoDriverApp());

const _yellow = Color(0xFFF7B500);
const _black = Color(0xFF171717);
const _surface = Color(0xFFF6F6F4);
const _apiBaseUrl = String.fromEnvironment('RADO_API_BASE_URL');

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
  final _api = DriverApi();
  int _tab = 0;
  bool _online = false;
  bool _busy = false;
  bool _loading = true;
  String? _clientId;
  DriverSession? _session;
  DriverWallet? _wallet;
  DriverOffer? _offer;
  DriverTrip? _trip;
  Timer? _poller;

  bool get _approved => _session?.approved == true;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _poller?.cancel();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      var clientId = prefs.getString('rado_driver_client_id');
      if (clientId == null || clientId.isEmpty) {
        clientId = '${DateTime.now().microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
        await prefs.setString('rado_driver_client_id', clientId);
      }
      _clientId = clientId;
      final session = await _api.session(clientId);
      if (!mounted) return;
      setState(() {
        _session = session;
        _online = session.online;
      });
      await _refresh();
      _poller = Timer.periodic(const Duration(seconds: 8), (_) => _tick());
    } catch (e) {
      _message(_api.messageFromError(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _tick() async {
    if (_clientId == null || _busy) return;
    try {
      if (_online && _approved) {
        final pos = await Geolocator.getCurrentPosition(
          locationSettings: const LocationSettings(
            accuracy: LocationAccuracy.high,
            distanceFilter: 10,
          ),
        );
        await _api.presence(
          _clientId!,
          online: true,
          lat: pos.latitude,
          lng: pos.longitude,
          heading: pos.heading.round(),
          speedKph: max(0, pos.speed * 3.6),
        );
      }
      await _refresh(silent: true);
    } catch (_) {
      // Polling errors must not throw the driver out of the app.
    }
  }

  Future<void> _refresh({bool silent = false}) async {
    final clientId = _clientId;
    if (clientId == null) return;
    try {
      final session = await _api.session(clientId);
      final state = session.approved ? await _api.offers(clientId) : null;
      final wallet = await _api.wallet(clientId);
      if (!mounted) return;
      setState(() {
        _session = session;
        _online = session.online || _online;
        _wallet = wallet;
        _offer = state?.offer;
        _trip = state?.activeTrip;
      });
    } catch (e) {
      if (!silent) _message(_api.messageFromError(e));
    }
  }

  Future<bool> _ensureLocationPermission() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      _message('برای آنلاین شدن، موقعیت مکانی گوشی را روشن کنید.');
      return false;
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      _message('اجازه دسترسی به موقعیت برای دریافت سفر لازم است.');
      return false;
    }
    return true;
  }

  Future<void> _toggleOnline() async {
    if (_busy || _clientId == null) return;
    if (!_approved) {
      _message('ابتدا حساب راننده باید در پنل مدیریت RADO تأیید شود.');
      return;
    }

    setState(() => _busy = true);
    try {
      if (_online) {
        await _api.presence(_clientId!, online: false);
        if (mounted) setState(() => _online = false);
        return;
      }
      if (!await _ensureLocationPermission()) return;
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high),
      );
      await _api.presence(
        _clientId!,
        online: true,
        lat: pos.latitude,
        lng: pos.longitude,
        heading: pos.heading.round(),
        speedKph: max(0, pos.speed * 3.6),
      );
      if (mounted) setState(() => _online = true);
      await _refresh();
    } catch (e) {
      _message(_api.messageFromError(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _answerOffer(bool accept) async {
    final offer = _offer;
    final clientId = _clientId;
    if (offer == null || clientId == null || _busy) return;
    setState(() => _busy = true);
    try {
      final trip = await _api.answerOffer(clientId, offer.offerId, accept);
      if (!mounted) return;
      setState(() {
        _offer = null;
        if (trip != null) _trip = trip;
      });
      _message(accept ? 'سفر با موفقیت پذیرفته شد.' : 'درخواست رد شد.');
      await _refresh(silent: true);
    } catch (e) {
      _message(_api.messageFromError(e));
      await _refresh(silent: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _tripAction(String action) async {
    final trip = _trip;
    final clientId = _clientId;
    if (trip == null || clientId == null || _busy) return;
    setState(() => _busy = true);
    try {
      final result = await _api.tripAction(clientId, trip.id, action);
      if (!mounted) return;
      setState(() => _trip = result.trip);
      if (action == 'complete' && result.finance != null) {
        final f = result.finance!;
        _message(
          'سفر پایان یافت؛ سهم شما ${formatMoney(f.driverNet)}، کمیسیون ${formatMoney(f.commission)}',
        );
      } else if (action == 'arrived') {
        _message('رسیدن به مبدا ثبت شد.');
      } else if (action == 'start') {
        _message('سفر شروع شد.');
      }
      await _refresh(silent: true);
    } catch (e) {
      _message(_api.messageFromError(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _navigateTo(TripPoint point) async {
    final label = Uri.encodeComponent(point.label.isEmpty ? 'مقصد RADO' : point.label);
    final uri = Uri.parse('geo:${point.lat},${point.lng}?q=${point.lat},${point.lng}($label)');
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      _message('برنامه مسیریاب در دسترس نیست.');
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
        loading: _loading,
        online: _online,
        busy: _busy,
        session: _session,
        wallet: _wallet,
        offer: _offer,
        trip: _trip,
        onToggleOnline: _toggleOnline,
        onAccept: () => _answerOffer(true),
        onReject: () => _answerOffer(false),
        onTripAction: _tripAction,
        onNavigate: _navigateTo,
        onRefresh: () => _refresh(),
      ),
      DriverTrips(trip: _trip),
      DriverEarnings(wallet: _wallet),
      DriverAccount(session: _session),
    ];

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        body: SafeArea(child: IndexedStack(index: _tab, children: pages)),
        bottomNavigationBar: NavigationBar(
          selectedIndex: _tab,
          onDestinationSelected: (value) => setState(() => _tab = value),
          destinations: const [
            NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'خانه'),
            NavigationDestination(icon: Icon(Icons.route_outlined), selectedIcon: Icon(Icons.route_rounded), label: 'سفرها'),
            NavigationDestination(icon: Icon(Icons.account_balance_wallet_outlined), selectedIcon: Icon(Icons.account_balance_wallet_rounded), label: 'درآمد'),
            NavigationDestination(icon: Icon(Icons.person_outline_rounded), selectedIcon: Icon(Icons.person_rounded), label: 'حساب'),
          ],
        ),
      ),
    );
  }
}

class DriverHome extends StatelessWidget {
  const DriverHome({
    super.key,
    required this.loading,
    required this.online,
    required this.busy,
    required this.session,
    required this.wallet,
    required this.offer,
    required this.trip,
    required this.onToggleOnline,
    required this.onAccept,
    required this.onReject,
    required this.onTripAction,
    required this.onNavigate,
    required this.onRefresh,
  });

  final bool loading;
  final bool online;
  final bool busy;
  final DriverSession? session;
  final DriverWallet? wallet;
  final DriverOffer? offer;
  final DriverTrip? trip;
  final VoidCallback onToggleOnline;
  final VoidCallback onAccept;
  final VoidCallback onReject;
  final ValueChanged<String> onTripAction;
  final ValueChanged<TripPoint> onNavigate;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => onRefresh(),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
        children: [
          _Header(online: online, approved: session?.approved == true),
          const SizedBox(height: 16),
          if (loading)
            const Center(child: Padding(padding: EdgeInsets.all(30), child: CircularProgressIndicator()))
          else ...[
            if (session?.approved != true) _ApprovalCard(status: session?.status ?? 'pending'),
            const SizedBox(height: 12),
            _OnlineCard(online: online, busy: busy, approved: session?.approved == true, onTap: onToggleOnline),
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(child: _StatCard(title: 'درآمد امروز', value: formatMoney(wallet?.todayNet ?? 0), icon: Icons.payments_outlined)),
                const SizedBox(width: 10),
                Expanded(child: _StatCard(title: 'سفر امروز', value: '${wallet?.todayTrips ?? 0} سفر', icon: Icons.local_taxi_outlined)),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(child: _StatCard(title: 'کمیسیون', value: '${wallet?.commissionRate.toStringAsFixed(1) ?? '0'}٪', icon: Icons.percent_rounded)),
                const SizedBox(width: 10),
                Expanded(child: _StatCard(title: 'کیف پول', value: formatMoney(wallet?.balance ?? 0), icon: Icons.account_balance_wallet_outlined)),
              ],
            ),
            const SizedBox(height: 16),
            if (trip != null)
              _ActiveTripCard(
                trip: trip!,
                busy: busy,
                onAction: onTripAction,
                onNavigate: onNavigate,
              )
            else if (offer != null)
              _OfferCard(offer: offer!, busy: busy, onAccept: onAccept, onReject: onReject)
            else
              _WaitingCard(online: online && session?.approved == true),
          ],
        ],
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.online, required this.approved});
  final bool online;
  final bool approved;

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
              Text('راننده رادو · بانه', style: TextStyle(color: Colors.black54, fontSize: 12)),
            ],
          ),
        ),
        _Badge(text: !approved ? 'در انتظار تایید' : online ? 'آنلاین' : 'آفلاین', active: approved && online),
      ],
    );
  }
}

class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard({required this.status});
  final String status;
  @override
  Widget build(BuildContext context) {
    final text = switch (status) {
      'rejected' => 'حساب راننده توسط مدیریت رد شده است.',
      'suspended' => 'حساب راننده موقتاً تعلیق شده است.',
      _ => 'حساب راننده ساخته شد و منتظر تایید مدیریت RADO است.',
    };
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: const Color(0xFFFFF3C1), borderRadius: BorderRadius.circular(20)),
      child: Row(children: [const Icon(Icons.verified_user_outlined), const SizedBox(width: 10), Expanded(child: Text(text, style: const TextStyle(fontWeight: FontWeight.w700)))]),
    );
  }
}

class _OnlineCard extends StatelessWidget {
  const _OnlineCard({required this.online, required this.busy, required this.approved, required this.onTap});
  final bool online;
  final bool busy;
  final bool approved;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(color: _black, borderRadius: BorderRadius.circular(26)),
      child: Row(
        children: [
          Icon(online ? Icons.wifi_tethering_rounded : Icons.power_settings_new_rounded, color: _yellow, size: 34),
          const SizedBox(width: 12),
          Expanded(child: Text(online ? 'آماده دریافت سفر' : approved ? 'برای دریافت سفر آنلاین شو' : 'پس از تایید مدیریت آنلاین شو', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900))),
          FilledButton(
            onPressed: busy || !approved ? null : onTap,
            style: FilledButton.styleFrom(backgroundColor: online ? Colors.white : _yellow, foregroundColor: _black),
            child: busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Text(online ? 'آفلاین' : 'آنلاین'),
          ),
        ],
      ),
    );
  }
}

class _OfferCard extends StatelessWidget {
  const _OfferCard({required this.offer, required this.busy, required this.onAccept, required this.onReject});
  final DriverOffer offer;
  final bool busy;
  final VoidCallback onAccept;
  final VoidCallback onReject;

  @override
  Widget build(BuildContext context) {
    return _TripBox(
      title: 'درخواست سفر جدید',
      subtitle: offer.offeredAt,
      children: [
        _LocationLine(icon: Icons.radio_button_checked, color: Colors.green, text: offer.trip.pickup.label),
        const SizedBox(height: 10),
        _LocationLine(icon: Icons.location_on, color: Colors.red, text: offer.trip.destination.label),
        const SizedBox(height: 14),
        Row(children: [
          Expanded(child: _InfoPill(label: 'کرایه', value: formatMoney(offer.trip.estimatedFare))),
          const SizedBox(width: 8),
          Expanded(child: _InfoPill(label: 'مسافت', value: offer.trip.distanceLabel)),
        ]),
        const SizedBox(height: 14),
        Row(children: [
          Expanded(child: OutlinedButton(onPressed: busy ? null : onReject, child: const Text('رد درخواست'))),
          const SizedBox(width: 10),
          Expanded(child: FilledButton(onPressed: busy ? null : onAccept, style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), child: const Text('قبول سفر'))),
        ]),
      ],
    );
  }
}

class _ActiveTripCard extends StatelessWidget {
  const _ActiveTripCard({required this.trip, required this.busy, required this.onAction, required this.onNavigate});
  final DriverTrip trip;
  final bool busy;
  final ValueChanged<String> onAction;
  final ValueChanged<TripPoint> onNavigate;

  @override
  Widget build(BuildContext context) {
    final toPickup = trip.status == 'driver_assigned' || trip.status == 'driver_arriving';
    final inProgress = trip.status == 'in_progress';
    final action = toPickup ? 'arrived' : trip.status == 'arrived' ? 'start' : inProgress ? 'complete' : null;
    final actionLabel = toPickup ? 'رسیدم' : trip.status == 'arrived' ? 'شروع سفر' : inProgress ? 'پایان سفر' : '';
    final navPoint = inProgress ? trip.destination : trip.pickup;

    return _TripBox(
      title: trip.statusFa,
      subtitle: trip.requestedAt,
      children: [
        _LocationLine(icon: Icons.radio_button_checked, color: Colors.green, text: trip.pickup.label),
        const SizedBox(height: 10),
        _LocationLine(icon: Icons.location_on, color: Colors.red, text: trip.destination.label),
        const SizedBox(height: 14),
        Row(children: [
          Expanded(child: _InfoPill(label: 'کرایه', value: formatMoney(trip.finalFare ?? trip.estimatedFare))),
          const SizedBox(width: 8),
          Expanded(child: _InfoPill(label: 'وضعیت', value: trip.statusFa)),
        ]),
        const SizedBox(height: 14),
        SizedBox(width: double.infinity, child: OutlinedButton.icon(onPressed: () => onNavigate(navPoint), icon: const Icon(Icons.navigation_rounded), label: Text(inProgress ? 'مسیریابی تا مقصد' : 'مسیریابی تا مبدا'))),
        if (action != null) ...[
          const SizedBox(height: 8),
          SizedBox(width: double.infinity, height: 52, child: FilledButton(onPressed: busy ? null : () => onAction(action), style: FilledButton.styleFrom(backgroundColor: _yellow, foregroundColor: _black), child: Text(actionLabel, style: const TextStyle(fontWeight: FontWeight.w900)))),
        ],
      ],
    );
  }
}

class _WaitingCard extends StatelessWidget {
  const _WaitingCard({required this.online});
  final bool online;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(22),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24), border: Border.all(color: online ? _yellow : Colors.black12)),
        child: Column(children: [
          Icon(online ? Icons.radar_rounded : Icons.local_taxi_outlined, size: 44),
          const SizedBox(height: 10),
          Text(online ? 'در انتظار درخواست سفر' : 'در حال حاضر آفلاین هستی', style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
          const SizedBox(height: 6),
          Text(online ? 'درخواست‌های نزدیک بانه خودکار اینجا ظاهر می‌شوند.' : 'برای دریافت سفر، وضعیت را آنلاین کن.', textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
          if (online) ...[const SizedBox(height: 14), const LinearProgressIndicator(minHeight: 3)],
        ]),
      );
}

class _TripBox extends StatelessWidget {
  const _TripBox({required this.title, required this.subtitle, required this.children});
  final String title;
  final String subtitle;
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24), boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 16, offset: Offset(0, 8))]),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
          if (subtitle.isNotEmpty) ...[const SizedBox(height: 3), Text(subtitle, style: const TextStyle(color: Colors.black54, fontSize: 11))],
          const SizedBox(height: 14),
          ...children,
        ]),
      );
}

class _LocationLine extends StatelessWidget {
  const _LocationLine({required this.icon, required this.color, required this.text});
  final IconData icon;
  final Color color;
  final String text;
  @override
  Widget build(BuildContext context) => Row(children: [Icon(icon, color: color), const SizedBox(width: 9), Expanded(child: Text(text.isEmpty ? 'آدرس ثبت نشده' : text, style: const TextStyle(fontWeight: FontWeight.w700)))]);
}

class _InfoPill extends StatelessWidget {
  const _InfoPill({required this.label, required this.value});
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: const Color(0xFFF5F5F2), borderRadius: BorderRadius.circular(15)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label, style: const TextStyle(fontSize: 10, color: Colors.black54)), const SizedBox(height: 3), Text(value, style: const TextStyle(fontWeight: FontWeight.w900))]),
      );
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.title, required this.value, required this.icon});
  final String title;
  final String value;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(15),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(21)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Icon(icon, size: 22), const SizedBox(height: 10), Text(title, style: const TextStyle(color: Colors.black54, fontSize: 11)), Text(value, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 14))]),
      );
}

class _Badge extends StatelessWidget {
  const _Badge({required this.text, required this.active});
  final String text;
  final bool active;
  @override
  Widget build(BuildContext context) => Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7), decoration: BoxDecoration(color: active ? const Color(0xFFE6F7EC) : const Color(0xFFF0F0EE), borderRadius: BorderRadius.circular(99)), child: Text(text, style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: active ? Colors.green.shade800 : Colors.black54)));
}

class DriverTrips extends StatelessWidget {
  const DriverTrips({super.key, required this.trip});
  final DriverTrip? trip;
  @override
  Widget build(BuildContext context) => _SimplePage(
        title: 'سفرهای من',
        icon: Icons.route_rounded,
        child: trip == null ? const Text('سفر فعال نداری.') : Text('${trip!.statusFa}\n${trip!.pickup.label}\n← ${trip!.destination.label}\n${trip!.requestedAt}', textAlign: TextAlign.center),
      );
}

class DriverEarnings extends StatelessWidget {
  const DriverEarnings({super.key, required this.wallet});
  final DriverWallet? wallet;
  @override
  Widget build(BuildContext context) => _SimplePage(
        title: 'درآمد و کیف پول',
        icon: Icons.account_balance_wallet_rounded,
        child: Column(children: [
          Text('موجودی: ${formatMoney(wallet?.balance ?? 0)}', style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
          const SizedBox(height: 8),
          Text('درآمد خالص امروز: ${formatMoney(wallet?.todayNet ?? 0)}'),
          Text('کمیسیون امروز: ${formatMoney(wallet?.todayCommission ?? 0)}'),
          Text('نرخ کمیسیون: ${wallet?.commissionRate.toStringAsFixed(1) ?? '0'}٪'),
          const SizedBox(height: 16),
          ...?wallet?.entries.take(8).map((e) => ListTile(dense: true, title: Text(e.title), subtitle: Text(e.jalali), trailing: Text(formatSignedMoney(e.amount)))),
        ]),
      );
}

class DriverAccount extends StatelessWidget {
  const DriverAccount({super.key, required this.session});
  final DriverSession? session;
  @override
  Widget build(BuildContext context) => _SimplePage(
        title: 'حساب راننده',
        icon: Icons.person_rounded,
        child: Column(children: [
          Text(session?.name ?? 'راننده رادو', style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
          const SizedBox(height: 6),
          Text('وضعیت: ${session?.statusFa ?? 'در انتظار تایید'}'),
          if ((session?.plate ?? '').isNotEmpty) Text('پلاک: ${session!.plate}'),
          if ((session?.vehicle ?? '').isNotEmpty) Text('خودرو: ${session!.vehicle}'),
        ]),
      );
}

class _SimplePage extends StatelessWidget {
  const _SimplePage({required this.title, required this.icon, required this.child});
  final String title;
  final IconData icon;
  final Widget child;
  @override
  Widget build(BuildContext context) => ListView(padding: const EdgeInsets.all(22), children: [
        Container(width: 76, height: 76, decoration: BoxDecoration(color: const Color(0xFFFFE59A), borderRadius: BorderRadius.circular(24)), child: Icon(icon, size: 38)),
        const SizedBox(height: 16),
        Text(title, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 22)),
        const SizedBox(height: 18),
        Card(child: Padding(padding: const EdgeInsets.all(18), child: child)),
      ]);
}

class DriverApi {
  DriverApi()
      : _dio = Dio(BaseOptions(
          baseUrl: _apiBaseUrl,
          connectTimeout: const Duration(seconds: 8),
          receiveTimeout: const Duration(seconds: 12),
          headers: const {'Accept': 'application/json'},
        ));
  final Dio _dio;

  Future<DriverSession> session(String clientId) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/driver/session', data: {'client_id': clientId});
    return DriverSession.fromJson((r.data?['driver'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  Future<void> presence(String clientId, {required bool online, double? lat, double? lng, int? heading, double? speedKph}) async {
    await _dio.post('/api/v1/driver/presence', data: {'client_id': clientId, 'online': online, 'lat': lat, 'lng': lng, 'heading': heading, 'speed_kph': speedKph});
  }

  Future<DriverState> offers(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/driver/offers', queryParameters: {'client_id': clientId});
    final data = r.data ?? const <String, dynamic>{};
    final list = (data['offers'] as List?) ?? const [];
    return DriverState(
      offer: list.isEmpty ? null : DriverOffer.fromJson((list.first as Map).cast<String, dynamic>()),
      activeTrip: data['active_trip'] is Map ? DriverTrip.fromJson((data['active_trip'] as Map).cast<String, dynamic>()) : null,
    );
  }

  Future<DriverTrip?> answerOffer(String clientId, int offerId, bool accept) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/driver/offers', data: {'client_id': clientId, 'offer_id': offerId, 'action': accept ? 'accept' : 'reject'});
    final trip = r.data?['trip'];
    return trip is Map ? DriverTrip.fromJson(trip.cast<String, dynamic>()) : null;
  }

  Future<TripActionResult> tripAction(String clientId, String tripId, String action) async {
    final r = await _dio.post<Map<String, dynamic>>('/api/v1/driver/trip', data: {'client_id': clientId, 'trip_id': tripId, 'action': action});
    final data = r.data ?? const <String, dynamic>{};
    return TripActionResult(
      trip: data['trip'] is Map ? DriverTrip.fromJson((data['trip'] as Map).cast<String, dynamic>()) : null,
      finance: data['finance'] is Map ? TripFinance.fromJson((data['finance'] as Map).cast<String, dynamic>()) : null,
    );
  }

  Future<DriverWallet> wallet(String clientId) async {
    final r = await _dio.get<Map<String, dynamic>>('/api/v1/driver/wallet', queryParameters: {'client_id': clientId});
    return DriverWallet.fromJson((r.data?['wallet'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  String messageFromError(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['message'] != null) return data['message'].toString();
      if (error.type == DioExceptionType.connectionTimeout || error.type == DioExceptionType.receiveTimeout) return 'ارتباط با سرور رادو طول کشید.';
      if (error.type == DioExceptionType.connectionError) return 'ارتباط با سرور RADO برقرار نشد.';
    }
    return 'عملیات انجام نشد؛ دوباره تلاش کنید.';
  }
}

class DriverSession {
  const DriverSession({required this.name, required this.status, required this.approved, required this.online, required this.commissionRate, required this.plate, required this.vehicle});
  final String name;
  final String status;
  final bool approved;
  final bool online;
  final double commissionRate;
  final String plate;
  final String vehicle;
  String get statusFa => switch (status) {'approved' => 'تاییدشده', 'suspended' => 'تعلیق‌شده', 'rejected' => 'ردشده', _ => 'در انتظار تایید'};
  factory DriverSession.fromJson(Map<String, dynamic> j) => DriverSession(
        name: (j['name'] ?? 'راننده رادو').toString(),
        status: (j['status'] ?? 'pending').toString(),
        approved: j['approved'] == true,
        online: j['online'] == true,
        commissionRate: (j['commission_rate'] as num?)?.toDouble() ?? 0,
        plate: (j['plate'] ?? '').toString(),
        vehicle: (j['vehicle'] ?? '').toString(),
      );
}

class DriverState {
  const DriverState({required this.offer, required this.activeTrip});
  final DriverOffer? offer;
  final DriverTrip? activeTrip;
}

class DriverOffer {
  const DriverOffer({required this.offerId, required this.trip, required this.offeredAt});
  final int offerId;
  final DriverTrip trip;
  final String offeredAt;
  factory DriverOffer.fromJson(Map<String, dynamic> j) => DriverOffer(
        offerId: (j['offer_id'] as num?)?.toInt() ?? 0,
        trip: DriverTrip.fromJson((j['trip'] as Map?)?.cast<String, dynamic>() ?? const {}),
        offeredAt: (((j['offered_at'] as Map?)?['jalali']) ?? '').toString(),
      );
}

class DriverTrip {
  const DriverTrip({required this.id, required this.status, required this.statusFa, required this.pickup, required this.destination, required this.estimatedFare, required this.finalFare, required this.distanceMeters, required this.requestedAt});
  final String id;
  final String status;
  final String statusFa;
  final TripPoint pickup;
  final TripPoint destination;
  final int estimatedFare;
  final int? finalFare;
  final int distanceMeters;
  final String requestedAt;
  String get distanceLabel => distanceMeters < 1000 ? '$distanceMeters متر' : '${(distanceMeters / 1000).toStringAsFixed(1)} کیلومتر';
  factory DriverTrip.fromJson(Map<String, dynamic> j) => DriverTrip(
        id: (j['id'] ?? '').toString(),
        status: (j['status'] ?? '').toString(),
        statusFa: (j['status_fa'] ?? '').toString(),
        pickup: TripPoint.fromJson((j['pickup'] as Map?)?.cast<String, dynamic>() ?? const {}),
        destination: TripPoint.fromJson((j['destination'] as Map?)?.cast<String, dynamic>() ?? const {}),
        estimatedFare: (j['estimated_fare'] as num?)?.toInt() ?? 0,
        finalFare: (j['final_fare'] as num?)?.toInt(),
        distanceMeters: (j['estimated_distance_m'] as num?)?.toInt() ?? 0,
        requestedAt: (((j['times'] as Map?)?['requested'] as Map?)?['jalali'] ?? '').toString(),
      );
}

class TripPoint {
  const TripPoint({required this.lat, required this.lng, required this.label});
  final double lat;
  final double lng;
  final String label;
  factory TripPoint.fromJson(Map<String, dynamic> j) => TripPoint(lat: (j['lat'] as num?)?.toDouble() ?? 0, lng: (j['lng'] as num?)?.toDouble() ?? 0, label: (j['label'] ?? '').toString());
}

class DriverWallet {
  const DriverWallet({required this.balance, required this.commissionRate, required this.todayNet, required this.todayCommission, required this.todayTrips, required this.entries});
  final int balance;
  final double commissionRate;
  final int todayNet;
  final int todayCommission;
  final int todayTrips;
  final List<WalletEntry> entries;
  factory DriverWallet.fromJson(Map<String, dynamic> j) => DriverWallet(
        balance: (j['balance'] as num?)?.toInt() ?? 0,
        commissionRate: (j['commission_rate'] as num?)?.toDouble() ?? 0,
        todayNet: (j['today_net'] as num?)?.toInt() ?? 0,
        todayCommission: (j['today_commission'] as num?)?.toInt() ?? 0,
        todayTrips: (j['today_trips'] as num?)?.toInt() ?? 0,
        entries: ((j['entries'] as List?) ?? const []).whereType<Map>().map((e) => WalletEntry.fromJson(e.cast<String, dynamic>())).toList(),
      );
}

class WalletEntry {
  const WalletEntry({required this.type, required this.amount, required this.jalali});
  final String type;
  final int amount;
  final String jalali;
  String get title => switch (type) {'trip_gross' => 'کرایه سفر', 'platform_commission' => 'کمیسیون RADO', _ => type};
  factory WalletEntry.fromJson(Map<String, dynamic> j) => WalletEntry(type: (j['type'] ?? '').toString(), amount: (j['amount'] as num?)?.toInt() ?? 0, jalali: (((j['created_at'] as Map?)?['jalali']) ?? '').toString());
}

class TripActionResult {
  const TripActionResult({required this.trip, required this.finance});
  final DriverTrip? trip;
  final TripFinance? finance;
}

class TripFinance {
  const TripFinance({required this.commission, required this.driverNet});
  final int commission;
  final int driverNet;
  factory TripFinance.fromJson(Map<String, dynamic> j) => TripFinance(commission: (j['commission'] as num?)?.toInt() ?? 0, driverNet: (j['driver_net'] as num?)?.toInt() ?? 0);
}

String formatMoney(int value) => '${_group(value)} ریال';
String formatSignedMoney(int value) => '${value >= 0 ? '+' : '-'}${_group(value.abs())} ریال';
String _group(int value) {
  final s = value.toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(',');
    b.write(s[i]);
  }
  return b.toString();
}
