import '../../../../core/accounting/account_isolation.dart';
import '../../data/models/invoice_model.dart';
import 'finance_enums.dart';

/// المحرك الحسابي للفواتير — نسخة Dart مطابقة 100% لمنطق Laravel.
///
/// المرجع المرجعية (source of truth):
///  - `Invoice::getRemainingAmount()`          → [ownRemaining]
///  - `Invoice::getTrueRemainingAmount()`      → [trueRemaining]
///  - `Invoice::isLatestInvoice()`             → [isLatestInvoice]
///  - `Invoice::updateStatus()`                → [computeStatus]
///  - `InvoiceResource` عمود `total_due`       → [totalDue]
///  - `InvoiceService::generateInvoice` سطر 96-104 (خصم الرصيد الدائن) → [previewNetPayable]
class InvoiceAccounting {
  InvoiceAccounting._();

  /// `total_amount + previous_balance` — الإجمالي المطلوب المعروض.
  static double totalDue(InvoiceModel invoice) =>
      invoice.totalAmount + invoice.previousBalance;

  /// `max(0, total_amount - amount_paid)` — متبقي الفاتورة نفسها فقط
  /// (المتأخرات السابقة تُعرض ولا تُضاف كي لا تتضاعف عند جمع الفواتير).
  static double ownRemaining(InvoiceModel invoice) {
    final value = invoice.totalAmount - invoice.amountPaid;
    return value < 0 ? 0 : value;
  }

  /// المتبقي التراكمي الحي: متبقي الفاتورة + متبقي كل أقدم منها في نفس الحساب المعزول.
  /// يطابق `getTrueRemainingAmount()` (تجميع بـ `subscriber_id + invoice_type (+meter_id)`).
  static double trueRemaining(
    InvoiceModel invoice,
    List<InvoiceModel> siblings,
  ) {
    if (invoice.serverTrueRemaining != null) {
      // الخادم أدق (يرى كل الفواتير وليس الصفحة الحالية فقط).
      return invoice.serverTrueRemaining!;
    }

    final groupKey = AccountIsolation.arrearsGroupKey(
      subscriberId: invoice.subscriberId,
      invoiceType: invoice.invoiceType.wire,
      meterId: invoice.meterId,
    );

    var total = ownRemaining(invoice);
    for (final other in siblings) {
      if (other.id >= invoice.id) continue;
      final otherKey = AccountIsolation.arrearsGroupKey(
        subscriberId: other.subscriberId,
        invoiceType: other.invoiceType.wire,
        meterId: other.meterId,
      );
      if (otherKey != groupKey) continue;
      total += ownRemaining(other);
    }
    return total < 0 ? 0 : total;
  }

  /// هل هذه أحدث فاتورة في حسابها المعزول؟ (`Invoice::isLatestInvoice()`)
  static bool isLatestInvoice(InvoiceModel invoice, List<InvoiceModel> siblings) {
    final flag = invoice.isLatestInvoice;
    if (flag != null) return flag;

    final groupKey = AccountIsolation.arrearsGroupKey(
      subscriberId: invoice.subscriberId,
      invoiceType: invoice.invoiceType.wire,
      meterId: invoice.meterId,
    );

    for (final other in siblings) {
      if (other.id <= invoice.id) continue;
      if (other.id == invoice.id) continue;
      final otherKey = AccountIsolation.arrearsGroupKey(
        subscriberId: other.subscriberId,
        invoiceType: other.invoiceType.wire,
        meterId: other.meterId,
      );
      if (otherKey == groupKey) return false;
    }
    return true;
  }

  /// العمود `remaining_amount` في Filament: المتبقي التراكمي للأحدث فقط، و`-` للقدمى.
  static double? displayRemaining(InvoiceModel invoice, List<InvoiceModel> siblings) {
    if (!isLatestInvoice(invoice, siblings)) return null;
    final remaining = trueRemaining(invoice, siblings);
    return remaining > 0 ? remaining : 0;
  }

