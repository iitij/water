import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/widgets/app_numeric_field.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../../shared/widgets/subscriber_meter_selector.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/subscription_fee.dart';
import '../cubit/subscription_fees_cubit.dart';

bool _hasSubscriptionFeesCubit(BuildContext context) {
  try {
    context.read<SubscriptionFeesCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// رسوم الاشتراك: Sum Summarizer (إجمالي/مفوتر/متبقي) + فلتر حالة + إصدار فاتورة.
class SubscriptionFeesScreen extends StatelessWidget {
  const SubscriptionFeesScreen({super.key, this.onAddFee, this.onGenerateInvoice});

  /// نافذة إضافة رسوم (اختيار مشترك ثم عداده) — تُبنى بمنتقي فؤاد المشترك.
  final VoidCallback? onAddFee;
  final Future<void> Function(BuildContext context, SubscriptionFee? fee)? onGenerateInvoice;

  @override
  Widget build(BuildContext context) {
    if (!_hasSubscriptionFeesCubit(context)) {
      return BlocProvider<SubscriptionFeesCubit>(
        create: (_) => SubscriptionFeesCubit(FouadModule.deps.fees)..load(),
        child: _SubscriptionFeesView(
          onAddFee: onAddFee,
          onGenerateInvoice: onGenerateInvoice,
        ),
      );
    }
    return _SubscriptionFeesView(
      onAddFee: onAddFee,
      onGenerateInvoice: onGenerateInvoice,
    );
  }
}

class _SubscriptionFeesView extends StatelessWidget {
  const _SubscriptionFeesView({this.onAddFee, this.onGenerateInvoice});

  final VoidCallback? onAddFee;
  final Future<void> Function(BuildContext context, SubscriptionFee? fee)? onGenerateInvoice;

  Future<void> _openGenerateInvoiceSheet(BuildContext context, {SubscriptionFee? fee}) async {
    if (onGenerateInvoice != null) {
      await onGenerateInvoice!(context, fee);
      return;
    }
    final cubit = context.read<SubscriptionFeesCubit>();
    final canUseFinanceSheet = GetIt.I.isRegistered<InvoicesRepository>() ||
        GetIt.I.isRegistered<DioClient>();
    if (canUseFinanceSheet) {
      await GenerateSubscriptionInvoiceSheet.show(
        context,
        preselectedSubscriberId: fee != null ? '${fee.subscriberId}' : null,
      );
      if (context.mounted) {
        await cubit.load();
      }
      return;
    }
    if (fee != null) {
      final bill = await cubit.invoice(fee.id);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('صدرت فاتورة الاشتراك: $bill')),
        );
      }
    }
  }

  Future<void> _defaultAddFee(BuildContext context) async {
    if (onAddFee != null) {
      onAddFee!();
      return;
    }
    final cubit = context.read<SubscriptionFeesCubit>();
    final repo = FouadModule.deps.subscribers;
    Subscriber? pickedSub;
    final amountCtrl = TextEditingController();
    final notesCtrl = TextEditingController();

    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.card_membership_rounded, size: 32, color: AppColors.primary),
        title: const Text('إضافة رسوم اشتراك', style: TextStyle(fontWeight: FontWeight.w800)),
        content: SizedBox(
          width: 420,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox(
                  height: 220,
                  child: SubscriberMeterSelectorWidget(
                    repository: repo,
                    onSelected: (s, _) {
                      pickedSub = s;
                    },
                  ),
                ),
                const SizedBox(height: 12),
                AppNumericField(controller: amountCtrl, label: 'مبلغ رسوم الاشتراك (ريال) *', suffixText: 'ريال'),
                const SizedBox(height: 10),
                AppTextField(controller: notesCtrl, label: 'ملاحظات', maxLines: 2),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () async {
              final sub = pickedSub;
              final amount = double.tryParse(normalizeDigits(amountCtrl.text));
              if (sub == null || amount == null || amount <= 0) return;
              await cubit.create(
                subscriberId: sub.id,
                amount: amount,
                notes: notesCtrl.text.trim().isEmpty ? null : notesCtrl.text.trim(),
              );
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<SubscriptionFeesCubit, SubscriptionFeesState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('رسوم الاشتراك'),
            actions: [
              IconButton(
                icon: const Icon(Icons.receipt_long_outlined),
                tooltip: 'إصدار فاتورة اشتراك',
                onPressed: () => _openGenerateInvoiceSheet(context),
              ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _defaultAddFee(context),
            icon: const Icon(Icons.add_rounded),
            label: const Text('إضافة رسوم'),
          ),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: SumBar(items: [
                (label: 'إجمالي الرسوم', value: state.totalSum, color: AppColors.primaryDark),
                (label: 'المفوتر', value: state.invoicedSum, color: AppColors.success),
                (label: 'المتبقي غير المفوتر', value: state.remainingSum, color: AppColors.brandAmber),
              ]),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Row(children: [
                FilterChip(
                  label: const Text('الكل'),
                  selected: state.status == null,
                  onSelected: (_) => context.read<SubscriptionFeesCubit>().filterStatus(null),
                ),
                for (final s in FeeStatus.values)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 8),
                    child: FilterChip(
                      label: Text(s.label),
                      selected: state.status == s,
                      onSelected: (_) => context.read<SubscriptionFeesCubit>().filterStatus(s),
                    ),
                  ),
              ]),
            ),
            Expanded(
              child: state.loading
                  ? ListView(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      children: List.generate(6, (_) => ShimmerSkeleton(height: 96)),
                    )
                  : state.fees.isEmpty
                      ? const EmptyState(message: 'لا توجد رسوم اشتراك مطابقة')
                      : ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          itemCount: state.fees.length,
                          itemBuilder: (_, i) {
                            final f = state.fees[i];
                            return AdminCard(
                              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Container(
                                    width: 42,
                                    height: 42,
                                    decoration: BoxDecoration(
                                      color: AppColors.primary.withValues(alpha: .12),
                                      borderRadius: BorderRadius.circular(13),
                                    ),
                                    child: const Icon(Icons.card_membership_outlined, color: AppColors.primary, size: 21),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text('${f.subscriberName ?? 'مشترك #${f.subscriberId}'} • ${f.meterCode ?? 'بدون عداد'}'),
                                        const SizedBox(height: 3),
                                        Text(
                                          'أُضيفت: ${f.createdAt?.substring(0, 10) ?? '-'}${f.invoiceNumber != null ? ' • فاتورة: ${f.invoiceNumber}' : ''}',
                                          style: TextStyle(fontSize: 12, color: AppColors.neutral),
                                        ),
                                      ],
                                    ),
                                  ),
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      Text(CurrencyFormatter.format(f.amount), style: const TextStyle(fontWeight: FontWeight.w800)),
                                      const SizedBox(height: 6),
                                      StatusBadge(
                                        label: f.status.label,
                                        color: switch (f.status) {
                                          FeeStatus.uninvoiced => AppColors.danger,
                                          FeeStatus.invoiced => AppColors.warning,
                                          FeeStatus.partiallyPaid => AppColors.info,
                                          FeeStatus.paid => AppColors.success,
                                        },
                                      ),
                                      const SizedBox(height: 6),
                                      Wrap(
                                        spacing: 4,
                                        runSpacing: 4,
                                        crossAxisAlignment: WrapCrossAlignment.center,
                                        children: [
                                          if (f.canInvoice)
                                            IconButton(
                                              icon: const Icon(Icons.receipt_long_rounded, size: 20, color: AppColors.primary),
                                              tooltip: 'إصدار فاتورة اشتراك',
                                              onPressed: () => _openGenerateInvoiceSheet(context, fee: f),
                                            ),
                                          IconButton(
                                            icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                                            tooltip: 'حذف',
                                            onPressed: () async {
                                              if (await confirmAction(context, 'حذف رسوم', 'حذف رسوم الاشتراك بقيمة ${f.amount}؟')) {
                                                if (context.mounted) {
                                                  context.read<SubscriptionFeesCubit>().delete(f.id);
                                                }
                                              }
                                            },
                                          ),
                                        ],
                                      ),
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
