import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../auth/session_service.dart';
import '../l10n/app_strings.dart';
import '../permissions/permission_service.dart';
import '../router/route_names.dart';
import '../theme/app_colors.dart';
import '../theme/app_theme.dart';
import '../theme/app_typography.dart';
import '../ui/app_brand.dart';
import '../ui/app_controls.dart';
import '../ui/app_surfaces.dart';
import '../ui/app_tokens.dart';
import '../utils/arabic_date.dart';

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

/// وجهات التنقل — تُبنى من `AppStrings` حتى لا تتكرر النصوص في الشاشات (S5-04).
List<_ShellDestination> _buildDestinations(AppStrings strings) =>
    <_ShellDestination>[
      _ShellDestination(
        label: strings.navHome,
        title: strings.homeTitle,
        subtitle: strings.homeSubtitle,
        icon: Icons.dashboard_outlined,
        selectedIcon: Icons.dashboard_rounded,
      ),
      _ShellDestination(
        label: strings.navField,
        title: strings.fieldTitle,
        subtitle: strings.fieldSubtitle,
        icon: Icons.sensors_outlined,
        selectedIcon: Icons.sensors_rounded,
      ),
      _ShellDestination(
        label: strings.navFinance,
        title: strings.financeTitle,
        subtitle: strings.financeSubtitle,
        icon: Icons.account_balance_wallet_outlined,
        selectedIcon: Icons.account_balance_wallet_rounded,
      ),
      _ShellDestination(
        label: strings.navSubscribers,
        title: strings.subscribersTitle,
        subtitle: strings.subscribersSubtitle,
        icon: Icons.groups_2_outlined,
        selectedIcon: Icons.groups_2_rounded,
      ),
      _ShellDestination(
        label: strings.navMore,
        title: strings.moreTitle,
        subtitle: strings.moreSubtitle,
        icon: Icons.grid_view_outlined,
        selectedIcon: Icons.grid_view_rounded,
      ),
    ];

/// Application frame: a hairline header, a flat floating tab bar on phones and
/// a quiet rail on wide screens. Pages provide their own panes and sections.
class AppHomeShell extends StatelessWidget {
  const AppHomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final wide = width >= 1020;
    final destinations = _buildDestinations(AppStrings.of(context));
    final index = shell.currentIndex.clamp(0, destinations.length - 1).toInt();

    void select(int next) =>
        shell.goBranch(next, initialLocation: next == shell.currentIndex);

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
          _TopBar(destination: destinations[index], home: index == 0),
          Expanded(child: shell),
        ],
      ),
      bottomNavigationBar: _BottomNav(currentIndex: index, onSelect: select),
      extendBody: true,
    );
  }
}

class _TopBar extends StatelessWidget {
  const _TopBar({required this.destination, required this.home});

  final _ShellDestination destination;
  final bool home;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final session = GetIt.I<SessionService>();
    final queue = GetIt.I<SyncQueueManager>();
    final user = session.user ?? const <String, dynamic>{};
    final name = user['name']?.toString() ?? 'موظف المشروع';
    final avatar = user['avatar']?.toString();
    final now = DateTime.now();

    final title = home
        ? '${ArabicDate.greeting(now)}، ${ArabicDate.firstName(name)}'
        : destination.title;
    final subtitle = home ? ArabicDate.full(now) : destination.subtitle;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: theme.brightness == Brightness.dark
          ? SystemUiOverlayStyle.light
          : SystemUiOverlayStyle.dark,
      child: ColoredBox(
        color: theme.scaffoldBackgroundColor,
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 14, 8),
            child: Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ),
                ),
                IconButton(
                  tooltip: 'البحث الشامل',
                  visualDensity: VisualDensity.compact,
                  onPressed: () => context.push(RouteNames.globalSearch),
                  icon: const Icon(Icons.search_rounded, size: 21),
                ),
                AnimatedBuilder(
                  animation: queue,
                  builder: (context, _) => _SyncButton(
                    online: queue.isOnline,
                    pending: queue.pendingCount,
                    onTap: () => context.push(RouteNames.offlineSync),
                  ),
                ),
                const SizedBox(width: 10),
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

/// Cloud glyph with a status dot: the only ambient element in the header.
class _SyncButton extends StatelessWidget {
  const _SyncButton({
    required this.online,
    required this.pending,
    required this.onTap,
  });

