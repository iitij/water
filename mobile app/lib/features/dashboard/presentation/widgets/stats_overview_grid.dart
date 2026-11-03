import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/l10n/dashboard_metric_strings.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';

/// Four headline KPIs. Kept intentionally light so it can sit on the dashboard
/// and on the overview page without a second implementation.
class StatsOverviewGrid extends StatelessWidget {
  const StatsOverviewGrid({required this.stats, required this.can, super.key});

  final DashboardStats stats;
  final bool Function(String) can;

  @override
  Widget build(BuildContext context) {
    VoidCallback? open(String path, Map<String, String> query, bool allowed) =>
        allowed
        ? () => context.push(Uri(path: path, queryParameters: query).toString())
        : null;
    final canViewInvoices = can('invoices.view');
    final now = DateTime.now();
    String date(DateTime value) => value.toIso8601String().split('T').first;
    return AppMetricGrid(
      children: <Widget>[
        AppMetricCard(
          title: DashboardMetricStrings.activeSubscribers,
          onTap: open(
            RouteNames.subscribers,
            {'status': 'active'},
            can('ViewAny:Subscriber') || can('View:Subscriber'),
          ),
          value: AdminFormat.number(stats.activeSubscribersCount),
          icon: Icons.groups_rounded,
          color: AppColors.primary,
          sparkline: stats.subscribersSparkline,
          caption: DashboardMetricStrings.totalSubscribers(
            AdminFormat.number(stats.totalSubscribersCount),
          ),
        ),
        AppMetricCard(
          title: DashboardMetricStrings.outstanding,
          onTap: open(RouteNames.invoices, {
            'status': 'unpaid',
          }, canViewInvoices),
          value: CurrencyFormatter.amount(stats.outstandingAmount),
          unit: DashboardMetricStrings.currencyShort,
          icon: Icons.account_balance_wallet_outlined,
          color: AppColors.danger,
          caption: DashboardMetricStrings.unpaidInvoices(
            AdminFormat.number(stats.unpaidInvoicesCount),
          ),
        ),
        AppMetricCard(
          title: DashboardMetricStrings.monthlyRevenue,
          onTap: open(RouteNames.payments, {
            'from': date(DateTime(now.year, now.month)),
            'to': date(DateTime(now.year, now.month + 1, 0)),
          }, can('payments.view')),
          value: CurrencyFormatter.amount(stats.monthlyCollection),
          unit: DashboardMetricStrings.currencyShort,
          icon: Icons.payments_rounded,
          color: AppColors.success,
          sparkline: stats.collectionSparkline,
          caption: DashboardMetricStrings.monthlyReadings(
            AdminFormat.number(stats.readingsThisMonth),
          ),
        ),
        AppMetricCard(
          title: DashboardMetricStrings.overdueInvoices,
          onTap: open(RouteNames.invoices, {
            'status': 'overdue',
          }, canViewInvoices),
          value: AdminFormat.number(stats.overdueInvoicesCount),
          icon: Icons.schedule_rounded,
          color: stats.overdueInvoicesCount > 0
              ? AppColors.warning
              : AppColors.success,
          caption: stats.overdueInvoicesCount > 0
              ? DashboardMetricStrings.needsFollowUp
              : DashboardMetricStrings.noOverdue,
        ),
      ],
    );
  }
}
