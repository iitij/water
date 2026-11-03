import 'package:flutter/material.dart';

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
/// تصميم v3 — «بطاقة هادئة»:
///  - اسم المستفيد أولاً (أهم ما يبحث عنه المحصّل) ورقم الفاتورة سطر ثانوي.
///  - سطر معلومات واحد بلا إطارات (موقع · تاريخ · فترة · متأخرات · هاتف)
///    بدل حبّات مُحيّطة مكدّسة.
///  - لوحة مبالغ ناعمة: «المتبقي» كبطل + ثلاثة أرقام مساندة.
///  - شريط إجراءات واحد: زر التحصيل الأساسي + أيقونات مضغوطة.
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
    final remainingColor = remainingLabel == '-'
        ? scheme.onSurfaceVariant
        : (invoice.netPayable > 0 ? scheme.error : AppColors.success);

    return AppCard(
      padding: EdgeInsets.zero,
      onTap: onTap,
      borderColor: invoice.isPathMatch
          ? scheme.primary.withValues(alpha: .35)
          : null,
      child: Padding(
        padding: EdgeInsets.all(dense ? 12 : 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            RecordLocationLabel(location: invoice.location),
            const SizedBox(height: 4),
            // ── A. الترويسة: المستفيد أولاً + الحالة ──
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
                  const SizedBox(width: 8),
                ],
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        invoice.beneficiaryName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          height: 1.3,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Row(
                        children: <Widget>[
                          Flexible(
                            child: LtrNumberText(
                              invoice.billNumber.isEmpty
                                  ? '—'
                                  : invoice.billNumber,
                              style: TextStyle(
                                fontSize: 11.5,
                                fontWeight: FontWeight.w500,
                                letterSpacing: .2,
                                color: scheme.onSurfaceVariant,
                              ),
                            ),
                          ),
                          const SizedBox(width: 6),
                          Icon(
                            _typeIcon(invoice.invoiceType.wire),
                            size: 12,
                            color: scheme.onSurfaceVariant,
                          ),
                          const SizedBox(width: 3),
                          Text(
                            _typeLabel(invoice.invoiceType.wire),
                            style: TextStyle(
                              fontSize: 11,
                              color: scheme.onSurfaceVariant,
                            ),
                          ),
                        ],
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
                            constraints: const BoxConstraints.tightFor(
                              width: 30,
                              height: 30,
                            ),
                            icon: Icon(
                              Icons.more_horiz_rounded,
                              size: 19,
                              color: scheme.onSurfaceVariant,
                            ),
                            onPressed: busy ? null : onMoreActions,
                          ),
                      ],
                    ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 10),

            // ── B. سطر معلومات واحد بلا إطارات ──
            Wrap(
              spacing: 12,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: <Widget>[
                if (_visible('location') && _locationText.isNotEmpty)
                  _QuietMeta(
                    icon: Icons.location_on_outlined,
                    text: _locationText,
                    highlight: invoice.isPathMatch,
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
                if (invoice.previousBalance > 0)
                  _QuietMeta(
                    icon: Icons.warning_amber_rounded,
                    text:
                        'متأخرات ${CurrencyFormatter.amount(invoice.previousBalance)}',
                    tone: AppColors.warning,
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
            const SizedBox(height: 12),

            // ── C. لوحة المبالغ: المتبقي بطل + ثلاثة أرقام مساندة ──
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: scheme.surfaceContainerHighest.withValues(alpha: .45),
                borderRadius: BorderRadius.circular(AppRadius.lg),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: <Widget>[
                  Expanded(
                    flex: 5,
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
                                      remainingLabel.replaceAll(
                                        ' ${CurrencyFormatter.currencyLabel}',
                                        '',
                                      ),
                                      style: TextStyle(
                                        fontSize: 20,
                                        fontWeight: FontWeight.w600,
                                        height: 1.2,
                                        letterSpacing: -.4,
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
                                    child: Text(
                                      'ر.ي',
                                      style: theme.textTheme.labelSmall,
                                    ),
                                  ),
                                ],
                              ),
                      ],
                    ),
                  ),
                  Container(
                    width: 1,
                    height: 34,
                    margin: const EdgeInsets.symmetric(horizontal: 10),
                    color: scheme.outlineVariant,
                  ),
                  Expanded(
                    flex: 3,
                    child: _MiniAmount(
                      label: 'قيمة الفاتورة',
                      value: invoice.totalAmount,
                    ),
                  ),
                  Expanded(
                    flex: 3,
                    child: _MiniAmount(
                      label: 'المطلوب',
                      value: InvoiceAccounting.totalDue(invoice),
                      color: scheme.error,
                    ),
                  ),
                  Expanded(
                    flex: 3,
                    child: _MiniAmount(
                      label: 'المسدد',
                      value: invoice.amountPaid,
                      color: AppColors.success,
                    ),
                  ),
                ],
              ),
            ),

            // ── D. شريط الإجراءات ──
            if (_hasActions) ...<Widget>[
              const SizedBox(height: 10),
              Row(
                children: <Widget>[
                  if (onCollect != null) ...<Widget>[
                    Expanded(
                      child: SizedBox(
                        height: 38,
                        child: FilledButton.icon(
                          onPressed: busy || !canCollect ? null : onCollect,
                          style: FilledButton.styleFrom(
                            padding: const EdgeInsets.symmetric(horizontal: 10),
                            minimumSize: const Size(0, 38),
                            textStyle: const TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          icon: const Icon(Icons.payments_rounded, size: 16),
                          label: Text(
                            'تحصيل ${CurrencyFormatter.amount(invoice.netPayable)}',
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                  ],
                  if (onPrint != null)
                    _IconAction(
                      icon: Icons.picture_as_pdf_outlined,
                      tooltip: 'سند PDF',
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
                      icon: Icons.chat_bubble_outline_rounded,
                      tooltip: 'إرسال واتساب',
                      onPressed: busy ? null : onWhatsapp,
                    ),
                ],
              ),
            ],
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
    'subscription' => 'اشتراك',
    'tanker' => 'وايت',
    _ => wire,
  };

  static IconData _typeIcon(String wire) => switch (wire) {
    'meter' => Icons.speed_rounded,
    'subscription' => Icons.autorenew_rounded,
    'tanker' => Icons.local_shipping_outlined,
    _ => Icons.receipt_long_rounded,
  };
}

/// عنصر معلومات صامت — أيقونة + نص فقط، بلا إطار ولا خلفية.
class _QuietMeta extends StatelessWidget {
  const _QuietMeta({
    required this.icon,
    required this.text,
    this.tone,
    this.highlight = false,
    this.ltr = false,
  });

  final IconData icon;
  final String text;
  final Color? tone;
  final bool highlight;
  final bool ltr;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final color = tone == null
        ? (highlight ? scheme.primary : scheme.onSurfaceVariant)
        : AppSemantic.resolve(context, tone!);
    final style = TextStyle(
      fontSize: 11,
      fontWeight: highlight ? FontWeight.w600 : FontWeight.w500,
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
      visualDensity: VisualDensity.compact,
      style: IconButton.styleFrom(
        backgroundColor: scheme.surfaceContainerHighest.withValues(alpha: .45),
        minimumSize: const Size(38, 38),
        maximumSize: const Size(38, 38),
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
                Text(
                  pageOnly ? ListOptionsStrings.pageTotal : 'إجمالي المستحق',
                  style: const TextStyle(
                    color: AppColors.onPanelMuted,
                    fontSize: 10.5,
                    height: 1.3,
                  ),
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
          Expanded(
            flex: 5,
            child: _Strip(
              count: pageOnly ? ListOptionsStrings.pageCount : 'الفواتير',
              value: '$count',
              open: openCount,
              overdue: overdueCount,
            ),
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
    const label = TextStyle(
      color: AppColors.onPanelMuted,
      fontSize: 10,
      height: 1.2,
    );
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
          children: <Widget>[
            Text(count, style: label),
            Text(value, style: number),
          ],
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Text('مفتوحة', style: label),
            Text('$open', style: number),
          ],
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Text('متأخرة', style: label),
            Text(
              '$overdue',
              style: overdue > 0
                  ? number.copyWith(
                      color: AppSemantic.resolve(context, AppColors.danger),
                    )
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
    invoice.status.isOpenForCollection &&
    InvoiceAccounting.isLatestInvoice(invoice, siblings);

/// نص متبقي الفاتورة كما يعرضه Filament (`-` للفواتير القديمة).
String remainingLabelFor(InvoiceModel invoice, List<InvoiceModel> siblings) {
  final value = InvoiceAccounting.displayRemaining(invoice, siblings);
  if (value == null) return '-';
  return CurrencyFormatter.debt(value);
}
