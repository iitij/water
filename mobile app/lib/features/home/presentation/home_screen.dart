import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/navigation/module_catalog.dart';
import '../../../core/navigation/sub_page.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/router/route_names.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_controls.dart';
import '../../../core/ui/app_motion.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tiles.dart';
import '../../../core/ui/app_tokens.dart';
import '../../../core/utils/arabic_date.dart';
import '../../../core/widgets/shimmer_skeleton.dart';
import '../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../dashboard/domain/repositories/dashboard_repository.dart';
import '../../dashboard/presentation/cubit/dashboard_cubit.dart';
import '../../dashboard/presentation/widgets/collection_progress_card.dart';
import '../../dashboard/presentation/widgets/monthly_revenue_chart_card.dart';
import '../../navigation/presentation/widgets/module_section.dart';
import '../../offline_sync/services/sync_queue_manager.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final _permissions = GetIt.I<PermissionService>();
  DashboardCubit? _dashboard;

  bool get _isAdmin => _permissions.isAdmin;

  bool get _canViewStats =>
      _isAdmin ||
      _permissions.hasAnyPermission(const <String>[
        'View:StatsOverview',
        'View:MonthlyRevenueChart',
        'View:OverdueInvoicesWidget',
        'View:LatestInvoicesWidget',
      ]);

  bool get _canViewDashboard =>
      _isAdmin ||
      _permissions.hasAnyPermission(const <String>[
        'View:CustomAccountWidget',
        'View:StatsOverview',
        'View:MonthlyRevenueChart',
      ]);

  bool get _canViewRevenue => _isAdmin || _permissions.can('View:MonthlyRevenueChart');

  @override
  void initState() {
    super.initState();
    if (_canViewStats) {
      _dashboard = DashboardCubit(GetIt.I<DashboardRepository>())..load();
    }
  }

  @override
  void dispose() {
    _dashboard?.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final session = GetIt.I<SessionService>();
    final queue = GetIt.I<SyncQueueManager>();
    final user = session.user ?? const <String, dynamic>{};
    final name = user['name']?.toString() ?? 'موظف المشروع';
    final firstName = ArabicDate.firstName(name);

    final fieldModules = ModuleCatalog.visible(_permissions, group: AppModuleGroup.field);
    final billingModules = ModuleCatalog.visible(_permissions, group: AppModuleGroup.billing);
    final crmModules = ModuleCatalog.visible(_permissions, group: AppModuleGroup.crm);
    final canInvoices = _isAdmin || _permissions.can('invoices.view');
    final canSubscribers = _isAdmin ||
        _permissions.hasAnyPermission(const <String>['ViewAny:Subscriber', 'View:Subscriber']);
    final canViewStatsWidget = _isAdmin || _permissions.can('View:StatsOverview');
    final insightModules = <AppModule>[
      ...ModuleCatalog.visible(_permissions, group: AppModuleGroup.insights),
      ...ModuleCatalog.visible(_permissions, group: AppModuleGroup.admin),
    ];

    Widget content = AnimatedBuilder(
      animation: queue,
      builder: (context, _) {
        return AppPageBody(
          onRefresh: _dashboard == null ? null : () => _dashboard!.refresh(),
          children: <Widget>[
            AppReveal(
              child: _GreetingHero(
                firstName: firstName,
                roleLabel: _roleLabel(_permissions),
                isOnline: queue.isOnline,
                pendingSync: queue.pendingCount,
                primaryLabel: _primaryLabel(),
                primaryIcon: _primaryIcon(),
                onPrimary: () => context.push(_primaryRoute()),
                canViewDashboard: _canViewDashboard,
                onDashboard: () => context.push(RouteNames.dashboard),
              ),
            ),
            const Gap(AppSpacing.md),
            AppReveal(
              delay: const Duration(milliseconds: 60),
              child: _QuickActionsCard(
                onReading: () => context.push(RouteNames.fieldReader),
                onCollect: () => context.push(RouteNames.quickCollector),
                onInvoice: canInvoices ? () => context.push(RouteNames.invoices) : null,
                onSubscriber:
                    canSubscribers ? () => context.push(RouteNames.subscriberNew) : null,
              ),
            ),
            const Gap(AppSpacing.xl),
            AppReveal(
              delay: const Duration(milliseconds: 90),
              child: SectionHeading(
                title: 'نظرة سريعة',
                subtitle: _dashboard == null
                    ? 'حالة جهازك ومساحة عملك الآن'
                    : 'أرقام محدثة من الخادم',
                trailing: _canViewDashboard
                    ? TextButton(
                        onPressed: () => context.push(RouteNames.dashboard),
                        child: const Text('لوحة القيادة'),
                      )
                    : null,
              ),
            ),
            const Gap(AppSpacing.sm),
            if (_dashboard == null)
              _WorkspaceMetrics(queue: queue)
            else
              _LiveMetrics(cubit: _dashboard!, canViewRevenue: _canViewRevenue),
            if (_dashboard != null) ...<Widget>[
              const Gap(AppSpacing.lg),
              _DashboardExtras(
                cubit: _dashboard!,
                canViewRevenue: _canViewRevenue,
                canViewStats: canViewStatsWidget,
                onOpenReports: () => context.push(RouteNames.reports),
              ),
            ],
            if (fieldModules.isNotEmpty) ...<Widget>[
              const Gap(AppSpacing.xl),
              AppReveal(
                delay: const Duration(milliseconds: 130),
                child: ModuleRailSection(
                  title: 'العمليات الميدانية',
                  subtitle: 'القراءات والعدادات في جيبك',
                  modules: fieldModules,
                  onViewAll: () => context.go(RouteNames.fieldHub),
                ),
              ),
            ],
            if (billingModules.isNotEmpty) ...<Widget>[
              const Gap(AppSpacing.xl),
              AppReveal(
                delay: const Duration(milliseconds: 160),
                child: ModuleRailSection(
                  title: 'التحصيل والفواتير',
                  subtitle: 'حصّل وأصدر السندات بسرعة',
                  modules: billingModules,
                  onViewAll: () => context.go(RouteNames.billingHub),
                ),
              ),
            ],
            if (crmModules.isNotEmpty) ...<Widget>[
              const Gap(AppSpacing.xl),
              AppReveal(
                delay: const Duration(milliseconds: 190),
                child: ModuleRailSection(
                  title: 'المشتركون والخدمات',
                  subtitle: 'ملفات المشتركين والمربعات',
                  modules: crmModules,
                  onViewAll: () => context.go(RouteNames.crmHub),
                ),
              ),
            ],
            if (insightModules.isNotEmpty) ...<Widget>[
              const Gap(AppSpacing.xl),
              AppReveal(
                delay: const Duration(milliseconds: 220),
                child: ModuleGridSection(
                  modules: insightModules,
                  title: 'التحليلات والإدارة',
                  subtitle: 'التقارير والإعدادات والصلاحيات',
                ),
              ),
            ],
            const Gap(AppSpacing.xl),
            AppReveal(
              delay: const Duration(milliseconds: 250),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  const SectionHeading(
                    title: 'المزامنة والاتصال',
                    subtitle: 'كل عملية محفوظة حتى تعود الشبكة',
                    icon: Icons.sync_rounded,
                  ),
                  const Gap(AppSpacing.sm),
                  SyncSummaryCard(onManage: () => context.push(RouteNames.offlineSync)),
                ],
              ),
            ),
            const Gap(AppSpacing.xl),
            Center(
              child: Text(
                'مشروع مياه الوشاح • منصة إدارة موحدة',
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ),
          ],
        );
      },
    );

    final cubit = _dashboard;
    if (cubit != null) {
      content = BlocProvider<DashboardCubit>.value(value: cubit, child: content);
    }
    return content;
  }

  String _roleLabel(PermissionService permissions) {
    if (permissions.isAdmin) return 'مدير النظام • صلاحيات كاملة';
    if (permissions.isCollector) return 'محصل ميداني • تحصيل وسندات';
    if (permissions.isReader) return 'قارئ عدادات • عمليات ميدانية';
    return 'موظف المشروع';
  }

  String _primaryLabel() {
    if (_permissions.isReader && !_permissions.isAdmin) return 'فتح القارئ الميداني';
    if (_permissions.isCollector && !_permissions.isAdmin) return 'بدء التحصيل السريع';
    if (_canViewDashboard) return 'لوحة القيادة';
    return 'فتح الفواتير';
  }

  IconData _primaryIcon() {
    if (_permissions.isReader && !_permissions.isAdmin) return Icons.speed_rounded;
    if (_permissions.isCollector && !_permissions.isAdmin) return Icons.point_of_sale_rounded;
    if (_canViewDashboard) return Icons.space_dashboard_rounded;
    return Icons.receipt_long_rounded;
  }

  String _primaryRoute() {
    if (_permissions.isReader && !_permissions.isAdmin) return RouteNames.fieldReader;
    if (_permissions.isCollector && !_permissions.isAdmin) return RouteNames.quickCollector;
    if (_canViewDashboard) return RouteNames.dashboard;
    return RouteNames.invoices;
  }
}

