import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/diagnostics/error_reporter.dart';

void main() {
  group('redact', () {
    test('hides phone numbers, amounts and emails', () {
      expect(redact('المشترك 777123456 غير موجود'), 'المشترك ######### غير موجود');
      expect(redact('راسل admin@water.ye الآن'), 'راسل [email] الآن');
      expect(redact('رمز 1234'), 'رمز 1234', reason: 'الأرقام القصيرة تُبقى');
    });

    test('truncates very long messages', () {
      final long = 'x' * 1000;
      expect(redact(long).length, 401);
      expect(redact(long).endsWith('…'), isTrue);
    });
  });

  group('RecordingErrorReporter', () {
    test('keeps the newest reports only', () {
      final reporter = RecordingErrorReporter(maxEntries: 3);
      for (var i = 0; i < 5; i++) {
        reporter.report(Exception('فشل $i'), context: 'sync', statusCode: 500);
      }

      expect(reporter.recent, hasLength(3));
      expect(reporter.recent.last.message, contains('4'));
      expect(reporter.recent.last.statusCode, 500);
      reporter.dispose();
    });

    test('streams reports and strips the Exception prefix', () async {
      final reporter = RecordingErrorReporter();
      final collected = <ErrorReport>[];
      final subscription = reporter.stream.listen(collected.add);

      reporter.report(Exception('انتهت المهلة'), endpoint: 'payments');
      await Future<void>.delayed(Duration.zero);

      expect(collected.single.message, 'انتهت المهلة');
      expect(collected.single.endpoint, 'payments');
      expect(collected.single.fatal, isFalse);
      await subscription.cancel();
      await reporter.dispose();
    });

    test('json payload carries no raw identifiers', () {
      final reporter = RecordingErrorReporter();
      reporter.report(Exception('فشل المشترك 777123456'), statusCode: 422);

      final json = reporter.recent.single.toJson();
      expect(json['message'], isNot(contains('777123456')));
      expect(json['status_code'], 422);
      expect(json['occurred_at'], isA<String>());
      reporter.dispose();
    });
  });

  test('NoopErrorReporter ignores everything', () async {
    const reporter = NoopErrorReporter();
    reporter.report(Exception('x'));
    await reporter.flush();
    expect(reporter.recent, isEmpty);
  });
}
