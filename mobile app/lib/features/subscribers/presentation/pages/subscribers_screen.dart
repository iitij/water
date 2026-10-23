import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/widgets/app_numeric_field.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/search_text_field.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
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
      icon: const Icon(Icons.payments_rounded, size: 32, color: AppColors.success),
      title: Text('تحصيل دفعة — ${subscriber.name}', style: const TextStyle(fontWeight: FontWeight.w800)),
      content: AppNumericField(
        controller: amountCtrl,
        label: 'المبلغ (ريال)',
        suffixText: 'ريال',
        autofocus: true,
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
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

/// قائمة المشتركين: بحث + فلترة مربع/حالة + رصيد ملون + 3 أزرار سريعة.
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

  void _handleOpen360(BuildContext context, Subscriber subscriber) {
    if (onOpen360 != null) {
      onOpen360!(subscriber);
      return;
    }
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        context.push('/subscribers/${subscriber.id}', extra: subscriber);
        return;
      } catch (_) {}
    }
    final deps = FouadModule.deps;
    Navigator.of(context).push(
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
  }

  Future<void> _handleOpenForm(BuildContext context) async {
    final cubit = context.read<SubscribersCubit>();
    bool? saved;
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        saved = await context.push<bool>('/subscribers/new');
      } catch (_) {
        saved = await Navigator.of(context).push<bool>(
          MaterialPageRoute<bool>(
            builder: (_) => RepositoryProvider<SubscribersRepository>.value(
              value: FouadModule.deps.subscribers,
              child: const SubscriberFormScreen(),
            ),
          ),
        );
      }
    } else {
      try {
        final res = await Navigator.of(context).pushNamed('/subscriber-form');
        saved = res == true;
      } catch (_) {
        if (!context.mounted) return;
        saved = await Navigator.of(context).push<bool>(
          MaterialPageRoute<bool>(
            builder: (_) => RepositoryProvider<SubscribersRepository>.value(
              value: FouadModule.deps.subscribers,
              child: const SubscriberFormScreen(),
            ),
          ),
        );
      }
    }
    if (saved == true && context.mounted) {
      await cubit.init();
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<SubscribersCubit, SubscribersState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(title: const Text('المشتركون')),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _handleOpenForm(context),
            icon: const Icon(Icons.person_add_rounded),
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
                  ),
          ),
        );
      },
    );
  }

  Widget _list(BuildContext context, SubscribersState state) {
    final cubit = context.read<SubscribersCubit>();
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
        child: SearchTextField(
          query: state.query,
          hintText: 'بحث بالاسم / الهاتف / كود العداد...',
          onChanged: cubit.search,
        ),
      ),
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        child: Row(children: [
          SizedBox(
            width: 170,
            child: _FilterSelect<int?>(
              hint: 'كل المربعات',
              value: state.squareId,
              items: [
                const DropdownMenuItem(value: null, child: Text('كل المربعات')),
                for (final sq in state.squares) DropdownMenuItem(value: sq.id, child: Text(sq.name)),
              ],
              onChanged: cubit.filterSquare,
            ),
          ),
          const SizedBox(width: 12),
          SizedBox(
            width: 170,
            child: _FilterSelect<SubscriberStatus?>(
              hint: 'كل الحالات',
              value: state.status,
              items: [
                const DropdownMenuItem(value: null, child: Text('كل الحالات')),
                for (final s in SubscriberStatus.values) DropdownMenuItem(value: s, child: Text(s.label)),
              ],
              onChanged: cubit.filterStatus,
            ),
          ),
        ]),
      ),
      Expanded(
        child: state.loading
            ? ListView(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                children: List.generate(6, (_) => ShimmerSkeleton(height: 118)),
              )
            : state.subscribers.isEmpty
                ? const EmptyState(message: 'لا يوجد مشتركون مطابقون')
                : ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    itemCount: state.subscribers.length,
                    itemBuilder: (_, i) => _SubscriberCard(
                      subscriber: state.subscribers[i],
                      onOpen360: (s) => _handleOpen360(context, s),
                      onCollectPayment: onCollectPayment,
                    ),
                  ),
      ),
    ]);
  }
}

/// dropdown معلم عصري يطابق ثيم الإدخالات (مستطيل ممتلئ بزوايا دائرية).
class _FilterSelect<T> extends StatelessWidget {
  const _FilterSelect({
    required this.hint,
    required this.value,
    required this.items,
    required this.onChanged,
  });

  final String hint;
  final T? value;
  final List<DropdownMenuItem<T>> items;
  final ValueChanged<T?> onChanged;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<T>(
      value: value,
      isDense: true,
      isExpanded: true,
      hint: Text(hint),
      items: items,
      onChanged: onChanged,
      decoration: InputDecoration(
        isCollapsed: true,
        filled: true,
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 13),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.7),
        ),
        floatingLabelBehavior: FloatingLabelBehavior.never,
      ),
    );
  }
}

