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
    this.obscureText = false,
    this.enabled = true,
    this.autofocus = false,
    this.maxLines = 1,
    this.maxLength,
    this.autofillHints,
    this.inputFormatters,
    this.onChanged,
    this.validator,
    this.onFieldSubmitted,
  });

  final TextEditingController controller;
  final String label;
  final String? hint;
  final IconData? prefixIcon;
  final Widget? suffixIcon;
  final TextInputType? keyboardType;
  final TextDirection? textDirection;
  final TextAlign? textAlign;
  final bool obscureText;
  final bool enabled;
  final bool autofocus;
  final int maxLines;
  final int? maxLength;
  final Iterable<String>? autofillHints;
  final List<TextInputFormatter>? inputFormatters;
  final ValueChanged<String>? onChanged;
  final FormFieldValidator<String>? validator;
  final ValueChanged<String>? onFieldSubmitted;

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
        obscureText: obscureText,
        enabled: enabled,
        autofocus: autofocus,
        maxLines: obscureText ? 1 : maxLines,
        maxLength: maxLength,
        autofillHints: autofillHints,
        inputFormatters: inputFormatters,
        onChanged: onChanged,
        onFieldSubmitted: onFieldSubmitted,
        validator: validator,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          prefixIcon: prefixIcon == null ? null : Icon(prefixIcon),
          suffixIcon: suffixIcon,
          counterText: '',
        ),
      ),
    );
  }
}
