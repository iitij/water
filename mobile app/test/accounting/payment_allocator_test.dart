import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/domain/accounting/payment_allocator.dart';

/// اختبارات قاعدة FIFO §3.4-3 — المرجع `PaymentService::distributePayment`
/// (`app/Services/PaymentService.php` سطر 116-190) و`recalculateInvoices`
/// (سطر 16-73). السقف هو `total_amount` وحده (المتأخرات السابقة لا تُسدد من الدفعة).

InvoiceModel _invoice({
  required int id,
  required double totalAmount,
  double amountPaid = 0,
  double previousBalance = 0,
  int subscriberId = 1,
  int? meterId,
  DateTime? billDate,
  InvoiceStatus status = InvoiceStatus.issued,
  bool? isLatest,
}) {
  return InvoiceModel(
    id: id,
    subscriberId: subscriberId,
    invoiceType: InvoiceType.meter,
    billNumber: 'INV-$id',
    meterId: meterId,
    totalAmount: totalAmount,
    amountPaid: amountPaid,
    previousBalance: previousBalance,
    billDate: billDate,
    status: status,
    isLatestInvoice: isLatest,
  );
}

PaymentModel _payment({
  required int id,
  required double amount,
  int? invoiceId,
  DateTime? date,
  int subscriberId = 1,
  int? meterId,
}) {
  return PaymentModel(
    id: id,
    subscriberId: subscriberId,
    receiptNumber: 'REC-$id',
    amount: amount,
    method: PaymentMethod.cash,
    invoiceId: invoiceId,
    paymentDate: date,
    meterId: meterId,
  );
}

