import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
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
        final scheme = Theme.of(context).colorScheme;
        return DefaultTabController(
          length: 6,
          child: SubPageScaffold(
            title: activeSubscriber.name,
            subtitle: 'الملف المالي الشامل 360°',
            actions: [
              AppIconButton(
                icon: Icons.payments_outlined,
                tooltip: 'تحصيل دفعة',
                size: 38,
                onPressed: () => _collect(context, activeSubscriber),
              ),
              AppIconButton(
                icon: Icons.edit_outlined,
                tooltip: 'تعديل المشترك',
                size: 38,
                onPressed: () => _editSubscriber(context, activeSubscriber),
              ),
              AppIconButton(
                icon: Icons.delete_outline,
                tooltip: 'حذف المشترك',
                size: 38,
                onPressed: () => _deleteSubscriber(context, activeSubscriber),
              ),
            ],
            child: state.loading
                ? const AppLoader()
                : detail == null
                    ? AdminEmptyView(
                        title: 'تعذر تحميل الملف',
                        message: state.error,
                        icon: Icons.error_outline_rounded,
                      )
                    : Column(children: [
                        _FinancialSummaryHeader(summary: detail.summary),
                        Material(
                          color: scheme.surface,
                          child: TabBar(
                            isScrollable: true,
                            tabAlignment: TabAlignment.start,
                            labelColor: scheme.primary,
                            unselectedLabelColor: scheme.onSurfaceVariant,
                            indicatorColor: scheme.primary,
                            tabs: const [
                              Tab(icon: Icon(Icons.speed), text: 'العدادات'),
                              Tab(icon: Icon(Icons.receipt_long), text: 'الفواتير'),
                              Tab(icon: Icon(Icons.payments), text: 'المدفوعات'),
                              Tab(icon: Icon(Icons.card_membership), text: 'رسوم الاشتراك'),
                              Tab(icon: Icon(Icons.local_shipping), text: 'طلبات الوايت'),
                              Tab(icon: Icon(Icons.account_balance), text: 'كشف الحساب'),
                            ],
                          ),
                        ),
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
/// الرصيد العام هو رقم الصفحة البطل على لوحة الحبر الوحيدة، والبقية أرقام مساندة.
class _FinancialSummaryHeader extends StatelessWidget {
  const _FinancialSummaryHeader({required this.summary});
  final FinancialSummary summary;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 4),
      child: AppHeroPanel(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'الرصيد العام للمشترك (اشتراك/وايت)',
              style: theme.textTheme.labelSmall?.copyWith(color: AppColors.onPanelMuted),
            ),
            const Gap(4),
            Directionality(
              textDirection: TextDirection.ltr,
              child: Text(
                CurrencyFormatter.format(summary.generalBalance),
                style: theme.textTheme.headlineMedium?.copyWith(
                  color: AppColors.onPanel,
                  fontWeight: FontWeight.w600,
                  fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                ),
              ),
            ),
            const Gap(12),
            Wrap(
              spacing: 20,
              runSpacing: 10,
              children: [
                _HeroStat(label: 'إجمالي دائن (عام + عدادات)', value: summary.totalCredit),
                _HeroStat(label: 'إجمالي مدين / متأخرات', value: -summary.totalDebit),
                _HeroStat(label: 'متبقي رسوم اشتراك غير مفوترة', value: -summary.remainingSubscriptionFee),
              ],
            ),
            if (summary.meterBalances.isNotEmpty) ...[
              const Gap(12),
              Text(
                'أرصدة العدادات المعزولة:',
                style: theme.textTheme.labelSmall?.copyWith(color: AppColors.onPanelSoft),
              ),
              const Gap(8),
              Wrap(
                spacing: 8,
                runSpacing: 6,
                children: [
                  for (final m in summary.meterBalances)
                    HeroMetaChip(
                      icon: Icons.speed_rounded,
                      label: '${m.title} | دائن ${m.credit.toStringAsFixed(0)} / مدين ${m.debit.toStringAsFixed(0)}',
                    ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// رقم مساند داخل لوحة الحبر: تسمية صامتة + قيمة بيضاء بأرقام جدولية.
class _HeroStat extends StatelessWidget {
  const _HeroStat({required this.label, required this.value});
  final String label;
  final double value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(label, style: theme.textTheme.labelSmall?.copyWith(color: AppColors.onPanelMuted)),
        const Gap(2),
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text(
            CurrencyFormatter.format(value),
            style: theme.textTheme.titleMedium?.copyWith(
              color: AppColors.onPanel,
              fontWeight: FontWeight.w600,
              fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
            ),
          ),
        ),
      ],
    );
  }
}

// ─── التبويب 1: العدادات ───
typedef Subscriber360View = Subscriber360;
