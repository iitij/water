import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../data/models/tariff_model.dart';
import '../../domain/repositories/paginated_tariffs_repository.dart';
import '../../domain/repositories/tariffs_repository.dart';

enum TariffsLoadStatus { idle, loading, ready, failure }

class TariffsState {
  static const _unchangedBusy = Object();
  const TariffsState({
    this.tariffs = const <TariffModel>[],
    this.activePrice,
    this.trashFilter = TariffsTrashFilter.without,
    this.search = '',
    this.status = TariffsLoadStatus.idle,
    this.errorMessage,
    this.saving = false,
    this.busyId,
    this.selectionMode = false,
    this.selectedIds = const <int>{},
    this.page = 1,
    this.pageSize = 25,
    this.sortBy = 'start_date',
    this.sortDirection = 'desc',
    this.lastPage,
    this.totalCount,
  });

  final List<TariffModel> tariffs;

  /// السعر النشط المعروض في ترويسة الشاشة (`Tariff::getActivePrice()`).
  final double? activePrice;
  final TariffsTrashFilter trashFilter;
  final String search;
  final TariffsLoadStatus status;
  final String? errorMessage;
  final bool saving;
  final int? busyId;

  /// وضع التحديد المتعدد لعمليات التعرفة الجماعية (استعادة/حذف نهائي).
  final bool selectionMode;
  final Set<int> selectedIds;
  final int page;
  final int pageSize;
  final String sortBy;
  final String sortDirection;
  final int? lastPage;
  final int? totalCount;
  bool get hasPreviousPage => page > 1;
  bool get hasNextPage =>
      lastPage != null ? page < lastPage! : tariffs.length == pageSize;

  bool get isLoading => status == TariffsLoadStatus.loading;

  bool get canBulkRestore =>
      selectionMode && selectedIds.isNotEmpty && showsTrashed;
  bool get canBulkForceDelete =>
      selectionMode && selectedIds.isNotEmpty && showsTrashed;

  bool isSelected(int id) => selectedIds.contains(id);
  bool get showsTrashed => trashFilter != TariffsTrashFilter.without;

  List<TariffModel> get visible {
    if (search.trim().isEmpty) return tariffs;
    final needle = search.trim();
    return tariffs.where((t) => t.name.contains(needle)).toList();
  }

  TariffModel? get activeTariff {
    final now = DateTime.now();
    final candidates =
        tariffs.where((t) => !t.isTrashed && t.isEffectiveOn(now)).toList()
          ..sort((a, b) => b.id.compareTo(a.id));
    return candidates.isEmpty ? null : candidates.first;
  }

  TariffsState copyWith({
    List<TariffModel>? tariffs,
    double? activePrice,
    TariffsTrashFilter? trashFilter,
    String? search,
    TariffsLoadStatus? status,
    String? errorMessage,
    bool clearError = false,
    bool? saving,
    Object? busyId = _unchangedBusy,
    bool? selectionMode,
    Set<int>? selectedIds,
    int? page,
    int? pageSize,
    String? sortBy,
    String? sortDirection,
    int? lastPage,
    int? totalCount,
  }) => TariffsState(
    tariffs: tariffs ?? this.tariffs,
    activePrice: activePrice ?? this.activePrice,
    trashFilter: trashFilter ?? this.trashFilter,
    search: search ?? this.search,
    status: status ?? this.status,
    errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
    saving: saving ?? this.saving,
    busyId: identical(busyId, _unchangedBusy) ? this.busyId : busyId as int?,
    selectionMode: selectionMode ?? this.selectionMode,
    selectedIds: selectedIds ?? this.selectedIds,
    page: page ?? this.page,
    pageSize: pageSize ?? this.pageSize,
    sortBy: sortBy ?? this.sortBy,
    sortDirection: sortDirection ?? this.sortDirection,
    lastPage: lastPage ?? this.lastPage,
    totalCount: totalCount ?? this.totalCount,
  );
}

/// `TariffsCubit` — إدارة شرائح التسعيرة (`SCR-21`) مع `TrashedFilter`/`Restore`/`ForceDelete`.
class TariffsCubit extends Cubit<TariffsState> {
  TariffsCubit({required TariffsRepository repository})
    : _repository = repository,
      super(const TariffsState());

  final TariffsRepository _repository;
  int _loadGeneration = 0;

  Future<void> load({bool refresh = false}) async {
    if (!refresh && state.tariffs.isNotEmpty) return;
    final generation = ++_loadGeneration;
    final query = state;
    emit(state.copyWith(status: TariffsLoadStatus.loading, clearError: true));
    try {
      final repository = _repository;
      if (repository is PaginatedTariffsRepository) {
        final result = await (repository as PaginatedTariffsRepository)
            .listPage(
              trash: query.trashFilter,
              search: query.search,
              page: query.page,
              pageSize: query.pageSize,
              sortBy: query.sortBy,
              sortDirection: query.sortDirection,
            );
        if (isClosed || generation != _loadGeneration) return;
        if (result.tariffs.isEmpty && query.page > result.lastPage) {
          emit(state.copyWith(page: result.lastPage));
          await load(refresh: true);
          return;
        }
        emit(
          state.copyWith(
            tariffs: result.tariffs,
            activePrice: result.activePrice,
            page: result.currentPage,
            lastPage: result.lastPage,
            totalCount: result.total,
            status: TariffsLoadStatus.ready,
            busyId: null,
          ),
        );
        return;
      }
      final tariffs = await _repository.list(
        trash: query.trashFilter,
        search: query.search.isEmpty ? null : query.search,
      );
      final activePrice = await _repository.activePrice();
      if (isClosed || generation != _loadGeneration) return;
      emit(
        state.copyWith(
          tariffs: tariffs,
          activePrice: activePrice,
          status: TariffsLoadStatus.ready,
        ),
      );
    } on AppFailure catch (failure) {
      if (isClosed || generation != _loadGeneration) return;
      emit(
        state.copyWith(
          status: TariffsLoadStatus.failure,
          errorMessage: failure.message,
        ),
      );
    } catch (error) {
      if (isClosed || generation != _loadGeneration) return;
      emit(
        state.copyWith(
          status: TariffsLoadStatus.failure,
          errorMessage: '$error',
        ),
      );
    }
  }

