import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

class AppTextField extends StatelessWidget {
  const AppTextField({
    super.key,
    required this.controller,
    required this.label,
    this.hint,
    this.prefixIcon,
    this.suffixIcon,
    this.keyboardType,
    this.textDirection,
    this.textAlign,
    this.textInputAction,
    this.textCapitalization,
    this.obscureText = false,
    this.enabled = true,
    this.readOnly = false,
    this.autofocus = false,
    this.maxLines = 1,
    this.minLines,
    this.maxLength,
    this.autofillHints,
    this.inputFormatters,
    this.onChanged,
    this.validator,
    this.onFieldSubmitted,
    this.helperText,
    this.helperStyle,
    this.hintStyle,
    this.autovalidateMode,
    this.onTap,
  });

  final TextEditingController controller;
  final String label;
  final String? hint;
  final IconData? prefixIcon;
  final Widget? suffixIcon;
  final TextInputType? keyboardType;
  final TextDirection? textDirection;
  final TextAlign? textAlign;
  final TextInputAction? textInputAction;
  final TextCapitalization? textCapitalization;
  final bool obscureText;
  final bool enabled;
  final bool readOnly;
  final bool autofocus;
  final int maxLines;
  final int? minLines;
  final int? maxLength;
  final Iterable<String>? autofillHints;
  final List<TextInputFormatter>? inputFormatters;
  final ValueChanged<String>? onChanged;
  final FormFieldValidator<String>? validator;
  final ValueChanged<String>? onFieldSubmitted;
  final String? helperText;
  final TextStyle? helperStyle;
  final TextStyle? hintStyle;
  final AutovalidateMode? autovalidateMode;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final direction = textDirection ?? TextDirection.rtl;
    return Directionality(
      textDirection: direction,
      child: TextFormField(
        controller: controller,
        keyboardType: keyboardType,
        textDirection: direction,
        textAlign: textAlign ?? (direction == TextDirection.ltr ? TextAlign.left : TextAlign.right),
        textInputAction: textInputAction,
        textCapitalization: textCapitalization,
        obscureText: obscureText,
        enabled: enabled,
        readOnly: readOnly,
        autofocus: autofocus,
        maxLines: obscureText ? 1 : maxLines,
        minLines: minLines,
        maxLength: maxLength,
        autofillHints: autofillHints,
        inputFormatters: inputFormatters,
        onChanged: onChanged,
        onFieldSubmitted: onFieldSubmitted,
        autovalidateMode: autovalidateMode,
        validator: validator,
        onTap: onTap,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          hintStyle: hintStyle,
          helperText: helperText,
          helperStyle: helperStyle,
          prefixIcon: prefixIcon == null ? null : Icon(prefixIcon),
          suffixIcon: suffixIcon,
          counterText: '',
        ),
      ),
    );
  }
}
