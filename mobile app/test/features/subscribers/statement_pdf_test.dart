import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/subscribers/domain/entities/subscriber.dart';
import 'package:water_project/features/subscribers/presentation/widgets/statement_pdf_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  for (final count in [0, 120]) {
    test(
      'Arabic statement embeds bundled fonts and paginates $count entries',
      () async {
        final bytes = await StatementPdfService.buildStatement(
          subscriberName: 'مشترك تجريبي للمعاينة',
          summary: const FinancialSummary(
            totalCredit: 15000,
            totalDebit: 66600,
          ),
          generatedAt: DateTime(2026, 10, 26, 4),
          entries: List.generate(
            count,
            (index) => LedgerEntry(
              id: index + 1,
              type: index.isEven ? 'invoice' : 'payment',
              amount: index.isEven ? -1200 : 600,
              runningBalance: -(index + 1) * 600,
              description:
                  'حركة تجريبية لاختبار التفاف النص العربي داخل جدول كشف الحساب',
              createdAt: '2026-10-26',
            ),
          ),
        );
        final structure = latin1.decode(bytes);
        expect(structure, startsWith('%PDF-'));
        expect(structure, contains('/FontFile2'));
        expect(structure, contains('/ToUnicode'));
        final pageCount = RegExp(
          r'/Type\s*/Page\b',
        ).allMatches(structure).length;
        expect(pageCount, count == 0 ? 1 : greaterThan(1));
        if (const bool.fromEnvironment('WRITE_REDESIGN_PREVIEWS')) {
          final output = Directory('build/redesign-preview')
            ..createSync(recursive: true);
          await File('${output.path}/statement-$count.pdf').writeAsBytes(bytes);
        }
      },
    );
  }
}