  Future<void> setTrashFilter(TariffsTrashFilter filter) async {
    emit(
      state.copyWith(
        trashFilter: filter,
        page: 1,
        tariffs: const <TariffModel>[],
        selectedIds: const <int>{},
        selectionMode: false,
      ),
    );
    await load(refresh: true);
  }

  Future<void> search(String value) async {
    if (value == state.search) return;
    emit(state.copyWith(search: value, page: 1, selectedIds: const <int>{}));
    if (_repository is PaginatedTariffsRepository) await load(refresh: true);
  }

  Future<void> setPresentation({
    required int pageSize,
    required String sortBy,
    required String sortDirection,
  }) async {
    emit(
      state.copyWith(
        page: 1,
        pageSize: pageSize,
        sortBy: sortBy,
        sortDirection: sortDirection,
        selectedIds: const <int>{},
        selectionMode: false,
      ),
    );
    await load(refresh: true);
  }

  Future<void> goToPage(int page) async {
    if (state.isLoading ||
        page < 1 ||
        page == state.page ||
        (state.lastPage != null && page > state.lastPage!)) {
      return;
    }
    emit(
      state.copyWith(
        page: page,
        selectedIds: const <int>{},
        selectionMode: false,
      ),
    );
    await load(refresh: true);
  }

  Future<bool> save(TariffModel tariff, {bool isNew = true}) async {
    emit(state.copyWith(saving: true, clearError: true));
    try {
      if (isNew) {
        await _repository.create(tariff);
      } else {
        await _repository.update(tariff);
      }
      emit(state.copyWith(saving: false));
      await load(refresh: true);
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(saving: false, errorMessage: failure.message));
      return false;
    }
  }

  Future<bool> softDelete(int id) => _run(id, () => _repository.softDelete(id));

  /// فتح/إغلاق وضع التحديد، ويُغلق تلقائياً عند تبديل فلتر السلة.
  void toggleSelectionMode({bool? enabled}) {
    final next = enabled ?? !state.selectionMode;
    emit(
      state.copyWith(
        selectionMode: next,
        selectedIds: next ? state.selectedIds : const <int>{},
      ),
    );
  }

  void toggleSelected(int id) {
    final next = Set<int>.from(state.selectedIds);
    if (!next.remove(id)) next.add(id);
    emit(state.copyWith(selectedIds: next));
  }

  void selectAllVisible() {
    final ids = state.visible.map((t) => t.id).toSet();
    final allSelected = ids.isNotEmpty && state.selectedIds.containsAll(ids);
    emit(state.copyWith(selectedIds: allSelected ? const <int>{} : ids));
  }

  void clearSelection() => emit(state.copyWith(selectedIds: const <int>{}));

  /// استعادة جماعية — تُفرغ التحديد وتعيد عدد ما تمّ.
  Future<int> bulkRestore() async {
    final ids = state.selectedIds.toList(growable: false);
    if (ids.isEmpty) return 0;
    emit(state.copyWith(saving: true, clearError: true));
    try {
      final count = await _repository.bulkRestore(ids);
      emit(state.copyWith(saving: false, selectedIds: const <int>{}));
      await load(refresh: true);
      return count;
    } on AppFailure catch (failure) {
      emit(state.copyWith(saving: false, errorMessage: failure.message));
      return 0;
    }
  }

  /// حذف نهائي جماعي — تُفرغ التحديد وتعيد عدد ما حُذف.
  Future<int> bulkForceDelete() async {
    final ids = state.selectedIds.toList(growable: false);
    if (ids.isEmpty) return 0;
    emit(state.copyWith(saving: true, clearError: true));
    try {
      final count = await _repository.bulkForceDelete(ids);
      emit(state.copyWith(saving: false, selectedIds: const <int>{}));
      await load(refresh: true);
      return count;
    } on AppFailure catch (failure) {
      emit(state.copyWith(saving: false, errorMessage: failure.message));
      return 0;
    }
  }

  Future<bool> restore(int id) => _run(id, () => _repository.restore(id));

  Future<bool> forceDelete(int id) =>
      _run(id, () => _repository.forceDelete(id));

  Future<bool> _run(int id, Future<void> Function() action) async {
    emit(state.copyWith(busyId: id, clearError: true));
    try {
      await action();
      emit(state.copyWith(busyId: null));
      await load(refresh: true);
      return true;
    } on AppFailure catch (failure) {
      emit(state.copyWith(busyId: null, errorMessage: failure.message));
      return false;
    }
  }
}
