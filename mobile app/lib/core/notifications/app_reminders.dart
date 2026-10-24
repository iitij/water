import 'package:equatable/equatable.dart';

/// تذكيرات محلية مجدولة على الجهاز (S7-09).
///
/// لا يوجد إشعار من الخادم: كل شيء يُحسب على الجهاز بمنطقة زمنية ثابتة
/// (اليمن UTC+3، بلا توقيت صيفي) حتى لا ينزاح الإشعار ساعةً في الصيف.
enum ReminderKind { readingDaily, invoicesDaily, cashClose, backupWeekly }

extension ReminderKindInfo on ReminderKind {
  /// معرّف الإشعار في النظام — ثابت ولا يتغيّر حتى نستطيع إلغاءه.
  int get notificationId => switch (this) {
        ReminderKind.readingDaily => 1001,
        ReminderKind.invoicesDaily => 1002,
        ReminderKind.cashClose => 1003,
        ReminderKind.backupWeekly => 1004,
      };

  String get title => switch (this) {
        ReminderKind.readingDaily => 'قراءات اليوم',
        ReminderKind.invoicesDaily => 'فواتير مستحقة',
        ReminderKind.cashClose => 'إقفال الصندوق',
        ReminderKind.backupWeekly => 'نسخة احتياطية',
      };

  String get body => switch (this) {
        ReminderKind.readingDaily => 'لم تُسجّل قراءات اليوم بعد؟ افتح «القارئ الميداني» وابدأ الجولة.',
        ReminderKind.invoicesDaily => 'راجع الفواتير غير المسددة قبل نهاية اليوم، وأرسل تذكير واتساب للمتأخرين.',
        ReminderKind.cashClose => 'أقفل صندوق المحصّل الآن وسجّل العدّ الفعلي قبل نهاية اليوم.',
        ReminderKind.backupWeekly => 'احفظ نسخة احتياطية محلية للبيانات غير المزامنة.',
      };

  /// الوقت الافتراضي (24 ساعة) — يعدّله المستخدم.
  int get defaultHour => switch (this) {
        ReminderKind.readingDaily => 8,
        ReminderKind.invoicesDaily => 13,
        ReminderKind.cashClose => 20,
        ReminderKind.backupWeekly => 17,
      };

  int get defaultMinute => 0;

  /// `null` = يومي، وإلا أيام الأسبوع (1 = الاثنين … 7 = الأحد).
  Set<int>? get weekdays => this == ReminderKind.backupWeekly ? const <int>{5} : null;

  String get scheduleLabel => weekdays == null ? 'يومياً' : 'كل جمعة';
}

/// إعداد تذكير واحد: مفعّل/متوقف + ساعة ودقيقة.
class ReminderSetting extends Equatable {
  const ReminderSetting({
    required this.kind,
    this.enabled = true,
    int? hour,
    int? minute,
  })  : hour = hour ?? 0,
        minute = minute ?? 0;

  final ReminderKind kind;
  final bool enabled;
  final int hour;
  final int minute;

  factory ReminderSetting.defaults(ReminderKind kind) => ReminderSetting(
        kind: kind,
        enabled: kind != ReminderKind.backupWeekly,
        hour: kind.defaultHour,
        minute: kind.defaultMinute,
      );

  String get timeLabel =>
      '${hour.toString().padLeft(2, '0')}:${minute.toString().padLeft(2, '0')}';

  ReminderSetting copyWith({bool? enabled, int? hour, int? minute}) => ReminderSetting(
        kind: kind,
        enabled: enabled ?? this.enabled,
        hour: hour ?? this.hour,
        minute: minute ?? this.minute,
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'kind': kind.name,
        'enabled': enabled,
        'hour': hour,
        'minute': minute,
      };

  factory ReminderSetting.fromJson(Map<String, dynamic> json) {
    final kind = ReminderKind.values.firstWhere(
      (value) => value.name == json['kind'],
      orElse: () => ReminderKind.readingDaily,
    );
    return ReminderSetting(
      kind: kind,
      enabled: json['enabled'] == true,
      hour: (json['hour'] as num?)?.toInt() ?? kind.defaultHour,
      minute: (json['minute'] as num?)?.toInt() ?? kind.defaultMinute,
    );
  }

  @override
  List<Object?> get props => <Object?>[kind, enabled, hour, minute];
}

