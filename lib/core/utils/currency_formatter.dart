import 'arabic_number_spellout.dart';

/// منسّق المبالغ المالية بالاتجاه LTR (الخطة §3.4 قاعدة الأرقام: كل الأرقام تُعرض
/// بأرقام لاتينية داخل `Directionality(ltr)` لتفادي انقلاب الأرقام في واجهة RTL).
///
/// يطابق تنسيق Filament في `InvoiceResource`: `->numeric(2)->suffix(' ريال')`
/// وتنسيق Blade: `number_format($amount, 0)` عند كون المبلغ صحيحاً وإلا `2`.
class CurrencyFormatter {
  CurrencyFormatter._();

  static const String currencyLabel = 'ريال';

  /// `1,250` أو `1,250.50` — بدون عملة.
  static String amount(num? value) {
    final v = value ?? 0;
    final isWhole = v == v.roundToDouble();
    final fixed = isWhole ? v.toStringAsFixed(0) : v.toStringAsFixed(2);
    return _groupThousands(fixed);
  }

  /// `1,250 ريال` — الصيغة المعروضة في البطاقات والجداول.
  static String withLabel(num? value) => '${amount(value)} $currencyLabel';

  /// المتبقي/الدين: يُعرض دائماً بخانتين عشريتين في شاشات التحصيل.
  static String debt(num? value) {
    final v = (value ?? 0).clamp(0, double.maxFinite);
    return '${_groupThousands(v.toStringAsFixed(2))} $currencyLabel';
  }

  /// الرقم مع فاصل الآلاف فقط (يُستخدم داخل سند القبض الحراري).
  static String _groupThousands(String decimalString) {
    final negative = decimalString.startsWith('-');
    var body = negative ? decimalString.substring(1) : decimalString;
    final dotIndex = body.indexOf('.');
    var intPart = dotIndex >= 0 ? body.substring(0, dotIndex) : body;
    final fracPart = dotIndex >= 0 ? body.substring(dotIndex) : '';

    final buffer = StringBuffer();
    final remainder = intPart.length % 3;
    for (var i = 0; i < intPart.length; i++) {
      if (i > 0 && (i - remainder) % 3 == 0) buffer.write(',');
      buffer.write(intPart[i]);
    }
    intPart = buffer.toString();
    body = intPart + fracPart;

    return negative ? '-$body' : body;
  }

  /// تفقيط مختصر للطابعة الحرارية: `الف: خمسمئة ريال يمني فقط لا غير`.
  static String inWords(num? value) =>
      ArabicNumberSpellout.spelloutYemeniRial(value ?? 0);

  /// نسبة مئوية معروضة (تُستخدم في شريط التقدّم في بطاقة الفاتورة).
  static String percent(num part, num whole) {
    if (whole <= 0) return '0%';
    final pct = ((part / whole) * 100).clamp(0, 100);
    return '${pct.toStringAsFixed(0)}%';
  }
}
