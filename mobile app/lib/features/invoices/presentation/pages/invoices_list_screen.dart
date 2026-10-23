import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_responsive.dart';
import '../../../../core/finance/finance_search.dart';
import '../../../../core/theme/app_colors.dart';
import '../../data/models/invoice_model.dart';
import '../../data/models/invoice_query.dart';
import '../../domain/repositories/invoices_repository.dart';
import '../cubit/invoices_cubit.dart';
import '../cubit/invoices_state.dart';
import '../sheets/generate_invoice_sheets.dart';
import '../widgets/invoice_actions.dart';
import '../widgets/invoice_card.dart';
import '../widgets/invoice_filters_sheet.dart';
import 'invoice_detail_screen.dart';

/// `SCR-14 — InvoicesListScreen`: قائمة الفواتير الشاملة بفلاترها،
/// نوع الفاتورة، سلة المحذوفات (Trashed)، الاستعادة والحذف النهائي، والإجراءات الجماعية.
class InvoicesListScreen extends StatefulWidget {
  const InvoicesListScreen({super.key, this.initialQuery});

  final InvoiceQuery? initialQuery;

  static Widget create({InvoiceQuery? initialQuery}) => MultiBlocProvider(
        providers: [
          BlocProvider<InvoicesCubit>(
            create: (_) => InvoicesCubit(
              repository: sl<InvoicesRepository>(),
              initialQuery: initialQuery ?? const InvoiceQuery(),
            )..load(),
          ),
        ],
        child: const InvoicesListScreen(),
      );

  @override
  State<InvoicesListScreen> createState() => _InvoicesListScreenState();
}

class _InvoicesListScreenState extends State<InvoicesListScreen> {
  final TextEditingController _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('الفواتير'),
        actions: <Widget>[
          BlocBuilder<InvoicesCubit, InvoicesState>(
            buildWhen: (prev, curr) =>
                prev.selectedIds != curr.selectedIds || prev.invoices.length != curr.invoices.length,
            builder: (context, state) {
              if (state.invoices.isEmpty) return const SizedBox.shrink();
              final cubit = context.read<InvoicesCubit>();
              return IconButton(
                tooltip: state.isSelectingMode ? 'إلغاء التحديد' : 'تحديد الكل',
                onPressed: state.isSelectingMode ? cubit.clearSelection : cubit.selectAll,
                icon: Icon(state.isSelectingMode ? Icons.deselect : Icons.select_all),
              );
            },
          ),
          IconButton(
            tooltip: 'تحديث',
            onPressed: () => context.read<InvoicesCubit>().load(refresh: true),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      floatingActionButton: const _GenerateInvoiceFab(),
      body: BlocBuilder<InvoicesCubit, InvoicesState>(
        builder: (context, state) {
          final cubit = context.read<InvoicesCubit>();
          return Column(
            children: <Widget>[
              FinanceSearchBar(
                controller: _search,
                onChanged: (value) => cubit.search(value),
                onSubmitted: (value) => cubit.search(value),
                filters: <Widget>[
                  FilterChipButton(
                    label: 'الحالة',
                    value: _statusLabel(state.query.status),
                    selected: state.query.status != null,
                    onPressed: () => _openFilters(context, cubit, state),
                  ),
                  FilterChipButton(
                    label: 'النوع',
                    value: _typeLabel(state.query.invoiceType),
                    selected: state.query.invoiceType != null,
                    onPressed: () => _cycleOrPickType(context, cubit, state),
                  ),
                  FilterChipButton(
                    label: 'المحذوفات',
                    value: _trashedLabel(state.query.trashed),
                    selected: state.query.trashed != 'without',
                    onPressed: () => _pickTrashedFilter(context, cubit, state),
                  ),
                  FilterChipButton(
                    label: 'فلاتر',
                    value: state.query.activeFilterCount > 0
                        ? '${state.query.activeFilterCount}'
                        : null,
                    selected: state.query.activeFilterCount > 0,
                    onPressed: () => _openFilters(context, cubit, state),
                  ),
                  if (state.isSelectingMode)
                    FilterChipButton(
                      label: 'محدد',
                      value: '${state.selectedIds.length}',
                      selected: true,
                      onPressed: cubit.clearSelection,
                    ),
                ],
                trailing: state.isLoading
                    ? const Padding(
                        padding: EdgeInsets.only(right: 14),
                        child: SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      )
                    : null,
              ),
              InvoicesSummaryBar(
                count: state.invoices.length,
                openCount: state.openCount,
                overdueCount: state.overdueCount,
                totalDue: state.totalDueSum,
              ),
              if (state.isSelectingMode)
                _BulkActionsBar(cubit: cubit, state: state),
              Expanded(child: _buildBody(context, cubit, state)),
            ],
          );
        },
      ),
    );
  }

