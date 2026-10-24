import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import 'app_reminders.dart';
import 'notification_service.dart';

enum RemindersStatus { loading, ready, failure }

class RemindersState extends Equatable {
  const RemindersState({
    this.status = RemindersStatus.loading,
    this.preferences = const ReminderPreferences(settings: <ReminderSetting>[]),
    this.planned = const <PlannedReminder>[],
    this.saving = false,
    this.permissionDenied = false,
    this.message,
    this.errorMessage,
  });

  final RemindersStatus status;
  final ReminderPreferences preferences;
  final List<PlannedReminder> planned;
  final bool saving;

  /// النظام رفض إذن الإشعارات — نعرض إرشاداً بدل تكرار الطلب بصمت.
  final bool permissionDenied;
  final String? message;
  final String? errorMessage;

  RemindersState copyWith({
    RemindersStatus? status,
    ReminderPreferences? preferences,
    List<PlannedReminder>? planned,
    bool? saving,
    bool? permissionDenied,
    String? message,
    String? errorMessage,
    bool clearMessages = false,
  }) =>
      RemindersState(
        status: status ?? this.status,
        preferences: preferences ?? this.preferences,
        planned: planned ?? this.planned,
        saving: saving ?? this.saving,
        permissionDenied: permissionDenied ?? this.permissionDenied,
        message: clearMessages ? null : message ?? this.message,
        errorMessage: clearMessages ? null : errorMessage ?? this.errorMessage,
      );

  @override
  List<Object?> get props =>
      <Object?>[status, preferences, planned, saving, permissionDenied, message, errorMessage];
}

/// إعدادات التذكيرات المحلية (S7-09).
class RemindersCubit extends Cubit<RemindersState> {
  RemindersCubit({
    required ReminderScheduler scheduler,
    required ReminderPreferencesStore store,
    DateTime Function()? clock,
  })  : _scheduler = scheduler,
        _store = store,
        _clock = clock ?? DateTime.now,
        super(const RemindersState());

  final ReminderScheduler _scheduler;
  final ReminderPreferencesStore _store;
  final DateTime Function() _clock;

  Future<void> load() async {
    try {
      final preferences = await _store.load();
      final planned = ReminderPlanner.plan(preferences, _clock());
      emit(state.copyWith(status: RemindersStatus.ready, preferences: preferences, planned: planned));
    } catch (error) {
      emit(state.copyWith(status: RemindersStatus.failure, errorMessage: _messageFor(error)));
    }
  }

  Future<void> setEnabled(ReminderKind kind, bool enabled) async {
    final updated = state.preferences.of(kind).copyWith(enabled: enabled);
    var permissionDenied = false;
    if (enabled) {
      try {
        final granted = await _scheduler.requestPermission();
        if (!granted) {
          permissionDenied = true;
          emit(state.copyWith(
            permissionDenied: true,
            clearMessages: true,
            errorMessage: 'النظام لا يسمح بإشعارات هذا التطبيق. فعّلها من إعدادات الجهاز '
                '← التطبيقات ← المياه ← الإشعارات.',
          ));
        }
      } catch (_) {
        // طلب الإذن غير متاح على هذه المنصّة — نكمل الجدولة.
      }
    }
    await _persist(state.preferences.withSetting(updated), permissionDenied: permissionDenied);
  }

  Future<void> setTime(ReminderKind kind, int hour, int minute) async {
    final updated = state.preferences.of(kind).copyWith(hour: hour, minute: minute);
    await _persist(state.preferences.withSetting(updated));
  }

  /// إعادة جدولة كل التذكيرات حسب الإعداد الحالي.
  Future<void> reschedule() async {
    if (state.status != RemindersStatus.ready) return;
    await _persist(state.preferences, rescheduleOnly: true);
  }

  Future<void> sendTest() async {
    try {
      await _scheduler.showNow(
        title: 'اختبار التذكيرات',
        body: 'إن وصلتك هذه الرسالة فإشعارات التطبيق تعمل على هذا الجهاز.',
      );
      emit(state.copyWith(clearMessages: true, message: 'أُرسل إشعار تجريبي.'));
    } catch (error) {
      emit(state.copyWith(errorMessage: 'تعذّر إرسال الإشعار: ${_messageFor(error)}'));
    }
  }

  Future<void> _persist(
    ReminderPreferences preferences, {
    bool permissionDenied = false,
    bool rescheduleOnly = false,
  }) async {
    emit(state.copyWith(saving: true, clearMessages: true));
    try {
      if (!rescheduleOnly) await _store.save(preferences);
      final planned = await applyReminderSchedule(
        scheduler: _scheduler,
        preferences: preferences,
        now: _clock(),
      );
      emit(state.copyWith(
        status: RemindersStatus.ready,
        saving: false,
        preferences: preferences,
        planned: planned,
        permissionDenied: permissionDenied || state.permissionDenied,
        message: planned.isEmpty ? 'كل التذكيرات متوقفة.' : 'حُدّثت التذكيرات (${planned.length}).',
      ));
    } catch (error) {
      emit(state.copyWith(saving: false, errorMessage: _messageFor(error)));
    }
  }

  void clearMessages() => emit(state.copyWith(clearMessages: true));

  String _messageFor(Object error) => 'تعذّر تحديث التذكيرات: $error';
}
