import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/dashboard/data/models/report_models.dart';

void main() {
  test('report cache preserves refunds and net receipts without double counting', () {
    final report = FinancialReportModel.fromJson({
      'summary': {'total_collected': 8000, 'total_invoiced': 6000},
      'financial_movements': {
        'refunds_disbursed': 1250.25, 'net_receipts_after_refunds': 6749.75,
      },
    });
    expect(report.totalCollected, 8000);
    expect(report.refundsDisbursed, 1250.25);
    expect(report.netReceiptsAfterRefunds, 6749.75);
    final restored = FinancialReportModel.fromJson(report.toJson());
    expect(restored.refundsDisbursed, 1250.25);
    expect(restored.netReceiptsAfterRefunds, 6749.75);
    expect(FinancialReportModel.fromJson({'summary': {'total_collected': 50}}).refundsDisbursed, isNull);
  });
}
