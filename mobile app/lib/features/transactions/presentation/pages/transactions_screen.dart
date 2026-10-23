import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
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
                    ListTile(
                      leading: const Icon(Icons.people_outline),
                      title: const Text('كل المشتركين'),
                      selected: state.subscriberId == null,
                      onTap: () => Navigator.pop(sheetCtx, -1),
                    ),
                    const Divider(height: 1),
                    Expanded(
                      child: ListView.builder(
                        itemCount: filtered.length,
                        itemBuilder: (_, i) {
                          final s = filtered[i];
                          return ListTile(
                            leading: const Icon(Icons.person_outline),
                            title: Text(s.name),
                            subtitle: Text('${s.squareName ?? ''} • ${s.phone ?? ''}'),
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

        return Scaffold(
          appBar: AppBar(
            title: const Text('دفتر الأستاذ العام (قراءة فقط)'),
            actions: [
              IconButton(
                tooltip: 'تحديث',
                icon: const Icon(Icons.refresh),
                onPressed: context.read<TransactionsCubit>().load,
              ),
            ],
          ),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
              child: TextField(
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'بحث باسم المشترك أو البيان أو كود العداد...',
                ),
                onChanged: context.read<TransactionsCubit>().search,
              ),
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
                DropdownButton<String?>(
                  value: validMeterValue,
                  hint: const Text('كل الحسابات / العدادات'),
                  items: [
                    const DropdownMenuItem<String?>(value: null, child: Text('كل الحسابات')),
                    const DropdownMenuItem<String?>(value: 'general', child: Text('الحساب العام (بدون عداد)')),
                    for (final m in meterOptions)
                      DropdownMenuItem<String?>(
                        value: '${m.id}',
                        child: Text(m.label),
                      ),
                  ],
                  onChanged: context.read<TransactionsCubit>().filterMeter,
                ),
                const SizedBox(width: 12),
                DropdownButton<String?>(
                  value: state.type,
                  hint: const Text('كل أنواع القيود'),
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
                (label: 'إجمالي المدين المعروض', value: state.totalDebit, color: Colors.redAccent),
              ]),
            ),
            if (state.loading) const LinearProgressIndicator(),
            Expanded(
              child: state.transactions.isEmpty && !state.loading
                  ? const EmptyState(message: 'لا توجد حركات مالية مطابقة', icon: Icons.account_balance)
                  : ListView.builder(
                      itemCount: state.transactions.length,
                      itemBuilder: (_, i) {
                        final t = state.transactions[i];
                        return ListTile(
                          leading: Icon(Icons.receipt_long, color: TransactionsScreen.typeColor(t.type)),
                          title: Text('${t.subscriberName ?? ''} • ${t.description ?? ''}'),
                          subtitle: Text(
                            '${TransactionsScreen._fmtDate(t.createdAt)} • '
                            '${t.meterId == null ? 'الحساب العام' : 'عداد رقم: ${t.meterId}'}'
                            '${t.referenceType != null ? ' • مرجع: ${t.referenceType!.split('\\').last.split('.').last}#${t.referenceId}' : ''}',
                          ),
                          trailing: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              StatusBadge(label: t.typeLabel, color: TransactionsScreen.typeColor(t.type)),
                              BalanceText(t.amount, fontSize: 13),
                            ],
                          ),
                        );
                      },
                    ),
            ),
          ]),
        );
      },
    );
  }
}
