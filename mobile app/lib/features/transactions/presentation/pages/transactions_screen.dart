import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/widgets/search_text_field.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../cubit/transactions_cubit.dart';

bool _hasTransactionsCubit(BuildContext context) {
  try {
    context.read<TransactionsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// دفتر الأستاذ العام: قراءة فقط، بحث + فلترة (مشترك/عداد/نوع قيد).
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
          appBar: AppBar(title: const Text('دفتر الأستاذ العام')),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
              child: SearchTextField(
                query: state.search,
                hintText: 'بحث باسم المشترك أو البيان...',
                onChanged: context.read<TransactionsCubit>().search,
              ),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(children: [
                SizedBox(
                  width: 170,
                  child: _FilterSelect<String?>(
                    hint: 'كل أنواع القيود',
                    value: state.type,
                    items: const [
                      DropdownMenuItem(value: null, child: Text('كل أنواع القيود')),
                      DropdownMenuItem(value: 'payment', child: Text('سند قبض (دائن)')),
                      DropdownMenuItem(value: 'invoice', child: Text('فاتورة (مدين)')),
                      DropdownMenuItem(value: 'adjustment', child: Text('تسوية')),
                    ],
                    onChanged: context.read<TransactionsCubit>().filterType,
                  ),
                ),
                const SizedBox(width: 12),
                SizedBox(
                  width: 170,
                  child: _FilterSelect<String?>(
                    hint: 'كل الحسابات',
                    value: state.meterId,
                    items: const [
                      DropdownMenuItem(value: null, child: Text('كل الحسابات')),
                      DropdownMenuItem(value: 'general', child: Text('الحساب العام (بدون عداد)')),
                    ],
                    onChanged: context.read<TransactionsCubit>().filterMeter,
                  ),
                ),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: SumBar(items: [
                (label: 'إجمالي الدائن المعروض', value: state.totalCredit, color: AppColors.success),
                (label: 'إجمالي المدين المعروض', value: state.totalDebit, color: AppColors.danger),
              ]),
            ),
            Expanded(
              child: state.loading
                  ? ListView(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      children: List.generate(8, (_) => ShimmerSkeleton(height: 88)),
                    )
                  : state.transactions.isEmpty
                      ? const EmptyState(message: 'لا توجد حركات مالية مطابقة', icon: Icons.account_balance)
                      : ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          itemCount: state.transactions.length,
                          itemBuilder: (_, i) {
                            final t = state.transactions[i];
                            final color = TransactionsScreen.typeColor(t.type);
                            return AdminCard(
                              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Container(
                                    width: 42,
                                    height: 42,
                                    decoration: BoxDecoration(
                                      color: color.withValues(alpha: .12),
                                      borderRadius: BorderRadius.circular(13),
                                    ),
                                    child: Icon(Icons.receipt_long_rounded, color: color, size: 21),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text('${t.subscriberName ?? ''} • ${t.description ?? ''}',
                                            maxLines: 1, overflow: TextOverflow.ellipsis),
                                        const SizedBox(height: 3),
                                        Text(
                                          '${TransactionsScreen._fmtDate(t.createdAt)} • ${t.meterId == null ? 'الحساب العام' : 'عداد رقم: ${t.meterId}'}${t.referenceType != null ? ' • مرجع: ${t.referenceType!.split('.').last}#${t.referenceId}' : ''}',
                                          style: TextStyle(fontSize: 12, color: AppColors.neutral),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ],
                                    ),
                                  ),
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      StatusBadge(label: t.typeLabel, color: color),
                                      const SizedBox(height: 6),
                                      BalanceText(t.amount, fontSize: 13),
                                    ],
                                  ),
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

/// dropdown معلم عصري يطابق ثيم الإدخالات.
class _FilterSelect<T> extends StatelessWidget {
  const _FilterSelect({
    required this.hint,
    required this.value,
    required this.items,
    required this.onChanged,
  });

  final String hint;
  final T? value;
  final List<DropdownMenuItem<T>> items;
  final ValueChanged<T?> onChanged;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<T>(
      value: value,
      isDense: true,
      isExpanded: true,
      hint: Text(hint),
      items: items,
      onChanged: onChanged,
      decoration: InputDecoration(
        isCollapsed: true,
        filled: true,
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 13),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.7),
        ),
        floatingLabelBehavior: FloatingLabelBehavior.never,
      ),
    );
  }
}
