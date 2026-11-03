import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../meters/data/models/meter.dart';
import '../../../meters/domain/meters_repository.dart';
import '../../data/models/reading.dart';
import '../../data/reading_filter_preset_store.dart';
import '../../domain/reading_filter_preset.dart';
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
    this.pathFilter,
    this.fromDate,
    this.toDate,
    this.query = '',
    this.selectedKeys = const <String>{},
    this.errorMessage,
    this.actionMessage,
    this.isMutating = false,
    this.presets = const <ReadingFilterPreset>[],
  });

  final ReadingsLoadStatus status;
  final List<Reading> readings;
  final List<SquareOption> squares;
  final ReadingInvoiceFilter invoiceFilter;
  final int? squareFilter;
  final int? pathFilter;
  final DateTime? fromDate;
  final DateTime? toDate;
  final String query;
  final Set<String> selectedKeys;
  final String? errorMessage;
  final String? actionMessage;
  final bool isMutating;

  /// فلاتر محفوظة على الجهاز (S7-06).
  final List<ReadingFilterPreset> presets;

  bool get isSelecting => selectedKeys.isNotEmpty;

  bool get hasFilters =>
      query.trim().isNotEmpty ||
      squareFilter != null ||
      pathFilter != null ||
      fromDate != null ||
      toDate != null ||
      invoiceFilter != ReadingInvoiceFilter.all;

  ReadingsState copyWith({
    ReadingsLoadStatus? status,
    List<Reading>? readings,
    List<SquareOption>? squares,
    ReadingInvoiceFilter? invoiceFilter,
    int? squareFilter,
    int? pathFilter,
    DateTime? fromDate,
    DateTime? toDate,
    String? query,
    Set<String>? selectedKeys,
    String? errorMessage,
    String? actionMessage,
    bool clearSquareFilter = false,
    bool clearPathFilter = false,
    bool clearFromDate = false,
    bool clearToDate = false,
    bool clearError = false,
    bool clearActionMessage = false,
    bool? isMutating,
    List<ReadingFilterPreset>? presets,
  }) {
    return ReadingsState(
      status: status ?? this.status,
      readings: readings ?? this.readings,
      squares: squares ?? this.squares,
      invoiceFilter: invoiceFilter ?? this.invoiceFilter,
      squareFilter: clearSquareFilter
          ? null
          : squareFilter ?? this.squareFilter,
      pathFilter: clearPathFilter ? null : pathFilter ?? this.pathFilter,
      fromDate: clearFromDate ? null : fromDate ?? this.fromDate,
      toDate: clearToDate ? null : toDate ?? this.toDate,
      query: query ?? this.query,
      selectedKeys: selectedKeys ?? this.selectedKeys,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
      actionMessage: clearActionMessage
          ? null
          : actionMessage ?? this.actionMessage,
      isMutating: isMutating ?? this.isMutating,
      presets: presets ?? this.presets,
    );
  }
}

class ReadingsCubit extends Cubit<ReadingsState> {
  ReadingsCubit({
    required ReadingsRepository readingsRepository,
    required MetersRepository metersRepository,
    ReadingFilterPresetStore? presetStore,
  }) : _readingsRepository = readingsRepository,
       _metersRepository = metersRepository,
       _presets = presetStore ?? SharedPreferencesReadingFilterPresetStore(),
       super(const ReadingsState());

  final ReadingsRepository _readingsRepository;
  final MetersRepository _metersRepository;
  final ReadingFilterPresetStore _presets;

  /// تحميل الفلاتر المحفوظة — يُستدعى مرة عند فتح الشاشة.
  Future<void> loadPresets() async {
    try {
      final presets = await _presets.load();
      emit(state.copyWith(presets: presets));
    } catch (_) {
      // فشل التخزين المحلي لا يجوز أن يمنع عرض القراءات.
    }
  }

  /// اسم مقترح للفلتر الحالي — يُستخدم كقيمة أولية في مربع الحفظ.
  String suggestPresetName() => ReadingFilterPreset.suggestName(
    squareId: state.squareFilter,
    squareName: _squareNameOf(state.squareFilter),
    pathNumber: state.pathFilter,
    invoiceFilterCode: state.invoiceFilter.name,
    fromDate: state.fromDate,
    toDate: state.toDate,
    query: state.query,
  );

  String? _squareNameOf(int? id) {
    if (id == null) return null;
    for (final square in state.squares) {
      if (square.id == id) return square.displayName;
    }
    return null;
  }

