import 'dart:async';

import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../theme/app_colors.dart';
import '../ui/app_tokens.dart';

/// شاشة مسح باركود/QR واحدة تُستخدم لكل الحالات (عداد، مشترك، سند).
///
/// السبب في وجودها في `core`: الشرط في خطة 2.0 أن يكون المسح سلوكاً واحداً في
/// التطبيق كله — نفس الإطار، نفس الإضاءة، نفس إغلاق الكاميرا عند الخروج. النسخ
/// السابقة كانت مكرّرة داخل شاشة القراءة، وتكرارها في المشتركين كان سيعني
/// إصلاح الخطأ مرتين لاحقاً.
///
/// الاستخدام:
/// ```dart
/// final code = await BarcodeScannerScreen.scan(context, title: 'مسح باركود المشترك');
/// if (code != null) cubit.search(code);
/// ```
class BarcodeScannerScreen extends StatefulWidget {
  const BarcodeScannerScreen({
    super.key,
    this.title = 'مسح الباركود',
    this.hint = 'وجّه الكاميرا إلى الباركود أو رمز QR.',
    this.cameraFacing = CameraFacing.back,
  });

  final String title;
  final String hint;
  final CameraFacing cameraFacing;

  /// يفتح الشاشة ويعيد القيمة الممسوحة أو `null` عند الإلغاء.
  static Future<String?> scan(
    BuildContext context, {
    String title = 'مسح الباركود',
    String hint = 'وجّه الكاميرا إلى الباركود أو رمز QR.',
    CameraFacing cameraFacing = CameraFacing.back,
  }) {
    return Navigator.of(context).push<String>(
      MaterialPageRoute<String>(
        builder: (_) => BarcodeScannerScreen(
          title: title,
          hint: hint,
          cameraFacing: cameraFacing,
        ),
      ),
    );
  }

  @override
  State<BarcodeScannerScreen> createState() => _BarcodeScannerScreenState();
}

class _BarcodeScannerScreenState extends State<BarcodeScannerScreen> {
  /// يمنع إرجاع أكثر من قيمة واحدة لو التقطت الكاميرا عدة باركودات في إطار واحد.
  bool _didReturn = false;
  bool _torchOn = false;
  late final MobileScannerController _controller;

  @override
  void initState() {
    super.initState();
    _controller = MobileScannerController(
      cameraResolution: const Size(1280, 720),
      facing: widget.cameraFacing,
    );
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_didReturn) return;
    for (final barcode in capture.barcodes) {
      final value = barcode.rawValue?.trim();
      if (value != null && value.isNotEmpty) {
        _didReturn = true;
        Navigator.of(context).pop(value);
        return;
      }
    }
  }

  void _toggleTorch() {
    setState(() => _torchOn = !_torchOn);
    unawaited(_controller.toggleTorch());
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        backgroundColor: Colors.black,
        body: Stack(
          fit: StackFit.expand,
          children: <Widget>[
            MobileScanner(
              controller: _controller,
              onDetect: _onDetect,
            ),
            const Positioned.fill(
              child: CustomPaint(painter: _ScannerOverlayPainter()),
            ),
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: SafeArea(
                bottom: false,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  child: Row(
                    children: <Widget>[
                      _ScannerIconButton(
                        icon: Icons.close_rounded,
                        tooltip: 'إغلاق',
                        onPressed: () => Navigator.of(context).maybePop(),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          widget.title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: AppColors.onPanel,
                            fontSize: 14.5,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      _ScannerIconButton(
                        icon: _torchOn ? Icons.flash_on_rounded : Icons.flash_off_rounded,
                        tooltip: _torchOn ? 'إطفاء الإضاءة' : 'تشغيل الإضاءة',
                        onPressed: _toggleTorch,
                      ),
                    ],
                  ),
                ),
              ),
            ),
            Positioned(
              left: 24,
              right: 24,
              bottom: 36,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                decoration: BoxDecoration(
                  color: AppColors.panel.withValues(alpha: .88),
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                  border: Border.all(color: AppColors.panelLine),
                ),
                child: Text(
                  widget.hint,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: AppColors.onPanelSoft,
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Round dark chrome button for the scanner bar (close / torch).
class _ScannerIconButton extends StatelessWidget {
  const _ScannerIconButton({
    required this.icon,
    required this.tooltip,
    required this.onPressed,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Material(
        color: Colors.black.withValues(alpha: .45),
        shape: const CircleBorder(side: BorderSide(color: Colors.white24)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onPressed,
          child: SizedBox.square(
            dimension: 40,
            child: Icon(icon, size: 19, color: AppColors.onPanel),
          ),
        ),
      ),
    );
  }
}

/// Solid black scrim with a rounded scan window and white corner brackets.
/// This is camera chrome: the design-system scrim ban does not apply over a
/// live camera preview, and the scanner keeps its dark, focused look.
class _ScannerOverlayPainter extends CustomPainter {
  const _ScannerOverlayPainter();

  static const double _windowWidth = 280;
  static const double _windowHeight = 190;

  @override
  void paint(Canvas canvas, Size size) {
    final window = Rect.fromCenter(
      center: Offset(size.width / 2, size.height / 2 - 18),
      width: _windowWidth,
      height: _windowHeight,
    );

    final scrim = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addRRect(
        RRect.fromRectAndRadius(window, const Radius.circular(AppRadius.lg)),
      );
    canvas.drawPath(
      scrim,
      Paint()..color = Colors.black.withValues(alpha: .55),
    );

    const bracketLength = 24.0;
    final corners = Path()
      .._addCorner(
        Offset(window.right - bracketLength, window.top),
        Offset(window.right, window.top),
        Offset(window.right, window.top + bracketLength),
      )
      .._addCorner(
        Offset(window.left + bracketLength, window.top),
        Offset(window.left, window.top),
        Offset(window.left, window.top + bracketLength),
      )
      .._addCorner(
        Offset(window.right - bracketLength, window.bottom),
        Offset(window.right, window.bottom),
        Offset(window.right, window.bottom - bracketLength),
      )
      .._addCorner(
        Offset(window.left + bracketLength, window.bottom),
        Offset(window.left, window.bottom),
        Offset(window.left, window.bottom - bracketLength),
      );
    canvas.drawPath(
      corners,
      Paint()
        ..color = AppColors.onPanel
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3.5
        ..strokeCap = StrokeCap.round,
    );
  }

  @override
  bool shouldRepaint(covariant _ScannerOverlayPainter oldDelegate) => false;
}

/// زوايا إطار المسح — امتداد على Path حتى تعمل الاستدعاءات المتتالية `.._addCorner`.
extension _CornerPath on Path {
  Path _addCorner(Offset start, Offset elbow, Offset end) {
    moveTo(start.dx, start.dy);
    lineTo(elbow.dx, elbow.dy);
    lineTo(end.dx, end.dy);
    return this;
  }
}
