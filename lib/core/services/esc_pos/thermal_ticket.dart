/// نماذج «تذكرة الطباعة الحرارية» — تصف المحتوى فقط، فتبقى قابلة للاختبار
/// بدون أي محاكاة للطابعة، وتترك للطابعة اختيار أسلوب الترميز.
enum ThermalAlign { right, center, left }

enum ThermalLineKind { text, divider, gap }

class ThermalLine {
  const ThermalLine(
    this.text, {
    this.align = ThermalAlign.right,
    this.bold = false,
    this.ltr = false,
    this.fontSizePx = baseFont,
    this.lineSpacingPx = 3,
    this.lineHeight = 1.3,
    this.maxLines,
    this.kind = ThermalLineKind.text,
  });

  /// فاصل أفقي.
  const ThermalLine.divider({this.align = ThermalAlign.center})
      : text = '',
        bold = false,
        ltr = false,
        fontSizePx = 4,
        lineSpacingPx = 4,
        lineHeight = 1,
        maxLines = 1,
        kind = ThermalLineKind.divider;

  /// مسافة فارغة.
  const ThermalLine.gap({this.fontSizePx = 10})
      : text = '',
        align = ThermalAlign.center,
        bold = false,
        ltr = false,
        lineSpacingPx = 0,
        lineHeight = 1,
        maxLines = 1,
        kind = ThermalLineKind.gap;

  static const double baseFont = 18;

  final String text;
  final ThermalAlign align;
  final bool bold;

  /// الحقول الرقمية (أرقام المستندات، المبالغ، الهواتف) تُرسم LTR دائماً
  /// عملاً بقاعدة الخطة §3.4-2.
  final bool ltr;
  final double fontSizePx;
  final double lineSpacingPx;
  final double lineHeight;
  final int? maxLines;
  final ThermalLineKind kind;

  bool get isDivider => kind == ThermalLineKind.divider;
  bool get isGap => kind == ThermalLineKind.gap;

  @override
  String toString() => isDivider ? 'ThermalLine.divider' : 'ThermalLine($text)';
}

/// التذكرة الكاملة (عنوان + أسطر + حشوة + تغذية الورق).
class ThermalTicket {
  const ThermalTicket({
    required this.title,
    required this.lines,
    this.paddingPx = 8,
    this.trailingFeedPx = 24,
  });

  final String title;
  final List<ThermalLine> lines;
  final double paddingPx;

  /// تغذية الورق بعد آخر سطر لتمرير السطر من رأس الطباعة.
  final double trailingFeedPx;
}

/// أبنية مختصرة تُستعمل في قوالب الفاتورة والسند.
class ThermalSyntax {
  ThermalSyntax._();

  static ThermalLine heading(String text) => ThermalLine(
        text,
        align: ThermalAlign.center,
        bold: true,
        fontSizePx: ThermalLine.baseFont * 1.25,
      );

  static ThermalLine subheading(String text) =>
      ThermalLine(text, align: ThermalAlign.center, bold: true);

  /// مبلغ بارز في منتصف الإيصال.
  static ThermalLine big(String text) => ThermalLine(
        text,
        align: ThermalAlign.center,
        bold: true,
        fontSizePx: ThermalLine.baseFont * 1.5,
        ltr: true,
      );

  /// حقل «تسمية: قيمة». القيمة تُوضع في نهاية السطر لتبقى الأرقام مقروءة
  /// بالتسلسل الصحيح عند رسم السطر كاملاً داخل كتلة LTR أو RTL.
  static ThermalLine field(String label, String value, {bool bold = false}) =>
      ThermalLine(
        '$label: $value',
        align: ThermalAlign.right,
        bold: bold,
        maxLines: 4,
      );

  static const ThermalLine divider = ThermalLine.divider();

  static ThermalLine gap({double heightPx = 8}) =>
      ThermalLine.gap(fontSizePx: heightPx);
}
