import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_responsive.dart';
import '../../../../core/finance/finance_search.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../data/models/payment_model.dart';
import '../../domain/repositories/payments_repository.dart';
import '../cubit/payments_cubit.dart';
import '../widgets/payment_actions.dart';
import '../widgets/payment_card.dart';
import 'payment_form_screen.dart';

/// `SCR-17` — قائمة سندات القبض (`PaymentTable` في Filament):
/// بحث برقم السند/المشترك/اسم المحصل، فلترة بطريقة الدفع وبفترة زمنية،
/// حذف فردي/جماعي (ناعم مع إعادة احتساب الفواتير)، وملخص المبالغ.
class PaymentsListScreen extends StatelessWidget {
  const PaymentsListScreen({super.key, this.initialQuery});

  final PaymentQuery? initialQuery;

  static Widget create({PaymentQuery? initialQuery}) => BlocProvider<PaymentsCubit>(
        create: (_) => PaymentsCubit(
              repository: sl<PaymentsRepository>(),
              initialQuery: initialQuery,
            )
            ..load(),
        child: const PaymentsListScreen(),
      );

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PaymentsCubit>(
      create: (_) => PaymentsCubit(
            repository: sl<PaymentsRepository>(),
            initialQuery: initialQuery,
          )
          ..load(),
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

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<PaymentsCubit>();

    return Scaffold(
      appBar: AppBar(
        title: Text(_selecting ? 'اختيار سندات' : 'سندات القبض'),
        actions: <Widget>[
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
          ] else
            IconButton(
              tooltip: 'تحديد متعدد',
              onPressed: () => setState(() => _selecting = true),
              icon: const Icon(Icons.checklist_rtl),
            ),
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
        label: const Text('دفعة جديدة'),
      ),
      body: SafeArea(
        child: BlocBuilder<PaymentsCubit, PaymentsState>(
          builder: (context, state) {
            return Column(
              children: <Widget>[
                FinanceSearchBar(
                  controller: _search,
                  hint: 'ابحث برقم السند أو اسم المشترك أو المحصل',
                  onChanged: (value) => cubit.search(value),
                  onSubmitted: (_) => cubit.load(refresh: true),
                  filters: <Widget>[
                    _MethodFilter(cubit: cubit, state: state),
                    _PeriodFilter(cubit: cubit, state: state),
                  ],
                  trailing: state.query.search.isEmpty
                      ? null
                      : IconButton(
                          icon: const Icon(Icons.clear),
                          onPressed: () {
                            _search.clear();
                            cubit.search('');
                          },
                        ),
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
              ],
            );
          },
        ),
      ),
    );
  }

  Future<void> _openForm(PaymentsCubit cubit) async {
    final created = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(builder: (_) => const PaymentFormScreen()),
    );
    if (created == true) await cubit.load(refresh: true);
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

  Widget _buildList(BuildContext context, PaymentsCubit cubit, PaymentsState state) {
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
              gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                maxCrossAxisExtent: 420,
                mainAxisExtent: 168,
              ),
              itemCount: state.payments.length,
              itemBuilder: (context, index) => _tile(cubit, state, state.payments[index]),
            )
          : ListView.builder(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 80),
              itemCount: state.payments.length,
              itemBuilder: (context, index) => _tile(cubit, state, state.payments[index]),
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
            message: 'سيُحذف السند حذفًا ناعمًا وتُعاد احتساب الفاتورة المرتبطة.',
            details: <String>[
              payment.receiptNumber,
              CurrencyFormatter.withLabel(payment.amount),
            ],
            confirmLabel: 'حذف',
            destructive: true,
          );
          if (!confirmed) return false;
          await cubit.deletePayment(payment.id);
          if (mounted) AppFeedback.success(context, 'تم حذف السند.');
          return true;
        },
        child: GestureDetector(
          onLongPress: () {
            setState(() => _selecting = true);
            cubit.toggleSelection(payment.id);
          },
          child: PaymentCard(
            payment: payment,
            dense: true,
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
      title: PaymentCard(payment: payment, dense: true),
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
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.success.withValues(alpha: 0.18)),
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: _Cell(label: 'عدد السندات', value: '${state.payments.length}'),
          ),
          Expanded(
            child: _Cell(
              label: 'الإجمالي المحصّل',
              value: CurrencyFormatter.withLabel(state.totalSum),
            ),
          ),
          Expanded(
            child: _Cell(
              label: 'نقدي',
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
        Text(label, style: const TextStyle(fontSize: 11, color: AppColors.neutral)),
        Text(
          value,
          style: TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w800,
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
                  fontWeight: FontWeight.w700,
                ),
              ),
              const Spacer(),
              TextButton(
                onPressed: onSelectAll,
                child: const Text('تحديد الكل', style: TextStyle(color: Colors.white70)),
              ),
              TextButton(
                onPressed: onClear,
                child: const Text('إلغاء', style: TextStyle(color: Colors.white70)),
              ),
              TextButton.icon(
                onPressed: onBulkDelete,
                icon: const Icon(Icons.delete_outline, size: 18, color: Color(0xFFFCA5A5)),
                label: const Text(
                  'حذف المحدد',
                  style: TextStyle(color: Color(0xFFFCA5A5)),
                ),
              ),
            ],
          ),
        ),
      );
}

