import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../data/models/tariff_model.dart';
import '../../domain/repositories/tariffs_repository.dart';

enum TariffsLoadStatus { idle, loading, ready, failure }

class TariffsState {
  const TariffsState({
    this.tariffs = const <TariffModel>[],
    this.activePrice,
    this.trashFilter = TariffsTrashFilter.without,
    this.search = '',
    this.status = TariffsLoadStatus.idle,
    this.errorMessage,
    this.saving = false,
    this.busyId,
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

  bool get isLoading => status == TariffsLoadStatus.loading;
  bool get showsTrashed => trashFilter != TariffsTrashFilter.without;

  List<TariffModel> get visible {
    if (search.trim().isEmpty) return tariffs;
    final needle = search.trim();
    return tariffs.where((t) => t.name.contains(needle)).toList();
  }

  TariffModel? get activeTariff {
    final now = DateTime.now();
    final candidates = tariffs
        .where((t) => !t.isTrashed && t.isEffectiveOn(now))
        .toList()
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
    int? busyId,
  }) =>
      TariffsState(
        tariffs: tariffs ?? this.tariffs,
        activePrice: activePrice ?? this.activePrice,
        trashFilter: trashFilter ?? this.trashFilter,
        search: search ?? this.search,
        status: status ?? this.status,
        errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
        saving: saving ?? this.saving,
        busyId: busyId ?? this.busyId,
      );
}

/// `TariffsCubit` — إدارة شرائح التسعيرة (`SCR-21`) مع `TrashedFilter`/`Restore`/`ForceDelete`.
class TariffsCubit extends Cubit<TariffsState> {
  TariffsCubit({required TariffsRepository repository})
      : _repository = repository,
        super(const TariffsState());

  final TariffsRepository _repository;

  Future<void> load({bool refresh = false}) async {
    if (!refresh && state.tariffs.isNotEmpty) return;
    emit(state.copyWith(status: TariffsLoadStatus.loading, clearError: true));
    try {
      final tariffs = await _repository.list(
        trash: state.trashFilter,
        search: state.search.isEmpty ? null : state.search,
      );
      final activePrice = await _repository.activePrice();
      emit(state.copyWith(
        tariffs: tariffs,
        activePrice: activePrice,
        status: TariffsLoadStatus.ready,
      ));
    } on AppFailure catch (failure) {
      emit(state.copyWith(status: TariffsLoadStatus.failure, errorMessage: failure.message));
    } catch (error) {
      emit(state.copyWith(status: TariffsLoadStatus.failure, errorMessage: '$error'));
    }
  }

  Future<void> setTrashFilter(TariffsTrashFilter filter) async {
    emit(state.copyWith(trashFilter: filter, tariffs: const <TariffModel>[]));
    await load(refresh: true);
  }

  void search(String value) => emit(state.copyWith(search: value));

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

  Future<bool> restore(int id) => _run(id, () => _repository.restore(id));

  Future<bool> forceDelete(int id) => _run(id, () => _repository.forceDelete(id));

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
