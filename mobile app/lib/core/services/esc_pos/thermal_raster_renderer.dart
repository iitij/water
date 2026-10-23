import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/painting.dart';

import 'thermal_ticket.dart';

/// ناتج التحويل إلى صورة نقطية جاهزة لأمر `GS v 0`.
class ThermalRaster {
  const ThermalRaster({
    required this.rows,
    required this.bytesPerRow,
    required this.widthDots,
  });

  final List<Uint8List> rows;
  final int bytesPerRow;
  final int widthDots;

  int get heightDots => rows.length;
}

/// يحوّل `ThermalTicket` إلى صورة نقطية أحادية البت (1bpp).
///
/// لماذا صورة نقطية بدلاً من نص خام؟ الطابعات الحرارية المحمولة (Xprinter/Zjiang/
/// Goojprt) لا تشارك ترميزاً موحداً للعربية (`CP1256` يختلف بين الموديلات) كما أنها
/// لا تشبّك الحروف ولا تحترم RTL. الرسم بمحرك نصوص Flutter (HarfBuzz) يضمن شكلاً
/// عربياً صحيحاً على كل الطابعات، وهو الأسلوب المعتمد في تطبيقات التحصيل الميداني.
///
/// ملاحظة الخطة §3.2 كانت تقترح `esc_pos_utils_plus`؛ استُبدلت بمولّد بايتات محلي
/// لنفس السبب (انظر `docs/handoff/DEV3_FINANCE_MODULE.md`).
class ThermalRasterRenderer {
  const ThermalRasterRenderer._();

  /// العرض بالنقاط: 384 لطابعة 58mm و576 لطابعة 80mm عند 203dpi.
  static Future<ThermalRaster> render({
    required ThermalTicket ticket,
    required int widthDots,
    int scale = 2,
  }) async {
    final renderWidth = widthDots * scale;
    final padding = (ticket.paddingPx * scale).round();
    final contentWidth = (renderWidth - padding * 2).toDouble();

    // ── المرور الأول: تخطيط الأسطر وحساب الارتفاع الكلي ──────────────────
    final painters = <TextPainter?>[];
    final heights = <double>[];
    var totalHeight = padding.toDouble();

    for (final line in ticket.lines) {
      if (line.isDivider || line.isGap) {
        heights.add(line.fontSizePx * scale);
        totalHeight += line.fontSizePx * scale;
        painters.add(null);
        continue;
      }

      final painter = _painterFor(line, line.fontSizePx * scale);
      painter.layout(maxWidth: contentWidth);
      painters.add(painter);
      final h = painter.height + line.lineSpacingPx * scale;
      heights.add(h);
      totalHeight += h;
    }

    totalHeight += ticket.trailingFeedPx * scale;
    final renderHeight = totalHeight.ceil();

    // ── المرور الثاني: الرسم على لوحة بحجم نهائي ─────────────────────────
    final record = ui.PictureRecorder();
    final canvas = ui.Canvas(record);
    canvas.drawRect(
      ui.Rect.fromLTWH(0, 0, renderWidth.toDouble(), renderHeight.toDouble()),
      ui.Paint()..color = const ui.Color(0xFFFFFFFF),
    );

    var y = padding.toDouble();
    for (var i = 0; i < ticket.lines.length; i++) {
      final line = ticket.lines[i];
      final painter = painters[i];

      if (painter == null) {
        // فاصل: خط أسود أفقي بسمك نقطة واحدة عند 1x. الفارغ يتخطى الرسم.
        final thickness = scale.toDouble();
        if (!line.isGap) {
          canvas.drawRect(
          ui.Rect.fromLTRB(
            padding.toDouble(),
            y,
            (padding + contentWidth).toDouble(),
            y + thickness,
          ),
          ui.Paint()..color = const ui.Color(0xFF000000),
        );
        }
        y += heights[i];
        continue;
      }

      final available = contentWidth - painter.width;
      final x = switch (line.align) {
        ThermalAlign.center => padding + available / 2,
        ThermalAlign.left => padding.toDouble(),
        ThermalAlign.right => padding + available,
      };
      painter.paint(canvas, ui.Offset(x, y));
      y += heights[i];
    }

    final image = record.endRecording().toImageSync(renderWidth, renderHeight);
    final byteData = await image.toByteData(format: ui.ImageByteFormat.rawRgba);
    image.dispose();

    if (byteData == null) {
      throw StateError('تعذّر تحويل الطباعة الحرارية إلى صورة نقطية.');
    }

    return _toBitmap(
      rgba: byteData.buffer.asUint8List(),
      renderWidth: renderWidth,
      renderHeight: renderHeight,
      scale: scale,
      widthDots: widthDots,
    );
  }

  static TextPainter _painterFor(ThermalLine line, double fontSize) {
    final style = TextStyle(
      fontSize: fontSize,
      height: line.lineHeight,
      fontWeight: line.isBold ? FontWeight.bold : FontWeight.normal,
      color: const Color(0xFF000000),
    );

    return TextPainter(
      text: TextSpan(text: line.text, style: style),
      textDirection: line.ltr ? TextDirection.ltr : TextDirection.rtl,
      textAlign: switch (line.align) {
        ThermalAlign.center => TextAlign.center,
        ThermalAlign.left => TextAlign.left,
        ThermalAlign.right => TextAlign.right,
      },
    )..maxLines = line.maxLines ?? 6;
  }

  static ThermalRaster _toBitmap({
    required Uint8List rgba,
    required int renderWidth,
    required int renderHeight,
    required int scale,
    required int widthDots,
  }) {
    final heightDots = renderHeight ~/ scale;
    final bytesPerRow = (widthDots + 7) ~/ 8;
    final bitmap = Uint8List(bytesPerRow * heightDots);

    for (var oy = 0; oy < heightDots; oy++) {
      for (var ox = 0; ox < widthDots; ox++) {
        var sum = 0.0;
        var count = 0;
        for (var sy = oy * scale; sy < (oy + 1) * scale && sy < renderHeight; sy++) {
          for (var sx = ox * scale; sx < (ox + 1) * scale && sx < renderWidth; sx++) {
            final offset = (sy * renderWidth + sx) * 4;
            if (offset + 3 >= rgba.length) continue;
            sum += 0.299 * rgba[offset] +
                0.587 * rgba[offset + 1] +
                0.114 * rgba[offset + 2];
            count++;
          }
        }
        final avg = count == 0 ? 255.0 : sum / count;
        if (avg < 168) {
          bitmap[oy * bytesPerRow + (ox >> 3)] |= 0x80 >> (ox & 7);
        }
      }
    }

    return ThermalRaster(
      rows: List<Uint8List>.generate(
        heightDots,
        (row) => Uint8List.fromList(
          bitmap.sublist(row * bytesPerRow, (row + 1) * bytesPerRow),
        ),
      ),
      bytesPerRow: bytesPerRow,
      widthDots: widthDots,
    );
  }
}
