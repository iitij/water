import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
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

/// SCR-20 — طلبات الوايت: Sum Summarizer + فلتر حالة/تاريخ + إضافة/تعديل (بتاريخ الخدمة والحالة) + حذف/حذف جماعي + إنجاز + فوترة.
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

  Future<void> _openRequestDialog(BuildContext context, {ServiceRequest? existing}) async {
    if (existing == null && onAddRequest != null) {
      onAddRequest!();
      return;
    }
    final cubit = context.read<ServiceRequestsCubit>();
    final repo = FouadModule.deps.subscribers;
    Subscriber? pickedSub;
    final amountCtrl = TextEditingController(
      text: existing == null ? '5000' : existing.amount.toStringAsFixed(0),
    );
    final notesCtrl = TextEditingController(text: existing?.notes ?? '');
    var selectedDate = DateTime.tryParse(existing?.serviceDate ?? '') ?? DateTime.now();
    var selectedStatus = existing?.status ?? TankerStatus.completed;

    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'طلب وايت جديد' : 'تعديل طلب الوايت'),
          content: SizedBox(
            width: 440,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (existing == null)
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
                    )
                  else
                    InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'المشترك',
                        border: OutlineInputBorder(),
                      ),
                      child: Text(existing.subscriberName ?? 'مشترك #${existing.subscriberId}'),
                    ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: amountCtrl,
                    keyboardType: TextInputType.number,
                    enabled: existing?.invoiceId == null,
                    decoration: const InputDecoration(
                      labelText: 'سعر الوايت (ريال) *',
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 10),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('تاريخ الخدمة *'),
                    subtitle: Text(selectedDate.toIso8601String().substring(0, 10)),
                    trailing: const Icon(Icons.calendar_today),
                    onTap: () async {
                      final picked = await showDatePicker(
                        context: ctx,
                        initialDate: selectedDate,
                        firstDate: DateTime(2020),
                        lastDate: DateTime(2100),
                      );
                      if (picked != null) setDialogState(() => selectedDate = picked);
                    },
                  ),
                  DropdownButtonFormField<TankerStatus>(
                    value: selectedStatus,
                    decoration: const InputDecoration(
                      labelText: 'الحالة',
                      border: OutlineInputBorder(),
                    ),
                    items: [
                      for (final s in TankerStatus.values)
                        DropdownMenuItem(value: s, child: Text(s.label)),
                    ],
                    onChanged: (v) => setDialogState(() => selectedStatus = v ?? selectedStatus),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: notesCtrl,
                    decoration: const InputDecoration(
                      labelText: 'ملاحظات',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: () async {
                final amount = double.tryParse(normalizeDigits(amountCtrl.text));
                if (amount == null || amount <= 0) return;
                final dateStr = selectedDate.toIso8601String().substring(0, 10);
                final notes = notesCtrl.text.trim().isEmpty ? null : notesCtrl.text.trim();
                if (existing == null) {
                  final sub = pickedSub;
                  if (sub == null) return;
                  await cubit.create(
                    subscriberId: sub.id,
                    amount: amount,
                    serviceDate: dateStr,
                    status: selectedStatus,
                    notes: notes,
                  );
                } else {
                  await cubit.update(ServiceRequest(
                    id: existing.id,
                    subscriberId: existing.subscriberId,
                    amount: amount,
                    serviceDate: dateStr,
                    status: selectedStatus,
                    invoiceId: existing.invoiceId,
                    notes: notes,
                  ));
                }
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: Text(existing == null ? 'إضافة الطلب' : 'حفظ التعديلات'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _handleBulkDelete(BuildContext context, int count) async {
    if (!await confirmAction(
      context,
      'حذف جماعي لطلبات الوايت',
      'هل تريد حذف $count طلب محدد؟ سيتم تجاوز الطلبات المرتبطة بفواتير.',
    )) {
      return;
    }
    if (!context.mounted) return;
    final deleted = await context.read<ServiceRequestsCubit>().bulkDelete();
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('تم حذف $deleted طلب بنجاح')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<ServiceRequestsCubit, ServiceRequestsState>(
      listenWhen: (prev, curr) => curr.error != null && prev.error != curr.error,
      listener: (context, state) {
        if (state.error != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(state.error!), backgroundColor: AppColors.danger),
          );
        }
      },
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('طلبات الوايت'),
            actions: [
              if (state.selectedIds.isNotEmpty) ...[
                IconButton(
                  icon: const Icon(Icons.clear),
                  tooltip: 'إلغاء التحديد',
                  onPressed: context.read<ServiceRequestsCubit>().clearSelection,
                ),
                IconButton(
                  icon: const Icon(Icons.delete_sweep),
                  tooltip: 'حذف المحدد (${state.selectedIds.length})',
                  onPressed: () => _handleBulkDelete(context, state.selectedIds.length),
                ),
              ],
              IconButton(
                icon: const Icon(Icons.receipt_long_outlined),
                tooltip: 'إصدار فاتورة وايت',
                onPressed: () => _openGenerateTankerInvoice(context),
              ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _openRequestDialog(context),
            icon: const Icon(Icons.add),
            label: const Text('طلب وايت جديد'),
          ),
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: SumBar(items: [
                (label: 'إجمالي مبالغ الطلبات المعروضة', value: state.totalSum, color: Colors.white),
              ]),
            ),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Row(children: [
                ChoiceChip(
                  label: const Text('الكل'),
                  selected: state.status == null,
                  onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(null),
                ),
                for (final s in TankerStatus.values)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 8),
                    child: ChoiceChip(
                      label: Text(s.label),
                      selected: state.status == s,
                      onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(s),
                    ),
                  ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  icon: const Icon(Icons.date_range),
                  label: Text(state.from == null ? 'نطاق التاريخ' : '${state.from} ← ${state.until}'),
                  onPressed: () => _pickRange(context, state),
                ),
                if (state.from != null)
                  IconButton(
                    icon: const Icon(Icons.clear),
                    onPressed: () => context.read<ServiceRequestsCubit>().filterRange(null, null),
                  ),
              ]),
            ),
            if (state.selectedIds.isNotEmpty)
              MaterialBanner(
                content: Text('تم تحديد ${state.selectedIds.length} طلب وايت'),
                leading: const Icon(Icons.checklist),
                actions: [
                  TextButton(
                    onPressed: context.read<ServiceRequestsCubit>().clearSelection,
                    child: const Text('إلغاء'),
                  ),
                  FilledButton.tonalIcon(
                    onPressed: () => _handleBulkDelete(context, state.selectedIds.length),
                    icon: const Icon(Icons.delete_sweep, color: AppColors.danger),
                    label: const Text('حذف المحدد'),
                  ),
                ],
              ),
            if (state.loading) const LinearProgressIndicator(),
            Expanded(
              child: state.requests.isEmpty && !state.loading
                  ? const EmptyState(message: 'لا توجد طلبات وايت مطابقة', icon: Icons.local_shipping)
                  : ListView.builder(
                      itemCount: state.requests.length,
                      itemBuilder: (_, i) {
                        final r = state.requests[i];
                        final checked = state.selectedIds.contains(r.id);
                        return Card(
                          margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          color: checked ? AppColors.primary.withValues(alpha: 0.08) : null,
                          child: ListTile(
                            onLongPress: () => context.read<ServiceRequestsCubit>().toggleSelect(r.id),
                            leading: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Checkbox(
                                  value: checked,
                                  onChanged: (_) => context.read<ServiceRequestsCubit>().toggleSelect(r.id),
                                  visualDensity: VisualDensity.compact,
                                ),
                                const Icon(Icons.local_shipping, color: AppColors.info),
                              ],
                            ),
                            title: Text('${r.subscriberName ?? 'مشترك #${r.subscriberId}'}${r.hasNote ? '  📝 يوجد ملاحظة' : ''}'),
                            subtitle: Text(
                              '${r.serviceDate} • ${CurrencyFormatter.format(r.amount)}'
                              '${r.invoiceNumber != null ? ' • فاتورة: ${r.invoiceNumber}' : ''}'
                              '${r.hasNote ? '\n${r.notes}' : ''}',
                            ),
                            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                              StatusBadge(
                                label: r.status.label,
                                color: switch (r.status) {
                                  TankerStatus.pending => AppColors.warning,
                                  TankerStatus.completed => AppColors.info,
                                  TankerStatus.invoiced => AppColors.primary,
                                  TankerStatus.paid => AppColors.success,
                                },
                              ),
                              if (r.canComplete)
                                IconButton(
                                  icon: const Icon(Icons.check_circle, color: AppColors.success),
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
                                  icon: const Icon(Icons.receipt_long, color: AppColors.primary),
                                  tooltip: 'إصدار فاتورة وايت',
                                  onPressed: () => _openGenerateTankerInvoice(context, request: r),
                                ),
                              IconButton(
                                icon: const Icon(Icons.edit_outlined),
                                tooltip: 'تعديل الطلب',
                                onPressed: () => _openRequestDialog(context, existing: r),
                              ),
                              IconButton(
                                icon: const Icon(Icons.delete_outline, color: AppColors.danger),
                                tooltip: 'حذف الطلب',
                                onPressed: () async {
                                  if (await confirmAction(context, 'حذف طلب', 'حذف طلب الوايت هذا؟')) {
                                    if (context.mounted) {
                                      context.read<ServiceRequestsCubit>().delete(r.id);
                                    }
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
