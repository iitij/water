import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../../core/l10n/report_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
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
  Widget build(BuildContext context) => BlocProvider(
    create: (_) => ReportsCubit(repository)..load(),
    child: const _ReportsView(),
  );
}

class _ReportsView extends StatelessWidget {
  const _ReportsView();
  @override
  Widget build(BuildContext context) => SubPageScaffold(
    title: ReportStrings.title,
    subtitle: ReportStrings.subtitle,
    actions: [
      AppIconButton(
        tooltip: ReportStrings.refresh,
        icon: Icons.refresh_rounded,
        onPressed: () => context.read<ReportsCubit>().refresh(),
      ),
    ],
    child: BlocBuilder<ReportsCubit, ReportsState>(
      builder: (context, state) {
        final snapshot = state.snapshot;
        final report = snapshot?.report;
        return AppPageBody(
          maxWidth: 1120,
          bottomPadding: 32,
          onRefresh: () => context.read<ReportsCubit>().refresh(),
          children: [
            _ReportToolbar(
              filter: state.filter,
              onSelect: () => _selectPeriod(context),
            ),
            const SizedBox(height: 16),
            if (report == null)
              if (state.status == ReportsRequestStatus.failure)
                AdminErrorView(
                  message: ReportStrings.loadFailed,
                  onRetry: () => context.read<ReportsCubit>().load(),
                )
              else
                const ShimmerSkeleton(height: 120, radius: 12)
            else ...[
              if (state.status == ReportsRequestStatus.loading) ...[
                const ShimmerSkeleton(height: 4, radius: 4),
                const SizedBox(height: 12),
              ],
              if (state.status == ReportsRequestStatus.failure ||
                  snapshot!.isFromCache) ...[
                AppNotice(
                  title: state.status == ReportsRequestStatus.failure
                      ? ReportStrings.failed
                      : ReportStrings.cached,
                  message: ReportStrings.lastUpdate(
                    AdminFormat.dateTime(snapshot!.updatedAt),
                  ),
                  icon: Icons.history_rounded,
                  color: AppColors.warning,
                ),
                const SizedBox(height: 12),
              ],
              _ReportMetrics(report: report),
              const SizedBox(height: 16),
              if (!report.hasDetailedData)
                const AppNotice(
                  title: ReportStrings.detailsUnavailable,
                  message: ReportStrings.totalsOnly,
                  icon: Icons.info_outline_rounded,
                )
              else if (report.invoicesCount == 0 && report.totalCollected == 0)
                const AppNotice(
                  title: ReportStrings.emptyPeriod,
                  message: ReportStrings.chooseAnotherPeriod,
                  icon: Icons.date_range_outlined,
                )
              else ...[
                if (report.revenueSeries.isNotEmpty)
                  MonthlyRevenueChartCard(
                    title: ReportStrings.revenue,
                    subtitle: ReportStrings.revenueSubtitle,
                    averageLabel: ReportStrings.averageDay,
                    bestLabel: ReportStrings.bestDay,
                    chartHeight: 180,
                    showStatistics: false,
                    revenue: MonthlyRevenue(
                      labels: report.revenueSeries.map((p) => p.label).toList(),
                      values: report.revenueSeries.map((p) => p.value).toList(),
                    ),
                  )
                else
                  const AppNotice(
                    title: ReportStrings.noCollections,
                    message: ReportStrings.chooseAnotherPeriod,
                    icon: Icons.payments_outlined,
                  ),
                const SizedBox(height: 16),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final statuses = _BreakdownCard(
                      title: ReportStrings.statuses,
                      emptyLabel: ReportStrings.noInvoices,
                      items: report.statusBreakdown,
                    );
                    final squares = _BreakdownCard(
                      title: ReportStrings.squares,
                      emptyLabel: ReportStrings.noCollections,
                      items: report.squareBreakdown,
                    );
                    if (constraints.maxWidth < 800) {
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          statuses,
                          const SizedBox(height: 12),
                          squares,
                        ],
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
              ],
            ],
          ],
        );
      },
    ),
  );

