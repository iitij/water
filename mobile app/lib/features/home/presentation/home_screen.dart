import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/router/route_names.dart';
import '../../../core/theme/app_colors.dart';
import '../../offline_sync/services/sync_queue_manager.dart';
import '../domain/workspace_catalog.dart';
import 'widgets/workspace_widgets.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = GetIt.I<SessionService>();
    final permissions = GetIt.I<PermissionService>();
    final queue = GetIt.I<SyncQueueManager>();
    return AnimatedBuilder(
      animation: Listenable.merge([session, queue]),
      builder: (context, _) => HomeWorkspace(
        name: session.user?['name']?.toString() ?? 'موظف المشروع',
        can: permissions.can,
        online: queue.isOnline,
        pendingCount: queue.pendingCount,
        failedCount: queue.failedCount,
        collectorFirst: permissions.isCollector && !permissions.isAdmin,
        onNavigate: (route) {
          if (const [RouteNames.services, RouteNames.offlineSync, RouteNames.profile].contains(Uri.parse(route).path)) {
            context.go(route);
          } else {
            context.push(route);
          }
        },
      ),
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
      RouteNames.subscribers, RouteNames.invoices, RouteNames.meters,
      RouteNames.readings, RouteNames.payments, RouteNames.dashboard,
    ].contains(entry.route)).take(4).toList();

    return SafeArea(
      top: false,
      child: SingleChildScrollView(
        key: const PageStorageKey('home-workspace'),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1080),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(
                  padding: const EdgeInsets.all(24),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(24),
                    gradient: const LinearGradient(
                      begin: AlignmentDirectional.topStart,
                      end: AlignmentDirectional.bottomEnd,
                      colors: [AppColors.primaryDark, AppColors.primary],
                    ),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.water_drop_rounded, color: Color(0xFF7DD3FC), size: 32),
                      const SizedBox(height: 16),
                      Text('أهلاً، $name', style: theme.textTheme.headlineSmall?.copyWith(
                        color: Colors.white, fontWeight: FontWeight.w800)),
                      const SizedBox(height: 6),
                      Text('كل ما تحتاجه لإدارة المياه، في مكان واحد.',
                        style: theme.textTheme.bodyLarge?.copyWith(color: Colors.white)),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                WorkspaceSearchButton(onTap: () => onNavigate('${RouteNames.services}?search=1')),
                const SizedBox(height: 12),
                _SyncSummary(
                  online: online, pendingCount: pendingCount, failedCount: failedCount,
                  onTap: () => onNavigate(RouteNames.offlineSync),
                ),
                if (shortcuts.isNotEmpty) ...[
                  const SizedBox(height: 24),
                  Text('ماذا تريد أن تفعل؟', style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 12),
                  WorkspaceShortcutGrid(entries: shortcuts, onNavigate: onNavigate),
                ],
                const SizedBox(height: 24),
                // Wrap lets the link move below the heading with large text.
                Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 16,
                  children: [
                    Text('الوصول السريع', style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                    TextButton.icon(
                      onPressed: () => onNavigate(RouteNames.services),
                      icon: const Icon(Icons.apps_rounded),
                      label: const Text('كل الخدمات'),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                for (final entry in frequent) Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: WorkspaceTile(entry: entry, onTap: () => onNavigate(entry.route)),
                ),
                if (frequent.isEmpty) ...[
                  Text('يمكنك الوصول إلى حسابك والمزامنة من كل الخدمات. لطلب صلاحيات إضافية، تواصل مع مدير النظام.',
                    style: theme.textTheme.bodyLarge),
                ],
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(padding: const EdgeInsets.all(16)),
                  onPressed: () => onNavigate(RouteNames.services),
                  icon: const Icon(Icons.grid_view_rounded),
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
  const _SyncSummary({required this.online, required this.pendingCount,
    required this.failedCount, required this.onTap});
  final bool? online;
  final int pendingCount;
  final int failedCount;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = failedCount > 0 ? theme.colorScheme.error : theme.colorScheme.onSurfaceVariant;
    final connection = online == true ? 'متصل بالشبكة' : online == false
        ? 'غير متصل بالشبكة' : 'جارٍ التحقق من الاتصال';
    final status = failedCount > 0 ? '$failedCount عملية تحتاج مراجعة' : pendingCount > 0
        ? '$pendingCount عملية بانتظار المزامنة' : 'لا توجد عمليات معلقة';
    return Material(
      color: color.withValues(alpha: .06),
      borderRadius: BorderRadius.circular(16),
      child: ListTile(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        leading: Icon(failedCount > 0 ? Icons.sync_problem_rounded :
          online == true ? Icons.cloud_done_outlined : Icons.cloud_off_outlined, color: color),
        title: Text(connection, style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w700)),
        subtitle: Text(status, style: TextStyle(color: color)),
        trailing: const Icon(Icons.arrow_forward_ios_rounded, size: 14),
        onTap: onTap,
      ),
    );
  }
}
