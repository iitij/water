import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/l10n/owner_ux_strings.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/router/route_names.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tiles.dart';
import '../../../core/ui/app_tokens.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../core/widgets/shimmer_skeleton.dart';
import '../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../dashboard/domain/entities/dashboard_entities.dart';
import '../../dashboard/domain/repositories/dashboard_repository.dart';
import '../../dashboard/presentation/cubit/dashboard_cubit.dart';
import '../../dashboard/presentation/widgets/collection_progress_card.dart';
import '../../dashboard/presentation/widgets/monthly_revenue_chart_card.dart';
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

  bool get _canViewStats => _isAdmin || _permissions.can('View:StatsOverview');

  bool get _canViewDashboard =>
      _isAdmin ||
      _permissions.hasAnyPermission(const <String>[
        'View:CustomAccountWidget',
        'View:StatsOverview',
        'View:MonthlyRevenueChart',
      ]);

  bool get _canViewRevenue =>
      _isAdmin || _permissions.can('View:MonthlyRevenueChart');

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

    final cubit = _dashboard;
    return AnimatedBuilder(
      animation: Listenable.merge(<Listenable>[session, queue]),
      builder: (context, _) => HomeWorkspace(
        name: session.user?['name']?.toString() ?? name,
        can: _permissions.can,
        online: queue.isOnline,
        pendingCount: queue.pendingCount,
        failedCount: queue.failedCount,
        collectorFirst: _permissions.isCollector && !_isAdmin,
        onRefresh: cubit == null ? null : () => cubit.refresh(),
        overview: cubit == null
            ? null
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _SummarySlot(
                    cubit: cubit,
                    canViewDashboard: _canViewDashboard,
                    onDashboard: () => context.push(RouteNames.dashboard),
                  ),
                  ExpansionTile(
                    tilePadding: EdgeInsets.zero,
                    title: const Text(OwnerUxStrings.moreNumbers),
                    children: <Widget>[
                      _LiveMetrics(
                        cubit: cubit,
                        canViewRevenue: _canViewRevenue,
                      ),
                      const Gap(AppSpacing.md),
                      _DashboardExtras(
                        cubit: cubit,
                        canViewRevenue: _canViewRevenue,
                        canViewStats:
                            _isAdmin || _permissions.can('View:StatsOverview'),
                        onOpenReports: () => context.push(RouteNames.reports),
                      ),
                    ],
                  ),
                ],
              ),
        onNavigate: (route) => context.push(route),
      ),
    );
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
    this.overview,
    this.onRefresh,
  });

  final String name;
  final bool Function(String) can;
  final bool? online;
  final int pendingCount;
  final int failedCount;
  final bool collectorFirst;
  final Widget? overview;
  final Future<void> Function()? onRefresh;
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final visible = WorkspaceCatalog.visible(can);
    final shortcuts = visible.where((entry) => entry.shortcut).toList();
    final priority = collectorFirst
        ? const [
            RouteNames.quickCollector,
            RouteNames.cashSession,
            RouteNames.fieldReader,
            RouteNames.subscriberNew,
            RouteNames.invoiceIssue,
          ]
        : const [
            RouteNames.quickCollector,
            RouteNames.subscriberNew,
            RouteNames.invoiceIssue,
            RouteNames.fieldReader,
            RouteNames.cashSession,
          ];
    shortcuts.sort(
      (a, b) => priority.indexOf(a.route).compareTo(priority.indexOf(b.route)),
    );
    final frequent = visible
        .where(
          (entry) => const [
            RouteNames.subscribers,
            RouteNames.invoices,
            RouteNames.meters,
            RouteNames.readings,
            RouteNames.payments,
            RouteNames.dashboard,
          ].contains(entry.route),
        )
        .take(4)
        .toList();

    final body = SafeArea(
      top: false,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        key: const PageStorageKey('home-workspace'),
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1080),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Text(
                  OwnerUxStrings.greeting(name),
                  style: theme.textTheme.titleLarge,
                ),
                const SizedBox(height: 4),
                Text(
                  OwnerUxStrings.dailyHelp,
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 12),
                WorkspaceSearchButton(
                  onTap: () => onNavigate('${RouteNames.services}?search=1'),
                ),
                const SizedBox(height: 10),
                _SyncSummary(
                  online: online,
                  pendingCount: pendingCount,
                  failedCount: failedCount,
                  onTap: () => onNavigate(RouteNames.offlineSync),
                ),
                if (can('ViewAny:Subscriber') ||
                    can('ViewAny:Invoice')) ...<Widget>[
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: () => onNavigate(RouteNames.globalSearch),
                    icon: const Icon(Icons.person_search_outlined),
                    label: const Text(OwnerUxStrings.recordsSearch),
                  ),
                ],
                if (shortcuts.isNotEmpty) ...<Widget>[
                  const SizedBox(height: 24),
                  Text(
                    OwnerUxStrings.dailyWork,
                    style: theme.textTheme.titleLarge,
                  ),
                  const SizedBox(height: 10),
                  WorkspaceShortcutGrid(
                    entries: shortcuts,
                    onNavigate: onNavigate,
                  ),
                ],
                if (overview != null) ...<Widget>[
                  const SizedBox(height: 24),
                  overview!,
                ],
                const SizedBox(height: 24),
                // Wrap lets the link move below the heading with large text.
                Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 16,
                  children: <Widget>[
                    Text(
                      OwnerUxStrings.quickAccess,
                      style: theme.textTheme.titleLarge,
                    ),
                    TextButton.icon(
                      onPressed: () => onNavigate(RouteNames.services),
                      icon: const Icon(Icons.grid_view_rounded, size: 16),
                      label: const Text(OwnerUxStrings.allServices),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                for (final entry in frequent)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: WorkspaceTile(
                      entry: entry,
                      onTap: () => onNavigate(entry.route),
                    ),
                  ),
                if (frequent.isEmpty)
                  Text(
                    OwnerUxStrings.emptyHome,
                    style: theme.textTheme.bodyMedium,
                  ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    padding: const EdgeInsets.all(14),
                  ),
                  onPressed: () => onNavigate(RouteNames.services),
                  icon: const Icon(Icons.grid_view_rounded, size: 16),
                  label: const Text(OwnerUxStrings.browseServices),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    return onRefresh == null
        ? body
        : RefreshIndicator(onRefresh: onRefresh!, child: body);
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
        ? OwnerUxStrings.online
        : online == false
        ? OwnerUxStrings.offline
        : OwnerUxStrings.checking;
    final status = failed
        ? OwnerUxStrings.failed(failedCount)
        : pendingCount > 0
        ? OwnerUxStrings.pending(pendingCount)
        : OwnerUxStrings.noPending;

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
