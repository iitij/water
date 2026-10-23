import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
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

/// منافذ التكامل مع أكرم (Dev 3): تحصيل سريع + بطاقات فواتير/مدفوعات.
/// الافتراضي عند الدمج يفتح `CollectPaymentSheet.show(context, ...)` الخاص بـ Dev 3.
typedef CollectPaymentLauncher = Future<void> Function(BuildContext context, Subscriber subscriber);

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
  final canUseFinanceSheet = GetIt.I.isRegistered<PaymentsRepository>() ||
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
        decoration: const InputDecoration(
          labelText: 'المبلغ (ريال)',
          border: OutlineInputBorder(),
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
            final String receipt;
            if (fallbackCollect != null) {
              receipt = await fallbackCollect(amount);
            } else {
              final res = await FouadModule.deps.subscribers
                  .collectPayment(subscriber.id, amount: amount);
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

  Future<void> _handleOpen360(BuildContext context, Subscriber subscriber) async {
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
                create: (_) => Subscriber360Cubit(deps.subscribers)..load(subscriber.id),
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

  Future<void> _handleOpenForm(BuildContext context, {Subscriber? existing}) async {
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

  Future<void> _handleDelete(BuildContext context, Subscriber subscriber) async {
    final confirmed = await confirmAction(
      context,
      'حذف المشترك',
      'هل تريد حذف المشترك «${subscriber.name}»؟ لا يمكن حذف مشترك مرتبط بعدادات أو حركات مالية.',
    );
    if (!confirmed || !context.mounted) return;
    final ok = await context.read<SubscribersCubit>().deleteSubscriber(subscriber.id);
    if (ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم حذف المشترك بنجاح')),
      );
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
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('تم حذف $deleted مشترك بنجاح')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<SubscribersCubit, SubscribersState>(
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
            title: const Text('المشتركون'),
            actions: [
              if (state.selectedIds.isNotEmpty) ...[
                IconButton(
                  tooltip: 'تحديد الكل',
                  icon: const Icon(Icons.select_all),
                  onPressed: context.read<SubscribersCubit>().selectAllVisible,
                ),
                IconButton(
                  tooltip: 'إلغاء التحديد',
                  icon: const Icon(Icons.clear),
                  onPressed: context.read<SubscribersCubit>().clearSelection,
                ),
                IconButton(
                  tooltip: 'حذف المحدد (${state.selectedIds.length})',
                  icon: const Icon(Icons.delete_sweep),
                  onPressed: () => _handleBulkDelete(context, state.selectedIds),
                ),
              ],
              IconButton(
                tooltip: 'تحديث',
                icon: const Icon(Icons.refresh),
                onPressed: context.read<SubscribersCubit>().load,
              ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _handleOpenForm(context),
            icon: const Icon(Icons.person_add),
            label: const Text('مشترك جديد'),
          ),
          body: MasterDetailLayout(
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
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
        child: TextField(
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search),
            hintText: 'بحث بالاسم / الهاتف / كود العداد...',
            border: OutlineInputBorder(),
          ),
          onChanged: context.read<SubscribersCubit>().search,
        ),
      ),
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        child: Row(children: [
          DropdownButton<int?>(
            value: state.squareId,
            hint: const Text('كل المربعات'),
            items: [
              const DropdownMenuItem(value: null, child: Text('كل المربعات')),
              for (final sq in state.squares) DropdownMenuItem(value: sq.id, child: Text(sq.name)),
            ],
            onChanged: context.read<SubscribersCubit>().filterSquare,
          ),
          const SizedBox(width: 12),
          DropdownButton<SubscriberStatus?>(
            value: state.status,
            hint: const Text('كل الحالات'),
            items: [
              const DropdownMenuItem(value: null, child: Text('كل الحالات')),
              for (final s in SubscriberStatus.values) DropdownMenuItem(value: s, child: Text(s.label)),
            ],
            onChanged: context.read<SubscribersCubit>().filterStatus,
          ),
        ]),
      ),
      if (state.selectedIds.isNotEmpty)
        MaterialBanner(
          content: Text('تم تحديد ${state.selectedIds.length} مشترك'),
          leading: const Icon(Icons.checklist),
          actions: [
            TextButton(
              onPressed: context.read<SubscribersCubit>().clearSelection,
              child: const Text('إلغاء'),
            ),
            FilledButton.tonalIcon(
              onPressed: () => _handleBulkDelete(context, state.selectedIds),
              icon: const Icon(Icons.delete_sweep, color: AppColors.danger),
              label: const Text('حذف المحدد'),
            ),
          ],
        ),
      if (state.loading) const LinearProgressIndicator(),
      Expanded(
        child: state.subscribers.isEmpty && !state.loading
            ? const EmptyState(message: 'لا يوجد مشتركون مطابقون')
            : ListView.builder(
                itemCount: state.subscribers.length,
                itemBuilder: (_, i) {
                  final sub = state.subscribers[i];
                  return _SubscriberCard(
                    subscriber: sub,
                    selected: state.selectedIds.contains(sub.id),
                    onToggleSelect: () => context.read<SubscribersCubit>().toggleSelect(sub.id),
                    onTap: () => context.read<SubscribersCubit>().select(sub),
                    onOpen360: (s) => _handleOpen360(context, s),
                    onEdit: (s) => _handleOpenForm(context, existing: s),
                    onDelete: (s) => _handleDelete(context, s),
                    onCollectPayment: onCollectPayment,
                  );
                },
              ),
      ),
    ]);
  }
}

