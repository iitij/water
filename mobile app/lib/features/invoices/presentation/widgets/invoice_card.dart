import 'package:flutter/material.dart';

import '../../../../core/l10n/collection_ui_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/record_location_label.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/status_badge.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/accounting/invoice_accounting.dart';

/// بطاقة فاتورة موحّدة — **مكوّن مُسلَّم** لفؤاد (`SCR-05/07/19/20`) ولأسامة
/// (`SCR-03` ودجة الفواتير المتأخرة)، ولشاشة المحصل السريع `SCR-13`.
///
/// اسم المستفيد والمتبقي أولاً؛ التفاصيل الحسابية والموقع خلف قسم قابل للتوسيع.
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
    this.hiddenFields = const <String>{},
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
  final Set<String> hiddenFields;
  bool _visible(String field) => !hiddenFields.contains(field);

  bool get _hasActions =>
      onCollect != null ||
      onPrint != null ||
      onWhatsapp != null ||
      onPrintThermal != null;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final historical = remainingLabel == '-';
    final remainingColor = historical
        ? scheme.onSurfaceVariant
        : (invoice.payableAmount > 0 ? scheme.error : AppColors.success);

    return AppCard(
      padding: EdgeInsets.zero,
      onTap: onTap,
      borderColor: invoice.isPathMatch
          ? scheme.primary.withValues(alpha: .35)
          : null,
      child: Padding(
        padding: EdgeInsets.all(dense ? 12 : 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                if (showCheckbox)
                  SizedBox(
                    width: 48,
                    height: 48,
                    child: Checkbox(
                      value: selected ?? false,
                      onChanged: busy || onToggleSelect == null
                          ? null
                          : (_) => onToggleSelect!(),
                    ),
                  ),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Text(
                      invoice.beneficiaryName,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        height: 1.4,
                      ),
                    ),
                  ),
                ),
                if (onMoreActions != null)
                  _IconAction(
                    icon: Icons.more_horiz_rounded,
                    tooltip: CollectionUiStrings.invoiceOptions,
                    onPressed: busy ? null : onMoreActions,
                  ),
              ],
            ),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: <Widget>[
                Text(
                  '${CollectionUiStrings.invoice} ${invoice.billNumber.isEmpty ? '—' : invoice.billNumber}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
                StatusBadge(
                  status: invoice.status.colorKey,
                  label: invoice.status.label,
                ),
                if (invoice.isDeleted)
                  const StatusBadge(
                    status: 'danger',
                    label: CollectionUiStrings.deleted,
                  ),
              ],
            ),
            const SizedBox(height: 16),
            Text(
              historical
                  ? CollectionUiStrings.historicalBalance
                  : CollectionUiStrings.remaining,
              style: theme.textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
            if (!historical) ...<Widget>[
              const SizedBox(height: 4),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: Semantics(
                  label: remainingLabel,
                  child: ExcludeSemantics(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: AlignmentDirectional.centerStart,
                      child: Text(
                        remainingLabel,
                        maxLines: 1,
                        softWrap: false,
                        textDirection: TextDirection.ltr,
                        style: theme.textTheme.headlineSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: remainingColor,
                          fontFeatures: const <FontFeature>[
                            FontFeature.tabularFigures(),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
            if (_hasActions) ...<Widget>[
              const SizedBox(height: 12),
              LayoutBuilder(
                builder: (context, constraints) {
                  final actions = <Widget>[
                    if (onPrint != null)
                      _IconAction(
                        icon: Icons.picture_as_pdf_outlined,
                        tooltip: CollectionUiStrings.pdf,
                        onPressed: busy ? null : onPrint,
                      ),
                    if (onPrintThermal != null && thermalEnabled)
                      _IconAction(
                        icon: Icons.print_outlined,
                        tooltip: CollectionUiStrings.thermalPrint,
                        onPressed: busy ? null : onPrintThermal,
                      ),
                    if (onWhatsapp != null && whatsappEnabled)
                      _IconAction(
                        icon: Icons.chat_bubble_outline_rounded,
                        tooltip: CollectionUiStrings.whatsapp,
                        onPressed: busy ? null : onWhatsapp,
                      ),
                  ];
                  final collect = onCollect != null && !historical
                      ? FilledButton.icon(
                          onPressed:
                              busy || !canCollect || invoice.payableAmount <= 0
                              ? null
                              : onCollect,
                          style: FilledButton.styleFrom(
                            minimumSize: const Size(0, 48),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 12,
                            ),
                          ),
                          icon: const Icon(Icons.payments_outlined, size: 20),
                          label: const Text(CollectionUiStrings.collectPayment),
                        )
                      : null;
                  final inline =
                      MediaQuery.textScalerOf(context).scale(14) <= 18 &&
                      constraints.maxWidth - actions.length * 56 >= 160;
                  if (collect != null && inline) {
                    return Row(
                      children: [
                        Expanded(child: collect),
                        for (final action in actions) ...[
                          const SizedBox(width: 8),
                          action,
                        ],
                      ],
                    );
                  }
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (collect != null) collect,
                      if (collect != null && actions.isNotEmpty)
                        const SizedBox(height: 8),
                      if (actions.isNotEmpty)
                        Wrap(spacing: 8, runSpacing: 8, children: actions),
                    ],
                  );
                },
              ),
            ],
            const SizedBox(height: 8),
            Theme(
              data: theme.copyWith(dividerColor: Colors.transparent),
              child: ExpansionTile(
                tilePadding: EdgeInsets.zero,
                childrenPadding: const EdgeInsets.only(bottom: 8),
                title: Text(
                  CollectionUiStrings.details,
                  style: theme.textTheme.bodyMedium,
                ),
                children: <Widget>[
                  _DetailAmount(
                    label: CollectionUiStrings.invoiceValue,
                    value: invoice.totalAmount,
                  ),
                  if (invoice.previousBalance > 0)
                    _DetailAmount(
                      label: CollectionUiStrings.previousBalance,
                      value: invoice.previousBalance,
                    ),
                  _DetailAmount(
                    label: CollectionUiStrings.paid,
                    value: invoice.amountPaid,
                  ),
                  if (invoice.creditApplied > 0)
                    _DetailAmount(
                      label: CollectionUiStrings.creditApplied,
                      value: invoice.creditApplied,
                    ),
                  const SizedBox(height: 12),
                  if (_visible('location')) ...<Widget>[
                    RecordLocationLabel(location: invoice.location),
                    if (_locationText.isNotEmpty)
                      _QuietMeta(
                        icon: Icons.location_on_outlined,
                        text: _locationText,
                      ),
                    const SizedBox(height: 8),
                  ],
                  Wrap(
                    spacing: 12,
                    runSpacing: 8,
                    children: <Widget>[
                      _QuietMeta(
                        icon: _typeIcon(invoice.invoiceType.wire),
                        text: _typeLabel(invoice.invoiceType.wire),
                      ),
                      if (_visible('date'))
                        _QuietMeta(
                          icon: Icons.event_outlined,
                          text: DateFormat.day(invoice.billDate),
                          ltr: true,
                        ),
                      if (_visible('period'))
                        _QuietMeta(
                          icon: Icons.date_range_outlined,
                          text:
                              '${DateFormat.day(invoice.periodStart)} ← ${DateFormat.day(invoice.periodEnd)}',
                          ltr: true,
                        ),
                      if (_visible('phone') &&
                          (invoice.subscriber?.phone ?? '').isNotEmpty)
                        _QuietMeta(
                          icon: Icons.call_outlined,
                          text: invoice.subscriber!.phone!,
                          ltr: true,
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  String get _locationText {
    final square = invoice.subscriber?.squareName?.trim();
    final path = invoice.meter?.pathNumber?.toString();
    final parts = <String>[
      if (square != null && square.isNotEmpty) square,
      if (path != null && path.isNotEmpty) 'مسار $path',
    ];
    return parts.join(' · ');
  }

  static String _typeLabel(String wire) => switch (wire) {
    'meter' => 'عداد',
    _ => wire,
  };

  static IconData _typeIcon(String wire) => switch (wire) {
    'meter' => Icons.speed_rounded,
    _ => Icons.receipt_long_rounded,
  };
}

/// عنصر معلومات صامت — أيقونة + نص فقط، بلا إطار ولا خلفية.
class _QuietMeta extends StatelessWidget {
  const _QuietMeta({required this.icon, required this.text, this.ltr = false});

  final IconData icon;
  final String text;
  final bool ltr;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final color = scheme.onSurfaceVariant;
    final style = TextStyle(
      fontSize: 13,
      fontWeight: FontWeight.w500,
      color: color,
      height: 1.3,
    );
    final label = ltr
        ? Directionality(
            textDirection: TextDirection.ltr,
            child: Text(text, style: style),
          )
        : Text(text, style: style);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(icon, size: 13, color: color),
        const SizedBox(width: 4),
        Flexible(child: label),
      ],
    );
  }
}

class _DetailAmount extends StatelessWidget {
  const _DetailAmount({required this.label, required this.value});
  final String label;
  final double value;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text(label, style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 2),
        Align(
          alignment: AlignmentDirectional.centerEnd,
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              CurrencyFormatter.withLabel(value),
              textDirection: TextDirection.ltr,
              maxLines: 1,
              softWrap: false,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ),
      ],
    ),
  );
}

class _IconAction extends StatelessWidget {
  const _IconAction({
    required this.icon,
    required this.tooltip,
    required this.onPressed,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return IconButton(
      tooltip: tooltip,
      onPressed: onPressed,
      visualDensity: VisualDensity.standard,
      style: IconButton.styleFrom(
        backgroundColor: scheme.surfaceContainerHighest.withValues(alpha: .45),
        minimumSize: const Size(48, 48),
        maximumSize: const Size(48, 48),
      ),
      icon: Icon(icon, size: 18, color: scheme.onSurfaceVariant),
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
    this.pageOnly = false,
  });

  final int count;
  final int openCount;
  final int overdueCount;
  final double totalDue;
  final bool pageOnly;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      margin: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 0),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLow,
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            spacing: 12,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                pageOnly
                    ? ListOptionsStrings.pageTotal
                    : CollectionUiStrings.totalDue,
                style: theme.textTheme.bodySmall,
              ),
              Text(
                CurrencyFormatter.withLabel(totalDue),
                textDirection: TextDirection.ltr,
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          _Strip(
            count: pageOnly
                ? ListOptionsStrings.pageCount
                : CollectionUiStrings.invoices,
            value: '$count',
            open: openCount,
            overdue: overdueCount,
          ),
        ],
      ),
    );
  }
}

