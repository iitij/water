import 'package:equatable/equatable.dart';

import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/accounting/invoice_accounting.dart';

/// حالة شاشة المحصل الميداني السريع (`SCR-13 — QuickCollectorScreen`).
class QuickCollectorState extends Equatable {
  const QuickCollectorState({
    this.searchText = '',
    this.selectedSquareId,
    this.squares = const <SquareOption>[],
    this.invoices = const <InvoiceModel>[],
    this.loading = false,
    this.loadingSquares = false,
    this.errorMessage,
    this.lastCollectedBillNumber,
    this.paidIds = const <int>{},
  });

  final String searchText;
  final int? selectedSquareId;
  final List<SquareOption> squares;
  final List<InvoiceModel> invoices;
  final bool loading;
  final bool loadingSquares;
  final String? errorMessage;

  /// آخر فاتورة تم تحصيلها لإبرازها في «الانتقال للتالي» (قاعدة النقرتين §3.4-2).
  final String? lastCollectedBillNumber;

  /// فواتير أُغلقت محلياً بعد التحصيل حتى لا يومض العميل قبل التحديث من الخادم.
  final Set<int> paidIds;

  int get openCount => invoices.where((i) => i.status.isOpenForCollection).length;

  double get totalDue => invoices.fold<double>(
        0,
        (sum, invoice) =>
            sum + (invoice.status.isOpenForCollection ? invoice.netPayable : 0),
      );

  double get overdueDue => invoices
      .where((i) => i.status.wire == 'overdue')
      .fold<double>(0, (sum, i) => sum + i.netPayable);

  /// النتائج مرتبة: مطابقة المسار أولاً ثم الأقدم استحقاقاً.
  List<InvoiceModel> get visibleInvoices {
    final list = invoices.where((i) => !paidIds.contains(i.id)).toList();
    list.sort((a, b) {
      if (a.isPathMatch != b.isPathMatch) return a.isPathMatch ? -1 : 1;
      final aOpen = a.status.isOpenForCollection;
      final bOpen = b.status.isOpenForCollection;
      if (aOpen != bOpen) return aOpen ? -1 : 1;
      return InvoiceAccounting.totalDue(b).compareTo(InvoiceAccounting.totalDue(a));
    });
    return list;
  }

  bool get isCompoundSearch =>
      RegExp(r'^\d+[/\-\\_]\d+$').hasMatch(searchText.trim());

  QuickCollectorState copyWith({
    String? searchText,
    int? selectedSquareId,
    bool clearSquare = false,
    List<SquareOption>? squares,
    List<InvoiceModel>? invoices,
    bool? loading,
    bool? loadingSquares,
    String? errorMessage,
    bool clearError = false,
    String? lastCollectedBillNumber,
    Set<int>? paidIds,
  }) =>
      QuickCollectorState(
        searchText: searchText ?? this.searchText,
        selectedSquareId:
            clearSquare ? null : (selectedSquareId ?? this.selectedSquareId),
        squares: squares ?? this.squares,
        invoices: invoices ?? this.invoices,
        loading: loading ?? this.loading,
        loadingSquares: loadingSquares ?? this.loadingSquares,
        errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
        lastCollectedBillNumber:
            lastCollectedBillNumber ?? this.lastCollectedBillNumber,
        paidIds: paidIds ?? this.paidIds,
      );

  @override
  List<Object?> get props => <Object?>[
        searchText,
        selectedSquareId,
        squares,
        invoices,
        loading,
        loadingSquares,
        errorMessage,
        lastCollectedBillNumber,
        paidIds,
      ];
}
