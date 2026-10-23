import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/common_widgets.dart';
import '../../domain/entities/ledger_transaction.dart';
import '../cubit/transactions_cubit.dart';

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
                          leading: Icon(Icons.receipt_long, color: typeColor(t.type)),
                          title: Text('${t.subscriberName ?? ''} • ${t.description ?? ''}'),
                          subtitle: Text('${_fmtDate(t.createdAt)} • ${t.meterId == null ? 'الحساب العام' : 'عداد رقم: ${t.meterId}'}${t.referenceType != null ? ' • مرجع: ${t.referenceType!.split('.').last}#${t.referenceId}' : ''}'),
                          trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                            StatusBadge(label: t.typeLabel, color: typeColor(t.type)),
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

  static String _fmtDate(String? iso) {
    if (iso == null || iso.length < 16) return iso ?? '';
    return iso.substring(0, 16).replaceAll('T', ' ');
  }
}