  Future<void> _selectPeriod(BuildContext context) async {
    final cubit = context.read<ReportsCubit>();
    final filter = cubit.state.filter;
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      initialDateRange: DateTimeRange(
        start: filter.from ?? DateTime(now.year, now.month),
        end: filter.to ?? now,
      ),
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year + 1),
      helpText: ReportStrings.selectPeriod,
      confirmText: ReportStrings.showReport,
      cancelText: ReportStrings.cancel,
    );
    if (range != null && !cubit.isClosed) {
      await cubit.load(ReportFilter(from: range.start, to: range.end));
    }
  }
}

class _ReportToolbar extends StatelessWidget {
  const _ReportToolbar({required this.filter, required this.onSelect});
  final ReportFilter filter;
  final VoidCallback onSelect;
  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final current = ReportsCubit.currentMonth(now);
    final previous = ReportFilter(
      from: DateTime(now.year, now.month - 1),
      to: DateTime(now.year, now.month, 0),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            AppFilterChip(
              label: ReportStrings.thisMonth,
              selected: filter == current,
              onTap: () => context.read<ReportsCubit>().load(current),
            ),
            AppFilterChip(
              label: ReportStrings.previousMonth,
              selected: filter == previous,
              onTap: () => context.read<ReportsCubit>().load(previous),
            ),
            AppFilterChip(
              label: ReportStrings.customPeriod,
              icon: Icons.date_range_rounded,
              selected: filter != current && filter != previous,
              onTap: onSelect,
            ),
          ],
        ),
        const SizedBox(height: 8),
        LtrText(
          '${AdminFormat.date(filter.from)} — ${AdminFormat.date(filter.to)}',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ],
    );
  }
}

class _ReportMetrics extends StatelessWidget {
  const _ReportMetrics({required this.report});
  final FinancialReport report;
  @override
  Widget build(BuildContext context) {
    final metrics = [
      _Metric(
        title: ReportStrings.invoiced,
        value: report.totalInvoiced,
        caption: ReportStrings.invoices(
          AdminFormat.number(report.invoicesCount),
        ),
      ),
      _Metric(
        title: ReportStrings.collected,
        value: report.totalCollected,
        caption: ReportStrings.activeReceipts,
      ),
      if (report.hasDetailedData) ...[
        _Metric(
          title: ReportStrings.outstanding,
          value: report.totalOutstanding,
          caption: ReportStrings.unpaid(
            AdminFormat.number(
              (report.invoicesCount - report.paidInvoicesCount).clamp(
                0,
                report.invoicesCount,
              ),
            ),
          ),
        ),
        _Metric(
          title: ReportStrings.overdue,
          value: report.overdueAmount,
          caption: ReportStrings.invoices(
            AdminFormat.number(report.overdueInvoicesCount),
          ),
        ),
      ],
    ];
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= 900
            ? metrics.length
            : MediaQuery.textScalerOf(context).scale(14) > 20
            ? 1
            : 2;
        final width = (constraints.maxWidth - 12 * (columns - 1)) / columns;
        return Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            for (final metric in metrics) SizedBox(width: width, child: metric),
          ],
        );
      },
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.title,
    required this.value,
    required this.caption,
  });
  final String title;
  final double value;
  final String caption;
  @override
  Widget build(BuildContext context) => AppCard(
    padding: const EdgeInsets.all(12),
    radius: 12,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 8),
        FittedBox(
          fit: BoxFit.scaleDown,
          child: LtrText(
            AdminFormat.money(value),
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w600,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ),
        const SizedBox(height: 4),
        Text(caption, style: Theme.of(context).textTheme.bodySmall),
      ],
    ),
  );
}

class _BreakdownCard extends StatelessWidget {
  const _BreakdownCard({
    required this.title,
    required this.items,
    required this.emptyLabel,
  });
  final String title;
  final List<ReportBreakdown> items;
  final String emptyLabel;
  @override
  Widget build(BuildContext context) {
    final total = items.fold<double>(0, (sum, item) => sum + item.value);
    return AppCard(
      radius: 12,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 16),
          if (items.isEmpty)
            Text(emptyLabel, style: Theme.of(context).textTheme.bodySmall),
          for (final item in items)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Wrap(
                    alignment: WrapAlignment.spaceBetween,
                    spacing: 12,
                    runSpacing: 4,
                    children: [
                      Text(item.label),
                      LtrText(AdminFormat.money(item.value)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  LinearProgressIndicator(
                    value: total <= 0 ? 0 : (item.value / total).clamp(0, 1),
                    minHeight: 4,
                    borderRadius: BorderRadius.circular(4),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
