import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_tokens.dart';

/// Gradient primary action. Ink effects are painted above the gradient so the
/// button still feels native while looking custom.
class AppPrimaryButton extends StatelessWidget {
  const AppPrimaryButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
    this.gradient = AppColors.aquaGradient,
    this.height = 52,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;
  final bool expand;
  final Gradient gradient;
  final double height;

  @override
  Widget build(BuildContext context) {
    final enabled = onPressed != null && !loading;
    final radius = BorderRadius.circular(AppRadius.md);
    return Opacity(
      opacity: enabled ? 1 : .55,
      child: Container(
        width: expand ? double.infinity : null,
        height: height,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          gradient: gradient,
          borderRadius: radius,
          boxShadow: enabled ? AppShadows.brand(AppColors.primary, opacity: .22) : null,
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            onTap: enabled ? onPressed : null,
            borderRadius: radius,
            splashColor: Colors.white.withValues(alpha: .18),
            highlightColor: Colors.white.withValues(alpha: .08),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (loading)
                    const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2.1, color: Colors.white),
                    )
                  else if (icon != null)
                    Icon(icon, size: 19, color: Colors.white),
                  if (loading || icon != null) const SizedBox(width: 9),
                  Flexible(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        fontSize: 14.5,
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

/// Quiet secondary action that sits next to a primary button.
class AppSecondaryButton extends StatelessWidget {
  const AppSecondaryButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.expand = true,
    this.height = 52,
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
    return SizedBox(
      width: expand ? double.infinity : null,
      height: height,
      child: Material(
        color: scheme.surface.withValues(alpha: .92),
        borderRadius: radius,
        child: InkWell(
          onTap: onPressed,
          borderRadius: radius,
          child: Container(
            decoration: BoxDecoration(
              borderRadius: radius,
              border: Border.all(color: scheme.outlineVariant),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 18),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (icon != null) ...<Widget>[
                  Icon(icon, size: 18, color: scheme.primary),
                  const SizedBox(width: 8),
                ],
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: scheme.onSurface,
                      fontWeight: FontWeight.w700,
                      fontSize: 13.5,
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

/// Round icon button used in headers and cards.
class AppIconButton extends StatelessWidget {
  const AppIconButton({
    super.key,
    required this.icon,
    this.onPressed,
    this.tooltip,
    this.color,
    this.size = 42,
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
    final tint = color ?? scheme.onSurface;
    final button = SizedBox.square(
      dimension: size,
      child: Stack(
        clipBehavior: Clip.none,
        children: <Widget>[
          Positioned.fill(
            child: Material(
              color: color == null
                  ? scheme.surface
                  : tint.withValues(alpha: .12),
              shape: const CircleBorder(side: BorderSide(color: Colors.transparent)),
              clipBehavior: Clip.antiAlias,
              child: InkWell(
                onTap: onPressed,
                child: Icon(icon, size: size * .46, color: tint),
              ),
            ),
          ),
          if (badge != null)
            PositionedDirectional(top: 2, end: 2, child: badge!),
        ],
      ),
    );
    return tooltip == null ? button : Tooltip(message: tooltip!, child: button);
  }
}

/// Circular avatar with a deterministic gradient fallback built from initials.
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
  final bool? online;
  final Color? ringColor;
  final Color? background;
  final Color? foreground;

  String get _initials {
    final parts = name.trim().split(RegExp(r'\s+')).where((part) => part.isNotEmpty).toList();
    if (parts.isEmpty) return '؟';
    String firstChar(String value) => value.isEmpty ? '' : value.substring(0, 1);
    if (parts.length == 1) {
      final single = parts.first;
      return single.length <= 2 ? single : single.substring(0, 2);
    }
    return '${firstChar(parts.first)}${firstChar(parts.last)}';
  }

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(size * .36);
    final avatar = ClipRRect(
      borderRadius: radius,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          gradient: background == null ? AppColors.oceanGradient : null,
          color: background,
          borderRadius: radius,
          border: Border.all(
            color: ringColor ?? Colors.white.withValues(alpha: .5),
            width: 1.4,
          ),
        ),
        alignment: Alignment.center,
        child: imageUrl != null && imageUrl!.trim().isNotEmpty
            ? Image.network(
                imageUrl!,
                fit: BoxFit.cover,
                width: size,
                height: size,
                errorBuilder: (context, error, stack) => _initialsText(),
              )
            : _initialsText(),
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
              width: size * .3,
              height: size * .3,
              decoration: BoxDecoration(
                color: online! ? AppColors.success : AppColors.warning,
                shape: BoxShape.circle,
                border: Border.all(color: Theme.of(context).colorScheme.surface, width: 2),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _initialsText() => Text(
        _initials,
        style: TextStyle(
          color: foreground ?? Colors.white,
          fontSize: size * .34,
          fontWeight: FontWeight.w800,
          height: 1,
        ),
      );
}

/// Rounded tinted label used for statuses, roles and metadata.
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
    final tint = color ?? scheme.primary;
    final background = filled
        ? tint
        : tint.withValues(alpha: Theme.of(context).brightness == Brightness.dark ? .2 : .1);
    final foreground = filled ? Colors.white : tint;

    final content = Container(
      padding: EdgeInsets.symmetric(
        horizontal: dense ? 9 : 12,
        vertical: dense ? 4 : 6.5,
      ),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: tint.withValues(alpha: filled ? .0 : .22)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: dense ? 12 : 14, color: foreground),
            const SizedBox(width: 5),
          ],
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: foreground,
                fontSize: dense ? 11 : 12,
                fontWeight: FontWeight.w700,
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
  const AppSegmentedOption({required this.value, required this.label, this.icon});

  final T value;
  final String label;
  final IconData? icon;
}

/// Animated pill segmented control (used for theme mode and filters).
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
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: dark ? AppColors.darkBackground : AppColors.lightSurfaceAlt,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: scheme.outlineVariant.withValues(alpha: .7)),
      ),
      child: Row(
        children: options.map((option) {
          final selected = option.value == value;
          return Expanded(
            child: InkWell(
              onTap: () => onChanged(option.value),
              borderRadius: BorderRadius.circular(AppRadius.pill),
              child: AnimatedContainer(
                duration: AppMotion.normal,
                curve: Curves.easeOutCubic,
                padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 6),
                decoration: BoxDecoration(
                  color: selected ? scheme.surface : Colors.transparent,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                  boxShadow: selected
                      ? <BoxShadow>[
                          BoxShadow(
                            color: Colors.black.withValues(alpha: dark ? .35 : .07),
                            blurRadius: 10,
                            offset: const Offset(0, 3),
                          ),
                        ]
                      : null,
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: <Widget>[
                    if (option.icon != null) ...<Widget>[
                      Icon(
                        option.icon,
                        size: 15,
                        color: selected ? scheme.primary : scheme.onSurfaceVariant,
                      ),
                      const SizedBox(width: 5),
                    ],
                    Flexible(
                      child: Text(
                        option.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 12.5,
                          fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
                          color: selected ? scheme.onSurface : scheme.onSurfaceVariant,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        }).toList(growable: false),
      ),
    );
  }
}
