import '../../../admin_shared/data/admin_json.dart';
import '../../domain/entities/dashboard_entities.dart';

class DashboardInvoiceModel extends DashboardInvoice {
  const DashboardInvoiceModel({
    required super.id,
    required super.billNumber,
    required super.subscriberName,
    required super.billDate,
    required super.totalAmount,
    required super.previousBalance,
    required super.creditApplied,
    required super.amountPaid,
    required super.status,
    super.subscriberId,
    super.meterId,
    super.subscriberPhone,
    super.trueRemainingAmount,
  });

  factory DashboardInvoiceModel.fromJson(dynamic value) {
    final json = adminMap(value);
    final subscriber = adminMap(json['subscriber']);
    final meter = adminMap(json['meter']);
    final id = adminInt(json['id']);
    return DashboardInvoiceModel(
      id: id,
      billNumber: adminString(
        json['bill_number'] ?? json['invoice_number'],
        id == 0 ? '—' : '#$id',
      ),
      subscriberId: _nullableInt(json['subscriber_id'] ?? subscriber['id']),
      meterId: _nullableInt(json['meter_id'] ?? meter['id']),
      subscriberName: adminString(
        json['subscriber_name'] ?? subscriber['name'] ?? json['beneficiary_name'],
        'مشترك غير محدد',
      ),
      subscriberPhone: adminNullableString(
        json['subscriber_phone'] ?? subscriber['phone'],
      ),
      billDate: adminDate(json['bill_date'] ?? json['created_at']),
      totalAmount: adminDouble(json['total_amount']),
      previousBalance: adminDouble(json['previous_balance']),
      creditApplied: adminDouble(json['credit_applied']),
      amountPaid: adminDouble(json['amount_paid']),
      status: adminString(json['status'], 'issued'),
      trueRemainingAmount: adminNullableDouble(json['true_remaining_amount']),
    );
  }

  static int? _nullableInt(dynamic value) {
    if (value == null || value == '') return null;
    return adminInt(value);
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'bill_number': billNumber,
        'subscriber_id': subscriberId,
        'meter_id': meterId,
        'subscriber_name': subscriberName,
        'subscriber_phone': subscriberPhone,
        'bill_date': billDate?.toIso8601String(),
        'total_amount': totalAmount,
        'previous_balance': previousBalance,
        'credit_applied': creditApplied,
        'amount_paid': amountPaid,
        'status': status,
        if (trueRemainingAmount != null) 'true_remaining_amount': trueRemainingAmount,
      };
}

class DashboardStatsModel extends DashboardStats {
  const DashboardStatsModel({
    required super.activeSubscribersCount,
    required super.totalSubscribersCount,
    required super.outstandingAmount,
    required super.monthlyCollection,
    required super.overdueInvoicesCount,
    required super.unpaidInvoicesCount,
    required super.readingsThisMonth,
    required super.subscribersSparkline,
    required super.collectionSparkline,
  });

  factory DashboardStatsModel.fromJson(dynamic value) {
    final json = adminMap(value);
    final active = adminInt(
      json['active_subscribers_count'] ?? json['subscribers_count'],
    );
    return DashboardStatsModel(
      activeSubscribersCount: active,
      totalSubscribersCount: adminInt(
        json['total_subscribers_count'] ?? json['subscribers_count'],
        active,
      ),
      outstandingAmount: adminDouble(
        json['outstanding_amount'] ?? json['total_unpaid_amount'],
      ),
      monthlyCollection: adminDouble(
        json['monthly_collection'] ?? json['collected_this_month'],
      ),
      overdueInvoicesCount: adminInt(json['overdue_invoices_count']),
      unpaidInvoicesCount: adminInt(json['unpaid_invoices_count']),
      readingsThisMonth: adminInt(json['readings_this_month']),
      subscribersSparkline: _doubles(
        json['subscribers_sparkline'],
        const [7, 3, 4, 5, 6, 3, 5],
      ),
      collectionSparkline: _doubles(
        json['collection_sparkline'],
        const [3, 5, 7, 4, 6, 8, 5],
      ),
    );
  }

  static List<double> _doubles(dynamic value, List<double> fallback) {
    if (value is! List || value.isEmpty) return List.unmodifiable(fallback);
    return List.unmodifiable(value.map(adminDouble));
  }

  Map<String, dynamic> toJson() => {
        'active_subscribers_count': activeSubscribersCount,
        'total_subscribers_count': totalSubscribersCount,
        'outstanding_amount': outstandingAmount,
        'monthly_collection': monthlyCollection,
        'overdue_invoices_count': overdueInvoicesCount,
        'unpaid_invoices_count': unpaidInvoicesCount,
        'readings_this_month': readingsThisMonth,
        'subscribers_sparkline': subscribersSparkline,
        'collection_sparkline': collectionSparkline,
      };
}

class MonthlyRevenueModel extends MonthlyRevenue {
  const MonthlyRevenueModel({required super.labels, required super.values});

  factory MonthlyRevenueModel.fromJson(dynamic value) {
    if (value is List) {
      return MonthlyRevenueModel(
        labels: value.map((item) => adminString(adminMap(item)['label'])).toList(),
        values: value.map((item) => adminDouble(adminMap(item)['value'])).toList(),
      );
    }
    final json = adminMap(value);
    final labels = json['labels'] is List
        ? (json['labels'] as List).map(adminString).toList()
        : <String>[];
    final rawValues = json['data'] ?? json['values'];
    final values = rawValues is List
        ? rawValues.map(adminDouble).toList()
        : <double>[];
    return MonthlyRevenueModel(labels: labels, values: values);
  }

  Map<String, dynamic> toJson() => {'labels': labels, 'data': values};
}

class DashboardDataModel extends DashboardData {
  const DashboardDataModel({
    required super.stats,
    required super.monthlyRevenue,
    required super.latestInvoices,
    required super.overdueInvoices,
  });

  factory DashboardDataModel.fromJson(dynamic value) {
    final json = adminDataMap(value);
    final stats = json['stats_overview'] == null
        ? json
        : adminMap(json['stats_overview']);
    return DashboardDataModel(
      stats: DashboardStatsModel.fromJson(stats),
      monthlyRevenue: MonthlyRevenueModel.fromJson(
        json['monthly_revenue_chart'] ?? json['monthly_revenue'],
      ),
      latestInvoices: adminList(
        json['latest_invoices'],
      ).map(DashboardInvoiceModel.fromJson).toList(growable: false),
      overdueInvoices: adminList(
        json['overdue_invoices'],
      ).map(DashboardInvoiceModel.fromJson).toList(growable: false),
    );
  }

  Map<String, dynamic> toJson() => {
        'stats_overview': (stats as DashboardStatsModel).toJson(),
        'monthly_revenue_chart': (monthlyRevenue as MonthlyRevenueModel).toJson(),
        'latest_invoices': latestInvoices
            .map((invoice) => (invoice as DashboardInvoiceModel).toJson())
            .toList(),
        'overdue_invoices': overdueInvoices
            .map((invoice) => (invoice as DashboardInvoiceModel).toJson())
            .toList(),
      };
}