  final bool? online;
  final int pending;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final icon = online == false
        ? Icons.cloud_off_rounded
        : pending > 0
        ? Icons.cloud_upload_outlined
        : Icons.cloud_done_rounded;
    final dot = online == false
        ? AppColors.warning
        : pending > 0
        ? AppColors.warning
        : AppColors.success;

    return Semantics(
      button: true,
      label: 'المزامنة',
      child: InkResponse(
        onTap: onTap,
        radius: 22,
        child: SizedBox(
          width: 48,
          height: 48,
          child: Stack(
            alignment: Alignment.center,
            children: <Widget>[
              Icon(icon, size: 21, color: scheme.onSurfaceVariant),
              PositionedDirectional(
                top: 8,
                end: 6,
                child: Container(
                  width: 7,
                  height: 7,
                  decoration: BoxDecoration(
                    color: dot,
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: Theme.of(context).scaffoldBackgroundColor,
                      width: 1.5,
                    ),
                  ),
                ),
              ),
            ],
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
      child: AppAvatar(
        name: name,
        imageUrl: imageUrl,
        online: online,
        size: 38,
      ),
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
    final destinations = _buildDestinations(AppStrings.of(context));
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(14, 0, 14, 10),
        child: Container(
          height: 38 + MediaQuery.textScalerOf(context).scale(11) * 3,
          clipBehavior: Clip.antiAlias,
          decoration: BoxDecoration(
            color: scheme.surface,
            borderRadius: BorderRadius.circular(AppRadius.xl),
            border: Border.all(color: scheme.outlineVariant),
            boxShadow: AppShadows.floating(context),
          ),
          child: Row(
            children: <Widget>[
              for (var index = 0; index < destinations.length; index++)
                Expanded(
                  child: _NavItem(
                    destination: destinations[index],
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
    return Semantics(
      button: true,
      selected: selected,
      label: destination.label,
      child: InkWell(
        onTap: onTap,
        splashColor: scheme.primary.withValues(alpha: .05),
        highlightColor: Colors.transparent,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            AnimatedContainer(
              duration: AppMotion.normal,
              curve: Curves.easeOutCubic,
              width: 30,
              height: 22,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: selected
                    ? scheme.primary.withValues(alpha: .1)
                    : Colors.transparent,
                borderRadius: BorderRadius.circular(AppRadius.xs),
              ),
              child: Icon(
                selected ? destination.selectedIcon : destination.icon,
                size: 19,
                color: selected ? scheme.primary : scheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 3),
            AnimatedDefaultTextStyle(
              duration: AppMotion.fast,
              style: TextStyle(
                fontFamily: AppTypography.fontFamily,
                fontSize: 11,
                height: 1.15,
                fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
                color: selected ? scheme.primary : scheme.onSurfaceVariant,
              ),
              child: Text(
                destination.label,
                textAlign: TextAlign.center,
                maxLines: 3,
              ),
            ),
          ],
        ),
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
    final destinations = _buildDestinations(AppStrings.of(context));
    final session = GetIt.I<SessionService>();
    final permissions = GetIt.I<PermissionService>();
    final queue = GetIt.I<SyncQueueManager>();
    final user = session.user ?? const <String, dynamic>{};
    final name = user['name']?.toString() ?? 'موظف المشروع';
    final avatar = user['avatar']?.toString();
    final role = _roleLabel(permissions);

    return Container(
      width: 244,
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border(left: BorderSide(color: scheme.outlineVariant)),
      ),
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(14, 20, 14, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              const AppBrandLockup(
                markSize: 38,
                subtitle: 'منصة إدارة المشروع',
                onDark: false,
              ),
              const SizedBox(height: 18),
              Material(
                color: scheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(AppRadius.md),
                child: InkWell(
                  onTap: () => context.push(RouteNames.globalSearch),
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 10,
                    ),
                    child: Row(
                      children: <Widget>[
                        Icon(
                          Icons.search_rounded,
                          size: 18,
                          color: scheme.onSurfaceVariant,
                        ),
                        const SizedBox(width: 8),
                        Text('بحث شامل', style: theme.textTheme.bodySmall),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 18),
              Padding(
                padding: const EdgeInsetsDirectional.only(start: 8, bottom: 8),
                child: Text(
                  'القائمة الرئيسية',
                  style: theme.textTheme.labelSmall?.copyWith(
                    letterSpacing: .3,
                  ),
                ),
              ),
              Expanded(
                child: ListView(
                  padding: EdgeInsets.zero,
                  children: <Widget>[
                    for (var index = 0; index < destinations.length; index++)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 2),
                        child: _RailItem(
                          destination: destinations[index],
                          selected: index == currentIndex,
                          onTap: () => onSelect(index),
                        ),
                      ),
                    const SizedBox(height: 14),
                    const AppDivider(),
                    const SizedBox(height: 10),
                    Padding(
                      padding: const EdgeInsetsDirectional.only(
                        start: 8,
                        bottom: 8,
                      ),
                      child: Text(
                        'النظام والحساب',
                        style: theme.textTheme.labelSmall?.copyWith(
                          letterSpacing: .3,
                        ),
                      ),
                    ),
                    _RailActionItem(
                      icon: Icons.apps_rounded,
                      label: 'كل الخدمات',
                      onTap: () => context.push(RouteNames.services),
                    ),
                    _RailActionItem(
                      icon: Icons.sync_rounded,
                      label: 'مراقب المزامنة',
                      badge: queue.pendingCount > 0
                          ? '${queue.pendingCount}'
                          : null,
                      badgeColor: queue.failedCount > 0
                          ? AppColors.danger
                          : AppColors.warning,
                      onTap: () => context.push(RouteNames.offlineSync),
                    ),
                    _RailActionItem(
                      icon: Icons.lock_outline_rounded,
                      label: 'قفل التطبيق',
                      onTap: () => context.push(RouteNames.appLock),
                    ),
                    _RailActionItem(
                      icon: Icons.route_rounded,
                      label: 'وضع المسار',
                      onTap: () => context.push(RouteNames.routeMode),
                    ),
                    _RailActionItem(
                      icon: Icons.health_and_safety_outlined,
                      label: 'التشخيص والدعم',
                      onTap: () => context.push(RouteNames.diagnostics),
                    ),
                    _RailActionItem(
                      icon: Icons.sync_alt_rounded,
                      label: 'سجلات API',
                      onTap: () => context.push(RouteNames.apiLogs),
                    ),
                  ],
                ),
              ),
              const AppDivider(),
              const SizedBox(height: 12),
              Row(
                children: <Widget>[
                  AppAvatar(name: name, imageUrl: avatar, size: 36),
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
                    icon: const Icon(Icons.chevron_left_rounded, size: 18),
                  ),
                ],
              ),
              const SizedBox(height: 6),
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
                      return IconButton(
                        tooltip: 'تبديل المظهر',
                        onPressed: () =>
                            controller.toggle(current: theme.brightness),
                        icon: Icon(
                          theme.brightness == Brightness.dark
                              ? Icons.light_mode_outlined
                              : Icons.dark_mode_outlined,
                          size: 18,
                          color: scheme.onSurfaceVariant,
                        ),
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
      borderRadius: BorderRadius.circular(AppRadius.sm),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.sm),
        child: AnimatedContainer(
          duration: AppMotion.normal,
          curve: Curves.easeOutCubic,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
          decoration: BoxDecoration(
            color: selected
                ? scheme.primary.withValues(alpha: .09)
                : Colors.transparent,
            borderRadius: BorderRadius.circular(AppRadius.sm),
          ),
          child: Row(
            children: <Widget>[
              Icon(
                selected ? destination.selectedIcon : destination.icon,
                size: 19,
                color: selected ? scheme.primary : scheme.onSurfaceVariant,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  destination.label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
                    color: selected ? scheme.primary : scheme.onSurface,
                  ),
                ),
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

class _RailActionItem extends StatelessWidget {
  const _RailActionItem({
    required this.icon,
    required this.label,
    required this.onTap,
    this.badge,
    this.badgeColor,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final String? badge;
  final Color? badgeColor;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: Colors.transparent,
      borderRadius: BorderRadius.circular(AppRadius.sm),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.sm),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Row(
            children: <Widget>[
              Icon(icon, size: 18, color: scheme.onSurfaceVariant),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w500,
                    color: scheme.onSurface,
                  ),
                ),
              ),
              if (badge != null)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 6,
                    vertical: 1.5,
                  ),
                  decoration: BoxDecoration(
                    color: (badgeColor ?? scheme.primary).withValues(
                      alpha: .15,
                    ),
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                  ),
                  child: Text(
                    badge!,
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: badgeColor ?? scheme.primary,
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
