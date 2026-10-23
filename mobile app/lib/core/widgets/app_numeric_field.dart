import 'dart:ui' show FontFeature;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

class ArabicToEnglishDigitsFormatter extends TextInputFormatter {
  static const Map<String, String> _digitMap = <String, String>{
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
    '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  };

  static String normalize(String value) {
    var converted = value;
    for (final entry in _digitMap.entries) {
      converted = converted.replaceAll(entry.key, entry.value);
    }
    return converted.replaceAll('٫', '.').replaceAll('٬', '');
  }

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final converted = normalize(newValue.text);
    final selection = TextSelection(
      baseOffset: _normalizedOffset(newValue.text, newValue.selection.baseOffset),
      extentOffset: _normalizedOffset(newValue.text, newValue.selection.extentOffset),
      affinity: newValue.selection.affinity,
      isDirectional: newValue.selection.isDirectional,
    );
    return newValue.copyWith(
      text: converted,
      selection: selection,
      composing: TextRange.empty,
    );
  }

  int _normalizedOffset(String original, int offset) {
    if (offset < 0) return offset;
    final bounded = offset.clamp(0, original.length).toInt();
    return normalize(original.substring(0, bounded)).length;
  }
}

/// Numeric/phone/path input with a hard LTR boundary, even inside an RTL form.
class AppNumericField extends StatelessWidget {
  const AppNumericField({
    super.key,
    required this.controller,
    required this.label,
    this.hint,
    this.suffixText,
    this.prefixIcon,
    this.allowDecimal = false,
    this.allowCompoundSearch = false,
    this.enabled = true,
    this.autofocus = false,
    this.onChanged,
    this.validator,
  });

  final TextEditingController controller;
  final String label;
  final String? hint;
  final String? suffixText;
  final IconData? prefixIcon;
  final bool allowDecimal;
  final bool allowCompoundSearch;
  final bool enabled;
  final bool autofocus;
  final ValueChanged<String>? onChanged;
  final FormFieldValidator<String>? validator;

  @override
  Widget build(BuildContext context) {
    final formatters = <TextInputFormatter>[ArabicToEnglishDigitsFormatter()];
    if (!allowCompoundSearch && !allowDecimal) {
      formatters.add(FilteringTextInputFormatter.digitsOnly);
    } else if (!allowCompoundSearch && allowDecimal) {
      formatters.add(FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')));
    }

    return Directionality(
      textDirection: TextDirection.ltr,
      child: TextFormField(
        controller: controller,
        enabled: enabled,
        autofocus: autofocus,
        textDirection: TextDirection.ltr,
        textAlign: TextAlign.left,
        keyboardType: allowCompoundSearch
            ? TextInputType.text
            : TextInputType.numberWithOptions(decimal: allowDecimal, signed: false),
        inputFormatters: formatters,
        style: const TextStyle(
          fontWeight: FontWeight.w700,
          letterSpacing: .3,
          fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
        ),
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          suffixText: suffixText,
          prefixIcon: prefixIcon == null ? null : Icon(prefixIcon),
        ),
        onChanged: onChanged,
        validator: validator,
      ),
    );
  }
}
