import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/l10n/printing_strings.dart';
import '../../../../core/l10n/reading_invoice_strings.dart';
import '../../../../core/network/api_endpoints.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../models/reading.dart';

/// Integration bridge between Dev 2's reading auto-invoice flow and Dev 3's
/// official PDF printing and image sharing. Messaging remains user-confirmed.
class ReadingInvoiceActionsService {
  const ReadingInvoiceActionsService(this._dio);

  final Dio _dio;

  Future<void> printInvoice(InvoiceSummary invoice) async {
    final invoiceId = invoice.id;
    if (invoiceId == null) throw StateError(PrintingStrings.missingInvoice);
    final pdfService = PdfPrintService(client: FinanceApiClient(_dio));
    final bytes = await pdfService.fetchPdf(
      ApiEndpoints.invoicePdf(invoiceId),
      fallbackUrl: invoice.pdfUrl,
    );
    await pdfService.printBytes(
      bytes,
      name: 'invoice-${invoice.billNumber ?? invoiceId}.pdf',
    );
  }

  Future<void> shareOnWhatsApp(InvoiceSummary invoice) async {
    final invoiceId = invoice.id;
    final phone = _normalizeYemeniPhone(invoice.subscriberPhone);
    if (phone == null) throw StateError(ReadingInvoiceStrings.missingPhone);
    if (invoiceId == null) {
      throw StateError(ReadingInvoiceStrings.missingInvoice);
    }

    // Use the authenticated API; the PDF URL alone may require a session.
    final response = await _dio.get<List<int>>(
      ApiEndpoints.invoicePdf(invoiceId),
      options: Options(responseType: ResponseType.bytes),
    );
    final bytes = response.data;
    if (bytes == null || !_isPdf(bytes)) {
      throw StateError(ReadingInvoiceStrings.invalidPdf);
    }

    final images = <XFile>[];
    final names = <String>[];
    final safeNumber = (invoice.billNumber ?? '$invoiceId').replaceAll(
      RegExp(r'[^A-Za-z0-9._-]'),
      '_',
    );
    try {
      // Keep every page, including any continuation of the invoice details.
      await for (final page in Printing.raster(
        Uint8List.fromList(bytes),
        dpi: 144,
      )) {
        final name = 'invoice-$safeNumber-${images.length + 1}.png';
        images.add(
          XFile.fromData(await page.toPng(), mimeType: 'image/png', name: name),
        );
        names.add(name);
      }
    } catch (_) {
      throw StateError(ReadingInvoiceStrings.imageFailed);
    }
    if (images.isEmpty) throw StateError(ReadingInvoiceStrings.imageFailed);

    // A wa.me URL cannot attach a local image. Use the native share sheet;
    // selecting WhatsApp, the recipient and sending are explicit user actions.
    try {
      await Share.shareXFiles(
        images,
        fileNameOverrides: names,
        subject: invoice.billNumber ?? ReadingInvoiceStrings.invoiceTitle,
        text:
            '${ReadingInvoiceStrings.recipient(invoice.subscriberName, phone)}\n${_message(invoice)}',
      );
    } catch (_) {
      throw StateError(ReadingInvoiceStrings.shareFailed);
    }
  }

  Future<void> sendSms(InvoiceSummary invoice) async {
    final phone = _normalizeYemeniPhone(invoice.subscriberPhone);
    if (phone == null) throw StateError(ReadingInvoiceStrings.missingPhone);
    final uri = Uri(
      scheme: 'sms',
      path: '+$phone',
      // Non-HTTP URI bodies must use percent-encoded spaces, not '+'.
      query: 'body=${Uri.encodeComponent(_message(invoice))}',
    );
    try {
      if (await launchUrl(uri, mode: LaunchMode.externalApplication)) return;
    } catch (_) {
      // Report unsupported SMS handlers without claiming that a message sent.
    }
    throw StateError(ReadingInvoiceStrings.smsUnavailable);
  }

  String _message(InvoiceSummary invoice) => <String>[
    ReadingInvoiceStrings.invoiceTitle,
    if (invoice.billNumber != null)
      ReadingInvoiceStrings.number(invoice.billNumber!),
    if (invoice.periodStart != null && invoice.periodEnd != null)
      ReadingInvoiceStrings.period(
        _date(invoice.periodStart!),
        _date(invoice.periodEnd!),
      ),
    if (invoice.meterCode != null)
      ReadingInvoiceStrings.meter(invoice.meterCode!),
    if (invoice.consumptionUnits != null)
      ReadingInvoiceStrings.consumption(invoice.consumptionUnits!),
    ReadingInvoiceStrings.total(invoice.totalAmount),
  ].join('\n');

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
