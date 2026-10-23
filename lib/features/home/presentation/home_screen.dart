import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/responsive/breakpoints.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/widgets/empty_state_widget.dart';
import '../../../core/widgets/stat_card.dart';
import '../../../core/widgets/status_badge.dart';
import '../../offline_sync/services/sync_queue_manager.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = GetIt.I<SessionService>();
    final permissions = GetIt.I<PermissionService>();
    final queue = GetIt.I<SyncQueueManager>();
    final name = session.user?['name']?.toString() ?? 'موظف المشروع';
    final roles = permissions.currentRoles;
    final roleTitle = roles.contains('super_admin') || roles.contains('admin')
        ? 'إدارة النظام'
        : roles.contains('collector')
            ? 'التحصيل الميداني'
            : roles.contains('reader') || roles.contains('meter_reader')
                ? 'القراءات الميدانية'
                : 'مساحة العمل';

    return AnimatedBuilder(
      animation: queue,
      builder: (context, _) {
        final width = MediaQuery.sizeOf(context).width;
        final horizontal = width < 360 ? 14.0 : 22.0;
        return SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(horizontal, 18, horizontal, 32),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: AppBreakpoints.contentMax),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _WelcomeCard(name: name, roleTitle: roleTitle, online: queue.isOnline),
                  const SizedBox(height: 18),
                  LayoutBuilder(builder: (context, constraints) {
                    final columns = constraints.maxWidth >= 840 ? 3 : (constraints.maxWidth >= 520 ? 3 : 1);
                    final cards = <Widget>[
                      StatCard(title: 'عمليات تنتظر المزامنة', value: queue.pendingCount.toString(), icon: Icons.cloud_upload_outlined, color: AppColors.warning, caption: queue.failedCount > 0 ? '${queue.failedCount} فاشلة' : null),
                      StatCard(title: 'صلاحيات حسابك', value: permissions.currentPermissions.length.toString(), icon: Icons.verified_user_outlined, color: AppColors.success, caption: 'محدّثة'),
                      StatCard(title: 'اتصال الخادم', value: queue.isOnline == true ? 'متصل' : (queue.isOnline == false ? 'غير متصل' : 'جارٍ الفحص'), icon: queue.isOnline == true ? Icons.wifi_rounded : Icons.wifi_off_rounded, color: queue.isOnline == true ? AppColors.info : AppColors.warning, caption: 'حالة الشبكة'),
                    ];
                    if (columns == 1) {
                      return Column(children: cards.map((card) => Padding(padding: const EdgeInsets.only(bottom: 10), child: SizedBox(width: double.infinity, child: card))).toList());
                    }
                    return Row(
                      children: cards.asMap().entries.map((entry) => Expanded(
                        child: Padding(
                          padding: EdgeInsetsDirectional.only(end: entry.key == cards.length - 1 ? 0 : 10),
                          child: entry.value,
                        ),
                      )).toList(),
                    );
                  }),
                  const SizedBox(height: 18),
                  Text('اختصارات سريعة', style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 12),
                  LayoutBuilder(builder: (context, constraints) {
                    final isWide = constraints.maxWidth >= 640;
                    final sync = _ActionCard(
                      icon: Icons.sync_rounded,
                      color: AppColors.info,
                      title: 'مراقب المزامنة',
                      description: queue.pendingCount == 0 ? 'قائمة المزامنة فارغة' : 'راجع ${queue.pendingCount} عملية محفوظة على الجهاز',
                      onTap: () => context.go('/sync'),
                    );
                    final profile = _ActionCard(
                      icon: Icons.manage_accounts_outlined,
                      color: AppColors.primary,
                      title: 'الملف الشخصي',
                      description: 'تحديث بياناتك وكلمة المرور والصورة',
                      onTap: () => context.go('/profile'),
                    );
                    final quickActions = <Widget>[sync, profile];
                    return isWide
                        ? Row(children: quickActions.map((card) => Expanded(child: Padding(padding: EdgeInsetsDirectional.only(end: card == quickActions.last ? 0 : 12), child: card))).toList())
                        : Column(children: quickActions.map((card) => Padding(padding: const EdgeInsets.only(bottom: 10), child: card)).toList());
                  }),
                  const SizedBox(height: 8),
                  _HandoffBanner(roles: roles),
                  if (queue.pendingCount == 0) ...<Widget>[
                    const SizedBox(height: 10),
                    const EmptyStateWidget(
                      icon: Icons.water_drop_outlined,
                      title: 'كل شيء جاهز',
                      message: 'تظهر هنا حالة المزامنة والاختصارات التي تناسب دورك. ستضيف وحدات الفريق عملياتها إلى هذا الأساس المشترك.',
                    ),
                  ],
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _WelcomeCard extends StatelessWidget {
  const _WelcomeCard({required this.name, required this.roleTitle, required this.online});

  final String name;
  final String roleTitle;
  final bool? online;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        gradient: const LinearGradient(begin: AlignmentDirectional.topStart, end: AlignmentDirectional.bottomEnd, colors: <Color>[Color(0xFF0B2B40), Color(0xFF02687E), Color(0xFF0EA5E9)]),
        boxShadow: <BoxShadow>[BoxShadow(color: AppColors.primary.withOpacity(.18), blurRadius: 26, offset: const Offset(0, 12))],
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: <Widget>[
              Text('صباح الخير، $name', style: theme.textTheme.headlineMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w800), maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 6),
              Text(roleTitle, style: theme.textTheme.titleMedium?.copyWith(color: Colors.white.withOpacity(.78))),
              const SizedBox(height: 17),
              StatusBadge(status: online == true ? 'connected' : (online == false ? 'offline' : 'waiting')),
            ]),
          ),
          const SizedBox(width: 12),
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(color: Colors.white.withOpacity(.14), borderRadius: BorderRadius.circular(22), border: Border.all(color: Colors.white.withOpacity(.2))),
            child: const Icon(Icons.water_drop_rounded, color: Colors.white, size: 34),
          ),
        ],
      ),
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({required this.icon, required this.color, required this.title, required this.description, required this.onTap});

  final IconData icon;
  final Color color;
  final String title;
  final String description;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surface,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.all(17),
          decoration: BoxDecoration(borderRadius: BorderRadius.circular(20), border: Border.all(color: theme.dividerColor.withOpacity(.7))),
          child: Row(children: <Widget>[
            Container(width: 44, height: 44, decoration: BoxDecoration(color: color.withOpacity(.1), borderRadius: BorderRadius.circular(15)), child: Icon(icon, color: color)),
            const SizedBox(width: 13),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: <Widget>[Text(title, style: theme.textTheme.titleMedium), const SizedBox(height: 3), Text(description, style: theme.textTheme.bodySmall, maxLines: 2, overflow: TextOverflow.ellipsis)])),
            const Icon(Icons.chevron_left_rounded),
          ]),
        ),
      ),
    );
  }
}

class _HandoffBanner extends StatelessWidget {
  const _HandoffBanner({required this.roles});
  final Set<String> roles;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final role = roles.contains('collector') ? 'المحصل' : (roles.contains('reader') || roles.contains('meter_reader') ? 'القارئ' : 'فريق الإدارة');
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(color: theme.colorScheme.primary.withOpacity(.055), borderRadius: BorderRadius.circular(18), border: Border.all(color: theme.colorScheme.primary.withOpacity(.12))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: <Widget>[
        Icon(Icons.lightbulb_outline_rounded, color: theme.colorScheme.primary, size: 20),
        const SizedBox(width: 10),
        Expanded(child: Text('أهلاً بك. مساحة العمل الأساسية جاهزة لدور $role، والعمليات الميدانية التي تنفذ دون اتصال ستظهر في مراقب المزامنة.', style: theme.textTheme.bodySmall?.copyWith(height: 1.6))),
      ]),
    );
  }
}
