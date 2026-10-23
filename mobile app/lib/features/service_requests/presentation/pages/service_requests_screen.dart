import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/fouad/api_client.dart' show friendlyError;
import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../shared/widgets/subscriber_meter_selector.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../domain/entities/service_request.dart';
import '../cubit/service_requests_cubit.dart';
import '../widgets/service_request_edit_sheet.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';

bool _hasServiceRequestsCubit(BuildContext context) {
  try {
    context.read<ServiceRequestsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// SCR-20 — طلبات الخدمة والوايت: Sum Summarizer + فلتر حالة/تاريخ + إضافة/تعديل (بتاريخ الخدمة والحالة) + حذف/حذف جماعي + إنجاز + فوترة.
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

  Future<void> _openCreateRequestDialog(BuildContext context) async {
    if (onAddRequest != null) {
      onAddRequest!();
      return;
    }
    final cubit = context.read<ServiceRequestsCubit>();
    final repo = FouadModule.deps.subscribers;
    Subscriber? pickedSub;
    final amountCtrl = TextEditingController(text: '5000');
    final notesCtrl = TextEditingController();
    var selectedDate = DateTime.now();
    var selectedStatus = TankerStatus.completed;

    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('طلب وايت جديد'),
          content: SizedBox(
            width: 440,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
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
                    initialValue: selectedStatus,
                    decoration: const InputDecoration(
                      labelText: 'الحالة',
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
                final dateStr = selectedDate.toIso8601String().substring(0, 10);
                final notes = notesCtrl.text.trim().isEmpty ? null : notesCtrl.text.trim();
                await cubit.create(
                  subscriberId: sub.id,
                  amount: amount,
                  serviceDate: dateStr,
                  status: selectedStatus,
                  notes: notes,
                );
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: const Text('إضافة الطلب'),
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
    bool can(String permission) =>
        !GetIt.I.isRegistered<PermissionService>() ||
        GetIt.I<PermissionService>().can(permission);

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
            title: const Text('طلبات الخدمة والوايت'),
            actions: [
              if (state.selectedIds.isNotEmpty && can('Delete:ServiceRequest')) ...[
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
              if (can('invoices.create'))
                IconButton(
                  icon: const Icon(Icons.receipt_long_outlined),
                  tooltip: 'إصدار فاتورة وايت',
                  onPressed: () => _openGenerateTankerInvoice(context),
                ),
            ],
          ),
          floatingActionButton: can('Create:ServiceRequest')
              ? FloatingActionButton.extended(
                  onPressed: () => _openCreateRequestDialog(context),
                  icon: const Icon(Icons.add),
                  label: const Text('طلب وايت جديد'),
                )
              : null,
          body: Column(children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: SumBar(items: [
                (label: 'إجمالي مبالغ الطلبات المعروضة', value: state.totalSum, color: Colors.white),
              ]),
            ),
            AppFilterChips(
              children: <Widget>[
                AppFilterChip(
                  label: 'الكل',
                  selected: state.status == null,
                  onTap: () => context.read<ServiceRequestsCubit>().filterStatus(null),
                ),
                for (final status in TankerStatus.values)
                  AppFilterChip(
                    label: status.label,
                    selected: state.status == status,
                    onTap: () => context.read<ServiceRequestsCubit>().filterStatus(status),
                  ),
                AppFilterChip(
                  label: state.from == null ? 'نطاق التاريخ' : '${state.from} ← ${state.until}',
                  icon: Icons.date_range_rounded,
                  selected: state.from != null,
                  onTap: () => _pickRange(context, state),
                  onRemove: state.from == null
                      ? null
                      : () => context.read<ServiceRequestsCubit>().filterRange(null, null),
                ),
              ],
            ),
            if (state.selectedIds.isNotEmpty && can('Delete:ServiceRequest'))
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
                        return Padding(
                          padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
                          child: AppCard(
                          padding: EdgeInsets.zero,
                          color: checked ? Theme.of(context).colorScheme.primary.withValues(alpha: .04) : null,
                          borderColor: checked ? Theme.of(context).colorScheme.primary.withValues(alpha: .4) : null,
                          child: ListTile(
                            onLongPress: can('Delete:ServiceRequest')
                                ? () => context.read<ServiceRequestsCubit>().toggleSelect(r.id)
                                : null,
                            leading: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                if (can('Delete:ServiceRequest'))
                                  Checkbox(
                                    value: checked,
                                    onChanged: (_) =>
                                        context.read<ServiceRequestsCubit>().toggleSelect(r.id),
                                    visualDensity: VisualDensity.compact,
                                  ),
                                const Icon(Icons.local_shipping, color: AppColors.info),
                              ],
                            ),
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
                                            await ServiceRequestEditSheet.show(
                                              context,
                                              request: r,
                                              onSave: cubit.updateRequest,
                                            );
                                            break;
                                          case 'complete':
                                            if (await confirmAction(
                                                  context,
                                                  'إنجاز الطلب',
                                                  'تأكيد إنجاز طلب الوايت؟',
                                                ) &&
                                                context.mounted) {
                                              await cubit.markCompleted(r.id);
                                            }
                                            break;
                                          case 'invoice':
                                            await _openGenerateTankerInvoice(context, request: r);
                                            break;
                                          case 'delete':
                                            if (await confirmAction(
                                                  context,
                                                  'حذف طلب',
                                                  'حذف طلب الوايت هذا؟',
                                                ) &&
                                                context.mounted) {
                                              await cubit.delete(r.id);
                                            }
                                            break;
                                        }
                                      } catch (error) {
                                        if (context.mounted) {
                                          ScaffoldMessenger.of(context).showSnackBar(
                                            SnackBar(content: Text(friendlyError(error))),
                                          );
                                        }
                                      }
                                    },
                                    itemBuilder: (_) => [
                                      if (r.canEdit && can('Update:ServiceRequest'))
                                        const PopupMenuItem(value: 'edit', child: Text('تعديل الطلب')),
                                      if (r.canComplete && can('Update:ServiceRequest'))
                                        const PopupMenuItem(value: 'complete', child: Text('إنجاز الطلب')),
                                      if (r.canInvoice && can('Update:ServiceRequest'))
                                        const PopupMenuItem(value: 'invoice', child: Text('إصدار فاتورة')),
                                      if (r.canEdit && can('Delete:ServiceRequest'))
                                        const PopupMenuItem(value: 'delete', child: Text('حذف الطلب')),
                                    ],
                                  )
                                : null,
                          ),
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
