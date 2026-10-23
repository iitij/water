import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/navigation/module_catalog.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/router/route_names.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_brand.dart';
import '../../../core/ui/app_motion.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tiles.dart';
import '../../../core/ui/app_tokens.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../core/widgets/shimmer_skeleton.dart';
import '../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../dashboard/domain/repositories/dashboard_repository.dart';
import '../../dashboard/presentation/cubit/dashboard_cubit.dart';
import '../../dashboard/presentation/widgets/collection_progress_card.dart';
import '../../dashboard/presentation/widgets/monthly_revenue_chart_card.dart';
import '../../navigation/presentation/widgets/module_section.dart';
import '../../offline_sync/services/sync_queue_manager.dart';
import '../domain/workspace_catalog.dart';
import 'widgets/workspace_widgets.dart';

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

    // Field collectors work action-first, so they get the compact workspace
    // (search + shortcuts + sync status) instead of the analytics overview.
    if (_permissions.isCollector && !_isAdmin) {
      return AnimatedBuilder(
        animation: Listenable.merge(<Listenable>[session, queue]),
        builder: (context, _) => HomeWorkspace(
          name: name,
          can: _permissions.can,
          online: queue.isOnline,
          pendingCount: queue.pendingCount,
          failedCount: queue.failedCount,
          collectorFirst: true,
          onNavigate: (route) {
            if (const <String>[
              RouteNames.services,
              RouteNames.offlineSync,
              RouteNames.profile,
            ].contains(Uri.parse(route).path)) {
              context.go(route);
            } else {
              context.push(route);
            }
          },
        ),
      );
    }

    final cubit = _dashboard;
    Widget content = AnimatedBuilder(
      animation: queue,
      builder: (context, _) {
        return AppPageBody(
          onRefresh: cubit == null ? null : () => cubit.refresh(),
          children: <Widget>[
            AppReveal(
              child: _SummarySlot(
                cubit: cubit,
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
            const Gap(AppSpacing.sm),
            AppReveal(
              delay: const Duration(milliseconds: 50),
              child: _QuickActionsCard(
                onReading: () => context.push(RouteNames.fieldReader),
                onCollect: () => context.push(RouteNames.quickCollector),
                onInvoice: canInvoices ? () => context.push(RouteNames.invoices) : null,
                onSubscriber: canSubscribers ? () => context.push(RouteNames.subscriberNew) : null,
              ),
            ),
            const Gap(AppSpacing.xl),
            AppReveal(
              delay: const Duration(milliseconds: 90),
              child: SectionHeading(
                title: 'نظرة سريعة',
                subtitle: cubit == null ? 'حالة جهازك ومساحة عملك الآن' : 'أرقام محدثة من الخادم',
                trailing: _canViewDashboard
                    ? TextButton(
                        onPressed: () => context.push(RouteNames.dashboard),
                        child: const Text('لوحة القيادة'),
                      )
                    : null,
              ),
            ),
            const Gap(AppSpacing.sm),
            if (cubit == null)
              _WorkspaceMetrics(queue: queue)
            else
              _LiveMetrics(cubit: cubit, canViewRevenue: _canViewRevenue),
            if (cubit != null) ...<Widget>[
              const Gap(AppSpacing.md),
              _DashboardExtras(
                cubit: cubit,
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
                  subtitle: 'القراءات والعدادات',
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
                  subtitle: 'حصّل وأصدر السندات',
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
                  subtitle: 'الملفات والمربعات',
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
                  ),
                  const Gap(AppSpacing.sm),
                  SyncSummaryCard(onManage: () => context.push(RouteNames.offlineSync)),
                ],
              ),
            ),
            const Gap(AppSpacing.xl),
            Center(
              child: Text(
                'مشروع مياه الوشاح',
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ),
          ],
        );
      },
    );

    if (cubit != null) {
      content = BlocProvider<DashboardCubit>.value(value: cubit, child: content);
    }
    return content;
  }

  String _roleLabel(PermissionService permissions) {
    if (permissions.isAdmin) return 'مدير النظام';
    if (permissions.isCollector) return 'محصل ميداني';
    if (permissions.isReader) return 'قارئ عدادات';
    return 'موظف المشروع';
  }

  String _primaryLabel() {
    if (_permissions.isReader && !_permissions.isAdmin) return 'فتح القارئ الميداني';
    if (_permissions.isCollector && !_permissions.isAdmin) return 'بدء التحصيل السريع';
    if (_canViewDashboard) return 'تحصيل سريع';
    return 'فتح الفواتير';
  }

  IconData _primaryIcon() {
    if (_permissions.isReader && !_permissions.isAdmin) return Icons.speed_rounded;
    if (_permissions.isCollector && !_permissions.isAdmin) return Icons.point_of_sale_rounded;
    if (_canViewDashboard) return Icons.point_of_sale_rounded;
    return Icons.receipt_long_rounded;
  }

  String _primaryRoute() {
    if (_permissions.isReader && !_permissions.isAdmin) return RouteNames.fieldReader;
    if (_permissions.isCollector && !_permissions.isAdmin) return RouteNames.quickCollector;
    if (_canViewDashboard) return RouteNames.quickCollector;
    return RouteNames.invoices;
  }
}

