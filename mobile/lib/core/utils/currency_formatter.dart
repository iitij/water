import 'package:intl/intl.dart';

/// تنسيق المبالغ (ريال) بأرقام لاتينية LTR دائماً (القاعدة الذهبية BiDi — الخطة 3.4).
class CurrencyFormatter {
  static final _fmt = NumberFormat('#,##0.##', 'en_US');
  static String format(num value) => '${_fmt.format(value)} ريال';
  static String signed(num value) =>
      '${value > 0 ? '+' : ''}${_fmt.format(value)} ريال';
}

/// تحويل الأرقام العربية المشرقية إلى لاتينية أثناء الإدخال الميداني.
String normalizeDigits(String input) {
  const arabic = '٠١٢٣٤٥٦٧٨٩';
  final buffer = StringBuffer();
  for (final ch in input.split('')) {
    final i = arabic.indexOf(ch);
    buffer.write(i >= 0 ? '$i' : ch);
  }
  return buffer.toString();
}
