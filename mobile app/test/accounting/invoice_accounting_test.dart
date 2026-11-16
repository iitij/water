import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/domain/accounting/invoice_accounting.dart';

/// اختبارات قاعدة «العزل + total_due + المتبقي التراكمي» — المرجع:
/// `app/Models/Invoice.php` (`getTotalDueAttribute`, `getRemainingAmount`,
/// `getTrueRemainingAmount`, `isLatestInvoice`, `updateStatus`).
InvoiceModel _invoice({
  required int id,
  double totalAmount = 0,
  double previousBalance = 0,
  double amountPaid = 0,
  double creditApplied = 0,
  int subscriberId = 1,
  int? meterId,
  InvoiceType invoiceType = InvoiceType.meter,
  InvoiceStatus status = InvoiceStatus.issued,
  DateTime? billDate,
  double? serverTrueRemaining,
  bool? isLatest,
}) {
  return InvoiceModel(
    id: id,
    subscriberId: subscriberId,
    invoiceType: invoiceType,
    billNumber: 'INV-$id',
    meterId: meterId,
    totalAmount: totalAmount,
    previousBalance: previousBalance,
    amountPaid: amountPaid,
    creditApplied: creditApplied,
    status: status,
    billDate: billDate,
    serverTrueRemaining: serverTrueRemaining,
    isLatestInvoice: isLatest,
  );
}

