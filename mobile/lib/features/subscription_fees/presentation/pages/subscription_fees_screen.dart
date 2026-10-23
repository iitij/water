import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/widgets/common_widgets.dart';
import '../../domain/entities/subscription_fee.dart';
import '../cubit/subscription_fees_cubit.dart';

/// SCR-19 — رسوم الاشتراك: Sum Summarizer (إجمالي/مفوتر/متبقي) + فلتر حالة + إصدار فاتورة.
class SubscriptionFeesScreen extends StatelessWidget {
  const SubscriptionFeesScreen({super.key, this.onAddFee});

  /// نافذة إضافة رسوم (اختيار مشترك ثم عداده) — تُبنى بمنتقي فؤاد المشترك.
  final VoidCallback? onAddFee;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<SubscriptionFeesCubit, SubscriptionFeesState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(title: const Text('SCR-19 • رسوم الاشتراك')),
          floatingActionButton: onAddFee == null
              ? null
              : FloatingActionButton.extended(
                  onPressed: onAddFee,
                  icon: const Icon(Icons.add),
                  label: const Text('إضافة رسوم'),
                ),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: SumBar(items: [
                (label: 'إجمالي الرسوم', value: state.totalSum, color: Colors.white),
                (label: 'المفوتر', value: state.invoicedSum, color: AppColors.success),
                (label: 'المتبقي غير المفوتر', value: state.remainingSum, color: AppColors.brandAmber),
              ]),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Row(children: [
                ChoiceChip(label: const Text('الكل'), selected: state.status == null, onSelected: (_) => context.read<SubscriptionFeesCubit>().filterStatus(null)),
                for (final s in FeeStatus.values)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 8),
                    child: ChoiceChip(label: Text(s.label), selected: state.status == s, onSelected: (_) => context.read<SubscriptionFeesCubit>().filterStatus(s)),
                  ),
              ]),
            ),
            if (state.loading) const LinearProgressIndicator(),
            Expanded(
              child: state.fees.isEmpty && !state.loading
                  ? const EmptyState(message: 'لا توجد رسوم اشتراك مطابقة')
                  : ListView.builder(
                      itemCount: state.fees.length,
                      itemBuilder: (_, i) {
                        final f = state.fees[i];
                        return Card(
                          margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          child: ListTile(
                            title: Text('${f.subscriberName ?? 'مشترك #${f.subscriberId}'} • ${f.meterCode ?? 'بدون عداد'}'),
                            subtitle: Text('أُضيفت: ${f.createdAt?.substring(0, 10) ?? '-'}${f.invoiceNumber != null ? ' • فاتورة: ${f.invoiceNumber}' : ''}'),
                            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                              Text(CurrencyFormatter.format(f.amount), style: const TextStyle(fontWeight: FontWeight.bold)),
                              const SizedBox(width: 8),
                              StatusBadge(
                                label: f.status.label,
                                color: switch (f.status) {
                                  FeeStatus.uninvoiced => AppColors.danger,
                                  FeeStatus.invoiced => AppColors.warning,
                                  FeeStatus.partiallyPaid => AppColors.info,
                                  FeeStatus.paid => AppColors.success,
                                },
                              ),
                              if (f.canInvoice)
                                IconButton(
                                  icon: const Icon(Icons.receipt_long, color: AppColors.primary),
                                  tooltip: 'إصدار فاتورة اشتراك',
                                  onPressed: () async {
                                    final bill = await context.read<SubscriptionFeesCubit>().invoice(f.id);
                                    if (context.mounted) {
                                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('صدرت فاتورة الاشتراك: $bill')));
                                    }
                                  },
                                ),
                              IconButton(
                                icon: const Icon(Icons.delete_outline, color: AppColors.danger),
                                onPressed: () async {
                                  if (await confirmAction(context, 'حذف رسوم', 'حذف رسوم الاشتراك بقيمة ${f.amount}؟')) {
                                    context.read<SubscriptionFeesCubit>().delete(f.id);
                                  }
                                },
                              ),
                            ]),
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