  Widget _buildBody(BuildContext context, InvoicesCubit cubit, InvoicesState state) {
    if (state.status == FinanceLoadStatus.loading) {
      return const SkeletonList(count: 6);
    }
    if (state.errorMessage != null && !state.hasData) {
      return ErrorStateWidget(message: state.errorMessage!, onRetry: cubit.load);
    }
    if (state.isEmpty) {
      return const EmptyStateWidget(
        title: 'لا توجد فواتير مطابقة',
        message: 'جرّب تعديل الفلاتر أو أصدر فاتورة جديدة من الزر الأسفل.',
        icon: Icons.receipt_long_outlined,
      );
    }

    final list = state.invoices;
    return RefreshIndicator(
      onRefresh: () => cubit.load(refresh: true),
      child: context.useMasterDetail
          ? _MasterDetailList(cubit: cubit, state: state, invoices: list)
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 96),
              itemCount: list.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (context, index) {
                final invoice = list[index];
                return InvoiceCardActions(
                  invoice: invoice,
                  siblings: list,
                  cubit: cubit,
                  child: InvoiceCard(
                    invoice: invoice,
                    remainingLabel: remainingLabelFor(invoice, list),
                    showCheckbox: true,
                    selected: state.selectedIds.contains(invoice.id),
                    onToggleSelect: () => cubit.toggleSelection(invoice.id),
                    onMoreActions: () =>
                        showInvoiceActionSheet(context, invoice: invoice, cubit: cubit),
                    onTap: () async {
                      await Navigator.of(context).push<void>(
                        MaterialPageRoute<void>(
                          builder: (_) => InvoiceDetailScreen.create(
                            invoiceId: invoice.id,
                            preset: invoice,
                          ),
                        ),
                      );
                      if (context.mounted) {
                        await cubit.load(refresh: true);
                      }
                    },
                    busy: state.busyInvoiceId == invoice.id,
                  ),
                );
              },
            ),
    );
  }

  Future<void> _openFilters(
    BuildContext context,
    InvoicesCubit cubit,
    InvoicesState state,
  ) async {
    await showInvoiceFiltersSheet(
      context,
      query: state.query,
      billingPeriods: state.billingPeriods,
      onSubmit: (query) => cubit.applyFilters(query),
    );
  }

  Future<void> _cycleOrPickType(
    BuildContext context,
    InvoicesCubit cubit,
    InvoicesState state,
  ) async {
    final picked = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const ListTile(
              title: Text('فلتر نوع الفاتورة', style: TextStyle(fontWeight: FontWeight.bold)),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.all_inclusive),
              title: const Text('كل الأنواع'),
              selected: state.query.invoiceType == null,
              onTap: () => Navigator.pop(ctx, ''),
            ),
            ListTile(
              leading: const Icon(Icons.speed_outlined),
              title: const Text('فاتورة عداد (Meter)'),
              selected: state.query.invoiceType == 'meter',
              onTap: () => Navigator.pop(ctx, 'meter'),
            ),
            ListTile(
              leading: const Icon(Icons.card_membership_outlined),
              title: const Text('رسوم اشتراك (Subscription)'),
              selected: state.query.invoiceType == 'subscription',
              onTap: () => Navigator.pop(ctx, 'subscription'),
            ),
            ListTile(
              leading: const Icon(Icons.local_shipping_outlined),
              title: const Text('طلب وايت (Tanker)'),
              selected: state.query.invoiceType == 'tanker',
              onTap: () => Navigator.pop(ctx, 'tanker'),
            ),
          ],
        ),
      ),
    );
    if (picked == null) return;
    await cubit.applyFilters(
      picked.isEmpty
          ? state.query.copyWith(clearInvoiceType: true)
          : state.query.copyWith(invoiceType: picked),
    );
  }

  Future<void> _pickTrashedFilter(
    BuildContext context,
    InvoicesCubit cubit,
    InvoicesState state,
  ) async {
    final picked = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const ListTile(
              title: Text('سلة المحذوفات (Trashed)', style: TextStyle(fontWeight: FontWeight.bold)),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.check_circle_outline),
              title: const Text('بدون المحذوف'),
              selected: state.query.trashed == 'without',
              onTap: () => Navigator.pop(ctx, 'without'),
            ),
            ListTile(
              leading: const Icon(Icons.layers_outlined),
              title: const Text('مع المحذوف'),
              selected: state.query.trashed == 'with',
              onTap: () => Navigator.pop(ctx, 'with'),
            ),
            ListTile(
              leading: const Icon(Icons.delete_sweep_outlined, color: Colors.redAccent),
              title: const Text('المحذوف فقط (سلة المحذوفات)'),
              selected: state.query.trashed == 'only',
              onTap: () => Navigator.pop(ctx, 'only'),
            ),
          ],
        ),
      ),
    );
    if (picked == null) return;
    await cubit.applyFilters(state.query.copyWith(trashed: picked));
  }

  static String? _statusLabel(String? status) {
    if (status == null) return null;
    return switch (status) {
      'issued' => 'صادرة',
      'partially_paid' => 'جزئياً',
      'paid' => 'مسددة',
      'overdue' => 'متأخرة',
      'draft' => 'مسودة',
      _ => status,
    };
  }

  static String? _typeLabel(String? type) {
    if (type == null) return null;
    return switch (type) {
      'meter' => 'عداد',
      'subscription' => 'اشتراك',
      'tanker' => 'وايت',
      _ => type,
    };
  }

  static String? _trashedLabel(String trashed) => switch (trashed) {
        'with' => 'مع المحذوف',
        'only' => 'المحذوف فقط',
        _ => null,
      };
}

