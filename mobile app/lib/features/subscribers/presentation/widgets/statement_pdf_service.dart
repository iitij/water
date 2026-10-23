import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import '../../domain/entities/subscriber.dart';

/// تصدير/مشاركة كشف حساب المشترك PDF (مهمة فؤاد الجانبية — الخطة 5.2).
class StatementPdfService {
  static Future<void> shareStatement({
    required String subscriberName,
    required FinancialSummary summary,
    required List<LedgerEntry> entries,
  }) async {
    final doc = pw.Document();
    doc.addPage(
      pw.Page(
        pageFormat: PdfPageFormat.a4,
        build: (ctx) => pw.Directionality(
          textDirection: pw.TextDirection.rtl,
          child: pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.start,
            children: [
              pw.Text('كشف حساب مشترك', style: pw.TextStyle(fontSize: 20, fontWeight: pw.FontWeight.bold)),
              pw.SizedBox(height: 6),
              pw.Text('المشترك: $subscriberName'),
              pw.Text('إجمالي دائن: ${summary.totalCredit}  |  إجمالي مدين: ${summary.totalDebit}'),
              pw.SizedBox(height: 12),
              pw.TableHelper.fromTextArray(
                headers: ['التاريخ', 'النوع', 'الجهة', 'المبلغ', 'الرصيد التراكمي', 'البيان'],
                data: [
                  for (final e in entries)
                    [e.createdAt ?? '', e.typeLabel, e.party, '${e.amount}', '${e.runningBalance}', e.description ?? ''],
                ],
              ),
            ],
          ),
        ),
      ),
    );
    final bytes = await doc.save();
    await Printing.sharePdf(bytes: bytes, filename: 'statement-$subscriberName.pdf');
  }
}
