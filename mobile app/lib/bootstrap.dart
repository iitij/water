import 'core/config/app_config.dart';
import 'core/di/injection_container.dart';
import 'core/notifications/notification_service.dart';
import 'core/theme/app_theme.dart';

Future<void> bootstrap() async {
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
