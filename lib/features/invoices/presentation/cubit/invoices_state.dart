import 'package:equatable/equatable.dart';

import '../../data/models/invoice_model.dart';
import '../../data/models/invoice_query.dart';

enum FinanceLoadStatus { idle, loading, ready, refreshing, failure }

/// حالة قائمة الفواتير (`SCR-14`) والمحصل السريع (`SCR-13`).
class InvoicesState extends Equatable {
  const InvoicesState({
    this.query = const InvoiceQuery(),
    this.invoices = const <InvoiceModel>[],
    this.billingPeriods = const <BillingPeriodOption>[],
    this.status = FinanceLoadStatus.idle,
    this.errorMessage,
    this.selectedIds = const <int>{},
    this.busyInvoiceId,
    this.bulkRunning = false,
  });

  final InvoiceQuery query;
  final List<InvoiceModel> invoices;
  final List<BillingPeriodOption> billingPeriods;
  final FinanceLoadStatus status;
  final String? errorMessage;

  /// تحديد جماعي لإجراءات `bulk-recalculate` و`bulk-delete`.
  final Set<int> selectedIds;

  /// الفاتورة التي يُنفَّذ عليها إجراء فردي (إعادة احتساب/حذف) لتعطيل أزرارها.
  final int? busyInvoiceId;
  final bool bulkRunning;

  bool get isLoading =>
      status == FinanceLoadStatus.loading || status == FinanceLoadStatus.refreshing;
  bool get hasData => invoices.isNotEmpty;
  bool get isEmpty => !isLoading && invoices.isEmpty && errorMessage == null;
  bool get isSelectingMode => selectedIds.isNotEmpty;

  /// إجمالي المستحق المعروض (`Σ total_amount + previous_balance`).
  double get totalDueSum => invoices.fold<double>(
        0,
        (sum, i) => sum + i.totalDue,
      );

  /// إجمالي المتبقي الفعلي (يُحسب تراكمياً لكل فاتورة على حدة لتفادي المضاعفة).
  double get openRemainingSum {
    var total = 0.0;
    for (final invoice in invoices) {
      if (!invoice.status.isOpenForCollection) continue;
      total += invoice.ownRemaining;
    }
    return total;
  }

  int get openCount =>
      invoices.where((i) => i.status.isOpenForCollection).length;

  int get overdueCount => invoices.where((i) => i.status.wire == 'overdue').length;

  /// يُمكّن الفلاتر من معرفة الفترات المتاحة بدون شبكة زائدة.
  List<InvoiceModel> invoicesOf(int subscriberId) =>
      invoices.where((i) => i.subscriberId == subscriberId).toList();

  InvoicesState copyWith({
    InvoiceQuery? query,
    List<InvoiceModel>? invoices,
    List<BillingPeriodOption>? billingPeriods,
    FinanceLoadStatus? status,
    String? errorMessage,
    bool clearError = false,
    Set<int>? selectedIds,
    int? busyInvoiceId,
    bool? bulkRunning,
  }) =>
      InvoicesState(
        query: query ?? this.query,
        invoices: invoices ?? this.invoices,
        billingPeriods: billingPeriods ?? this.billingPeriods,
        status: status ?? this.status,
        errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
        selectedIds: selectedIds ?? this.selectedIds,
        busyInvoiceId: busyInvoiceId ?? this.busyInvoiceId,
        bulkRunning: bulkRunning ?? this.bulkRunning,
      );

  @override
  List<Object?> get props => <Object?>[
        query,
        invoices,
        billingPeriods,
        status,
        errorMessage,
        selectedIds,
        busyInvoiceId,
        bulkRunning,
      ];
}
