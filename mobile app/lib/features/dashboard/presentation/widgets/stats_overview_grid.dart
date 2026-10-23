import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';

/// Four headline KPIs. Kept intentionally light so it can sit on the dashboard
/// and on the overview page without a second implementation.
class StatsOverviewGrid extends StatelessWidget {
  const StatsOverviewGrid({required this.stats, super.key});

  final DashboardStats stats;

  @override
  Widget build(BuildContext context) {
    return AppMetricGrid(
      children: <Widget>[
        AppMetricCard(
          title: 'المشتركون النشطون',
          value: AdminFormat.number(stats.activeSubscribersCount),
          icon: Icons.groups_rounded,
          color: AppColors.primary,
          sparkline: stats.subscribersSparkline,
          caption: 'من أصل ${AdminFormat.number(stats.totalSubscribersCount)} مشترك',
        ),
        AppMetricCard(
          title: 'الديون المستحقة',
          value: AdminFormat.compactMoney(stats.outstandingAmount),
          unit: 'ر.ي',
          icon: Icons.account_balance_wallet_outlined,
          color: AppColors.danger,
          caption: '${AdminFormat.number(stats.unpaidInvoicesCount)} فاتورة غير مسددة',
        ),
        AppMetricCard(
          title: 'إيرادات هذا الشهر',
          value: AdminFormat.compactMoney(stats.monthlyCollection),
          unit: 'ر.ي',
          icon: Icons.payments_rounded,
          color: AppColors.success,
          sparkline: stats.collectionSparkline,
          caption: '${AdminFormat.number(stats.readingsThisMonth)} قراءة هذا الشهر',
        ),
        AppMetricCard(
          title: 'الفواتير المتأخرة',
          value: AdminFormat.number(stats.overdueInvoicesCount),
          icon: Icons.schedule_rounded,
          color: stats.overdueInvoicesCount > 0 ? AppColors.warning : AppColors.success,
          caption: stats.overdueInvoicesCount > 0 ? 'تحتاج متابعة عاجلة' : 'لا توجد متأخرات',
        ),
      ],
    );
  }
}
