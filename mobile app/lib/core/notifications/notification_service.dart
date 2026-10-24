import 'dart:convert';

import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:timezone/data/latest_all.dart' as tz_data;
import 'package:timezone/timezone.dart' as tz;

import 'app_reminders.dart';

/// واجهة الجدولة — تفصل الشاشة عن الإضافة الأصلية، فتُختبر بلا جهاز.
abstract interface class ReminderScheduler {
  Future<void> initialize();

  /// هل يسمح النظام بالإشعارات؟ (أندرويد 13+ وiOS يطلبانها صراحةً)
  Future<bool> requestPermission();

  Future<void> schedule(PlannedReminder reminder);

  Future<void> cancel(int id);

  Future<void> cancelAll();

  Future<void> showNow({required String title, required String body});
}

/// جدولة حقيقية عبر `flutter_local_notifications`.
///
/// المنطقة الزمنية: اليمن (Asia/Aden، UTC+3 بلا توقيت صيفي)، ويُسمح بتجاوزها
/// لاختبار أو لبلد آخر عبر [timeZoneName].
class FlutterReminderScheduler implements ReminderScheduler {
  FlutterReminderScheduler({
    FlutterLocalNotificationsPlugin? plugin,
    this.timeZoneName = 'Asia/Aden',
  }) : _plugin = plugin ?? FlutterLocalNotificationsPlugin();

  static const String channelId = 'water_reminders';
  static const String channelName = 'تذكيرات العمل';
  static const String channelDescription = 'قراءات وفواتير وإقفال صندوق ونسخة احتياطية';

  final FlutterLocalNotificationsPlugin _plugin;
  final String timeZoneName;
  bool _ready = false;

  @override
  Future<void> initialize() async {
    if (_ready) return;
    tz_data.initializeTimeZones();
    try {
      tz.setLocalLocation(tz.getLocation(timeZoneName));
    } catch (_) {
      // منطقة غير معروفة → يبقى UTC، والإشعار يصل متأخراً 3 ساعات كأقصى حد.
    }

    const settings = InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      iOS: DarwinInitializationSettings(
        requestAlertPermission: false,
        requestBadgePermission: false,
        requestSoundPermission: false,
      ),
    );
    await _plugin.initialize(settings);
    _ready = true;
  }

  @override
  Future<bool> requestPermission() async {
    await initialize();
    final android = _plugin.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();
    if (android != null) {
      final granted = await android.requestNotificationsPermission();
      return granted ?? false;
    }
    final ios = _plugin.resolvePlatformSpecificImplementation<
        IOSFlutterLocalNotificationsPlugin>();
    if (ios != null) {
      final granted = await ios.requestPermissions(alert: true, badge: true, sound: true);
      return granted ?? false;
    }
    return true; // ويندوز/لينكس/ماك: لا نطلب شيئاً.
  }

  static const NotificationDetails _details = NotificationDetails(
    android: AndroidNotificationDetails(
      channelId,
      channelName,
      channelDescription: channelDescription,
      importance: Importance.defaultImportance,
      priority: Priority.defaultPriority,
    ),
    iOS: DarwinNotificationDetails(),
  );

  @override
  Future<void> schedule(PlannedReminder reminder) async {
    await initialize();
    await _plugin.zonedSchedule(
      reminder.id,
      reminder.title,
      reminder.body,
      tz.TZDateTime.from(reminder.scheduledAt, tz.local),
      _details,
      androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
      // التكرار: يومي للساعات، وأسبوعي لنفس اليوم.
      matchDateTimeComponents:
          reminder.weekday == null ? DateTimeComponents.time : DateTimeComponents.dayOfWeekAndTime,
      payload: reminder.kind.name,
    );
  }

  @override
  Future<void> cancel(int id) async {
    await initialize();
    await _plugin.cancel(id);
  }

  @override
  Future<void> cancelAll() async {
    await initialize();
    await _plugin.cancelAll();
  }

  @override
  Future<void> showNow({required String title, required String body}) async {
    await initialize();
    await _plugin.show(9999, title, body, _details);
  }
}

/// تخزين إعدادات التذكيرات في SharedPreferences.
abstract interface class ReminderPreferencesStore {
  Future<ReminderPreferences> load();

  Future<void> save(ReminderPreferences preferences);
}

class SharedPrefsReminderStore implements ReminderPreferencesStore {
  SharedPrefsReminderStore({SharedPreferences? prefs}) : _prefs = prefs;

  static const String storageKey = 'notifications.reminders';
  final SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async => _prefs ?? await SharedPreferences.getInstance();

  @override
  Future<ReminderPreferences> load() async {
    final raw = (await _store).getString(storageKey);
    if (raw == null || raw.trim().isEmpty) return ReminderPreferences.defaults();
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return ReminderPreferences.defaults();
      return ReminderPreferences.fromJson(decoded);
    } on FormatException {
      return ReminderPreferences.defaults();
    }
  }

  @override
  Future<void> save(ReminderPreferences preferences) async {
    await (await _store).setString(storageKey, jsonEncode(preferences.toJson()));
  }
}

/// تطبيق الإعدادات على النظام: إلغاء كل شيء ثم جدولة المفعّل فقط.
///
/// الإلغاء الشامل أولاً مقصود: تعديل الوقت يجب ألا يترك تذكيرين لنفس النوع.
Future<List<PlannedReminder>> applyReminderSchedule({
  required ReminderScheduler scheduler,
  required ReminderPreferences preferences,
  required DateTime now,
}) async {
  await scheduler.cancelAll();
  final planned = ReminderPlanner.plan(preferences, now);
  for (final reminder in planned) {
    await scheduler.schedule(reminder);
  }
  return planned;
}
