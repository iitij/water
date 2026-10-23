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
import '../../domain/entities/service_request.dart';
import '../cubit/service_requests_cubit.dart';

bool _hasServiceRequestsCubit(BuildContext context) {
  try {
    context.read<ServiceRequestsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// طلبات الوايت: Sum Summarizer + فلتر حالة/تاريخ + شارة 📝 + إنجاز + إصدار فاتورة وايت.
class ServiceRequestsScreen extends StatelessWidget {
  const ServiceRequestsScreen({super.key, this.onAddRequest, this.onGenerateInvoice});
  final VoidCallback? onAddRequest;
  final Future<void> Function(BuildContext context, ServiceRequest? request)? onGenerateInvoice;

  @override
  Widget build(BuildContext context) {
    if (!_hasServiceRequestsCubit(context)) {
      return BlocProvider<ServiceRequestsCubit>(
        create: (_) => ServiceRequestsCubit(FouadModule.deps.requests)..load(),
        child: _ServiceRequestsView(
          onAddRequest: onAddRequest,
          onGenerateInvoice: onGenerateInvoice,
        ),
      );
    }
    return _ServiceRequestsView(
      onAddRequest: onAddRequest,
      onGenerateInvoice: onGenerateInvoice,
    );
  }
}

class _ServiceRequestsView extends StatelessWidget {
  const _ServiceRequestsView({this.onAddRequest, this.onGenerateInvoice});
  final VoidCallback? onAddRequest;
  final Future<void> Function(BuildContext context, ServiceRequest? request)? onGenerateInvoice;

  Future<void> _openGenerateTankerInvoice(BuildContext context, {ServiceRequest? request}) async {
    if (onGenerateInvoice != null) {
      await onGenerateInvoice!(context, request);
      return;
    }
    final cubit = context.read<ServiceRequestsCubit>();
    final canUseFinanceSheet = GetIt.I.isRegistered<InvoicesRepository>() ||
        GetIt.I.isRegistered<DioClient>();
    if (canUseFinanceSheet) {
      await GenerateTankerInvoiceSheet.show(
        context,
        preselectedSubscriberId: request != null ? '${request.subscriberId}' : null,
      );
      if (context.mounted) {
        await cubit.load();
      }
      return;
    }
    if (request != null) {
      final bill = await cubit.invoice(request.id);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('صدرت فاتورة الوايت: $bill')),
        );
      }
    }
  }

  Future<void> _defaultAddRequest(BuildContext context) async {
    if (onAddRequest != null) {
      onAddRequest!();
      return;
    }
    final cubit = context.read<ServiceRequestsCubit>();
    final repo = FouadModule.deps.subscribers;
    Subscriber? pickedSub;
    final amountCtrl = TextEditingController(text: '5000');
    final notesCtrl = TextEditingController();

    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.local_shipping_rounded, size: 32, color: AppColors.info),
        title: const Text('طلب وايت جديد', style: TextStyle(fontWeight: FontWeight.w800)),
        content: SizedBox(
          width: 420,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox(
                  height: 200,
                  child: SubscriberMeterSelectorWidget(
                    repository: repo,
                    showMeterPicker: false,
                    onSelected: (s, _) async {
                      pickedSub = s;
                      final last = await repo.lastTankerAmount(s.id);
                      if (last != null) {
                        amountCtrl.text = last.toStringAsFixed(0);
                      }
                    },
                  ),
                ),
                const SizedBox(height: 12),
                AppNumericField(controller: amountCtrl, label: 'سعر الوايت (ريال) *', suffixText: 'ريال'),
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
            child: const Text('إضافة الطلب'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<ServiceRequestsCubit, ServiceRequestsState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('طلبات الوايت'),
            actions: [
              IconButton(
                icon: const Icon(Icons.receipt_long_outlined),
                tooltip: 'إصدار فاتورة وايت',
                onPressed: () => _openGenerateTankerInvoice(context),
              ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _defaultAddRequest(context),
            icon: const Icon(Icons.add_rounded),
            label: const Text('طلب وايت جديد'),
          ),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: SumBar(items: [
                (label: 'إجمالي مبالغ الطلبات المعروضة', value: state.totalSum, color: AppColors.primaryDark),
              ]),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Row(children: [
                FilterChip(
                  label: const Text('الكل'),
                  selected: state.status == null,
                  onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(null),
                ),
                for (final s in TankerStatus.values)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 8),
                    child: FilterChip(
                      label: Text(s.label),
                      selected: state.status == s,
                      onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(s),
                    ),
                  ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  icon: const Icon(Icons.date_range_rounded),
                  label: Text(state.from == null ? 'نطاق التاريخ' : '${state.from} ← ${state.until}'),
                  onPressed: () => _pickRange(context, state),
                ),
                if (state.from != null)
                  IconButton(icon: const Icon(Icons.clear_rounded), onPressed: () => context.read<ServiceRequestsCubit>().filterRange(null, null)),
              ]),
            ),
            Expanded(
              child: state.loading
                  ? ListView(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      children: List.generate(6, (_) => ShimmerSkeleton(height: 96)),
                    )
                  : state.requests.isEmpty
                      ? const EmptyState(message: 'لا توجد طلبات وايت مطابقة', icon: Icons.local_shipping)
                      : ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          itemCount: state.requests.length,
                          itemBuilder: (_, i) {
                            final r = state.requests[i];
                            return AdminCard(
                              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Container(
                                    width: 42,
                                    height: 42,
                                    decoration: BoxDecoration(
                                      color: AppColors.info.withValues(alpha: .12),
                                      borderRadius: BorderRadius.circular(13),
                                    ),
                                    child: const Icon(Icons.local_shipping_rounded, color: AppColors.info, size: 21),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text('${r.subscriberName ?? 'مشترك #${r.subscriberId}'}${r.hasNote ? '  📝 يوجد ملاحظة' : ''}'),
                                        const SizedBox(height: 3),
                                        Text(
                                          '${r.serviceDate} • ${CurrencyFormatter.format(r.amount)}${r.invoiceNumber != null ? ' • فاتورة: ${r.invoiceNumber}' : ''}',
                                          style: TextStyle(fontSize: 12, color: AppColors.neutral),
                                        ),
                                        if (r.hasNote)
                                          Text(r.notes ?? '', style: const TextStyle(fontSize: 12, color: AppColors.neutral)),
                                      ],
                                    ),
                                  ),
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      StatusBadge(
                                        label: r.status.label,
                                        color: switch (r.status) {
                                          TankerStatus.pending => AppColors.warning,
                                          TankerStatus.completed => AppColors.info,
                                          TankerStatus.invoiced => AppColors.primary,
                                          TankerStatus.paid => AppColors.success,
                                        },
                                      ),
                                      const SizedBox(height: 6),
                                      Wrap(
                                        spacing: 4,
                                        runSpacing: 4,
                                        crossAxisAlignment: WrapCrossAlignment.center,
                                        children: [
                                          if (r.canComplete)
                                            IconButton(
                                              icon: const Icon(Icons.check_circle_rounded, size: 20, color: AppColors.success),
                                              tooltip: 'إنجاز',
                                              onPressed: () async {
                                                if (await confirmAction(context, 'إنجاز الطلب', 'تأكيد إنجاز طلب الوايت بقيمة ${r.amount}؟')) {
                                                  if (context.mounted) {
                                                    context.read<ServiceRequestsCubit>().markCompleted(r.id);
                                                  }
                                                }
                                              },
                                            ),
                                          if (r.canInvoice)
                                            IconButton(
                                              icon: const Icon(Icons.receipt_long_rounded, size: 20, color: AppColors.primary),
                                              tooltip: 'إصدار فاتورة وايت',
                                              onPressed: () => _openGenerateTankerInvoice(context, request: r),
                                            ),
                                          IconButton(
                                            icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                                            tooltip: 'حذف',
                                            onPressed: () async {
                                              if (await confirmAction(context, 'حذف طلب', 'حذف طلب الوايت هذا؟')) {
                                                if (context.mounted) {
                                                  context.read<ServiceRequestsCubit>().delete(r.id);
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

  Future<void> _pickRange(BuildContext context, ServiceRequestsState state) async {
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (range != null && context.mounted) {
      context.read<ServiceRequestsCubit>().filterRange(
            range.start.toIso8601String().substring(0, 10),
            range.end.toIso8601String().substring(0, 10),
          );
    }
  }
}
