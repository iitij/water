import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/app_colors.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import '../../../invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../../invoices/presentation/widgets/invoice_actions.dart';
import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../../meters/presentation/pages/meter_form_screen.dart';
import '../../../payments/data/models/payment_model.dart';
import '../../../payments/domain/repositories/payments_repository.dart';
import '../../../payments/presentation/widgets/payment_actions.dart';
import '../../../readings/domain/readings_repository.dart';
import '../../../readings/presentation/widgets/record_reading_sheet.dart';
import '../../../service_requests/domain/entities/service_request.dart';
import '../../../service_requests/presentation/cubit/service_requests_cubit.dart';
import '../../../subscription_fees/domain/entities/subscription_fee.dart';
import '../../../subscription_fees/presentation/cubit/subscription_fees_cubit.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';
import '../cubit/subscriber360_cubit.dart';
import '../widgets/statement_pdf_service.dart';
import 'subscriber_form_screen.dart';
import 'subscribers_screen.dart'
    show CollectPaymentLauncher, launchDefaultSubscriberPayment;

part '../widgets/subscriber_360_tabs.dart';

/// منافذ تكامل: أنور (Dev 2) بطاقة عداد + تسجيل قراءة — أكرم (Dev 3) تحصيل/فواتير/مدفوعات.
typedef RecordReadingLauncher = void Function(BuildContext context, MeterBalance meter);
typedef AddMeterLauncher = void Function(BuildContext context, Subscriber subscriber);

bool _has360Cubits(BuildContext context) {
  try {
    context.read<Subscriber360Cubit>();
    context.read<SubscriptionFeesCubit>();
    context.read<ServiceRequestsCubit>();
    return true;
  } catch (_) {
    return false;
  }
}

/// SCR-07 — الملف المالي الشامل 360°: ملخص معزول + تعديل/حذف المشترك + 6 تبويبات تفاعلية كاملة.
class Subscriber360Screen extends StatelessWidget {
  const Subscriber360Screen({
    super.key,
    required this.subscriber,
    this.onRecordReading,
    this.onAddMeter,
    this.onCollectPayment,
  });

  final Subscriber subscriber;
  final RecordReadingLauncher? onRecordReading;
  final AddMeterLauncher? onAddMeter;
  final CollectPaymentLauncher? onCollectPayment;

  @override
  Widget build(BuildContext context) {
    if (!_has360Cubits(context)) {
      final deps = FouadModule.deps;
      return RepositoryProvider<SubscribersRepository>.value(
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
          child: _Subscriber360View(
            subscriber: subscriber,
            onRecordReading: onRecordReading,
            onAddMeter: onAddMeter,
            onCollectPayment: onCollectPayment,
          ),
        ),
      );
    }
    return _Subscriber360View(
      subscriber: subscriber,
      onRecordReading: onRecordReading,
      onAddMeter: onAddMeter,
      onCollectPayment: onCollectPayment,
    );
  }
}

class _Subscriber360View extends StatelessWidget {
  const _Subscriber360View({
    required this.subscriber,
    this.onRecordReading,
    this.onAddMeter,
    this.onCollectPayment,
  });

  final Subscriber subscriber;
  final RecordReadingLauncher? onRecordReading;
  final AddMeterLauncher? onAddMeter;
  final CollectPaymentLauncher? onCollectPayment;

