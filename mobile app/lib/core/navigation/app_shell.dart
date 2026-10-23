import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../auth/session_service.dart';
import '../config/app_config.dart';
import '../permissions/permission_service.dart';
import '../router/route_names.dart';
import '../theme/app_colors.dart';
import '../theme/app_theme.dart';
import '../ui/app_brand.dart';
import '../ui/app_controls.dart';
import '../ui/app_tokens.dart';

class _ShellDestination {
  const _ShellDestination({
    required this.label,
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.selectedIcon,
  });

  final String label;
  final String title;
  final String subtitle;
  final IconData icon;
  final IconData selectedIcon;
}

const List<_ShellDestination> _destinations = <_ShellDestination>[
  _ShellDestination(
    label: 'الرئيسية',
    title: 'مساحة العمل',
    subtitle: 'خلاصة اليوم وإجراءاتك السريعة',
    icon: Icons.dashboard_outlined,
    selectedIcon: Icons.dashboard_rounded,
  ),
  _ShellDestination(
    label: 'الميدان',
    title: 'العمليات الميدانية',
    subtitle: 'القراءات والعدادات والمسارات',
    icon: Icons.sensors_outlined,
    selectedIcon: Icons.sensors_rounded,
  ),
  _ShellDestination(
    label: 'المالية',
    title: 'التحصيل والمالية',
    subtitle: 'الفواتير والسندات والتعرفة',
    icon: Icons.account_balance_wallet_outlined,
    selectedIcon: Icons.account_balance_wallet_rounded,
  ),
  _ShellDestination(
    label: 'المشتركون',
    title: 'المشتركون والخدمات',
    subtitle: 'الملفات والمربعات وطلبات الخدمة',
    icon: Icons.groups_2_outlined,
    selectedIcon: Icons.groups_2_rounded,
  ),
  _ShellDestination(
    label: 'المزيد',
    title: 'المزيد',
    subtitle: 'التقارير والإعدادات والحساب',
    icon: Icons.grid_view_outlined,
    selectedIcon: Icons.grid_view_rounded,
  ),
];

/// Application frame: a floating pill navigation bar on phones/tablets and a
/// branded rail on wide screens. Pages provide their own heroes and sections.
class AppHomeShell extends StatelessWidget {
  const AppHomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final wide = width >= 1020;
    final index = shell.currentIndex.clamp(0, _destinations.length - 1).toInt();

    void select(int next) => shell.goBranch(
          next,
          initialLocation: next == shell.currentIndex,
        );

    if (wide) {
      return Scaffold(
        body: Row(
          children: <Widget>[
            _SideRail(currentIndex: index, onSelect: select),
            Expanded(child: SafeArea(child: shell)),
          ],
        ),
      );
    }

    return Scaffold(
      body: Column(
        children: <Widget>[
          _TopBar(destination: _destinations[index]),
          Expanded(child: shell),
        ],
      ),
      bottomNavigationBar: _BottomNav(currentIndex: index, onSelect: select),
      extendBody: true,
    );
  }
}

class _TopBar extends StatelessWidget {
  const _TopBar({required this.destination});