/// Rebuilds the summary panel when the dashboard snapshot changes, without
/// rebuilding the rest of the page.
class _SummarySlot extends StatelessWidget {
  const _SummarySlot({
    required this.cubit,
    required this.roleLabel,
    required this.isOnline,
    required this.pendingSync,
    required this.primaryLabel,
    required this.primaryIcon,
    required this.onPrimary,
    required this.canViewDashboard,
    required this.onDashboard,
  });

  final DashboardCubit? cubit;
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
    final active = cubit;
    if (active == null) {
      return _BalancePanel(
        roleLabel: roleLabel,
        isOnline: isOnline,
        pendingSync: pendingSync,
        primaryLabel: primaryLabel,
        primaryIcon: primaryIcon,
        onPrimary: onPrimary,
        canViewDashboard: canViewDashboard,
        onDashboard: onDashboard,
      );
    }
    return BlocBuilder<DashboardCubit, DashboardState>(
      buildWhen: (previous, current) => previous.snapshot != current.snapshot,
      builder: (context, state) {
        final stats = state.snapshot?.data.stats;
        return _BalancePanel(
          roleLabel: roleLabel,
          isOnline: isOnline,
          pendingSync: pendingSync,
          primaryLabel: primaryLabel,
          primaryIcon: primaryIcon,
          onPrimary: onPrimary,
          canViewDashboard: canViewDashboard,
          onDashboard: onDashboard,
          outstanding: stats?.outstandingAmount,
          collected: stats?.monthlyCollection,
          unpaidInvoices: stats?.unpaidInvoicesCount,
          overdueInvoices: stats?.overdueInvoicesCount,
        );
      },
    );
  }
}

/// The page's single dark surface: outstanding balance, collection progress and
/// the two actions that matter today.
class _BalancePanel extends StatelessWidget {
  const _BalancePanel({
    required this.roleLabel,
    required this.isOnline,
    required this.pendingSync,
    required this.primaryLabel,
    required this.primaryIcon,
    required this.onPrimary,
    required this.canViewDashboard,
    required this.onDashboard,
    this.outstanding,
    this.collected,
    this.unpaidInvoices,
    this.overdueInvoices,
  });

  final String roleLabel;
  final bool? isOnline;
  final int pendingSync;
  final String primaryLabel;
  final IconData primaryIcon;
  final VoidCallback onPrimary;
  final bool canViewDashboard;
  final VoidCallback onDashboard;
  final double? outstanding;
  final double? collected;
  final int? unpaidInvoices;
  final int? overdueInvoices;

  static const Color _panelAccent = AppColors.accentOnPanel;
  static const Color _onPanel = AppColors.onPanel;
  static const Color _onPanelSoft = AppColors.onPanelSoft;
  static const Color _onPanelMuted = AppColors.onPanelMuted;

  double get _ratio {
    final due = outstanding ?? 0;
    final paid = collected ?? 0;
    final total = due + paid;
    if (total <= 0) return 0;
    return (paid / total).clamp(0, 1).toDouble();
  }

  bool get _hasNumbers => outstanding != null;

