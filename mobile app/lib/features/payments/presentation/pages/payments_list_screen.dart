import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_responsive.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../data/models/payment_model.dart';
import '../../data/services/daily_receipts_exporter.dart';
import '../../domain/repositories/payments_repository.dart';
import '../cubit/payments_cubit.dart';
import '../sheets/void_requests_sheet.dart';
import '../widgets/payment_actions.dart';
import '../widgets/payment_card.dart';

/// `SCR-17` — قائمة سندات القبض (`PaymentTable` في Filament):
/// بحث برقم السند/المشترك/اسم المحصل، فلترة بطريقة الدفع وبفترة زمنية،
/// حذف فردي/جماعي (ناعم مع إعادة احتساب الفواتير)، وملخص المبالغ.
class PaymentsListScreen extends StatelessWidget {
  const PaymentsListScreen({super.key, this.initialQuery});

  final PaymentQuery? initialQuery;

  static Widget create({PaymentQuery? initialQuery}) =>
      BlocProvider<PaymentsCubit>(
        create: (_) => PaymentsCubit(
          repository: sl<PaymentsRepository>(),
          initialQuery: initialQuery,
        )..load(),
        child: const PaymentsListScreen(),
      );

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PaymentsCubit>(
      create: (_) => PaymentsCubit(
        repository: sl<PaymentsRepository>(),
        initialQuery: initialQuery,
      )..load(),
      child: const _PaymentsListBody(),
    );
  }
}

class _PaymentsListBody extends StatefulWidget {
  const _PaymentsListBody();

  @override
  State<_PaymentsListBody> createState() => _PaymentsListBodyState();
}

class _PaymentsListBodyState extends State<_PaymentsListBody> {
  final TextEditingController _search = TextEditingController();
  bool _selecting = false;
  late ListPresentationSettings _presentation;

  @override
  void initState() {
    super.initState();
    final query = context.read<PaymentsCubit>().state.query;
    _presentation = ListPresentationSettings(
      sortBy: query.sortBy,
      descending: query.sortDirection == 'desc',
      pageSize: query.limit ?? 25,
      compact: true,
    );
  }