class _SubscriberCard extends StatelessWidget {
  const _SubscriberCard({
    required this.subscriber,
    required this.selected,
    required this.onToggleSelect,
    required this.onTap,
    required this.onOpen360,
    required this.onEdit,
    required this.onDelete,
    this.onCollectPayment,
  });

  final Subscriber subscriber;
  final bool selected;
  final VoidCallback onToggleSelect;
  final VoidCallback onTap;
  final void Function(Subscriber) onOpen360;
  final void Function(Subscriber) onEdit;
  final void Function(Subscriber) onDelete;
  final CollectPaymentLauncher? onCollectPayment;

  @override
  Widget build(BuildContext context) {
    final s = subscriber;
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      color: selected ? AppColors.primary.withValues(alpha: 0.08) : null,
      child: InkWell(
        onTap: onTap,
        onLongPress: onToggleSelect,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Checkbox(
                value: selected,
                onChanged: (_) => onToggleSelect(),
                visualDensity: VisualDensity.compact,
              ),
              Expanded(
                child: Text(
                  '#${s.id} • ${s.name}',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                ),
              ),
              StatusBadge(
                label: s.status.label,
                color: switch (s.status) {
                  SubscriberStatus.active => AppColors.success,
                  SubscriberStatus.suspended => AppColors.warning,
                  SubscriberStatus.inactive => AppColors.danger,
                },
              ),
              IconButton(
                icon: const Icon(Icons.edit_outlined, size: 20),
                tooltip: 'تعديل المشترك',
                onPressed: () => onEdit(s),
              ),
              IconButton(
                icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                tooltip: 'حذف المشترك',
                onPressed: () => onDelete(s),
              ),
            ]),
            const SizedBox(height: 4),
            Text(
              '${s.squareName ?? ''} • ${s.metersCount} عداد • بدء الاشتراك: ${s.subscriptionStartDate ?? '-'}',
              style: const TextStyle(color: AppColors.neutral, fontSize: 12),
            ),
            if (s.phone != null)
              Directionality(
                textDirection: TextDirection.ltr,
                child: Text(s.phone!, style: const TextStyle(fontSize: 13)),
              ),
            const SizedBox(height: 6),
            Row(children: [
              const Text('الرصيد الصافي: '),
              BalanceText(s.netBalance),
              const Spacer(),
              if (s.debitBalance > 0)
                Text(
                  'مدين: ${CurrencyFormatter.format(s.debitBalance)}',
                  style: const TextStyle(color: AppColors.danger, fontSize: 12),
                ),
            ]),
            const Divider(),
            Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
              TextButton.icon(
                icon: const Icon(Icons.payments, color: AppColors.success),
                label: const Text('تحصيل دفعة'),
                onPressed: () => _collect(context),
              ),
              TextButton.icon(
                icon: const Icon(Icons.local_shipping, color: AppColors.info),
                label: const Text('طلب وايت'),
                onPressed: () => _quickTanker(context),
              ),
              TextButton.icon(
                icon: const Icon(Icons.folder_open, color: AppColors.primary),
                label: const Text('ملف 360°'),
                onPressed: () => onOpen360(s),
              ),
            ]),
          ]),
        ),
      ),
    );
  }

  Future<void> _collect(BuildContext context) async {
    if (onCollectPayment != null) return onCollectPayment!(context, subscriber);
    final cubit = context.read<SubscribersCubit>();
    final saved = await launchDefaultSubscriberPayment(
      context,
      subscriber,
      fallbackCollect: (amount) => cubit.collectPayment(subscriber.id, amount),
    );
    if (saved && context.mounted) {
      await cubit.load();
    }
  }

  /// طلب وايت سريع: السعر الافتراضي = آخر طلب للمشترك أو 5000 + اختيار تاريخ الخدمة كما في Filament.
  Future<void> _quickTanker(BuildContext context) async {
    final cubit = context.read<SubscribersCubit>();
    final requestsCubit = context.read<ServiceRequestsCubit>();
    final defaultPrice = await cubit.quickTankerDefaultPrice(subscriber.id);
    if (!context.mounted) return;
    final amountCtrl = TextEditingController(text: defaultPrice.toStringAsFixed(0));
    final notesCtrl = TextEditingController();
    var serviceDate = DateTime.now();
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text('طلب وايت سريع — ${subscriber.name}'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(
              controller: amountCtrl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'سعر الوايت (ريال)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 8),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('تاريخ الخدمة'),
              subtitle: Text(serviceDate.toIso8601String().substring(0, 10)),
              trailing: const Icon(Icons.calendar_today),
              onTap: () async {
                final picked = await showDatePicker(
                  context: ctx,
                  initialDate: serviceDate,
                  firstDate: DateTime(2020),
                  lastDate: DateTime(2100),
                );
                if (picked != null) setDialogState(() => serviceDate = picked);
              },
            ),
            const SizedBox(height: 8),
            TextField(
              controller: notesCtrl,
              decoration: const InputDecoration(
                labelText: 'ملاحظات',
                border: OutlineInputBorder(),
              ),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
            FilledButton(
              onPressed: () async {
                final amount = double.tryParse(normalizeDigits(amountCtrl.text));
                if (amount == null || amount <= 0) return;
                await requestsCubit.create(
                  subscriberId: subscriber.id,
                  amount: amount,
                  serviceDate: serviceDate.toIso8601String().substring(0, 10),
                  notes: notesCtrl.text.isEmpty ? null : notesCtrl.text,
                );
                if (ctx.mounted) {
                  Navigator.pop(ctx);
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('تم إضافة طلب الوايت بنجاح')),
                  );
                }
              },
              child: const Text('إضافة الطلب'),
            ),
          ],
        ),
      ),
    );
  }
}

