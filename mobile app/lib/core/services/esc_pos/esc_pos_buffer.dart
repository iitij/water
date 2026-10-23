import 'dart:typed_data';

/// مُولّد أوامر ESC/POS خام — يملكه Dev 3 لأن الطباعة الحرارية الميدانية يجب ألا
/// تعتمد على أي سحابة. نستخدم أوامر ثابتة موثّقة في مواصفة Epson ESC/POS:
///
///  - `ESC @`        تهيئة
///  - `ESC a n`      المحاذاة (0 يسار، 1 وسط، 2 يمين)
///  - `ESC E n`      عريض
///  - `GS ! n`       حجم الخط (المربعات الأفقية/العمودية)
///  - `ESC 3 n`      ارتفاع السطر
///  - `GS V B 0`     قطع جزئي
///  - `GS v 0`       صورة نقطية (نستخدمها للعربية لأن أوامر ترميز CP1256 غير
///                    موحّدة بين الطابعات الصينية، وصورة نقطية تضمن شكلاً صحيحاً).
class EscPosBuffer {
  EscPosBuffer();

  static const List<int> _esc = <int>[0x1B];
  static const List<int> _gs = <int>[0x1D];

  final BytesBuilder _bytes = BytesBuilder(copy: false);

  Uint8List get bytes => _bytes.takeBytes();

  void _cmd(List<int> values) => _bytes.add(values);

  /// يفرغ المخزن (لإعادة استخدام العارض في المعاينة).
  void clear() => _bytes.clear();

  /// `ESC @`
  void initialize() => _cmd(<int>[..._esc, 0x40]);

  /// `ESC a n`
  void align(int mode) => _cmd(<int>[..._esc, 0x61, mode]);

  /// `ESC E n`
  void bold(bool on) => _cmd(<int>[..._esc, 0x45, on ? 1 : 0]);

  /// `GS ! n` — `size` 1 → عادي، 2 → مضاعف الطول والعرض.
  void textSize({bool doubleWidth = false, bool doubleHeight = false}) {
    final n = (doubleWidth ? 0x10 : 0) | (doubleHeight ? 0x01 : 0);
    _cmd(<int>[..._gs, 0x21, n]);
  }

  /// `ESC 3 n` — ارتفاع السطر بالنقاط.
  void lineSpacing(int dots) => _cmd(<int>[..._esc, 0x33, dots & 0xFF]);

  /// `ESC d n` — تغذية n أسطر.
  void feedLines(int n) => _cmd(<int>[..._esc, 0x64, n]);

  /// `ESC p` — دفع الدبوس (إن وُجد).
  void beep() => _cmd(<int>[..._esc, 0x70, 0, 60, 255]);

  /// `GS V B 0` — قطع جزئي.
  void partialCut() => _cmd(<int>[..._gs, 0x56, 0x42, 0x00]);

  void text(String value) => _bytes.add(asciiBytes(value));

  /// يرسم صورة نقطية ثنائية السطور بعرض `widthDots` (يجب مضاعفته إلى بايتات).
  ///
  /// [bitmapRows]: كل صف `Uint8List` بطول `bytesPerRow`، بت التجميع = 1 (أسود).
  void rasterImage({
    required int widthDots,
    required int bytesPerRow,
    required List<Uint8List> bitmapRows,
  }) {
    assert(widthDots <= bytesPerRow * 8, 'عرض النقاط $widthDots لا يتسع له عرض البايتات.');
    final widthBytes = bytesPerRow;
    _cmd(<int>[
      ..._gs,
      0x76,
      0x30,
      0x00,
      widthBytes & 0xFF,
      (widthBytes >> 8) & 0xFF,
      bitmapRows.length & 0xFF,
      (bitmapRows.length >> 8) & 0xFF,
    ]);
    for (final row in bitmapRows) {
      assert(row.length == widthBytes, 'صف بعرض خاطئ: ${row.length} != $widthBytes');
      _bytes.add(row);
    }
    // هامش أمان بعد الصورة.
    _cmd(<int>[0x0A]);
  }

  /// نص ASCII آمن (أرقام المستندات فقط) — المسار النصي الاحتياطي.
  /// العربية لا تُرسل نصياً لأن ترميز CP1256 غير موحّد بين الطابعات.
  static Uint8List asciiBytes(String value) =>
      Uint8List.fromList(value.codeUnits.map((c) => c <= 127 ? c : 0x3F).toList());

  static int bytesPerRowFor(int dots) => (dots + 7) ~/ 8;
}
