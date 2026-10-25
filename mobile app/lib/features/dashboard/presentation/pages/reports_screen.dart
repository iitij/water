import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';
import '../../domain/repositories/dashboard_repository.dart';
import '../cubit/reports_cubit.dart';
import '../widgets/monthly_revenue_chart_card.dart';

class ReportsScreen extends StatelessWidget {
  const ReportsScreen({required this.repository, super.key});

  final DashboardRepository repository;

  @override
  Widget build(BuildContext context) {
    return BlocProvider(
      create: (_) => ReportsCubit(repository)..load(),
      child: const _ReportsView(),
    );
  }
}

class _ReportsView extends StatelessWidget {
  const _ReportsView();

  @override
  Widget build(BuildContext context) {
    return SubPageScaffold(
      title: 'التقارير المالية والتحليلية',
      subtitle: 'حركة الإيرادات والتحصيل حسب الفترة',
      actions: [
        AppIconButton(
          tooltip: 'تحديد الفترة',
          onPressed: () => _selectPeriod(context),
          icon: Icons.date_range_rounded,
        ),
        AppIconButton(
          tooltip: 'تحديث',
          onPressed: () => context.read<ReportsCubit>().refresh(),
          icon: Icons.refresh_rounded,
        ),
      ],
      child: BlocBuilder<ReportsCubit, ReportsState>(
        builder: (context, state) {
          if (state.status == ReportsRequestStatus.loading && state.snapshot == null) {
            return const Padding(padding: EdgeInsets.all(20), child: AdminLoadingView());
          }
          if (state.status == ReportsRequestStatus.failure && state.snapshot == null) {
            return AdminErrorView(
              message: adminErrorMessage(state.errorMessage ?? 'تعذر تحميل التقرير.'),
              onRetry: () => context.read<ReportsCubit>().load(),
            );
          }
          final snapshot = state.snapshot!;
          final report = snapshot.report;
          return AppPageBody(
            maxWidth: 1400,
            onRefresh: () => context.read<ReportsCubit>().refresh(),
            children: [
              _ReportToolbar(filter: state.filter, onSelect: () => _selectPeriod(context)),
              if (state.status == ReportsRequestStatus.loading)
                const Padding(
                  padding: EdgeInsets.only(top: 12),
                  child: ShimmerSkeleton(height: 8, radius: 8),
                ),
              if (snapshot.isFromCache)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: AppNotice(
                    title: 'تقرير مخزن',
                    message: 'آخر تحديث ${AdminFormat.dateTime(snapshot.updatedAt)} — قد تكون الأرقام أقدم من لحظة العرض.',
                    icon: Icons.history_rounded,
                    color: AppColors.warning,
                  ),
                ),
              const SizedBox(height: 16),
              AppReveal(
                child: _ReportHero(report: report),
              ),
              const SizedBox(height: 16),
              AdminResponsiveGrid(
                minItemWidth: 210,
                childAspectRatio: 1.65,
                children: [
                  AppMetricCard(
                    title: 'إجمالي الفواتير',
                    value: AdminFormat.money(report.totalInvoiced),
                    caption: '${AdminFormat.number(report.invoicesCount)} فاتورة',
                    icon: Icons.receipt_long_outlined,
                    color: AppColors.info,
                  ),
                  AppMetricCard(
                    title: 'إجمالي التحصيل',
                    value: AdminFormat.money(report.totalCollected),
                    caption: 'نسبة التحصيل ${AdminFormat.number(report.collectionRate)}%',
                    icon: Icons.payments_outlined,
                    color: AppColors.success,
                  ),
                  AppMetricCard(
                    title: 'الرصيد المستحق',
                    value: AdminFormat.money(report.totalOutstanding),
                    caption: '${AdminFormat.number(report.invoicesCount - report.paidInvoicesCount)} غير مسددة',
                    icon: Icons.account_balance_wallet_outlined,
                    color: AppColors.warning,
                  ),
                  AppMetricCard(
                    title: 'المتأخرات',
                    value: AdminFormat.money(report.overdueAmount),
                    caption: '${AdminFormat.number(report.overdueInvoicesCount)} فاتورة متأخرة',
                    icon: Icons.warning_amber_rounded,
                    color: AppColors.danger,
                  ),
                ],
              ),
              const SizedBox(height: 16),
              MonthlyRevenueChartCard(
                title: 'حركة الإيرادات',
                subtitle: 'قيم التحصيل خلال الفترة المحددة',
                revenue: MonthlyRevenue(
                  labels: report.revenueSeries.map((point) => point.label).toList(),
                  values: report.revenueSeries.map((point) => point.value).toList(),
                ),
              ),
              const SizedBox(height: 16),
              LayoutBuilder(
                builder: (context, constraints) {
                  final statuses = _BreakdownCard(
                    title: 'الفواتير حسب الحالة',
                    items: report.statusBreakdown,
                    total: report.totalInvoiced,
                  );
                  final squares = _BreakdownCard(
                    title: 'التحصيل حسب المربع',
                    items: report.squareBreakdown,
                    total: report.squareBreakdown.fold<double>(
                      0,
                      (sum, item) => sum + item.value,
                    ),
                  );
                  if (constraints.maxWidth < 950) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [statuses, const SizedBox(height: 16), squares],
                    );
                  }
                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(child: statuses),
                      const SizedBox(width: 16),
                      Expanded(child: squares),
                    ],
                  );
                },
              ),
              const SizedBox(height: 32),
            ],
          );
        },
      ),
    );
  }

  Future<void> _selectPeriod(BuildContext context) async {
    final cubit = context.read<ReportsCubit>();
    final current = cubit.state.filter;
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      initialDateRange: current.from != null && current.to != null
          ? DateTimeRange(start: current.from!, end: current.to!)
          : DateTimeRange(start: DateTime(now.year, now.month), end: now),
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year + 1),
      helpText: 'فترة التقرير المالي',
      confirmText: 'عرض التقرير',
      cancelText: 'إلغاء',
    );
    if (range != null) {
      await cubit.load(ReportFilter(from: range.start, to: range.end));
    }
  }
}