  /// إعادة إنتاج `Invoice::updateStatus()` — للاستخدام في «المعاينة الفورية» بعد
  /// إدخال دفعة أوفلاين. مصدر الحقيقة يبقى الخادم.
  static InvoiceStatus computeStatus(
    InvoiceModel invoice, {
    required List<InvoiceModel> siblings,
    int gracePeriodDays = 14,
    bool strictOverdueOnNewInvoice = false,
    DateTime? now,
  }) {
    final reference = now ?? DateTime.now();
    final billDate = invoice.billDate ?? invoice.createdAt ?? reference;
    final dueDeadline = billDate.add(Duration(days: gracePeriodDays));
    final isPastGrace = reference.isAfter(dueDeadline);
    final latest = isLatestInvoice(invoice, siblings);

    if (latest) {
      final trueRem = trueRemaining(invoice, siblings);
      final totalDebt = invoice.totalAmount + invoice.previousBalance;

      if (trueRem <= 0) return InvoiceStatus.paid;
      if (trueRem < totalDebt) return InvoiceStatus.partiallyPaid;
      if (isPastGrace) return InvoiceStatus.overdue;
      return InvoiceStatus.issued;
    }

    final remaining = ownRemaining(invoice);
    if (remaining <= 0) return InvoiceStatus.paid;
    if (invoice.amountPaid > 0) return InvoiceStatus.partiallyPaid;
    if (strictOverdueOnNewInvoice || isPastGrace) return InvoiceStatus.overdue;
    return InvoiceStatus.issued;
  }

  /// معاينة صافي الفاتورة قبل الإصدار: `subtotal - min(credit, subtotal)`
  /// حيث `subtotal = consumptionFee` دون أي رسوم إضافية.
  static double previewNetPayable({
    required double consumptionFee,
    required double availableCredit,
  }) {
    final subtotal = consumptionFee;
    final creditApplied = availableCredit > 0 && subtotal > 0
        ? (availableCredit < subtotal ? availableCredit : subtotal)
        : 0.0;
    return subtotal - creditApplied;
  }

  /// مقدار الرصيد الدائن الذي سيُخصم عند الإصدار (للعرض في نافذة الإصدار).
  static double previewCreditApplied({
    required double consumptionFee,
    required double availableCredit,
  }) {
    final subtotal = consumptionFee;
    if (availableCredit <= 0 || subtotal <= 0) return 0;
    return availableCredit < subtotal ? availableCredit : subtotal;
  }

  /// أحدث فاتورة غير مسددة لمشترك (يستخدمه `CollectPaymentSheet` عند اختيار مشترك،
  /// مطابقاً لـ `PaymentResource::form` سطر 36-52: `orderBy('id','desc')` على
  /// `status != 'paid'`).
  static InvoiceModel? latestUnpaidOf(
    int subscriberId,
    List<InvoiceModel> invoices,
  ) {
    InvoiceModel? best;
    for (final invoice in invoices) {
      if (invoice.subscriberId != subscriberId) continue;
      if (invoice.status == InvoiceStatus.paid ||
          invoice.status == InvoiceStatus.draft ||
          invoice.status == InvoiceStatus.cancelled) {
        continue;
      }
      if (best == null || invoice.id > best.id) best = invoice;
    }
    return best;
  }

  /// كل الفواتير المفتوحة لنفس الحساب المعزول (العداد أو الحساب العام) بترتيب الأقدم
  /// — كما في `PaymentService::distributePayment`.
  static List<InvoiceModel> openInvoicesForAccount({
    required List<InvoiceModel> invoices,
    required int subscriberId,
    int? meterId,
  }) {
    final list = invoices
        .where((i) =>
            i.subscriberId == subscriberId &&
            i.status.isOpenForCollection &&
            AccountIsolation.sameAllocationAccount(
              paymentMeterId: meterId,
              invoiceMeterId: i.meterId,
            ))
        .toList();
    list.sort((a, b) {
      final byDate = a.billDate?.compareTo(b.billDate ?? DateTime(1970)) ?? 0;
      if (byDate != 0) return byDate;
      return a.id.compareTo(b.id);
    });
    return list;
  }
}