  Future<void> _applyPresentation(ListPresentationSettings settings) async {
    final reload =
        settings.sortBy != _presentation.sortBy ||
        settings.descending != _presentation.descending ||
        settings.pageSize != _presentation.pageSize;
    setState(() {
      _presentation = settings;
      _selecting = false;
    });
    if (!reload) return;
    final cubit = context.read<PaymentsCubit>();
    await cubit.applyFilters(
      cubit.state.query.copyWith(
        limit: settings.pageSize,
        sortBy: settings.sortBy,
        sortDirection: settings.descending ? 'desc' : 'asc',
      ),
    );
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  /// تصدير سندات اليوم من الجهاز ومشاركتها (خطة 2.0 — الفصل 6.2).
  ///
  /// نُصفّي على تاريخ اليوم داخل التطبيق لأن القائمة قد تكون مفلترة بفترة
  /// أوسع؛ المطابقة النقدية تخصّ يوم الصندوق فقط. والمحذوف يُستثنى في
  /// [DailyReceiptsExporter] نفسه.
  Future<void> _exportToday(BuildContext context, PaymentsCubit cubit) async {
    final now = DateTime.now();
    final today = <PaymentModel>[
      for (final payment in cubit.state.payments)
        if (payment.paymentDate != null &&
            payment.paymentDate!.year == now.year &&
            payment.paymentDate!.month == now.month &&
            payment.paymentDate!.day == now.day &&
            !payment.isDeleted)
          payment,
    ];
    if (today.isEmpty) {
      AppFeedback.info(context, 'لا توجد سندات مسجّلة اليوم لتصديرها.');
      return;
    }

    try {
      final exporter = DailyReceiptsExporter(now: () => now);
      final result = await exporter.save(today);
      if (!context.mounted) return;
      await exporter.share(result);
      if (!context.mounted) return;
      AppFeedback.success(
        context,
        'صدّرت ${result.rowCount} سنداً بإجمالي ${CurrencyFormatter.withLabel(result.totalAmount)}.',
      );
    } catch (error) {
      if (!context.mounted) return;
      AppFeedback.error(context, 'تعذّر تصدير سندات اليوم: $error');
    }
  }

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<PaymentsCubit>();

    return Scaffold(
      appBar: AppBar(
        title: Text(_selecting ? 'اختيار سندات' : 'سندات القبض'),
        actions: <Widget>[
          BlocBuilder<PaymentsCubit, PaymentsState>(
            builder: (context, state) => IgnorePointer(
              ignoring: state.isLoading,
              child: ListPresentationControls(
                settings: _presentation,
                sortOptions: const {
                  'payment_date': ListOptionsStrings.date,
                  'receipt_number': ListOptionsStrings.receiptNumber,
                  'subscriber_name': ListOptionsStrings.subscriber,
                  'amount': ListOptionsStrings.amount,
                  'user_name': ListOptionsStrings.collector,
                  'id': ListOptionsStrings.id,
                },
                fields: const {
                  'date': ListOptionsStrings.date,
                  'method': ListOptionsStrings.method,
                  'invoice': ListOptionsStrings.invoice,
                  'account': ListOptionsStrings.account,
                  'reference': ListOptionsStrings.reference,
                  'collector': ListOptionsStrings.collector,
                  'whatsapp': ListOptionsStrings.whatsapp,
                },
                onChanged: _applyPresentation,
              ),
            ),
          ),
          if (_selecting) ...<Widget>[
            IconButton(
              tooltip: 'تحديد الكل',
              onPressed: cubit.selectAll,
              icon: const Icon(Icons.select_all),
            ),
            IconButton(
              tooltip: 'حذف المحدد',
              onPressed: () => _bulkDelete(context, cubit),
              icon: const Icon(Icons.delete_outline, color: AppColors.danger),
            ),
          ] else ...<Widget>[
            IconButton(
              tooltip: 'تحديد متعدد',
              onPressed: () => setState(() => _selecting = true),
              icon: const Icon(Icons.checklist_rtl),
            ),
            // اعتماد طلبات الإلغاء المعلّقة — للمشرف فقط (خطة 2.0 — الفصل 5.3).
            if (trySl<PermissionService>()?.can('payments.void.approve') ??
                false)
              IconButton(
                tooltip: 'طلبات الإلغاء المعلّقة',
                onPressed: () async {
                  final remaining = await VoidRequestsSheet.show(
                    context,
                    sl<PaymentsRepository>(),
                  );
                  if (remaining != null && context.mounted) {
                    unawaited(cubit.load(refresh: true));
                  }
                },
                icon: const Icon(Icons.gavel_outlined),
              ),
            IconButton(
              tooltip: 'تصدير سندات اليوم (CSV)',
              onPressed: () => _exportToday(context, cubit),
              icon: const Icon(Icons.ios_share_rounded),
            ),
          ],
          IconButton(
            tooltip: 'إعادة تحميل',
            onPressed: () => cubit.load(refresh: true),
            icon: const Icon(Icons.refresh),
          ),
        ],
        leading: _selecting
            ? IconButton(
                icon: const Icon(Icons.close),
                onPressed: () {
                  cubit.clearSelection();
                  setState(() => _selecting = false);
                },
              )
            : null,
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _openForm(cubit),
        icon: const Icon(Icons.add),
        label: const Text('بدء التحصيل'),
      ),
      body: SafeArea(
        child: BlocBuilder<PaymentsCubit, PaymentsState>(
          builder: (context, state) {
            return Column(
              children: <Widget>[
                const SizedBox(height: 10),
                AppFilterBar(
                  controller: _search,
                  hint: 'رقم السند، المشترك، أو المحصل',
                  activeCount: _activeFacets(state),
                  onOpenFilters: () => _openFilters(context, cubit, state),
                  onChanged: (value) => cubit.search(value),
                ),
                const SizedBox(height: 8),
                AppFilterChips(
                  children: <Widget>[
                    AppFilterChip(
                      label: 'كل الطرق',
                      selected: state.query.method == null,
                      onTap: () =>
                          cubit.applyFilters(state.query.copyWith(method: '')),
                    ),
                    for (final method in PaymentMethod.values)
                      AppFilterChip(
                        label: method.label,
                        selected: state.query.method == method.wire,
                        onTap: () => cubit.applyFilters(
                          state.query.method == method.wire
                              ? state.query.copyWith(method: '')
                              : state.query.copyWith(method: method.wire),
                        ),
                      ),
                  ],
                ),
                AppActiveFilters(
                  filters: <Widget>[
                    if (state.query.method != null)
                      AppFilterChip(
                        label: PaymentMethod.parse(state.query.method).label,
                        selected: true,
                        icon: Icons.payments_rounded,
                        onRemove: () => cubit.applyFilters(
                          state.query.copyWith(method: ''),
                        ),
                      ),
                    if (state.query.fromDate != null ||
                        state.query.toDate != null)
                      AppFilterChip(
                        label:
                            '${DateFormat.day(state.query.fromDate)} ← '
                            '${DateFormat.day(state.query.toDate)}',
                        selected: true,
                        icon: Icons.event_rounded,
                        onRemove: () => cubit.applyFilters(
                          state.query.copyWith(clearFilters: true),
                        ),
                      ),
                  ],
                  onClearAll: () {
                    _search.clear();
                    cubit.applyFilters(
                      PaymentQuery(
                        sortBy: _presentation.sortBy,
                        sortDirection: _presentation.descending
                            ? 'desc'
                            : 'asc',
                        limit: _presentation.pageSize,
                      ),
                    );
                  },
                ),
                _PaymentsSummary(state: state),
                if (_selecting && state.selectedIds.isNotEmpty)
                  _SelectionBar(
                    count: state.selectedIds.length,
                    onSelectAll: cubit.selectAll,
                    onClear: () {
                      cubit.clearSelection();
                      setState(() => _selecting = false);
                    },
                    onBulkDelete: () => _bulkDelete(context, cubit),
                  ),
                Expanded(child: _buildList(context, cubit, state)),
                if (state.status != PaymentsLoadStatus.idle)
                  SafeArea(
                    top: false,
                    child: Wrap(
                      alignment: WrapAlignment.center,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      spacing: 12,
                      children: [
                        TextButton(
                          onPressed: state.isLoading || !state.hasPreviousPage
                              ? null
                              : () {
                                  setState(() => _selecting = false);
                                  cubit.goToPage(state.query.page - 1);
                                },
                          child: const Text(ListOptionsStrings.previous),
                        ),
                        Text(
                          '${ListOptionsStrings.page} ${state.query.page}${state.lastPage == null ? '' : ' / ${state.lastPage}'}'
                          '${state.totalCount == null ? '' : ' · ${ListOptionsStrings.allRecords}: ${state.totalCount}'}',
                        ),
                        TextButton(
                          onPressed: state.isLoading || !state.hasNextPage
                              ? null
                              : () {
                                  setState(() => _selecting = false);
                                  cubit.goToPage(state.query.page + 1);
                                },
                          child: const Text(ListOptionsStrings.next),
                        ),
                      ],
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }

  Future<void> _openForm(PaymentsCubit cubit) async {
    await context.push<void>(RouteNames.quickCollector);
    if (mounted) await cubit.load(refresh: true);
  }

  int _activeFacets(PaymentsState state) =>
      (state.query.method == null ? 0 : 1) +
      (state.query.fromDate != null || state.query.toDate != null ? 1 : 0);

  Future<void> _openFilters(
    BuildContext context,
    PaymentsCubit cubit,
    PaymentsState state,
  ) async {
    await AppFilterSheet.show<void>(
      context: context,
      builder: (_) => _PaymentsFilterSheet(cubit: cubit, state: state),
    );
  }

  Future<void> _bulkDelete(BuildContext context, PaymentsCubit cubit) async {
    final state = cubit.state;
    if (state.selectedIds.isEmpty) return;

    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف ${state.selectedIds.length} سند',
      message: 'سيُحذف كل سند حذفًا ناعمًا وتُعاد احتساب الفواتير المرتبطة به.',
      confirmLabel: 'حذف الكل',
      destructive: true,
    );
    if (!confirmed || !context.mounted) return;

    final count = await cubit.bulkDelete();
    if (!context.mounted) return;
    if (count > 0) {
      AppFeedback.success(context, 'تم حذف $count سند.');
    }
    if (mounted) setState(() => _selecting = false);
  }

  Widget _buildList(
    BuildContext context,
    PaymentsCubit cubit,
    PaymentsState state,
  ) {
    if (state.isLoading && state.payments.isEmpty) {
      return const SkeletonList(count: 7, lines: 2);
    }

    if (state.status == PaymentsLoadStatus.failure && state.payments.isEmpty) {
      return ErrorStateWidget(
        message: state.errorMessage ?? 'تعذّر تحميل السندات.',
        onRetry: () => cubit.load(refresh: true),
      );
    }

    if (state.payments.isEmpty) {
      return ListView(
        children: <Widget>[
          const SizedBox(height: 60),
          EmptyStateWidget(
            title: 'لا توجد سندات',
            message: state.query.search.isEmpty
                ? 'لم يسجَّل أي تحصيل بعد.'
                : 'لا نتائج مطابقة للبحث.',
            icon: Icons.receipt_long_outlined,
          ),
        ],
      );
    }

    final useGrid = context.useMasterDetail;

    return RefreshIndicator(
      onRefresh: () => cubit.load(refresh: true),
      child: useGrid
          ? GridView.builder(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 80),
              gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
                maxCrossAxisExtent: 420,
                mainAxisExtent: _presentation.compact ? 168 : 190,
              ),
              itemCount: state.payments.length,
              itemBuilder: (context, index) =>
                  _tile(cubit, state, state.payments[index]),
            )
          : ListView.builder(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 80),
              itemCount: state.payments.length,
              itemBuilder: (context, index) =>
                  _tile(cubit, state, state.payments[index]),
            ),
    );
  }

  Widget _tile(PaymentsCubit cubit, PaymentsState state, PaymentModel payment) {
    final selected = state.selectedIds.contains(payment.id);

    if (!_selecting) {
      return Dismissible(
        key: ValueKey<int>(payment.id),
        direction: DismissDirection.endToStart,
        background: Container(
          alignment: Alignment.centerLeft,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          color: AppColors.danger,
          child: const Icon(Icons.delete_outline, color: Colors.white),
        ),
        confirmDismiss: (_) async {
          final confirmed = await ConfirmDialog.show(
            context,
            title: 'حذف سند قبض',
            message:
                'سيُحذف السند حذفًا ناعمًا وتُعاد احتساب الفاتورة المرتبطة.',
            details: <String>[
              payment.receiptNumber,
              CurrencyFormatter.withLabel(payment.amount),
            ],
            confirmLabel: 'حذف',
            destructive: true,
          );
          if (!confirmed) return false;
          final deleted = await cubit.deletePayment(payment.id);
          if (deleted && mounted) AppFeedback.success(context, 'تم حذف السند.');
          return deleted;
        },
        child: GestureDetector(
          onLongPress: () {
            setState(() => _selecting = true);
            cubit.toggleSelection(payment.id);
          },
          child: PaymentCard(
            payment: payment,
            dense: _presentation.compact,
            hiddenFields: _presentation.hiddenFields,
            onTap: () => showPaymentActionSheet(context, payment: payment),
          ),
        ),
      );
    }

    return CheckboxListTile(
      value: selected,
      onChanged: (_) => cubit.toggleSelection(payment.id),
      controlAffinity: ListTileControlAffinity.leading,
      contentPadding: EdgeInsets.zero,
      title: PaymentCard(
        payment: payment,
        dense: _presentation.compact,
        hiddenFields: _presentation.hiddenFields,
      ),
    );
  }
}

class _PaymentsSummary extends StatelessWidget {
  const _PaymentsSummary({required this.state});

  final PaymentsState state;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.success.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.success.withValues(alpha: 0.18)),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: _Cell(
              label: ListOptionsStrings.pageCount,
              value: '${state.payments.length}',
            ),
          ),
          Expanded(
            child: _Cell(
              label: ListOptionsStrings.pageTotal,
              value: CurrencyFormatter.withLabel(state.totalSum),
            ),
          ),
          Expanded(
            child: _Cell(
              label: ListOptionsStrings.pageCash,
              value: CurrencyFormatter.withLabel(state.cashSum),
              tone: AppColors.neutral,
            ),
          ),
        ],
      ),
    );
  }
}