class _Strip extends StatelessWidget {
  const _Strip({
    required this.count,
    required this.value,
    required this.open,
    required this.overdue,
  });

  final String count;
  final String value;
  final int open;
  final int overdue;

  @override
  Widget build(BuildContext context) {
    final label = Theme.of(context).textTheme.bodySmall;
    final number = Theme.of(
      context,
    ).textTheme.bodyMedium!.copyWith(fontWeight: FontWeight.w600);
    Widget statistic(String labelText, String valueText, {Color? color}) =>
        Text.rich(
          TextSpan(
            children: [
              TextSpan(
                text: valueText,
                style: number.copyWith(color: color),
              ),
              TextSpan(text: ' $labelText', style: label),
            ],
          ),
        );
    return Wrap(
      alignment: WrapAlignment.spaceBetween,
      spacing: 12,
      runSpacing: 8,
      children: [
        statistic(count, value),
        statistic(CollectionUiStrings.open, '$open'),
        statistic(
          CollectionUiStrings.overdue,
          '$overdue',
          color: overdue > 0
              ? AppSemantic.resolve(context, AppColors.danger)
              : null,
        ),
      ],
    );
  }
}

/// أداة عرض «هل الفاتورة قابلة للتحصيل من هذه الشاشة؟» — مطابقة لشرط
/// `collect_payment` في `InvoiceResource` (مفتوحة + الأحدث).
bool canCollectInvoice(InvoiceModel invoice, List<InvoiceModel> siblings) =>
    !invoice.isDeleted &&
    invoice.status.isOpenForCollection &&
    invoice.payableAmount > 0 &&
    InvoiceAccounting.isLatestInvoice(invoice, siblings);

/// نص متبقي الفاتورة كما يعرضه Filament (`-` للفواتير القديمة).
String remainingLabelFor(InvoiceModel invoice, List<InvoiceModel> siblings) {
  final value = InvoiceAccounting.displayRemaining(invoice, siblings);
  if (value == null) return '-';
  return CurrencyFormatter.debt(value);
}
