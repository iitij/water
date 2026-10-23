import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/status_badge.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/accounting/invoice_accounting.dart';

/// بطاقة فاتورة موحّدة — **مكوّن مُسلَّم** لفؤاد (`SCR-05/07/19/20`) ولأسامة
/// (`SCR-03` ودجة الفواتير المتأخرة)، ولشاشة المحصل السريع `SCR-13`.
///
/// التصميم: سطح مسطّح بحد رفيع، رقم الفاتورة أولاً، ثم المستفيد والتصنيف،
/// ثم سطر «المتبقي» البارز، وأخيراً ثلاثة أرقام ثانوية على سطر واحد مع الإجراءات.
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
    this.onMoreActions,
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
  final VoidCallback? onMoreActions;
  final bool canCollect;
  final bool whatsappEnabled;
  final bool thermalEnabled;
  final bool busy;
  final bool showCheckbox;

  bool get _hasActions =>
      onCollect != null || onPrint != null || onWhatsapp != null || onPrintThermal != null;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.labelSmall?.color;
    final remainingColor = remainingLabel == '-'
        ? scheme.onSurfaceVariant
        : (invoice.netPayable > 0 ? scheme.error : AppColors.success);

    return AppCard(
      padding: EdgeInsets.zero,
      onTap: onTap,
      borderColor: invoice.isPathMatch ? scheme.primary.withValues(alpha: .35) : null,
      child: Padding(
        padding: EdgeInsets.all(dense ? 12 : 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                if (showCheckbox) ...<Widget>[
                  SizedBox(
                    width: 26,
                    height: 26,
                    child: Checkbox(
                      value: selected ?? false,
                      onChanged: (_) => onToggleSelect?.call(),
                    ),
                  ),
                  const SizedBox(width: 6),
                ],
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      LtrNumberText(
                        invoice.billNumber.isEmpty ? '—' : invoice.billNumber,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w600,
                          fontSize: 14,
                          letterSpacing: .1,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        invoice.beneficiaryName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: <Widget>[
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        if (invoice.isDeleted) ...<Widget>[
                          const StatusBadge(status: 'danger', label: 'محذوفة'),
                          const SizedBox(width: 4),
                        ],
                        StatusBadge(
                          status: invoice.status.colorKey,
                          label: invoice.status.label,
                        ),
                        if (onMoreActions != null)
                          IconButton(
                            visualDensity: VisualDensity.compact,
                            tooltip: 'خيارات الفاتورة',
                            padding: EdgeInsets.zero,
                            constraints: const BoxConstraints.tightFor(width: 30, height: 30),
                            icon: Icon(
                              Icons.more_horiz_rounded,
                              size: 19,
                              color: scheme.onSurfaceVariant,
                            ),
                            onPressed: busy ? null : onMoreActions,
                          ),
                      ],
                    ),
                    const SizedBox(height: 3),
                    InvoiceTypeBadge(type: invoice.invoiceType.wire, compact: dense),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: <Widget>[
                PathChip(
                  squareName: invoice.subscriber?.squareName,
                  pathNumber: invoice.meter?.pathNumber?.toString(),
                  highlighted: invoice.isPathMatch,
                ),
                _Meta(label: 'التاريخ', value: DateFormat.day(invoice.billDate)),
                _Meta(
                  label: 'الفترة',
                  value: '${DateFormat.day(invoice.periodStart)} ← ${DateFormat.day(invoice.periodEnd)}',
                ),
                if (invoice.previousBalance > 0)
                  _Meta(
                    label: 'متأخرات',
                    value: CurrencyFormatter.amount(invoice.previousBalance),
                    color: AppColors.warning,
                  ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text('المتبقي', style: theme.textTheme.labelSmall),
                      const SizedBox(height: 2),
                      remainingLabel == '-'
                          ? Text(
                              '-',
                              style: TextStyle(
                                fontSize: 19,
                                fontWeight: FontWeight.w600,
                                color: remainingColor,
                              ),
                            )
                          : Row(
                              textDirection: TextDirection.rtl,
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: <Widget>[
                                Flexible(
                                  child: LtrNumberText(
                                    remainingLabel.replaceAll(' ${CurrencyFormatter.currencyLabel}', ''),
                                    style: TextStyle(
                                      fontSize: 19,
                                      fontWeight: FontWeight.w600,
                                      height: 1.2,
                                      letterSpacing: -.3,
                                      color: remainingColor,
                                      fontFeatures: const <FontFeature>[
                                        FontFeature.tabularFigures(),
                                      ],
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 4),
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 3),
                                  child: Text('ر.ي', style: theme.textTheme.labelSmall),
                                ),
                              ],
                            ),
                    ],
                  ),
                ),
                if (onTap != null)
                  Icon(
                    Icons.chevron_left_rounded,
                    size: 18,
                    color: scheme.onSurfaceVariant.withValues(alpha: .7),
                  ),
              ],
            ),
            const SizedBox(height: 10),
            Container(height: 1, color: scheme.outlineVariant),
            const SizedBox(height: 10),
            Row(
              children: <Widget>[
                Expanded(
                  child: _MiniAmount(label: 'قيمة الفاتورة', value: invoice.totalAmount),
                ),
                Expanded(
                  child: _MiniAmount(
                    label: 'المطلوب',
                    value: InvoiceAccounting.totalDue(invoice),
                    color: scheme.error,
                  ),
                ),
                Expanded(
                  child: _MiniAmount(
                    label: 'المسدد',
                    value: invoice.amountPaid,
                    color: AppColors.success,
                  ),
                ),
                if (onCollect != null) ...<Widget>[
                  const SizedBox(width: 6),
                  SizedBox(
                    height: 36,
                    child: FilledButton.icon(
                      onPressed: busy || !canCollect ? null : onCollect,
                      style: FilledButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        minimumSize: const Size(0, 36),
                        textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                      ),
                      icon: const Icon(Icons.payments_rounded, size: 15),
                      label: Text(dense ? 'تحصيل' : 'تحصيل'),
                    ),
                  ),
                ],
              ],
            ),
            if (_hasActions && (onPrint != null || onWhatsapp != null || onPrintThermal != null)) ...<Widget>[
              const SizedBox(height: 6),
              Row(
                children: <Widget>[
                  if (onPrint != null)
                    _TextAction(
                      icon: Icons.picture_as_pdf_outlined,
                      label: 'PDF',
                      onPressed: busy ? null : onPrint,
                    ),
                  if (onPrintThermal != null && thermalEnabled)
                    _TextAction(
                      icon: Icons.print_outlined,
                      label: 'حرارية',
                      onPressed: busy ? null : onPrintThermal,
                    ),
                  if (onWhatsapp != null && whatsappEnabled)
                    _TextAction(
                      icon: Icons.chat_bubble_outline_rounded,
                      label: 'واتساب',
                      onPressed: busy ? null : onWhatsapp,
                    ),
                  const Spacer(),
                  if (muted != null && (invoice.subscriber?.phone ?? '').isNotEmpty)
                    Text(invoice.subscriber!.phone!, style: theme.textTheme.labelSmall),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.label, required this.value, this.color});

  final String label;
  final String value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final tone = color == null ? scheme.onSurfaceVariant : AppSemantic.resolve(context, color!);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Text(
        '$label · $value',
        style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.w500, color: tone, height: 1.2),
      ),
    );
  }
}

