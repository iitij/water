import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_search.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/widgets/invoice_actions.dart';
import '../../../invoices/presentation/widgets/invoice_card.dart';
import '../cubit/quick_collector_cubit.dart';
import '../cubit/quick_collector_state.dart';
import '../sheets/collect_payment_sheet.dart';
import '../../../../core/ui/app_tokens.dart';

/// `SCR-13` — المحصل السريع (Field Collector).
///
/// البحث الفوري يعمل على: اسم المشترك، اسم المستأجر (tenant)، رقم الفاتورة،
/// كود العداد، والمسار المركّب `4/1` (مربع/مسار) — يُحوَّل تلقائياً إلى
/// `square_id` + `path_number` إن كان الخادم يقبلهما (الخطة §7.1-1).
/// التحصيل بلمسة واحدة عبر [CollectPaymentSheet]، ثم طباعة السند وإرساله واتساب
/// من نافذة النتيجة مباشرة.
class QuickCollectorScreen extends StatelessWidget {
  const QuickCollectorScreen({super.key});

  /// يبني الـ Cubit الخاص بالشاشة — يُستعمل من `finance_routes` عندما يريد
  /// الأب توفير الحالة (مثلاً بعد الرجوع من `SCR-18`).
  static Widget create({required QuickCollectorCubit cubit}) {
    return BlocProvider<QuickCollectorCubit>.value(value: cubit, child: const QuickCollectorScreen());
  }

  @override
  Widget build(BuildContext context) {
    return BlocProvider<QuickCollectorCubit>(
      create: (_) => QuickCollectorCubit(
            invoicesRepository: sl<InvoicesRepository>(),
            references: sl<ReferencesRemoteDataSource>(),
          )
          ..bootstrap(),
      child: const _QuickCollectorBody(),
    );
  }
}

class _QuickCollectorBody extends StatefulWidget {
  const _QuickCollectorBody();

  @override
  State<_QuickCollectorBody> createState() => _QuickCollectorBodyState();
}

class _QuickCollectorBodyState extends State<_QuickCollectorBody> {
  final TextEditingController _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<QuickCollectorCubit>();

