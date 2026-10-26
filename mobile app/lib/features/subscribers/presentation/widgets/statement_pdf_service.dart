import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/l10n/subscriber_experience_strings.dart';
import '../../domain/entities/subscriber.dart';

/// Arabic statement with embedded local fonts and automatic page breaks.
class StatementPdfService {
  static Future<Uint8List> buildStatement({
    required String subscriberName,
    required FinancialSummary summary,
    required List<LedgerEntry> entries,
    pw.Font? regularFont,
    pw.Font? boldFont,
    DateTime? generatedAt,
  }) async {
    final regular =
        regularFont ??
        pw.Font.ttf(
          await rootBundle.load('assets/fonts/IBMPlexSansArabic-Regular.ttf'),
        );
    final bold =
        boldFont ??
        pw.Font.ttf(
          await rootBundle.load('assets/fonts/IBMPlexSansArabic-Bold.ttf'),
        );
    final issued = generatedAt ?? DateTime.now();
    final date =
        '${issued.year}-${issued.month.toString().padLeft(2, '0')}-${issued.day.toString().padLeft(2, '0')}';
    final navy = PdfColor.fromHex('#123451');
    final pale = PdfColor.fromHex('#EFF6FA');
    final muted = PdfColor.fromHex('#526879');
    final doc = pw.Document(
      title: SubscriberExperienceStrings.statementTitle,
      author: SubscriberExperienceStrings.project,
      theme: pw.ThemeData.withFont(base: regular, bold: bold),
    );
    pw.Widget amount(double value) => pw.Directionality(
      textDirection: pw.TextDirection.ltr,
      child: pw.Text(
        CurrencyFormatter.format(
          value == 0 ? 0 : value,
        ).replaceAll(' ريال', ''),
        style: const pw.TextStyle(fontWeight: pw.FontWeight.bold),
      ),
    );
    pw.Widget stat(String label, double value) => pw.Expanded(
      child: pw.Padding(
        padding: const pw.EdgeInsets.all(10),
        child: pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.start,
          children: [
            pw.Text(label, style: pw.TextStyle(color: muted, fontSize: 9)),
            pw.SizedBox(height: 5),
            pw.Row(
              children: [
                amount(value),
                pw.SizedBox(width: 4),
                pw.Text(SubscriberExperienceStrings.currency),
              ],
            ),
          ],
        ),
      ),
    );
    doc.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.a4,
        margin: const pw.EdgeInsets.all(32),
        maxPages: 1000,
        textDirection: pw.TextDirection.rtl,
        header: (context) => pw.Container(
          padding: const pw.EdgeInsets.only(bottom: 12),
          decoration: pw.BoxDecoration(
            border: pw.Border(bottom: pw.BorderSide(color: navy, width: 2)),
          ),
          child: pw.Row(
            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
            children: [
              pw.Text(
                SubscriberExperienceStrings.project,
                style: pw.TextStyle(
                  color: navy,
                  fontWeight: pw.FontWeight.bold,
                ),
              ),
              pw.Row(
                mainAxisSize: pw.MainAxisSize.min,
                children: [
                  pw.Text(
                    '${SubscriberExperienceStrings.issuedAt}: ',
                    style: pw.TextStyle(color: muted, fontSize: 9),
                  ),
                  pw.Directionality(
                    textDirection: pw.TextDirection.ltr,
                    child: pw.Text(
                      date,
                      style: pw.TextStyle(color: muted, fontSize: 9),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
        footer: (context) => pw.Container(
          padding: const pw.EdgeInsets.only(top: 10),
          child: pw.Row(
            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
            children: [
              pw.Text(
                SubscriberExperienceStrings.allEntries,
                style: pw.TextStyle(color: muted, fontSize: 8),
              ),
              pw.Text(
                '${SubscriberExperienceStrings.page} ${context.pageNumber} ${SubscriberExperienceStrings.ofTotal} ${context.pagesCount}',
                style: pw.TextStyle(color: muted, fontSize: 8),
              ),
            ],
          ),
        ),
        build: (context) => [
          pw.SizedBox(height: 18),
          pw.Text(
            SubscriberExperienceStrings.statementTitle,
            style: pw.TextStyle(
              fontSize: 23,
              color: navy,
              fontWeight: pw.FontWeight.bold,
            ),
          ),
          pw.SizedBox(height: 7),
          pw.Text('${SubscriberExperienceStrings.subscriber}: $subscriberName'),
          pw.SizedBox(height: 16),
          pw.Container(
            color: pale,
            child: pw.Row(
              children: [
                stat(
                  SubscriberExperienceStrings.generalBalance,
                  summary.generalBalance,
                ),
                stat(
                  SubscriberExperienceStrings.totalCredit,
                  summary.totalCredit,
                ),
                stat(
                  SubscriberExperienceStrings.totalDebit,
                  summary.totalDebit,
                ),
              ],
            ),
          ),
          pw.SizedBox(height: 20),
          if (entries.isEmpty)
            pw.Container(
              padding: const pw.EdgeInsets.all(24),
              alignment: pw.Alignment.center,
              child: pw.Text(
                SubscriberExperienceStrings.emptyStatement,
                style: pw.TextStyle(color: muted),
              ),
            )
          else
            pw.TableHelper.fromTextArray(
              headers: [
                SubscriberExperienceStrings.description,
                SubscriberExperienceStrings.runningBalance,
                SubscriberExperienceStrings.amount,
                SubscriberExperienceStrings.party,
                SubscriberExperienceStrings.type,
                SubscriberExperienceStrings.date,
              ],
              data: [
                for (final e in entries)
                  [
                    e.description ?? '',
                    CurrencyFormatter.format(
                      e.runningBalance == 0 ? 0 : e.runningBalance,
                    ).replaceAll(' ريال', ''),
                    CurrencyFormatter.format(
                      e.amount == 0 ? 0 : e.amount,
                    ).replaceAll(' ريال', ''),
                    e.party,
                    e.typeLabel,
                    e.createdAt ?? '',
                  ],
              ],
              headerStyle: pw.TextStyle(
                font: bold,
                color: PdfColors.white,
                fontSize: 9,
              ),
              cellStyle: pw.TextStyle(font: regular, fontSize: 8),
              headerDecoration: pw.BoxDecoration(color: navy),
              oddRowDecoration: pw.BoxDecoration(color: pale),
              cellPadding: const pw.EdgeInsets.symmetric(
                horizontal: 7,
                vertical: 9,
              ),
              cellAlignment: pw.Alignment.centerRight,
              cellAlignments: {1: pw.Alignment.center, 2: pw.Alignment.center},
              border: pw.TableBorder(
                horizontalInside: pw.BorderSide(
                  color: PdfColor.fromHex('#DBE5EC'),
                  width: .5,
                ),
              ),
              columnWidths: {
                0: const pw.FlexColumnWidth(1.7),
                1: const pw.FlexColumnWidth(1.1),
                2: const pw.FlexColumnWidth(1),
                3: const pw.FlexColumnWidth(1.4),
                4: const pw.FlexColumnWidth(.7),
                5: const pw.FlexColumnWidth(1.1),
              },
            ),
        ],
      ),
    );
    return doc.save();
  }

  static Future<void> shareStatement({
    required String subscriberName,
    required FinancialSummary summary,
    required List<LedgerEntry> entries,
  }) async {
    final bytes = await buildStatement(
      subscriberName: subscriberName,
      summary: summary,
      entries: entries,
    );
    final safeName = subscriberName.replaceAll(RegExp(r'[\/:*?"<>|]'), '_');
    await Printing.sharePdf(bytes: bytes, filename: 'statement-$safeName.pdf');
  }
}