void main() {
  group('total_due', () {
    test('يضيف المتأخرات السابقة إلى إجمالي الفاتورة', () {
      final invoice = _invoice(id: 1, totalAmount: 1000, previousBalance: 250);
      expect(InvoiceAccounting.totalDue(invoice), 1250);
      expect(invoice.netPayable, 1250);
    });

    test('يخصم الرصيد الدائن ولا يسمح بصافي سالب', () {
      final invoice = _invoice(
        id: 2,
        totalAmount: 400,
        previousBalance: 100,
        creditApplied: 700,
      );
      expect(invoice.netPayable, 0);
    });
  });

  group('own vs true remaining', () {
    test('متبقي الفاتورة نفسها لا يشمل متأخرات الفواتير الأقدم', () {
      final older = _invoice(id: 1, totalAmount: 500, amountPaid: 200);
      final latest = _invoice(id: 2, totalAmount: 300, amountPaid: 0, isLatest: true);

      expect(InvoiceAccounting.ownRemaining(latest), 300);
      expect(InvoiceAccounting.trueRemaining(latest, <InvoiceModel>[older, latest]), 600);
    });

    test('الخادم أدق: تُقدَّم قيمة `true_remaining_amount` على الحساب المحلي', () {
      final older = _invoice(id: 1, totalAmount: 500);
      final latest = _invoice(
        id: 2,
        totalAmount: 300,
        serverTrueRemaining: 900,
        isLatest: true,
      );

      expect(InvoiceAccounting.trueRemaining(latest, <InvoiceModel>[older, latest]), 900);
    });

    test('حساب العداد لا يختلط بالحساب العام للمشترك', () {
      final meterInvoice = _invoice(id: 1, totalAmount: 500, meterId: 77);
      final generalInvoice = _invoice(id: 2, totalAmount: 400, meterId: null);

      // الأحدث في الحساب العام لا يرث دين فاتورة العداد.
      expect(
        InvoiceAccounting.trueRemaining(generalInvoice, <InvoiceModel>[
          meterInvoice,
          generalInvoice,
        ]),
        400,
      );
    });

  });

  group('latest invoice + display', () {
    test('غير الأحدث تعرض «-» للمتأخرات المتراكمة', () {
      final older = _invoice(id: 1, totalAmount: 500, isLatest: false);
      final latest = _invoice(id: 2, totalAmount: 300, isLatest: true);

      expect(InvoiceAccounting.isLatestInvoice(older, <InvoiceModel>[older, latest]), false);
      expect(InvoiceAccounting.displayRemaining(older, <InvoiceModel>[older, latest]), null);
      expect(
        InvoiceAccounting.displayRemaining(latest, <InvoiceModel>[older, latest]),
        800,
      );
    });

    test('بدون علم من الخادم تُحسب الأحدث أكبر `id`', () {
      final a = _invoice(id: 3, totalAmount: 100);
      final b = _invoice(id: 9, totalAmount: 100);
      expect(InvoiceAccounting.isLatestInvoice(b, <InvoiceModel>[a, b]), true);
      expect(InvoiceAccounting.isLatestInvoice(a, <InvoiceModel>[a, b]), false);
    });
  });

  group('updateStatus', () {
    final billDate = DateTime(2026, 1, 1);

    test('الأحدث المسددة بالكامل تصبح paid', () {
      final invoice = _invoice(
        id: 1,
        totalAmount: 1000,
        amountPaid: 1000,
        billDate: billDate,
        isLatest: true,
      );

      expect(
        InvoiceAccounting.computeStatus(
          invoice,
          siblings: <InvoiceModel>[invoice],
          now: DateTime(2026, 6, 1),
        ),
        InvoiceStatus.paid,
      );
    });

    test('تجاوز مدة السماح مع بقاء دين ⇒ overdue', () {
      final invoice = _invoice(id: 1, totalAmount: 1000, billDate: billDate, isLatest: true);

      expect(
        InvoiceAccounting.computeStatus(
          invoice,
          siblings: <InvoiceModel>[invoice],
          gracePeriodDays: 14,
          now: billDate.add(const Duration(days: 15)),
        ),
        InvoiceStatus.overdue,
      );
    });

    test('داخل مدة السماح ⇒ issued', () {
      final invoice = _invoice(id: 1, totalAmount: 1000, billDate: billDate, isLatest: true);

      expect(
        InvoiceAccounting.computeStatus(
          invoice,
          siblings: <InvoiceModel>[invoice],
          gracePeriodDays: 14,
          now: billDate.add(const Duration(days: 3)),
        ),
        InvoiceStatus.issued,
      );
    });

    test('الفاتورة القديمة المدفوعة جزئياً ⇒ partially_paid', () {
      final older = _invoice(
        id: 1,
        totalAmount: 1000,
        amountPaid: 400,
        billDate: billDate,
        isLatest: false,
      );
      final latest = _invoice(
        id: 2,
        totalAmount: 100,
        billDate: billDate,
        isLatest: true,
      );

      expect(
        InvoiceAccounting.computeStatus(
          older,
          siblings: <InvoiceModel>[older, latest],
          now: billDate.add(const Duration(days: 40)),
        ),
        InvoiceStatus.partiallyPaid,
      );
    });

    test('strict_overdue_on_new_invoice ⇒ الأقدم تعتبر متأخرة فوراً', () {
      final older = _invoice(
        id: 1,
        totalAmount: 1000,
        billDate: billDate,
        isLatest: false,
      );
      final latest = _invoice(id: 2, totalAmount: 50, billDate: billDate, isLatest: true);

      expect(
        InvoiceAccounting.computeStatus(
          older,
          siblings: <InvoiceModel>[older, latest],
          strictOverdueOnNewInvoice: true,
          now: billDate,
        ),
        InvoiceStatus.overdue,
      );
    });
  });

  group('معاينة الإصدار (InvoiceService)', () {
    test('الرصيد الدائن يخصم ولا يتجاوز subtotal', () {
      expect(
        InvoiceAccounting.previewNetPayable(
          consumptionFee: 600,
          availableCredit: 400,
        ),
        200,
      );
      expect(
        InvoiceAccounting.previewCreditApplied(
          consumptionFee: 600,
          availableCredit: 400,
        ),
        400,
      );
    });

    test('دائن أكبر من الإجمالي ⇒ الصافي صفر والخصم محجوب عند subtotal', () {
      expect(
        InvoiceAccounting.previewNetPayable(
          consumptionFee: 100,
          availableCredit: 900,
        ),
        0,
      );
      expect(
        InvoiceAccounting.previewCreditApplied(
          consumptionFee: 100,
          availableCredit: 900,
        ),
        100,
      );
    });
  });

  group('latestUnpaidOf + العزل', () {
    test('تتخطى المسددة والمسودة والملغاة وتأخذ أحدث متبقٍ', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 100, status: InvoiceStatus.paid),
        _invoice(id: 5, totalAmount: 100, status: InvoiceStatus.draft),
        _invoice(id: 3, totalAmount: 100),
        _invoice(id: 4, totalAmount: 100, status: InvoiceStatus.cancelled),
      ];

      final picked = InvoiceAccounting.latestUnpaidOf(1, invoices);
      expect(picked?.id, 3);
    });

    test('openInvoicesForAccount يعزل فاتورة العداد عن العامة ويرتب بالأقدم', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 3, totalAmount: 300, meterId: 77),
        _invoice(id: 1, totalAmount: 100, meterId: 77),
        _invoice(id: 2, totalAmount: 200, meterId: null),
        _invoice(id: 4, totalAmount: 400, meterId: 77, status: InvoiceStatus.paid),
      ];

      final meterScoped = InvoiceAccounting.openInvoicesForAccount(
        invoices: invoices,
        subscriberId: 1,
        meterId: 77,
      );
      expect(meterScoped.map((i) => i.id).toList(), <int>[1, 3]);

      final generalScoped = InvoiceAccounting.openInvoicesForAccount(
        invoices: invoices,
        subscriberId: 1,
      );
      expect(generalScoped.map((i) => i.id).toList(), <int>[2]);
    });
  });
}