    return Scaffold(
      appBar: AppBar(
        title: const Text('المحصل السريع'),
        actions: <Widget>[
          IconButton(
            tooltip: 'تحديث',
            onPressed: cubit.loadUnpaid,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: SafeArea(
        child: BlocBuilder<QuickCollectorCubit, QuickCollectorState>(
          builder: (context, state) {
            final invoices = state.visibleInvoices;
            final overdueCount = state.invoices
                .where((i) => i.isOverdue && i.status.isOpenForCollection)
                .length;

            return Column(
              children: <Widget>[
                FinanceSearchBar(
                  controller: _search,
                  autofocus: true,
                  onChanged: (value) => cubit.onSearchChanged(value),
                  onSubmitted: (_) => cubit.searchNow(),
                  filters: <Widget>[
                    FilterChipButton(
                      label: 'المربع',
                      value: _squareLabel(state),
                      selected: state.selectedSquareId != null,
                      onPressed: () => _pickSquare(context, cubit, state),
                    ),
                    if (state.isCompoundSearch)
                      const FilterChipButton(
                        label: 'بحث مسار',
                        icon: Icons.alt_route,
                        selected: true,
                      ),
                  ],
                  trailing: state.searchText.isEmpty
                      ? null
                      : IconButton(
                          icon: const Icon(Icons.clear),
                          onPressed: () {
                            _search.clear();
                            cubit.clearSearch();
                          },
                        ),
                ),
                InvoicesSummaryBar(
                  count: state.invoices.length,
                  openCount: state.openCount,
                  overdueCount: overdueCount,
                  totalDue: state.totalDue,
                ),
                if (state.lastCollectedBillNumber != null)
                  _LastCollectedBanner(billNumber: state.lastCollectedBillNumber!),
                Expanded(child: _buildList(context, cubit, state, invoices)),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _buildList(
    BuildContext context,
    QuickCollectorCubit cubit,
    QuickCollectorState state,
    List<InvoiceModel> invoices,
  ) {
    if (state.loading && invoices.isEmpty) {
      return const SkeletonList(count: 6, lines: 2);
    }

    if (state.errorMessage != null && invoices.isEmpty) {
      return ErrorStateWidget(
        message: state.errorMessage!,
        onRetry: () => cubit.loadUnpaid(),
      );
    }

    if (invoices.isEmpty) {
      return RefreshIndicator(
        onRefresh: () => cubit.loadUnpaid(),
        child: ListView(
          children: <Widget>[
            const SizedBox(height: 60),
            EmptyStateWidget(
              title: state.searchText.isEmpty
                  ? 'لا توجد فواتير مفتوحة للتحصيل'
                  : 'لا نتائج للبحث',
              message: state.searchText.isEmpty
                  ? 'كل الفواتير مسدّدة حالياً.'
                  : 'جرّب اسم المستأجر أو رقم الفاتورة أو مسار بصيغة 4/1.',
              icon: state.searchText.isEmpty
                  ? Icons.task_alt
                  : Icons.search_off,
              actionLabel: state.searchText.isEmpty ? null : 'مسح البحث',
              onAction: state.searchText.isEmpty
                  ? null
                  : () {
                      _search.clear();
                      cubit.clearSearch();
                    },
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: () => cubit.loadUnpaid(),
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
        itemCount: invoices.length,
        itemBuilder: (context, index) {
          final invoice = invoices[index];
          return _CollectorCard(
            invoice: invoice,
            siblings: invoices,
            justCollected: state.paidIds.contains(invoice.id),
            onCollect: () => _collect(context, cubit, invoice),
          );
        },
      ),
    );
  }

  /// تحصيل بلمسة واحدة: تفتح الودجة، وإن نجحت نعلّم الفاتورة ونحدّث القائمة.
  Future<void> _collect(
    BuildContext context,
    QuickCollectorCubit cubit,
    InvoiceModel invoice,
  ) async {
    final saved = await CollectPaymentSheet.show(context, invoice: invoice);
    if (!saved || !context.mounted) return;
    cubit.markCollected(invoice);
    AppFeedback.success(context, 'تم تحصيل ${invoice.beneficiaryName}.');
  }

  String? _squareLabel(QuickCollectorState state) {
    final id = state.selectedSquareId;
    if (id == null) return null;
    final matches = state.squares.where((s) => s.id == id);
    return matches.isEmpty ? null : matches.first.name;
  }

  Future<void> _pickSquare(
    BuildContext context,
    QuickCollectorCubit cubit,
    QuickCollectorState state,
  ) async {
    final squareId = await showModalBottomSheet<int?>(
      context: context,
      showDragHandle: true,
      builder: (sheetContext) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 4, 16, 8),
              child: Text(
                'تصفية بالمربع',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
              ),
            ),
            ListTile(
              leading: const Icon(Icons.filter_alt_off_outlined),
              title: const Text('كل المربعات'),
              onTap: () => Navigator.of(sheetContext).pop(0),
            ),
            const Divider(height: 1),
            if (state.loadingSquares)
              const Padding(
                padding: EdgeInsets.all(16),
                child: LinearProgressIndicator(minHeight: 2),
              )
            else
              Flexible(
                child: ListView.builder(
                  shrinkWrap: true,
                  itemCount: state.squares.length,
                  itemBuilder: (context, index) {
                    final square = state.squares[index];
                    return ListTile(
                      leading: Icon(
                        state.selectedSquareId == square.id
                            ? Icons.radio_button_checked
                            : Icons.radio_button_unchecked,
                        color: AppColors.primary,
                      ),
                      title: Text(square.name),
                      subtitle: square.subscribersCount != null
                          ? Text('${square.subscribersCount} مشترك')
                          : null,
                      onTap: () => Navigator.of(sheetContext).pop(square.id),
                    );
                  },
                ),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );

    if (squareId == null) return;
    await cubit.selectSquare(squareId == 0 ? null : squareId);
  }
}

class _LastCollectedBanner extends StatelessWidget {
  const _LastCollectedBanner({required this.billNumber});

  final String billNumber;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: AppColors.success.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Row(
        children: <Widget>[
          const Icon(Icons.check_circle, size: 18, color: AppColors.success),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'آخر سند محصّل: فاتورة $billNumber',
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
}

class _CollectorCard extends StatelessWidget {
  const _CollectorCard({
    required this.invoice,
    required this.siblings,
    required this.onCollect,
    this.justCollected = false,
  });

  final InvoiceModel invoice;
  final List<InvoiceModel> siblings;
  final VoidCallback onCollect;
  final bool justCollected;

  @override
  Widget build(BuildContext context) {
    final config = sl<FinanceConfigRepository>().cached;
    final canCollect = !justCollected && canCollectInvoice(invoice, siblings);

    return Opacity(
      opacity: justCollected ? 0.5 : 1,
      child: InvoiceCard(
        invoice: invoice,
        dense: true,
        remainingLabel: remainingLabelFor(invoice, siblings),
        canCollect: canCollect,
        onCollect: canCollect ? onCollect : null,
        thermalEnabled: true,
        onPrintThermal: () => printInvoiceThermal(context, invoice),
        whatsappEnabled: config.whatsappEnabled,
        onWhatsapp: config.whatsappEnabled
            ? () => sendInvoiceWhatsapp(context, invoice)
            : null,
        onPrint: () => openInvoicePdfPreview(context, invoice),
        onTap: () => _showDue(context),
      ),
    );
  }

  void _showDue(BuildContext context) {
    final due = invoice.netPayable;
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                invoice.beneficiaryName,
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 2),
              Text(
                'فاتورة ${invoice.billNumber} • ${invoice.status.label}',
                style: const TextStyle(fontSize: 12, color: AppColors.neutral),
              ),
              const SizedBox(height: 14),
              _DetailRow(label: 'إجمالي الفاتورة', value: CurrencyFormatter.withLabel(invoice.totalAmount)),
              if (invoice.previousBalance > 0)
                _DetailRow(label: 'متأخرات سابقة', value: CurrencyFormatter.withLabel(invoice.previousBalance)),
              if (invoice.creditApplied > 0)
                _DetailRow(
                  label: 'رصيد دائن مخصوم',
                  value: '- ${CurrencyFormatter.amount(invoice.creditApplied)}',
                  tone: AppColors.success,
                ),
              if (invoice.amountPaid > 0)
                _DetailRow(label: 'المسدد', value: CurrencyFormatter.withLabel(invoice.amountPaid)),
              _DetailRow(label: 'صافي المستحق', value: CurrencyFormatter.debt(due), bold: true),
              const SizedBox(height: 16),
              FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: AppColors.success),
                onPressed: () {
                  Navigator.of(sheetContext).pop();
                  onCollect();
                },
                icon: const Icon(Icons.payments_outlined),
                label: Text('تحصيل ${CurrencyFormatter.amount(due)} ريال'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({required this.label, required this.value, this.tone, this.bold = false});

  final String label;
  final String value;
  final Color? tone;
  final bool bold;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(label, style: const TextStyle(fontSize: 12))),
          Text(
            value,
            style: TextStyle(
              fontSize: bold ? 14 : 12,
              fontWeight: bold ? FontWeight.w600 : FontWeight.w600,
              color: tone,
            ),
          ),
        ],
      ),
    );
  }
}