class _GreetingHero extends StatelessWidget {
  const _GreetingHero({
    required this.firstName,
    required this.roleLabel,
    required this.isOnline,
    required this.pendingSync,
    required this.primaryLabel,
    required this.primaryIcon,
    required this.onPrimary,
    required this.canViewDashboard,
    required this.onDashboard,
  });

  final String firstName;
  final String roleLabel;
  final bool? isOnline;
  final int pendingSync;
  final String primaryLabel;
  final IconData primaryIcon;
  final VoidCallback onPrimary;
  final bool canViewDashboard;
  final VoidCallback onDashboard;

  @override
  Widget build(BuildContext context) {
    final today = DateTime.now();
    return AppHeroPanel(
      padding: const EdgeInsets.all(AppSpacing.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          HeroCopy(
            eyebrow: '${ArabicDate.greeting(today)} • ${ArabicDate.full(today)}',
            title: 'أهلاً، $firstName 👋',
            subtitle: roleLabel,
          ),
          const Gap(AppSpacing.md),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: <Widget>[
              HeroMetaChip(
                icon: isOnline == true ? Icons.wifi_rounded : Icons.wifi_off_rounded,
                label: isOnline == true
                    ? 'متصل بالخادم'
                    : isOnline == false
                        ? 'وضع أوفلاين'
                        : 'جارٍ فحص الشبكة',
              ),
              if (pendingSync > 0)
                HeroMetaChip(
                  icon: Icons.cloud_upload_outlined,
                  label: '$pendingSync بانتظار المزامنة',
                ),
              const HeroMetaChip(icon: Icons.shield_outlined, label: 'جلسة آمنة'),
            ],
          ),
          const Gap(AppSpacing.lg),
          HeroActions(
            primary: HeroButton(
              label: primaryLabel,
              icon: primaryIcon,
              onPressed: onPrimary,
            ),
            secondary: canViewDashboard
                ? HeroGhostButton(
                    label: 'لوحة القيادة',
                    icon: Icons.insights_rounded,
                    onPressed: onDashboard,
                  )
                : null,
          ),
        ],
      ),
    );
  }
}

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
    final theme = Theme.of(context);
    return AppCard(
      padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm + 2, AppSpacing.md, AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Row(
              children: <Widget>[
                Text(
                  'إجراءات سريعة',
                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
                ),
                const Spacer(),
                const AppPill(
                  label: 'الأكثر استخداماً',
                  icon: Icons.bolt_rounded,
                  color: AppColors.brandAmber,
                  dense: true,
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          AppQuickActionBar(
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
                color: AppColors.warning,
                onTap: onCollect,
              ),
              if (onInvoice != null)
                AppQuickAction(
                  label: 'فاتورة',
                  icon: Icons.receipt_long_rounded,
                  color: AppColors.info,
                  onTap: onInvoice!,
                ),
              if (onSubscriber != null)
                AppQuickAction(
                  label: 'مشترك',
                  icon: Icons.person_add_alt_1_rounded,
                  color: AppColors.success,
                  onTap: onSubscriber!,
                ),
            ],
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
        color: queue.pendingCount > 0 ? AppColors.warning : AppColors.success,
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
        color: queue.isOnline == true ? AppColors.success : AppColors.warning,
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
        color: AppColors.violet,
        caption: 'مساحة العمل',
      ),
    ];
    return AppMetricGrid(children: cards);
  }
}

class _LiveMetrics extends StatelessWidget {
  const _LiveMetrics({required this.cubit, required this.canViewRevenue});

  final DashboardCubit cubit;
  final bool canViewRevenue;

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
          return _MetricsSkeleton();
        }

        final stats = snapshot.data.stats;
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
              icon: Icons.account_balance_wallet_outlined,
              color: AppColors.danger,
              caption: '${AdminFormat.number(stats.unpaidInvoicesCount)} فاتورة غير مسددة',
            ),
            AppMetricCard(
              title: 'إيرادات هذا الشهر',
              value: AdminFormat.compactMoney(stats.monthlyCollection),
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
              caption: stats.overdueInvoicesCount > 0 ? 'تحتاج متابعة' : 'لا توجد متأخرات',
            ),
          ],
        );
      },
    );
  }
}

class _MetricsSkeleton extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return const AppMetricGrid(
      children: <Widget>[
        ShimmerSkeleton(height: 150, radius: 26),
        ShimmerSkeleton(height: 150, radius: 26),
        ShimmerSkeleton(height: 150, radius: 26),
        ShimmerSkeleton(height: 150, radius: 26),
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
              const Gap(AppSpacing.md),
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
