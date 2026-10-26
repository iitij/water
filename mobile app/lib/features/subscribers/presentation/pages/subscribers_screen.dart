import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/l10n/subscriber_experience_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/widgets/barcode_scanner_screen.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../payments/domain/repositories/payments_repository.dart';
import '../../../payments/presentation/sheets/collect_payment_sheet.dart';
import '../../../service_requests/presentation/cubit/service_requests_cubit.dart';
import '../../../subscription_fees/presentation/cubit/subscription_fees_cubit.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';
import '../cubit/subscriber360_cubit.dart';
import '../cubit/subscribers_cubit.dart';
import 'subscriber_360_screen.dart';
import 'subscriber_form_screen.dart';

part '../widgets/subscriber_cards.dart';
part '../widgets/subscribers_filter_sheet.dart';

/// منافذ التكامل مع أكرم (Dev 3): تحصيل سريع + بطاقات فواتير/مدفوعات.
/// الافتراضي عند الدمج يفتح `CollectPaymentSheet.show(context, ...)` الخاص بـ Dev 3.
typedef CollectPaymentLauncher =
    Future<void> Function(BuildContext context, Subscriber subscriber);

bool _hasSubscribersCubits(BuildContext context) {
  try {
    context.read<SubscribersCubit>();
    context.read<ServiceRequestsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// يفتح نافذة التحصيل المشتركة لـ Dev 3 عند توفرها، أو نافذة التحصيل المباشرة الاحتياطية.
Future<bool> launchDefaultSubscriberPayment(
  BuildContext context,
  Subscriber subscriber, {
  Future<String> Function(double amount)? fallbackCollect,
}) async {
  final canUseFinanceSheet =
      GetIt.I.isRegistered<PaymentsRepository>() ||
      GetIt.I.isRegistered<DioClient>();
  if (canUseFinanceSheet) {
    return CollectPaymentSheet.show(
      context,
      subscriber: SubscriberOption(
        id: subscriber.id,
        name: subscriber.name,
        phone: subscriber.phone,
        squareId: subscriber.squareId,
        squareName: subscriber.squareName,
        status: subscriber.status.apiValue,
        balance: subscriber.generalBalance,
      ),
    );
  }

  final amountCtrl = TextEditingController();
  var collected = false;
  await showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text('تحصيل دفعة — ${subscriber.name}'),
      content: TextField(
        controller: amountCtrl,
        keyboardType: TextInputType.number,
        decoration: const InputDecoration(labelText: 'المبلغ (ريال)'),
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
            final String receipt;
            if (fallbackCollect != null) {
              receipt = await fallbackCollect(amount);
            } else {
              final res = await FouadModule.deps.subscribers.collectPayment(
                subscriber.id,
                amount: amount,
              );
              receipt =
                  (res['payment'] as Map?)?['receipt_number']?.toString() ?? '';
            }
            collected = true;
            if (ctx.mounted) {
              Navigator.pop(ctx);
              ScaffoldMessenger.of(ctx).showSnackBar(
                SnackBar(content: Text('تم التحصيل بنجاح — سند: $receipt')),
              );
            }
          },
          child: const Text('تحصيل'),
        ),
      ],
    ),
  );
  return collected;
}

/// SCR-05 — قائمة المشتركين: بحث + فلترة مربع/حالة + رصيد ملون + تعديل/حذف/حذف جماعي + أزرار سريعة.
class SubscribersScreen extends StatelessWidget {
  const SubscribersScreen({super.key, this.onOpen360, this.onCollectPayment});

  final void Function(Subscriber subscriber)? onOpen360;
  final CollectPaymentLauncher? onCollectPayment;

  @override
  Widget build(BuildContext context) {
    if (!_hasSubscribersCubits(context)) {
      final deps = FouadModule.deps;
      return RepositoryProvider<SubscribersRepository>.value(
        value: deps.subscribers,
        child: MultiBlocProvider(
          providers: [
            BlocProvider<SubscribersCubit>(
              create: (_) => SubscribersCubit(deps.subscribers)..init(),
            ),
            BlocProvider<ServiceRequestsCubit>(
              create: (_) => ServiceRequestsCubit(deps.requests),
            ),
          ],
          child: _SubscribersView(
            onOpen360: onOpen360,
            onCollectPayment: onCollectPayment,
          ),
        ),
      );
    }
    return _SubscribersView(
      onOpen360: onOpen360,
      onCollectPayment: onCollectPayment,
    );
  }
}

