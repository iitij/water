import 'package:equatable/equatable.dart';

/// مشكلة جودة بيانات واحدة كما يُرجعها الخادم (خطة 2.0 — الفصل 6.1).
class DataQualityIssue extends Equatable {
  const DataQualityIssue({
    required this.code,
    required this.title,
    required this.detail,
    required this.severity,
    required this.count,
    this.samples = const <DataQualitySample>[],
  });

  final String code;
  final String title;
  final String detail;

  /// `high` / `medium` / `low` / `info` — تُترجم للون ونص عربي في الواجهة.
  final String severity;
  final int count;
  final List<DataQualitySample> samples;

  bool get hasProblem => count > 0;
  bool get isInfo => severity == 'info';

  String get severityLabel => switch (severity) {
        'high' => 'حرجة',
        'medium' => 'متوسطة',
        'low' => 'منخفضة',
        _ => 'معلومة',
      };

  /// ترتيب العرض: الأشد والأكثر عدداً أولاً، والمعلومات آخراً.
  static int compare(DataQualityIssue a, DataQualityIssue b) {
    if (a.isInfo != b.isInfo) return a.isInfo ? 1 : -1;
    final bySeverity = _weight(b.severity).compareTo(_weight(a.severity));
    if (bySeverity != 0) return bySeverity;
    return b.count.compareTo(a.count);
  }

  static int _weight(String severity) => switch (severity) {
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        _ => 0,
      };

  factory DataQualityIssue.fromJson(Map<String, dynamic> json) => DataQualityIssue(
        code: json['code']?.toString() ?? '',
        title: json['title']?.toString() ?? '',
        detail: json['detail']?.toString() ?? '',
        severity: json['severity']?.toString() ?? 'info',
        count: (json['count'] as num?)?.toInt() ?? 0,
        samples: (json['samples'] as List?)
                ?.whereType<Map>()
                .map((item) => DataQualitySample.fromJson(Map<String, dynamic>.from(item)))
                .toList(growable: false) ??
            const <DataQualitySample>[],
      );

  @override
  List<Object?> get props => <Object?>[code, severity, count, samples];
}

/// مثال واحد داخل مشكلة: معرّف واسم وربما تاريخ، مع الشاشة التي تُصلحه.
class DataQualitySample extends Equatable {
  const DataQualitySample({
    required this.id,
    required this.label,
    this.meta,
    this.route,
  });

  final int id;
  final String label;
  final String? meta;

  /// مفتاح شاشة الإصلاح (`subscribers` / `meters` / `readings`).
  final String? route;

  factory DataQualitySample.fromJson(Map<String, dynamic> json) => DataQualitySample(
        id: (json['id'] as num?)?.toInt() ?? 0,
        label: json['label']?.toString() ?? '',
        meta: json['meta']?.toString(),
        route: json['route']?.toString(),
      );

  @override
  List<Object?> get props => <Object?>[id, label, meta, route];
}

/// تقرير جودة البيانات كاملاً.
class DataQualityReport extends Equatable {
  const DataQualityReport({
    required this.issues,
    required this.staleDays,
    this.generatedAt,
  });

  final List<DataQualityIssue> issues;
  final int staleDays;
  final DateTime? generatedAt;

  /// المشكلات الفعلية فقط، مرتبة حسب الأهمية.
  List<DataQualityIssue> get problems {
    final list = issues.where((issue) => issue.hasProblem && !issue.isInfo).toList();
    list.sort(DataQualityIssue.compare);
    return list;
  }

  int get totalProblems =>
      problems.fold<int>(0, (sum, issue) => sum + issue.count);

  bool get isClean => problems.isEmpty;

  factory DataQualityReport.fromJson(Map<String, dynamic> json) => DataQualityReport(
        issues: (json['issues'] as List?)
                ?.whereType<Map>()
                .map((item) => DataQualityIssue.fromJson(Map<String, dynamic>.from(item)))
                .toList(growable: false) ??
            const <DataQualityIssue>[],
        staleDays: (json['stale_days'] as num?)?.toInt() ?? 45,
        generatedAt: DateTime.tryParse(json['generated_at']?.toString() ?? ''),
      );

  @override
  List<Object?> get props => <Object?>[issues, staleDays, generatedAt];
}