class _MiniAmount extends StatelessWidget {
  const _MiniAmount({required this.label, required this.value, this.color});

  final String label;
  final double value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: theme.textTheme.labelSmall?.copyWith(fontSize: 10)),
        const SizedBox(height: 1),
        LtrNumberText(
          CurrencyFormatter.amount(value),
          style: TextStyle(
            fontSize: 12,
            height: 1.25,
            fontWeight: FontWeight.w600,
            color: color == null
                ? theme.colorScheme.onSurface
                : AppSemantic.resolve(context, color!),
            fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}

class _TextAction extends StatelessWidget {
  const _TextAction({required this.icon, required this.label, required this.onPressed});

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return TextButton.icon(
      onPressed: onPressed,
      style: TextButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 6),
        minimumSize: const Size(0, 32),
        foregroundColor: scheme.onSurfaceVariant,
        textStyle: const TextStyle(fontSize: 11, fontWeight: FontWeight.w500),
      ),
      icon: Icon(icon, size: 15),
      label: Text(label),
    );
  }
}

/// شريط ملخّص أعلى القوائم — سطح حبر واحد مضغوط بدل شريط متدرّج.
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
      margin: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 0),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: AppColors.panel,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.panelLine),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            flex: 4,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Text(
                  'إجمالي المستحق',
                  style: TextStyle(color: AppColors.onPanelMuted, fontSize: 10.5, height: 1.3),
                ),
                const SizedBox(height: 2),
                LtrNumberText(
                  CurrencyFormatter.amount(totalDue),
                  style: const TextStyle(
                    color: AppColors.onPanel,
                    fontWeight: FontWeight.w600,
                    fontSize: 17,
                    height: 1.2,
                    letterSpacing: -.3,
                    fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
                  ),
                ),
              ],
            ),
          ),
          Container(
            width: 1,
            height: 30,
            color: Colors.white.withValues(alpha: .12),
            margin: const EdgeInsets.symmetric(horizontal: 10),
          ),
          Expanded(flex: 5, child: _Strip(count: 'الفواتير', value: '$count', open: openCount, overdue: overdueCount)),
        ],
      ),
    );
  }
}

class _Strip extends StatelessWidget {
  const _Strip({required this.count, required this.value, required this.open, required this.overdue});

  final String count;
  final String value;
  final int open;
  final int overdue;

  @override
  Widget build(BuildContext context) {
    const label = TextStyle(color: AppColors.onPanelMuted, fontSize: 10, height: 1.2);
    const number = TextStyle(
      color: AppColors.onPanelSoft,
      fontSize: 13,
      fontWeight: FontWeight.w600,
      height: 1.3,
    );
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: <Widget>[
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[Text(count, style: label), Text(value, style: number)],
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[const Text('مفتوحة', style: label), Text('$open', style: number)],
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Text('متأخرة', style: label),
            Text(
              '$overdue',
              style: overdue > 0
                  ? number.copyWith(color: AppSemantic.resolve(context, AppColors.danger))
                  : number,
            ),
          ],
        ),
      ],
    );
  }
}

/// أداة عرض «هل الفاتورة قابلة للتحصيل من هذه الشاشة؟» — مطابقة لشرط
/// `collect_payment` في `InvoiceResource` (مفتوحة + الأحدث).
bool canCollectInvoice(InvoiceModel invoice, List<InvoiceModel> siblings) =>
    invoice.status.isOpenForCollection && InvoiceAccounting.isLatestInvoice(invoice, siblings);

/// نص متبقي الفاتورة كما يعرضه Filament (`-` للفواتير القديمة).
String remainingLabelFor(InvoiceModel invoice, List<InvoiceModel> siblings) {
  final value = InvoiceAccounting.displayRemaining(invoice, siblings);
  if (value == null) return '-';
  return CurrencyFormatter.debt(value);
}
