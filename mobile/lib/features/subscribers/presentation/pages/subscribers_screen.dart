import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/widgets/common_widgets.dart';
import '../../../service_requests/presentation/cubit/service_requests_cubit.dart';
import '../../domain/entities/subscriber.dart';
import '../cubit/subscribers_cubit.dart';

/// منافذ التكامل مع أكرم (Dev 3): تحصيل سريع + بطاقات فواتير/مدفوعات.
/// تُحقن عند الدمج؛ الافتراضي هنا يستدعي endpoint التحصيل المباشر لفؤاد.
typedef CollectPaymentLauncher = Future<void> Function(BuildContext context, Subscriber subscriber);

/// SCR-05 — قائمة المشتركين: بحث + فلترة مربع/حالة + رصيد ملون + 3 أزرار سريعة.
class SubscribersScreen extends StatelessWidget {
  const SubscribersScreen({super.key, required this.onOpen360, this.onCollectPayment});

  final void Function(Subscriber subscriber) onOpen360;
  final CollectPaymentLauncher? onCollectPayment;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<SubscribersCubit, SubscribersState>(
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(title: const Text('SCR-05 • المشتركون')),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => Navigator.of(context).pushNamed('/subscriber-form'),
            icon: const Icon(Icons.person_add),
            label: const Text('مشترك جديد'),
          ),
          body: MasterDetailLayout(
            hasSelection: state.selected != null,
            master: _list(context, state),
            detail: state.selected == null
                ? const SizedBox.shrink()
                : _SubscriberPeek(subscriber: state.selected!, onOpen360: onOpen360),
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
          decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'بحث بالاسم / الهاتف / كود العداد...', border: OutlineInputBorder()),
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
      if (state.loading) const LinearProgressIndicator(),
      Expanded(
        child: state.subscribers.isEmpty && !state.loading
            ? const EmptyState(message: 'لا يوجد مشتركون مطابقون')
            : ListView.builder(
                itemCount: state.subscribers.length,
                itemBuilder: (_, i) => _SubscriberCard(
                  subscriber: state.subscribers[i],
                  onOpen360: onOpen360,
                  onCollectPayment: onCollectPayment,
                ),
              ),
      ),
    ]);
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
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text('#${s.id} • ${s.name}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16))),
            StatusBadge(
              label: s.status.label,
              color: switch (s.status) {
                SubscriberStatus.active => AppColors.success,
                SubscriberStatus.suspended => AppColors.warning,
                SubscriberStatus.inactive => AppColors.danger,
              },
            ),
          ]),
        const SizedBox(height: 4),
          Text('${s.squareName ?? ''} • ${s.metersCount} عداد • بدء الاشتراك: ${s.subscriptionStartDate ?? '-'}',
              style: const TextStyle(color: AppColors.neutral, fontSize: 12)),
          if (s.phone != null)
            Directionality(textDirection: TextDirection.ltr, child: Text(s.phone!, style: const TextStyle(fontSize: 13))),
          const SizedBox(height: 6),
          Row(children: [
            const Text('الرصيد الصافي: '),
            BalanceText(s.netBalance),
            const Spacer(),
            if (s.debitBalance > 0)
              Text('مدين: ${CurrencyFormatter.format(s.debitBalance)}', style: const TextStyle(color: AppColors.danger, fontSize: 12)),
          ]),
          const Divider(),
          // الأزرار السريعة الثلاثة (SCR-05)
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
    );
  }

  Future<void> _collect(BuildContext context) async {
    if (onCollectPayment != null) return onCollectPayment!(context, subscriber);
    // الافتراضي: نافذة تحصيل فؤاد المباشرة (POST /subscribers/{id}/collect-payment)
    final amountCtrl = TextEditingController();
    final cubit = context.read<SubscribersCubit>();
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('تحصيل دفعة — ${subscriber.name}'),
        content: TextField(
          controller: amountCtrl,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'المبلغ (ريال)', border: OutlineInputBorder()),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () async {
              final amount = double.tryParse(normalizeDigits(amountCtrl.text));
              if (amount == null || amount <= 0) return;
              final receipt = await cubit.collectPayment(subscriber.id, amount);
              if (ctx.mounted) {
                Navigator.pop(ctx);
                ScaffoldMessenger.of(ctx).showSnackBar(
                    SnackBar(content: Text('تم التحصيل بنجاح — سند: $receipt')));
              }
            },
            child: const Text('تحصيل'),
          ),
        ],
      ),
    );
  }

  /// طلب وايت سريع: السعر الافتراضي = آخر طلب للمشترك أو 5000 (SCR-05 / الخطة).
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
        title: Text('طلب وايت سريع — ${subscriber.name}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: amountCtrl, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'سعر الوايت (ريال)', border: OutlineInputBorder())),
          const SizedBox(height: 8),
          TextField(controller: notesCtrl, decoration: const InputDecoration(labelText: 'ملاحظات', border: OutlineInputBorder())),
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
      );
}
