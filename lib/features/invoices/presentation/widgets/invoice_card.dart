import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/status_badge.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/accounting/invoice_accounting.dart';

/// بطاقة فاتورة موحّدة — **مكوّن مُسلَّم** لفؤاد (`SCR-05/07/19/20`) ولأسامة
/// (`SCR-03` ودجة الفواتير المتأخرة)، ولشاشة المحصل السريع `SCR-13`.
///
/// تعرض بدقة الأعمدة المطابقة لجدول Filament في `InvoiceResource::table`:
/// `beneficiary_name`, `bill_date`, الاستهلاك, المتأخرات, قيمة الفاتورة,
/// `total_due`, المسدد، والمتبقي (تراكمي للأحدث فقط، وإلا `-`).
class InvoiceCard extends StatelessWidget {
  const InvoiceCard({
    super.key,
    required this.invoice,
    required this.remainingLabel,
    this.dense = false,
    this.selected,
    this.onTap,
    this.onToggleSelect,
    this.onCollect,
    this.onPrint,
    this.onWhatsapp,
    this.canCollect = false,
    this.whatsappEnabled = false,
    this.thermalEnabled = false,
    this.onPrintThermal,
    this.busy = false,
    this.showCheckbox = false,
  });

  final InvoiceModel invoice;

  /// نص المتبقي المحسوب في طبقة العرض (`-` للفواتير القديمة).
  final String remainingLabel;
  final bool dense;
  final bool? selected;
  final VoidCallback? onTap;
  final VoidCallback? onToggleSelect;
  final VoidCallback? onCollect;
  final VoidCallback? onPrint;
  final VoidCallback? onPrintThermal;
  final VoidCallback? onWhatsapp;
  final bool canCollect;
  final bool whatsappEnabled;
  final bool thermalEnabled;
  final bool busy;
  final bool showCheckbox;

  @override
  Widget build(BuildContext context) {
    final remainingColor = remainingLabel == '-'
        ? AppColors.neutral
        : (invoice.netPayable > 0 ? AppColors.danger : AppColors.success);

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Padding(
          padding: EdgeInsets.all(dense ? 10 : 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  if (showCheckbox) ...<Widget>[
                    Checkbox(
                      value: selected ?? false,
                      onChanged: (_) => onToggleSelect?.call(),
                    ),
                    const SizedBox(width: 4),
                  ],
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        LtrNumberText(
                          invoice.billNumber.isEmpty ? '—' : invoice.billNumber,
                          style: const TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: 15,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          invoice.beneficiaryName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontWeight: FontWeight.w600,
                            fontSize: 13,
                          ),
                        ),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: <Widget>[
                      InvoiceTypeBadge(type: invoice.invoiceType.wire, compact: dense),
                      const SizedBox(height: 4),
                      StatusBadge.fromStatus(invoice.status.colorKey, compact: dense),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: <Widget>[
                  PathChip(
                    squareName: invoice.subscriber?.squareName,
                    pathNumber: invoice.meter?.pathNumber,
                    highlighted: invoice.isPathMatch,
                  ),
                  _Pill(
                    label: 'التاريخ',
                    value: DateFormat.day(invoice.billDate),
                  ),
                  _Pill(
                    label: 'الفترة',
                    value: '${DateFormat.day(invoice.periodStart)} ← '
                        '${DateFormat.day(invoice.periodEnd)}',
                  ),
                  if (invoice.previousBalance > 0)
                    _Pill(
                      label: 'متأخرات',
                      value: CurrencyFormatter.amount(invoice.previousBalance),
                      color: AppColors.warning,
                    ),
                ],
              ),
              const SizedBox(height: 10),
              Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: <Widget>[
                  Expanded(
                    child: _AmountColumn(
                      label: 'قيمة الفاتورة',
                      value: invoice.totalAmount,
                    ),
                  ),
                  Expanded(
                    child: _AmountColumn(
                      label: 'إجمالي المطلوب',
                      value: InvoiceAccounting.totalDue(invoice),
                      color: AppColors.danger,
                    ),
                  ),
                  Expanded(
                    child: _AmountColumn(
                      label: 'المسدد',
                      value: invoice.amountPaid,
                      color: AppColors.success,
                    ),
                  ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(
                          'المتبقي',
                          style: TextStyle(
                            fontSize: 11,
                            color: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.color
                                ?.withValues(alpha: 0.7),
                          ),
                        ),
                        const SizedBox(height: 2),
                        remainingLabel == '-'
                            ? Text(
                                '-',
                                style: TextStyle(
                                  fontSize: 14,
                                  fontWeight: FontWeight.w800,
                                  color: remainingColor,
                                ),
                              )
                            : LtrNumberText(
                                remainingLabel,
                                style: TextStyle(
                                  fontSize: 14,
                                  fontWeight: FontWeight.w800,
                                  color: remainingColor,
                                ),
                              ),
                      ],
                    ),
                  ),
                ],
              ),
              if (onCollect != null || onPrint != null || onWhatsapp != null) ...<Widget>[
                const Divider(height: 20),
                Row(
                  children: <Widget>[
                    if (onCollect != null)
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: busy ? null : (canCollect ? onCollect : null),
                          style: FilledButton.styleFrom(
                            backgroundColor: AppColors.success,
                            padding: const EdgeInsets.symmetric(vertical: 10),
                          ),
                          icon: const Icon(Icons.payments_outlined, size: 18),
                          label: Text(dense ? 'تحصيل' : 'تحصيل فوري'),
                        ),
                      ),
                    if (onCollect != null) const SizedBox(width: 8),
                    if (onPrint != null)
                      _IconAction(
                        icon: Icons.picture_as_pdf_outlined,
                        tooltip: 'طباعة PDF',
                        onPressed: busy ? null : onPrint,
                      ),
                    if (onPrintThermal != null && thermalEnabled)
                      _IconAction(
                        icon: Icons.print_outlined,
                        tooltip: 'طباعة حرارية',
                        onPressed: busy ? null : onPrintThermal,
                      ),
                    if (onWhatsapp != null && whatsappEnabled)
                      _IconAction(
                        icon: Icons.chat_outlined,
                        tooltip: 'إرسال واتساب',
                        onPressed: busy ? null : onWhatsapp,
                        color: const Color(0xFF25D366),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _AmountColumn extends StatelessWidget {
  const _AmountColumn({required this.label, required this.value, this.color});

  final String label;
  final double value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(
          label,
          style: TextStyle(
            fontSize: 11,
            color: Theme.of(context).textTheme.bodySmall?.color?.withValues(alpha: 0.7),
          ),
        ),
        const SizedBox(height: 2),
        LtrNumberText(
          CurrencyFormatter.amount(value),
          style: TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: color ?? AppColors.primaryDark,
          ),
        ),
      ],
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label, required this.value, this.color});

  final String label;
  final String value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final tone = color ?? AppColors.neutral;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        '$label: $value',
        style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: tone),
      ),
    );
  }
}