class _SubscribersView extends StatelessWidget {
  const _SubscribersView({this.onOpen360, this.onCollectPayment});

  final void Function(Subscriber subscriber)? onOpen360;
  final CollectPaymentLauncher? onCollectPayment;

  Future<void> _handleOpen360(
    BuildContext context,
    Subscriber subscriber,
  ) async {
    if (onOpen360 != null) {
      onOpen360!(subscriber);
      return;
    }
    final cubit = context.read<SubscribersCubit>();
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        await context.push('/subscribers/${subscriber.id}', extra: subscriber);
        if (context.mounted) await cubit.load();
        return;
      } catch (_) {}
    }
    if (!context.mounted) return;
    final deps = FouadModule.deps;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => RepositoryProvider<SubscribersRepository>.value(
          value: deps.subscribers,
          child: MultiBlocProvider(
            providers: [
              BlocProvider<Subscriber360Cubit>(
                create: (_) =>
                    Subscriber360Cubit(deps.subscribers)..load(subscriber.id),
              ),
              BlocProvider<SubscriptionFeesCubit>(
                create: (_) => SubscriptionFeesCubit(deps.fees)
                  ..subscriberId = subscriber.id
                  ..load(),
              ),
              BlocProvider<ServiceRequestsCubit>(
                create: (_) => ServiceRequestsCubit(deps.requests)
                  ..subscriberId = subscriber.id
                  ..load(),
              ),
            ],
            child: Subscriber360Screen(
              subscriber: subscriber,
              onCollectPayment: onCollectPayment,
            ),
          ),
        ),
      ),
    );
    if (context.mounted) await cubit.load();
  }

  Future<void> _handleOpenForm(
    BuildContext context, {
    Subscriber? existing,
  }) async {
    final cubit = context.read<SubscribersCubit>();
    bool? saved;
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        saved = await context.push<bool>(
          existing == null ? '/subscribers/new' : '/subscriber-form',
          extra: existing,
        );
      } catch (_) {
        if (!context.mounted) return;
        saved = await Navigator.of(context).push<bool>(
          MaterialPageRoute<bool>(
            builder: (_) => RepositoryProvider<SubscribersRepository>.value(
              value: FouadModule.deps.subscribers,
              child: SubscriberFormScreen(existing: existing),
            ),
          ),
        );
      }
    } else {
      saved = await Navigator.of(context).push<bool>(
        MaterialPageRoute<bool>(
          builder: (_) => RepositoryProvider<SubscribersRepository>.value(
            value: FouadModule.deps.subscribers,
            child: SubscriberFormScreen(existing: existing),
          ),
        ),
      );
    }
    if (saved == true && context.mounted) {
      await cubit.init();
    }
  }

  Future<void> _handleDelete(
    BuildContext context,
    Subscriber subscriber,
  ) async {
    final confirmed = await confirmAction(
      context,
      'حذف المشترك',
      'هل تريد حذف المشترك «${subscriber.name}»؟ لا يمكن حذف مشترك مرتبط بعدادات أو حركات مالية.',
    );
    if (!confirmed || !context.mounted) return;
    final ok = await context.read<SubscribersCubit>().deleteSubscriber(
      subscriber.id,
    );
    if (ok && context.mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تم حذف المشترك بنجاح')));
    }
  }

  Future<void> _handleBulkDelete(BuildContext context, Set<int> ids) async {
    final confirmed = await confirmAction(
      context,
      'حذف جماعي للمشتركين',
      'هل تريد حذف ${ids.length} مشترك محدد؟ سيتم تجاوز المشتركين المرتبطين بحركات أو عدادات.',
    );
    if (!confirmed || !context.mounted) return;
    final deleted = await context.read<SubscribersCubit>().bulkDelete();
    if (context.mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('تم حذف $deleted مشترك بنجاح')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<SubscribersCubit, SubscribersState>(
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
        return SubPageScaffold(
          title: 'المشتركون',
          subtitle: 'سجل المشتركين والأرصدة',
          actions: [
            if (state.selectedIds.isNotEmpty) ...[
              AppIconButton(
                tooltip: 'تحديد الكل',
                icon: Icons.select_all,
                size: 38,
                onPressed: context.read<SubscribersCubit>().selectAllVisible,
              ),
              AppIconButton(
                tooltip: 'إلغاء التحديد',
                icon: Icons.clear_rounded,
                size: 38,
                onPressed: context.read<SubscribersCubit>().clearSelection,
              ),
              AppIconButton(
                tooltip: 'حذف المحدد (${state.selectedIds.length})',
                icon: Icons.delete_sweep_outlined,
                size: 38,
                onPressed: () => _handleBulkDelete(context, state.selectedIds),
              ),
            ],
            AppIconButton(
              tooltip: 'مشترك جديد',
              icon: Icons.person_add_alt_1_rounded,
              size: 38,
              onPressed: () => _handleOpenForm(context),
            ),
            AppIconButton(
              tooltip: 'تحديث',
              icon: Icons.refresh_rounded,
              size: 38,
              onPressed: context.read<SubscribersCubit>().load,
            ),
          ],
          child: MasterDetailLayout(
            hasSelection: state.selected != null,
            master: _list(context, state),
            detail: state.selected == null
                ? const SizedBox.shrink()
                : _SubscriberPeek(
                    subscriber: state.selected!,
                    onOpen360: (s) => _handleOpen360(context, s),
                    onEdit: (s) => _handleOpenForm(context, existing: s),
                    onDelete: (s) => _handleDelete(context, s),
                  ),
          ),
        );
      },
    );
  }

  Widget _list(BuildContext context, SubscribersState state) {
    final cubit = context.read<SubscribersCubit>();
    final theme = Theme.of(context);
    final activeCount =
        (state.squareId == null ? 0 : 1) + (state.status == null ? 0 : 1);
    final squareName = _squareName(state);
    final netBalanceTotal = state.subscribers.fold<double>(
      0,
      (sum, s) => sum + s.netBalance,
    );

    return Column(
      children: [
        const SizedBox(height: AppSpacing.xs),
        AppFilterBar(
          query: state.query,
          hint: 'بحث بالاسم / الهاتف / كود العداد',
          activeCount: activeCount,
          onOpenFilters: () => _openFilters(context, state, cubit),
          onChanged: cubit.search,
          trailing: IconButton(
            tooltip: 'مسح باركود المشترك',
            icon: const Icon(Icons.qr_code_scanner_rounded),
            onPressed: () async {
              final code = await BarcodeScannerScreen.scan(
                context,
                title: 'مسح باركود المشترك',
                hint: 'وجّه الكاميرا إلى بطاقة المشترك أو باركود العداد.',
              );
              if (code != null && context.mounted) cubit.search(code);
            },
          ),
        ),
        AppFilterChips(
          children: <Widget>[
            AppFilterChip(
              label: 'الكل',
              selected: state.status == null,
              onTap: () => cubit.filterStatus(null),
            ),
            for (final status in SubscriberStatus.values)
              AppFilterChip(
                label: status.label,
                selected: state.status == status,
                onTap: () =>
                    cubit.filterStatus(state.status == status ? null : status),
              ),
          ],
        ),
        AppActiveFilters(
          filters: <Widget>[
            if (squareName != null)
              AppFilterChip(
                label: squareName,
                icon: Icons.grid_view_rounded,
                selected: true,
                onRemove: () => cubit.filterSquare(null),
              ),
            if (state.status != null)
              AppFilterChip(
                label: 'الحالة · ${state.status!.label}',
                icon: Icons.verified_user_outlined,
                selected: true,
                onRemove: () => cubit.filterStatus(null),
              ),
          ],
          onClearAll: activeCount == 0
              ? null
              : () {
                  cubit.filterSquare(null);
                  cubit.filterStatus(null);
                },
        ),
        Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 12),
          child: AppStatStrip(
            items: [
              AppStatItem(
                label: 'عدد المشتركين',
                value: '${state.subscribers.length}',
                icon: Icons.people_outline_rounded,
                color: theme.colorScheme.primary,
              ),
              AppStatItem(
                label: 'نشط',
                value:
                    '${state.subscribers.where((s) => s.status == SubscriberStatus.active).length}',
                icon: Icons.check_circle_outline_rounded,
                color: AppColors.success,
              ),
              AppStatItem(
                label: SubscriberExperienceStrings.visibleBalance,
                value: CurrencyFormatter.format(netBalanceTotal),
                icon: Icons.account_balance_wallet_outlined,
                color: netBalanceTotal < 0
                    ? AppColors.danger
                    : AppColors.success,
              ),
            ],
          ),
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
                  Icon(
                    Icons.checklist,
                    size: 18,
                    color: theme.colorScheme.primary,
                  ),
                  const Gap(8),
                  Expanded(
                    child: Text(
                      'تم تحديد ${state.selectedIds.length} مشترك',
                      style: theme.textTheme.labelMedium,
                    ),
                  ),
                  TextButton(
                    onPressed: context.read<SubscribersCubit>().clearSelection,
                    child: const Text('إلغاء'),
                  ),
                  TextButton(
                    onPressed: () =>
                        _handleBulkDelete(context, state.selectedIds),
                    child: const Text('حذف المحدد'),
                  ),
                ],
              ),
            ),
          ),
        if (state.loading) const LinearProgressIndicator(),
        Expanded(
          child: ListPresentation<Subscriber>(
            items: state.subscribers,
            initialSettings: const ListPresentationSettings(sortBy: 'name', descending: false),
            sortValues: {
              'name': (label: ListOptionsStrings.name, value: (subscriber) => subscriber.name),
              'id': (label: ListOptionsStrings.id, value: (subscriber) => subscriber.id),
              'subscription_start_date': (label: ListOptionsStrings.date, value: (subscriber) => subscriber.subscriptionStartDate ?? ''),
              'square_name': (label: ListOptionsStrings.square, value: (subscriber) => subscriber.squareName ?? ''),
              'balance': (label: ListOptionsStrings.balance, value: (subscriber) => subscriber.netBalance),
              'meters_count': (label: ListOptionsStrings.meters, value: (subscriber) => subscriber.metersCount),
              'status': (label: ListOptionsStrings.status, value: (subscriber) => subscriber.status.index),
            },
            fields: const {'phone': ListOptionsStrings.phone, 'square': ListOptionsStrings.square,
              'meters': ListOptionsStrings.meters, 'status': ListOptionsStrings.status, 'balance': ListOptionsStrings.balance},
            builder: (context, subscribers, options) => subscribers.isEmpty && !state.loading
              ? Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    AdminEmptyView(
                      title: 'لا يوجد مشتركون مطابقون',
                      message: activeCount == 0
                          ? 'أضف مشتركاً جديداً لتبدأ.'
                          : 'لا شيء يطابق البحث أو الفلاتر الحالية.',
                      icon: activeCount == 0
                          ? Icons.people_outline_rounded
                          : Icons.filter_alt_off_rounded,
                    ),
                    if (activeCount != 0)
                      TextButton.icon(
                        onPressed: () {
                          cubit.filterSquare(null);
                          cubit.filterStatus(null);
                          cubit.search('');
                        },
                        icon: const Icon(Icons.restart_alt_rounded, size: 18),
                        label: const Text('مسح الفلاتر'),
                      ),
                  ],
                )
              : ListView.builder(
                  itemCount: subscribers.length,
                  itemBuilder: (_, i) {
                    final sub = subscribers[i];
                    return _SubscriberCard(
                      subscriber: sub,
                      presentation: options,
                      selected: state.selectedIds.contains(sub.id),
                      onToggleSelect: () =>
                          context.read<SubscribersCubit>().toggleSelect(sub.id),
                      onTap: () => context.read<SubscribersCubit>().select(sub),
                      onOpen360: (s) => _handleOpen360(context, s),
                      onEdit: (s) => _handleOpenForm(context, existing: s),
                      onDelete: (s) => _handleDelete(context, s),
                      onCollectPayment: onCollectPayment,
                    );
                  },
                ),
          ),
        ),
      ],
    );
  }
}
