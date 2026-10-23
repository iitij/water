import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../../core/widgets/shimmer_skeleton.dart';
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
    return Scaffold(
      appBar: AppBar(
        title: const Text('التقارير المالية والتحليلية'),
        actions: [
          IconButton(
            tooltip: 'تحديد الفترة',
            onPressed: () => _selectPeriod(context),
            icon: const Icon(Icons.date_range_rounded),
          ),
          IconButton(
            tooltip: 'تحديث',
            onPressed: () => context.read<ReportsCubit>().refresh(),
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: BlocBuilder<ReportsCubit, ReportsState>(
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
          return RefreshIndicator(
            onRefresh: () => context.read<ReportsCubit>().refresh(),
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1400),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _ReportToolbar(filter: state.filter, onSelect: () => _selectPeriod(context)),
                      if (state.status == ReportsRequestStatus.loading)
                        const Padding(
                          padding: EdgeInsets.only(top: 12),
                          child: ShimmerSkeleton(height: 8),
                        ),
                      if (snapshot.isFromCache)
                        Padding(
                          padding: const EdgeInsets.only(top: 12),
                          child: Text(
                            'تقرير مخزن • ${AdminFormat.dateTime(snapshot.updatedAt)}',
                            style: const TextStyle(color: AdminPalette.warning),
                          ),
                        ),
                      const SizedBox(height: 16),
                      AdminResponsiveGrid(
                        minItemWidth: 210,
                        childAspectRatio: 1.65,
                        children: [
                          _ReportMetric(
                            title: 'إجمالي الفواتير',
                            value: AdminFormat.money(report.totalInvoiced),
                            caption: '${AdminFormat.number(report.invoicesCount)} فاتورة',
                            icon: Icons.receipt_long_outlined,
                            color: AdminPalette.info,
                          ),
                          _ReportMetric(
                            title: 'إجمالي التحصيل',
                            value: AdminFormat.money(report.totalCollected),
                            caption: 'نسبة التحصيل ${AdminFormat.number(report.collectionRate)}%',
                            icon: Icons.payments_outlined,
                            color: AdminPalette.success,
                          ),
                          _ReportMetric(
                            title: 'الرصيد المستحق',
                            value: AdminFormat.money(report.totalOutstanding),
                            caption: '${AdminFormat.number(report.invoicesCount - report.paidInvoicesCount)} غير مسددة',
                            icon: Icons.account_balance_wallet_outlined,
                            color: AdminPalette.warning,
                          ),
                          _ReportMetric(
                            title: 'المتأخرات',
                            value: AdminFormat.money(report.overdueAmount),
                            caption: '${AdminFormat.number(report.overdueInvoicesCount)} فاتورة متأخرة',
                            icon: Icons.warning_amber_rounded,
                            color: AdminPalette.danger,
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
                  ),
                ),
              ),
            ),
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

class _ReportToolbar extends StatelessWidget {
  const _ReportToolbar({required this.filter, required this.onSelect});

  final ReportFilter filter;
  final VoidCallback onSelect;

  @override
  Widget build(BuildContext context) {
    final label = filter.from == null && filter.to == null
        ? 'كل الفترات'
        : '${AdminFormat.date(filter.from)} ← ${AdminFormat.date(filter.to)}';
    return AdminCard(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Wrap(
        spacing: 12,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          const Icon(Icons.filter_alt_outlined, color: AdminPalette.primary),
          Text(label, style: const TextStyle(fontWeight: FontWeight.w700)),
          OutlinedButton.icon(
            onPressed: onSelect,
            icon: const Icon(Icons.date_range_outlined),
            label: const Text('تغيير الفترة'),
          ),
          if (filter.from != null || filter.to != null)
            TextButton(
              onPressed: () => context.read<ReportsCubit>().load(const ReportFilter()),
              child: const Text('كل الفترات'),
            ),
        ],
      ),
    );
  }
}

class _ReportMetric extends StatelessWidget {
  const _ReportMetric({
    required this.title,
    required this.value,
    required this.caption,
    required this.icon,
    required this.color,
  });

  final String title;
  final String value;
  final String caption;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return AdminCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: color),
          const Spacer(),
          Text(title, style: Theme.of(context).textTheme.labelLarge),
          const SizedBox(height: 4),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: LtrText(
              value,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
            ),
          ),
          const SizedBox(height: 4),
          Text(caption, maxLines: 1, overflow: TextOverflow.ellipsis),
        ],
      ),
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
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    LinearProgressIndicator(
                      value: fraction,
                      minHeight: 7,
                      borderRadius: BorderRadius.circular(99),
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
