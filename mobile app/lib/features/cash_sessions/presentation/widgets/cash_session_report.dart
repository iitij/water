import 'package:flutter/material.dart';

import '../../../../core/finance/finance_locator.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/services/pdf_print_service.dart';
import '../../../../core/widgets/pdf_preview_sheet.dart';
import '../../domain/cash_session.dart';

/// تقرير الصندوق اليومي: يفتح نسخة الخادم الرسمية (A4 عربي + تفقيط) في
/// نافذة معاينة فيها الطباعة والمشاركة عبر واتساب أو أي تطبيق آخر.
///
/// لماذا من الخادم؟ لأن تشكيل العربية والخطوط وتفقيط المبلغ لا يمكن ضمانها
/// على كل جهاز ميداني، والنسخة الورقية يجب أن تطابق ما يراه المشرف في اللوحة.
///
/// مرجع: خطة الإصدار 2.0 — بطاقة S4-06.
Future<void> openCashSessionReportPdf(
  BuildContext context,
  CashSession session,
) {
  return PdfPreviewSheet.open(
    context,
    title: 'تقرير صندوق ${session.id}',

    service: sl<PdfPrintService>(),
    fileName: 'cash-session-${session.id}.pdf',
    loader: () => sl<PdfPrintService>().fetchPdf(
      FinanceEndpoints.cashSessionReportPdf(session.id),
    ),
  );
}

/// زر موحّد لتقرير الصندوق يُستخدم في بطاقتي الصندوق المفتوح والمغلق.
class CashSessionReportButton extends StatelessWidget {
  const CashSessionReportButton({super.key, required this.session});

  final CashSession session;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton.icon(
      onPressed: () => openCashSessionReportPdf(context, session),
      icon: const Icon(Icons.picture_as_pdf_outlined),
      label: const Text('تقرير الصندوق (PDF)'),
    );
  }
}
