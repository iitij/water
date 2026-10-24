import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/data/services/daily_receipts_exporter.dart';

/// تصدير سندات اليوم من الجهاز (خطة 2.0 — الفصل 6.2).
///
/// هذه الدالة تعمل أوفلاين بالكامل، فهي ما يُستخدم لمطابقة نقد المحصّل في نهاية
/// اليوم. نحمي هنا: بذرة Excel العربية، الفاصل والاقتباس، استثناء المحذوف،
/// وأن الإجمالي في آخر سطر يساوي مجموع السندات السارية.
void main() {
  PaymentModel payment({
    required int id,
    required String receipt,
    required double amount,
    String? subscriber,
    DateTime? date,
    bool voided = false,
    bool deleted = false,
    bool whatsapp = false,
    String? voidReason,
    int? cashSessionId,
  }) =>
      PaymentModel(
        id: id,
        subscriberId: 1,
        receiptNumber: receipt,
        amount: amount,
        paymentDate: date ?? DateTime(2026, 10, 24, 9),
        method: PaymentMethod.cash,
        subscriberName: subscriber ?? 'أحمد',
        collectorName: 'سالم',
        deletedAt: deleted ? DateTime(2026, 10, 24, 12) : null,
        voidedAt: voided ? DateTime(2026, 10, 24, 13) : null,
        voidReason: voidReason,
        cashSessionId: cashSessionId,
        whatsappSentAt: whatsapp ? DateTime(2026, 10, 24, 9, 30) : null,
      );

  group('buildCsv', () {
    test('يبدأ ببذرة UTF-8 BOM ويكتب الترويسة العربية كاملة', () {
      final csv = DailyReceiptsExporter.buildCsv(const <PaymentModel>[]);
      expect(csv.codeUnitAt(0), 0xFEFF);
      final header = csv.split('\n').first.replaceFirst('\uFEFF', '').trim();
      expect(header.split(',').length, DailyReceiptsExporter.headers.length);
      expect(header, contains('رقم السند'));
      expect(header, contains('المبلغ'));
    });

    test('سطر لكل سند ساري + سطر إجمالي واحد', () {
      final csv = DailyReceiptsExporter.buildCsv(<PaymentModel>[
        payment(id: 1, receipt: 'R-2', amount: 1500),
        payment(id: 2, receipt: 'R-1', amount: 500),
      ]);
      final lines = csv.split('\n').where((line) => line.trim().isNotEmpty).toList();
      // ترويسة + سندان + إجمالي
      expect(lines.length, 4);
      expect(lines.last, contains('الإجمالي'));
      expect(lines.last, contains('2000.00'));
    });

    test('السند المحذوف يُستثنى من السطور ومن الإجمالي', () {
      final csv = DailyReceiptsExporter.buildCsv(<PaymentModel>[
        payment(id: 1, receipt: 'R-1', amount: 1000),
        payment(id: 2, receipt: 'R-9', amount: 7000, deleted: true),
      ]);
      expect(csv, isNot(contains('R-9')));
      expect(csv, contains('1000.00'));
      expect(csv, isNot(contains('8000.00')));
    });

    test('السند الملغى يبقى في الملف بحالة «ملغى» وسببه', () {
      final csv = DailyReceiptsExporter.buildCsv(<PaymentModel>[
        payment(
          id: 3,
          receipt: 'R-3',
          amount: 900,
          voided: true,
          voidReason: 'سند مكرر بسبب انقطاع الشبكة',
        ),
      ]);
      expect(csv, contains('R-3'));
      expect(csv, contains('ملغى'));
      expect(csv, contains('سند مكرر بسبب انقطاع الشبكة'));
    });

    test('يقتبس القيم التي تحتوي فاصلة أو علامة اقتباس', () {
      final csv = DailyReceiptsExporter.buildCsv(<PaymentModel>[
        payment(id: 4, receipt: 'R-4', amount: 10, subscriber: 'أحمد، علي'),
      ]);
      expect(csv, contains('"أحمد، علي"'));
    });

    test('يضاعف علامة الاقتباس داخل القيمة بدل كسر الأعمدة', () {
      final csv = DailyReceiptsExporter.buildCsv(<PaymentModel>[
        payment(id: 5, receipt: 'R-5', amount: 10, subscriber: 'شركة "النور"'),
      ]);
      expect(csv, contains('"شركة ""النور"""'));
    });

    test('عمود واتساب يعكس حالة التسجيل، وعمود الصندوق يعرض شرطة عند الغياب', () {
      final csv = DailyReceiptsExporter.buildCsv(<PaymentModel>[
        payment(id: 6, receipt: 'R-6', amount: 10, whatsapp: true, cashSessionId: 12),
        payment(id: 7, receipt: 'R-7', amount: 20),
      ]);
      final lines = csv.split('\n').where((line) => line.trim().isNotEmpty).toList();
      final first = lines[1];
      expect(first, contains('نعم'));
      expect(first, contains('12'));
      expect(lines[2], contains('—'));
    });
  });

  group('totalOf', () {
    test('يجمع السارية فقط', () {
      expect(
        DailyReceiptsExporter.totalOf(<PaymentModel>[
          payment(id: 1, receipt: 'R-1', amount: 1200),
          payment(id: 2, receipt: 'R-2', amount: 300, deleted: true),
        ]),
        1200,
      );
    });

    test('قائمة فارغة تساوي صفراً لا استثناءً', () {
      expect(DailyReceiptsExporter.totalOf(const <PaymentModel>[]), 0);
    });
  });
}