class _Cell extends StatelessWidget {
  const _Cell({required this.label, required this.value, this.tone});

  final String label;
  final String value;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(
          label,
          style: const TextStyle(fontSize: 11, color: AppColors.neutral),
        ),
        Text(
          value,
          style: TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w600,
            color: tone,
          ),
        ),
      ],
    );
  }
}

class _SelectionBar extends StatelessWidget {
  const _SelectionBar({
    required this.count,
    required this.onSelectAll,
    required this.onClear,
    required this.onBulkDelete,
  });

  final int count;
  final VoidCallback onSelectAll;
  final VoidCallback onClear;
  final VoidCallback onBulkDelete;

  @override
  Widget build(BuildContext context) => Material(
    color: AppColors.primaryDark,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Row(
        children: <Widget>[
          Text(
            'محدد: $count سند',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
          const Spacer(),
          TextButton(
            onPressed: onSelectAll,
            child: const Text(
              'تحديد الكل',
              style: TextStyle(color: Colors.white70),
            ),
          ),
          TextButton(
            onPressed: onClear,
            child: const Text('إلغاء', style: TextStyle(color: Colors.white70)),
          ),
          TextButton.icon(
            onPressed: onBulkDelete,
            icon: const Icon(
              Icons.delete_outline,
              size: 18,
              color: AppColors.onPanelSoft,
            ),
            label: const Text(
              'حذف المحدد',
              style: TextStyle(color: AppColors.onPanelSoft),
            ),
          ),
        ],
      ),
    ),
  );
}

