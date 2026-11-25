import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_search.dart';
import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/repositories/invoices_repository.dart';
import 'quick_collector_state.dart';

/// Field collection list. Every accepted response belongs to the current filters.
class QuickCollectorCubit extends Cubit<QuickCollectorState> {
  QuickCollectorCubit({
    required InvoicesRepository invoicesRepository,
    required ReferencesRemoteDataSource references,
  })  : _invoices = invoicesRepository,
        _references = references,
        super(const QuickCollectorState());

  static const Duration _debounce = Duration(milliseconds: 300);
  final InvoicesRepository _invoices;
  final ReferencesRemoteDataSource _references;

  int _requestSequence = 0;
  // A queued offline receipt is not authoritative server settlement. Do not
  // re-enable collection merely because a cached/server list still shows debt.
  final Set<int> _queuedOfflineIds = <int>{};

  Future<void> bootstrap() async {
    if (isClosed) return;
    emit(state.copyWith(loadingSquares: true));
    try {
      final squares = await _references.squares();
      if (isClosed) return;
      emit(state.copyWith(squares: squares, loadingSquares: false));
    } catch (error) {
      if (isClosed) return;
      emit(state.copyWith(loadingSquares: false, errorMessage: '$error'));
    }
    await loadUnpaid();
  }

  Future<void> loadUnpaid() => _load();
  Future<void> searchNow() => _load();

  Future<void> onSearchChanged(String value) async {
    if (isClosed) return;
    final sequence = ++_requestSequence;
    // Invalidate the old response before the debounce starts.
    emit(state.copyWith(searchText: value, invoices: const [], loading: true, clearError: true));
    await Future<void>.delayed(_debounce);
    if (isClosed || sequence != _requestSequence) return;
    await _load();
  }

  Future<void> _load({bool quiet = false}) async {
    if (isClosed) return;
    final sequence = ++_requestSequence;
    final parsed = FinanceSearchQuery.parse(state.searchText);
    final square = state.selectedSquareId;
    if (!quiet) emit(state.copyWith(loading: true, clearError: true));
    try {
      final invoices = parsed.isEmpty && square == null
          ? await _invoices.collectorList()
          : await _invoices.collectorList(search: parsed, squareId: square);
      if (isClosed || sequence != _requestSequence) return;
      // A successful server refresh can show a committed partial payment again.
      // Keep queued-offline suppressions until an independently verified sync.
      emit(state.copyWith(
        invoices: invoices,
        loading: false,
        paidIds: Set<int>.of(_queuedOfflineIds),
        clearError: true,
      ));
    } catch (error) {
      if (isClosed || sequence != _requestSequence) return;
      if (quiet && !state.loading) return;
      emit(state.copyWith(
        loading: false,
        errorMessage: error is AppFailure ? error.message : '$error',
      ));
    }
  }

  Future<void> selectSquare(int? squareId) async {
    if (isClosed) return;
    ++_requestSequence;
    emit(state.copyWith(
      selectedSquareId: squareId,
      clearSquare: squareId == null,
      invoices: const [],
      loading: true,
    ));
    await _load();
  }

  void clearSearch() {
    if (isClosed) return;
    ++_requestSequence;
    emit(state.copyWith(searchText: '', invoices: const [], loading: true));
    unawaited(_load());
  }

  void markCollected(InvoiceModel invoice, {bool queuedOffline = false}) {
    if (isClosed) return;
    if (queuedOffline) _queuedOfflineIds.add(invoice.id);
    emit(state.copyWith(
      paidIds: state.paidIds.union(<int>{invoice.id}),
      lastCollectedBillNumber: invoice.billNumber,
    ));
    unawaited(refreshQuietly());
  }

  Future<void> refreshQuietly() => _load(quiet: true);
}
