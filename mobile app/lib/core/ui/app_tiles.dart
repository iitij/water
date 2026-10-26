import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_motion.dart';
import 'app_surfaces.dart';
import 'app_tokens.dart';
import 'app_viz.dart';

/// Squircle icon holder with a very light colour wash.
///
/// The wash is 6–7% in light mode, so a grid of tiles reads as one neutral
/// surface with quiet icons rather than a patchwork of colours.
class AppIconBubble extends StatelessWidget {
  const AppIconBubble({
    super.key,
    required this.icon,
    required this.color,
    this.size = 40,
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
    final dark = Theme.of(context).brightness == Brightness.dark;
    final tint = dark ? AppSemantic.resolve(context, color) : color;
    final borderRadius = BorderRadius.circular(radius ?? size * .3);
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: filled ? tint : AppTint.soft(color, context),
        borderRadius: borderRadius,
        border: Border.all(
          color: filled ? Colors.transparent : tint.withValues(alpha: .16),
        ),
      ),
      child: Icon(icon, size: size * .46, color: filled ? Colors.white : tint),
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
        padding: const EdgeInsets.all(13),
        onTap: onTap,
        child: Row(
          children: <Widget>[
            AppIconBubble(icon: icon, color: color),
            const SizedBox(width: 12),
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
                      if (badge != null) ...<Widget>[
                        const SizedBox(width: 8),
                        badge!,
                      ],
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 6),
            Icon(
              Icons.chevron_left_rounded,
              size: 18,
              color: theme.colorScheme.onSurfaceVariant.withValues(alpha: .7),
            ),
          ],
        ),
      );
    }

    return AppCard(
      padding: const EdgeInsets.all(14),
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              AppIconBubble(icon: icon, color: color),
              const Spacer(),
              if (badge != null) badge!,
            ],
          ),
          const Spacer(),
          Text(
            title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            subtitle,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(height: 1.45),
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
    this.minTileWidth = 200,
    this.tileHeight = 152,
    this.spacing = 10,
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
              .map(
                (child) =>
                    SizedBox(width: width, height: tileHeight, child: child),
              )
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
    this.tileWidth = 208,
    this.tileHeight = 152,
    this.spacing = 10,
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

/// Tap target used for the four primary shortcuts on the home page.
///
/// A plain icon and a label — the shortcut strip is one card with hairline
/// dividers, so the individual actions stay quiet.
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
    final dark = Theme.of(context).brightness == Brightness.dark;
    final tint = dark ? AppSemantic.resolve(context, color) : color;
    return PressableScale(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 2),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(icon, size: 22, color: tint),
            const SizedBox(height: 7),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w500,
                color: theme.colorScheme.onSurface,
              ),
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
      ),
    );
  }
}

/// KPI card: quiet label, dominant figure, one line of context.
class AppMetricCard extends StatelessWidget {
  const AppMetricCard({
    super.key,
    required this.title,
    required this.value,
    required this.icon,
    required this.color,
    this.caption,
    this.unit,
    this.sparkline = const <double>[],
    this.trend,
    this.onTap,
  });

  final String title;
  final String value;
  final IconData icon;
  final Color color;
  final String? caption;

  /// Optional currency or unit shown, in Arabic, to the left of [value].
  final String? unit;
  final List<double> sparkline;
  final Widget? trend;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return AppCard(
      padding: const EdgeInsets.fromLTRB(14, 13, 14, 13),
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
              if (trend != null)
                trend!
              else
                Icon(
                  icon,
                  size: 15,
                  color: scheme.onSurfaceVariant.withValues(alpha: .65),
                ),
            ],
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                textDirection: TextDirection.rtl,
                mainAxisSize: MainAxisSize.min,
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
                          style: theme.textTheme.headlineSmall?.copyWith(
                            fontWeight: FontWeight.w600,
                            height: 1.1,
                            letterSpacing: -.5,
                          ),
                        ),
                      ),
                    ),
                  ),
                  if (unit != null) ...<Widget>[
                    const SizedBox(width: 4),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 3),
                      child: Text(
                        unit!,
                        style: theme.textTheme.labelSmall?.copyWith(
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                  ],
                ],
              ),
              if (caption != null) ...<Widget>[
                const SizedBox(height: 3),
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
              height: 18,
              child: AppSparkline(
                values: sparkline,
                color: scheme.primary,
                height: 18,
              ),
            ),
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
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          children: <Widget>[
            AppIconBubble(icon: icon, color: color, size: 36),
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
              Icon(
                Icons.chevron_left_rounded,
                size: 18,
                color: theme.colorScheme.onSurfaceVariant.withValues(alpha: .7),
              ),
          ],
        ),
      ),
    );
  }
}

/// Inline notice (info / warning / success / danger).
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
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final tint = dark ? AppSemantic.resolve(context, color) : color;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.only(top: 1),
            child: Icon(icon, size: 17, color: tint),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(title, style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(
                  message,
                  style: theme.textTheme.bodySmall?.copyWith(height: 1.55),
                ),
                if (action != null) ...<Widget>[
                  const SizedBox(height: 6),
                  action!,
                ],
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
    final divider = Theme.of(context).colorScheme.outlineVariant;
    final children = <Widget>[];
    for (var index = 0; index < actions.length; index++) {
      if (index > 0) {
        children.add(Container(width: 1, height: 34, color: divider));
      }
      children.add(Expanded(child: actions[index]));
    }
    return Row(children: children);
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
    return AppCard(
      padding: const EdgeInsets.all(10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          LayoutBuilder(
            builder: (context, constraints) {
              final scale = MediaQuery.textScalerOf(
                context,
              ).scale(1).clamp(1.0, 2.0);
              final columns = (constraints.maxWidth / (160 * scale))
                  .floor()
                  .clamp(1, items.length.clamp(1, 4));
              final width =
                  (constraints.maxWidth - 10 * (columns - 1)) / columns;
              return Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [
                  for (var index = 0; index < items.length; index++)
                    SizedBox(
                      // An odd final metric spans the row instead of leaving a hole.
                      width:
                          index == items.length - 1 &&
                              items.length % columns == 1
                          ? constraints.maxWidth
                          : width,
                      child: _StatCell(item: items[index]),
                    ),
                ],
              );
            },
          ),
          if (description != null) ...[
            const SizedBox(height: 12),
            const AppDivider(),
            const SizedBox(height: 10),
            Text(description!, style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }
}

class _StatCell extends StatelessWidget {
  const _StatCell({required this.item});
  final AppStatItem item;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final tone = AppSemantic.resolve(context, item.color);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: .52),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(item.label, style: theme.textTheme.labelSmall),
              ),
              const SizedBox(width: 6),
              Icon(item.icon, size: 17, color: tone),
            ],
          ),
          const SizedBox(height: 9),
          AppAmount(
            value: item.value,
            style: theme.textTheme.headlineSmall?.copyWith(
              color: scheme.onSurface,
              fontWeight: FontWeight.w700,
              height: 1.25,
            ),
          ),
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
    this.minTileWidth = 160,
    this.tileHeight = 118,
    this.spacing = 10,
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
        final columns = (constraints.maxWidth / minTileWidth)
            .floor()
            .clamp(1, 4)
            .toInt();
        final width =
            (constraints.maxWidth - spacing * (columns - 1)) / columns;
        return Wrap(
          spacing: spacing,
          runSpacing: spacing,
          children: children
              .map(
                (child) =>
                    SizedBox(width: width, height: tileHeight, child: child),
              )
              .toList(growable: false),
        );
      },
    );
  }
}