  final _ShellDestination destination;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final session = GetIt.I<SessionService>();
    final queue = GetIt.I<SyncQueueManager>();
    final user = session.user ?? const <String, dynamic>{};
    final name = user['name']?.toString() ?? 'موظف المشروع';
    final avatar = user['avatar']?.toString();

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: theme.brightness == Brightness.dark
          ? SystemUiOverlayStyle.light
          : SystemUiOverlayStyle.dark,
      child: Container(
        decoration: BoxDecoration(
          color: scheme.surface,
          border: Border(bottom: BorderSide(color: scheme.outlineVariant.withValues(alpha: .7))),
        ),
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 12, 10),
            child: Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        destination.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                      ),
                      const SizedBox(height: 1),
                      Text(
                        destination.subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ),
                ),
                AnimatedBuilder(
                  animation: queue,
                  builder: (context, _) => AppIconButton(
                    icon: queue.isOnline == false ? Icons.cloud_off_rounded : Icons.cloud_done_rounded,
                    tooltip: 'المزامنة',
                    color: queue.pendingCount > 0 ? AppColors.warning : AppColors.success,
                    onPressed: () => context.push(RouteNames.offlineSync),
                    badge: queue.pendingCount > 0
                        ? Container(
                            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                            decoration: BoxDecoration(
                              color: AppColors.warning,
                              borderRadius: BorderRadius.circular(AppRadius.pill),
                              border: Border.all(color: scheme.surface, width: 1.5),
                            ),
                            child: Text(
                              '${queue.pendingCount}',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 9,
                                fontWeight: FontWeight.w800,
                                height: 1.3,
                              ),
                            ),
                          )
                        : null,
                  ),
                ),
                const SizedBox(width: 8),
                AnimatedBuilder(
                  animation: GetIt.I<AppThemeController>(),
                  builder: (context, _) {
                    final controller = GetIt.I<AppThemeController>();
                    final isDark = theme.brightness == Brightness.dark;
                    return AppIconButton(
                      icon: isDark ? Icons.light_mode_rounded : Icons.dark_mode_rounded,
                      tooltip: 'تبديل المظهر',
                      color: scheme.primary,
                      onPressed: () => controller.toggle(current: theme.brightness),
                    );
                  },
                ),
                const SizedBox(width: 8),
                PressableAvatar(
                  name: name,
                  imageUrl: avatar,
                  online: queue.isOnline,
                  onTap: () => context.push(RouteNames.profile),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class PressableAvatar extends StatelessWidget {
  const PressableAvatar({
    super.key,
    required this.name,
    required this.imageUrl,
    required this.online,
    required this.onTap,
  });

  final String name;
  final String? imageUrl;
  final bool? online;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AppAvatar(name: name, imageUrl: imageUrl, online: online, size: 42),
    );
  }
}

class _BottomNav extends StatelessWidget {
  const _BottomNav({required this.currentIndex, required this.onSelect});

  final int currentIndex;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(14, 0, 14, 10),
        child: Container(
          height: 70,
          clipBehavior: Clip.antiAlias,
          decoration: BoxDecoration(
            color: dark ? const Color(0xFF0E2029).withValues(alpha: .97) : Colors.white.withValues(alpha: .98),
            borderRadius: BorderRadius.circular(AppRadius.xl),
            border: Border.all(color: scheme.outlineVariant.withValues(alpha: dark ? .9 : .8)),
            boxShadow: AppShadows.floating(context),
          ),
          child: Row(
            children: <Widget>[
              for (var index = 0; index < _destinations.length; index++)
                Expanded(
                  child: _NavItem(
                    destination: _destinations[index],
                    selected: index == currentIndex,
                    onTap: () => onSelect(index),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.destination,
    required this.selected,
    required this.onTap,
  });

  final _ShellDestination destination;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return InkWell(
      onTap: onTap,
      splashColor: scheme.primary.withValues(alpha: .06),
      highlightColor: Colors.transparent,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          AnimatedContainer(
            duration: AppMotion.normal,
            curve: Curves.easeOutCubic,
            padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 6),
            decoration: BoxDecoration(
              color: selected ? scheme.primary.withValues(alpha: .12) : Colors.transparent,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: Icon(
              selected ? destination.selectedIcon : destination.icon,
              size: 22,
              color: selected ? scheme.primary : scheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 3),
          AnimatedDefaultTextStyle(
            duration: AppMotion.fast,
            style: TextStyle(
              fontSize: 10.5,
              height: 1.1,
              fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
              color: selected ? scheme.primary : scheme.onSurfaceVariant,
            ),
            child: Text(destination.label),
          ),
        ],
      ),
    );
  }
}

class _SideRail extends StatelessWidget {
  const _SideRail({required this.currentIndex, required this.onSelect});

