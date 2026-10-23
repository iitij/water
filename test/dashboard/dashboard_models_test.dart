import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/dashboard/data/models/dashboard_models.dart';
import 'package:water_project/features/dashboard/data/models/report_models.dart';

void main() {
  group('DashboardDataModel', () {
    test('parses the documented payload and computes net due without duplication', () {
      final dashboard = DashboardDataModel.fromJson({
        'stats_overview': {
          'active_subscribers_count': 145,
          'total_subscribers_count': 160,
          'outstanding_amount': '1250400.00',
          'monthly_collection': 480000,
          'overdue_invoices_count': 1,
          'unpaid_invoices_count': 2,
          'readings_this_month': 138,
          'subscribers_sparkline': [7, 3, 4],
          'collection_sparkline': [3, 5, 7],
        },
        'monthly_revenue_chart': {
          'labels': ['سبتمبر 2026', 'أكتوبر 2026'],
          'data': [320000, 480000],
        },
        'latest_invoices': const [],
        'overdue_invoices': [
          {
            'id': 9,
            'bill_number': 'INV-9',
            'subscriber': {'id': 4, 'name': 'أحمد'},
            'bill_date': '2026-09-01',
            'total_amount': 1000,
            'previous_balance': 500,
            'credit_applied': 100,
            'amount_paid': 200,
            'status': 'overdue',
          },
        ],
      });

      expect(dashboard.stats.activeSubscribersCount, 145);
      expect(dashboard.stats.monthlyCollection, 480000);
      expect(dashboard.monthlyRevenue.length, 2);
      expect(dashboard.overdueInvoices.single.remainingAmount, 1200);
      expect(dashboard.overdueInvoices.single.subscriberName, 'أحمد');

      final restored = DashboardDataModel.fromJson(dashboard.toJson());
      expect(restored.overdueInvoices.single.trueRemainingAmount, isNull);
      expect(restored.overdueInvoices.single.remainingAmount, 1200);
    });

    test('honours true_remaining_amount and legacy stats aliases', () {
      final invoice = DashboardInvoiceModel.fromJson({
        'id': 1,
        'total_amount': 100,
        'previous_balance': 900,
        'amount_paid': 0,
        'true_remaining_amount': 750,
      });
      final stats = DashboardStatsModel.fromJson({
        'subscribers_count': 20,
        'collected_this_month': 5000,
        'total_unpaid_amount': 7000,
      });

      expect(invoice.remainingAmount, 750);
      expect(stats.totalSubscribersCount, 20);
      expect(stats.monthlyCollection, 5000);
      expect(stats.outstandingAmount, 7000);
    });
  });

  test('FinancialReportModel parses summary, chart and breakdowns', () {
    final report = FinancialReportModel.fromJson({
      'summary': {
        'total_invoiced': 10000,
        'total_collected': 7500,
        'total_outstanding': 2500,
        'overdue_amount': 500,
        'invoices_count': 10,
        'paid_invoices_count': 7,
        'overdue_invoices_count': 1,
      },
      'monthly_revenue_chart': {
        'labels': ['أكتوبر'],
        'data': [7500],
      },
      'invoice_statuses': {
        'paid': {'label': 'مسددة', 'amount': 7500, 'count': 7},
      },
    });

    expect(report.collectionRate, 75);
    expect(report.revenueSeries.single.value, 7500);
    expect(report.statusBreakdown.single.count, 7);
  });
}
