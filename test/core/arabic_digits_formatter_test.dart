import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_mobile/core/widgets/app_numeric_field.dart';

void main() {
  test('converts Arabic and Persian digits to English without changing length', () {
    const formatter = ArabicToEnglishDigitsFormatter();
    final value = formatter.formatEditUpdate(
      const TextEditingValue(text: ''),
      const TextEditingValue(text: '٠١۲۳-45', selection: TextSelection.collapsed(offset: 7)),
    );

    expect(value.text, '0123-45');
    expect(value.selection.extentOffset, 7);
  });

  test('normalizes Arabic decimal and grouping separators', () {
    expect(ArabicToEnglishDigitsFormatter.normalize('١٬٢٠٠٫٥'), '1200.5');
  });

  test('keeps the cursor in bounds when grouping separators are removed', () {
    const formatter = ArabicToEnglishDigitsFormatter();
    final value = formatter.formatEditUpdate(
      const TextEditingValue(text: ''),
      const TextEditingValue(text: '١٬٢٠٠', selection: TextSelection.collapsed(offset: 5)),
    );

    expect(value.text, '1200');
    expect(value.selection.extentOffset, 4);
  });
}
