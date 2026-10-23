import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'core/config/app_config.dart';
import 'core/di.dart';
import 'core/theme/app_colors.dart';
import 'features/service_requests/presentation/routes.dart';
import 'features/squares/presentation/routes.dart';
import 'features/subscribers/domain/repositories/subscribers_repository.dart';
import 'features/subscribers/presentation/routes.dart';
import 'features/subscription_fees/presentation/routes.dart';
import 'features/transactions/presentation/routes.dart';

class WaterFouadApp extends StatelessWidget {
  const WaterFouadApp({super.key, required this.deps});
  final FouadDependencies deps;

  @override
  Widget build(BuildContext context) {
    return RepositoryProvider<SubscribersRepository>.value(
      value: deps.subscribers,
      child: MaterialApp(
        title: 'مشروع المياه — وحدات فؤاد',
        debugShowCheckedModeBanner: false,
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: ThemeData(
          useMaterial3: true,
          colorScheme: ColorScheme.fromSeed(seedColor: AppColors.primary),
          scaffoldBackgroundColor: AppColors.background,
          fontFamily: 'Tajawal',
        ),
        routes: {
          '/': (_) => const _HomeShell(),
          ...SquaresRoutes.routes(deps),
          ...SubscribersRoutes.routes(deps),
          ...SubscriptionFeesRoutes.routes(deps),
          ...ServiceRequestsRoutes.routes(deps),
          ...TransactionsRoutes.routes(deps),
        },
        initialRoute: '/',
      ),
    );
  }
}

/// هيكل تنقل رئيسي لوحدات فؤاد (NavigationRail عند >= 600dp — الخطة 3.3).
class _HomeShell extends StatefulWidget {
  const _HomeShell();
  @override
  State<_HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<_HomeShell> {
  int _index = 0;

  static const _items = [
    (icon: Icons.grid_view, label: 'المربعات', route: SquaresRoutes.squares, code: 'SCR-04'),
    (icon: Icons.people, label: 'المشتركون', route: SubscribersRoutes.subscribers, code: 'SCR-05'),
    (icon: Icons.card_membership, label: 'رسوم الاشتراك', route: SubscriptionFeesRoutes.fees, code: 'SCR-19'),
    (icon: Icons.local_shipping, label: 'طلبات الوايت', route: ServiceRequestsRoutes.requests, code: 'SCR-20'),
    (icon: Icons.account_balance, label: 'دفتر الأستاذ', route: TransactionsRoutes.transactions, code: 'SCR-22'),
  ];

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 600;
    final destinations = [
      for (final it in _items) NavigationDestination(icon: Icon(it.icon), label: it.label),
    ];
    final railDestinations = [
      for (final it in _items) NavigationRailDestination(icon: Icon(it.icon), label: Text(it.label)),
    ];

    final body = ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          color: AppColors.primaryDark,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('وحدات المهندس فؤاد (Dev 4)', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
              const SizedBox(height: 4),
              Text(
                AppConfig.demoMode
                    ? 'وضع العرض التجريبي (بيانات وهمية) — للاتصال بالخادم: --dart-define=DEMO=false --dart-define=API_BASE_URL=...'
                    : 'متصل بالخادم: ${AppConfig.baseUrl}',
                style: const TextStyle(color: Colors.white70, fontSize: 12),
              ),
            ]),
          ),
        ),
        const SizedBox(height: 12),
        for (final it in _items)
          Card(
            child: ListTile(
              leading: Icon(it.icon, color: AppColors.primary),
              title: Text(it.label),
              subtitle: Text(it.code),
              trailing: const Icon(Icons.arrow_forward_ios, size: 16),
              onTap: () => Navigator.of(context).pushNamed(it.route),
            ),
          ),
        const SizedBox(height: 8),
        const Text(
          'ملف المشترك 360° (SCR-07) يُفتح من بطاقة أي مشترك في SCR-05، ونموذج المشترك (SCR-06) من زر «مشترك جديد».',
          style: TextStyle(color: AppColors.neutral, fontSize: 12),
        ),
      ],
    );

    if (wide) {
      return Scaffold(
        appBar: AppBar(title: const Text('إدارة مشاريع المياه')),
        body: Row(children: [
          NavigationRail(
            selectedIndex: _index,
            onDestinationSelected: (i) {
              setState(() => _index = i);
              Navigator.of(context).pushNamed(_items[i].route);
            },
            destinations: railDestinations,
          ),
          const VerticalDivider(width: 1),
          Expanded(child: body),
        ]),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('إدارة مشاريع المياه')),
      body: body,
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) {
          setState(() => _index = i);
          Navigator.of(context).pushNamed(_items[i].route);
        },
        destinations: destinations,
      ),
    );
  }
}