class _IconAction extends StatelessWidget {
  const _IconAction({
    required this.icon,
    required this.tooltip,
    required this.onPressed,
    this.color,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tooltip,
      onPressed: onPressed,
      visualDensity: VisualDensity.compact,
      style: IconButton.styleFrom(minimumSize: const Size(38, 38)),
      icon: Icon(icon, size: 20, color: color),
    );
  }
}

/// شريط ملخّص أعلى القوائم (عدّاد الفواتير/إجمالي المستحق) — مطابق لترويسة Filament.
class InvoicesSummaryBar extends StatelessWidget {
  const InvoicesSummaryBar({
    super.key,
    required this.count,
    required this.openCount,
    required this.overdueCount,
    required this.totalDue,
  });

  final int count;
  final int openCount;
  final int overdueCount;
  final double totalDue;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: <Widget>[
          _stat(context, 'الفواتير', '$count'),
          _stat(context, 'مفتوحة', '$openCount'),
          _stat(context, 'متأخرة', '$overdueCount', color: const Color(0xFFFCA5A5)),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: <Widget>[
                const Text(
                  'إجمالي المستحق',
                  style: TextStyle(color: Colors.white70, fontSize: 11),
                ),
                LtrNumberText(
                  CurrencyFormatter.withLabel(totalDue),
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 14,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _stat(BuildContext context, String label, String value, {Color? color}) {
    return Padding(
      padding: const EdgeInsets.only(left: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            label,
            style: const TextStyle(color: Colors.white70, fontSize: 11),
          ),
          Text(
            value,
            style: TextStyle(
              color: color ?? Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: 15,
            ),
          ),
        ],
      ),
    );
  }
}

/// أداة عرض «هل الفاتورة قابلة للتحصيل من هذه الشاشة؟» — مطابقة لشرط
/// `collect_payment` في `InvoiceResource` (مفتوحة + الأحدث).
bool canCollectInvoice(InvoiceModel invoice, List<InvoiceModel> siblings) =>
    invoice.status.isOpenForCollection &&
    InvoiceAccounting.isLatestInvoice(invoice, siblings);

/// نص متبقي الفاتورة كما يعرضه Filament (`-` للفواتير القديمة).
String remainingLabelFor(InvoiceModel invoice, List<InvoiceModel> siblings) {
  final value = InvoiceAccounting.displayRemaining(invoice, siblings);
  if (value == null) return '-';
  return CurrencyFormatter.debt(value);
}
