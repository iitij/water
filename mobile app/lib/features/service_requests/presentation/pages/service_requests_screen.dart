import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/api_client.dart' show friendlyError;
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/l10n/app_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../domain/entities/service_request.dart';
import '../cubit/service_requests_cubit.dart';
import '../widgets/service_request_create_dialog.dart';
import '../widgets/service_request_edit_sheet.dart';

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
  const ServiceRequestsScreen({
    super.key,
    this.onAddRequest,
    this.onGenerateInvoice,
  });
  final VoidCallback? onAddRequest;
  final Future<void> Function(BuildContext context, ServiceRequest? request)?
  onGenerateInvoice;

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
  final Future<void> Function(BuildContext context, ServiceRequest? request)?
  onGenerateInvoice;

  Future<void> _openGenerateTankerInvoice(
    BuildContext context, {
    ServiceRequest? request,
  }) async {
    if (onGenerateInvoice != null) {
      await onGenerateInvoice!(context, request);
      return;
    }
    final cubit = context.read<ServiceRequestsCubit>();
    final canUseFinanceSheet =
        GetIt.I.isRegistered<InvoicesRepository>() ||
        GetIt.I.isRegistered<DioClient>();
    if (canUseFinanceSheet) {
      await GenerateTankerInvoiceSheet.show(
        context,
        preselectedSubscriberId: request != null
            ? '${request.subscriberId}'
            : null,
      );
      if (context.mounted) {
        await cubit.load();
      }
      return;
    }
    if (request != null) {
      final bill = await cubit.invoice(request.id);
      if (context.mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('صدرت فاتورة الوايت: $bill')));
      }
    }
  }

  Future<void> _openCreateRequestDialog(BuildContext context) async {
    if (onAddRequest != null) {
      onAddRequest!();
      return;
    }
    final cubit = context.read<ServiceRequestsCubit>();
    await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => ServiceRequestCreateDialog(
        repository: FouadModule.deps.subscribers,
        onSave: (draft) => cubit.create(
          subscriberId: draft.subscriberId,
          amount: draft.amount,
          serviceDate: draft.serviceDate,
          status: draft.status,
          notes: draft.notes,
        ),
        errorMessage: () => cubit.state.error,
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
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('تم حذف $deleted طلب بنجاح')));
    }
  }

  @override
  Widget build(BuildContext context) {
    bool can(String permission) =>
        !GetIt.I.isRegistered<PermissionService>() ||
        GetIt.I<PermissionService>().can(permission);

    return BlocConsumer<ServiceRequestsCubit, ServiceRequestsState>(
      listenWhen: (prev, curr) =>
          curr.error != null && prev.error != curr.error,
      listener: (context, state) {
        if (state.error != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(state.error!),
              backgroundColor: AppColors.danger,
            ),
          );
        }
      },
      builder: (context, state) {
        final scheme = Theme.of(context).colorScheme;
        return SubPageScaffold(
          title: 'طلبات الخدمة والوايت',
          subtitle: 'طلبات صهاريج المياه وحالتها',
          actions: [
            if (state.selectedIds.isNotEmpty &&
                can('Delete:ServiceRequest')) ...[
              AppIconButton(
                icon: Icons.clear_rounded,
                tooltip: 'إلغاء التحديد',
                size: 38,
                onPressed: context.read<ServiceRequestsCubit>().clearSelection,
              ),
              AppIconButton(
                icon: Icons.delete_sweep_outlined,
                tooltip: 'حذف المحدد (${state.selectedIds.length})',
                size: 38,
                onPressed: () =>
                    _handleBulkDelete(context, state.selectedIds.length),
              ),
            ],
            if (can('invoices.create'))
              AppIconButton(
                icon: Icons.receipt_long_outlined,
                tooltip: 'إصدار فاتورة وايت',
                size: 38,
                onPressed: () => _openGenerateTankerInvoice(context),
              ),
            if (can('Create:ServiceRequest'))
              AppIconButton(
                icon: Icons.add_rounded,
                tooltip: 'طلب وايت جديد',
                size: 38,
                onPressed: () => _openCreateRequestDialog(context),
              ),
          ],
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 0),
                child: AppStatStrip(
                  items: [
                    AppStatItem(
                      label: 'عدد الطلبات المعروضة',
                      value: '${state.requests.length}',
                      icon: Icons.local_shipping_outlined,
                      color: scheme.primary,
                    ),
                    AppStatItem(
                      label: 'إجمالي مبالغ الطلبات المعروضة',
                      value: CurrencyFormatter.format(state.totalSum),
                      icon: Icons.payments_outlined,
                      color: AppColors.success,
                    ),
                  ],
                ),
              ),
              AppFilterChips(
                children: <Widget>[
                  AppFilterChip(
                    label: 'الكل',
                    selected: state.status == null,
                    onTap: () =>
                        context.read<ServiceRequestsCubit>().filterStatus(null),
                  ),
                  for (final status in TankerStatus.values)
                    AppFilterChip(
                      label: status.label,
                      selected: state.status == status,
                      onTap: () => context
                          .read<ServiceRequestsCubit>()
                          .filterStatus(status),
                    ),
                  AppFilterChip(
                    label: state.from == null
                        ? 'نطاق التاريخ'
                        : '${state.from} ← ${state.until}',
                    icon: Icons.date_range_rounded,
                    selected: state.from != null,
                    onTap: () => _pickRange(context, state),
                    onRemove: state.from == null
                        ? null
                        : () => context
                              .read<ServiceRequestsCubit>()
                              .filterRange(null, null),
                  ),
                ],
              ),
              if (state.selectedIds.isNotEmpty && can('Delete:ServiceRequest'))
                Padding(
                  padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 0),
                  child: Container(
                    padding: const EdgeInsetsDirectional.fromSTEB(12, 6, 12, 6),
                    decoration: BoxDecoration(
                      color: AppColors.accentSoft,
                      borderRadius: BorderRadius.circular(AppRadius.lg),
                      border: Border.all(color: AppColors.border),
                    ),
                    child: Row(
                      children: [
                        Icon(Icons.checklist, size: 18, color: scheme.primary),
                        const Gap(8),
                        Expanded(
                          child: Text(
                            'تم تحديد ${state.selectedIds.length} طلب وايت',
                            style: Theme.of(context).textTheme.labelMedium,
                          ),
                        ),
                        TextButton(
                          onPressed: context
                              .read<ServiceRequestsCubit>()
                              .clearSelection,
                          child: const Text('إلغاء'),
                        ),
                        TextButton(
                          onPressed: () => _handleBulkDelete(
                            context,
                            state.selectedIds.length,
                          ),
                          child: const Text('حذف المحدد'),
                        ),
                      ],
                    ),
                  ),
                ),
              if (state.loading) const LinearProgressIndicator(),
              Expanded(
                child: ListPresentation<ServiceRequest>(
                  items: state.requests,
                  initialSettings: const ListPresentationSettings(sortBy: 'id'),
                  sortValues: {
                    'id': (
                      label: ListOptionsStrings.id,
                      value: (request) => request.id,
                    ),
                    'subscriber': (
                      label: ListOptionsStrings.subscriber,
                      value: (request) => request.subscriberName ?? '',
                    ),
                    'amount': (
                      label: ListOptionsStrings.amount,
                      value: (request) => request.amount,
                    ),
                    'date': (
                      label: ListOptionsStrings.date,
                      value: (request) => request.serviceDate,
                    ),
                    'status': (
                      label: ListOptionsStrings.status,
                      value: (request) => request.status.index,
                    ),
                  },
                  fields: const {
                    'date': ListOptionsStrings.date,
                    'invoice': ListOptionsStrings.invoice,
                    'notes': ListOptionsStrings.notes,
                  },
                  builder: (context, requests, options) =>
                      requests.isEmpty && !state.loading
                      ? const Center(
                          child: AdminEmptyView(
                            title: 'لا توجد طلبات وايت مطابقة',
                            icon: Icons.local_shipping,
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.only(top: 10, bottom: 24),
                          itemCount: requests.length,
                          itemBuilder: (_, i) {
                            final r = requests[i];
                            final checked = state.selectedIds.contains(r.id);
                            return Padding(
                              padding: const EdgeInsetsDirectional.fromSTEB(
                                16,
                                0,
                                16,
                                8,
                              ),
                              child: AppCard(
                                padding: EdgeInsets.zero,
                                color: checked
                                    ? scheme.primary.withValues(alpha: .04)
                                    : null,
                                borderColor: checked
                                    ? scheme.primary.withValues(alpha: .4)
                                    : null,
                                child: InkWell(
                                  onLongPress: can('Delete:ServiceRequest')
                                      ? () => context
                                            .read<ServiceRequestsCubit>()
                                            .toggleSelect(r.id)
                                      : null,
                                  borderRadius: BorderRadius.circular(
                                    AppRadius.xl,
                                  ),
                                  child: Padding(
                                    padding: EdgeInsets.all(
                                      options.compact ? 8 : 12,
                                    ),
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          children: [
                                            if (can('Delete:ServiceRequest'))
                                              Checkbox(
                                                value: checked,
                                                onChanged: (_) => context
                                                    .read<
                                                      ServiceRequestsCubit
                                                    >()
                                                    .toggleSelect(r.id),
                                                visualDensity:
                                                    VisualDensity.compact,
                                              ),
                                            const AppGlyph(
                                              icon:
                                                  Icons.local_shipping_rounded,
                                              tone: AppColors.info,
                                              size: 38,
                                            ),
                                            const Gap(10),
                                            Expanded(
                                              child: Column(
                                                crossAxisAlignment:
                                                    CrossAxisAlignment.start,
                                                mainAxisSize: MainAxisSize.min,
                                                children: [
                                                  Text(
                                                    '${r.subscriberName ?? 'مشترك #${r.subscriberId}'}${r.hasNote ? '  📝 يوجد ملاحظة' : ''}',
                                                    maxLines: 1,
                                                    overflow:
                                                        TextOverflow.ellipsis,
                                                    style: const TextStyle(
                                                      fontWeight:
                                                          FontWeight.w600,
                                                      fontSize: 14.5,
                                                      height: 1.3,
                                                    ),
                                                  ),
                                                  const Gap(2),
                                                  Text(
                                                    '${options.isVisible('date') ? r.serviceDate : ''} • ${CurrencyFormatter.format(r.amount)}'
                                                    '${options.isVisible('invoice') && r.invoiceNumber != null ? ' • فاتورة: ${r.invoiceNumber}' : ''}',
                                                    maxLines: 1,
                                                    overflow:
                                                        TextOverflow.ellipsis,
                                                    style: Theme.of(
                                                      context,
                                                    ).textTheme.labelSmall,
                                                  ),
                                                ],
                                              ),
                                            ),
                                            StatusBadge(
                                              label: r.status.label,
                                              color: switch (r.status) {
                                                TankerStatus.pending =>
                                                  AppColors.warning,
                                                TankerStatus.completed =>
                                                  AppColors.info,
                                                TankerStatus.invoiced =>
                                                  AppColors.primary,
                                                TankerStatus.paid =>
                                                  AppColors.success,
                                              },
                                            ),
                                            if (r.canEdit &&
                                                (can('Update:ServiceRequest') ||
                                                    can(
                                                      'Delete:ServiceRequest',
                                                    )))
                                              PopupMenuButton<String>(
                                                tooltip: 'إجراءات الطلب',
                                                shape: RoundedRectangleBorder(
                                                  borderRadius:
                                                      BorderRadius.circular(
                                                        AppRadius.lg,
                                                      ),
                                                ),
                                                onSelected: (action) async {
                                                  final cubit = context
                                                      .read<
                                                        ServiceRequestsCubit
                                                      >();
                                                  try {
                                                    switch (action) {
                                                      case 'edit':
                                                        await ServiceRequestEditSheet.show(
                                                          context,
                                                          request: r,
                                                          onSave: cubit
                                                              .updateRequest,
                                                        );
                                                        break;
                                                      case 'complete':
                                                        if (await confirmAction(
                                                              context,
                                                              'إنجاز الطلب',
                                                              'تأكيد إنجاز طلب الوايت؟',
                                                            ) &&
                                                            context.mounted) {
                                                          await cubit
                                                              .markCompleted(
                                                                r.id,
                                                              );
                                                        }
                                                        break;
                                                      case 'invoice':
                                                        await _openGenerateTankerInvoice(
                                                          context,
                                                          request: r,
                                                        );
                                                        break;
                                                      case 'delete':
                                                        if (await confirmAction(
                                                              context,
                                                              'حذف طلب',
                                                              'حذف طلب الوايت هذا؟',
                                                            ) &&
                                                            context.mounted) {
                                                          await cubit.delete(
                                                            r.id,
                                                          );
                                                        }
                                                        break;
                                                    }
                                                  } catch (error) {
                                                    if (context.mounted) {
                                                      ScaffoldMessenger.of(
                                                        context,
                                                      ).showSnackBar(
                                                        SnackBar(
                                                          content: Text(
                                                            friendlyError(
                                                              error,
                                                            ),
                                                          ),
                                                        ),
                                                      );
                                                    }
                                                  }
                                                },
                                                itemBuilder: (_) => [
                                                  if (r.canEdit &&
                                                      can(
                                                        'Update:ServiceRequest',
                                                      ))
                                                    const PopupMenuItem(
                                                      value: 'edit',
                                                      child: Text(
                                                        'تعديل الطلب',
                                                      ),
                                                    ),
                                                  if (r.canComplete &&
                                                      can(
                                                        'Update:ServiceRequest',
                                                      ))
                                                    const PopupMenuItem(
                                                      value: 'complete',
                                                      child: Text(
                                                        'إنجاز الطلب',
                                                      ),
                                                    ),
                                                  if (r.canInvoice &&
                                                      can(
                                                        'Update:ServiceRequest',
                                                      ))
                                                    const PopupMenuItem(
                                                      value: 'invoice',
                                                      child: Text(
                                                        'إصدار فاتورة',
                                                      ),
                                                    ),
                                                  if (r.canDelete &&
                                                      can(
                                                        'Delete:ServiceRequest',
                                                      ))
                                                    const PopupMenuItem(
                                                      value: 'delete',
                                                      child: Text('حذف الطلب'),
                                                    ),
                                                ],
                                              ),
                                          ],
                                        ),
                                        if (options.isVisible('invoice') &&
                                            r.invoiceId != null &&
                                            can('View:Invoice'))
                                          TextButton.icon(
                                            onPressed: () => context.push(
                                              '${RouteNames.invoices}/${r.invoiceId}',
                                            ),
                                            icon: const Icon(
                                              Icons.receipt_long_outlined,
                                            ),
                                            label: Text(
                                              AppStrings.of(
                                                context,
                                              ).openLinkedInvoice,
                                            ),
                                          ),
                                        if (options.isVisible('notes') &&
                                            r.hasNote &&
                                            r.notes != null &&
                                            r.notes!.isNotEmpty) ...[
                                          const Gap(8),
                                          Text(
                                            r.notes!,
                                            maxLines: 2,
                                            overflow: TextOverflow.ellipsis,
                                            style: Theme.of(
                                              context,
                                            ).textTheme.bodySmall,
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _pickRange(
    BuildContext context,
    ServiceRequestsState state,
  ) async {
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
