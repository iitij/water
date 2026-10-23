import 'package:flutter_test/flutter_test.dart';
import 'package:water_fouad/core/utils/currency_formatter.dart';
import 'package:water_fouad/features/subscribers/domain/entities/subscriber.dart';

void main() {
  group('قاعدة عزل الأرصدة (الخطة 1.3/1)', () {
    test('الرصيد الصافي = الدائن - المدين عبر العام والعدادات', () {
      const s = Subscriber(id: 1, name: 'تجريبي', creditBalance: 7000, debitBalance: 12500);
      expect(s.netBalance, -5500);
    });
  });

  group('فلتر جهة كشف الحساب', () {
    test('القيم المرسلة للـ API مطابقة لـ SubscriberTransactionsTable', () {
      expect(const PartyAll().apiValue, isNull);
      expect(const PartyGeneral().apiValue, 'general');
      const m = MeterBalance(meterId: 11, uniqueCode: 'W-1');
      expect(const PartyMeter(m).apiValue, '11');
    });
  });

  group('تحويل الأرقام العربية', () {
    test('٠١٢٣ تتحول إلى 0123', () {
      expect(normalizeDigits('٠١٢٣٤٥'), '012345');
      expect(normalizeDigits('+967 ٧٧١'), '+967 771');
    });
  });
}
