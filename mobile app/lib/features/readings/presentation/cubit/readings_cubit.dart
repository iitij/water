import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../data/models/reading.dart';
import '../../domain/readings_repository.dart';

enum ReadingsLoadStatus { initial, loading, success, failure }
enum ReadingInvoiceFilter { all, invoiced, notInvoiced }

class ReadingsState {
  const ReadingsState({
    this.status = ReadingsLoadStatus.initial,
    this.readings = const [],
    this.squares = const [],
    this.invoiceFilter = ReadingInvoiceFilter.all,
    this.squareFilter,
    this.fromDate,
    this.toDate,
    this.query = '',
    this.selectedKeys = const <String>{},
    this.errorMessage,
    this.actionMessage,
    this.isMutating = false,
  });

  final ReadingsLoadStatus status;
  final List<Reading> readings;
  final List<SquareOption> squares;
  final ReadingInvoiceFilter invoiceFilter;
  final int? squareFilter;
  final DateTime? fromDate;
  final DateTime? toDate;
  final String query;
  final Set<String> selectedKeys;
  final String? errorMessage;
  final String? actionMessage;
  final bool isMutating;

  bool get isSelecting => selectedKeys.isNotEmpty;

  ReadingsState copyWith({
    ReadingsLoadStatus? status,
    List<Reading>? readings,
    List<SquareOption>? squares,
    ReadingInvoiceFilter? invoiceFilter,
    int? squareFilter,
    DateTime? fromDate,
    DateTime? toDate,
    String? query,
    Set<String>? selectedKeys,
    String? errorMessage,
    String? actionMessage,
    bool clearSquareFilter = false,
    bool clearFromDate = false,
    bool clearToDate = false,
    bool clearError = false,
    bool clearActionMessage = false,
    bool? isMutating,
  }) {
    return ReadingsState(
      status: status ?? this.status,
      readings: readings ?? this.readings,
      squares: squares ?? this.squares,
      invoiceFilter: invoiceFilter ?? this.invoiceFilter,
      squareFilter: clearSquareFilter ? null : squareFilter ?? this.squareFilter,
      fromDate: clearFromDate ? null : fromDate ?? this.fromDate,
      toDate: clearToDate ? null : toDate ?? this.toDate,
      query: query ?? this.query,
      selectedKeys: selectedKeys ?? this.selectedKeys,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
      actionMessage: clearActionMessage ? null : actionMessage ?? this.actionMessage,
      isMutating: isMutating ?? this.isMutating,
    );
  }
}

class ReadingsCubit extends Cubit<ReadingsState> {
  ReadingsCubit({
    required ReadingsRepository readingsRepository,
    required MetersRepository metersRepository,
  })  : _readingsRepository = readingsRepository,
        _metersRepository = metersRepository,
        super(const ReadingsState());

  final ReadingsRepository _readingsRepository;
  final MetersRepository _metersRepository;
  Timer? _searchTimer;

  Future<void> load({bool keepSelection = false}) async {
    emit(state.copyWith(
      status: ReadingsLoadStatus.loading,
      clearError: true,
      selectedKeys: keepSelection ? state.selectedKeys : <String>{},
    ));
    try {
      final bool? isInvoiced = switch (state.invoiceFilter) {
        ReadingInvoiceFilter.all => null,
        ReadingInvoiceFilter.invoiced => true,
        ReadingInvoiceFilter.notInvoiced => false,
      };
      final results = await Future.wait<Object>([
        _readingsRepository.getReadings(
          isInvoiced: isInvoiced,
          squareId: state.squareFilter,
          fromDate: state.fromDate,
          toDate: state.toDate,
          query: state.query,
        ),
        _metersRepository.getSquares(),
      ]);
      emit(state.copyWith(
        status: ReadingsLoadStatus.success,
        readings: results[0] as List<Reading>,
        squares: results[1] as List<SquareOption>,
      ));
    } catch (error) {
      emit(state.copyWith(
        status: state.readings.isEmpty ? ReadingsLoadStatus.failure : ReadingsLoadStatus.success,
        errorMessage: _messageFor(error),
      ));
    }
  }

  void setQuery(String query) {
    emit(state.copyWith(query: query));
    _searchTimer?.cancel();
    _searchTimer = Timer(const Duration(milliseconds: 300), load);
  }

