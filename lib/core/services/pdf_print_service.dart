import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:path_provider/path_provider.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../config/app_config.dart';
import '../error/failures.dart';
import '../network/api_client.dart';

/// محرّك الطباعة الأول (`PDF A5 Landscape`) — تحميل الملف الرسمي من الخادم ثم
/// معاينته/طباعته/مشاركته. لا يُعاد توليد التنسيق في التطبيق: الخادم هو مصدر
/// الحقيقة (Spatie Pdf + `invoice.blade.php`/`receipt.blade.php`).
///
/// المرجع: الخطة §4.4 (زر `generate_pdf`) و§4.5 و§2.3 (المسار 45 و53).
class PdfPrintService {
  const PdfPrintService({required DioClient client, AppConfig config = const AppConfig()})
      : _client = client,
        _config = config;

  final DioClient _client;
  final AppConfig _config;

  /// تحميل بايتات الـ PDF من مسار الـ API، وعند فشل ذلك (نسخة API قديمة) نجرب
  /// مسار الطباعة في لوحة Filament عبر رابط مطلق.
  Future<Uint8List> fetchPdf(String apiPath, {String? fallbackUrl}) async {
    try {
      return await _client.downloadBytes(apiPath);
    } on AppFailure catch (failure) {
      final url = fallbackUrl;
      if (url == null) rethrow;
      try {
        final response = await _client.dio.get<dynamic>(
          url,
          options: Options(responseType: ResponseType.bytes),
        );
        final data = response.data;
        if (data is Uint8List) return data;
        if (data is List<int>) return Uint8List.fromList(data);
        rethrow;
      } catch (_) {
        throw failure;
      }
    }
  }

  /// طباعة مباشرة عبر طباعة النظام (`Printing.layoutPdf`).
  Future<void> printBytes(Uint8List bytes, {String name = 'document'}) async {
    await Printing.layoutPdf(
      name: name,
      onLayout: (_) async => bytes,
      format: PdfPageFormat.a5,
    );
  }

  /// مشاركة الملف (واتساب/تطبيقات أخرى) بعد حفظه في مسار مؤقت.
  Future<String> sharePdf(Uint8List bytes, {String fileName = 'document.pdf'}) async {
    final file = await _writeTemp(bytes, fileName);
    await Share.shareXFiles(
      <XFile>[XFile(file.path)],
      subject: fileName,
    );
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
    final base = _config.pdfBaseUrl;
    if (base == null || base.isEmpty) return null;
    return '$base$relativePath';
  }
}
