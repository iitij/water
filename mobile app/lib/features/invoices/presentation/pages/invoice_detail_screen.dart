import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_responsive.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../../../../core/services/thermal_printer_service.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/pdf_preview_sheet.dart';
import '../../../../core/widgets/status_badge.dart';
import '../../../payments/presentation/sheets/collect_payment_sheet.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/repositories/invoices_repository.dart';
import '../cubit/invoice_detail_cubit.dart';
import '../cubit/invoice_detail_state.dart';
import '../cubit/invoices_cubit.dart';
import '../whatsapp/invoice_whatsapp_builder.dart';
import '../widgets/invoice_actions.dart';
import 'invoice_create_edit_screen.dart';

/// `SCR-15 — InvoiceDetailScreen`: تفاصيل الفاتورة كاملة مع أزرار:
/// تحصيل، إعادة احتساب، طباعة PDF، طباعة حرارية، واتساب، تعديل، حذف.
class InvoiceDetailScreen extends StatelessWidget {
  const InvoiceDetailScreen({super.key, required this.invoiceId, this.preset});

  final int invoiceId;

  /// فاتورة جاهزة (من قائمة) لتفادي وميض التحميل الأول.
  final InvoiceModel? preset;

  static Widget create({required int invoiceId, InvoiceModel? preset}) =>
      BlocProvider<InvoiceDetailCubit>(
        create: (_) => InvoiceDetailCubit(
          invoiceId: invoiceId,
          repository: sl<InvoicesRepository>(),
          pdfService: sl<PdfPrintService>(),
          thermalService: sl<ThermalPrinterService>(),
          whatsappService: sl<WhatsAppLauncherService>(),
          whatsappBuilder: InvoiceWhatsAppBuilder(
            configRepository: sl<FinanceConfigRepository>(),
          ),
        )..load(),
        child: InvoiceDetailScreen(invoiceId: invoiceId, preset: preset),
      );

  @override
  Widget build(BuildContext context) {
    return SubPageScaffold(
      title: 'فاتورة ${preset?.billNumber ?? '#$invoiceId'}',
      actions: <Widget>[
        AppIconButton(
          tooltip: 'تحديث',
          size: 38,
          onPressed: () => context.read<InvoiceDetailCubit>().load(),
          icon: Icons.refresh_rounded,
        ),
      ],
      child: BlocConsumer<InvoiceDetailCubit, InvoiceDetailState>(
        listenWhen: (previous, next) =>
            next.lastActionLabel != null &&
            previous.lastActionLabel != next.lastActionLabel,
        listener: (context, state) {
          if (state.lastActionLabel != null) {
            AppFeedback.success(context, state.lastActionLabel!);
          }
        },
        builder: (context, state) {
          if (state.isLoading && state.invoice == null) {
            return const SkeletonList(count: 3, lines: 5);
          }
          if (state.invoice == null) {
            return ErrorStateWidget(
              message: state.errorMessage ?? 'الفاتورة غير موجودة.',
              onRetry: () => context.read<InvoiceDetailCubit>().load(),
            );
          }
          return _InvoiceDetailView(state: state, cubit: context.read<InvoiceDetailCubit>());
        },
      ),
    );
  }
}

class _InvoiceDetailView extends StatelessWidget {
  const _InvoiceDetailView({required this.state, required this.cubit});

  final InvoiceDetailState state;
  final InvoiceDetailCubit cubit;

