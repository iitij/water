import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';

import 'core/router/app_router.dart';
import 'core/router/finance_route_names.dart';
import 'core/settings/finance_config.dart';
import 'core/di/injection_container.dart';
import 'core/theme/app_theme.dart';

/// جذر التطبيق لشريحة Dev 3 (الفواتير/الدفعات/الطباعة/الواتساب).
///
/// • RTL كامل (المشروع عربي بالكامل) مع إبقاء الأرقام LTR داخل `LtrNumberText`.
/// • تعطيل تكبير الخط النصي: أرقام الفواتير والسندات يجب أن تبقى مقروءة بالقياس
///   نفسه الذي تُطبع به (قاعدة المطابقة مع PDF).
/// • التوطين العربي عبر `flutter_localizations` (تواريخ/أرقام Material).
/// • شريط تنقل مالي رباعي: `FinanceShell` + `FinanceScaffold` (BottomNav ↔ Rail ↔ Extended).
class WaterApp extends StatefulWidget {
  const WaterApp({
    super.key,
    this.initialLocation,
    this.loginLocation,
    this.extraRoutes = const <RouteBase>[],
  });

  /// مسار البداية الافتراضي (`SCR-13` للمحصلين، `SCR-14` للمدير).
  final String? initialLocation;

  /// مسار شاشة الدخول (Dev 1). يبقى `null` عند دمج نسخة Dev 1 الحقيقية.
  final String? loginLocation;

  /// مسارات إضافية (تسجيل الدخول، لوحة التحكم) — تُمرَّر من `main.dart`.
  final List<RouteBase> extraRoutes;

  @override
  State<WaterApp> createState() => _WaterAppState();
}

class _WaterAppState extends State<WaterApp> {
  /// يُنشأ مرة واحدة: `GoRouter` يحتفظ بحالة الملاح الداخلية.
  late final GoRouter _router = FinanceAppRouter.create(
    initialLocation: widget.initialLocation ?? FinanceRouteNames.quickCollector,
    loginLocation: widget.loginLocation ?? _fallbackLoginLocation,
    extraRoutes: widget.extraRoutes,
    wrapInShell: true,
  );

  @override
  void dispose() {
    _router.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final config = sl<FinanceConfigRepository>().cached;

    return MaterialApp.router(
      title: config.projectName,
      debugShowCheckedModeBanner: false,
      routerConfig: _router,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: ThemeMode.system,
      locale: const Locale('ar'),
      supportedLocales: const <Locale>[Locale('ar'), Locale('en')],
      localizationsDelegates: const <LocalizationsDelegate<dynamic>>[
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: MediaQuery.withClampedTextScaling(
          minScaleFactor: 1,
          maxScaleFactor: 1.25,
          child: child ?? const SizedBox.shrink(),
        ),
      ),
    );
  }

  static const String _fallbackLoginLocation = '/login';
}

