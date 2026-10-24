import 'package:flutter/foundation.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import 'core/config/app_config.dart';
import 'core/di/injection_container.dart';
import 'core/diagnostics/api_log_manager.dart';
import 'core/diagnostics/app_bloc_observer.dart';
import 'core/notifications/notification_service.dart';
import 'core/theme/app_theme.dart';

Future<void> bootstrap() async {
  // تفعيل مراقبة جميع Blocs و Cubits على مستوى التطبيق
  Bloc.observer = AppBlocObserver();

  // التقاط جميع أخطاء إطار عمل Flutter وواجهات المستخدم
  FlutterError.onError = (FlutterErrorDetails details) {
    ApiLogManager.instance.logAppError(
      'flutter_ui',
      details.exception,
      stackTrace: details.stack,
    );
    FlutterError.presentError(details);
  };

  // التقاط الأخطاء غير المعالجة في المهام غير المتزامنة (Async / Isolates)
  PlatformDispatcher.instance.onError = (Object error, StackTrace stack) {
    ApiLogManager.instance.logAppError(
      'platform_async',
      error,
      stackTrace: stack,
    );
    return true;
  };

  await configureDependencies(appVersion: AppConfig.appVersion);
  await getIt<AppThemeController>().initialize();
  await _applyReminders();
}

/// يعيد جدولة التذكيرات المحلية عند كل إقلاع (S7-09).
///
/// إعادة الجدولة مطلوبة لا اختيارية: أندرويد يُلغي الإشعارات المجدولة عند
/// إعادة تشغيل الجهاز، والتطبيق لا يعمل حينها ليعيدها.
Future<void> _applyReminders() async {
  try {
    final store = getIt<ReminderPreferencesStore>();
    final preferences = await store.load();
    await applyReminderSchedule(
      scheduler: getIt<ReminderScheduler>(),
      preferences: preferences,
      now: DateTime.now(),
    );
  } catch (_) {
    // فشل الإشعارات لا يجوز أن يمنع فتح التطبيق.
  }
}