  @override
  Widget build(BuildContext context) {
    final invoice = state.invoice!;
    final config = sl<FinanceConfigRepository>().cached;

    return AppReveal(
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 14, 16, 120),
        children: <Widget>[
          _HeroPanel(invoice: invoice),
          const SizedBox(height: 10),
          _HeaderCard(invoice: invoice),
          const SizedBox(height: 16),
          const SectionHeading(
            title: 'تفاصيل المبالغ',
            icon: Icons.calculate_outlined,
          ),
          const SizedBox(height: 8),
          _AmountsCard(invoice: invoice),
          if (invoice.items.isNotEmpty) ...<Widget>[
            const SizedBox(height: 16),
            const SectionHeading(title: 'بنود الفاتورة', icon: Icons.list_alt_rounded),
            const SizedBox(height: 8),
            _ItemsCard(invoice: invoice),
          ],
          if (invoice.readings.isNotEmpty) ...<Widget>[
            const SizedBox(height: 16),
            const SectionHeading(title: 'القراءات', icon: Icons.speed_rounded),
            const SizedBox(height: 8),
            _ReadingsCard(invoice: invoice),
          ],
          if (invoice.payments.isNotEmpty) ...<Widget>[
            const SizedBox(height: 16),
            const SectionHeading(title: 'المدفوعات المرتبطة', icon: Icons.payments_rounded),
            const SizedBox(height: 8),
            _PaymentsCard(invoice: invoice),
          ],
          if (invoice.notes != null && invoice.notes!.isNotEmpty) ...<Widget>[
            const SizedBox(height: 16),
            const SectionHeading(title: 'ملاحظات', icon: Icons.notes_rounded),
            const SizedBox(height: 8),
            _NotesCard(notes: invoice.notes!),
          ],
          const SizedBox(height: 16),
          _ActionGrid(
            invoice: invoice,
            cubit: cubit,
            state: state,
            whatsappEnabled: config.whatsappEnabled,
            whatsappIsApi: config.isApiSend,
          ),
        ],
      ),
    );
  }
}

/// لوحة الحبر: المتبقي الفعلي (تراكمي) هو الرقم الرئيسي للشاشة.
class _HeroPanel extends StatelessWidget {
  const _HeroPanel({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    final trueRemaining = invoice.serverTrueRemaining ?? invoice.ownRemaining;
    return AppHeroPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            'المتبقي الفعلي (تراكمي)',
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AppColors.onPanelMuted,
                ),
          ),
          const SizedBox(height: 4),
          LtrNumberText(
            CurrencyFormatter.debt(trueRemaining),
            style: const TextStyle(
              fontSize: 24,
              height: 1.15,
              fontWeight: FontWeight.w600,
              letterSpacing: -.5,
              color: AppColors.onPanel,
              fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(height: 10),
          Text(
            '${invoice.beneficiaryName} • قيمة الفاتورة '
            '${CurrencyFormatter.withLabel(invoice.totalAmount)} • المسدد '
            '${CurrencyFormatter.withLabel(invoice.amountPaid)}',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AppColors.onPanelSoft,
                  height: 1.5,
                ),
          ),
        ],
      ),
    );
  }
}

