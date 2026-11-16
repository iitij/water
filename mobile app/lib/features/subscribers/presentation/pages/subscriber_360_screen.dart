import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/common_widgets.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/fouad/fouad_module.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/l10n/subscriber_experience_strings.dart';
import '../../../../core/models/record_location.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/network/dio_client.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/ui/record_location_label.dart';
import '../../../../core/utils/json_utils.dart';
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
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';
import '../cubit/subscriber360_cubit.dart';
import '../widgets/statement_pdf_service.dart';
import 'subscriber_form_screen.dart';
import 'subscribers_screen.dart'
    show CollectPaymentLauncher, launchDefaultSubscriberPayment;

part '../widgets/subscriber_360_tabs.dart';

/// منافذ تكامل: أنور (Dev 2) بطاقة عداد + تسجيل قراءة — أكرم (Dev 3) تحصيل/فواتير/مدفوعات.
typedef RecordReadingLauncher =
    void Function(BuildContext context, MeterBalance meter);
typedef AddMeterLauncher =
    void Function(BuildContext context, Subscriber subscriber);

bool _has360Cubits(BuildContext context) {
  try {
    context.read<Subscriber360Cubit>();
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
              create: (_) =>
                  Subscriber360Cubit(deps.subscribers)..load(subscriber.id),
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

  Future<void> _collect(
    BuildContext context,
    Subscriber activeSubscriber,
  ) async {
    if (onCollectPayment != null) {
      await onCollectPayment!(context, activeSubscriber);
      if (context.mounted) {
        await context.read<Subscriber360Cubit>().load(activeSubscriber.id);
      }
      return;
    }
    final cubit = context.read<Subscriber360Cubit>();
    final saved = await launchDefaultSubscriberPayment(
      context,
      activeSubscriber,
    );
    if (saved && context.mounted) {
      await cubit.load(activeSubscriber.id);
    }
  }

  Future<void> _editSubscriber(
    BuildContext context,
    Subscriber activeSubscriber,
  ) async {
    final cubit = context.read<Subscriber360Cubit>();
    bool? saved;
    final router = GoRouter.maybeOf(context);
    if (router != null) {
      try {
        saved = await context.push<bool>(
          '/subscriber-form',
          extra: activeSubscriber,
        );
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

  Future<void> _deleteSubscriber(
    BuildContext context,
    Subscriber activeSubscriber,
  ) async {
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
          SnackBar(
            content: Text(friendlyError(e)),
            backgroundColor: AppColors.danger,
          ),
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
          length: 4,
          child: SubPageScaffold(
            title: activeSubscriber.name,
            subtitle: SubscriberExperienceStrings.profile,
            actions: [
              AppIconButton(
                icon: Icons.payments_outlined,
                tooltip: SubscriberExperienceStrings.collect,
                size: 48,
                onPressed: () => _collect(context, activeSubscriber),
              ),
              PopupMenuButton<String>(
                tooltip: SubscriberExperienceStrings.options,
                onSelected: (value) {
                  if (value == 'edit') {
                    _editSubscriber(context, activeSubscriber);
                  }
                  if (value == 'delete') {
                    _deleteSubscriber(context, activeSubscriber);
                  }
                },
                itemBuilder: (_) => const [
                  PopupMenuItem(
                    value: 'edit',
                    child: Text(SubscriberExperienceStrings.edit),
                  ),
                  PopupMenuItem(
                    value: 'delete',
                    child: Text(SubscriberExperienceStrings.delete),
                  ),
                ],
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
                : NestedScrollView(
                    headerSliverBuilder: (context, innerBoxIsScrolled) => [
                      SliverToBoxAdapter(
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 16,
                            vertical: 8,
                          ),
                          child: RecordLocationLabel(
                            location: RecordLocation(
                              squareId: activeSubscriber.squareId,
                              squareName: activeSubscriber.squareName,
                              paths: activeSubscriber.pathNumbers,
                            ),
                          ),
                        ),
                      ),
                      SliverToBoxAdapter(
                        child: _FinancialSummaryHeader(summary: detail.summary),
                      ),
                      const SliverToBoxAdapter(child: _SubscriberSections()),
                    ],
                    body: TabBarView(
                      children: [
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
                        _StatementTab(subscriberName: activeSubscriber.name),
                      ],
                    ),
                  ),
          ),
        );
      },
    );
  }
}

/// All five sections remain visible without a horizontally hidden tab rail.
class _SubscriberSections extends StatelessWidget {
  const _SubscriberSections();

  @override
  Widget build(BuildContext context) {
    final controller = DefaultTabController.of(context);
    final theme = Theme.of(context);
    const sections = [
      (Icons.speed_outlined, SubscriberExperienceStrings.meters),
      (Icons.receipt_long_outlined, SubscriberExperienceStrings.invoices),
      (Icons.payments_outlined, SubscriberExperienceStrings.payments),
      (Icons.account_balance_outlined, SubscriberExperienceStrings.statement),
    ];
    return AnimatedBuilder(
      animation: controller,
      builder: (context, _) => Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 12),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final largeText = MediaQuery.textScalerOf(context).scale(14) > 19;
            final columns = constraints.maxWidth > 700
                ? 5
                : largeText
                ? 2
                : 3;
            final width = (constraints.maxWidth - 8 * (columns - 1)) / columns;
            return Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (var i = 0; i < sections.length; i++)
                  SizedBox(
                    width: width,
                    child: Semantics(
                      selected: controller.index == i,
                      button: true,
                      child: Material(
                        color: controller.index == i
                            ? theme.colorScheme.primaryContainer
                            : theme.colorScheme.surface,
                        borderRadius: BorderRadius.circular(12),
                        child: InkWell(
                          onTap: () => controller.animateTo(i),
                          borderRadius: BorderRadius.circular(12),
                          child: Container(
                            constraints: const BoxConstraints(minHeight: 52),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 10,
                            ),
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(
                                color: controller.index == i
                                    ? theme.colorScheme.primary
                                    : theme.colorScheme.outlineVariant,
                              ),
                            ),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(
                                  sections[i].$1,
                                  size: 19,
                                  color: controller.index == i
                                      ? theme.colorScheme.primary
                                      : theme.colorScheme.onSurfaceVariant,
                                ),
                                const Gap(4),
                                Text(
                                  sections[i].$2,
                                  textAlign: TextAlign.center,
                                  style: theme.textTheme.labelMedium?.copyWith(
                                    fontWeight: controller.index == i
                                        ? FontWeight.w700
                                        : FontWeight.w500,
                                    color: controller.index == i
                                        ? theme.colorScheme.onPrimaryContainer
                                        : theme.colorScheme.onSurface,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}

/// General account stays separate from meter debts and uninvoiced fees.
class _FinancialSummaryHeader extends StatelessWidget {
  const _FinancialSummaryHeader({required this.summary});
  final FinancialSummary summary;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: const Color(0xFF123451),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFF24577B)),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        SubscriberExperienceStrings.generalBalance,
                        style: theme.textTheme.labelLarge?.copyWith(
                          color: const Color(0xFFCCE6F7),
                        ),
                      ),
                      const Gap(6),
                      _SubscriberMoney(
                        value: summary.generalBalance,
                        color: Colors.white,
                        fontSize: 32,
                      ),
                      const Gap(6),
                      Text(
                        SubscriberExperienceStrings.generalHint,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: const Color(0xFFCCE6F7),
                        ),
                      ),
                    ],
                  ),
                ),
                const Gap(12),
                const Icon(
                  Icons.account_balance_wallet_outlined,
                  color: Color(0xFF7BD9F1),
                  size: 30,
                ),
              ],
            ),
          ),
          const Gap(10),
          LayoutBuilder(
            builder: (context, constraints) {
              final largeText = MediaQuery.textScalerOf(context).scale(14) > 19;
              final columns = constraints.maxWidth < 330 || largeText ? 1 : 2;
              final width =
                  (constraints.maxWidth - 8 * (columns - 1)) / columns;
              return Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  SizedBox(
                    width: width,
                    child: _HeroStat(
                      label: SubscriberExperienceStrings.totalCredit,
                      value: summary.totalCredit,
                      icon: Icons.south_west_rounded,
                      color: AppSemantic.resolve(context, AppColors.success),
                    ),
                  ),
                  SizedBox(
                    width: width,
                    child: _HeroStat(
                      label: SubscriberExperienceStrings.totalDebit,
                      value: summary.totalDebit,
                      icon: Icons.north_east_rounded,
                      color: AppSemantic.resolve(context, AppColors.danger),
                    ),
                  ),
                ],
              );
            },
          ),
          if (summary.meterBalances.isNotEmpty) ...[
            const Gap(10),
            ExpansionTile(
              tilePadding: const EdgeInsets.symmetric(horizontal: 12),
              childrenPadding: const EdgeInsetsDirectional.fromSTEB(
                12,
                0,
                12,
                12,
              ),
              collapsedShape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: BorderSide(color: scheme.outlineVariant),
              ),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: BorderSide(color: scheme.outlineVariant),
              ),
              leading: Icon(Icons.speed_outlined, color: scheme.primary),
              title: Text(
                SubscriberExperienceStrings.isolatedMeters,
                style: theme.textTheme.labelLarge,
              ),
              children: [
                for (final meter in summary.meterBalances)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(
                          '${SubscriberExperienceStrings.meterTitle(meter.meterId)} • ${meter.uniqueCode}',
                          style: theme.textTheme.labelMedium,
                        ),
                        const Gap(5),
                        Wrap(
                          spacing: 16,
                          runSpacing: 5,
                          children: [
                            _MeterAmount(
                              label: SubscriberExperienceStrings.credit,
                              amount: meter.credit,
                            ),
                            _MeterAmount(
                              label: SubscriberExperienceStrings.debit,
                              amount: meter.debit,
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _MeterAmount extends StatelessWidget {
  const _MeterAmount({required this.label, required this.amount});
  final String label;
  final double amount;
  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Text(label, style: Theme.of(context).textTheme.bodySmall),
      const Gap(6),
      _SubscriberMoney(value: amount, fontSize: 13),
    ],
  );
}

class _HeroStat extends StatelessWidget {
  const _HeroStat({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });
  final String label;
  final double value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => AppCard(
    radius: 12,
    padding: const EdgeInsets.all(12),
    child: LayoutBuilder(
      builder: (context, constraints) {
        if (constraints.maxWidth > 200) {
          return Row(
            children: [
              Icon(icon, size: 18, color: color),
              const Gap(8),
              Expanded(
                child: Text(
                  label,
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ),
              const Gap(8),
              Flexible(
                child: _SubscriberMoney(
                  value: value,
                  color: color,
                  fontSize: 19,
                ),
              ),
            ],
          );
        }
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 17, color: color),
            const Gap(8),
            Text(label, style: Theme.of(context).textTheme.labelSmall),
            const Gap(6),
            _SubscriberMoney(value: value, color: color, fontSize: 17),
          ],
        );
      },
    ),
  );
}

