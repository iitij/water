import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

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
import '../../../../shared/widgets/subscriber_meter_selector.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
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

/// SCR-19 — رسوم الاشتراك: Sum Summarizer + فلتر حالة + إضافة/تعديل/حذف/حذف جماعي + إصدار فاتورة.
class SubscriptionFeesScreen extends StatelessWidget {
  const SubscriptionFeesScreen({super.key, this.onAddFee, this.onGenerateInvoice});

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

  Future<void> _openFeeDialog(BuildContext context, {SubscriptionFee? existing}) async {
    if (existing == null && onAddFee != null) {
      onAddFee!();
      return;
    }
    final cubit = context.read<SubscriptionFeesCubit>();
    final repo = FouadModule.deps.subscribers;
    Subscriber? pickedSub;
    int? pickedMeterId = existing?.meterId;
    var metersForExisting = <MeterBalance>[];
    if (existing != null) {
      try {
        final detail = await repo.getSubscriber360(existing.subscriberId);
        pickedSub = detail.subscriber;
        metersForExisting = detail.summary.meterBalances;
      } catch (_) {}
    }
    if (!context.mounted) return;

    final amountCtrl = TextEditingController(
      text: existing == null ? '' : existing.amount.toStringAsFixed(0),
    );
    final notesCtrl = TextEditingController(text: existing?.notes ?? '');
    var selectedStatus = existing?.status ?? FeeStatus.uninvoiced;
    var changingSubscriber = false;
    var saving = false;
    String? saveError;

    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'إضافة رسوم اشتراك' : 'تعديل رسوم الاشتراك'),
          content: SizedBox(
            width: 440,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (existing == null)
                    SizedBox(
                      height: 220,
                      child: SubscriberMeterSelectorWidget(
                        repository: repo,
                        onSelected: (s, m) {
                          pickedSub = s;
                          pickedMeterId = m?.meterId;
                        },
                      ),
                    )
                  else ...[
                    InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'المشترك',
                      ),
                      child: Text(pickedSub?.name ?? existing.subscriberName ?? 'مشترك #${existing.subscriberId}'),
                    ),
                    if (existing.invoiceId == null)
                      TextButton.icon(
                        icon: const Icon(Icons.person_search_outlined),
                        label: Text(AppStrings.of(context).feeChangeSubscriber),
                        onPressed: saving ? null : () => setDialogState(() => changingSubscriber = !changingSubscriber),
                      ),
                    if (changingSubscriber) SizedBox(
                      height: 220,
                      child: SubscriberMeterSelectorWidget(repository: repo, showMeterPicker: false,
                        onSelected: (subscriber, _) async {
                          setDialogState(() {
                            pickedSub = subscriber; pickedMeterId = null;
                            metersForExisting = []; changingSubscriber = false;
                          });
                          try {
                            final detail = await repo.getSubscriber360(subscriber.id);
                            if (ctx.mounted && pickedSub?.id == subscriber.id) {
                              setDialogState(() => metersForExisting = detail.summary.meterBalances);
                            }
                          } catch (error) {
                            if (ctx.mounted) setDialogState(() => saveError = '$error');
                          }
                        }),
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<int?>(
                      key: ValueKey('fee-meter-${pickedSub?.id}-$pickedMeterId'),
                      initialValue: metersForExisting.any((m) => m.meterId == pickedMeterId) ? pickedMeterId : null,
                      decoration: const InputDecoration(
                        labelText: 'العداد',
                      ),
                      items: [
                        const DropdownMenuItem<int?>(value: null, child: Text('بدون عداد محدد')),
                        for (final m in metersForExisting)
                          DropdownMenuItem<int?>(value: m.meterId, child: Text(m.title)),
                      ],
                      onChanged: saving ? null : (v) => setDialogState(() => pickedMeterId = v),
                    ),
                  ],
                  const SizedBox(height: 12),
                  TextField(
                    controller: amountCtrl,
                    keyboardType: TextInputType.number,
                    enabled: existing?.invoiceId == null,
                    decoration: const InputDecoration(
                      labelText: 'مبلغ رسوم الاشتراك (ريال) *',
                    ),
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<FeeStatus>(
                    initialValue: selectedStatus,
                    decoration: const InputDecoration(
                      labelText: 'الحالة',
                    ),
                    items: [
                      for (final s in FeeStatus.values)
                        DropdownMenuItem(value: s, child: Text(s.label)),
                    ],
                    onChanged: (v) => setDialogState(() => selectedStatus = v ?? selectedStatus),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: notesCtrl,
                    decoration: const InputDecoration(
                      labelText: 'ملاحظات',
                    ),
                  ),
                  if (saveError != null) Text(saveError!, style: TextStyle(color: Theme.of(ctx).colorScheme.error)),
                  if (saving) const LinearProgressIndicator(),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: saving ? null : () => Navigator.pop(ctx),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: saving ? null : () async {
                final amount = double.tryParse(normalizeDigits(amountCtrl.text));
                if (amount == null || !amount.isFinite || amount <= 0) {
                  setDialogState(() => saveError = AppStrings.of(ctx).setupAmountInvalid);
                  return;
                }
                final notes = notesCtrl.text.trim().isEmpty ? null : notesCtrl.text.trim();
                if (existing == null && pickedSub == null) return;
                setDialogState(() { saving = true; saveError = null; });
                bool success;
                if (existing == null) {
                  final sub = pickedSub;
                  if (sub == null) return;
                  success = await cubit.create(
                    subscriberId: sub.id,
                    meterId: pickedMeterId,
                    amount: amount,
                    status: selectedStatus,
                    notes: notes,
                  );
                } else {
                  success = await cubit.update(SubscriptionFee(
                    id: existing.id,
                    subscriberId: pickedSub?.id ?? existing.subscriberId,
                    meterId: pickedMeterId,
                    amount: amount,
                    status: selectedStatus,
                    invoiceId: existing.invoiceId,
                    notes: notes,
                  ));
                }
                if (!ctx.mounted) return;
                if (success) { Navigator.pop(ctx); } else {
                  setDialogState(() { saving = false; saveError = cubit.state.error; });
                }
              },
              child: const Text('حفظ'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _handleBulkDelete(BuildContext context, int count) async {
    if (!await confirmAction(
      context,
      'حذف جماعي لرسوم الاشتراك',
      'هل تريد حذف $count سجل رسوم محدد؟ سيتم تجاوز الرسوم المرتبطة بفواتير.',
    )) {
      return;
    }
    if (!context.mounted) return;
    final deleted = await context.read<SubscriptionFeesCubit>().bulkDelete();
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('تم حذف $deleted سجل رسوم بنجاح')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<SubscriptionFeesCubit, SubscriptionFeesState>(
      listenWhen: (prev, curr) => curr.error != null && prev.error != curr.error,
      listener: (context, state) {
        if (state.error != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(state.error!), backgroundColor: AppColors.danger),
          );
        }
      },
      builder: (context, state) {
        final scheme = Theme.of(context).colorScheme;
        return SubPageScaffold(
          title: 'رسوم الاشتراك',
          subtitle: 'رسوم الربط السكني ومفوتراتها',
          actions: [
            if (state.selectedIds.isNotEmpty) ...[
              AppIconButton(
                icon: Icons.clear_rounded,
                tooltip: 'إلغاء التحديد',
                size: 38,
                onPressed: context.read<SubscriptionFeesCubit>().clearSelection,
              ),
              AppIconButton(
                icon: Icons.delete_sweep_outlined,
                tooltip: 'حذف المحدد (${state.selectedIds.length})',
                size: 38,
                onPressed: () => _handleBulkDelete(context, state.selectedIds.length),
              ),
            ],
            AppIconButton(
              icon: Icons.receipt_long_outlined,
              tooltip: 'إصدار فاتورة اشتراك',
              size: 38,
              onPressed: () => _openGenerateInvoiceSheet(context),
            ),
            AppIconButton(
              icon: Icons.add_rounded,
              tooltip: 'إضافة رسوم',
              size: 38,
              onPressed: () => _openFeeDialog(context),
            ),
          ],
          child: Column(children: [
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 0),
              child: AppStatStrip(
                items: [
                  AppStatItem(
                    label: 'إجمالي الرسوم',
                    value: CurrencyFormatter.format(state.totalSum),
                    icon: Icons.payments_outlined,
                    color: scheme.primary,
                  ),
                  AppStatItem(
                    label: 'المفوتر',
                    value: CurrencyFormatter.format(state.invoicedSum),
                    icon: Icons.receipt_long_rounded,
                    color: AppColors.success,
                  ),
                  AppStatItem(
                    label: 'المتبقي غير المفوتر',
                    value: CurrencyFormatter.format(state.remainingSum),
                    icon: Icons.hourglass_bottom_rounded,
                    color: AppColors.warning,
                  ),
                ],
              ),
            ),
            AppFilterChips(
              children: <Widget>[
                AppFilterChip(
                  label: 'الكل',
                  selected: state.status == null,
                  onTap: () => context.read<SubscriptionFeesCubit>().filterStatus(null),
                ),
                for (final status in FeeStatus.values)
                  AppFilterChip(
                    label: status.label,
                    selected: state.status == status,
                    onTap: () => context.read<SubscriptionFeesCubit>().filterStatus(status),
                  ),
              ],
            ),
            if (state.selectedIds.isNotEmpty)
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
                          'تم تحديد ${state.selectedIds.length} رسوم اشتراك',
                          style: Theme.of(context).textTheme.labelMedium,
                        ),
                      ),
                      TextButton(
                        onPressed: context.read<SubscriptionFeesCubit>().clearSelection,
                        child: const Text('إلغاء'),
                      ),
                      TextButton(
                        onPressed: () => _handleBulkDelete(context, state.selectedIds.length),
                        child: const Text('حذف المحدد'),
                      ),
                    ],
                  ),
                ),
              ),
            if (state.loading) const LinearProgressIndicator(),
            Expanded(
              child: ListPresentation<SubscriptionFee>(
                items: state.fees,
                initialSettings: const ListPresentationSettings(sortBy: 'id'),
                sortValues: {
                  'id': (label: ListOptionsStrings.id, value: (fee) => fee.id),
                  'subscriber': (label: ListOptionsStrings.subscriber, value: (fee) => fee.subscriberName ?? ''),
                  'amount': (label: ListOptionsStrings.amount, value: (fee) => fee.amount),
                  'status': (label: ListOptionsStrings.status, value: (fee) => fee.status.index),
                  'date': (label: ListOptionsStrings.date, value: (fee) => fee.createdAt ?? ''),
                },
                fields: const {'meter': ListOptionsStrings.meter, 'date': ListOptionsStrings.date,
                  'invoice': ListOptionsStrings.invoice, 'notes': ListOptionsStrings.notes},
                builder: (context, fees, options) => fees.isEmpty && !state.loading
                  ? const Center(child: AdminEmptyView(title: 'لا توجد رسوم اشتراك مطابقة'))
                  : ListView.builder(
                      padding: const EdgeInsets.only(top: 10, bottom: 24),
                      itemCount: fees.length,
                      itemBuilder: (_, i) {
                        final f = fees[i];
                        final checked = state.selectedIds.contains(f.id);
                        return Padding(
                          padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
                          child: AppCard(
                            padding: EdgeInsets.zero,
                            color: checked ? scheme.primary.withValues(alpha: .04) : null,
                            borderColor: checked ? scheme.primary.withValues(alpha: .4) : null,
                            child: InkWell(
                              onLongPress: () => context.read<SubscriptionFeesCubit>().toggleSelect(f.id),
                              borderRadius: BorderRadius.circular(AppRadius.xl),
                              child: Padding(
                                padding: EdgeInsets.all(options.compact ? 8 : 12),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Checkbox(
                                          value: checked,
                                          onChanged: (_) => context.read<SubscriptionFeesCubit>().toggleSelect(f.id),
                                          visualDensity: VisualDensity.compact,
                                        ),
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              Text(
                                                f.subscriberName ?? 'مشترك #${f.subscriberId}',
                                                maxLines: 1,
                                                overflow: TextOverflow.ellipsis,
                                                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14.5, height: 1.3),
                                              ),
                                              const Gap(2),
                                              Text(
                                                '${options.isVisible('meter') ? f.meterCode ?? 'بدون عداد' : ''}'
                                                '${options.isVisible('date') ? ' • ${f.createdAt ?? ''}' : ''}'
                                                '${options.isVisible('invoice') && f.invoiceNumber != null ? ' • فاتورة: ${f.invoiceNumber}' : ''}',
                                                maxLines: 1,
                                                overflow: TextOverflow.ellipsis,
                                                style: Theme.of(context).textTheme.labelSmall,
                                              ),
                                            ],
                                          ),
                                        ),
                                        StatusBadge(
                                          label: f.status.label,
                                          color: switch (f.status) {
                                            FeeStatus.uninvoiced => AppColors.danger,
                                            FeeStatus.invoiced => AppColors.warning,
                                            FeeStatus.partiallyPaid => AppColors.info,
                                            FeeStatus.paid => AppColors.success,
                                          },
                                        ),
                                        PopupMenuButton<String>(
                                          tooltip: 'إجراءات الرسوم',
                                          shape: RoundedRectangleBorder(
                                            borderRadius: BorderRadius.circular(AppRadius.lg),
                                          ),
                                          onSelected: (value) {
                                            if (value == 'invoice') {
                                              _openGenerateInvoiceSheet(context, fee: f);
                                            }
                                            if (value == 'edit') _openFeeDialog(context, existing: f);
                                            if (value == 'delete') asyncDeleteFee(context, f);
                                          },
                                          itemBuilder: (context) => [
                                            if (f.canInvoice)
                                              const PopupMenuItem(
                                                value: 'invoice',
                                                child: Row(children: [
                                                  Icon(Icons.receipt_long, size: 18),
                                                  SizedBox(width: 10),
                                                  Text('إصدار فاتورة اشتراك'),
                                                ]),
                                              ),
                                            const PopupMenuItem(
                                              value: 'edit',
                                              child: Row(children: [
                                                Icon(Icons.edit_outlined, size: 18),
                                                SizedBox(width: 10),
                                                Text('تعديل الرسوم'),
                                              ]),
                                            ),
                                            const PopupMenuItem(
                                              value: 'delete',
                                              child: Row(children: [
                                                Icon(Icons.delete_outline, size: 18, color: AppColors.danger),
                                                SizedBox(width: 10),
                                                Text('حذف الرسوم', style: TextStyle(color: AppColors.danger)),
                                              ]),
                                            ),
                                          ],
                                        ),
                                      ],
                                    ),
                                    if (options.isVisible('invoice') && f.invoiceId != null && GetIt.I.isRegistered<PermissionService>() &&
                                        GetIt.I<PermissionService>().can('View:Invoice'))
                                      TextButton.icon(
                                        onPressed: () => context.push('${RouteNames.invoices}/${f.invoiceId}'),
                                        icon: const Icon(Icons.receipt_long_outlined),
                                        label: Text(AppStrings.of(context).openLinkedInvoice),
                                      ),
                                    if (options.isVisible('notes') && f.notes != null && f.notes!.isNotEmpty) ...[
                                      const Gap(6),
                                      Text(
                                        f.notes!,
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        style: Theme.of(context).textTheme.bodySmall,
                                      ),
                                    ],
                                    const Gap(10),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                      decoration: BoxDecoration(
                                        color: scheme.surfaceContainerHighest.withValues(alpha: .45),
                                        borderRadius: BorderRadius.circular(AppRadius.lg),
                                      ),
                                      child: Row(
                                        children: [
                                          Text('مبلغ الرسم', style: Theme.of(context).textTheme.labelSmall),
                                          const Gap(8),
                                          Directionality(
                                            textDirection: TextDirection.ltr,
                                            child: Text(
                                              CurrencyFormatter.format(f.amount),
                                              style: const TextStyle(
                                                fontWeight: FontWeight.w600,
                                                fontSize: 15,
                                                fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
                                              ),
                                            ),
                                          ),
                                          const Spacer(),
                                          if (f.canInvoice)
                                            AppIconButton(
                                              icon: Icons.receipt_long_rounded,
                                              tooltip: 'إصدار فاتورة اشتراك',
                                              size: 38,
                                              onPressed: () => _openGenerateInvoiceSheet(context, fee: f),
                                            ),
                                        ],
                                      ),
                                    ),
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
          ]),
        );
      },
    );
  }

  /// حذف رسوم بعد تأكيد — نفس منطق الزر الأحمر السابق دون تغيير سلوك الـ cubit.
  Future<void> asyncDeleteFee(BuildContext context, SubscriptionFee f) async {
    if (await confirmAction(context, 'حذف رسوم', 'حذف رسوم الاشتراك بقيمة ${f.amount}؟')) {
      if (context.mounted) {
        await context.read<SubscriptionFeesCubit>().delete(f.id);
      }
    }
  }
}