class _MethodFilter extends StatelessWidget {
  const _MethodFilter({required this.cubit, required this.state});

  final PaymentsCubit cubit;
  final PaymentsState state;

  @override
  Widget build(BuildContext context) {
    return FilterChipButton(
      label: 'طريقة الدفع',
      value: state.query.method,
      selected: state.query.method != null,
      onPressed: () async {
        final method = await showModalBottomSheet<String?>(
          context: context,
          showDragHandle: true,
          builder: (sheetContext) => SafeArea(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                ListTile(
                  leading: const Icon(Icons.close),
                  title: const Text('كل الطرق'),
                  onTap: () => Navigator.of(sheetContext).pop(''),
                ),
                const Divider(height: 1),
                ...PaymentMethod.values.map(
                  (m) => ListTile(
                    leading: Icon(m.icon),
                    title: Text(m.label),
                    onTap: () => Navigator.of(sheetContext).pop(m.wire),
                  ),
                ),
                const SizedBox(height: 8),
              ],
            ),
          ),
        );
        if (method == null) return;
        await cubit.applyFilters(
          state.query.copyWith(method: method.isEmpty ? null : method),
        );
      },
    );
  }
}

extension on PaymentMethod {
  IconData get icon => switch (this) {
        PaymentMethod.cash => Icons.payments_outlined,
        PaymentMethod.bankTransfer => Icons.account_balance_outlined,
        PaymentMethod.check => Icons.event_note_outlined,
      };
}

/// فلتر الفترة (من/إلى) — يطابق `->dateFilter('payment_date')` في Filament.
class _PeriodFilter extends StatelessWidget {
  const _PeriodFilter({required this.cubit, required this.state});

  final PaymentsCubit cubit;
  final PaymentsState state;

  @override
  Widget build(BuildContext context) {
    final label = state.query.fromDate == null
        ? null
        : '${DateFormat.day(state.query.fromDate)} ← ${DateFormat.day(state.query.toDate)}';

    return FilterChipButton(
      label: 'الفترة',
      value: label,
      selected: state.query.fromDate != null,
      icon: Icons.event_outlined,
      onPressed: () async {
        final now = DateTime.now();
        final from = await showDatePicker(
          context: context,
          initialDate: state.query.fromDate ?? now.subtract(const Duration(days: 30)),
          firstDate: DateTime(now.year - 3),
          lastDate: now,
        );
        if (from == null || !context.mounted) return;
        final to = await showDatePicker(
          context: context,
          initialDate: state.query.toDate ?? now,
          firstDate: from,
          lastDate: now.add(const Duration(days: 1)),
        );
        if (!context.mounted) return;
        if (to == null) return;
        await cubit.applyFilters(state.query.copyWith(fromDate: from, toDate: to));
      },
    );
  }
}
