import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// A polished filled dropdown used for filters and selectors across the app.
class FilledDropdown<T> extends StatelessWidget {
  const FilledDropdown({
    super.key,
    required this.items,
    required this.value,
    required this.onChanged,
    this.isExpanded = true,
    this.label,
    this.hint,
    this.prefixIcon,
    this.suffixIcon,
    this.helperText,
    this.helperStyle,
    this.validator,
    this.isDense = true,
  });

  final List<DropdownMenuItem<T>> items;
  final T? value;
  final ValueChanged<T?>? onChanged;
  final bool isExpanded;
  final bool isDense;
  final String? label;
  final String? hint;
  final Widget? prefixIcon;
  final Widget? suffixIcon;
  final String? helperText;
  final TextStyle? helperStyle;
  final FormFieldValidator<T?>? validator;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final isDark = scheme.brightness == Brightness.dark;
    return DropdownButtonFormField<T>(
      value: value,
      isExpanded: isExpanded,
      isDense: isDense,
      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
      validator: validator,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        helperText: helperText,
        helperStyle: helperStyle,
        prefixIcon: prefixIcon,
        suffixIcon: suffixIcon ??
            Icon(Icons.keyboard_arrow_down_rounded, size: 22, color: scheme.onSurfaceVariant),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: theme.dividerColor),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: theme.dividerColor),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.7),
        ),
        filled: true,
        fillColor: isDark ? const Color(0xFF172338) : const Color(0xFFF8FAFC),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      ),
      items: items,
      onChanged: onChanged,
    );
  }
}
