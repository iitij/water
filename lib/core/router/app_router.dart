import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../auth/session_store.dart';
import '../di/injection_container.dart';
import '../widgets/finance_scaffold.dart';
import 'finance_routes.dart';

/// ⚠️ نسخة تعاقدية من `core/router/app_router.dart` — المالك الفعلي **Dev 1**.
///
/// الهدف: تشغيل شريحة Dev 3 كاملة (تسجيل دخول ← محصل سريع ← فواتير ← سندات ←
/// تسعيرات) بدون انتظار دمج فرع `feat/mohammed-core-auth-api`. عند الدمج تُحذف
/// هذه الدالة ويُستعان بـ `buildFinanceRoutes()` المصدَّرة من `finance_routes.dart`
/// لإضافتها إلى `routes` الخاص بـ Dev 1.
class FinanceAppRouter {
  FinanceAppRouter._();

  static final GlobalKey<NavigatorState> rootNavigatorKey =
      GlobalKey<NavigatorState>(debugLabel: 'finance-root');

  static GoRouter create({
    List<RouteBase> extraRoutes = const <RouteBase>[],
    String initialLocation = '/collector',
    String loginLocation = '/login',
    bool wrapInShell = false,
  }) {
    return GoRouter(
      navigatorKey: rootNavigatorKey,
      initialLocation: initialLocation,
      debugLogDiagnostics: false,
      redirect: (context, state) {
        final loggedIn = sl<SessionStore>().isLoggedIn;
        final atLogin = state.matchedLocation == loginLocation;
        if (!loggedIn && !atLogin) return loginLocation;
        if (loggedIn && atLogin) return initialLocation;
        return null;
      },
      errorBuilder: (context, state) => Scaffold(
        appBar: AppBar(title: const Text('مسار غير معروف')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              'لا توجد شاشة لهذا المسار:\n${state.uri}',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 13),
            ),
          ),
        ),
      ),
      routes: <RouteBase>[
        ...extraRoutes,
        // إن لم يوفّر Dev 1 مسار /login بعد، نضع بديلاً يشرح الحالة بدل التيه.
        if (!extraRoutes.any((route) => route.path == loginLocation))
          GoRoute(
            path: loginLocation,
            name: 'finance-login-placeholder',
            builder: (context, state) => const MissingLoginPlaceholder(),
          ),
        if (wrapInShell)
          ShellRoute(
            builder: (context, state, child) =>
                FinanceShell(state: state, child: child),
            routes: buildFinanceRoutes(),
          )
        else
          ...buildFinanceRoutes(),
      ],
    );
  }
}

/// شاشة احتياطية عند غياب شاشة الدخول الحقيقية (Dev 1) — تعرض إرشاداً واضحاً
/// بدل شاشة فارغة، وتُستبدل تلقائياً بمسار `/login` في `extraRoutes`.
class MissingLoginPlaceholder extends StatelessWidget {
  const MissingLoginPlaceholder({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تسجيل الدخول')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const Icon(Icons.lock_open, size: 40),
              const SizedBox(height: 12),
              const Text(
                'شاشة تسجيل الدخول يوفّرها Dev 1 (`core/auth`).',
                textAlign: TextAlign.center,
                style: TextStyle(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              const Text(
                'لتجربة شريحة المالية فوراً: مرّر مسار /login حقيقي في extraRoutes، '
                'أو سجّل الرمز في SharedPreferences بالمفتاح auth.token.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
