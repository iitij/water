import 'package:flutter/material.dart';

import '../l10n/experience_strings.dart';
import '../theme/app_colors.dart';
import 'app_tokens.dart';

part 'parts/app_filter_inputs.dart';

/// Filter primitives.
///
/// Every filter surface in the app is assembled from this file so the same
/// gesture reads the same way on every screen:
///
///   1. [AppFilterBar]      — search + one filter button that counts facets.
///   2. [AppFilterChips]    — a single scrolling rail of quick scopes.
///   3. [AppActiveFilters]  — what is on right now, removable, always visible.
///   4. [AppFilterSheet]    — every advanced field, one sheet, one apply.
///
/// Selection is explicit; interactive controls retain a 48dp minimum target.
/// A sheet applies its fields once, from its footer button.

/// A small, quiet chip used in rails and active-filter rows.
class AppFilterChip extends StatelessWidget {
  const AppFilterChip({
    super.key,
    required this.label,
    this.selected = false,
    this.onTap,
    this.icon,
    this.count,
    this.onRemove,
    this.dense = false,
  });

  final String label;
  final bool selected;
  final VoidCallback? onTap;
  final IconData? icon;

  /// Optional trailing count. Pass `null` when the number is unknown.
  final int? count;

  /// Renders a removal glyph on the trailing edge instead of a count.
  final VoidCallback? onRemove;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final foreground = selected ? scheme.primary : scheme.onSurfaceVariant;
    const height = 48.0;

    return Semantics(
      button: true,
      selected: selected,
      child: Material(
        color: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.sm),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onRemove ?? onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: height),
            child: Container(
              margin: const EdgeInsets.symmetric(vertical: 6),
              padding: EdgeInsets.symmetric(
                horizontal: dense ? 10 : 12,
                vertical: 8,
              ),
              decoration: BoxDecoration(
                color: selected
                    ? scheme.primary.withValues(alpha: .12)
                    : Colors.transparent,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (icon != null) ...<Widget>[
                    Icon(icon, size: dense ? 14 : 15, color: foreground),
                    SizedBox(width: dense ? 4 : 6),
                  ],
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 240),
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelMedium?.copyWith(
                        color: foreground,
                        fontWeight: selected
                            ? FontWeight.w600
                            : FontWeight.w500,
                        fontFeatures: const <FontFeature>[
                          FontFeature.tabularFigures(),
                        ],
                      ),
                    ),
                  ),
                  if (count != null) ...<Widget>[
                    const SizedBox(width: 6),
                    Text(
                      '$count',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: foreground,
                        fontWeight: FontWeight.w500,
                        fontFeatures: const <FontFeature>[
                          FontFeature.tabularFigures(),
                        ],
                      ),
                    ),
                  ],
                  if (onRemove != null) ...<Widget>[
                    const SizedBox(width: 4),
                    Icon(Icons.close_rounded, size: 14, color: foreground),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A single scrolling rail. Never wraps: two rows of chips read as a menu.
class AppFilterChips extends StatelessWidget {
  const AppFilterChips({
    super.key,
    required this.children,
    this.padding = const EdgeInsets.symmetric(horizontal: AppSpacing.md),
    this.spacing = AppSpacing.xs,
  });

  final List<Widget> children;
  final EdgeInsetsGeometry padding;
  final double spacing;

  @override
  Widget build(BuildContext context) {
    if (children.isEmpty) return const SizedBox.shrink();
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: padding,
      child: Row(
        children: <Widget>[
          for (var index = 0; index < children.length; index++) ...<Widget>[
            if (index > 0) SizedBox(width: spacing),
            children[index],
          ],
        ],
      ),
    );
  }
}

/// The app's only search treatment: a filled field and an optional filter
/// button that carries the number of applied facets.
class AppFilterBar extends StatefulWidget {
  const AppFilterBar({
    super.key,
    this.controller,
    this.query,
    this.hint = ExperienceStrings.search,
    this.onChanged,
    this.onSubmitted,
    this.autofocus = false,
    this.onOpenFilters,
    this.activeCount = 0,
    this.onClear,
    this.trailing,
    this.padding = const EdgeInsets.symmetric(horizontal: AppSpacing.md),
  });

  /// External controller. Leave null and pass [query] to let the bar own it.
  final TextEditingController? controller;

  /// Controlled text. When it differs from what is typed, the field resyncs —
  /// so "مسح الكل" can empty the box from outside.
  final String? query;
  final String hint;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final bool autofocus;
  final VoidCallback? onOpenFilters;

  /// Drives the badge on the filter button; hidden at zero.
  final int activeCount;
  final VoidCallback? onClear;
  final Widget? trailing;
  final EdgeInsetsGeometry padding;

  @override
  State<AppFilterBar> createState() => _AppFilterBarState();
}

class _AppFilterBarState extends State<AppFilterBar> {
  TextEditingController? _own;

  TextEditingController get _controller =>
      widget.controller ??
      (_own ??= TextEditingController(text: widget.query ?? ''));