/// الإعدادات كاملةً — تُكتب في SharedPreferences.
class ReminderPreferences extends Equatable {
  const ReminderPreferences({required this.settings});

  final List<ReminderSetting> settings;

  factory ReminderPreferences.defaults() => ReminderPreferences(
        settings: ReminderKind.values.map(ReminderSetting.defaults).toList(growable: false),
      );

  ReminderSetting of(ReminderKind kind) =>
      settings.firstWhere((setting) => setting.kind == kind, orElse: () => ReminderSetting.defaults(kind));

  List<ReminderSetting> get enabled =>
      settings.where((setting) => setting.enabled).toList(growable: false);

  ReminderPreferences withSetting(ReminderSetting updated) => ReminderPreferences(
        settings: settings
            .map((setting) => setting.kind == updated.kind ? updated : setting)
            .toList(growable: false),
      );

  List<Map<String, dynamic>> toJson() =>
      settings.map((setting) => setting.toJson()).toList(growable: false);

  factory ReminderPreferences.fromJson(List<dynamic> json) {
    if (json.isEmpty) return ReminderPreferences.defaults();
    final parsed = json.whereType<Map>().map((item) {
      return ReminderSetting.fromJson(Map<String, dynamic>.from(item));
    }).toList();
    // نضمن وجود كل نوع حتى لو كانت النسخة المحفوظة قديمة.
    final byKind = <ReminderKind, ReminderSetting>{
      for (final setting in parsed) setting.kind: setting,
    };
    return ReminderPreferences(
      settings: ReminderKind.values
          .map((kind) => byKind[kind] ?? ReminderSetting.defaults(kind))
          .toList(growable: false),
    );
  }

  @override
  List<Object?> get props => <Object?>[settings];
}

/// تذكير محسوب: متى يُطلق وماذا يقول.
class PlannedReminder extends Equatable {
  const PlannedReminder({
    required this.kind,
    required this.scheduledAt,
    required this.title,
    required this.body,
    this.repeatsDaily = true,
    this.weekday,
  });

  final ReminderKind kind;
  final DateTime scheduledAt;
  final String title;
  final String body;
  final bool repeatsDaily;

  /// 1 = الاثنين … 7 = الأحد (نمط `DateTime.weekday`).
  final int? weekday;

  int get id => kind.notificationId;

  @override
  List<Object?> get props => <Object?>[kind, scheduledAt, repeatsDaily, weekday];
}

/// حساب مواعيد التذكيرات — منطق نقي بلا إشعارات ولا تخزين، حتى يُختبر وحده.
class ReminderPlanner {
  ReminderPlanner._();

  /// يحوّل الوقت المحلي المطلوب إلى أقرب موعد قادم (اليوم إن لم يمضِ، وإلا غداً).
  ///
  /// [now] يُمرَّر صراحةً في الاختبارات.
  static List<PlannedReminder> plan(ReminderPreferences preferences, DateTime now) {
    final planned = <PlannedReminder>[];
    for (final setting in preferences.enabled) {
      final weekdays = setting.kind.weekdays;
      final next = weekdays == null
          ? _nextDaily(now, setting.hour, setting.minute)
          : _nextWeekly(now, weekdays, setting.hour, setting.minute);
      planned.add(PlannedReminder(
        kind: setting.kind,
        scheduledAt: next,
        title: setting.kind.title,
        body: setting.kind.body,
        repeatsDaily: weekdays == null,
        weekday: weekdays?.first,
      ));
    }
    planned.sort((a, b) => a.scheduledAt.compareTo(b.scheduledAt));
    return planned;
  }

  static DateTime _nextDaily(DateTime now, int hour, int minute) {
    final today = DateTime(now.year, now.month, now.day, hour, minute);
    return today.isAfter(now) ? today : today.add(const Duration(days: 1));
  }

  static DateTime _nextWeekly(DateTime now, Set<int> weekdays, int hour, int minute) {
    for (var offset = 0; offset <= 7; offset++) {
      final candidate = DateTime(now.year, now.month, now.day, hour, minute)
          .add(Duration(days: offset));
      if (!weekdays.contains(candidate.weekday)) continue;
      if (candidate.isAfter(now)) return candidate;
    }
    // احتياطي: بعد أسبوع من أول يوم مطلوب (لا يحدث عملياً).
    return DateTime(now.year, now.month, now.day, hour, minute).add(const Duration(days: 7));
  }
}
