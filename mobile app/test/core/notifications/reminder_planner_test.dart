import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/notifications/app_reminders.dart';

/// التذكيرات المحلية (S7-09).
///
/// الإشعار لا يُختبر على الجهاز هنا، لكن حساب موعده يُختبر بدقة: وقتٌ خاطئ
/// يعني تذكيراً لا يصل أو يصلاً بعد انتهاء الدوام.
void main() {
  group('ReminderSetting', () {
    test('defaults تفعّل كل شيء إلا النسخة الأسبوعية', () {
      expect(ReminderSetting.defaults(ReminderKind.readingDaily).enabled, isTrue);
      expect(ReminderSetting.defaults(ReminderKind.backupWeekly).enabled, isFalse);
      expect(ReminderSetting.defaults(ReminderKind.cashClose).hour, 20);
    });

    test('toJson/fromJson يحافظان على النوع والوقت والتشغيل', () {
      const original = ReminderSetting(
        kind: ReminderKind.cashClose,
        enabled: false,
        hour: 21,
        minute: 15,
      );
      final restored = ReminderSetting.fromJson(original.toJson());
      expect(restored.kind, ReminderKind.cashClose);
      expect(restored.enabled, isFalse);
      expect(restored.timeLabel, '21:15');
    });

    test('نوع مجهول في التخزين لا يُسقط الشاشة', () {
      final restored = ReminderSetting.fromJson(<String, dynamic>{'kind': 'قديم', 'enabled': true});
      expect(restored.kind, ReminderKind.readingDaily);
      expect(restored.enabled, isTrue);
    });

    test('timeLabel يُصفّر الخانات', () {
      expect(ReminderSetting.defaults(ReminderKind.readingDaily).timeLabel, '08:00');
    });
  });

  group('ReminderPreferences', () {
    test('fromJson يكمّل الأنواع الغائبة بالافتراضي', () {
      final preferences = ReminderPreferences.fromJson(<dynamic>[
        <String, dynamic>{'kind': 'readingDaily', 'enabled': false, 'hour': 6, 'minute': 30},
      ]);
      expect(preferences.settings, hasLength(ReminderKind.values.length));
      expect(preferences.of(ReminderKind.readingDaily).enabled, isFalse);
      expect(preferences.of(ReminderKind.readingDaily).timeLabel, '06:30');
      expect(preferences.of(ReminderKind.cashClose).hour, 20);
    });

    test('قائمة فارغة تُرجع الافتراضيات', () {
      expect(ReminderPreferences.fromJson(const <dynamic>[]).settings,
          hasLength(ReminderKind.values.length));
    });

    test('withSetting يستبدل نوعاً واحداً فقط', () {
      final base = ReminderPreferences.defaults();
      final updated = base.withSetting(
        base.of(ReminderKind.invoicesDaily).copyWith(hour: 9, minute: 45),
      );
      expect(updated.of(ReminderKind.invoicesDaily).timeLabel, '09:45');
      expect(updated.of(ReminderKind.cashClose).timeLabel, '20:00');
      expect(updated.settings, hasLength(base.settings.length));
    });

    test('enabled يحصر المفعّل فقط', () {
      final enabled = ReminderPreferences.defaults().enabled;
      expect(enabled.map((setting) => setting.kind), isNot(contains(ReminderKind.backupWeekly)));
      expect(enabled, hasLength(3));
    });
  });

  group('ReminderPlanner', () {
    test('موعد اليوم يبقى اليوم إن لم يمضِ', () {
      final now = DateTime(2026, 10, 24, 7, 0);
      final planned = ReminderPlanner.plan(ReminderPreferences.defaults(), now);
      final reading = planned.firstWhere((item) => item.kind == ReminderKind.readingDaily);
      expect(reading.scheduledAt, DateTime(2026, 10, 24, 8, 0));
      expect(reading.repeatsDaily, isTrue);
    });

    test('موعد مضى ينتقل إلى الغد', () {
      final now = DateTime(2026, 10, 24, 9, 30);
      final planned = ReminderPlanner.plan(ReminderPreferences.defaults(), now);
      final reading = planned.firstWhere((item) => item.kind == ReminderKind.readingDaily);
      expect(reading.scheduledAt, DateTime(2026, 10, 25, 8, 0));
    });

    test('الوقت الحالي بالضبط يُعدّ ماضياً (لتفادي إطلاق فوري)', () {
      final now = DateTime(2026, 10, 24, 13, 0);
      final planned = ReminderPlanner.plan(ReminderPreferences.defaults(), now);
      final invoices = planned.firstWhere((item) => item.kind == ReminderKind.invoicesDaily);
      expect(invoices.scheduledAt, DateTime(2026, 10, 25, 13, 0));
    });

    test('النسخة الأسبوعية تصل إلى الجمعة القادمة فقط', () {
      final preferences = ReminderPreferences.defaults().withSetting(
        ReminderSetting.defaults(ReminderKind.backupWeekly).copyWith(enabled: true),
      );
      // السبت 2026-10-24 → الجمعة التالية 2026-10-30
      final planned = ReminderPlanner.plan(preferences, DateTime(2026, 10, 24, 10, 0));
      final backup = planned.firstWhere((item) => item.kind == ReminderKind.backupWeekly);
      expect(backup.scheduledAt.weekday, DateTime.friday);
      expect(backup.scheduledAt, DateTime(2026, 10, 30, 17, 0));
      expect(backup.repeatsDaily, isFalse);
      expect(backup.weekday, DateTime.friday);
    });

    test('النسخة الأسبوعية اليوم وقبل الوقت تُجدول اليوم نفسه', () {
      final preferences = ReminderPreferences.defaults().withSetting(
        ReminderSetting.defaults(ReminderKind.backupWeekly).copyWith(enabled: true),
      );
      // الجمعة 2026-10-30 الساعة 10:00
      final planned = ReminderPlanner.plan(preferences, DateTime(2026, 10, 30, 10, 0));
      final backup = planned.firstWhere((item) => item.kind == ReminderKind.backupWeekly);
      expect(backup.scheduledAt, DateTime(2026, 10, 30, 17, 0));
    });

    test('المعطّل لا يُجدول إطلاقاً', () {
      final preferences = ReminderPreferences(
        settings: ReminderKind.values
            .map((kind) => ReminderSetting.defaults(kind).copyWith(enabled: false))
            .toList(),
      );
      expect(ReminderPlanner.plan(preferences, DateTime(2026, 10, 24, 10, 0)), isEmpty);
    });

    test('النتيجة مرتبة زمنياً ومعرّفاتها ثابتة', () {
      final planned = ReminderPlanner.plan(
        ReminderPreferences.defaults(),
        DateTime(2026, 10, 24, 0, 30),
      );
      final times = planned.map((item) => item.scheduledAt).toList();
      final sorted = List<DateTime>.from(times)..sort();
      expect(times, sorted);
      expect(planned.map((item) => item.id).toSet(), hasLength(planned.length));
      expect(planned.first.id, ReminderKind.readingDaily.notificationId);
    });

    test('وقت مخصّص يُحترم', () {
      final preferences = ReminderPreferences.defaults().withSetting(
        ReminderSetting.defaults(ReminderKind.readingDaily).copyWith(hour: 5, minute: 15),
      );
      final planned = ReminderPlanner.plan(preferences, DateTime(2026, 10, 24, 0, 1));
      expect(
        planned.firstWhere((item) => item.kind == ReminderKind.readingDaily).scheduledAt,
        DateTime(2026, 10, 24, 5, 15),
      );
    });

    test('نصوص التذكيرات عربية وغير فارغة', () {
      for (final kind in ReminderKind.values) {
        expect(kind.title.trim(), isNotEmpty);
        expect(kind.body.trim(), isNotEmpty);
        expect(kind.body, isNot(contains('TODO')));
      }
    });
  });
}
