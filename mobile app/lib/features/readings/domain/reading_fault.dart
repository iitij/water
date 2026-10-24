/// «القراءة المتعذّرة» — ماذا يفعل المحصّل حين لا يستطيع قراءة العداد؟
///
/// الخادم لا يقبل قراءة ناقصة (current_reading إلزامية وأكبر من السابقة)،
/// فالتعذّر لا يُرسل كقراءة. نسجّله محلياً على الجهاز مع السبب، فيبقى العداد
/// ظاهراً للمشرف كمتأخر بدل أن يُخترع له رقم وهمي أو يُهمَل بصمت.
///
/// التخزين محلي فقط لأن الرفع يحتاج مساراً خادمياً غير موجود في v2.0؛ وهذا
/// مذكور صراحة في تقرير البطاقة S3-04 (لا ندّعي مزامنة لا تحدث).
library;

/// سبب تعذّر القراءة. القيم تُخزَّن نصاً حتى تبقى البيانات مقروءة بعد الترقية.
enum ReadingFaultReason {
  /// العداد متعطّل أو مكسور (العدّاد لا يدور/الزجاج مكسور).
  meterBroken,

  /// العداد مغلق أو الوصول إليه متعذّر (باب مغلق، عقار خالٍ).
  meterLocked,

  /// المشترك غير موجود أو رفض القراءة.
  subscriberAbsent,

  /// سبب آخر يكتبه المحصّل بنفسه.
  other,
}

extension ReadingFaultReasonX on ReadingFaultReason {
  /// القيمة المخزّنة/المُرسلة.
  String get code {
    if (this == ReadingFaultReason.meterBroken) return 'meter_broken';
    if (this == ReadingFaultReason.meterLocked) return 'meter_locked';
    if (this == ReadingFaultReason.subscriberAbsent) return 'subscriber_absent';
    return 'other';
  }

  /// نص عربي جاهز للعرض.
  String get label {
    if (this == ReadingFaultReason.meterBroken) return 'العداد معطّل';
    if (this == ReadingFaultReason.meterLocked) return 'تعذّر الوصول للعداد';
    if (this == ReadingFaultReason.subscriberAbsent) return 'المشترك غير موجود';
    return 'سبب آخر';
  }

  /// هل يلزم نص حر مع هذا السبب؟
  bool get needsNote => this == ReadingFaultReason.other;

  static ReadingFaultReason fromCode(String? code) {
    if (code == 'meter_broken') return ReadingFaultReason.meterBroken;
    if (code == 'meter_locked') return ReadingFaultReason.meterLocked;
    if (code == 'subscriber_absent') return ReadingFaultReason.subscriberAbsent;
    return ReadingFaultReason.other;
  }
}

/// سطر تعذّر قراءة واحد. `day` مشتق من `recordedAt` ولا يُخزَّن منفصلاً.
class ReadingFault {
  const ReadingFault({
    required this.meterId,
    required this.reason,
    required this.recordedAt,
    this.meterCode,
    this.subscriberName,
    this.squareId,
    this.note,
  });

  final int meterId;
  final ReadingFaultReason reason;
  final DateTime recordedAt;
  final String? meterCode;
  final String? subscriberName;
  final int? squareId;
  final String? note;

  /// يوم الجولة (بلا وقت) — يُستخدم لتجميع تقرير المسار.
  DateTime get day => DateTime(recordedAt.year, recordedAt.month, recordedAt.day);

  /// ملخّص قصير يظهر في القائمة.
  String get summary {
    final parts = <String>[reason.label];
    if (note != null && note!.trim().isNotEmpty) parts.add(note!.trim());
    return parts.join(' — ');
  }

  Map<String, Object?> toJson() => <String, Object?>{
        'meter_id': meterId,
        'reason': reason.code,
        'recorded_at': recordedAt.toIso8601String(),
        'meter_code': meterCode,
        'subscriber_name': subscriberName,
        'square_id': squareId,
        'note': note,
      };

  factory ReadingFault.fromJson(Map<String, Object?> json) => ReadingFault(
        meterId: (json['meter_id'] as num?)?.toInt() ?? 0,
        reason: ReadingFaultReasonX.fromCode(json['reason'] as String?),
        recordedAt: DateTime.tryParse(json['recorded_at'] as String? ?? '') ?? DateTime.now(),
        meterCode: json['meter_code'] as String?,
        subscriberName: json['subscriber_name'] as String?,
        squareId: (json['square_id'] as num?)?.toInt(),
        note: json['note'] as String?,
      );

  /// دمج التعذّر مع سجلّه: آخر تعذّر لنفس العداد في نفس اليوم يستبدل القديم.
  ReadingFault copyWith({ReadingFaultReason? reason, String? note}) => ReadingFault(
        meterId: meterId,
        reason: reason ?? this.reason,
        recordedAt: recordedAt,
        meterCode: meterCode,
        subscriberName: subscriberName,
        squareId: squareId,
        note: note ?? this.note,
      );
}

/// حساب «هل هذا اليوم نفسه؟» بلا لبس مناطق زمنية.
bool isSameDay(DateTime a, DateTime b) =>
    a.year == b.year && a.month == b.month && a.day == b.day;
