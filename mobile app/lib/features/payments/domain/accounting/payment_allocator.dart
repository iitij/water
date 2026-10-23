import '../../../../core/accounting/account_isolation.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../../invoices/domain/accounting/invoice_accounting.dart';
import '../../data/models/payment_model.dart';

/// توزيع دفعة على الفواتير — إعادة إنتاج دقيقة لـ
/// `PaymentService::distributePayment` (سطر 116-190) و`recalculateInvoices`
/// (سطر 16-73) في `app/Services/PaymentService.php`.
///
/// الترتيب الحرج (أي تغيير فيه يكسر المطابقة مع الخادم):
///  1. تُطبَّق الدفعة أولاً على الفاتورة المحددة `invoice_id` (إن كانت مفتوحة).
///  2. الفائض يُوزَّع FIFO على الأقدم داخل **نفس الحساب المعزول**
///     (`meter_id` للعداد، أو `meter_id IS NULL` للحساب العام).
///  3. السقف هو `total_amount` لكل فاتورة **بدون** `previous_balance` —
///     وإلا تضاعفت المتأخرات (القاعدة 3 في §1.3 من الخطة).
///  4. الفائض بعد استيفاء كل الفواتير يتحول رصيداً دائناً (`credit`) يُخصم من
///     الفاتورة القادمة عبر `credit_applied` في `InvoiceService::generateInvoice`.
class PaymentAllocator {
  PaymentAllocator._();

  /// نتائج تطبيق دفعة على مجموعة فواتير.
  static AllocationResult allocate({
    required List<InvoiceModel> invoices,
    required double amount,
    int? targetInvoiceId,
  }) {
    final working = List<InvoiceModel>.of(invoices);
    var remaining = amount;
    final allocations = <InvoiceAllocation>[];

    void apply(InvoiceModel invoice, double cap) {
      final due = cap - invoice.amountPaid;
      if (due <= 0) return;
      final applied = remaining < due ? remaining : due;
      if (applied <= 0) return;

      final index = working.indexWhere((i) => i.id == invoice.id);
      if (index < 0) return;
      working[index] = invoice.copyWith(amountPaid: invoice.amountPaid + applied);
      allocations.add(InvoiceAllocation(invoiceId: invoice.id, applied: applied));
      remaining = double.parse((remaining - applied).toStringAsFixed(2));
    }

    // 1) الفاتورة المستهدفة أولاً.
    if (targetInvoiceId != null && remaining > 0) {
      final targetIdx = working.indexWhere((i) => i.id == targetInvoiceId);
      if (targetIdx >= 0) {
        final target = working[targetIdx];
        if (target.status.isOpenForCollection) {
          apply(target, target.totalAmount);
        }
      }
    }

    // 2) ثم FIFO على الأقدم داخل نفس الحساب المعزول.
    if (remaining > 0) {
      final ordered = _oldestFirst(working)
          .where((i) => i.id != targetInvoiceId && i.status.isOpenForCollection)
          .toList();
      for (final invoice in ordered) {
        if (remaining <= 0) break;
        apply(invoice, invoice.totalAmount);
      }
    }

    return AllocationResult(
      invoices: working,
      allocations: allocations,
      surplusCredit: remaining > 0 ? remaining : 0,
    );
  }

  /// إعادة احتساب كاملة بعد إنشاء/تعديل/حذف فاتورة أو دفعة — تطابق
  /// `PaymentService::recalculateInvoices`: تصفير `amount_paid` ثم إعادة توزيع
  /// كل الدفعات بترتيبها الزمني (المدفوع لفاتورة محددة يُقدَّم).
  static AllocationResult recalculate({
    required List<InvoiceModel> invoices,
    required List<PaymentModel> payments,
    required int subscriberId,
    int? meterId,
    int gracePeriodDays = 14,
    bool strictOverdueOnNewInvoice = false,
    DateTime? now,
  }) {
    final scoped = _accountInvoices(
      invoices,
      subscriberId: subscriberId,
      meterId: meterId,
    ).map((i) => i.copyWith(amountPaid: 0, status: InvoiceStatus.issued)).toList();

    final orderedPayments = _oldestFirstPayment(payments)
        .where((p) =>
            p.subscriberId == subscriberId &&
            AccountIsolation.sameAllocationAccount(
              paymentMeterId: meterId,
              invoiceMeterId: p.meterId,
            ))
        .toList();

    var current = scoped;
    var surplus = 0.0;

    for (final payment in orderedPayments) {
      final result = allocate(
        invoices: current,
        amount: payment.amount,
        targetInvoiceId: payment.invoiceId,
      );
      current = result.invoices;
      surplus = double.parse((surplus + result.surplusCredit).toStringAsFixed(2));
    }

    // تحديث الحالات بعد إعادة التوزيع (`$inv->updateStatus()` في نهاية الدالة).
    final settled = current.map((invoice) {
      final status = InvoiceAccounting.computeStatus(
        invoice,
        siblings: current,
        gracePeriodDays: gracePeriodDays,
        strictOverdueOnNewInvoice: strictOverdueOnNewInvoice,
        now: now,
      );
      return invoice.copyWith(status: status);
    }).toList();

    return AllocationResult(
      invoices: settled,
      allocations: <InvoiceAllocation>[],
      surplusCredit: surplus,
    );
  }

