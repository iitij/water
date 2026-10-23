import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../data/services/excel_export_service.dart';
import '../../domain/entities/export_entities.dart';

class ExportState extends Equatable {
  const ExportState({
    this.catalog,
    this.filters = const {
      ExportKind.subscribers: ExportFilters(),
      ExportKind.invoices: ExportFilters(),
      ExportKind.readings: ExportFilters(),
    },
    this.files = const {},
    this.busyKinds = const {},
    this.sharingKinds = const {},
    this.isCatalogLoading = false,
    this.errorMessage,
    this.successMessage,
  });

  final ExportCatalog? catalog;
  final Map<ExportKind, ExportFilters> filters;
  final Map<ExportKind, ExportedExcelFile> files;
  final Set<ExportKind> busyKinds;
  final Set<ExportKind> sharingKinds;
  final bool isCatalogLoading;
  final String? errorMessage;
  final String? successMessage;

  ExportState copyWith({
    ExportCatalog? catalog,
    Map<ExportKind, ExportFilters>? filters,
    Map<ExportKind, ExportedExcelFile>? files,
    Set<ExportKind>? busyKinds,
    Set<ExportKind>? sharingKinds,
    bool? isCatalogLoading,
    String? errorMessage,
    String? successMessage,
    bool clearMessages = false,
  }) {
    return ExportState(
      catalog: catalog ?? this.catalog,
      filters: filters ?? this.filters,
      files: files ?? this.files,
      busyKinds: busyKinds ?? this.busyKinds,
      sharingKinds: sharingKinds ?? this.sharingKinds,
      isCatalogLoading: isCatalogLoading ?? this.isCatalogLoading,
      errorMessage: clearMessages ? null : errorMessage ?? this.errorMessage,
      successMessage: clearMessages ? null : successMessage ?? this.successMessage,
    );
  }

  @override
  List<Object?> get props => [
        catalog,
        filters,
        files,
        busyKinds,
        sharingKinds,
        isCatalogLoading,
        errorMessage,
        successMessage,
      ];
}

class ExportCubit extends Cubit<ExportState> {
  ExportCubit(this._service) : super(const ExportState());

  final ExcelExportService _service;

  Future<void> loadCatalog() async {
    if (state.isCatalogLoading) return;
    emit(state.copyWith(isCatalogLoading: true, clearMessages: true));
    try {
      final catalog = await _service.getCatalog();
      emit(state.copyWith(catalog: catalog, isCatalogLoading: false));
    } catch (error) {
      emit(state.copyWith(isCatalogLoading: false, errorMessage: error.toString()));
    }
  }

  void updateFilters(ExportKind kind, ExportFilters filters) {
    emit(state.copyWith(filters: {...state.filters, kind: filters}, clearMessages: true));
  }

  Future<void> export(ExportKind kind) async {
    if (state.busyKinds.contains(kind)) return;
    final filters = state.filters[kind] ?? const ExportFilters();
    if (!filters.hasValidDates) {
      emit(state.copyWith(errorMessage: 'تاريخ البداية يجب ألا يكون بعد تاريخ النهاية.'));
      return;
    }
    emit(
      state.copyWith(
        busyKinds: {...state.busyKinds, kind},
        clearMessages: true,
      ),
    );
    try {
      final file = await _service.export(kind, filters);
      emit(
        state.copyWith(
          files: {...state.files, kind: file},
          busyKinds: {...state.busyKinds}..remove(kind),
          successMessage: 'تم تنزيل ${file.fileName} وحفظه على الجهاز.',
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          busyKinds: {...state.busyKinds}..remove(kind),
          errorMessage: error.toString(),
        ),
      );
    }
  }

  Future<void> open(ExportKind kind) async {
    final file = state.files[kind];
    if (file == null) return;
    try {
      await _service.open(file);
    } catch (error) {
      emit(state.copyWith(errorMessage: error.toString()));
    }
  }

  Future<void> share(ExportKind kind) async {
    final file = state.files[kind];
    if (file == null || state.sharingKinds.contains(kind)) return;
    emit(state.copyWith(sharingKinds: {...state.sharingKinds, kind}, clearMessages: true));
    try {
      await _service.share(file);
      emit(
        state.copyWith(
          sharingKinds: {...state.sharingKinds}..remove(kind),
          successMessage: 'تم فتح خيارات مشاركة ${file.fileName}.',
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          sharingKinds: {...state.sharingKinds}..remove(kind),
          errorMessage: error.toString(),
        ),
      );
    }
  }
}
