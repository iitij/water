part of '../app_filters.dart';

/// A compact two-to-four option switch. Use only when options are stable.
class AppSegmentedFilter<T> extends StatelessWidget {
  const AppSegmentedFilter({
    super.key,
    required this.value,
    required this.onChanged,
    required this.options,
  });

  final T value;
  final ValueChanged<T> onChanged;
  final List<({T value, String label, IconData? icon})> options;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: dark ? AppColors.darkSurfaceAlt : AppColors.surfaceAlt,
        borderRadius: BorderRadius.circular(AppRadius.sm),
        border: Border.all(color: theme.dividerColor),
      ),
      child: Row(
        children: <Widget>[
          for (final option in options)
            Expanded(
              child: GestureDetector(
                behavior: HitTestBehavior.opaque,
                onTap: () => onChanged(option.value),
                child: AnimatedContainer(
                  duration: AppMotion.fast,
                  curve: AppMotion.curve,
                  height: 34,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: option.value == value
                        ? scheme.surface
                        : Colors.transparent,
                    borderRadius: BorderRadius.circular(AppRadius.xs),
                    border: Border.all(
                      color: option.value == value
                          ? theme.dividerColor
                          : Colors.transparent,
                    ),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      if (option.icon != null) ...<Widget>[
                        Icon(
                          option.icon,
                          size: 15,
                          color: option.value == value
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
                          style: theme.textTheme.labelMedium?.copyWith(
                            color: option.value == value
                                ? scheme.onSurface
                                : scheme.onSurfaceVariant,
                            fontWeight: option.value == value
                                ? FontWeight.w600
                                : FontWeight.w500,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Title + optional helper + body for a sheet section.
/// Title + optional helper + body for a sheet section.
class AppFilterSection extends StatelessWidget {
  const AppFilterSection({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.trailing,
  });

  final String title;
  final String? subtitle;
  final Widget? trailing;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  title,
                  style: theme.textTheme.labelLarge?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              if (trailing != null) trailing!,
            ],
          ),
          if (subtitle != null) ...<Widget>[
            const SizedBox(height: 2),
            Text(
              subtitle!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
          const SizedBox(height: AppSpacing.sm),
          child,
        ],
      ),
    );
  }
}

/// A radio-like row: label, optional icon, selection tick. No checkbox noise.
/// A radio-like row: label, optional icon, selection tick. No checkbox noise.
class AppFilterOptionTile extends StatelessWidget {
  const AppFilterOptionTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.meta,
    this.count,
  });

  final String label;
  final IconData? icon;
  final String? meta;
  final int? count;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;

    return Material(
      color: selected
          ? scheme.primary.withValues(alpha: dark ? .14 : .06)
          : Colors.transparent,
      borderRadius: BorderRadius.circular(AppRadius.sm),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.sm),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm,
            vertical: 10,
          ),
          child: Row(
            children: <Widget>[
              Icon(
                icon ??
                    (selected
                        ? Icons.radio_button_checked_rounded
                        : Icons.radio_button_unchecked_rounded),
                size: 18,
                color: selected ? scheme.primary : theme.dividerColor,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      label,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: selected
                            ? FontWeight.w600
                            : FontWeight.w400,
                      ),
                    ),
                    if (meta != null) ...<Widget>[
                      const SizedBox(height: 1),
                      Text(
                        meta!,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              if (count != null)
                Text(
                  '$count',
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: scheme.onSurfaceVariant,
                    fontFeatures: const <FontFeature>[
                      FontFeature.tabularFigures(),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// From / to date pickers in one aligned pair.
/// From / to date pickers in one aligned pair.
class AppFilterDateRange extends StatelessWidget {
  const AppFilterDateRange({
    super.key,
    required this.fromLabel,
    required this.toLabel,
    required this.onPickFrom,
    required this.onPickTo,
    this.onClear,
  });

  final String fromLabel;
  final String toLabel;
  final VoidCallback onPickFrom;
  final VoidCallback onPickTo;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Expanded(
          child: _DateBox(
            label: 'من',
            value: fromLabel,
            hasValue: fromLabel != 'بدون حد',
            onTap: onPickFrom,
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          child: _DateBox(
            label: 'إلى',
            value: toLabel,
            hasValue: toLabel != 'بدون حد',
            onTap: onPickTo,
          ),
        ),
        if (onClear != null) ...<Widget>[
          const SizedBox(width: AppSpacing.xxs),
          IconButton(
            onPressed: onClear,
            icon: const Icon(Icons.close_rounded, size: 18),
            tooltip: 'مسح التاريخ',
            splashRadius: 18,
          ),
        ],
      ],
    );
  }
}

class _DateBox extends StatelessWidget {
  const _DateBox({
    required this.label,
    required this.value,
    required this.hasValue,
    required this.onTap,
  });

  final String label;
  final String value;
  final bool hasValue;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;

    return Material(
      color: hasValue
          ? scheme.primary.withValues(alpha: dark ? .12 : .05)
          : Colors.transparent,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.md),
        onTap: onTap,
        child: Container(
          height: 46,
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.md),
            border: Border.all(
              color: hasValue
                  ? scheme.primary.withValues(alpha: .40)
                  : theme.dividerColor,
            ),
          ),
          child: Row(
            children: <Widget>[
              Icon(
                Icons.calendar_today_rounded,
                size: 15,
                color: hasValue ? scheme.primary : scheme.onSurfaceVariant,
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      label,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                        height: 1.1,
                      ),
                    ),
                    Text(
                      value,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelMedium?.copyWith(
                        height: 1.25,
                        color: hasValue
                            ? scheme.onSurface
                            : scheme.onSurfaceVariant,
                        fontWeight: hasValue
                            ? FontWeight.w600
                            : FontWeight.w500,
                        fontFeatures: const <FontFeature>[
                          FontFeature.tabularFigures(),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A single-choice dropdown that matches the 46 px filter fields.
/// A single-choice dropdown that matches the 46 px filter fields.
class AppFilterDropdown<T> extends StatelessWidget {
  const AppFilterDropdown({
    super.key,
    required this.items,
    required this.onChanged,
    this.value,
    this.label,
    this.icon,
    this.hint,
    this.enabled = true,
  });

  final T? value;
  final List<DropdownMenuItem<T>> items;
  final ValueChanged<T?> onChanged;
  final String? label;
  final IconData? icon;
  final String? hint;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;

    return SizedBox(
      height: 46,
      child: DropdownButtonFormField<T>(
        key: ValueKey<T?>(value),
        initialValue: value,
        isExpanded: true,
        icon: Icon(
          Icons.keyboard_arrow_down_rounded,
          size: 20,
          color: scheme.onSurfaceVariant,
        ),
        hint: (hint ?? label) == null
            ? null
            : Text(
                (hint ?? label)!,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
        style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurface),
        dropdownColor: scheme.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        decoration: InputDecoration(
          filled: true,
          fillColor: dark ? AppColors.darkSurfaceAlt : AppColors.surfaceAlt,
          isDense: true,
          contentPadding: const EdgeInsetsDirectional.symmetric(
            horizontal: AppSpacing.sm,
            vertical: 12,
          ),
          prefixIcon: Icon(
            icon ?? Icons.filter_alt_outlined,
            size: 18,
            color: scheme.onSurfaceVariant,
          ),
          prefixIconConstraints: const BoxConstraints(
            minWidth: 38,
            minHeight: 38,
          ),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
            borderSide: BorderSide(color: theme.dividerColor),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
            borderSide: BorderSide(color: theme.dividerColor),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
            borderSide: BorderSide(color: scheme.primary),
          ),
        ),
        items: items,
        onChanged: enabled ? onChanged : null,
      ),
    );
  }
}

/// The one bottom sheet used for filtering.
///
/// Open it with [AppFilterSheet.show]; the content builds an [AppFilterSheet]
/// whose footer applies the drafted values exactly once.
/// The one bottom sheet used for filtering.
///
/// Open it with [AppFilterSheet.show]; the content builds an [AppFilterSheet]
/// whose footer applies the drafted values exactly once.
class AppFilterSheet extends StatelessWidget {
  const AppFilterSheet({
    super.key,
    required this.title,
    required this.sections,
    required this.onApply,
    this.resultCountLabel,
    this.onReset,
    this.resetEnabled = false,
    this.applyLabel = 'عرض النتائج',
  });

  final String title;
  final List<Widget> sections;
  final VoidCallback onApply;
  final String? resultCountLabel;
  final VoidCallback? onReset;
  final bool resetEnabled;
  final String applyLabel;

  static Future<T?> show<T>({
    required BuildContext context,
    required WidgetBuilder builder,
  }) {
    return showModalBottomSheet<T>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      barrierColor: Colors.black.withValues(alpha: .38),
      builder: builder,
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final media = MediaQuery.of(context);

    return Padding(
      padding: EdgeInsets.only(bottom: media.viewInsets.bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: media.size.height * .86),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: scheme.surface,
            borderRadius: const BorderRadius.vertical(
              top: Radius.circular(AppRadius.xxl),
            ),
            border: Border(top: BorderSide(color: theme.dividerColor)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const SizedBox(height: AppSpacing.xs),
              Container(
                width: 36,
                height: 4,
                decoration: BoxDecoration(
                  color: theme.dividerColor,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
              ),
              Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(
                  AppSpacing.md,
                  AppSpacing.sm,
                  AppSpacing.xs,
                  AppSpacing.xs,
                ),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Text(
                        title,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.of(context).maybePop(),
                      icon: const Icon(Icons.close_rounded, size: 20),
                      tooltip: 'إغلاق',
                      splashRadius: 20,
                      color: scheme.onSurfaceVariant,
                    ),
                  ],
                ),
              ),
              if (resultCountLabel != null)
                Padding(
                  padding: const EdgeInsetsDirectional.fromSTEB(
                    AppSpacing.md,
                    0,
                    AppSpacing.md,
                    AppSpacing.xs,
                  ),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      resultCountLabel!,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                        fontFeatures: const <FontFeature>[
                          FontFeature.tabularFigures(),
                        ],
                      ),
                    ),
                  ),
                ),
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsetsDirectional.fromSTEB(
                    AppSpacing.md,
                    AppSpacing.xs,
                    AppSpacing.md,
                    0,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: sections,
                  ),
                ),
              ),
              _SheetFooter(
                applyLabel: applyLabel,
                resetEnabled: resetEnabled,
                onReset: onReset,
                onApply: onApply,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SheetFooter extends StatelessWidget {
  const _SheetFooter({
    required this.applyLabel,
    required this.resetEnabled,
    required this.onReset,
    required this.onApply,
  });

  final String applyLabel;
  final bool resetEnabled;
  final VoidCallback? onReset;
  final VoidCallback onApply;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return DecoratedBox(
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border(top: BorderSide(color: theme.dividerColor)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(
            AppSpacing.md,
            AppSpacing.sm,
            AppSpacing.md,
            AppSpacing.sm,
          ),
          child: Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: 8,
            runSpacing: 8,
            children: <Widget>[
              if (onReset != null)
                TextButton(
                  onPressed: resetEnabled ? onReset : null,
                  style: TextButton.styleFrom(
                    minimumSize: const Size(0, 44),
                    foregroundColor: scheme.onSurfaceVariant,
                    textStyle: theme.textTheme.labelLarge,
                  ),
                  child: const Text('إعادة تعيين'),
                ),
              SizedBox(
                height: 44,
                child: FilledButton(
                  onPressed: onApply,
                  style: FilledButton.styleFrom(
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.lg,
                    ),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(AppRadius.md),
                    ),
                    textStyle: theme.textTheme.labelLarge,
                  ),
                  child: Text(applyLabel),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