/// The page's single headline figure: the outstanding balance for the period.
class _ReportHero extends StatelessWidget {
  const _ReportHero({required this.report});

  final FinancialReport report;

  @override
  Widget build(BuildContext context) {
    return AppHeroPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'الرصيد المستحق',
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AppColors.onPanelMuted,
                  fontWeight: FontWeight.w500,
                ),
          ),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: LtrText(
              AdminFormat.money(report.totalOutstanding),
              style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                    color: AppColors.onPanel,
                    fontWeight: FontWeight.w600,
                    height: 1.15,
                    letterSpacing: -.4,
                    fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                  ),
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              HeroMetaChip(
                icon: Icons.receipt_long_outlined,
                label: '${AdminFormat.number(report.invoicesCount - report.paidInvoicesCount)} غير مسددة',
              ),
              HeroMetaChip(
                icon: Icons.percent_rounded,
                label: 'نسبة التحصيل ${AdminFormat.number(report.collectionRate)}%',
              ),
              HeroMetaChip(
                icon: Icons.warning_amber_rounded,
                label: 'المتأخرات: ${AdminFormat.number(report.overdueInvoicesCount)}',
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ReportToolbar extends StatelessWidget {
  const _ReportToolbar({required this.filter, required this.onSelect});

  final ReportFilter filter;
  final VoidCallback onSelect;

  @override
  Widget build(BuildContext context) {
    final hasRange = filter.from != null || filter.to != null;
    final label = hasRange
        ? '${AdminFormat.date(filter.from)} ← ${AdminFormat.date(filter.to)}'
        : 'كل الفترات';
    return AppFilterChips(
      padding: EdgeInsets.zero,
      children: [
        AppFilterChip(
          label: label,
          icon: Icons.date_range_rounded,
          selected: hasRange,
          onTap: onSelect,
          onRemove: hasRange
              ? () => context.read<ReportsCubit>().load(const ReportFilter())
              : null,
        ),
        AppFilterChip(
          label: 'تغيير الفترة',
          icon: Icons.edit_calendar_rounded,
          onTap: onSelect,
        ),
      ],
    );
  }
}

class _BreakdownCard extends StatelessWidget {
  const _BreakdownCard({required this.title, required this.items, required this.total});

  final String title;
  final List<ReportBreakdown> items;
  final double total;

  @override
  Widget build(BuildContext context) {
    return AdminCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AdminSectionHeader(title: title, icon: Icons.donut_small_outlined),
          const SizedBox(height: 16),
          if (items.isEmpty)
            const AdminEmptyView(title: 'لا توجد بيانات تفصيلية')
          else
            ...items.map((item) {
              final fraction = total <= 0 ? 0.0 : (item.value / total).clamp(0, 1).toDouble();
              final scheme = Theme.of(context).colorScheme;
              return Padding(
                padding: const EdgeInsets.only(bottom: 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Expanded(child: Text(item.label)),
                        LtrText(
                          AdminFormat.money(item.value),
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Container(
                      height: 7,
                      alignment: AlignmentDirectional.centerStart,
                      decoration: BoxDecoration(
                        color: scheme.surfaceContainerHighest,
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                      ),
                      child: FractionallySizedBox(
                        widthFactor: fraction,
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            color: scheme.primary,
                            borderRadius: BorderRadius.circular(AppRadius.pill),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }
}