class _HeaderCard extends StatelessWidget {
  const _HeaderCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(15, 14, 15, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    LtrNumberText(
                      invoice.billNumber,
                      style: const TextStyle(
                        fontSize: 19,
                        height: 1.2,
                        fontWeight: FontWeight.w600,
                        letterSpacing: -.2,
                      ),
                    ),
                    const SizedBox(height: 3),
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
                  StatusBadge(
                    status: invoice.status.colorKey,
                    label: invoice.status.label,
                  ),
                  const SizedBox(height: 5),
                  InvoiceTypeBadge(type: invoice.invoiceType.wire),
                ],
              ),
            ],
          ),
          const SizedBox(height: 12),
          Container(height: 1, color: scheme.outlineVariant),
          const SizedBox(height: 12),
          Wrap(
            spacing: 22,
            runSpacing: 12,
            children: <Widget>[
              AppField(
                icon: Icons.event_outlined,
                label: 'تاريخ الفاتورة',
                value: DateFormat.day(invoice.billDate),
                ltr: true,
              ),
              AppField(
                icon: Icons.date_range_rounded,
                label: 'فترة الفوترة',
                value:
                    '${DateFormat.day(invoice.periodStart)} ← ${DateFormat.day(invoice.periodEnd)}',
                ltr: true,
              ),
              AppField(
                icon: Icons.grid_view_rounded,
                label: 'المربع',
                value: invoice.subscriber?.squareName ?? '-',
              ),
              AppField(
                icon: Icons.speed_rounded,
                label: 'العداد',
                value: invoice.meter?.label ?? 'بدون عداد',
                ltr: true,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// جدول المبالغ: يطابق ترتيب الحقول في `invoice.blade.php` + قواعد المتبقي التراكمي.
class _AmountsCard extends StatelessWidget {
  const _AmountsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final rows = <(String, String, Color?)>[
      ('قيمة الاستهلاك', CurrencyFormatter.withLabel(invoice.consumptionFee), null),
      ('قسط الاشتراك', CurrencyFormatter.withLabel(invoice.subscriptionInstallment), null),
      ('رسوم الوايت', CurrencyFormatter.withLabel(invoice.tankerCharges), null),
      ('متأخرات سابقة', CurrencyFormatter.withLabel(invoice.previousBalance), AppColors.warning),
      ('خصم رصيد دائن', CurrencyFormatter.withLabel(invoice.creditApplied), AppColors.success),
      ('قيمة الفاتورة', CurrencyFormatter.withLabel(invoice.totalAmount), null),
      ('إجمالي المطلوب', CurrencyFormatter.withLabel(invoice.totalDue), AppColors.danger),
      ('المسدد', CurrencyFormatter.withLabel(invoice.amountPaid), AppColors.success),
    ];

    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(15, 13, 15, 13),
      child: Column(
        children: <Widget>[
          for (final row in rows)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: Text(row.$1, style: theme.textTheme.bodySmall),
                  ),
                  LtrNumberText(
                    row.$2,
                    style: TextStyle(
                      fontSize: 13,
                      height: 1.4,
                      fontWeight: FontWeight.w500,
                      color: row.$3 == null
                          ? scheme.onSurface
                          : AppSemantic.resolve(context, row.$3!),
                      fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 8),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Text(
              'تفقيط: ${CurrencyFormatter.inWords(invoice.netPayable)}',
              style: theme.textTheme.labelSmall?.copyWith(height: 1.6),
            ),
          ),
        ],
      ),
    );
  }
}

