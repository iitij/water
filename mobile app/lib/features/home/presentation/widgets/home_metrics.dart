part of '../home_screen.dart';

class _QuickActionsCard extends StatelessWidget {
  const _QuickActionsCard({
    required this.onReading,
    required this.onCollect,
    required this.onInvoice,
    required this.onSubscriber,
  });

  final VoidCallback onReading;
  final VoidCallback onCollect;
  final VoidCallback? onInvoice;
  final VoidCallback? onSubscriber;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
      child: AppQuickActionBar(
        actions: <Widget>[
          AppQuickAction(
            label: 'قراءة',
            icon: Icons.speed_rounded,
            color: AppColors.primary,
            onTap: onReading,
          ),
          AppQuickAction(
            label: 'تحصيل',
            icon: Icons.point_of_sale_rounded,
            color: AppColors.primary,
            onTap: onCollect,
          ),
          if (onInvoice != null)
            AppQuickAction(
              label: 'فاتورة',
              icon: Icons.receipt_long_rounded,
              color: AppColors.primary,
              onTap: onInvoice!,
            ),
          if (onSubscriber != null)
            AppQuickAction(
              label: 'مشترك',
              icon: Icons.person_add_alt_1_rounded,
              color: AppColors.primary,
              onTap: onSubscriber!,
            ),
        ],
      ),
    );
  }
}

class _WorkspaceMetrics extends StatelessWidget {
  const _WorkspaceMetrics({required this.queue});

  final SyncQueueManager queue;

  @override
  Widget build(BuildContext context) {
    final permissions = GetIt.I<PermissionService>();
    final cards = <Widget>[
      AppMetricCard(
        title: 'بانتظار المزامنة',
        value: '${queue.pendingCount}',
        icon: Icons.cloud_upload_outlined,
        color: AppColors.primary,
        caption: queue.failedCount > 0 ? '${queue.failedCount} فاشلة' : 'جاهز للمزامنة',
      ),
      AppMetricCard(
        title: 'حالة الاتصال',
        value: queue.isOnline == true
            ? 'متصل'
            : queue.isOnline == false
                ? 'أوفلاين'
                : 'فحص',
        icon: queue.isOnline == true ? Icons.wifi_rounded : Icons.wifi_off_rounded,
        color: AppColors.primary,
        caption: 'الخادم الرئيسي',
      ),
      AppMetricCard(
        title: 'صلاحيات حسابك',
        value: permissions.isAdmin ? 'كاملة' : '${permissions.currentPermissions.length}',
        icon: Icons.verified_user_outlined,
        color: AppColors.primary,
        caption: permissions.isAdmin ? 'مدير النظام' : 'صلاحية مفعّلة',
      ),
      AppMetricCard(
        title: 'الدور الوظيفي',
        value: permissions.isAdmin
            ? 'مدير'
            : permissions.isCollector
                ? 'محصل'
                : permissions.isReader
                    ? 'قارئ'
                    : 'موظف',
        icon: Icons.badge_outlined,
        color: AppColors.primary,
        caption: 'مساحة العمل',
      ),
    ];
    return AppMetricGrid(children: cards);
  }
}

class _LiveMetrics extends StatelessWidget {
  const _LiveMetrics({required this.cubit});

  final DashboardCubit cubit;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<DashboardCubit, DashboardState>(
      buildWhen: (previous, current) =>
          previous.snapshot != current.snapshot || previous.status != current.status,
      builder: (context, state) {
        final snapshot = state.snapshot;
        if (snapshot == null) {
          if (state.status == DashboardRequestStatus.failure) {
            return AppNotice(
              title: 'تعذر تحميل الأرقام',
              message: 'قد تكون غير متصل بالشبكة. بقية مساحة العمل تعمل بشكل طبيعي.',
              icon: Icons.cloud_off_rounded,
              color: AppColors.warning,
              action: TextButton(
                onPressed: () => cubit.load(forceRefresh: true),
                style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 28)),
                child: const Text('إعادة المحاولة'),
              ),
            );
          }
          return const _MetricsSkeleton();
        }

        return StatsOverviewGrid(
          stats: snapshot.data.stats,
          can: GetIt.I<PermissionService>().can,
        );
      },
    );
  }
}

class _MetricsSkeleton extends StatelessWidget {
  const _MetricsSkeleton();

  @override
  Widget build(BuildContext context) {
    return const AppMetricGrid(
      children: <Widget>[
        ShimmerSkeleton(height: 118, radius: 18),
        ShimmerSkeleton(height: 118, radius: 18),
        ShimmerSkeleton(height: 118, radius: 18),
        ShimmerSkeleton(height: 118, radius: 18),
      ],
    );
  }
}

class _DashboardExtras extends StatelessWidget {
  const _DashboardExtras({
    required this.cubit,
    required this.canViewRevenue,
    required this.canViewStats,
    required this.onOpenReports,
  });

  final DashboardCubit cubit;
  final bool canViewRevenue;
  final bool canViewStats;
  final VoidCallback onOpenReports;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<DashboardCubit, DashboardState>(
      buildWhen: (previous, current) => previous.snapshot != current.snapshot,
      builder: (context, state) {
        final snapshot = state.snapshot;
        if (snapshot == null) return const SizedBox.shrink();
        final data = snapshot.data;
        final chart = canViewRevenue && data.monthlyRevenue.length > 0
            ? MonthlyRevenueChartCard(revenue: data.monthlyRevenue)
            : null;
        final collection = canViewStats
            ? CollectionProgressCard(stats: data.stats, onOpenReports: onOpenReports)
            : null;
        if (chart == null && collection == null) {
          return const SizedBox.shrink();
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (chart != null)
              LayoutBuilder(
                builder: (context, constraints) {
                  if (constraints.maxWidth < 980) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        chart,
                        if (collection != null) ...<Widget>[
                          const Gap(AppSpacing.md),
                          collection,
                        ],
                      ],
                    );
                  }
                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Expanded(flex: 3, child: chart),
                      const SizedBox(width: AppSpacing.md),
                      if (collection != null) Expanded(flex: 2, child: collection),
                    ],
                  );
                },
              )
            else if (collection != null)
              collection,
            if (data.overdueInvoices.isNotEmpty) ...<Widget>[
              const Gap(AppSpacing.sm),
              InsightCard(
                title: '${data.overdueInvoices.length} فاتورة تحتاج متابعة',
                message: 'ابدأ بالتحصيل أو أرسل تذكيراً عبر واتساب بضغطة واحدة.',
                icon: Icons.notifications_active_rounded,
                color: AppColors.warning,
                actionLabel: 'التحصيل',
                onAction: () => context.push(RouteNames.quickCollector),
              ),
            ],
          ],
        );
      },
    );
  }
}

/// Dependency-free view, also used to test narrow, RTL and large-text layouts.
