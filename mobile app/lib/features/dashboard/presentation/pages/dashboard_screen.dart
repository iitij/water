import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/repositories/dashboard_repository.dart';
import '../cubit/dashboard_cubit.dart';
import '../widgets/custom_account_banner.dart';
import '../widgets/invoice_sections.dart';
import '../widgets/monthly_revenue_chart_card.dart';
import '../widgets/stats_overview_grid.dart';

class DashboardScreen extends StatelessWidget {
  const DashboardScreen({
    required this.repository,
    required this.userName,
    required this.roleLabel,
    required this.can,
    required this.onCollectPayment,
    this.avatarUrl,
    this.isOnline = true,
    this.pendingSyncCount = 0,
    this.onAddReading,
    this.onAddSubscriber,
    this.onOpenInvoice,
    this.onSendReminder,
    this.onOpenReports,
    super.key,
  });

  final DashboardRepository repository;
  final String userName;
  final String roleLabel;
  final String? avatarUrl;
  final bool isOnline;
  final int pendingSyncCount;
  final AdminPermissionCheck can;
  final VoidCallback? onAddReading;
  final VoidCallback? onAddSubscriber;
  final DashboardInvoiceCallback? onOpenInvoice;
  final DashboardPaymentLauncher onCollectPayment;
  final DashboardReminderLauncher? onSendReminder;
  final VoidCallback? onOpenReports;

  @override
  Widget build(BuildContext context) {
    return BlocProvider(
      create: (_) => DashboardCubit(repository)..load(),
      child: _DashboardView(
        userName: userName,
        roleLabel: roleLabel,
        avatarUrl: avatarUrl,
        isOnline: isOnline,
        pendingSyncCount: pendingSyncCount,
        can: can,
        onAddReading: onAddReading,
        onAddSubscriber: onAddSubscriber,
        onOpenInvoice: onOpenInvoice,
        onCollectPayment: onCollectPayment,
        onSendReminder: onSendReminder,
        onOpenReports: onOpenReports,
      ),
    );
  }
}

class _DashboardView extends StatelessWidget {
  const _DashboardView({
    required this.userName,
    required this.roleLabel,
    required this.can,
    required this.onCollectPayment,
    this.avatarUrl,
    required this.isOnline,
    required this.pendingSyncCount,
    this.onAddReading,
    this.onAddSubscriber,
    this.onOpenInvoice,
    this.onSendReminder,
    this.onOpenReports,
  });

  final String userName;
  final String roleLabel;
  final String? avatarUrl;
  final bool isOnline;
  final int pendingSyncCount;
  final AdminPermissionCheck can;
  final VoidCallback? onAddReading;
  final VoidCallback? onAddSubscriber;
  final DashboardInvoiceCallback? onOpenInvoice;
  final DashboardPaymentLauncher onCollectPayment;
  final DashboardReminderLauncher? onSendReminder;
  final VoidCallback? onOpenReports;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('لوحة القيادة'),
        actions: [
          if (onOpenReports != null)
            IconButton(
              tooltip: 'التقارير المالية',
              onPressed: onOpenReports,
              icon: const Icon(Icons.analytics_outlined),
            ),
          IconButton(
            tooltip: 'تحديث',
            onPressed: () => context.read<DashboardCubit>().refresh(),
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: BlocConsumer<DashboardCubit, DashboardState>(
        listenWhen: (previous, current) =>
            current.errorMessage != null && previous.errorMessage != current.errorMessage,
        listener: (context, state) => showAdminSnackBar(
          context,
          adminErrorMessage(state.errorMessage!),
          isError: true,
        ),
        builder: (context, state) {
          if (state.status == DashboardRequestStatus.loading && state.snapshot == null) {
            return const Padding(
              padding: EdgeInsets.all(20),
              child: AdminLoadingView(itemCount: 4),
            );
          }
          if (state.status == DashboardRequestStatus.failure && state.snapshot == null) {
            return AdminErrorView(
              message: adminErrorMessage(state.errorMessage ?? 'تعذر تحميل لوحة القيادة.'),
              onRetry: () => context.read<DashboardCubit>().load(forceRefresh: true),
            );
          }
          final snapshot = state.snapshot!;
          final data = snapshot.data;
          return RefreshIndicator(
            onRefresh: () => context.read<DashboardCubit>().refresh(),
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1500),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      AdminPermissionGuard(
                        permission: AdminPermissions.viewDashboard,
                        can: can,
                        child: CustomAccountBanner(
                          userName: userName,
                          roleLabel: roleLabel,
                          avatarUrl: avatarUrl,
                          isOnline: isOnline,
                          pendingSyncCount: pendingSyncCount,
                          onAddReading: onAddReading,
                          onAddSubscriber: onAddSubscriber,
                        ),
                      ),
                      if (snapshot.isFromCache) ...[
                        const SizedBox(height: 12),
                        _CacheBanner(updatedAt: snapshot.updatedAt),
                      ],
                      const SizedBox(height: 16),
                      AdminPermissionGuard(
                        permission: AdminPermissions.viewStats,
                        can: can,
                        child: StatsOverviewGrid(stats: data.stats),
                      ),
                      const SizedBox(height: 16),
                      AdminPermissionGuard(
                        permission: AdminPermissions.viewRevenue,
                        can: can,
                        child: MonthlyRevenueChartCard(revenue: data.monthlyRevenue),
                      ),
                      const SizedBox(height: 16),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final latest = AdminPermissionGuard(
                            permission: AdminPermissions.viewLatestInvoices,
                            can: can,
                            child: LatestInvoicesSection(
                              invoices: data.latestInvoices,
                              onOpenInvoice: onOpenInvoice,
                            ),
                          );
                          final overdue = AdminPermissionGuard(
                            permission: AdminPermissions.viewOverdueInvoices,
                            can: can,
                            child: OverdueInvoicesSection(
                              invoices: data.overdueInvoices,
                              onOpenInvoice: onOpenInvoice,
                              onCollectPayment: !can(AdminPermissions.createPayment)
                                  ? null
                                  : (sheetContext, invoice) async {
                                      final collected =
                                          await onCollectPayment(sheetContext, invoice);
                                      if (collected && context.mounted) {
                                        await context.read<DashboardCubit>().refresh();
                                      }
                                      return collected;
                                    },
                              onSendReminder: onSendReminder,
                              isRefreshing: state.isRefreshing,
                            ),
                          );
                          if (constraints.maxWidth < 1050) {
                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [latest, const SizedBox(height: 16), overdue],
                            );
                          }
                          return Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(child: latest),
                              const SizedBox(width: 16),
                              Expanded(child: overdue),
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
}

class _CacheBanner extends StatelessWidget {
  const _CacheBanner({required this.updatedAt});

  final DateTime updatedAt;

  @override
  Widget build(BuildContext context) {
    return MaterialBanner(
      content: Text('بيانات مخزنة منذ ${AdminFormat.dateTime(updatedAt)}'),
      leading: const Icon(Icons.offline_bolt_outlined),
      actions: const [SizedBox.shrink()],
    );
  }
}
