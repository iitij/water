import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/l10n/laravel_screen_strings.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/repositories/dashboard_repository.dart';
import '../cubit/dashboard_cubit.dart';
import '../widgets/collection_progress_card.dart';
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
    return SubPageScaffold(
      title: LaravelScreenStrings.dashboard,
      subtitle: 'مؤشرات حية من الخادم',
      actions: <Widget>[
        if (onOpenReports != null) ...<Widget>[
          AppIconButton(
            icon: Icons.analytics_outlined,
            tooltip: 'التقارير المالية',
            onPressed: onOpenReports,
            color: AppColors.info,
          ),
          const SizedBox(width: 8),
        ],
        AppIconButton(
          icon: Icons.refresh_rounded,
          tooltip: 'تحديث البيانات',
          onPressed: () => context.read<DashboardCubit>().refresh(),
          color: AppColors.primary,
        ),
      ],
      child: BlocConsumer<DashboardCubit, DashboardState>(
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
              padding: EdgeInsets.all(AppSpacing.md),
              child: AdminLoadingView(itemCount: 4),
            );
          }
          if (state.status == DashboardRequestStatus.failure && state.snapshot == null) {
            return AdminErrorView(
              message: adminErrorMessage(state.errorMessage ?? 'تعذر تحميل ${LaravelScreenStrings.dashboard}.'),
              onRetry: () => context.read<DashboardCubit>().load(forceRefresh: true),
            );
          }
          final snapshot = state.snapshot!;
          final data = snapshot.data;

          return AppPageBody(
            onRefresh: () => context.read<DashboardCubit>().refresh(),
            children: <Widget>[
              AppReveal(
                child: AdminPermissionGuard(
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
              ),
              if (snapshot.isFromCache) ...<Widget>[
                const Gap(AppSpacing.md),
                AppNotice(
                  title: 'أنت تعرض نسخة محفوظة',
                  message: 'آخر تحديث ${AdminFormat.dateTime(snapshot.updatedAt)} — سيتم التحديث عند عودة الاتصال.',
                  icon: Icons.offline_bolt_outlined,
                  color: AppColors.warning,
                ),
              ],
              const Gap(AppSpacing.xl),
              AppReveal(
                delay: const Duration(milliseconds: 60),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    const SectionHeading(
                      title: 'المؤشرات الرئيسية',
                      subtitle: 'نظرة فورية على أداء المشروع',
                    ),
                    const Gap(AppSpacing.sm),
                    AdminPermissionGuard(
                      permission: AdminPermissions.viewStats,
                      can: can,
                      child: StatsOverviewGrid(stats: data.stats, can: can),
                    ),
                  ],
                ),
              ),
              const Gap(AppSpacing.lg),
              AppReveal(
                delay: const Duration(milliseconds: 100),
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final chart = AdminPermissionGuard(
                      permission: AdminPermissions.viewRevenue,
                      can: can,
                      child: MonthlyRevenueChartCard(revenue: data.monthlyRevenue),
                    );
                    final collection = AdminPermissionGuard(
                      permission: AdminPermissions.viewStats,
                      can: can,
                      child: CollectionProgressCard(
                        stats: data.stats,
                        onOpenReports: onOpenReports,
                      ),
                    );
                    if (constraints.maxWidth < 1020) {
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: <Widget>[chart, const Gap(AppSpacing.md), collection],
                      );
                    }
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Expanded(flex: 3, child: chart),
                        const SizedBox(width: AppSpacing.md),
                        Expanded(flex: 2, child: collection),
                      ],
                    );
                  },
                ),
              ),
              const Gap(AppSpacing.lg),
              AppReveal(
                delay: const Duration(milliseconds: 140),
                child: LayoutBuilder(
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
                                final collected = await onCollectPayment(sheetContext, invoice);
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
                        children: <Widget>[latest, const Gap(AppSpacing.md), overdue],
                      );
                    }
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Expanded(child: latest),
                        const SizedBox(width: AppSpacing.md),
                        Expanded(child: overdue),
                      ],
                    );
                  },
                ),
              ),
              if (data.overdueInvoices.isNotEmpty) ...<Widget>[
                const Gap(AppSpacing.md),
                AppReveal(
                  delay: const Duration(milliseconds: 180),
                  child: InsightCard(
                    title: '${data.overdueInvoices.length} فاتورة تحتاج متابعة',
                    message: 'يمكنك التحصيل الفوري أو إرسال تذكير واتساب من القائمة أعلاه.',
                    icon: Icons.bolt_rounded,
                    color: AppColors.warning,
                    actionLabel: onOpenReports == null ? null : 'التقارير',
                    onAction: onOpenReports,
                  ),
                ),
              ],
              const Gap(AppSpacing.xxl),
            ],
          );
        },
      ),
    );
  }
}
