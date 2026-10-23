import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'core/auth/session_store.dart';
import 'core/config/app_config.dart';
import 'core/di/injection_container.dart';
import 'core/settings/finance_config.dart';

/// نقطة التهيئة: تخزين محلي ← حقن تبعيات ← تحديث إعدادات في الخلفية.
///
/// ⚠️ Dev 3 وفّر نسخة قابلة للتشغيل حتى يدمج Dev 1 نسخته من `bootstrap.dart`
/// (التي ستضيف Drift/طابور المزامنة وتوليد `injectable`).
Future<void> bootstrap(AppConfig config) async {
  WidgetsFlutterBinding.ensureInitialized();

  await SystemChrome.setPreferredOrientations(<DeviceOrientation>[
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
    DeviceOrientation.landscapeLeft,
    DeviceOrientation.landscapeRight,
  ]);

  final prefs = await SharedPreferences.getInstance();
  final session = await SessionStore.load();

  registerCoreContracts(config: config, prefs: prefs, session: session);
  registerSyncQueueFallback(prefs);
  registerFinanceModule(offlineEnabled: config.isOfflineEnabled);

  // لا ننتظر الخادم عند الإقلاع: الكاش المحلي كافٍ، والتحديث يتم في الخلفية.
  await sl<FinanceConfigRepository>().refresh(force: true);
}

/// يغلّف التطبيق بـ `ScreenUtilInit` لمقاسات متجاوبة على كل الأحجام.
Widget buildResponsiveGate({required Widget child}) => ScreenUtilInit(
      designSize: const Size(360, 690),
      minTextAdapt: true,
      splitScreenMode: true,
      builder: (context, _) => child,
    );