class _SubscriberCard extends StatelessWidget {
  const _SubscriberCard({required this.subscriber, required this.onOpen360, this.onCollectPayment});
  final Subscriber subscriber;
  final void Function(Subscriber) onOpen360;
  final CollectPaymentLauncher? onCollectPayment;

  @override
  Widget build(BuildContext context) {
    final s = subscriber;
    return AdminCard(
      padding: const EdgeInsets.all(15),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text('#${s.id} • ${s.name}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
          StatusBadge(
            label: s.status.label,
            color: switch (s.status) {
              SubscriberStatus.active => AppColors.success,
              SubscriberStatus.suspended => AppColors.warning,
              SubscriberStatus.inactive => AppColors.danger,
            },
          ),
        ]),
        const SizedBox(height: 6),
        Text('${s.squareName ?? ''} • ${s.metersCount} عداد • بدء الاشتراك: ${s.subscriptionStartDate ?? '-'}',
            style: TextStyle(color: AppColors.neutral, fontSize: 12)),
        if (s.phone != null)
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(s.phone!, style: const TextStyle(fontSize: 13)),
          ),
        const SizedBox(height: 8),
        Row(children: [
          const Text('الرصيد الصافي: '),
          BalanceText(s.netBalance),
          const Spacer(),
          if (s.debitBalance > 0)
            Text('مدين: ${CurrencyFormatter.format(s.debitBalance)}', style: const TextStyle(color: AppColors.danger, fontSize: 12)),
        ]),
        const SizedBox(height: 12),
        Divider(color: context.theme.dividerColor.withValues(alpha: .6)),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            FilledButton.tonalIcon(
              style: _quickActionStyle,
              onPressed: () => _collect(context),
              icon: const Icon(Icons.payments_outlined, size: 18),
              label: const Text('تحصيل دفعة'),
            ),
            FilledButton.tonalIcon(
              style: _quickActionStyle,
              onPressed: () => _quickTanker(context),
              icon: const Icon(Icons.local_shipping_outlined, size: 18),
              label: const Text('طلب وايت'),
            ),
            FilledButton.tonalIcon(
              style: _quickActionStyle,
              onPressed: () => onOpen360(s),
              icon: const Icon(Icons.folder_open_outlined, size: 18),
              label: const Text('ملف 360°'),
            ),
          ],
        ),
      ]),
    );
  }

  static final ButtonStyle _quickActionStyle = ButtonStyle(
    minimumSize: const WidgetStateProperty.resolveWith<Size>((states) => Size.zero),
    padding: const WidgetStateProperty.resolveWith<EdgeInsets>(
      (states) => EdgeInsets.symmetric(horizontal: 12, vertical: 8),
    ),
    textStyle: const WidgetStateProperty.resolveWith<TextStyle>(
      (states) => TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
    ),
    iconSize: const WidgetStateProperty.resolveWith<double>((states) => 18),
  );

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

  /// طلب وايت سريع: السعر الافتراضي = آخر طلب للمشترك أو 5000.
  Future<void> _quickTanker(BuildContext context) async {
    final cubit = context.read<SubscribersCubit>();
    final requestsCubit = context.read<ServiceRequestsCubit>();
    final defaultPrice = await cubit.quickTankerDefaultPrice(subscriber.id);
    if (!context.mounted) return;
    final amountCtrl = TextEditingController(text: defaultPrice.toStringAsFixed(0));
    final notesCtrl = TextEditingController();
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.local_shipping_rounded, size: 32, color: AppColors.info),
        title: Text('طلب وايت سريع — ${subscriber.name}', style: const TextStyle(fontWeight: FontWeight.w800)),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          AppNumericField(controller: amountCtrl, label: 'سعر الوايت (ريال)', suffixText: 'ريال'),
          const SizedBox(height: 10),
          AppTextField(controller: notesCtrl, label: 'ملاحظات', maxLines: 2),
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
                notes: notesCtrl.text.isEmpty ? null : notesCtrl.text,
              );
              if (ctx.mounted) {
                Navigator.pop(ctx);
                ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(content: Text('تم إضافة طلب الوايت بنجاح')));
              }
            },
            child: const Text('إضافة الطلب'),
          ),
        ],
      ),
    );
  }
}

class _SubscriberPeek extends StatelessWidget {
  const _SubscriberPeek({required this.subscriber, required this.onOpen360});
  final Subscriber subscriber;
  final void Function(Subscriber) onOpen360;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(20),
        child: AdminCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(subscriber.name, style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 8),
            Text('المربع: ${subscriber.squareName ?? '-'}'),
            Text('الهاتف: ${subscriber.phone ?? '-'}'),
            const SizedBox(height: 8),
            Row(children: [const Text('الرصيد الصافي: '), BalanceText(subscriber.netBalance, fontSize: 18)]),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: () => onOpen360(subscriber),
              icon: const Icon(Icons.folder_open),
              label: const Text('فتح الملف المالي الشامل 360°'),
            ),
          ]),
        ),
      );
}