void main() {
  final jan = DateTime(2026, 1, 1);
  final feb = DateTime(2026, 2, 1);

  group('allocate — FIFO داخل الحساب المعزول', () {
    test('الأقدم أولاً ثم الأحدث', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, billDate: jan),
        _invoice(id: 2, totalAmount: 300, billDate: feb),
      ];

      final result = PaymentAllocator.allocate(invoices: invoices, amount: 700);

      expect(result.allocations.map((a) => a.invoiceId).toList(), <int>[1, 2]);
      expect(result.allocations.map((a) => a.applied).toList(), <double>[500, 200]);
      expect(result.surplusCredit, 0);

      final updated = result.invoices;
      expect(updated.firstWhere((i) => i.id == 1).amountPaid, 500);
      expect(updated.firstWhere((i) => i.id == 2).amountPaid, 200);
    });

    test('الفائض يصبح رصيداً دائناً', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, billDate: jan),
        _invoice(id: 2, totalAmount: 300, billDate: feb),
      ];

      final result = PaymentAllocator.allocate(invoices: invoices, amount: 1000);

      expect(result.totalApplied, 800);
      expect(result.surplusCredit, 200);
    });

    test('الفاتورة المستهدفة تُسدَّد قبل FIFO', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, billDate: jan),
        _invoice(id: 2, totalAmount: 300, billDate: feb),
      ];

      final result = PaymentAllocator.allocate(
        invoices: invoices,
        amount: 250,
        targetInvoiceId: 2,
      );

      expect(result.allocations.length, 1);
      expect(result.allocations.single.invoiceId, 2);
      expect(result.allocations.single.applied, 250);
    });

    test('سقف الفاتورة = `total_amount` بدون المتأخرات المحمولة', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, previousBalance: 300, billDate: jan),
      ];

      final result = PaymentAllocator.allocate(invoices: invoices, amount: 900);

      expect(result.totalApplied, 500);
      // المتأخرات السابقة لا تُسدد من الدفعة: الفائض يرجع رصيداً دائناً.
      expect(result.surplusCredit, 400);
    });

    test('الفواتير المسددة أو غير المفتوحة لا تستقبل شيئاً', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, amountPaid: 500, billDate: jan),
        _invoice(id: 2, totalAmount: 300, status: InvoiceStatus.cancelled, billDate: feb),
        _invoice(id: 3, totalAmount: 100, billDate: feb),
      ];

      final result = PaymentAllocator.allocate(invoices: invoices, amount: 250);

      expect(result.allocations.length, 1);
      expect(result.allocations.single.invoiceId, 3);
      expect(result.surplusCredit, 150);
    });

    test('المبلغ الجزئي يترك بقية الدين كما هو', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, billDate: jan),
      ];

      final result = PaymentAllocator.allocate(invoices: invoices, amount: 120.5);
      final paid = result.invoices.single;

      expect(paid.amountPaid, 120.5);
      expect(paid.ownRemaining, 379.5);
    });
  });

  group('recalculate — إعادة البناء الكامل', () {
    test('تصفّر المدفوع ثم تعيد توزيع كل الدفعات بالترتيب الزمني', () {
      final invoices = <InvoiceModel>[
        // مدفوعة مسبقاً بالكامل في البيانات القديمة (يجب أن تُعاد من الصفر).
        _invoice(id: 1, totalAmount: 500, amountPaid: 500, billDate: jan),
        _invoice(id: 2, totalAmount: 300, billDate: feb),
      ];

      final payments = <PaymentModel>[
        _payment(id: 1, amount: 400, date: DateTime(2026, 1, 20)),
        _payment(id: 2, amount: 100, date: DateTime(2026, 2, 10)),
      ];

      final result = PaymentAllocator.recalculate(
        invoices: invoices,
        payments: payments,
        subscriberId: 1,
        now: DateTime(2026, 2, 20),
      );

      final first = result.invoices.firstWhere((i) => i.id == 1);
      final second = result.invoices.firstWhere((i) => i.id == 2);

      expect(first.amountPaid, 500);
      expect(second.amountPaid, 0);
      // فاتورة مسددة بالكامل ⇒ paid، والأحدث عليها دين ⇒ overdue بعد مدة السماح.
      expect(first.status, InvoiceStatus.paid);
      expect(second.status, InvoiceStatus.overdue);
      expect(result.surplusCredit, 0);
    });

    test('الدفعات المخصصة لفاتورة معينة تُقدَّم في إعادة التوزيع', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, billDate: jan),
        _invoice(id: 2, totalAmount: 300, billDate: feb),
      ];

      final result = PaymentAllocator.recalculate(
        invoices: invoices,
        payments: <PaymentModel>[
          _payment(id: 1, amount: 300, invoiceId: 2, date: DateTime(2026, 3, 1)),
        ],
        subscriberId: 1,
        now: DateTime(2026, 3, 2),
      );

      expect(result.invoices.firstWhere((i) => i.id == 2).amountPaid, 300);
      expect(result.invoices.firstWhere((i) => i.id == 1).amountPaid, 0);
    });

    test('توحيد الحساب: دفعة العداد تسدد أيضاً فاتورة المشترك العامة', () {
      final invoices = <InvoiceModel>[
        _invoice(id: 1, totalAmount: 500, meterId: 77, billDate: jan),
        _invoice(id: 2, totalAmount: 300, meterId: null, billDate: feb),
      ];

      final result = PaymentAllocator.recalculate(
        invoices: invoices,
        payments: <PaymentModel>[
          _payment(id: 1, amount: 600, meterId: 77, date: DateTime(2026, 3, 1)),
        ],
        subscriberId: 1,
        meterId: 77,
        now: DateTime(2026, 3, 1),
      );

      expect(result.invoices.firstWhere((i) => i.id == 1).amountPaid, 500);
      expect(result.invoices.firstWhere((i) => i.id == 2).amountPaid, 100);
      expect(result.surplusCredit, 0);
    });
  });

  test('subscriber isolation remains strict after meter account unification', () {
    final result = PaymentAllocator.recalculate(
      invoices: [
        _invoice(id: 1, totalAmount: 500, meterId: 77),
        _invoice(id: 2, totalAmount: 900, subscriberId: 2),
      ],
      payments: [
        _payment(id: 1, amount: 600),
        _payment(id: 2, amount: 1000, subscriberId: 2),
      ],
      subscriberId: 1,
    );
    expect(result.invoices.single.id, 1);
    expect(result.invoices.single.amountPaid, 500);
    expect(result.surplusCredit, 100);
  });

  group('validate', () {
    test('مبلغ صفر ⇒ مرفوض', () {
      final shortfall = PaymentAllocator.validate(
        invoice: _invoice(id: 1, totalAmount: 500),
        amount: 0,
      );
      expect(shortfall.accepted, false);
      expect(shortfall.message, isNotNull);
    });

    test('دفع أكثر من المستحق ⇒ مقبول مع تنبيه الفائض', () {
      final shortfall = PaymentAllocator.validate(
        invoice: _invoice(id: 1, totalAmount: 500),
        amount: 650,
      );

      expect(shortfall.accepted, true);
      expect(shortfall.overpay, 150);
      expect(shortfall.remainingAfter, 0);
      expect(shortfall.message, contains('رصيداً دائناً'));
    });

    test('دفع جزئي ⇒ يبقى الفرق', () {
      final shortfall = PaymentAllocator.validate(
        invoice: _invoice(id: 1, totalAmount: 500, previousBalance: 100),
        amount: 200,
      );

      expect(shortfall.due, 500);
      expect(shortfall.remainingAfter, 300);
      expect(shortfall.message, isNull);
    });
  });

  test('historical replacement retains target, incoming transfers and refunds are applied once', () {
    final original = _invoice(id: 1, totalAmount: 6000, status: InvoiceStatus.cancelled);
    final other = _invoice(id: 2, totalAmount: 1000, billDate: jan);
    final replacement = InvoiceModel.fromJson({
      'id': 3, 'subscriber_id': 1, 'invoice_type': 'meter',
      'bill_number': 'HIST-3', 'total_amount': '4500.00',
      'bill_date': '2026-02-01', 'status': 'issued', 'retired_meter_id': 77,
      'historical_correction': {
        'original_invoice_id': 1, 'corrected_units': '7.50',
        'historical_unit_price': '600.00',
      },
    });
    final restored = InvoiceModel.fromJson(replacement.toJson());
    expect(restored.originalInvoiceId, 1);
    expect(restored.consumptionUnits, 7.5);
    expect(restored.impliedUnitPrice, 600);
    final payment = PaymentModel.fromJson({
      'id': 1, 'subscriber_id': 1, 'invoice_id': 1,
      'receipt_number': 'REC-1', 'amount': '2500.25',
      'payment_method': 'cash', 'payment_date': '2026-01-20',
      'refunded_amount': '500.00',
    });
    final transferred = PaymentModel.fromJson({
      'id': 2, 'subscriber_id': 1, 'amount': '900.00',
      'receipt_number': 'REC-2', 'payment_method': 'cash',
      'account_transfer': {'to_subscriber_id': 2},
    });
    final result = PaymentAllocator.recalculate(
      invoices: [original, other, restored],
      payments: [payment, transferred],
      subscriberId: 1,
      incomingTransfers: [
        AccountTransferCredit(id: 10, subscriberId: 1, amount: 700, createdAt: feb),
        AccountTransferCredit(id: 11, subscriberId: 2, amount: 5000, createdAt: feb),
      ],
    );
    expect(result.invoices.map((i) => i.id), [2, 3]);
    expect(result.invoices.firstWhere((i) => i.id == 3).amountPaid, 2000.25);
    expect(result.invoices.firstWhere((i) => i.id == 2).amountPaid, 700);
    expect(result.surplusCredit, 0);
  });

}
