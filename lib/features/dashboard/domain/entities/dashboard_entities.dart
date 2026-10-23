import 'package:equatable/equatable.dart';

class DashboardInvoice extends Equatable {
  const DashboardInvoice({
    required this.id,
    required this.billNumber,
    required this.subscriberName,
    required this.billDate,
    required this.totalAmount,
    required this.previousBalance,
    required this.creditApplied,
    required this.amountPaid,
    required this.status,
    this.subscriberId,
    this.meterId,
    this.tenantName,
    this.subscriberPhone,
    this.trueRemainingAmount,
  });

  final int id;
  final String billNumber;
  final int? subscriberId;
  final int? meterId;
  final String subscriberName;
  final String? tenantName;
  final String? subscriberPhone;
  final DateTime? billDate;
  final double totalAmount;
  final double previousBalance;
  final double creditApplied;
  final double amountPaid;
  final String status;
  final double? trueRemainingAmount;

  /// Net due without counting previous arrears twice.
  double get remainingAmount {
    if (trueRemainingAmount != null) return trueRemainingAmount!;
    return (totalAmount + previousBalance - creditApplied - amountPaid)
        .clamp(0, double.infinity)
        .toDouble();
  }

  String get statusLabel => switch (status) {
        'draft' => 'مسودة',
        'issued' => 'صادرة',
        'partially_paid' => 'مسددة جزئياً',
        'paid' => 'مسددة',
        'overdue' => 'متأخرة',
        _ => status.isEmpty ? 'غير محدد' : status,
      };

  @override
  List<Object?> get props => [
        id,
        billNumber,
        subscriberId,
        meterId,
        subscriberName,
        tenantName,
        subscriberPhone,
        billDate,
        totalAmount,
        previousBalance,
        creditApplied,
        amountPaid,
        status,
        trueRemainingAmount,
      ];
}

class DashboardStats extends Equatable {
  const DashboardStats({
    required this.activeSubscribersCount,
    required this.totalSubscribersCount,
    required this.outstandingAmount,
    required this.monthlyCollection,
    required this.overdueInvoicesCount,
    required this.unpaidInvoicesCount,
    required this.readingsThisMonth,
    required this.subscribersSparkline,
    required this.collectionSparkline,
  });

  final int activeSubscribersCount;
  final int totalSubscribersCount;
  final double outstandingAmount;
  final double monthlyCollection;
  final int overdueInvoicesCount;
  final int unpaidInvoicesCount;
  final int readingsThisMonth;
  final List<double> subscribersSparkline;
  final List<double> collectionSparkline;

  @override
  List<Object> get props => [
        activeSubscribersCount,
        totalSubscribersCount,
        outstandingAmount,
        monthlyCollection,
        overdueInvoicesCount,
        unpaidInvoicesCount,
        readingsThisMonth,
        subscribersSparkline,
        collectionSparkline,
      ];
}

class MonthlyRevenue extends Equatable {
  const MonthlyRevenue({required this.labels, required this.values});

  final List<String> labels;
  final List<double> values;

  int get length => labels.length < values.length ? labels.length : values.length;

  @override
  List<Object> get props => [labels, values];
}

class DashboardData extends Equatable {
  const DashboardData({
    required this.stats,
    required this.monthlyRevenue,
    required this.latestInvoices,
    required this.overdueInvoices,
  });

  final DashboardStats stats;
  final MonthlyRevenue monthlyRevenue;
  final List<DashboardInvoice> latestInvoices;
  final List<DashboardInvoice> overdueInvoices;

  @override
  List<Object> get props => [stats, monthlyRevenue, latestInvoices, overdueInvoices];
}

class DashboardSnapshot extends Equatable {
  const DashboardSnapshot({
    required this.data,
    required this.updatedAt,
    required this.isFromCache,
  });

  final DashboardData data;
  final DateTime updatedAt;
  final bool isFromCache;

  @override
  List<Object> get props => [data, updatedAt, isFromCache];
}

class ReportFilter extends Equatable {
  const ReportFilter({this.from, this.to});

  final DateTime? from;
  final DateTime? to;

  bool get isValid => from == null || to == null || !from!.isAfter(to!);

  @override
  List<Object?> get props => [from, to];
}

class ReportPoint extends Equatable {
  const ReportPoint({required this.label, required this.value});

  final String label;
  final double value;

  @override
  List<Object> get props => [label, value];
}

class ReportBreakdown extends Equatable {
  const ReportBreakdown({
    required this.key,
    required this.label,
    required this.value,
    this.count = 0,
  });

  final String key;
  final String label;
  final double value;
  final int count;

  @override
  List<Object> get props => [key, label, value, count];
}

class FinancialReport extends Equatable {
  const FinancialReport({
    required this.totalInvoiced,
    required this.totalCollected,
    required this.totalOutstanding,
    required this.overdueAmount,
    required this.invoicesCount,
    required this.paidInvoicesCount,
    required this.overdueInvoicesCount,
    required this.revenueSeries,
    required this.statusBreakdown,
    required this.squareBreakdown,
  });

  final double totalInvoiced;
  final double totalCollected;
  final double totalOutstanding;
  final double overdueAmount;
  final int invoicesCount;
  final int paidInvoicesCount;
  final int overdueInvoicesCount;
  final List<ReportPoint> revenueSeries;
  final List<ReportBreakdown> statusBreakdown;
  final List<ReportBreakdown> squareBreakdown;

  double get collectionRate => totalInvoiced <= 0
      ? 0
      : (totalCollected / totalInvoiced * 100).clamp(0, 100).toDouble();

  @override
  List<Object> get props => [
        totalInvoiced,
        totalCollected,
        totalOutstanding,
        overdueAmount,
        invoicesCount,
        paidInvoicesCount,
        overdueInvoicesCount,
        revenueSeries,
        statusBreakdown,
        squareBreakdown,
      ];
}

class ReportSnapshot extends Equatable {
  const ReportSnapshot({
    required this.report,
    required this.updatedAt,
    required this.isFromCache,
  });

  final FinancialReport report;
  final DateTime updatedAt;
  final bool isFromCache;

  @override
  List<Object> get props => [report, updatedAt, isFromCache];
}