  @override
  Widget build(BuildContext context) {
    final ratio = _ratio;
    final percent = (ratio * 100).round();
    final connection = isOnline == true
        ? 'متصل'
        : isOnline == false
            ? 'أوفلاين'
            : 'جارٍ الفحص';

    return AppHeroPanel(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  _hasNumbers ? 'إجمالي الديون المستحقة' : 'مساحة العمل اليوم',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: _onPanelSoft,
                    fontSize: 11.5,
                    fontWeight: FontWeight.w500,
                    height: 1.3,
                  ),
                ),
              ),
              Container(
                width: 6,
                height: 6,
                decoration: BoxDecoration(
                  color: isOnline == false ? AppColors.warning : _panelAccent,
                  shape: BoxShape.circle,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                connection,
                style: const TextStyle(
                  color: _onPanelMuted,
                  fontSize: 10.5,
                  height: 1.3,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (_hasNumbers)
            _AmountLine(value: CurrencyFormatter.amount(outstanding))
          else
            Text(
              pendingSync > 0 ? '$pendingSync عملية بانتظار المزامنة' : 'لا مهام معلقة اليوم',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: _onPanel,
                fontSize: 20,
                fontWeight: FontWeight.w600,
                height: 1.3,
              ),
            ),
          if (_hasNumbers) ...<Widget>[
            const SizedBox(height: 3),
            Text(
              '${unpaidInvoices ?? 0} فاتورة غير مسددة · ${overdueInvoices ?? 0} متأخرة',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: _onPanelMuted, fontSize: 10.5, height: 1.4),
            ),
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(AppRadius.pill),
              child: TweenAnimationBuilder<double>(
                tween: Tween<double>(begin: 0, end: ratio),
                duration: AppMotion.slow,
                curve: Curves.easeOutCubic,
                builder: (context, value, _) => LinearProgressIndicator(
                  value: value,
                  minHeight: 4,
                  backgroundColor: Colors.white.withValues(alpha: .12),
                  valueColor: const AlwaysStoppedAnimation<Color>(_panelAccent),
                ),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: <Widget>[
                Expanded(
                  child: Text(
                    'تم تحصيل $percent% من المستحقات',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: _onPanelMuted, fontSize: 10, height: 1.4),
                  ),
                ),
                Text(
                  '${CurrencyFormatter.amount(collected ?? 0)} ر.ي',
                  style: const TextStyle(color: _onPanelSoft, fontSize: 10.5, height: 1.4),
                ),
              ],
            ),
          ] else ...<Widget>[
            const SizedBox(height: 2),
            Text(
              roleLabel,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: _onPanelMuted, fontSize: 10.5, height: 1.4),
            ),
          ],
          const SizedBox(height: 16),
          LayoutBuilder(
            builder: (context, constraints) {
              final primary = _PanelButton(
                label: primaryLabel,
                icon: primaryIcon,
                primary: true,
                onPressed: onPrimary,
              );
              if (!canViewDashboard || constraints.maxWidth < 300) {
                return primary;
              }
              return Row(
                children: <Widget>[
                  Expanded(flex: 3, child: primary),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: _PanelButton(
                      label: 'لوحة القيادة',
                      icon: Icons.insights_rounded,
                      primary: false,
                      onPressed: onDashboard,
                    ),
                  ),
                ],
              );
            },
          ),
        ],
      ),
    );
  }
}

class _AmountLine extends StatelessWidget {
  const _AmountLine({required this.value});

  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      textDirection: TextDirection.rtl,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: <Widget>[
        Flexible(
          child: FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerEnd,
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Text(
                value,
                maxLines: 1,
                style: const TextStyle(
                  color: AppColors.onPanel,
                  fontSize: 31,
                  fontWeight: FontWeight.w600,
                  height: 1.1,
                  letterSpacing: -.6,
                ),
              ),
            ),
          ),
        ),
        const SizedBox(width: 5),
        const Padding(
          padding: EdgeInsets.only(bottom: 4),
          child: Text(
            'ر.ي',
            style: TextStyle(
              color: AppColors.onPanelMuted,
              fontSize: 11.5,
              fontWeight: FontWeight.w500,
            ),
          ),
        ),
      ],
    );
  }
}

class _PanelButton extends StatelessWidget {
  const _PanelButton({
    required this.label,
    required this.icon,
    required this.primary,
    required this.onPressed,
  });

