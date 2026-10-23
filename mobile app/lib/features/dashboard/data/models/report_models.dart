import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/dashboard_entities.dart';

class ReportPointModel extends ReportPoint {
  const ReportPointModel({required super.label, required super.value});

  factory ReportPointModel.fromJson(dynamic value) {
    final json = adminMap(value);
    return ReportPointModel(
      label: adminString(json['label'] ?? json['month'] ?? json['date']),
      value: adminDouble(json['value'] ?? json['amount'] ?? json['total']),
    );
  }

  Map<String, dynamic> toJson() => {'label': label, 'value': value};
}

class ReportBreakdownModel extends ReportBreakdown {
  const ReportBreakdownModel({
    required super.key,
    required super.label,
    required super.value,
    super.count,
  });

  factory ReportBreakdownModel.fromJson(dynamic value, [String? fallbackKey]) {
    final json = adminMap(value);
    final key = adminString(json['key'] ?? json['status'] ?? json['id'], fallbackKey ?? '');
    return ReportBreakdownModel(
      key: key,
      label: adminString(json['label'] ?? json['name'] ?? json['status'], key),
      value: adminDouble(json['value'] ?? json['amount'] ?? json['total']),
      count: adminInt(json['count'] ?? json['invoices_count']),
    );
  }

  Map<String, dynamic> toJson() => {
        'key': key,
        'label': label,
        'value': value,
        'count': count,
      };
}

class FinancialReportModel extends FinancialReport {
  const FinancialReportModel({
    required super.totalInvoiced,
    required super.totalCollected,
    required super.totalOutstanding,
    required super.overdueAmount,
    required super.invoicesCount,
    required super.paidInvoicesCount,
    required super.overdueInvoicesCount,
    required super.revenueSeries,
    required super.statusBreakdown,
    required super.squareBreakdown,
  });

  factory FinancialReportModel.fromJson(dynamic value) {
    final root = adminDataMap(value);
    final summary = root['summary'] is Map ? adminMap(root['summary']) : root;
    return FinancialReportModel(
      totalInvoiced: adminDouble(
        summary['total_invoiced'] ?? summary['invoiced_amount'],
      ),
      totalCollected: adminDouble(
        summary['total_collected'] ??
            summary['collected_amount'] ??
            summary['total_payments'],
      ),
      totalOutstanding: adminDouble(
        summary['total_outstanding'] ?? summary['outstanding_amount'],
      ),
      overdueAmount: adminDouble(summary['overdue_amount']),
      invoicesCount: adminInt(summary['invoices_count']),
      paidInvoicesCount: adminInt(summary['paid_invoices_count']),
      overdueInvoicesCount: adminInt(summary['overdue_invoices_count']),
      revenueSeries: _points(
        root['revenue_series'] ?? root['monthly_revenue_chart'] ?? root['monthly_revenue'],
      ),
      statusBreakdown: _breakdown(
        root['status_breakdown'] ?? root['invoice_statuses'],
      ),
      squareBreakdown: _breakdown(
        root['square_breakdown'] ?? root['by_square'],
      ),
    );
  }

  static List<ReportPoint> _points(dynamic value) {
    if (value is Map && value['labels'] is List) {
      final labels = value['labels'] as List;
      final values = value['data'] is List
          ? value['data'] as List
          : value['values'] is List
              ? value['values'] as List
              : const [];
      return List.generate(
        labels.length < values.length ? labels.length : values.length,
        (index) => ReportPointModel(
          label: adminString(labels[index]),
          value: adminDouble(values[index]),
        ),
      );
    }
    return adminList(value).map(ReportPointModel.fromJson).toList(growable: false);
  }

  static List<ReportBreakdown> _breakdown(dynamic value) {
    if (value is Map) {
      return value.entries
          .map(
            (entry) => entry.value is Map
                ? ReportBreakdownModel.fromJson(entry.value, adminString(entry.key))
                : ReportBreakdownModel(
                    key: adminString(entry.key),
                    label: adminString(entry.key),
                    value: adminDouble(entry.value),
                  ),
          )
          .toList(growable: false);
    }
    return adminList(value)
        .map((item) => ReportBreakdownModel.fromJson(item))
        .toList(growable: false);
  }

  Map<String, dynamic> toJson() => {
        'summary': {
          'total_invoiced': totalInvoiced,
          'total_collected': totalCollected,
          'total_outstanding': totalOutstanding,
          'overdue_amount': overdueAmount,
          'invoices_count': invoicesCount,
          'paid_invoices_count': paidInvoicesCount,
          'overdue_invoices_count': overdueInvoicesCount,
        },
        'revenue_series': revenueSeries
            .map((point) => (point as ReportPointModel).toJson())
            .toList(),
        'status_breakdown': statusBreakdown
            .map((item) => (item as ReportBreakdownModel).toJson())
            .toList(),
        'square_breakdown': squareBreakdown
            .map((item) => (item as ReportBreakdownModel).toJson())
            .toList(),
      };
}
