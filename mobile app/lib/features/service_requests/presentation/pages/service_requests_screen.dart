import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/fouad/api_client.dart' show friendlyError;
import '../../../../shared/widgets/subscriber_meter_selector.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/service_request.dart';
import '../cubit/service_requests_cubit.dart';
import '../widgets/service_request_edit_sheet.dart';

bool _hasServiceRequestsCubit(BuildContext context) {
  try {
    context.read<ServiceRequestsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// SCR-20 — طلبات الوايت: Sum Summarizer + فلتر حالة/تاريخ (created_from..until)
/// + شارة 📝 + زر إنجاز (mark_completed) + إصدار فاتورة وايت.
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
        title: const Text('طلب وايت جديد'),
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
                TextField(
                  controller: amountCtrl,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'سعر الوايت (ريال) *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 8),
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
    bool can(String permission) => GetIt.I.isRegistered<PermissionService>() &&
        GetIt.I<PermissionService>().can(permission);
    return BlocBuilder<ServiceRequestsCubit, ServiceRequestsState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('طلبات الخدمة والوايت'),
            actions: [
              if (can('invoices.create')) IconButton(
                icon: const Icon(Icons.receipt_long_outlined),
                tooltip: 'إصدار فاتورة وايت',
                onPressed: () => _openGenerateTankerInvoice(context),
              ),
            ],
          ),
          floatingActionButton: can('Create:ServiceRequest') ? FloatingActionButton.extended(
            onPressed: () => _defaultAddRequest(context),
            icon: const Icon(Icons.add),
            label: const Text('طلب وايت جديد'),
          ) : null,
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
                ChoiceChip(label: const Text('الكل'), selected: state.status == null, onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(null)),
                for (final s in TankerStatus.values)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 8),
                    child: ChoiceChip(label: Text(s.label), selected: state.status == s, onSelected: (_) => context.read<ServiceRequestsCubit>().filterStatus(s)),
                  ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  icon: const Icon(Icons.date_range),
                  label: Text(state.from == null ? 'نطاق التاريخ' : '${state.from} ← ${state.until}'),
                  onPressed: () => _pickRange(context, state),
                ),
                if (state.from != null)
                  IconButton(icon: const Icon(Icons.clear), onPressed: () => context.read<ServiceRequestsCubit>().filterRange(null, null)),
              ]),
            ),
            if (state.loading) const LinearProgressIndicator(),
            Expanded(
              child: state.requests.isEmpty && !state.loading
                  ? const EmptyState(message: 'لا توجد طلبات وايت مطابقة', icon: Icons.local_shipping)
                  : ListView.builder(
                      itemCount: state.requests.length,
                      itemBuilder: (_, i) {
                        final r = state.requests[i];
                        return Card(
                          margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          child: ListTile(
                            leading: const Icon(Icons.local_shipping, color: AppColors.info),
                            title: Text('${r.subscriberName ?? 'مشترك #${r.subscriberId}'}${r.hasNote ? '  📝 يوجد ملاحظة' : ''}'),
                            subtitle: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('${r.serviceDate} • ${CurrencyFormatter.format(r.amount)}'),
                                if (r.invoiceNumber != null) Text('فاتورة: ${r.invoiceNumber}'),
                                if (r.hasNote) Text(r.notes!),
                                const SizedBox(height: 6),
                                StatusBadge(
                                  label: r.status.label,
                                  color: switch (r.status) {
                                    TankerStatus.pending => AppColors.warning,
                                    TankerStatus.completed => AppColors.info,
                                    TankerStatus.invoiced => AppColors.primary,
                                    TankerStatus.paid => AppColors.success,
                                  },
                                ),
                              ],
                            ),
                            trailing: r.canEdit && (can('Update:ServiceRequest') || can('Delete:ServiceRequest'))
                                ? PopupMenuButton<String>(
                              tooltip: 'إجراءات الطلب',
                              onSelected: (action) async {
                                final cubit = context.read<ServiceRequestsCubit>();
                                try {
                                  switch (action) {
                                    case 'edit':
                                      await ServiceRequestEditSheet.show(context, request: r, onSave: cubit.updateRequest);
                                      break;
                                    case 'complete':
                                      if (await confirmAction(context, 'إنجاز الطلب', 'تأكيد إنجاز طلب الوايت؟') && context.mounted) {
                                        await cubit.markCompleted(r.id);
                                      }
                                      break;
                                    case 'invoice':
                                      await _openGenerateTankerInvoice(context, request: r);
                                      break;
                                    case 'delete':
                                      if (await confirmAction(context, 'حذف طلب', 'حذف طلب الوايت هذا؟') && context.mounted) {
                                        await cubit.delete(r.id);
                                      }
                                      break;
                                  }
                                } catch (error) {
                                  if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(content: Text(friendlyError(error))),
                                  );
                                }
                              },
                              itemBuilder: (_) => [
                                if (r.canEdit && can('Update:ServiceRequest')) const PopupMenuItem(value: 'edit', child: Text('تعديل الطلب')),
                                if (r.canComplete && can('Update:ServiceRequest')) const PopupMenuItem(value: 'complete', child: Text('إنجاز الطلب')),
                                if (r.canInvoice && can('Update:ServiceRequest')) const PopupMenuItem(value: 'invoice', child: Text('إصدار فاتورة')),
                                if (r.canEdit && can('Delete:ServiceRequest')) const PopupMenuItem(value: 'delete', child: Text('حذف الطلب')),
                              ],
                            ) : null,
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