  /// يحفظ الفلاتر الحالية باسم. يعيد `true` عند النجاح.
  ///
  /// حفظ اسم موجود يستبدل محتواه بدل تكرار الشريحة — القارئ يقصد «حدّث هذا الفلتر».
  Future<bool> savePreset(String name) async {
    final trimmed = name.trim();
    if (trimmed.isEmpty) return false;
    final preset = ReadingFilterPreset(
      id: trimmed,
      name: trimmed,
      squareId: state.squareFilter,
      squareName: _squareNameOf(state.squareFilter),
      pathNumber: state.pathFilter,
      invoiceFilterCode: state.invoiceFilter.name,
      fromDate: state.fromDate,
      toDate: state.toDate,
      query: state.query,
    );
    final next = <ReadingFilterPreset>[
      ...state.presets.where((item) => item.id != preset.id),
      preset,
    ];
    try {
      await _presets.save(next);
      emit(state.copyWith(presets: next));
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<void> deletePreset(String id) async {
    final next = state.presets
        .where((item) => item.id != id)
        .toList(growable: false);
    try {
      await _presets.save(next);
    } catch (_) {
      // نُحدّث الواجهة على أي حال؛ أسوأ حالة يعود الفلتر بعد إعادة التشغيل.
    }
    emit(state.copyWith(presets: next));
  }

  /// يطبّق فلتراً محفوظاً بضغطة واحدة ثم يعيد التحميل.
  Future<void> applyPreset(ReadingFilterPreset preset) async {
    _searchTimer?.cancel();
    final filter = switch (preset.invoiceFilterCode) {
      'invoiced' => ReadingInvoiceFilter.invoiced,
      'notInvoiced' => ReadingInvoiceFilter.notInvoiced,
      _ => ReadingInvoiceFilter.all,
    };
    emit(
      state.copyWith(
        query: preset.query,
        invoiceFilter: filter,
        squareFilter: preset.squareId,
        clearSquareFilter: preset.squareId == null,
        pathFilter: preset.pathNumber,
        clearPathFilter: preset.pathNumber == null,
        fromDate: preset.fromDate,
        toDate: preset.toDate,
        clearFromDate: preset.fromDate == null,
        clearToDate: preset.toDate == null,
      ),
    );
    await load();
  }

  Timer? _searchTimer;

  Future<void> load({bool keepSelection = false}) async {
    emit(
      state.copyWith(
        status: ReadingsLoadStatus.loading,
        clearError: true,
        selectedKeys: keepSelection ? state.selectedKeys : <String>{},
      ),
    );
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
      emit(
        state.copyWith(
          status: ReadingsLoadStatus.success,
          readings: (results[0] as List<Reading>)
              .where(
                (reading) =>
                    state.pathFilter == null ||
                    reading.pathNumber == state.pathFilter,
              )
              .toList(growable: false),
          squares: results[1] as List<SquareOption>,
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          status: state.readings.isEmpty
              ? ReadingsLoadStatus.failure
              : ReadingsLoadStatus.success,
          errorMessage: _messageFor(error),
        ),
      );
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
    emit(
      state.copyWith(
        squareFilter: squareId,
        clearSquareFilter: squareId == null,
      ),
    );
    load();
  }

  void setDateRange({DateTime? fromDate, DateTime? toDate}) {
    emit(
      state.copyWith(
        fromDate: fromDate,
        toDate: toDate,
        clearFromDate: fromDate == null,
        clearToDate: toDate == null,
      ),
    );
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

  /// Applies several facets in one shot so the list reloads once.
  void applyFilters({
    required int? squareId,
    required DateTime? fromDate,
    required DateTime? toDate,
    int? pathNumber,
    bool clearPath = false,
  }) {
    _searchTimer?.cancel();
    final unchanged =
        squareId == state.squareFilter &&
        fromDate == state.fromDate &&
        toDate == state.toDate &&
        (clearPath
            ? state.pathFilter == null
            : pathNumber == null || pathNumber == state.pathFilter);
    emit(
      state.copyWith(
        squareFilter: squareId,
        clearSquareFilter: squareId == null,
        pathFilter: pathNumber,
        clearPathFilter: clearPath,
        fromDate: fromDate,
        toDate: toDate,
        clearFromDate: fromDate == null,
        clearToDate: toDate == null,
      ),
    );
    if (!unchanged) load();
  }

  /// Clears every facet and the search text in one transition.
  void clearAllFilters() {
    _searchTimer?.cancel();
    emit(
      state.copyWith(
        query: '',
        invoiceFilter: ReadingInvoiceFilter.all,
        clearSquareFilter: true,
        clearPathFilter: true,
        clearFromDate: true,
        clearToDate: true,
      ),
    );
    load();
  }

  void setPathFilter(int? pathNumber) => applyFilters(
    squareId: state.squareFilter,
    fromDate: state.fromDate,
    toDate: state.toDate,
    pathNumber: pathNumber,
    clearPath: pathNumber == null,
  );

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
    emit(
      state.copyWith(
        isMutating: true,
        clearError: true,
        clearActionMessage: true,
      ),
    );
    try {
      final result = await _readingsRepository.bulkIssueInvoices(selected);
      emit(
        state.copyWith(
          isMutating: false,
          actionMessage:
              result.message ?? 'تم إصدار ${result.succeededCount} فاتورة.',
          selectedKeys: <String>{},
        ),
      );
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
    emit(
      state.copyWith(
        isMutating: true,
        clearError: true,
        clearActionMessage: true,
      ),
    );
    try {
      final result = await _readingsRepository.bulkDeleteReadings(selected);
      emit(
        state.copyWith(
          isMutating: false,
          actionMessage:
              result.message ?? 'تم حذف ${result.succeededCount} قراءة.',
          selectedKeys: <String>{},
        ),
      );
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
