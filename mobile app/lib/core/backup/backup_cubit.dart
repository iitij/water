import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import 'local_backup.dart';

enum BackupStatus { initial, loading, ready, failure }

class BackupState extends Equatable {
  const BackupState({
    this.status = BackupStatus.initial,
    this.files = const <BackupFile>[],
    this.busy = false,
    this.restoring,
    this.includePendingActions = false,
    this.message,
    this.errorMessage,
  });

  final BackupStatus status;
  final List<BackupFile> files;

  /// عملية جارية (نسخ أو مشاركة) — تعطّل الأزرار كلها.
  final bool busy;

  /// اسم الملف الجاري استعادته.
  final String? restoring;

  /// استعادة عمليات المزامنة غير المرسلة مع البيانات.
  final bool includePendingActions;
  final String? message;
  final String? errorMessage;

  bool get isWorking => busy || restoring != null;

  BackupState copyWith({
    BackupStatus? status,
    List<BackupFile>? files,
    bool? busy,
    String? restoring,
    bool? includePendingActions,
    String? message,
    String? errorMessage,
    bool clearRestoring = false,
    bool clearMessages = false,
  }) =>
      BackupState(
        status: status ?? this.status,
        files: files ?? this.files,
        busy: busy ?? this.busy,
        restoring: clearRestoring ? null : restoring ?? this.restoring,
        includePendingActions: includePendingActions ?? this.includePendingActions,
        message: clearMessages ? null : message ?? this.message,
        errorMessage: clearMessages ? null : errorMessage ?? this.errorMessage,
      );

  @override
  List<Object?> get props =>
      <Object?>[status, files, busy, restoring, includePendingActions, message, errorMessage];
}

/// نسخ/استعادة بيانات الميدان (S7-08).
class BackupCubit extends Cubit<BackupState> {
  BackupCubit(this._service) : super(const BackupState());

  final LocalBackupService _service;

  Future<void> load() async {
    emit(state.copyWith(status: BackupStatus.loading, clearMessages: true));
    try {
      final files = await _service.listBackups();
      emit(state.copyWith(status: BackupStatus.ready, files: files));
    } catch (error) {
      emit(state.copyWith(status: BackupStatus.failure, errorMessage: _messageFor(error)));
    }
  }

  void setIncludePendingActions(bool value) =>
      emit(state.copyWith(includePendingActions: value));

  Future<BackupFile?> createBackup() async {
    if (state.isWorking) return null;
    emit(state.copyWith(busy: true, clearMessages: true));
    try {
      final created = await _service.createBackup();
      final files = await _service.listBackups();
      emit(state.copyWith(
        status: BackupStatus.ready,
        busy: false,
        files: files,
        message: 'أُنشئت النسخة ${created.name} (${created.totalRows} صفاً، ${created.sizeLabel}).',
      ));
      return created;
    } catch (error) {
      emit(state.copyWith(busy: false, errorMessage: _messageFor(error)));
      return null;
    }
  }

  Future<void> share(String name) async {
    if (state.isWorking) return;
    emit(state.copyWith(busy: true, clearMessages: true));
    try {
      await _service.shareBackup(name);
      emit(state.copyWith(busy: false));
    } catch (error) {
      emit(state.copyWith(busy: false, errorMessage: 'تعذّرت المشاركة: ${_messageFor(error)}'));
    }
  }

  Future<BackupRestoreResult?> restore(String name) async {
    if (state.isWorking) return null;
    emit(state.copyWith(restoring: name, clearMessages: true));
    try {
      final result = await _service.restore(
        name,
        includePendingActions: state.includePendingActions,
      );
      emit(state.copyWith(
        restoring: null,
        clearRestoring: true,
        message: 'استُعيدت ${result.restoredRows} صفاً'
            '${result.pendingActions > 0 ? ' و${result.pendingActions} عملية غير مرسلة' : ''}.'
            '${result.warnings.isEmpty ? '' : ' ${result.warnings.first}'}',
      ));
      return result;
    } catch (error) {
      emit(state.copyWith(clearRestoring: true, errorMessage: _messageFor(error)));
      return null;
    }
  }

  Future<void> delete(String name) async {
    if (state.isWorking) return;
    emit(state.copyWith(clearMessages: true));
    try {
      await _service.delete(name);
      final files = await _service.listBackups();
      emit(state.copyWith(files: files, message: 'حُذفت النسخة $name.'));
    } catch (error) {
      emit(state.copyWith(errorMessage: 'تعذّر حذف الملف: ${_messageFor(error)}'));
    }
  }

  void clearMessages() => emit(state.copyWith(clearMessages: true));

  String _messageFor(Object error) => error is BackupException
      ? error.message
      : error is StateError
          ? error.message.toString()
      : 'تعذّرت العملية على الجهاز: ${error.toString()}';
}
