import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_search.dart';
import '../../data/models/invoice_model.dart';
import '../../data/models/invoice_query.dart';
import '../../data/models/invoices_page.dart';
import '../../domain/repositories/invoices_repository.dart';
import '../../domain/repositories/paginated_invoices_repository.dart';
import 'invoices_state.dart';

/// `InvoicesCubit` — جلب/فلترة/بحث + إجراءات جماعية على قائمة الفواتير (`SCR-14`).
///
/// الحسابات المالية ليست هنا: تُقرأ من الخادم، والمعاينة المحلية عبر
/// `InvoiceAccounting` (اختبارها في `test/accounting/`).
class InvoicesCubit extends Cubit<InvoicesState> {
  InvoicesCubit({
    required InvoicesRepository repository,
    InvoiceQuery initialQuery = const InvoiceQuery(),
  }) : _repository = repository,
       super(InvoicesState(query: initialQuery));

  final InvoicesRepository _repository;
  String? _lastSignature;
  int _loadGeneration = 0;

  Future<void> load({bool refresh = false}) async {
    final query = state.query;
    final signature = query.signature;
    if (!refresh && signature == _lastSignature && state.hasData) return;
    final generation = ++_loadGeneration;

    emit(
      state.copyWith(
        status: state.hasData
            ? FinanceLoadStatus.refreshing
            : FinanceLoadStatus.loading,
        clearError: true,
      ),
    );

    try {
      final repository = _repository;
      if (repository is PaginatedInvoicesRepository) {
        final results = await Future.wait<Object>([
          (repository as PaginatedInvoicesRepository).listPage(query),
          _repository.billingPeriods(),
        ]);
        if (isClosed || generation != _loadGeneration) return;
        final page = results[0] as InvoicesPage;
        if (page.invoices.isEmpty && query.page > page.lastPage) {
          emit(state.copyWith(query: query.copyWith(page: page.lastPage)));
          await load(refresh: true);
          return;
        }
        _lastSignature = signature;
        emit(
          state.copyWith(
            invoices: page.invoices,
            billingPeriods: results[1] as List<BillingPeriodOption>,
            query: query.copyWith(page: page.currentPage),
            lastPage: page.lastPage,
            totalCount: page.total,
            status: FinanceLoadStatus.ready,
            busyInvoiceId: null,
            selectedIds: const <int>{},
          ),
        );
        return;
      }
      final results = await Future.wait(<Future<Object>>[
        _repository.list(query),
        _repository.billingPeriods(),
      ]);
      if (isClosed || generation != _loadGeneration) return;

      final invoices = results[0] as List<InvoiceModel>;
      final periods = results[1] as List<BillingPeriodOption>;

      _lastSignature = signature;
      emit(
        state.copyWith(
          invoices: invoices,
          billingPeriods: periods,
          status: FinanceLoadStatus.ready,
          selectedIds: const <int>{},
        ),
      );
    } on AppFailure catch (failure) {
      if (isClosed || generation != _loadGeneration) return;
      emit(
        state.copyWith(
          status: FinanceLoadStatus.failure,
          errorMessage: failure.message,
        ),
      );
    } catch (error) {
      if (isClosed || generation != _loadGeneration) return;
      emit(
        state.copyWith(
          status: FinanceLoadStatus.failure,
          errorMessage: 'تعذّر جلب الفواتير: $error',
        ),
      );
    }
  }

  /// بحث فوري — البحث النصي لا يشمل مسارات `/unpaid` في الخادم، لذا نستعمل
  /// `/invoices/search` عندما يكون هناك نص بحث (سلوك `InvoiceController::search`).
  Future<void> search(String value) async {
    if (state.query.search == value) return;
    emit(
      state.copyWith(
        query: state.query.copyWith(search: value, page: 1),
        selectedIds: const <int>{},
      ),
    );
    if (_repository is PaginatedInvoicesRepository) {
      await load(refresh: true);
      return;
    }

    final parsed = FinanceSearchQuery.parse(value);
    final hasExtraFilters =
        state.query.trashed != 'without' ||
        state.query.invoiceType != null ||
        state.query.status != null ||
        state.query.subscriberId != null ||
        state.query.billingPeriod != null;
    if ((parsed.isEmpty && state.query.squareId == null) || hasExtraFilters) {
      await load(refresh: true);
      return;
    }

    emit(state.copyWith(status: FinanceLoadStatus.loading, clearError: true));
    try {
      final invoices = await _repository.collectorList(
        search: parsed,
        squareId: state.query.squareId,
      );
      emit(state.copyWith(invoices: invoices, status: FinanceLoadStatus.ready));
    } on AppFailure catch (failure) {
      emit(
        state.copyWith(
          status: FinanceLoadStatus.failure,
          errorMessage: failure.message,
        ),
      );
    }
  }

  Future<void> applyFilters(InvoiceQuery query) async {
    emit(
      state.copyWith(
        query: query.copyWith(page: 1),
        selectedIds: const <int>{},
      ),
    );
    await load(refresh: true);
  }

  Future<void> goToPage(int page) async {
    if (state.isLoading ||
        page < 1 ||
        page == state.query.page ||
        (state.lastPage != null && page > state.lastPage!)) {
      return;
    }
    emit(
      state.copyWith(
        query: state.query.copyWith(page: page),
        selectedIds: const <int>{},
      ),
    );
    await load(refresh: true);
  }

