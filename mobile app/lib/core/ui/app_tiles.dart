import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_motion.dart';
import 'app_surfaces.dart';
import 'app_tokens.dart';
import 'app_viz.dart';

/// Squircle icon holder with a soft brand wash.
class AppIconBubble extends StatelessWidget {
  const AppIconBubble({
    super.key,
    required this.icon,
    required this.color,
    this.size = 46,
    this.radius,
    this.filled = false,
  });

  final IconData icon;
  final Color color;
  final double size;
  final double? radius;
  final bool filled;

  @override
  Widget build(BuildContext context) {
    final borderRadius = BorderRadius.circular(radius ?? size * .32);
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        gradient: filled ? LinearGradient(colors: <Color>[color, color.withValues(alpha: .75)]) : AppTint.gradient(color, context),
        borderRadius: borderRadius,
        border: Border.all(color: color.withValues(alpha: filled ? .15 : .18)),
      ),
      child: Icon(icon, size: size * .48, color: filled ? Colors.white : color),
    );
  }
}

/// Card used for a navigable module (grid and list variants).
class AppModuleTile extends StatelessWidget {
  const AppModuleTile({
    super.key,
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.color,
    required this.onTap,
    this.badge,
    this.list = false,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;
  final Widget? badge;
  final bool list;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (list) {
      return AppCard(
        padding: const EdgeInsets.all(14),
        onTap: onTap,
        child: Row(
          children: <Widget>[
            AppIconBubble(icon: icon, color: color),
            const SizedBox(width: 13),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      Flexible(
                        child: Text(
                          title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleMedium,
                        ),
                      ),
                      if (badge != null) ...<Widget>[const SizedBox(width: 8), badge!],
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(
                    subtitle,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Icon(Icons.arrow_back_ios_new_rounded, size: 13, color: theme.colorScheme.onSurfaceVariant),
          ],
        ),
      );
    }

    return AppCard(
      padding: const EdgeInsets.all(16),
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              AppIconBubble(icon: icon, color: color),
              const Spacer(),
              if (badge != null) badge!,
              const SizedBox(width: 6),
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: .7),
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  Icons.arrow_back_ios_new_rounded,
                  size: 11,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
          const Spacer(),
          Text(
            title,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800, height: 1.3),
          ),
          const SizedBox(height: 4),
          Text(
            subtitle,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

/// Responsive grid of module tiles.
class AppModuleGrid extends StatelessWidget {
  const AppModuleGrid({
    super.key,
    required this.children,
    this.minTileWidth = 210,
    this.tileHeight = 178,
    this.spacing = 12,
  });

  final List<Widget> children;
  final double minTileWidth;
  final double tileHeight;
  final double spacing;

  @override
  Widget build(BuildContext context) {
    if (children.isEmpty) return const SizedBox.shrink();
    return LayoutBuilder(
      builder: (context, constraints) {
        final available = constraints.maxWidth;
        final columns = (available / minTileWidth).floor().clamp(1, 4).toInt();
        final width = (available - spacing * (columns - 1)) / columns;
        return Wrap(
          spacing: spacing,
          runSpacing: spacing,
          children: children
              .map((child) => SizedBox(width: width, height: tileHeight, child: child))
              .toList(growable: false),
        );
      },
    );
  }
}

/// Horizontally scrolling module rail used on the overview page.
class AppModuleRail extends StatelessWidget {
  const AppModuleRail({
    super.key,
    required this.children,
    this.tileWidth = 228,
    this.tileHeight = 178,
    this.spacing = 12,
  });

  final List<Widget> children;
  final double tileWidth;
  final double tileHeight;
  final double spacing;

  @override
  Widget build(BuildContext context) {
    if (children.isEmpty) return const SizedBox.shrink();
    return SizedBox(
      height: tileHeight,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        clipBehavior: Clip.none,
        padding: const EdgeInsets.symmetric(horizontal: 1, vertical: 1),
        itemCount: children.length,
        separatorBuilder: (_, __) => SizedBox(width: spacing),
        itemBuilder: (context, index) => SizedBox(
          width: tileWidth,
          height: tileHeight,
          child: children[index],
        ),
      ),
    );
  }
}

/// Large tap target used for the four primary field actions.
class AppQuickAction extends StatelessWidget {
  const AppQuickAction({
    super.key,
    required this.label,
    required this.icon,
    required this.color,
    required this.onTap,
    this.caption,
  });

  final String label;
  final String? caption;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return PressableScale(
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              gradient: AppTint.gradient(color, context),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: color.withValues(alpha: .18)),
            ),
            child: Icon(icon, color: color, size: 25),
          ),
          const SizedBox(height: 8),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: theme.textTheme.labelMedium?.copyWith(fontWeight: FontWeight.w700),
          ),
          if (caption != null) ...<Widget>[
            const SizedBox(height: 1),
            Text(
              caption!,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: theme.textTheme.labelSmall,
            ),
          ],
        ],
      ),
    );
  }
}

/// KPI card with optional sparkline and trend.
class AppMetricCard extends StatelessWidget {
  const AppMetricCard({
    super.key,
    required this.title,
    required this.value,
    required this.icon,
    required this.color,
    this.caption,
    this.sparkline = const <double>[],
    this.trend,
    this.onTap,
  });