class _SubscriberPeek extends StatelessWidget {
  const _SubscriberPeek({
    required this.subscriber,
    required this.onOpen360,
    required this.onEdit,
    required this.onDelete,
  });

  final Subscriber subscriber;
  final void Function(Subscriber) onOpen360;
  final void Function(Subscriber) onEdit;
  final void Function(Subscriber) onDelete;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(subscriber.name, style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 8),
          Text('المربع: ${subscriber.squareName ?? '-'}'),
          Text('الهاتف: ${subscriber.phone ?? '-'}'),
          Text('العنوان: ${subscriber.address ?? '-'}'),
          const SizedBox(height: 8),
          Row(children: [
            const Text('الرصيد الصافي: '),
            BalanceText(subscriber.netBalance, fontSize: 18),
          ]),
          const SizedBox(height: 16),
          Wrap(spacing: 10, runSpacing: 10, children: [
            FilledButton.icon(
              onPressed: () => onOpen360(subscriber),
              icon: const Icon(Icons.folder_open),
              label: const Text('فتح الملف المالي الشامل 360°'),
            ),
            OutlinedButton.icon(
              onPressed: () => onEdit(subscriber),
              icon: const Icon(Icons.edit_outlined),
              label: const Text('تعديل المشترك'),
            ),
            OutlinedButton.icon(
              onPressed: () => onDelete(subscriber),
              icon: const Icon(Icons.delete_outline, color: AppColors.danger),
              label: const Text('حذف المشترك', style: TextStyle(color: AppColors.danger)),
            ),
          ]),
        ]),
      );
}