  final String label;
  final IconData icon;
  final bool primary;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    const radius = BorderRadius.all(Radius.circular(AppRadius.md));
    final foreground = primary ? AppColors.panel : AppColors.onPanelSoft;
    return SizedBox(
      height: 44,
      child: Material(
        color: primary ? AppColors.accentOnPanel : Colors.transparent,
        borderRadius: radius,
        child: InkWell(
          onTap: onPressed,
          borderRadius: radius,
          splashColor: primary ? Colors.black12 : Colors.white12,
          child: Container(
            decoration: BoxDecoration(
              borderRadius: radius,
              border: primary ? null : Border.all(color: Colors.white.withValues(alpha: .18)),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                Icon(icon, size: 16, color: foreground),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: foreground,
                      fontSize: 12.5,
                      fontWeight: primary ? FontWeight.w600 : FontWeight.w500,
                      height: 1.2,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
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
          return const _MetricsSkeleton();
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
              value: CurrencyFormatter.amount(stats.outstandingAmount),
              unit: 'ر.ي',
              icon: Icons.account_balance_wallet_outlined,
              color: AppColors.danger,
              caption: '${AdminFormat.number(stats.unpaidInvoicesCount)} فاتورة غير مسددة',
            ),
            AppMetricCard(
              title: 'إيرادات هذا الشهر',
              value: CurrencyFormatter.amount(stats.monthlyCollection),
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
              color: AppColors.warning,
              caption: stats.overdueInvoicesCount > 0 ? 'تحتاج متابعة' : 'لا توجد متأخرات',
            ),
          ],
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
class HomeWorkspace extends StatelessWidget {
  const HomeWorkspace({
    super.key,
    required this.name,
    required this.can,
    required this.online,
    required this.pendingCount,
    required this.failedCount,
    required this.onNavigate,
    this.collectorFirst = false,
  });

  final String name;
  final bool Function(String) can;
  final bool? online;
  final int pendingCount;
  final int failedCount;
  final bool collectorFirst;
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final visible = WorkspaceCatalog.visible(can);
    final shortcuts = visible.where((entry) => entry.shortcut).toList();
    if (collectorFirst) {
      final index = shortcuts.indexWhere((entry) => entry.route == RouteNames.quickCollector);
      if (index > 0) shortcuts.insert(0, shortcuts.removeAt(index));
    }
    final frequent = visible.where((entry) => const [
          RouteNames.subscribers,
          RouteNames.invoices,
          RouteNames.meters,
          RouteNames.readings,
          RouteNames.payments,
          RouteNames.dashboard,
        ].contains(entry.route)).take(4).toList();

    return SafeArea(
      top: false,
      child: SingleChildScrollView(
        key: const PageStorageKey('home-workspace'),
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1080),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                AppHeroPanel(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      const AppBrandMark(size: 36, glow: false),
                      const SizedBox(height: 16),
                      Text(
                        'أهلاً، $name',
                        style: theme.textTheme.titleLarge?.copyWith(
                          color: AppColors.onPanel,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'كل ما تحتاجه لإدارة المياه، في مكان واحد.',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: AppColors.onPanelSoft,
                          height: 1.5,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                WorkspaceSearchButton(onTap: () => onNavigate('${RouteNames.services}?search=1')),
                const SizedBox(height: 10),
                _SyncSummary(
                  online: online,
                  pendingCount: pendingCount,
                  failedCount: failedCount,
                  onTap: () => onNavigate(RouteNames.offlineSync),
                ),
                if (shortcuts.isNotEmpty) ...<Widget>[
                  const SizedBox(height: 24),
                  Text('ماذا تريد أن تفعل؟', style: theme.textTheme.titleLarge),
                  const SizedBox(height: 10),
                  WorkspaceShortcutGrid(entries: shortcuts, onNavigate: onNavigate),
                ],
                const SizedBox(height: 24),
                // Wrap lets the link move below the heading with large text.
                Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 16,
                  children: <Widget>[
                    Text('الوصول السريع', style: theme.textTheme.titleLarge),
                    TextButton.icon(
                      onPressed: () => onNavigate(RouteNames.services),
                      icon: const Icon(Icons.grid_view_rounded, size: 16),
                      label: const Text('كل الخدمات'),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                for (final entry in frequent)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: WorkspaceTile(entry: entry, onTap: () => onNavigate(entry.route)),
                  ),
                if (frequent.isEmpty)
                  Text(
                    'يمكنك الوصول إلى حسابك والمزامنة من كل الخدمات. لطلب صلاحيات إضافية، تواصل مع مدير النظام.',
                    style: theme.textTheme.bodyMedium,
                  ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(padding: const EdgeInsets.all(14)),
                  onPressed: () => onNavigate(RouteNames.services),
                  icon: const Icon(Icons.grid_view_rounded, size: 16),
                  label: const Text('استعراض كل الخدمات'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SyncSummary extends StatelessWidget {
  const _SyncSummary({
    required this.online,
    required this.pendingCount,
    required this.failedCount,
    required this.onTap,
  });

  final bool? online;
  final int pendingCount;
  final int failedCount;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final failed = failedCount > 0;
    final connection = online == true
        ? 'متصل بالشبكة'
        : online == false
            ? 'غير متصل بالشبكة'
            : 'جارٍ التحقق من الاتصال';
    final status = failed
        ? '$failedCount عملية تحتاج مراجعة'
        : pendingCount > 0
            ? '$pendingCount عملية بانتظار المزامنة'
            : 'لا توجد عمليات معلقة';

    return AppCard(
      padding: EdgeInsets.zero,
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(14, 12, 14, 12),
        child: Row(
          children: <Widget>[
            Icon(
              failed
                  ? Icons.sync_problem_rounded
                  : online == true
                      ? Icons.cloud_done_outlined
                      : Icons.cloud_off_outlined,
              size: 18,
              color: failed ? scheme.error : scheme.onSurfaceVariant,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(connection, style: theme.textTheme.titleSmall),
                  const SizedBox(height: 1),
                  Text(status, style: theme.textTheme.labelSmall),
                ],
              ),
            ),
            Icon(
              Icons.chevron_left_rounded,
              size: 18,
              color: scheme.onSurfaceVariant.withValues(alpha: .7),
            ),
          ],
        ),
      ),
    );
  }
}