class _PaymentsFilterSheet extends StatefulWidget {
  const _PaymentsFilterSheet({required this.cubit, required this.state});

  final PaymentsCubit cubit;
  final PaymentsState state;

  @override
  State<_PaymentsFilterSheet> createState() => _PaymentsFilterSheetState();
}

class _PaymentsFilterSheetState extends State<_PaymentsFilterSheet> {
  late String? _method = widget.state.query.method;
  late DateTime? _from = widget.state.query.fromDate;
  late DateTime? _to = widget.state.query.toDate;

  Future<void> _pick({required bool from}) async {
    final now = DateTime.now();
    final first = from
        ? DateTime(now.year - 3)
        : (_from ?? DateTime(now.year - 3));
    final last = from ? (_to ?? now) : now;
    final requested = (from ? _from : _to) ?? now;
    final initial = requested.isBefore(first)
        ? first
        : requested.isAfter(last)
        ? last
        : requested;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: first,
      lastDate: last,
      helpText: from ? 'من تاريخ القبض' : 'إلى تاريخ القبض',
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return AppFilterSheet(
      title: 'تصفية سندات القبض',
      resultCountLabel: '${widget.state.payments.length} سند في القائمة',
      applyLabel: 'عرض النتائج',
      onApply: () {
        widget.cubit.applyFilters(
          widget.state.query.copyWith(
            method: _method ?? '',
            fromDate: _from,
            toDate: _to,
          ),
        );
        Navigator.of(context).maybePop();
      },
      onReset: () => setState(() {
        _method = null;
        _from = null;
        _to = null;
      }),
      resetEnabled: _method != null || _from != null || _to != null,
      sections: <Widget>[
        AppFilterSection(
          title: 'طريقة الدفع',
          child: Column(
            children: <Widget>[
              AppFilterOptionTile(
                label: 'كل الطرق',
                icon: Icons.apps_rounded,
                selected: _method == null,
                onTap: () => setState(() => _method = null),
              ),
              const SizedBox(height: 6),
              for (final method in PaymentMethod.values) ...<Widget>[
                AppFilterOptionTile(
                  label: method.label,
                  icon: method == PaymentMethod.cash
                      ? Icons.payments_rounded
                      : method == PaymentMethod.bankTransfer
                      ? Icons.account_balance_rounded
                      : Icons.receipt_long_rounded,
                  selected: _method == method.wire,
                  onTap: () => setState(() => _method = method.wire),
                ),
                const SizedBox(height: 6),
              ],
            ],
          ),
        ),
        AppFilterSection(
          title: 'فترة القبض',
          subtitle: 'حدّد نطاق السندات بالتاريخ',
          child: AppFilterDateRange(
            fromLabel: _from == null ? 'بدون حد' : DateFormat.day(_from),
            toLabel: _to == null ? 'بدون حد' : DateFormat.day(_to),
            onPickFrom: () => _pick(from: true),
            onPickTo: () => _pick(from: false),
            onClear: _from == null && _to == null
                ? null
                : () => setState(() {
                    _from = null;
                    _to = null;
                  }),
          ),
        ),
        const SizedBox(height: 18),
      ],
    );
  }
}