  final String title;
  final String value;
  final IconData icon;
  final Color color;
  final String? caption;
  final List<double> sparkline;
  final Widget? trend;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppCard(
      padding: const EdgeInsets.all(14),
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: <Widget>[
          Row(
            children: <Widget>[
              AppIconBubble(icon: icon, color: color, size: 40),
              const Spacer(),
              if (trend != null) trend!,
            ],
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Directionality(
                textDirection: TextDirection.ltr,
                child: Text(
                  value,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.start,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    height: 1.1,
                    letterSpacing: -.2,
                  ),
                ),
              ),
              const SizedBox(height: 2),
              Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600),
              ),
              if (caption != null) ...<Widget>[
                const SizedBox(height: 1),
                Text(
                  caption!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ],
          ),
          if (sparkline.length > 1)
            SizedBox(
              height: 22,
              child: AppSparkline(values: sparkline, color: color, height: 22),
            )
          else
            const SizedBox(height: 2),
        ],
      ),
    );
  }
}

/// Rounded settings/action row with an icon, labels and a trailing widget.
class AppSettingRow extends StatelessWidget {
  const AppSettingRow({
    super.key,
    required this.title,
    required this.icon,
    required this.color,
    this.subtitle,
    this.onTap,
    this.trailing,
    this.showChevron = true,
  });

  final String title;
  final String? subtitle;
  final IconData icon;
  final Color color;
  final VoidCallback? onTap;
  final Widget? trailing;
  final bool showChevron;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          children: <Widget>[
            AppIconBubble(icon: icon, color: color, size: 40),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(title, style: theme.textTheme.titleSmall),
                  if (subtitle != null) ...<Widget>[
                    const SizedBox(height: 2),
                    Text(
                      subtitle!,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ],
              ),
            ),
            if (trailing != null)
              trailing!
            else if (showChevron)
              Icon(Icons.arrow_back_ios_new_rounded, size: 13, color: theme.colorScheme.onSurfaceVariant),
          ],
        ),
      ),
    );
  }
}

/// Lightweight inline notice (info / warning / success / danger).
class AppNotice extends StatelessWidget {
  const AppNotice({
    super.key,
    required this.title,
    required this.message,
    this.icon = Icons.info_outline_rounded,
    this.color = AppColors.info,
    this.action,
  });

  final String title;
  final String message;
  final IconData icon;
  final Color color;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withValues(alpha: dark ? .14 : .07),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: color.withValues(alpha: dark ? .3 : .18)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          AppIconBubble(icon: icon, color: color, size: 36, radius: 12),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  title,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 2),
                Text(
                  message,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.5),
                ),
                if (action != null) ...<Widget>[const SizedBox(height: 8), action!],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Row of quick actions laid out evenly.
class AppQuickActionBar extends StatelessWidget {
  const AppQuickActionBar({super.key, required this.actions});

  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    if (actions.isEmpty) return const SizedBox.shrink();
    return Row(
      children: actions
          .map((action) => Expanded(child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 3),
                child: action,
              )))
          .toList(growable: false),
    );
  }
}

/// A compact strip of related numbers (queue depth, connection status…).
class AppStatItem {
  const AppStatItem({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color color;
}

class AppStatStrip extends StatelessWidget {
  const AppStatStrip({super.key, required this.items, this.description});

  final List<AppStatItem> items;
  final String? description;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return AppCard(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          LayoutBuilder(
            builder: (context, constraints) {
              final columns = constraints.maxWidth >= 430 ? items.length : 2;
              final width = (constraints.maxWidth - 12 * (columns - 1)) / columns;
              return Wrap(
                spacing: 12,
                runSpacing: 14,
                children: items
                    .map(
                      (item) => SizedBox(
                        width: width,
                        child: Row(
                          children: <Widget>[
                            AppIconBubble(icon: item.icon, color: item.color, size: 36, radius: 12),
                            const SizedBox(width: 9),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                mainAxisSize: MainAxisSize.min,
                                children: <Widget>[
                                  Text(
                                    item.value,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: theme.textTheme.titleMedium?.copyWith(
                                      fontWeight: FontWeight.w800,
                                    ),
                                  ),
                                  Text(
                                    item.label,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: theme.textTheme.labelSmall,
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    )
                    .toList(growable: false),
              );
            },
          ),
          if (description != null) ...<Widget>[
            const SizedBox(height: 12),
            Container(height: 1, color: scheme.outlineVariant.withValues(alpha: .6)),
            const SizedBox(height: 10),
            Text(description!, style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }
}

/// Responsive grid for KPI cards.
class AppMetricGrid extends StatelessWidget {
  const AppMetricGrid({
    super.key,
    required this.children,
    this.minTileWidth = 168,
    this.tileHeight = 156,
    this.spacing = 12,
  });

  final List<Widget> children;
  final double minTileWidth;
  final double tileHeight;
  final double spacing;

  @override
  Widget build(BuildContext context) {
    if (children.isEmpty) return const SizedBox.shrink();
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = (constraints.maxWidth / minTileWidth).floor().clamp(1, 4).toInt();
        final width = (constraints.maxWidth - spacing * (columns - 1)) / columns;
        return Wrap(
          spacing: spacing,
          runSpacing: spacing,
          children: children
              .map((child) => SizedBox(width: width, height: tileHeight, child: child))
              .toList(growable: false),
        );
      },
    );
  }
}
