import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/di/injection_container.dart';
import '../../../../core/responsive/breakpoints.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../../../../core/services/thermal_printer_service.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/confirm_dialog.dart';
import '../../../../core/widgets/empty_state_widget.dart';
import '../../../../core/widgets/loading_skeleton.dart';
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
    return Scaffold(
      appBar: AppBar(
        title: Text('فاتورة ${preset?.billNumber ?? '#$invoiceId'}'),
        actions: <Widget>[
          IconButton(
            tooltip: 'تحديث',
            onPressed: () => context.read<InvoiceDetailCubit>().load(),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: BlocConsumer<InvoiceDetailCubit, InvoiceDetailState>(
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

    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 120),
      children: <Widget>[
        _HeaderCard(invoice: invoice),
        const SizedBox(height: 12),
        _AmountsCard(invoice: invoice),
        if (invoice.items.isNotEmpty) ...<Widget>[
          const SizedBox(height: 12),
          _ItemsCard(invoice: invoice),
        ],
        if (invoice.readings.isNotEmpty) ...<Widget>[
          const SizedBox(height: 12),
          _ReadingsCard(invoice: invoice),
        ],
        if (invoice.payments.isNotEmpty) ...<Widget>[
          const SizedBox(height: 12),
          _PaymentsCard(invoice: invoice),
        ],
        if (invoice.notes != null && invoice.notes!.isNotEmpty) ...<Widget>[
          const SizedBox(height: 12),
          _NotesCard(notes: invoice.notes!),
        ],
        const SizedBox(height: 20),
        _ActionGrid(
          invoice: invoice,
          cubit: cubit,
          state: state,
          whatsappEnabled: config.whatsappEnabled,
          whatsappIsApi: config.isApiSend,
        ),
      ],
    );
  }
}

class _HeaderCard extends StatelessWidget {
  const _HeaderCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      LtrNumberText(
                        invoice.billNumber,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        invoice.beneficiaryName,
                        style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: <Widget>[
                    InvoiceTypeBadge(type: invoice.invoiceType.wire),
                    const SizedBox(height: 6),
                    StatusBadge.fromStatus(invoice.status.colorKey),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 18,
              runSpacing: 8,
              children: <Widget>[
                _meta(context, 'تاريخ الفاتورة', DateFormat.day(invoice.billDate)),
                _meta(
                  context,
                  'فترة الفوترة',
                  '${DateFormat.day(invoice.periodStart)} ← ${DateFormat.day(invoice.periodEnd)}',
                ),
                _meta(context, 'المربع', invoice.subscriber?.squareName ?? '-'),
                _meta(context, 'العداد', invoice.meter?.label ?? 'بدون عداد'),
                if (invoice.meter?.tenantPhone != null)
                  _meta(
                    context,
                    'هاتف المستأجر',
                    invoice.meter!.tenantPhone!,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _meta(BuildContext context, String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(
          label,
          style: const TextStyle(fontSize: 11, color: AppColors.neutral),
        ),
        Text(value, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
      ],
    );
  }
}

/// جدول المبالغ: يطابق ترتيب الحقول في `invoice.blade.php` + قواعد المتبقي التراكمي.
class _AmountsCard extends StatelessWidget {
  const _AmountsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    final trueRemaining = invoice.serverTrueRemaining ?? invoice.ownRemaining;
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

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: <Widget>[
            ...rows.map(
              (row) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 5),
                child: Row(
                  children: <Widget>[
                    Expanded(child: Text(row.$1, style: const TextStyle(fontSize: 13))),
                    LtrNumberText(
                      row.$2,
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: row.$3,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const Divider(height: 24),
            Row(
              children: <Widget>[
                const Expanded(
                  child: Text(
                    'المتبقي الفعلي (تراكمي)',
                    style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
                  ),
                ),
                LtrNumberText(
                  CurrencyFormatter.debt(trueRemaining),
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: trueRemaining > 0 ? AppColors.danger : AppColors.success,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: Text(
                'مفقط: ${CurrencyFormatter.inWords(invoice.netPayable)}',
                style: const TextStyle(fontSize: 12, color: AppColors.primaryDark),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ItemsCard extends StatelessWidget {
  const _ItemsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Text(
              'بنود الفاتورة',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
            ),
            const SizedBox(height: 10),
            ...invoice.items.map(
              (item) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Text(
                        item.description.isEmpty ? item.type.label : item.description,
                        style: const TextStyle(fontSize: 13),
                      ),
                    ),
                    LtrNumberText(
                      CurrencyFormatter.withLabel(item.amount),
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ReadingsCard extends StatelessWidget {
  const _ReadingsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Text(
              'القراءات',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
            ),
            const SizedBox(height: 10),
            ...invoice.readings.map(
              (reading) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: <Widget>[
                    SizedBox(
                      width: 92,
                      child: Text(
                        DateFormat.day(reading.readingDate),
                        style: const TextStyle(fontSize: 12, color: AppColors.neutral),
                      ),
                    ),
                    Expanded(
                      child: LtrNumberText(
                        '${CurrencyFormatter.amount(reading.previousReading)} → '
                        '${CurrencyFormatter.amount(reading.currentReading)}',
                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                    ),
                    Text(
                      '${CurrencyFormatter.amount(reading.consumptionUnits)} وحدة',
                      style: const TextStyle(fontSize: 12),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PaymentsCard extends StatelessWidget {
  const _PaymentsCard({required this.invoice});

  final InvoiceModel invoice;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Text(
              'المدفوعات المرتبطة',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
            ),
            const SizedBox(height: 10),
            ...invoice.payments.map(
              (payment) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          LtrNumberText(
                            payment.receiptNumber,
                            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
                          ),
                          Text(
                            '${DateFormat.day(payment.paymentDate)} • ${payment.collectorName ?? 'غير محدد'}',
                            style: const TextStyle(fontSize: 11, color: AppColors.neutral),
                          ),
                        ],
                      ),
                    ),
                    PaymentMethodBadge(method: payment.method.wire, compact: true),
                    const SizedBox(width: 8),
                    LtrNumberText(
                      CurrencyFormatter.withLabel(payment.amount),
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.success,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _NotesCard extends StatelessWidget {
  const _NotesCard({required this.notes});

  final String notes;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Text('ملاحظات', style: TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(notes),
          ],
        ),
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

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          children: <Widget>[
            if (state.canCollect)
              Row(
                children: <Widget>[
                  Expanded(
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(backgroundColor: AppColors.success),
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
                      icon: const Icon(Icons.payments_outlined),
                      label: const Text('تحصيل دفعة'),
                    ),
                  ),
                ],
              ),
            if (state.canCollect) const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: <Widget>[
                _chip(
                  context,
                  icon: Icons.picture_as_pdf_outlined,
                  label: 'PDF',
                  busy: state.printingBusy,
                  onTap: () async {
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
                ),
                _chip(
                  context,
                  icon: Icons.print_outlined,
                  label: 'طباعة حرارية',
                  busy: state.printingBusy,
                  onTap: () => printInvoiceThermal(context, invoice),
                ),
                if (whatsappEnabled)
                  _chip(
                    context,
                    icon: Icons.chat_outlined,
                    label: whatsappIsApi ? 'واتساب (PDF)' : 'واتساب',
                    color: const Color(0xFF25D366),
                    busy: state.whatsappBusy,
                    onTap: () => cubit.sendWhatsapp().then((result) {
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
                  ),
                _chip(
                  context,
                  icon: Icons.refresh,
                  label: 'إعادة احتساب',
                  busy: busy,
                  onTap: () => cubit.recalculate(),
                ),
                _chip(
                  context,
                  icon: Icons.edit_outlined,
                  label: 'تعديل',
                  onTap: () async {
                    final saved = await Navigator.of(context).push<bool>(
                      MaterialPageRoute<bool>(
                        builder: (_) => InvoiceCreateEditScreen(invoice: invoice),
                      ),
                    );
                    if (saved == true) await cubit.load();
                  },
                ),
                _chip(
                  context,
                  icon: Icons.delete_outline,
                  label: 'حذف',
                  color: AppColors.danger,
                  busy: busy,
                  onTap: () async {
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
                  },
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _chip(
    BuildContext context, {
    required IconData icon,
    required String label,
    required VoidCallback onTap,
    bool busy = false,
    Color? color,
  }) {
    return ActionChip(
      onPressed: busy ? null : onTap,
      avatar: busy
          ? const SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : Icon(icon, size: 18, color: color),
      label: Text(label),
      backgroundColor: color?.withValues(alpha: 0.08),
      side: BorderSide(color: color?.withValues(alpha: 0.3) ?? AppColors.lightBorder),
    );
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
      padding: const EdgeInsets.all(12),
      children: <Widget>[
        _HeaderCard(invoice: invoice),
        const SizedBox(height: 12),
        _AmountsCard(invoice: invoice),
        const SizedBox(height: 16),
        Row(
          children: <Widget>[
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => openInvoicePdfPreview(context, invoice),
                icon: const Icon(Icons.picture_as_pdf_outlined),
                label: const Text('PDF'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => printInvoiceThermal(context, invoice),
                icon: const Icon(Icons.print_outlined),
                label: const Text('حراري'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () =>
                    showInvoiceActionSheet(context, invoice: invoice, cubit: cubit),
                icon: const Icon(Icons.more_horiz),
                label: const Text('المزيد'),
              ),
            ),
          ],
        ),
        if (context.useMasterDetail) const SizedBox(height: 8),
      ],
    );
  }
}
