import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/widgets/offline_status_bar.dart';

/// شريط الحالة الأوفلاين (S3-05): يظهر عند الحاجة ويختفي عند تمام المزامنة.
void main() {
  group('offlineStatusLabel', () {
    test('لا نص عندما يكون متصلاً وبلا عمليات معلّقة', () {
      final label = offlineStatusLabel(online: true, pending: 0, needsAttention: 0);
      expect(label.text, isEmpty);
      expect(label.isWarning, isFalse);
    });

    test('الانقطاع يسبق أي عدّاد آخر', () {
      final label = offlineStatusLabel(online: false, pending: 3, needsAttention: 2);
      expect(label.isWarning, isTrue);
      expect(label.text, contains('غير متصل'));
      expect(label.text, contains('3'));
    });

    test('الانقطاع بلا عمليات يعطي رسالة مطمئنة بلا أرقام', () {
      final label = offlineStatusLabel(online: false, pending: 0, needsAttention: 0);
      expect(label.text, 'أنت غير متصل — العمل مستمر محلياً');
      expect(label.isWarning, isTrue);
    });

    test('عند الاتصال: عمليات تحتاج تدخلاً تُنبَّه قبل عدّاد الانتظار', () {
      final label = offlineStatusLabel(online: true, pending: 5, needsAttention: 2);
      expect(label.isWarning, isTrue);
      expect(label.text, contains('2'));
    });

    test('عند الاتصال مع انتظار فقط: رسالة إخبارية غير تحذيرية', () {
      final label = offlineStatusLabel(online: true, pending: 4, needsAttention: 0);
      expect(label.isWarning, isFalse);
      expect(label.text, contains('4'));
    });
  });
}