/// شريط الإجراءات الجماعية: `bulk-recalculate` + `bulk-restore` + `bulk-delete` + `bulk-force-delete`.
class _BulkActionsBar extends StatelessWidget {
  const _BulkActionsBar({required this.cubit, required this.state});

  final InvoicesCubit cubit;
  final InvoicesState state;

  @override
  Widget build(BuildContext context) {
    final selectedInvoices =
        state.invoices.where((i) => state.selectedIds.contains(i.id)).toList();
    final hasDeleted = selectedInvoices.any((i) => i.isDeleted) || state.query.trashed == 'only';
    final hasActive = selectedInvoices.any((i) => !i.isDeleted);

    return Material(
      color: AppColors.primaryDark,
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: <Widget>[
              Text(
                '${state.selectedIds.length} محدد',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
              ),
              const SizedBox(width: 8),
              TextButton(
                onPressed: state.bulkRunning ? null : cubit.selectAll,
                child: const Text('تحديد الكل', style: TextStyle(color: Colors.white70)),
              ),
              if (state.bulkRunning)
                const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 12),
                  child: SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  ),
                )
              else ...<Widget>[
                if (hasActive)
                  TextButton.icon(
                    onPressed: () => _bulkRecalculate(context),
                    icon: const Icon(Icons.refresh, size: 18, color: Colors.white),
                    label: const Text(
                      'إعادة احتساب',
                      style: TextStyle(color: Colors.white),
                    ),
                  ),
                if (hasDeleted)
                  TextButton.icon(
                    onPressed: () => _bulkRestore(context),
                    icon: const Icon(Icons.restore_from_trash_outlined,
                        size: 18, color: Color(0xFF86EFAC)),
                    label: const Text(
                      'استعادة',
                      style: TextStyle(color: Color(0xFF86EFAC)),
                    ),
                  ),
                if (hasActive)
                  TextButton.icon(
                    onPressed: () => _bulkDelete(context),
                    icon: const Icon(Icons.delete_outline,
                        size: 18, color: Color(0xFFFCA5A5)),
                    label: const Text(
                      'حذف ناعم',
                      style: TextStyle(color: Color(0xFFFCA5A5)),
                    ),
                  ),
                TextButton.icon(
                  onPressed: () => _bulkForceDelete(context),
                  icon: const Icon(Icons.delete_forever_outlined,
                      size: 18, color: Color(0xFFF87171)),
                  label: const Text(
                    'حذف نهائي',
                    style: TextStyle(color: Color(0xFFF87171)),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _bulkRecalculate(BuildContext context) async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'إعادة احتساب جماعية',
      message: 'سيُعاد توزيع الدفعات على ${state.selectedIds.length} فاتورة '
          'داخل نفس الحسابات المعزولة (FIFO).',
      confirmLabel: 'إعادة الاحتساب',
    );
    if (!ok) return;
    final result = await cubit.bulkRecalculate();
    if (!context.mounted) return;
    if (result == null) {
      AppFeedback.error(context, state.errorMessage ?? 'تعذّرت إعادة الاحتساب.');
      return;
    }
    AppFeedback.success(
      context,
      'أُعيد احتساب ${result.processed} فاتورة'
      '${result.failed > 0 ? ' — تعذّر ${result.failed}' : ''}',
    );
  }

  Future<void> _bulkRestore(BuildContext context) async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'استعادة جماعية للفواتير',
      message: 'ستُستعاد الفواتير المحددة (${state.selectedIds.length}) من سلة المحذوفات ويُعاد احتساب أرصدة حساباتها.',
      confirmLabel: 'استعادة',
    );
    if (!ok) return;
    final result = await cubit.bulkRestore();
    if (!context.mounted) return;
    if (result == null) {
      AppFeedback.error(context, state.errorMessage ?? 'تعذّرت الاستعادة الجماعية.');
      return;
    }
    AppFeedback.success(context, 'تمت استعادة ${result.processed} فاتورة بنجاح.');
  }

  Future<void> _bulkDelete(BuildContext context) async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'حذف جماعي للفواتير',
      message: 'سيُحذف ${state.selectedIds.length} فاتورة حذفاً ناعماً.',
      details: const <String>[
        'تُفكّ ارتباط القراءات ورسوم الاشتراك وطلبات الوايت تلقائياً.',
        'تُزال قيود دفتر الأستاذ وتُعاد توزيع الدفعات على الفواتير الباقية.',
      ],
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (!ok) return;
    final result = await cubit.bulkDelete();
    if (!context.mounted) return;
    if (result == null) {
      AppFeedback.error(context, state.errorMessage ?? 'تعذّر الحذف.');
      return;
    }
    AppFeedback.success(context, 'تم حذف ${result.processed} فاتورة.');
  }

  Future<void> _bulkForceDelete(BuildContext context) async {
    final ok = await ConfirmDialog.show(
      context,
      title: 'حذف نهائي جماعي للفواتير',
      message: 'تحذير: سيُحذف ${state.selectedIds.length} فاتورة نهائياً من قاعدة البيانات ولا يمكن التراجع!',
      confirmLabel: 'حذف نهائي',
      destructive: true,
    );
    if (!ok) return;
    final result = await cubit.bulkForceDelete();
    if (!context.mounted) return;
    if (result == null) {
      AppFeedback.error(context, state.errorMessage ?? 'تعذّر الحذف النهائي.');
      return;
    }
    AppFeedback.success(context, 'تم حذف ${result.processed} فاتورة نهائياً.');
  }
}

