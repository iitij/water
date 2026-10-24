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

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(title: Text(widget.title)),
        body: Stack(
          fit: StackFit.expand,
          children: <Widget>[
            MobileScanner(
              facing: widget.cameraFacing,
              onDetect: _onDetect,
            ),
            IgnorePointer(
              child: Center(
                child: Container(
                  width: 280,
                  height: 170,
                  decoration: BoxDecoration(
                    border: Border.all(color: Colors.white, width: 3),
                    borderRadius: BorderRadius.circular(AppRadius.md),
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
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  border: Border.all(color: AppColors.panelLine),
                ),
                child: Text(
                  widget.hint,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AppColors.onPanel, fontSize: 12),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
