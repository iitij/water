import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/ledger_transaction.dart';
import '../cubit/transactions_cubit.dart';

bool _hasTransactionsCubit(BuildContext context) {
  try {
    context.read<TransactionsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// SCR-22 — دفتر الأستاذ العام: قراءة فقط، بحث + فلترة (مشترك / عداد فعلي / نوع قيد).
class TransactionsScreen extends StatelessWidget {
  const TransactionsScreen({super.key});

  static Color typeColor(String type) => switch (type) {
        'payment' || 'credit' => AppColors.success,
        'invoice' || 'debit' => AppColors.danger,
        'adjustment' => AppColors.warning,
        _ => AppColors.neutral,
      };

  @override
  Widget build(BuildContext context) {
    if (!_hasTransactionsCubit(context)) {
      return BlocProvider<TransactionsCubit>(
        create: (_) => TransactionsCubit(FouadModule.deps.transactions)..load(),
        child: const _TransactionsView(),
      );
    }
    return const _TransactionsView();
  }

  static String _fmtDate(String? iso) {
    if (iso == null || iso.length < 16) return iso ?? '';
    return iso.substring(0, 16).replaceAll('T', ' ');
  }

  static String _shortDate(DateTime date) {
    final month = date.month.toString().padLeft(2, '0');
    final day = date.day.toString().padLeft(2, '0');
    return '${date.year}-$month-$day';
  }
}

class _TransactionsView extends StatelessWidget {
  const _TransactionsView();

  Future<void> _pickSubscriber(BuildContext context, TransactionsState state) async {
    final cubit = context.read<TransactionsCubit>();
    var query = '';
    final picked = await showModalBottomSheet<int?>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheetCtx) => StatefulBuilder(
        builder: (sheetCtx, setSheetState) {
          final filtered = state.subscribers.where((Subscriber s) {
            if (query.trim().isEmpty) return true;
            return s.name.contains(query) ||
                (s.phone ?? '').contains(query) ||
                (s.meterNumber ?? '').contains(query);
          }).toList();
          return SafeArea(
            child: FractionallySizedBox(
              heightFactor: 0.75,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    TextField(
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.search),
                        hintText: 'ابحث عن مشترك بالاسم أو الهاتف...',
                      ),
                      onChanged: (v) => setSheetState(() => query = v),
                    ),
                    const SizedBox(height: 8),
                    _SubscriberOptionRow(
                      icon: Icons.people_outline,
                      title: 'كل المشتركين',
                      subtitle: '',
                      selected: state.subscriberId == null,
                      onTap: () => Navigator.pop(sheetCtx, -1),
                    ),
                    const Divider(height: 1),
                    Expanded(
                      child: ListView.builder(
                        itemCount: filtered.length,
                        itemBuilder: (_, i) {
                          final s = filtered[i];
                          return _SubscriberOptionRow(
                            icon: Icons.person_outline,
                            title: s.name,
                            subtitle: '${s.squareName ?? ''} • ${s.phone ?? ''}',
                            selected: state.subscriberId == s.id,
                            onTap: () => Navigator.pop(sheetCtx, s.id),
                          );
                        },
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );

    if (picked == null || !context.mounted) return;
    await cubit.filterSubscriber(picked == -1 ? null : picked);
  }

  Future<void> _pickDateRange(BuildContext context, TransactionsState state) async {
    final cubit = context.read<TransactionsCubit>();
    final initial = state.fromDate ?? DateTime.now();
    final from = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2015),
      lastDate: DateTime.now().add(const Duration(days: 366)),
      helpText: 'من تاريخ',
    );
    if (from == null) return;
    if (!context.mounted) return;
    final to = await showDatePicker(
      context: context,
      initialDate: state.toDate ?? from,
      firstDate: from,
      lastDate: DateTime.now().add(const Duration(days: 366)),
      helpText: 'إلى تاريخ',
    );
    if (to == null) return;
    await cubit.filterDateRange(from, to);
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<TransactionsCubit, TransactionsState>(
      builder: (context, state) {
        final selectedSub = state.subscriberId == null
            ? null
            : state.subscribers.cast<Subscriber?>().firstWhere(
                  (s) => s?.id == state.subscriberId,
                  orElse: () => null,
                );
        final subscriberChipLabel = state.subscriberId == null
            ? 'كل المشتركين'
            : (selectedSub?.name ?? 'مشترك #${state.subscriberId}');

        final meterOptions = state.visibleMeters;
        final validMeterValue = state.meterId == null ||
                state.meterId == 'general' ||
                meterOptions.any((m) => '${m.id}' == state.meterId)
            ? state.meterId
            : null;

        return SubPageScaffold(
          title: 'دفتر الأستاذ العام (قراءة فقط)',
          subtitle: 'بحث وفلترة كل القيود المالية للمشروع',
          actions: [
            AppIconButton(
              tooltip: 'تحديث',
              icon: Icons.refresh_rounded,
              onPressed: context.read<TransactionsCubit>().load,
            ),
          ],
          child: Column(children: [
            AppFilterBar(
              query: state.search,
              hint: 'بحث باسم المشترك أو البيان أو كود العداد...',
              onChanged: context.read<TransactionsCubit>().search,
              padding: const EdgeInsetsDirectional.fromSTEB(12, 12, 12, 4),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(children: [
                AppFilterChip(
                  label: subscriberChipLabel,
                  icon: Icons.person_search_rounded,
                  selected: state.subscriberId != null,
                  onTap: () => _pickSubscriber(context, state),
                  onRemove: state.subscriberId == null
                      ? null
                      : () => context.read<TransactionsCubit>().filterSubscriber(null),
                ),
                const SizedBox(width: 12),
                AppFilterDropdown<String>(
                  value: validMeterValue,
                  hint: 'كل الحسابات / العدادات',
                  icon: Icons.water_damage_outlined,
                  items: [
                    const DropdownMenuItem<String>(value: null, child: Text('كل الحسابات')),
                    const DropdownMenuItem<String>(value: 'general', child: Text('الحساب العام (بدون عداد)')),
                    for (final m in meterOptions)
                      DropdownMenuItem<String>(
                        value: '${m.id}',
                        child: Text(m.label),
                      ),
                  ],
                  onChanged: context.read<TransactionsCubit>().filterMeter,
                ),
                const SizedBox(width: 12),
                AppFilterChip(
                  label: state.fromDate == null
                      ? 'كل الفترات'
                      : '${TransactionsScreen._shortDate(state.fromDate!)} ← ${TransactionsScreen._shortDate(state.toDate ?? DateTime.now())}',
                  icon: Icons.date_range_rounded,
                  selected: state.fromDate != null,
                  onTap: () => _pickDateRange(context, state),
                  onRemove: state.fromDate == null
                      ? null
                      : () => context.read<TransactionsCubit>().filterDateRange(null, null),
                ),
                const SizedBox(width: 12),
                AppFilterDropdown<String>(
                  value: state.type,
                  hint: 'كل أنواع القيود',
                  icon: Icons.category_outlined,
                  items: const [
                    DropdownMenuItem(value: null, child: Text('كل أنواع القيود')),
                    DropdownMenuItem(value: 'payment', child: Text('سند قبض (دائن)')),
                    DropdownMenuItem(value: 'invoice', child: Text('فاتورة (مدين)')),
                    DropdownMenuItem(value: 'adjustment', child: Text('تسوية')),
                  ],
                  onChanged: context.read<TransactionsCubit>().filterType,
                ),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: SumBar(items: [
                (label: 'إجمالي الدائن المعروض', value: state.totalCredit, color: AppColors.success),
                (label: 'إجمالي المدين المعروض', value: state.totalDebit, color: AppColors.danger),
              ]),
            ),
            if (state.loading) const LinearProgressIndicator(),
            Expanded(
              child: state.transactions.isEmpty && !state.loading
                  ? const EmptyState(message: 'لا توجد حركات مالية مطابقة', icon: Icons.account_balance)
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(12, 4, 12, 16),
                      itemCount: state.transactions.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 6),
                      itemBuilder: (_, i) {
                        final t = state.transactions[i];
                        return _LedgerRow(transaction: t);
                      },
                    ),
            ),
          ]),
        );
      },
    );
  }
}

class _LedgerRow extends StatelessWidget {
  const _LedgerRow({required this.transaction});

  final LedgerTransaction transaction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final t = transaction;
    final tone = TransactionsScreen.typeColor(t.type);
    return AppCard(
      padding: const EdgeInsetsDirectional.fromSTEB(12, 10, 12, 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          AppGlyph(icon: Icons.receipt_long_rounded, tone: tone, size: 38),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${t.subscriberName ?? ''} • ${t.description ?? ''}',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
                const SizedBox(height: 3),
                Text(
                  '${TransactionsScreen._fmtDate(t.createdAt)} • '
                  '${t.meterId == null ? 'الحساب العام' : 'عداد رقم: ${t.meterId}'}'
                  '${t.referenceType != null ? ' • مرجع: ${t.referenceType!.split('\\').last.split('.').last}#${t.referenceId}' : ''}',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: [
              StatusBadge(label: t.typeLabel, color: tone),
              const SizedBox(height: 4),
              BalanceText(t.amount, fontSize: 13),
            ],
          ),
        ],
      ),
    );
  }
}

/// One selectable option row inside the subscriber picker sheet.
class _SubscriberOptionRow extends StatelessWidget {
  const _SubscriberOptionRow({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.selected,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(4, 8, 4, 8),
        child: Row(
          children: [
            AppGlyph(icon: icon, tone: selected ? scheme.primary : AppColors.neutral, size: 36),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (subtitle.isNotEmpty && subtitle != ' • ') ...<Widget>[
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (selected)
              Icon(Icons.check_circle_rounded, size: 20, color: scheme.primary),
          ],
        ),
      ),
    );
  }
}
