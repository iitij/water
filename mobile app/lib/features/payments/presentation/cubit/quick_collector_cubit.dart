import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_search.dart';
import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import 'quick_collector_state.dart';

/// منطق `SCR-13` — بحث فوري (اسم مشترك/رقم فاتورة/مسار `4/1`) + فلتر المربع،
/// وتحصيل بلمسة واحدة عبر `CollectPaymentSheet`.
class QuickCollectorCubit extends Cubit<QuickCollectorState> {
  QuickCollectorCubit({
    required InvoicesRepository invoicesRepository,
    required ReferencesRemoteDataSource references,
  })  : _invoices = invoicesRepository,
        _references = references,
        super(const QuickCollectorState());

  /// زمن تهدئة البحث الميداني: أقصر من الكيوبتات الأخرى لأن الشبكة في الحارات ضعيفة
  /// لكن المحصل يحتاج ردّاً فورياً (قاعدة §3.4-2).
  static const Duration _debounce = Duration(milliseconds: 300);

  final InvoicesRepository _invoices;
  final ReferencesRemoteDataSource _references;

  int _requestSequence = 0;

  Future<void> bootstrap() async {
    emit(state.copyWith(loadingSquares: true));
    try {
      final squares = await _references.squares();
      emit(state.copyWith(squares: squares, loadingSquares: false));
    } catch (error) {
      emit(state.copyWith(loadingSquares: false, errorMessage: '$error'));
    }
    await loadUnpaid();
  }

  /// قائمة الفواتير غير المسددة عند فتح الشاشة (بدون بحث).
  Future<void> loadUnpaid() async {
    final sequence = ++_requestSequence;
    emit(state.copyWith(loading: true, clearError: true));
    try {
      final invoices = await _invoices.collectorList();
      if (sequence != _requestSequence) return;
      emit(state.copyWith(invoices: invoices, loading: false));
    } on AppFailure catch (failure) {
      if (sequence != _requestSequence) return;
      emit(state.copyWith(loading: false, errorMessage: failure.message));
    }
  }

  Future<void> onSearchChanged(String value) async {
    emit(state.copyWith(searchText: value));
    await Future<void>.delayed(_debounce);
    await searchNow();
  }

  Future<void> searchNow() async {
    final parsed = FinanceSearchQuery.parse(state.searchText);
    if (parsed.isEmpty && state.selectedSquareId == null) {
      await loadUnpaid();
      return;
    }

    final sequence = ++_requestSequence;
    emit(state.copyWith(loading: true, clearError: true));
    try {
      final invoices = await _invoices.collectorList(
        search: parsed,
        squareId: state.selectedSquareId,
      );
      if (sequence != _requestSequence) return;
      emit(state.copyWith(invoices: invoices, loading: false));
    } on AppFailure catch (failure) {
      if (sequence != _requestSequence) return;
      emit(state.copyWith(loading: false, errorMessage: failure.message));
    }
  }

  Future<void> selectSquare(int? squareId) async {
    if (squareId == null) {
      emit(state.copyWith(clearSquare: true));
    } else {
      emit(state.copyWith(selectedSquareId: squareId));
    }
    await searchNow();
  }

  void clearSearch() {
    emit(state.copyWith(searchText: ''));
    loadUnpaid();
  }

  /// بعد نجاح التحصيل: نعلّم الفاتورة كمسدّدة محلياً ونعيد الجلب في الخلفية.
  void markCollected(InvoiceModel invoice) {
    emit(state.copyWith(
      paidIds: state.paidIds.union(<int>{invoice.id}),
      lastCollectedBillNumber: invoice.billNumber,
    ));
    refreshQuietly();
  }

  Future<void> refreshQuietly() async {
    try {
      final invoices = await _invoices.collectorList();
      emit(state.copyWith(invoices: invoices));
    } on AppFailure {
      // التحديث الصامت لا يعرض خطأ: القائمة الحالية تبقى صالحة للعرض.
    }
  }
}