  // ─── التحديد الجماعي ───────────────────────────────────────────────────
  void toggleSelection(int id) {
    final selected = state.selectedIds.toSet();
    if (!selected.remove(id)) selected.add(id);
    emit(state.copyWith(selectedIds: selected));
  }

  void selectAll() => emit(
    state.copyWith(selectedIds: state.invoices.map((i) => i.id).toSet()),
  );

  void clearSelection() => emit(state.copyWith(selectedIds: const <int>{}));

  // ─── الإجراءات الفردية ──────────────────────────────────────────────────
  Future<InvoiceModel?> recalculate(int id) async {
    emit(state.copyWith(busyInvoiceId: id, clearError: true));
    try {
      final updated = await _repository.recalculate(id);
      final invoices = state.invoices
          .map((i) => i.id == updated.id ? updated : i)
          .toList(growable: false);
      emit(state.copyWith(invoices: invoices, busyInvoiceId: null));
      return updated;
    } on AppFailure catch (failure) {
      emit(state.copyWith(busyInvoiceId: null, errorMessage: failure.message));
      return null;
    }
  }

  Future<bool> deleteInvoice(int id) async {
    emit(state.copyWith(busyInvoiceId: id, clearError: true));
    try {
      await _repository.delete(id);
      emit(state.copyWith(busyInvoiceId: null));
      if (_repository is PaginatedInvoicesRepository) {
        await load(refresh: true);
        return true;
      }
      emit(
        state.copyWith(
          invoices: state.invoices.where((i) => i.id != id).toList(),
          selectedIds: state.selectedIds.where((s) => s != id).toSet(),
          busyInvoiceId: null,
        ),
      );
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(busyInvoiceId: null, errorMessage: failure.message));
      return false;
    }
  }

  Future<bool> restoreInvoice(int id) async {
    emit(state.copyWith(busyInvoiceId: id, clearError: true));
    try {
      await _repository.restore(id);
      emit(state.copyWith(busyInvoiceId: null));
      await load(refresh: true);
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(busyInvoiceId: null, errorMessage: failure.message));
      return false;
    }
  }

  Future<bool> forceDeleteInvoice(int id) async {
    emit(state.copyWith(busyInvoiceId: id, clearError: true));
    try {
      await _repository.forceDelete(id);
      emit(state.copyWith(busyInvoiceId: null));
      if (_repository is PaginatedInvoicesRepository) {
        await load(refresh: true);
        return true;
      }
      emit(
        state.copyWith(
          invoices: state.invoices.where((i) => i.id != id).toList(),
          selectedIds: state.selectedIds.where((s) => s != id).toSet(),
          busyInvoiceId: null,
        ),
      );
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(busyInvoiceId: null, errorMessage: failure.message));
      return false;
    }
  }

  // ─── الإجراءات الجماعية (`Bulk Actions`) ────────────────────────────────
  Future<BulkActionResult?> bulkRecalculate() async {
    if (state.selectedIds.isEmpty) return null;
    emit(state.copyWith(bulkRunning: true, clearError: true));
    try {
      final result = await _repository.bulkRecalculate(
        state.selectedIds.toList(),
      );
      await load(refresh: true);
      emit(state.copyWith(bulkRunning: false, selectedIds: const <int>{}));
      return result;
    } on AppFailure catch (failure) {
      emit(state.copyWith(bulkRunning: false, errorMessage: failure.message));
      return null;
    }
  }

  Future<BulkActionResult?> bulkDelete() async {
    if (state.selectedIds.isEmpty) return null;
    emit(state.copyWith(bulkRunning: true, clearError: true));
    try {
      final result = await _repository.bulkDelete(state.selectedIds.toList());
      await load(refresh: true);
      emit(state.copyWith(bulkRunning: false, selectedIds: const <int>{}));
      return result;
    } on AppFailure catch (failure) {
      emit(state.copyWith(bulkRunning: false, errorMessage: failure.message));
      return null;
    }
  }

  Future<BulkActionResult?> bulkRestore() async {
    if (state.selectedIds.isEmpty) return null;
    emit(state.copyWith(bulkRunning: true, clearError: true));
    try {
      final result = await _repository.bulkRestore(state.selectedIds.toList());
      await load(refresh: true);
      emit(state.copyWith(bulkRunning: false, selectedIds: const <int>{}));
      return result;
    } on AppFailure catch (failure) {
      emit(state.copyWith(bulkRunning: false, errorMessage: failure.message));
      return null;
    }
  }

  Future<BulkActionResult?> bulkForceDelete() async {
    if (state.selectedIds.isEmpty) return null;
    emit(state.copyWith(bulkRunning: true, clearError: true));
    try {
      final result = await _repository.bulkForceDelete(
        state.selectedIds.toList(),
      );
      await load(refresh: true);
      emit(state.copyWith(bulkRunning: false, selectedIds: const <int>{}));
      return result;
    } on AppFailure catch (failure) {
      emit(state.copyWith(bulkRunning: false, errorMessage: failure.message));
      return null;
    }
  }

  /// تحديث محلي بعد تحصيل دفعة (يُستدعى من `CollectPaymentSheet` عبر callback).
  void applyLocalInvoiceUpdate(InvoiceModel invoice) {
    final exists = state.invoices.any((i) => i.id == invoice.id);
    if (!exists) return;
    emit(
      state.copyWith(
        invoices: state.invoices
            .map((i) => i.id == invoice.id ? invoice : i)
            .toList(growable: false),
      ),
    );
  }
}
