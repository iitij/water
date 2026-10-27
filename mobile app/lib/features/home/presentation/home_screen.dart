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

part 'widgets/home_panels.dart';
part 'widgets/home_metrics.dart';

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
