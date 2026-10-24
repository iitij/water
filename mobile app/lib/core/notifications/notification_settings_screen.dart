import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../finance/finance_feedback.dart';
import '../l10n/app_strings.dart';
import '../ui/app_controls.dart';
import '../ui/app_surfaces.dart';
import '../ui/app_tokens.dart';
import 'app_reminders.dart';
import 'notification_service.dart';
import 'reminders_cubit.dart';

/// شاشة «تذكيرات الجهاز» (S7-09).
///
/// الغرض: القارئ لا يفتح التطبيق على نفسه. هذه الشاشة تجعل التطبيق يتذكّره:
/// قراءات الصباح، فواتير الظهر، إقفال الصندوق مساءً، ونسخة احتياطية أسبوعية.
class NotificationSettingsScreen extends StatelessWidget {
  const NotificationSettingsScreen({
    required this.scheduler,
    this.store,
    this.clock,
    super.key,
  });

  final ReminderScheduler scheduler;
  final ReminderPreferencesStore? store;
  final DateTime Function()? clock;

  @override
  Widget build(BuildContext context) => BlocProvider(
        create: (_) => RemindersCubit(
          scheduler: scheduler,
          store: store ?? SharedPrefsReminderStore(),
          clock: clock,
        )..load(),
        child: const _RemindersView(),
      );
}

class _RemindersView extends StatelessWidget {
  const _RemindersView();

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<RemindersCubit, RemindersState>(
      listenWhen: (previous, current) =>
          previous.message != current.message || previous.errorMessage != current.errorMessage,
      listener: (context, state) {
        final message = state.message;
        final error = state.errorMessage;
        if (message != null) {
          AppFeedback.success(context, message);
          context.read<RemindersCubit>().clearMessages();
        } else if (error != null) {
          AppFeedback.error(context, error);
          context.read<RemindersCubit>().clearMessages();
        }
      },
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: Text(AppStrings.of(context).remindersTitle),
            actions: <Widget>[
              IconButton(
                tooltip: 'إشعار تجريبي',
                onPressed: state.saving ? null : () => context.read<RemindersCubit>().sendTest(),
                icon: const Icon(Icons.notifications_active_outlined),
              ),
            ],
          ),
          body: state.status == RemindersStatus.loading
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  children: <Widget>[
                    if (state.permissionDenied) const _PermissionCard(),
                    AppCard(
                      color: Theme.of(context).colorScheme.surfaceContainerHighest,
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Icon(Icons.schedule_rounded, color: Theme.of(context).colorScheme.primary),
                          const SizedBox(width: AppSpacing.sm),
                          const Expanded(
                            child: Text(
                              'التذكيرات تُجدول على هذا الجهاز فقط — لا تحتاج شبكة، '
                              'ولا تُرسل أي بيانات. غيّر الوقت بالضغط على الساعة.',
                              style: TextStyle(height: 1.5),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: AppSpacing.md),
                    for (final setting in state.preferences.settings)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: _ReminderTile(
                          setting: setting,
                          planned: state.planned,
                          enabled: !state.saving,
                        ),
                      ),
                  ],
                ),
        );
      },
    );
  }
}

class _PermissionCard extends StatelessWidget {
  const _PermissionCard();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: AppCard(
        borderColor: scheme.error,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Icon(Icons.notifications_off_outlined, color: scheme.error),
            const SizedBox(width: AppSpacing.sm),
            const Expanded(
              child: Text(
                'إشعارات التطبيق ممنوعة من إعدادات النظام. افتح إعدادات الجهاز ← '
                'التطبيقات ← المياه ← الإشعارات، واسمح بها ثم أعد تشغيل التطبيق.',
                style: TextStyle(height: 1.5),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ReminderTile extends StatelessWidget {
  const _ReminderTile({
    required this.setting,
    required this.planned,
    required this.enabled,
  });

  final ReminderSetting setting;
  final List<PlannedReminder> planned;
  final bool enabled;

  PlannedReminder? get _planned {
    for (final item in planned) {
      if (item.kind == setting.kind) return item;
    }
    return null;
  }

  Future<void> _pickTime(BuildContext context) async {
    final picked = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: setting.hour, minute: setting.minute),
      helpText: 'وقت التذكير',
    );
    if (picked == null || !context.mounted) return;
    await context.read<RemindersCubit>().setTime(setting.kind, picked.hour, picked.minute);
  }

  @override
  Widget build(BuildContext context) {
    final reminder = _planned;
    return AppCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: <Widget>[
          SwitchListTile.adaptive(
            value: setting.enabled,
            onChanged: enabled
                ? (value) => context.read<RemindersCubit>().setEnabled(setting.kind, value)
                : null,
            title: Text(setting.kind.title),
            subtitle: Text(_subtitle(reminder)),
            secondary: Icon(_iconOf(setting.kind)),
          ),
          if (setting.enabled)
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(0, 0, AppSpacing.sm, AppSpacing.xs),
                child: TextButton.icon(
                  onPressed: enabled ? () => _pickTime(context) : null,
                  icon: const Icon(Icons.access_time_rounded, size: 18),
                  label: Text('${setting.kind.scheduleLabel} — ${setting.timeLabel}'),
                ),
              ),
            ),
        ],
      ),
    );
  }

  static IconData _iconOf(ReminderKind kind) => switch (kind) {
        ReminderKind.readingDaily => Icons.speed_rounded,
        ReminderKind.invoicesDaily => Icons.receipt_long_rounded,
        ReminderKind.cashClose => Icons.point_of_sale_rounded,
        ReminderKind.backupWeekly => Icons.archive_outlined,
      };

  static String _subtitle(PlannedReminder? reminder) {
    if (reminder == null) return 'متوقف';
    final next = reminder.scheduledAt;
    String two(int value) => value.toString().padLeft(2, '0');
    return 'التالي: ${next.year}/${two(next.month)}/${two(next.day)} '
        '${two(next.hour)}:${two(next.minute)}';
  }
}