  @override
  void didUpdateWidget(covariant AppFilterBar oldWidget) {
    super.didUpdateWidget(oldWidget);
    final query = widget.query;
    if (query != null && _controller.text != query) {
      _controller.value = TextEditingValue(
        text: query,
        selection: TextSelection.collapsed(offset: query.length),
      );
    }
  }

  @override
  void dispose() {
    _own?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Padding(
      padding: widget.padding,
      child: Container(
        decoration: BoxDecoration(
          color: scheme.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: scheme.outlineVariant),
        ),
        child: Row(
          children: <Widget>[
            Expanded(
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 48),
                child: TextField(
                  controller: _controller,
                  autofocus: widget.autofocus,
                  textInputAction: TextInputAction.search,
                  onChanged: widget.onChanged,
                  onSubmitted: widget.onSubmitted,
                  style: theme.textTheme.bodyMedium,
                  decoration: InputDecoration(
                    hintText: widget.hint,
                    isDense: true,
                    filled: true,
                    fillColor: Colors.transparent,
                    contentPadding: const EdgeInsetsDirectional.symmetric(
                      horizontal: AppSpacing.md,
                      vertical: AppSpacing.sm,
                    ),
                    prefixIcon: Icon(
                      Icons.search_rounded,
                      size: 20,
                      color: scheme.onSurfaceVariant,
                    ),
                    prefixIconConstraints: const BoxConstraints(
                      minWidth: 48,
                      minHeight: 48,
                    ),
                    suffixIcon: widget.onClear == null
                        ? null
                        : IconButton(
                            onPressed: widget.onClear,
                            icon: const Icon(Icons.close_rounded, size: 18),
                            color: scheme.onSurfaceVariant,
                            tooltip: ExperienceStrings.clearSearch,
                            splashRadius: 18,
                          ),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(AppRadius.md),
                      borderSide: BorderSide.none,
                    ),
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(AppRadius.md),
                      borderSide: BorderSide.none,
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(AppRadius.md),
                      borderSide: BorderSide(color: scheme.primary, width: 1.2),
                    ),
                  ),
                ),
              ),
            ),
            if (widget.onOpenFilters != null) ...<Widget>[
              Container(width: 1, height: 20, color: scheme.outlineVariant),
              _FilterButton(
                onTap: widget.onOpenFilters!,
                count: widget.activeCount,
                hasActive: widget.activeCount > 0,
              ),
            ],
            if (widget.trailing != null) ...<Widget>[widget.trailing!],
          ],
        ),
      ),
    );
  }
}

class _FilterButton extends StatelessWidget {
  const _FilterButton({
    required this.onTap,
    required this.count,
    required this.hasActive,
  });

  final VoidCallback onTap;
  final int count;
  final bool hasActive;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Semantics(
      button: true,
      label: hasActive
          ? ExperienceStrings.activeFilters(count)
          : ExperienceStrings.filters,
      child: Material(
        color: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: SizedBox(
            width: 48,
            height: 48,
            child: Stack(
              alignment: Alignment.center,
              children: <Widget>[
                Icon(
                  Icons.tune_rounded,
                  size: 20,
                  color: hasActive ? scheme.primary : scheme.onSurfaceVariant,
                ),
                if (hasActive)
                  PositionedDirectional(
                    top: 6,
                    end: 6,
                    child: Container(
                      constraints: const BoxConstraints(minWidth: 15),
                      height: 15,
                      alignment: Alignment.center,
                      padding: const EdgeInsets.symmetric(horizontal: 3),
                      decoration: BoxDecoration(
                        color: scheme.primary,
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                      ),
                      child: Text(
                        '$count',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: scheme.onPrimary,
                          fontSize: 9,
                          height: 1,
                          fontWeight: FontWeight.w600,
                          fontFeatures: const <FontFeature>[
                            FontFeature.tabularFigures(),
                          ],
                        ),
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

/// "الفلاتر المفعّلة" strip. Collapses to nothing when no filter is set.
class AppActiveFilters extends StatelessWidget {
  const AppActiveFilters({
    super.key,
    required this.filters,
    this.onClearAll,
    this.padding = const EdgeInsets.fromLTRB(
      AppSpacing.md,
      AppSpacing.xs,
      AppSpacing.md,
      AppSpacing.xs,
    ),
  });

  final List<Widget> filters;
  final VoidCallback? onClearAll;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    if (filters.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Padding(
      padding: padding,
      child: Row(
        children: <Widget>[
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: <Widget>[
                  for (
                    var index = 0;
                    index < filters.length;
                    index++
                  ) ...<Widget>[
                    if (index > 0) const SizedBox(width: AppSpacing.xs),
                    filters[index],
                  ],
                ],
              ),
            ),
          ),
          if (onClearAll != null) ...<Widget>[
            const SizedBox(width: AppSpacing.xxs),
            TextButton(
              onPressed: onClearAll,
              style: TextButton.styleFrom(
                minimumSize: const Size(0, 48),
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                foregroundColor: theme.colorScheme.onSurfaceVariant,
                textStyle: theme.textTheme.labelMedium,
              ),
              child: const Text(ExperienceStrings.clearAll),
            ),
          ],
        ],
      ),
    );
  }
}
