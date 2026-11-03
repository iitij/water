import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../error/failures.dart';
import '../finance/finance_api_client.dart';
import '../l10n/printing_strings.dart';

/// محرّك الطباعة الأول (`PDF A5 Landscape`) — تحميل الملف الرسمي من الخادم ثم
/// معاينته/طباعته/مشاركته. لا يُعاد توليد التنسيق في التطبيق: الخادم هو مصدر
/// الحقيقة (Spatie Pdf + `invoice.blade.php`/`receipt.blade.php`).
///
/// المرجع: الخطة §4.4 (زر `generate_pdf`) و§4.5 و§2.3 (المسار 45 و53).
class PdfPrintService {
  const PdfPrintService({required FinanceApiClient client}) : _client = client;

  final FinanceApiClient _client;

  /// تحميل بايتات الـ PDF من مسار الـ API، وعند فشل ذلك (نسخة API قديمة) نجرب
  /// مسار الطباعة في لوحة Filament عبر رابط مطلق.
  Future<Uint8List> fetchPdf(String apiPath, {String? fallbackUrl}) async {
    try {
      return _validated(
        await _client.downloadBytes(
          apiPath,
          timeout: const Duration(seconds: 45),
        ),
      );
    } on AppFailure catch (failure) {
      final url = fallbackUrl;
      // Older APIs may lack the endpoint. Never repeat a slow/failed request,
      // bypass a permission error, or forward credentials to another origin.
      if (url == null ||
          !const [404, 405].contains(failure.statusCode) ||
          !_sameServer(url) ||
          _client.dio.options.baseUrl.isEmpty ||
          _resolved(url) == _resolved(apiPath)) {
        rethrow;
      }
      try {
        return _validated(
          await _client.downloadBytes(
            url,
            timeout: const Duration(seconds: 45),
          ),
        );
      } catch (_) {
        throw failure;
      }
    }
  }

  Uri _resolved(String path) =>
      Uri.parse(_client.dio.options.baseUrl).resolve(financeApiPath(path));

  bool _sameServer(String path) {
    final base = Uri.tryParse(_client.dio.options.baseUrl);
    final target = Uri.tryParse(path);
    if (base == null || target == null || !base.hasAuthority) return false;
    final resolved = base.resolveUri(target);
    return resolved.userInfo.isEmpty && resolved.origin == base.origin;
  }

  static Uint8List _validated(Uint8List bytes) {
    if (bytes.length < 5 || String.fromCharCodes(bytes.take(5)) != '%PDF-') {
      throw const UnexpectedFailure(PrintingStrings.invalidPdf);
    }
    return bytes;
  }

  /// طباعة مباشرة عبر طباعة النظام (`Printing.layoutPdf`).
  Future<void> printBytes(Uint8List bytes, {String name = 'document'}) async {
    await printDocument(bytes, name: name);
  }

  /// The preview and direct actions must use the same native print settings.
  static Future<bool> printDocument(
    Uint8List bytes, {
    String name = 'document',
  }) async {
    _validated(bytes);
    try {
      return await Printing.layoutPdf(
        name: name,
        onLayout: (_) async => bytes,
        format: PdfPageFormat.a5.landscape,
        dynamicLayout: false,
        windowsModernDialog: defaultTargetPlatform == TargetPlatform.windows,
      );
    } catch (_) {
      throw const UnexpectedFailure(PrintingStrings.failed);
    }
  }

  /// مشاركة الملف (واتساب/تطبيقات أخرى) بعد حفظه في مسار مؤقت.
  Future<String> sharePdf(
    Uint8List bytes, {
    String fileName = 'document.pdf',
  }) async {
    final file = await _writeTemp(bytes, fileName);
    await Share.shareXFiles(<XFile>[XFile(file.path)], subject: fileName);
    return file.path;
  }

  /// حفظ + فتح بالعارض الافتراضي (مفيد عندما يعجز عارض `printing` على جهاز معين).
  Future<String> saveAndOpen(Uint8List bytes, String fileName) async {
    final file = await _writeTemp(bytes, fileName);
    try {
      await Process.run('xdg-open', <String>[file.path]);
    } catch (_) {
      // المنصة لا تسمح بفتح عملية (iOS/Android) → الاعتماد على العارض الداخلي.
    }
    return file.path;
  }

  Future<File> _writeTemp(Uint8List bytes, String fileName) async {
    final dir = await getTemporaryDirectory();
    final safeName = fileName.replaceAll(RegExp(r'[^A-Za-z0-9._\-]'), '_');
    final file = File('${dir.path}/$safeName');
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  /// مسار بديل من لوحة Filament (`/invoices/{id}/print`) — يُستخدم عند عدم توفر
  /// `/api/invoices/{id}/pdf` بعد (يرجى دمجه من Dev 1 في Sprint 1).
  String? filamentFallbackUrl(String relativePath) {
    final url = financeAdminPrintUrl(relativePath);
    return url == relativePath ? null : url;
  }
}
