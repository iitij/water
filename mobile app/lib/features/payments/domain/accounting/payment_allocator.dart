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
///  2. الفائض يُوزَّع FIFO على الأقدم داخل حساب المشترك الموحد؛ معرّف العداد مرجع فقط.
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
    if (invoices.map((i) => i.subscriberId).toSet().length > 1) {
      throw ArgumentError('Allocation must belong to a single subscriber.');
    }
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

    // 2) ثم FIFO على الأقدم داخل حساب المشترك.
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
    List<AccountTransferCredit> incomingTransfers = const [],
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

    final credits = <({double amount, int? target, DateTime? date, int kind, int id})>[
      for (final payment in payments)
        if (payment.subscriberId == subscriberId && !payment.isDeleted &&
            !payment.isVoided && !payment.isAccountTransferred)
          (amount: payment.effectiveAmount, target: payment.invoiceId,
            date: payment.paymentDate, kind: 0, id: payment.id),
      for (final transfer in incomingTransfers)
        if (transfer.subscriberId == subscriberId)
          (amount: transfer.amount, target: null, date: transfer.createdAt,
            kind: 1, id: transfer.id),
    ]..sort((a, b) {
      if (a.date != null && b.date != null) {
        final byDate = a.date!.compareTo(b.date!);
        if (byDate != 0) return byDate;
      } else if (a.date != null || b.date != null) {
        return a.date == null ? -1 : 1;
      }
      final byKind = a.kind.compareTo(b.kind);
      return byKind != 0 ? byKind : a.id.compareTo(b.id);
    });

    final replacements = <int, int>{
      for (final invoice in scoped)
        if (invoice.originalInvoiceId != null) invoice.originalInvoiceId!: invoice.id,
    };
    var current = scoped;
    var surplus = 0.0;

    for (final credit in credits) {
      final result = allocate(
        invoices: current,
        amount: credit.amount,
        targetInvoiceId: replacements[credit.target] ?? credit.target,
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
    final due = invoice.payableAmount;
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
              !i.isDeleted &&
              i.status != InvoiceStatus.draft &&
              i.status != InvoiceStatus.cancelled)
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

  static double _round(double value) => double.parse(value.toStringAsFixed(2));
}

/// Account transfer is an accounting credit, never a second physical receipt.
class AccountTransferCredit {
  const AccountTransferCredit({
    required this.id, required this.subscriberId, required this.amount,
    required this.createdAt,
  });
  final int id;
  final int subscriberId;
  final double amount;
  final DateTime createdAt;
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