/// قائمة بسيطة + لوحة تفاصيل على الشاشات الكبيرة (§3.3-3 Master-Detail).
class _MasterDetailList extends StatefulWidget {
  const _MasterDetailList({
    required this.cubit,
    required this.state,
    required this.invoices,
  });

  final InvoicesCubit cubit;
  final InvoicesState state;
  final List<InvoiceModel> invoices;

  @override
  State<_MasterDetailList> createState() => _MasterDetailListState();
}

class _MasterDetailListState extends State<_MasterDetailList> {
  int? _selectedId;

  @override
  Widget build(BuildContext context) {
    final selected = widget.invoices.where((i) => i.id == _selectedId).toList();
    final invoice = selected.isEmpty ? null : selected.first;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          flex: 5,
          child: ListView.separated(
            padding: const EdgeInsets.fromLTRB(12, 12, 6, 96),
            itemCount: widget.invoices.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, index) {
              final item = widget.invoices[index];
              return InvoiceCardActions(
                invoice: item,
                siblings: widget.invoices,
                cubit: widget.cubit,
                child: InkWell(
                  onTap: () => setState(() => _selectedId = item.id),
                  child: InvoiceCard(
                    invoice: item,
                    dense: true,
                    remainingLabel: remainingLabelFor(item, widget.invoices),
                    showCheckbox: true,
                    selected: widget.state.selectedIds.contains(item.id),
                    onToggleSelect: () => widget.cubit.toggleSelection(item.id),
                    onMoreActions: () => showInvoiceActionSheet(
                      context,
                      invoice: item,
                      cubit: widget.cubit,
                    ),
                  ),
                ),
              );
            },
          ),
        ),
        const VerticalDivider(width: 1),
        Expanded(
          flex: 6,
          child: invoice == null
              ? const EmptyStateWidget(
                  title: 'اختر فاتورة لعرض تفاصيلها',
                  icon: Icons.receipt_long_outlined,
                  compact: true,
                )
              : InvoiceDetailPanel(invoice: invoice, cubit: widget.cubit),
        ),
      ],
    );
  }
}

/// زر الإصدار الثلاثي + الإنشاء اليدوي (مطابق لـ Header Actions في `InvoiceResource`).
class _GenerateInvoiceFab extends StatelessWidget {
  const _GenerateInvoiceFab();

  @override
  Widget build(BuildContext context) {
    return FloatingActionButton.extended(
      onPressed: () => showGenerateInvoicePicker(context),
      backgroundColor: AppColors.primary,
      foregroundColor: Colors.white,
      icon: const Icon(Icons.add),
      label: const Text('إصدار فاتورة'),
    );
  }
}
