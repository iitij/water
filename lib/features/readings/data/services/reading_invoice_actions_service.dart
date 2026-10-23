import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:printing/printing.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../../core/network/api_endpoints.dart';
import '../models/reading.dart';

/// Narrow integration seam for the invoice module's print and WhatsApp actions.
/// Dev 3 can replace this adapter with the shared PdfPrintService and
/// WhatsAppLauncherService without changing the reading screens.
class ReadingInvoiceActionsService {
  const ReadingInvoiceActionsService(this._dio);

  final Dio _dio;

  Future<void> printInvoice(InvoiceSummary invoice) async {
    final invoiceId = invoice.id;
    if (invoiceId == null) throw StateError('رقم الفاتورة غير متاح للطباعة.');

    try {
      final response = await _dio.get<List<int>>(
        ApiEndpoints.invoicePdf(invoiceId),
        options: Options(responseType: ResponseType.bytes),
      );
      final bytes = response.data;
      if (bytes != null && _isPdf(bytes)) {
        await Printing.layoutPdf(
          onLayout: (_) async => Uint8List.fromList(bytes),
          name: 'invoice-${invoice.billNumber ?? invoiceId}.pdf',
        );
        return;
      }
    } on DioException catch (error) {
      if (error.response?.statusCode != 404) rethrow;
    }

    // The existing Laravel reading response supplies `/invoices/{id}/print` but
    // the API PDF endpoint is not yet in routes/api.php. Open that print view as
    // a compatible fallback until Dev 3 wires the shared PDF service.
    final rawPrintUrl = invoice.pdfUrl;
    var printUri = rawPrintUrl == null ? null : Uri.tryParse(rawPrintUrl);
    if (printUri != null && !printUri.hasScheme) {
      final baseUri = Uri.tryParse(_dio.options.baseUrl);
      if (baseUri != null) printUri = baseUri.resolveUri(printUri);
    }
    if (printUri == null || !await launchUrl(printUri, mode: LaunchMode.externalApplication)) {
      throw StateError('تعذر فتح ملف الفاتورة للطباعة.');
    }
  }

  Future<void> shareOnWhatsApp(InvoiceSummary invoice) async {
    final phone = _normalizeYemeniPhone(invoice.subscriberPhone);
    if (phone == null) throw StateError('رقم هاتف المشترك غير متاح لإرسال الفاتورة.');
    final message = <String>[
      'فاتورة استهلاك مياه',
      if (invoice.billNumber != null) 'رقم الفاتورة: ${invoice.billNumber}',
      if (invoice.periodStart != null && invoice.periodEnd != null)
        'الفترة: ${_date(invoice.periodStart!)} — ${_date(invoice.periodEnd!)}',
      if (invoice.meterCode != null) 'العداد: ${invoice.meterCode}',
      if (invoice.consumptionUnits != null) 'الاستهلاك: ${invoice.consumptionUnits} وحدة',
      'الإجمالي المستحق: ${invoice.totalAmount} ريال',
      if (invoice.pdfUrl != null) 'رابط الفاتورة: ${invoice.pdfUrl}',
    ].join('\n');
    final uri = Uri.https('wa.me', '/$phone', {'text': message});
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      throw StateError('تعذر فتح تطبيق واتساب على هذا الجهاز.');
    }
  }

  bool _isPdf(List<int> bytes) =>
      bytes.length >= 4 && String.fromCharCodes(bytes.take(4)) == '%PDF';

  String? _normalizeYemeniPhone(String? value) {
    if (value == null) return null;
    var digits = value.replaceAll(RegExp(r'\D'), '');
    if (digits.startsWith('00')) digits = digits.substring(2);
    if (digits.startsWith('0')) {
      digits = '967${digits.substring(1)}';
    } else if (!digits.startsWith('967')) {
      digits = '967$digits';
    }
    return digits.length >= 11 ? digits : null;
  }

  String _date(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';
}
