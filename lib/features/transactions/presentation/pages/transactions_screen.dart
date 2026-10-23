import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../cubit/transactions_cubit.dart';

bool _hasTransactionsCubit(BuildContext context) {
  try {
    context.read<TransactionsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// SCR-22 — دفتر الأستاذ العام: قراءة فقط، بحث + فلترة (مشترك/عداد/نوع قيد).
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

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<TransactionsCubit, TransactionsState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(title: const Text('SCR-22 • دفتر الأستاذ العام (قراءة فقط)')),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
              child: TextField(
                decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'بحث باسم المشترك أو البيان...', border: OutlineInputBorder()),
                onChanged: context.read<TransactionsCubit>().search,
              ),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(children: [
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
                const SizedBox(width: 12),
                DropdownButton<String?>(
                  value: state.meterId,
                  hint: const Text('كل الحسابات'),
                  items: const [
                    DropdownMenuItem(value: null, child: Text('كل الحسابات')),
                    DropdownMenuItem(value: 'general', child: Text('الحساب العام (بدون عداد)')),
                  ],
                  onChanged: context.read<TransactionsCubit>().filterMeter,
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
                          subtitle: Text('${TransactionsScreen._fmtDate(t.createdAt)} • ${t.meterId == null ? 'الحساب العام' : 'عداد رقم: ${t.meterId}'}${t.referenceType != null ? ' • مرجع: ${t.referenceType!.split('.').last}#${t.referenceId}' : ''}'),
                          trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                            StatusBadge(label: t.typeLabel, color: TransactionsScreen.typeColor(t.type)),
                            BalanceText(t.amount, fontSize: 13),
                          ]),
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
