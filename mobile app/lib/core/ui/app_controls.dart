import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_tokens.dart';

/// Primary action: a solid accent block with one icon and one label.
///
/// No gradient, no glow. The single saturated element per screen is usually
/// this button (or the selected tab), which is what makes the layout read as
/// calm.
class AppPrimaryButton extends StatelessWidget {
  const AppPrimaryButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
    this.gradient,
    this.height = 50,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;
  final bool expand;

  /// Deprecated: ignored. Kept for compatibility with older call sites.
  final Gradient? gradient;
  final double height;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final enabled = onPressed != null && !loading;
    final radius = BorderRadius.circular(AppRadius.md);
    final foreground = scheme.onPrimary;

    return Opacity(
      opacity: enabled ? 1 : .45,
      child: Container(
        width: expand ? double.infinity : null,
        height: height,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(color: scheme.primary, borderRadius: radius),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            onTap: enabled ? onPressed : null,
            borderRadius: radius,
            splashColor: foreground.withValues(alpha: .16),
            highlightColor: foreground.withValues(alpha: .08),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 18),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (loading)
                    SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: foreground,
                      ),
                    )
                  else if (icon != null)
                    Icon(icon, size: 18, color: foreground),
                  if (loading || icon != null) const SizedBox(width: 8),
                  Flexible(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: foreground,
                        fontWeight: FontWeight.w600,
                        fontSize: 13.5,
                        height: 1.2,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Quiet secondary action: transparent fill, hairline border, ink label.
class AppSecondaryButton extends StatelessWidget {
  const AppSecondaryButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.expand = true,
    this.height = 50,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool expand;
  final double height;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final radius = BorderRadius.circular(AppRadius.md);
    return Opacity(
      opacity: onPressed == null ? .45 : 1,
      child: SizedBox(
        width: expand ? double.infinity : null,
        height: height,
        child: Material(
          color: scheme.surface,
          borderRadius: radius,
          child: InkWell(
            onTap: onPressed,
            borderRadius: radius,
            child: Container(
              decoration: BoxDecoration(
                borderRadius: radius,
                border: Border.all(color: scheme.outlineVariant),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (icon != null) ...<Widget>[
                    Icon(icon, size: 17, color: scheme.onSurfaceVariant),
                    const SizedBox(width: 7),
                  ],
                  Flexible(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: scheme.onSurface,
                        fontWeight: FontWeight.w600,
                        fontSize: 13,
                        height: 1.2,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Quiet icon button used in headers and cards.
class AppIconButton extends StatelessWidget {
  const AppIconButton({
    super.key,
    required this.icon,
    this.onPressed,
    this.tooltip,
    this.color,
    this.size = 40,
    this.badge,
  });

  final IconData icon;
  final VoidCallback? onPressed;
  final String? tooltip;
  final Color? color;
  final double size;
  final Widget? badge;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final tiny = size <= 32;
    final button = Semantics(
      button: true,
      label: tooltip,
      enabled: onPressed != null,
      child: SizedBox.square(
        dimension: size < 48 ? 48 : size,
        child: Stack(
          clipBehavior: Clip.none,
          children: <Widget>[
            Positioned.fill(
              child: Material(
                color: tiny
                    ? Colors.transparent
                    : scheme.surfaceContainerHighest,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(size * .32),
                  side: tiny
                      ? BorderSide.none
                      : BorderSide(color: scheme.outlineVariant),
                ),
                clipBehavior: Clip.antiAlias,
                child: InkWell(
                  onTap: onPressed,
                  child: Icon(
                    icon,
                    size: size * .46,
                    color: color ?? scheme.onSurfaceVariant,
                  ),
                ),
              ),
            ),
            if (badge != null)
              PositionedDirectional(top: -3, end: -3, child: badge!),
          ],
        ),
      ),
    );
    return tooltip == null ? button : Tooltip(message: tooltip!, child: button);
  }
}

/// Square avatar with a deterministic fallback built from the name initials.
class AppAvatar extends StatelessWidget {
  const AppAvatar({
    super.key,
    required this.name,
    this.imageUrl,
    this.size = 46,
    this.online,
    this.ringColor,
    this.background,
    this.foreground,
  });

  final String name;
  final String? imageUrl;
  final double size;

  /// Optional connection dot: `true` green, `false` amber, `null` hidden.
  final bool? online;
  final Color? ringColor;
  final Color? background;
  final Color? foreground;

  String get _initials {
    final parts = name
        .trim()
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .toList();
    if (parts.isEmpty) return '؟';
    String firstChar(String value) =>
        value.isEmpty ? '' : value.substring(0, 1);
    if (parts.length == 1) {
      final single = parts.first;
      return single.length <= 2 ? single : single.substring(0, 2);
    }
    return '${firstChar(parts.first)}${firstChar(parts.last)}';
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final radius = BorderRadius.circular(size * .32);
    final bg =
        background ?? (dark ? AppColors.darkAccentSoft : AppColors.accentSoft);
    final fg =
        foreground ?? (dark ? AppColors.darkAccent : AppColors.accentStrong);

    final avatar = ClipRRect(
      borderRadius: radius,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          color: bg,
          borderRadius: radius,
          border: Border.all(color: ringColor ?? scheme.outlineVariant),
        ),
        alignment: Alignment.center,
        child: imageUrl != null && imageUrl!.trim().isNotEmpty
            ? Image.network(
                imageUrl!,
                fit: BoxFit.cover,
                width: size,
                height: size,
                errorBuilder: (context, error, stack) => _initialsText(fg),
              )
            : _initialsText(fg),
      ),
    );

    if (online == null) return avatar;

    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        clipBehavior: Clip.none,
        children: <Widget>[
          avatar,
          PositionedDirectional(
            bottom: -1,
            start: -1,
            child: Container(
              width: size * .28,
              height: size * .28,
              decoration: BoxDecoration(
                color: online! ? AppColors.success : AppColors.warning,
                shape: BoxShape.circle,
                border: Border.all(color: scheme.surface, width: 2),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _initialsText(Color color) => Text(
    _initials,
    style: TextStyle(
      color: color,
      fontSize: size * .32,
      fontWeight: FontWeight.w600,
      height: 1,
    ),
  );
}

/// Rounded label used for statuses, roles and metadata.
///
/// The body stays neutral; [color] is only applied to the icon so status
/// colour never turns a row into a paint swatch.
class AppPill extends StatelessWidget {
  const AppPill({
    super.key,
    required this.label,
    this.icon,
    this.color,
    this.dense = false,
    this.filled = false,
    this.onTap,
  });

  final String label;
  final IconData? icon;
  final Color? color;
  final bool dense;
  final bool filled;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final tint = AppSemantic.resolve(context, color ?? scheme.primary);
    final background = filled ? tint : scheme.surfaceContainerHighest;
    final foreground = filled
        ? (Theme.of(context).brightness == Brightness.dark
              ? AppColors.darkPaper
              : Colors.white)
        : scheme.onSurfaceVariant;

    final content = Container(
      padding: EdgeInsets.symmetric(
        horizontal: dense ? 9 : 11,
        vertical: dense ? 4 : 6,
      ),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(AppRadius.xs),
        border: filled ? null : Border.all(color: scheme.outlineVariant),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(
              icon,
              size: dense ? 12 : 13,
              color: filled ? foreground : tint,
            ),
            const SizedBox(width: 5),
          ],
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: foreground,
                fontSize: dense ? 10.5 : 11.5,
                fontWeight: FontWeight.w500,
                height: 1.2,
              ),
            ),
          ),
        ],
      ),
    );

    if (onTap == null) return content;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: content,
    );
  }
}

class AppSegmentedOption<T> {
  const AppSegmentedOption({
    required this.value,
    required this.label,
    this.icon,
  });

  final T value;
  final String label;
  final IconData? icon;
}

/// Flat segmented control used for filters and the theme mode.
class AppSegmentedControl<T> extends StatelessWidget {
  const AppSegmentedControl({
    super.key,
    required this.options,
    required this.value,
    required this.onChanged,
  });

  final List<AppSegmentedOption<T>> options;
  final T value;
  final ValueChanged<T> onChanged;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Row(
        children: options
            .map((option) {
              final selected = option.value == value;
              return Expanded(
                child: InkWell(
                  onTap: () => onChanged(option.value),
                  borderRadius: BorderRadius.circular(AppRadius.sm),
                  child: AnimatedContainer(
                    duration: AppMotion.normal,
                    curve: Curves.easeOutCubic,
                    padding: const EdgeInsets.symmetric(
                      vertical: 8,
                      horizontal: 6,
                    ),
                    decoration: BoxDecoration(
                      color: selected ? scheme.surface : Colors.transparent,
                      borderRadius: BorderRadius.circular(AppRadius.sm),
                      border: Border.all(
                        color: selected
                            ? scheme.outlineVariant
                            : Colors.transparent,
                      ),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: <Widget>[
                        if (option.icon != null) ...<Widget>[
                          Icon(
                            option.icon,
                            size: 14,
                            color: selected
                                ? scheme.onSurface
                                : scheme.onSurfaceVariant,
                          ),
                          const SizedBox(width: 5),
                        ],
                        Flexible(
                          child: Text(
                            option.label,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: selected
                                  ? FontWeight.w600
                                  : FontWeight.w500,
                              color: selected
                                  ? scheme.onSurface
                                  : scheme.onSurfaceVariant,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              );
            })
            .toList(growable: false),
      ),
    );
  }
}