  void setInvoiceFilter(ReadingInvoiceFilter filter) {
    emit(state.copyWith(invoiceFilter: filter));
    load();
  }

  void setSquareFilter(int? squareId) {
    emit(state.copyWith(
      squareFilter: squareId,
      clearSquareFilter: squareId == null,
    ));
    load();
  }

  void setDateRange({DateTime? fromDate, DateTime? toDate}) {
    emit(state.copyWith(
      fromDate: fromDate,
      toDate: toDate,
      clearFromDate: fromDate == null,
      clearToDate: toDate == null,
    ));
    load();
  }

  void setFromDate(DateTime? value) {
    emit(state.copyWith(fromDate: value, clearFromDate: value == null));
    load();
  }

  void setToDate(DateTime? value) {
    emit(state.copyWith(toDate: value, clearToDate: value == null));
    load();
  }

  void clearDateFilters() {
    emit(state.copyWith(clearFromDate: true, clearToDate: true));
    load();
  }

  void toggleSelection(Reading reading) {
    final next = Set<String>.from(state.selectedKeys);
    if (!next.add(reading.selectionKey)) next.remove(reading.selectionKey);
    emit(state.copyWith(selectedKeys: next));
  }

  void selectAllVisible() {
    final next = <String>{...state.selectedKeys};
    for (final reading in state.readings) {
      next.add(reading.selectionKey);
    }
    emit(state.copyWith(selectedKeys: next));
  }

  void clearSelection() => emit(state.copyWith(selectedKeys: <String>{}));

  List<Reading> get selectedReadings => state.readings
      .where((reading) => state.selectedKeys.contains(reading.selectionKey))
      .toList(growable: false);

  Future<BulkOperationResult?> issueSelectedInvoices() async {
    final selected = selectedReadings;
    if (selected.isEmpty) return null;
    emit(state.copyWith(isMutating: true, clearError: true, clearActionMessage: true));
    try {
      final result = await _readingsRepository.bulkIssueInvoices(selected);
      emit(state.copyWith(
        isMutating: false,
        actionMessage: result.message ?? 'تم إصدار ${result.succeededCount} فاتورة.',
        selectedKeys: <String>{},
      ));
      await load();
      return result;
    } catch (error) {
      emit(state.copyWith(isMutating: false, errorMessage: _messageFor(error)));
      return null;
    }
  }

  Future<BulkOperationResult?> deleteSelectedReadings() async {
    final selected = selectedReadings;
    if (selected.isEmpty) return null;
    emit(state.copyWith(isMutating: true, clearError: true, clearActionMessage: true));
    try {
      final result = await _readingsRepository.bulkDeleteReadings(selected);
      emit(state.copyWith(
        isMutating: false,
        actionMessage: result.message ?? 'تم حذف ${result.succeededCount} قراءة.',
        selectedKeys: <String>{},
      ));
      await load();
      return result;
    } catch (error) {
      emit(state.copyWith(isMutating: false, errorMessage: _messageFor(error)));
      return null;
    }
  }

  Future<bool> deleteReading(Reading reading) async {
    if (reading.isInvoiced) return false;
    emit(state.copyWith(isMutating: true, clearError: true));
    try {
      await _readingsRepository.deleteReading(reading);
      emit(state.copyWith(isMutating: false));
      await load(keepSelection: true);
      return true;
    } catch (error) {
      emit(state.copyWith(isMutating: false, errorMessage: _messageFor(error)));
      return false;
    }
  }

  Future<bool> updateReading(
    Reading reading,
    double currentReading, {
    DateTime? newReadingDate,
  }) async {
    if (reading.isInvoiced) return false;
    emit(state.copyWith(isMutating: true, clearError: true));
    try {
      await _readingsRepository.updateReading(
        reading,
        currentReading,
        newReadingDate: newReadingDate,
      );
      emit(state.copyWith(isMutating: false));
      await load();
      return true;
    } catch (error) {
      emit(state.copyWith(isMutating: false, errorMessage: _messageFor(error)));
      return false;
    }
  }

  static String _messageFor(Object error) {
    if (error is StateError) return error.message.toString();
    return 'تعذر تنفيذ العملية. يرجى التحقق من الاتصال والمحاولة مجدداً.';
  }

  @override
  Future<void> close() {
    _searchTimer?.cancel();
    return super.close();
  }
}