  /// هل يكفي المبلغ المسجل لسداد الفاتورة المختارة؟ (للتحقق الفوري في النموذج)
  static PaymentShortfall validate({
    required InvoiceModel invoice,
    required double amount,
  }) {
    final due = invoice.netPayable;
    if (amount <= 0) {
      return PaymentShortfall(
        accepted: false,
        message: 'أدخل مبلغاً أكبر من صفر.',
        due: due,
        overpay: 0,
        remainingAfter: due,
      );
    }
    final remainingAfter = due - amount;
    return PaymentShortfall(
      accepted: true,
      message: amount > due
          ? 'المبلغ أكبر من المستحق: الفائض ${_round(amount - due)} سيتحول رصيداً دائناً.'
          : null,
      due: due,
      overpay: amount > due ? _round(amount - due) : 0,
      remainingAfter: remainingAfter > 0 ? _round(remainingAfter) : 0,
    );
  }

  static List<InvoiceModel> _accountInvoices(
    List<InvoiceModel> invoices, {
    required int subscriberId,
    int? meterId,
  }) =>
      invoices
          .where((i) =>
              i.subscriberId == subscriberId &&
              AccountIsolation.sameAllocationAccount(
                paymentMeterId: meterId,
                invoiceMeterId: i.meterId,
              ))
          .toList();

  static List<InvoiceModel> _oldestFirst(List<InvoiceModel> invoices) {
    final list = List<InvoiceModel>.of(invoices);
    list.sort((a, b) {
      final aDate = a.billDate;
      final bDate = b.billDate;
      if (aDate != null && bDate != null) {
        final byDate = aDate.compareTo(bDate);
        if (byDate != 0) return byDate;
      }
      return a.id.compareTo(b.id);
    });
    return list;
  }

  static List<PaymentModel> _oldestFirstPayment(List<PaymentModel> payments) {
    final list = List<PaymentModel>.of(payments);
    list.sort((a, b) {
      final aDate = a.paymentDate;
      final bDate = b.paymentDate;
      if (aDate != null && bDate != null) {
        final byDate = aDate.compareTo(bDate);
        if (byDate != 0) return byDate;
      }
      return a.id.compareTo(b.id);
    });
    return list;
  }

  static double _round(double value) => double.parse(value.toStringAsFixed(2));
}

class InvoiceAllocation {
  const InvoiceAllocation({required this.invoiceId, required this.applied});

  final int invoiceId;
  final double applied;

  @override
  String toString() => 'invoice#$invoiceId ← $applied';
}

class AllocationResult {
  const AllocationResult({
    required this.invoices,
    required this.allocations,
    required this.surplusCredit,
  });

  final List<InvoiceModel> invoices;
  final List<InvoiceAllocation> allocations;

  /// الفائض الذي يصبح رصيداً دائناً (`credit`) للمشترك/العداد.
  final double surplusCredit;

  double get totalApplied =>
      allocations.fold<double>(0, (sum, a) => sum + a.applied);
}

class PaymentShortfall {
  const PaymentShortfall({
    required this.accepted,
    required this.message,
    required this.due,
    required this.overpay,
    required this.remainingAfter,
  });

  final bool accepted;
  final String? message;
  final double due;
  final double overpay;
  final double remainingAfter;
}