class _ItemsCard extends StatelessWidget {
  const _ItemsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(15, 13, 15, 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          for (final item in invoice.items)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      item.description.isEmpty ? item.type.label : item.description,
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  LtrNumberText(
                    CurrencyFormatter.withLabel(item.amount),
                    style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w500,
                      color: theme.colorScheme.onSurface,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _ReadingsCard extends StatelessWidget {
  const _ReadingsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(15, 13, 15, 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          for (final reading in invoice.readings)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Row(
                children: <Widget>[
                  SizedBox(
                    width: 84,
                    child: Text(
                      DateFormat.day(reading.readingDate),
                      style: theme.textTheme.labelSmall,
                    ),
                  ),
                  Expanded(
                    child: LtrNumberText(
                      '${CurrencyFormatter.amount(reading.previousReading)} → '
                      '${CurrencyFormatter.amount(reading.currentReading)}',
                      style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w500,
                        color: scheme.onSurface,
                        fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                      ),
                    ),
                  ),
                  Text(
                    '${CurrencyFormatter.amount(reading.consumptionUnits)} وحدة',
                    style: theme.textTheme.labelSmall,
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _PaymentsCard extends StatelessWidget {
  const _PaymentsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(15, 13, 15, 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          for (final payment in invoice.payments)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        LtrNumberText(
                          payment.receiptNumber,
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500),
                        ),
                        Text(
                          '${DateFormat.day(payment.paymentDate)} • ${payment.collectorName ?? 'غير محدد'}',
                          style: theme.textTheme.labelSmall,
                        ),
                      ],
                    ),
                  ),
                  PaymentMethodBadge(method: payment.method.wire, compact: true),
                  const SizedBox(width: 8),
                  LtrNumberText(
                    CurrencyFormatter.withLabel(payment.amount),
                    style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: AppSemantic.resolve(context, AppColors.success),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _NotesCard extends StatelessWidget {
  const _NotesCard({required this.notes});

  final String notes;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(15, 13, 15, 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(notes, style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.7)),
        ],
      ),
    );
  }
}

class _ActionGrid extends StatelessWidget {
  const _ActionGrid({
    required this.invoice,
    required this.cubit,
    required this.state,
    required this.whatsappEnabled,
    required this.whatsappIsApi,
  });

  final InvoiceModel invoice;
  final InvoiceDetailCubit cubit;
  final InvoiceDetailState state;
  final bool whatsappEnabled;
  final bool whatsappIsApi;

  @override
  Widget build(BuildContext context) {
    final busy = state.actionRunning;

    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(12, 12, 12, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (state.canCollect) ...<Widget>[
            AppPrimaryButton(
              label: 'تحصيل دفعة',
              icon: Icons.payments_rounded,
              height: 48,
              onPressed: busy
                  ? null
                  : () async {
                      final saved = await CollectPaymentSheet.show(
                        context,
                        invoice: invoice,
                      );
                      if (!saved || !context.mounted) return;
                      await cubit.load();
                    },
            ),
            const SizedBox(height: 12),
          ],
          Text('إجراءات الفاتورة', style: Theme.of(context).textTheme.labelSmall),
          const SizedBox(height: 7),
          Wrap(
            spacing: 7,
            runSpacing: 7,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: <Widget>[
              AppIconButton(
                tooltip: 'PDF',
                size: 38,
                onPressed: state.printingBusy
                    ? null
                    : () async {
                  final bytes = await cubit.loadPdfBytes();
                  if (bytes == null || !context.mounted) return;
                  await showModalBottomSheet<void>(
                    context: context,
                    isScrollControlled: true,
                    showDragHandle: true,
                    builder: (_) => PdfPreviewSheet(
                      title: 'فاتورة ${invoice.billNumber}',
                      bytes: bytes,
                      fileName: 'invoice-${invoice.billNumber}.pdf',
                    ),
                  );
                },
                icon: Icons.picture_as_pdf_outlined,
              ),
              AppIconButton(
                tooltip: 'طباعة حرارية',
                size: 38,
                onPressed: state.printingBusy ? null : () => printInvoiceThermal(context, invoice),
                icon: Icons.print_outlined,
              ),
              if (whatsappEnabled)
                AppIconButton(
                  tooltip: whatsappIsApi ? 'واتساب (PDF)' : 'واتساب',
                  size: 38,
                  color: AppColors.whatsapp,
                  onPressed: state.whatsappBusy
                      ? null
                      : () => cubit.sendWhatsapp().then((result) {
                          if (!context.mounted) return;
                          if (result.isSuccess) {
                            AppFeedback.success(context, 'تم الإرسال.');
                          } else {
                            AppFeedback.error(
                              context,
                              result.error ?? 'تعذّر الإرسال — تحقق من إعدادات واتساب.',
                            );
                          }
                        }),
                  icon: Icons.chat_bubble_outline_rounded,
                ),
              if (invoice.isDeleted)
                AppIconButton(
                  tooltip: 'استعادة',
                  size: 38,
                  onPressed: busy
                      ? null
                      : () async {
                          final ok = await ConfirmDialog.show(
                            context,
                            title: 'استعادة الفاتورة ${invoice.billNumber}',
                            message: 'ستُستعاد الفاتورة من سلة المحذوفات ويعاد احتساب أرصدة الحساب.',
                            confirmLabel: 'استعادة',
                          );
                          if (!ok) return;
                          await cubit.restore();
                        },
                  icon: Icons.restore_rounded,
                )
              else ...<Widget>[
                AppIconButton(
                  tooltip: 'إعادة احتساب',
                  size: 38,
                  onPressed: busy ? null : () => cubit.recalculate(),
                  icon: Icons.refresh_rounded,
                ),
                AppIconButton(
                  tooltip: 'تعديل',
                  size: 38,
                  onPressed: () async {
                    final saved = await Navigator.of(context).push<bool>(
                      MaterialPageRoute<bool>(
                        builder: (_) => InvoiceCreateEditScreen(invoice: invoice),
                      ),
                    );
                    if (saved == true) await cubit.load();
                  },
                  icon: Icons.edit_outlined,
                ),
              ],
              PopupMenuButton<String>(
                tooltip: 'خيارات الحذف',
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
                icon: Icon(
                  Icons.more_horiz_rounded,
                  size: 18,
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
                constraints: const BoxConstraints(minWidth: 44, minHeight: 38),
                onSelected: (value) => _runDestructive(context, value),
                itemBuilder: (context) => <PopupMenuEntry<String>>[
                  if (!invoice.isDeleted)
                    const PopupMenuItem<String>(
                      value: 'soft',
                      child: Text('حذف ناعم'),
                    ),
                  const PopupMenuItem<String>(
                    value: 'force',
                    child: Text('حذف نهائي'),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// الحذف (الناعم/النهائي) يمر دائماً عبر `ConfirmDialog` — يبقى المنطق كما هو.
  Future<void> _runDestructive(BuildContext context, String value) async {
    final busy = state.actionRunning;
    if (busy) return;
    if (value == 'soft') {
      final ok = await ConfirmDialog.show(
        context,
        title: 'حذف الفاتورة ${invoice.billNumber}',
        message: 'سيُفكّ ارتباط القراءات والأقساط والوايت وتُعاد '
            'توزيع الدفعات على هذا الحساب.',
        confirmLabel: 'حذف',
        destructive: true,
      );
      if (!ok) return;
      final deleted = await cubit.delete();
      if (!context.mounted) return;
      if (deleted) {
        Navigator.of(context).pop();
      }
      return;
    }
    if (value == 'force') {
      final ok = await ConfirmDialog.show(
        context,
        title: 'حذف نهائي للفاتورة ${invoice.billNumber}',
        message: 'تحذير: سيُحذف سجل الفاتورة نهائياً من قاعدة البيانات ولا يمكن استعادته!',
        confirmLabel: 'حذف نهائي',
        destructive: true,
      );
      if (!ok) return;
      final deleted = await cubit.forceDelete();
      if (!context.mounted) return;
      if (deleted) {
        Navigator.of(context).pop();
      }
    }
  }
}

/// لوحة التفاصيل المُضمَّنة في وضع Master-Detail (تابلت) — تعرض نفس البطاقة
/// بدون AppBar وتُحدّط قائمة الفواتير عبر `InvoicesCubit`.
class InvoiceDetailPanel extends StatelessWidget {
  const InvoiceDetailPanel({super.key, required this.invoice, this.cubit});

  final InvoiceModel invoice;

  /// `InvoicesCubit` القائمة الأصلية — يُستعمل لتحديث الصفوف بعد الإجراءات.
  final InvoicesCubit? cubit;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 14, 16, 24),
      children: <Widget>[
        _HeaderCard(invoice: invoice),
        const SizedBox(height: 10),
        _AmountsCard(invoice: invoice),
        const SizedBox(height: 14),
        Row(
          children: <Widget>[
            Expanded(
              child: AppSecondaryButton(
                label: 'PDF',
                icon: Icons.picture_as_pdf_outlined,
                height: 44,
                onPressed: () => openInvoicePdfPreview(context, invoice),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: AppSecondaryButton(
                label: 'حراري',
                icon: Icons.print_outlined,
                height: 44,
                onPressed: () => printInvoiceThermal(context, invoice),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: AppSecondaryButton(
                label: 'المزيد',
                icon: Icons.more_horiz_rounded,
                height: 44,
                onPressed: () =>
                    showInvoiceActionSheet(context, invoice: invoice, cubit: cubit),
              ),
            ),
          ],
        ),
        if (context.useMasterDetail) const SizedBox(height: 8),
      ],
    );
  }
}