/// Isolates the numeral direction while retaining Arabic currency direction.
class _SubscriberMoney extends StatelessWidget {
  const _SubscriberMoney({required this.value, this.color, this.fontSize = 18});
  final double value;
  final Color? color;
  final double fontSize;
  @override
  Widget build(BuildContext context) => Wrap(
    spacing: 5,
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      Directionality(
        textDirection: TextDirection.ltr,
        child: Text(
          CurrencyFormatter.format(
            value == 0 ? 0 : value,
          ).replaceAll(' ريال', ''),
          style: TextStyle(
            color: color,
            fontSize: fontSize,
            fontWeight: FontWeight.w700,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ),
      Text(
        SubscriberExperienceStrings.currency,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w500,
        ),
      ),
    ],
  );
}

typedef Subscriber360View = Subscriber360;

class _SubscriberEmptyState extends StatelessWidget {
  const _SubscriberEmptyState({required this.title, required this.icon});
  final String title;
  final IconData icon;
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SingleChildScrollView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 24, 16, 24),
      child: AppCard(
        radius: 16,
        padding: const EdgeInsets.all(20),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AppGlyph(icon: icon, size: 44),
            const Gap(14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: theme.textTheme.titleMedium),
                  const Gap(6),
                  Text(
                    SubscriberExperienceStrings.emptyHint,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
