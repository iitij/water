import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/data_quality/domain/data_quality_issue.dart';

/// تقرير جودة البيانات (S7-10).
///
/// الترتيب جزء من القيمة: المشرف يفتح الشاشة ويرى الأشد أولاً. ولو رتّبنا حسب
/// ترتيب الخادم لظهرت «معلومة» قبل «12 مشتركاً بلا هاتف».
void main() {
  Map<String, dynamic> issueJson(
    String code, {
    String severity = 'medium',
    int count = 1,
    List<Map<String, dynamic>> samples = const <Map<String, dynamic>>[],
  }) =>
      <String, dynamic>{
        'code': code,
        'title': 'عنوان $code',
        'detail': 'تفصيل $code',
        'severity': severity,
        'count': count,
        'samples': samples,
      };

  DataQualityReport reportOf(List<Map<String, dynamic>> issues) =>
      DataQualityReport.fromJson(<String, dynamic>{
        'generated_at': '2026-10-24T09:00:00+00:00',
        'stale_days': 45,
        'total': 0,
        'issues': issues,
      });

  group('DataQualityIssue', () {
    test('fromJson يقرأ الأمثلة والحقول', () {
      final issue = DataQualityIssue.fromJson(issueJson(
        'subscriber_without_phone',
        severity: 'high',
        count: 12,
        samples: <Map<String, dynamic>>[
          <String, dynamic>{'id': 7, 'label': 'أحمد', 'route': 'subscribers'},
          <String, dynamic>{'id': 8, 'label': 'سالم', 'meta': 'مربع 2'},
        ],
      ));

      expect(issue.count, 12);
      expect(issue.hasProblem, isTrue);
      expect(issue.severityLabel, 'حرجة');
      expect(issue.samples, hasLength(2));
      expect(issue.samples.first.route, 'subscribers');
      expect(issue.samples[1].meta, 'مربع 2');
    });

    test('severityLabel يترجم كل المستويات', () {
      String label(String severity) => DataQualityIssue.fromJson(issueJson('x', severity: severity)).severityLabel;
      expect(label('high'), 'حرجة');
      expect(label('medium'), 'متوسطة');
      expect(label('low'), 'منخفضة');
      expect(label('info'), 'معلومة');
    });

    test('حقول ناقصة لا تُسقط التحليل', () {
      final issue = DataQualityIssue.fromJson(<String, dynamic>{'code': 'x'});
      expect(issue.title, '');
      expect(issue.count, 0);
      expect(issue.samples, isEmpty);
      expect(issue.hasProblem, isFalse);
    });
  });

  group('DataQualityReport', () {
    test('problems يستثني الصفر والمعلومات ويرتّب بالأشد ثم الأكثر', () {
      final report = reportOf(<Map<String, dynamic>>[
        issueJson('info_one', severity: 'info', count: 0),
        issueJson('low_one', severity: 'low', count: 30),
        issueJson('high_small', severity: 'high', count: 2),
        issueJson('high_big', severity: 'high', count: 12),
        issueJson('medium_zero', severity: 'medium', count: 0),
        issueJson('medium_one', severity: 'medium', count: 4),
      ]);

      expect(report.problems.map((issue) => issue.code), <String>[
        'high_big',
        'high_small',
        'medium_one',
        'low_one',
      ]);
    });

    test('totalProblems يجمع أعداد المشكلات الفعلية فقط', () {
      final report = reportOf(<Map<String, dynamic>>[
        issueJson('a', severity: 'high', count: 12),
        issueJson('b', severity: 'medium', count: 4),
        issueJson('c', severity: 'info', count: 0),
      ]);
      expect(report.totalProblems, 16);
      expect(report.isClean, isFalse);
    });

    test('تقرير كله أصفار ومعلومات = سليم', () {
      final report = reportOf(<Map<String, dynamic>>[
        issueJson('no_active_tariff', severity: 'info', count: 0),
        issueJson('subscriber_without_phone', severity: 'high', count: 0),
      ]);
      expect(report.isClean, isTrue);
      expect(report.problems, isEmpty);
      expect(report.totalProblems, 0);
    });

    test('fromJson يقرأ التاريخ والعتبة', () {
      final report = reportOf(const <Map<String, dynamic>>[]);
      expect(report.staleDays, 45);
      expect(report.generatedAt, isNotNull);
    });

    test('استجابة فارغة تُنتج تقريراً سليماً بلا استثناء', () {
      final report = DataQualityReport.fromJson(const <String, dynamic>{});
      expect(report.issues, isEmpty);
      expect(report.isClean, isTrue);
      expect(report.staleDays, 45);
    });
  });
}