  final int currentIndex;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final session = GetIt.I<SessionService>();
    final permissions = GetIt.I<PermissionService>();
    final user = session.user ?? const <String, dynamic>{};
    final name = user['name']?.toString() ?? 'موظف المشروع';
    final avatar = user['avatar']?.toString();
    final role = _roleLabel(permissions);

    return Container(
      width: 252,
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border(left: BorderSide(color: scheme.outlineVariant.withValues(alpha: .8))),
      ),
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 20, 16, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                children: <Widget>[
                  const AppBrandMark(size: 44, glow: false),
                  const SizedBox(width: 11),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(
                          AppConfig.appName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        Text(
                          'منصة إدارة المشروع',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.labelSmall,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 26),
              Padding(
                padding: const EdgeInsetsDirectional.only(start: 8, bottom: 8),
                child: Text(
                  'القائمة الرئيسية',
                  style: theme.textTheme.labelSmall?.copyWith(letterSpacing: .4),
                ),
              ),
              for (var index = 0; index < _destinations.length; index++)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: _RailItem(
                    destination: _destinations[index],
                    selected: index == currentIndex,
                    onTap: () => onSelect(index),
                  ),
                ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: scheme.surfaceContainerHighest.withValues(alpha: .5),
                  borderRadius: BorderRadius.circular(AppRadius.lg),
                  border: Border.all(color: scheme.outlineVariant.withValues(alpha: .7)),
                ),
                child: Row(
                  children: <Widget>[
                    AppAvatar(name: name, imageUrl: avatar, size: 38),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Text(
                            name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleSmall,
                          ),
                          Text(
                            role,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.labelSmall,
                          ),
                        ],
                      ),
                    ),
                    IconButton(
                      tooltip: 'الملف الشخصي',
                      onPressed: () => context.push(RouteNames.profile),
                      icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 15),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      'الإصدار 1.0.0',
                      style: theme.textTheme.labelSmall,
                    ),
                  ),
                  AnimatedBuilder(
                    animation: GetIt.I<AppThemeController>(),
                    builder: (context, _) {
                      final controller = GetIt.I<AppThemeController>();
                      return AppIconButton(
                        icon: theme.brightness == Brightness.dark
                            ? Icons.light_mode_rounded
                            : Icons.dark_mode_rounded,
                        tooltip: 'تبديل المظهر',
                        color: scheme.primary,
                        onPressed: () => controller.toggle(current: theme.brightness),
                        size: 38,
                      );
                    },
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _RailItem extends StatelessWidget {
  const _RailItem({
    required this.destination,
    required this.selected,
    required this.onTap,
  });

  final _ShellDestination destination;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: Colors.transparent,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.md),
        child: AnimatedContainer(
          duration: AppMotion.normal,
          curve: Curves.easeOutCubic,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
          decoration: BoxDecoration(
            gradient: selected ? AppColors.aquaGradient : null,
            borderRadius: BorderRadius.circular(AppRadius.md),
            boxShadow: selected ? AppShadows.brand(AppColors.primary, opacity: .2) : null,
          ),
          child: Row(
            children: <Widget>[
              Icon(
                selected ? destination.selectedIcon : destination.icon,
                size: 21,
                color: selected ? Colors.white : scheme.onSurfaceVariant,
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Text(
                  destination.label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
                    color: selected ? Colors.white : scheme.onSurface,
                  ),
                ),
              ),
              if (selected)
                Container(
                  width: 6,
                  height: 6,
                  decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

String _roleLabel(PermissionService permissions) {
  if (permissions.isAdmin) return 'مدير النظام';
  if (permissions.isCollector) return 'محصل ميداني';
  if (permissions.isReader) return 'قارئ عدادات';
  final roles = permissions.currentRoles;
  return roles.isEmpty ? 'موظف' : roles.first;
}
