import 'package:flutter/material.dart';

/// قائمة منسدلة موحّدة تقرأ تنسيقها من الثيم، فلا تكرّر الحدود أو الألوان.
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
    return DropdownButtonFormField<T>(
      initialValue: value,
      isExpanded: isExpanded,
      isDense: isDense,
      style: theme.textTheme.bodyMedium?.copyWith(
        fontWeight: FontWeight.w500,
        color: scheme.onSurface,
      ),
      validator: validator,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        helperText: helperText,
        helperStyle: helperStyle,
        prefixIcon: prefixIcon,
        suffixIcon: suffixIcon ??
            Icon(Icons.keyboard_arrow_down_rounded, size: 20, color: scheme.onSurfaceVariant),
      ),
      items: items,
      onChanged: onChanged,
    );
  }
}
