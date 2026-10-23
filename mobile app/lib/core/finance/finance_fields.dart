/// حقول الوحدة المالية (نص/رقم بالأرقام العربية) — نسخة Dev 3 محفوظة الاسم
/// `AppTextField`/`AppNumericField` لأن نواة Dev 1 تقدّم نفس الأسماء بواجهة مختلفة
/// (`label` بدل `labelText`، بلا `onTap`/`readOnly`).
library;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../utils/compound_search_parser.dart';

class AppNumericField extends StatefulWidget {
  const AppNumericField({
    super.key,
    required this.controller,
    this.label,
    this.hint,
    this.helperText,
    this.prefixText,
    this.suffix,
    this.decimal = true,
    this.maxLines = 1,
    this.required = false,
    this.enabled = true,
    this.autofocus = false,
    this.onChanged,
    this.onSubmitted,
    this.validator,
    this.keyboardType,
    this.allowNegative = false,
    this.textInputAction,
    this.fillColor,
  });

  final TextEditingController controller;
  final String? label;
  final String? hint;
  final String? helperText;
  final String? prefixText;
  final Widget? suffix;
  final bool decimal;
  final int maxLines;
  final bool required;
  final bool enabled;
  final bool autofocus;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final String? Function(String?)? validator;
  final TextInputType? keyboardType;
  final bool allowNegative;
  final TextInputAction? textInputAction;
  final Color? fillColor;

  @override
  State<AppNumericField> createState() => _AppNumericFieldState();
}

class _AppNumericFieldState extends State<AppNumericField> {
  late final FocusNode _focusNode = FocusNode()..addListener(_onFocusChange);

  void _onFocusChange() {
    if (!_focusNode.hasFocus) _normalize();
  }

  /// تطبيع الأرقام العربية عند مغادرة الحقل.
  void _normalize() {
    final normalized =
        CompoundSearchQuery.normalizeDigits(widget.controller.text.trim());
    if (normalized != widget.controller.text) {
      widget.controller.value = TextEditingValue(
        text: normalized,
        selection: TextSelection.collapsed(offset: normalized.length),
      );
    }
  }

  List<TextInputFormatter> _formatters() {
    final base = <TextInputFormatter>[
      _ArabicDigitNormalizer(),
      FilteringTextInputFormatter.allow(
        widget.decimal
            ? RegExp(r'^-?\d*\.?\d*$')
            : RegExp(r'^-?\d*$'),
      ),
    ];
    if (!widget.allowNegative) {
      base.add(FilteringTextInputFormatter.deny(RegExp(r'^-')));
    }
    return base;
  }

  @override
  void dispose() {
    _focusNode.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: TextFormField(
        controller: widget.controller,
        focusNode: _focusNode,
        enabled: widget.enabled,
        autofocus: widget.autofocus,
        maxLines: widget.maxLines,
        textAlign: TextAlign.left,
        textDirection: TextDirection.ltr,
        keyboardType: widget.keyboardType ??
            (widget.decimal
                ? const TextInputType.numberWithOptions(decimal: true)
                : TextInputType.number),
        textInputAction: widget.textInputAction,
        inputFormatters: _formatters(),
        onChanged: widget.onChanged,
        onFieldSubmitted: widget.onSubmitted,
        style: const TextStyle(fontWeight: FontWeight.w600, letterSpacing: 0.2),
        decoration: InputDecoration(
          labelText: widget.label,
          hintText: widget.hint,
          helperText: widget.helperText,
          prefixText: widget.prefixText == null
              ? null
              : '${widget.prefixText} ',
          suffixIcon: widget.suffix,
          filled: widget.fillColor != null,
          fillColor: widget.fillColor,
        ),
        validator: (value) {
          if (widget.required && (value == null || value.trim().isEmpty)) {
            return 'هذا الحقل مطلوب';
          }
          if (!widget.required && (value == null || value.trim().isEmpty)) {
            return null;
          }
          final parsed = num.tryParse(value!.trim());
          if (parsed == null) return 'أدخل رقماً صالحاً';
          if (!widget.allowNegative && parsed < 0) return 'القيمة لا يمكن أن تكون سالبة';
          return widget.validator?.call(value);
        },
      ),
    );
  }
}

/// محوّل يطبّع الأرقام العربية/الفارسية أثناء الكتابة إلى أرقام لاتينية.
class _ArabicDigitNormalizer extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final normalized = CompoundSearchQuery.normalizeDigits(newValue.text);
    if (normalized == newValue.text) return newValue;
    return TextEditingValue(
      text: normalized,
      selection: TextSelection.collapsed(
        offset: normalized.length,
      ),
    );
  }
}

/// حقل نصي عربي قياسي (ملاحظات/سبب) بنفس شكل `AppNumericField`.
class AppTextField extends StatelessWidget {
  const AppTextField({
    super.key,
    required this.controller,
    this.label,
    this.hint,
    this.helperText,
    this.maxLines = 1,
    this.required = false,
    this.enabled = true,
    this.textInputAction,
    this.onChanged,
    this.onSubmitted,
    this.suffix,
    this.keyboardType,
    this.validator,
  });

  final TextEditingController controller;
  final String? label;
  final String? hint;
  final String? helperText;
  final int maxLines;
  final bool required;
  final bool enabled;
  final TextInputAction? textInputAction;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final Widget? suffix;
  final TextInputType? keyboardType;

  /// تحقق إضافي يُدمج مع فحص الإلزام المدمج.
  final String? Function(String?)? validator;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: controller,
      maxLines: maxLines,
      enabled: enabled,
      keyboardType: keyboardType,
      textInputAction: textInputAction,
      onChanged: onChanged,
      onFieldSubmitted: onSubmitted,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        helperText: helperText,
        suffixIcon: suffix,
      ),
      validator: (value) {
        if (required && (value == null || value.trim().isEmpty)) {
          return 'هذا الحقل مطلوب';
        }
        final custom = validator;
        return custom == null ? null : custom(value);
      },
    );
  }
}
