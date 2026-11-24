import '../../../../core/services/esc_pos/thermal_ticket.dart';
import '../../../../core/utils/arabic_number_spellout.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/utils/phone_formatter.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../data/models/payment_model.dart';
import '../../domain/accounting/payment_allocator.dart';

/// يبني سند القبض الحراري (`58mm/80mm`) — محتوى مطابق لـ `resources/views/pdf/receipt.blade.php`
/// لكن مضغوطاً، مع التفقيط العربي وإظهار «الرصيد الدائن المتبقي» عند الدفع الزائد
/// (قاعدة FIFO §3.4-3: الفائض يبقى رصيداً في نفس الحساب المعزول).
ThermalTicket buildPaymentThermalTicket(
  PaymentModel payment, {
  ReceiptDataModel? receiptData,
  InvoiceModel? invoice,
  String projectName = 'مشروع مياه الوشاح',
  AllocationResult? allocation,
}) {
  final remaining = receiptData?.invoiceRemaining ?? _localRemaining(payment, invoice);
  final credit = receiptData?.creditBalance ?? allocation?.surplusCredit ?? 0.0;
  final outstanding = receiptData?.outstandingBalance;

  final lines = <ThermalLine>[
    ThermalSyntax.heading(projectName),
    ThermalSyntax.subheading('سند قبض'),
    const ThermalLine.divider(),
    ThermalSyntax.field('رقم السند', payment.receiptNumber),
    ThermalSyntax.field('التاريخ', DateFormat.day(payment.paymentDate)),
    ThermalSyntax.field('استلمنا من', payment.displayName),
    if (payment.subscriberPhone != null)
      ThermalSyntax.field('الهاتف', PhoneFormatter.forReceipt(payment.subscriberPhone)),
    if (payment.invoiceBillNumber != null)
      ThermalSyntax.field('بموجب فاتورة', payment.invoiceBillNumber!),
    ThermalSyntax.field(
      'طريقة الدفع',
      payment.referenceNumber == null || payment.referenceNumber!.isEmpty
          ? payment.method.label
          : '${payment.method.label} — ${payment.referenceNumber}',
    ),
    const ThermalLine.divider(),
    if (allocation != null && allocation.allocations.isNotEmpty) ...<ThermalLine>[
      const ThermalLine('توزيع الدفعة:', bold: true, fontSizePx: 13),
      ...allocation.allocations.map((entry) {
        final matches = allocation.invoices.where((i) => i.id == entry.invoiceId);
        final label = matches.isEmpty
            ? 'فاتورة #${entry.invoiceId}'
            : matches.first.billNumber;
        return ThermalSyntax.field(label, CurrencyFormatter.amount(entry.applied));
      }),
      const ThermalLine.divider(),
    ],
    ThermalSyntax.big('${CurrencyFormatter.amount(payment.amount)} ريال'),
    ThermalSyntax.gap(heightPx: 4),
    ThermalLine(
      ArabicNumberSpellout.spelloutYemeniRial(payment.amount),
      align: ThermalAlign.center,
      fontSizePx: 15,
    ),
    const ThermalLine.divider(),
    if (invoice != null || receiptData != null)
      ThermalSyntax.field('متبقي الفاتورة', CurrencyFormatter.debt(remaining), bold: true),
    if (credit > 0)
      ThermalSyntax.field('رصيد دائن (زائد)', CurrencyFormatter.withLabel(credit)),
    if (outstanding != null)
      ThermalSyntax.field('إجمالي المستحق', CurrencyFormatter.debt(outstanding)),
    if (payment.notes != null && payment.notes!.isNotEmpty)
      ThermalSyntax.field('ملاحظات', payment.notes!),
    if (payment.collectorName != null && payment.collectorName!.isNotEmpty)
      ThermalSyntax.field('المحصّل', payment.collectorName!),
    ThermalSyntax.gap(heightPx: 12),
    const ThermalLine(
      'شكراً لتعاملكم معنا',
      align: ThermalAlign.center,
      fontSizePx: 14,
    ),
  ];

  return ThermalTicket(title: 'سند ${payment.receiptNumber}', lines: lines);
}

double _localRemaining(PaymentModel payment, InvoiceModel? invoice) {
  if (invoice == null) return 0;
  final rest = invoice.netPayable - payment.amount;
  return rest > 0 ? double.parse(rest.toStringAsFixed(2)) : 0;
}
