import '../../../../core/services/esc_pos/thermal_ticket.dart';
import '../../../../core/utils/arabic_number_spellout.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/utils/phone_formatter.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/accounting/finance_enums.dart';

/// يبني تذكرة الطابعة الحرارية للفاتورة — محتوى مطابق لحقول `invoice.blade.php`
/// ولكن مضغوطاً لعرض 58mm/80mm، مع التفقيط العربي للمبلغ.
ThermalTicket buildInvoiceThermalTicket(
  InvoiceModel invoice, {
  String projectName = 'مشروع مياه الوشاح',
  String? collectorName,
}) {
  final lines = <ThermalLine>[
    ThermalSyntax.heading(projectName),
    ThermalSyntax.subheading('فاتورة ${invoice.invoiceType.label}'),
    const ThermalLine.divider(),
    ThermalSyntax.field('رقم الفاتورة', invoice.billNumber),
    ThermalSyntax.field('التاريخ', DateFormat.day(invoice.billDate)),
    ThermalSyntax.field('الفترة', '${DateFormat.day(invoice.periodStart)} ← ${DateFormat.day(invoice.periodEnd)}'),
    ThermalSyntax.field('المستفيد', invoice.beneficiaryName),
    if (invoice.meter != null)
      ThermalSyntax.field('العداد', invoice.meter!.label)
    else if (invoice.retiredMeterCode != null)
      ThermalSyntax.field('العداد', invoice.retiredMeterCode!),
    if (invoice.subscriber?.squareName != null)
      ThermalSyntax.field('المربع', invoice.subscriber!.squareName!),
    if (invoice.messageRecipientPhone != null)
      ThermalSyntax.field('الهاتف', PhoneFormatter.forReceipt(invoice.messageRecipientPhone)),
    const ThermalLine.divider(),
    if (invoice.tariffLines?.isNotEmpty == true) ...<ThermalLine>[
      for (final line in invoice.tariffLines!) ...<ThermalLine>[
        ThermalSyntax.field('الفترة', '${line.periodStart} — ${line.periodEnd}'),
        ThermalSyntax.field('الاستهلاك', '${CurrencyFormatter.amount(line.units)} وحدة × ${CurrencyFormatter.amount(line.unitPrice)}'),
        ThermalSyntax.field('المبلغ', CurrencyFormatter.amount(line.amount)),
      ],
    ] else ...<ThermalLine>[
      if (invoice.readings.isNotEmpty || invoice.correctedUnits != null)
        ThermalSyntax.field('الاستهلاك', '${CurrencyFormatter.amount(invoice.consumptionUnits)} وحدة'),
      ThermalSyntax.field('سعر الوحدة', 'غير موثقة'),
    ],
    ThermalSyntax.field('قيمة الاستهلاك', CurrencyFormatter.amount(invoice.consumptionFee)),
    if (invoice.previousBalance > 0)
      ThermalSyntax.field('متأخرات سابقة', CurrencyFormatter.amount(invoice.previousBalance)),
    if (invoice.creditApplied > 0)
      ThermalSyntax.field('رصيد دائن مخصوم', CurrencyFormatter.amount(invoice.creditApplied)),
    if (invoice.amountPaid > 0)
      ThermalSyntax.field('المسدد', CurrencyFormatter.amount(invoice.amountPaid)),
    const ThermalLine.divider(),
    ThermalSyntax.big('${CurrencyFormatter.amount(invoice.netPayable)} ريال'),
    ThermalSyntax.gap(heightPx: 4),
    ThermalLine(
      ArabicNumberSpellout.spelloutYemeniRial(invoice.netPayable),
      align: ThermalAlign.center,
      fontSizePx: 15,
    ),
    if (invoice.status != InvoiceStatus.paid) ...<ThermalLine>[
      const ThermalLine.divider(),
      ThermalSyntax.field(
        'المتبقي التراكمي',
        CurrencyFormatter.debt(invoice.serverTrueRemaining ?? invoice.ownRemaining),
        bold: true,
      ),
    ],
    ThermalSyntax.field('الحالة', invoice.status.label),
    if (collectorName != null && collectorName.isNotEmpty)
      ThermalSyntax.field('المحصل', collectorName),
    if (invoice.notes != null && invoice.notes!.isNotEmpty)
      ThermalSyntax.field('ملاحظات', invoice.notes!),
    ThermalSyntax.gap(heightPx: 12),
    const ThermalLine(
      'شكراً لتعاملكم معنا',
      align: ThermalAlign.center,
      fontSizePx: 14,
    ),
  ];

  return ThermalTicket(title: 'فاتورة ${invoice.billNumber}', lines: lines);
}