  Future<void> _collect(BuildContext context, Subscriber activeSubscriber) async {
    if (onCollectPayment != null) {
      await onCollectPayment!(context, activeSubscriber);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(activeSubscriber.id);
      }
      return;
    }
    final cubit = context.read<Subscriber360Cubit>();
    final saved = await launchDefaultSubscriberPayment(context, activeSubscriber);
    if (saved && context.mounted) {
      await cubit.load(activeSubscriber.id);
    }
  }

  Future<void> _editSubscriber(BuildContext context, Subscriber activeSubscriber) async {
    final cubit = context.read<Subscriber360Cubit>();
    bool? saved;
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        saved = await context.push<bool>('/subscriber-form', extra: activeSubscriber);
      } catch (_) {
        if (!context.mounted) return;
        saved = await Navigator.of(context).push<bool>(
          MaterialPageRoute<bool>(
            builder: (_) => RepositoryProvider<SubscribersRepository>.value(
              value: FouadModule.deps.subscribers,
              child: SubscriberFormScreen(existing: activeSubscriber),
            ),
          ),
        );
      }
    } else {
      saved = await Navigator.of(context).push<bool>(
        MaterialPageRoute<bool>(
          builder: (_) => RepositoryProvider<SubscribersRepository>.value(
            value: FouadModule.deps.subscribers,
            child: SubscriberFormScreen(existing: activeSubscriber),
          ),
        ),
      );
    }
    if (saved == true && context.mounted) {
      await cubit.load(activeSubscriber.id);
    }
  }

  Future<void> _deleteSubscriber(BuildContext context, Subscriber activeSubscriber) async {
    final confirmed = await confirmAction(
      context,
      'حذف المشترك',
      'هل تريد حذف المشترك «${activeSubscriber.name}»؟ لا يمكن حذفه إذا كانت له عدادات أو حركات مالية.',
    );
    if (!confirmed || !context.mounted) return;
    try {
      await FouadModule.deps.subscribers.deleteSubscriber(activeSubscriber.id);
      if (!context.mounted) return;
      final router = GoRouter.maybeOf(context);
      if (router != null && router.canPop()) {
        context.pop(true);
      } else {
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(friendlyError(e)), backgroundColor: AppColors.danger),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<Subscriber360Cubit, Subscriber360State>(
      builder: (context, state) {
        final detail = state.detail;
        final activeSubscriber = detail?.subscriber ?? subscriber;
        return DefaultTabController(
          length: 6,
          child: Scaffold(
            appBar: AppBar(
              title: Text(activeSubscriber.name),
              actions: [
                IconButton(
                  icon: const Icon(Icons.payments_outlined),
                  tooltip: 'تحصيل دفعة',
                  onPressed: () => _collect(context, activeSubscriber),
                ),
                IconButton(
                  icon: const Icon(Icons.edit_outlined),
                  tooltip: 'تعديل المشترك',
                  onPressed: () => _editSubscriber(context, activeSubscriber),
                ),
                IconButton(
                  icon: const Icon(Icons.delete_outline),
                  tooltip: 'حذف المشترك',
                  onPressed: () => _deleteSubscriber(context, activeSubscriber),
                ),
              ],
              bottom: const TabBar(
                isScrollable: true,
                tabs: [
                  Tab(icon: Icon(Icons.speed), text: 'العدادات'),
                  Tab(icon: Icon(Icons.receipt_long), text: 'الفواتير'),
                  Tab(icon: Icon(Icons.payments), text: 'المدفوعات'),
                  Tab(icon: Icon(Icons.card_membership), text: 'رسوم الاشتراك'),
                  Tab(icon: Icon(Icons.local_shipping), text: 'طلبات الوايت'),
                  Tab(icon: Icon(Icons.account_balance), text: 'كشف الحساب'),
                ],
              ),
            ),
            body: state.loading
                ? const Center(child: CircularProgressIndicator())
                : detail == null
                    ? EmptyState(message: state.error ?? 'تعذر تحميل الملف')
                    : Column(children: [
                        _FinancialSummaryHeader(summary: detail.summary),
                        Expanded(
                          child: TabBarView(children: [
                            _MetersTab(
                              detail: detail,
                              onRecordReading: onRecordReading,
                              onAddMeter: onAddMeter,
                            ),
                            _InvoicesTab(
                              subscriber: activeSubscriber,
                              invoices: detail.invoices,
                              onCollect: () => _collect(context, activeSubscriber),
                            ),
                            _PaymentsTab(
                              subscriber: activeSubscriber,
                              payments: detail.payments,
                            ),
                            _FeesTab(meters: detail.summary.meterBalances),
                            const _RequestsTab(),
                            _StatementTab(subscriberName: activeSubscriber.name),
                          ]),
                        ),
                      ]),
          ),
        );
      },
    );
  }
}

/// لوحة الملخص المالي: فصل صريح بين الرصيد العام وأرصدة كل عداد (قاعدة العزل).
class _FinancialSummaryHeader extends StatelessWidget {
  const _FinancialSummaryHeader({required this.summary});
  final FinancialSummary summary;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: AppColors.primaryDark,
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(spacing: 12, runSpacing: 8, children: [
          _SummaryChip(label: 'إجمالي دائن (عام + عدادات)', value: summary.totalCredit, color: AppColors.success),
          _SummaryChip(label: 'إجمالي مدين / متأخرات', value: -summary.totalDebit, color: AppColors.danger),
          _SummaryChip(label: 'الرصيد العام للمشترك (اشتراك/وايت)', value: summary.generalBalance, color: Colors.lightBlueAccent),
          _SummaryChip(label: 'متبقي رسوم اشتراك غير مفوترة', value: -summary.remainingSubscriptionFee, color: AppColors.brandAmber),
        ]),
        if (summary.meterBalances.isNotEmpty) ...[
          const SizedBox(height: 10),
          const Text('أرصدة العدادات المعزولة:', style: TextStyle(color: Colors.white70)),
          const SizedBox(height: 6),
          Wrap(spacing: 8, runSpacing: 6, children: [
            for (final m in summary.meterBalances)
              Chip(
                avatar: const Icon(Icons.speed, size: 16),
                label: Text('${m.title} | دائن ${m.credit.toStringAsFixed(0)} / مدين ${m.debit.toStringAsFixed(0)}'),
              ),
          ]),
        ],
      ]),
    );
  }
}

class _SummaryChip extends StatelessWidget {
  const _SummaryChip({required this.label, required this.value, required this.color});
  final String label;
  final double value;
  final Color color;
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: const TextStyle(color: Colors.white70, fontSize: 11)),
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text(CurrencyFormatter.format(value),
              style: TextStyle(color: color, fontSize: 16, fontWeight: FontWeight.bold)),
        ),
      ]);
}

// ─── التبويب 1: العدادات ───
typedef Subscriber360View = Subscriber360;
