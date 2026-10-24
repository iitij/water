import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../finance/finance_feedback.dart';
import '../l10n/app_strings.dart';
import '../ui/app_controls.dart';
import '../ui/app_surfaces.dart';
import '../ui/app_tokens.dart';
import 'backup_cubit.dart';
import 'local_backup.dart';

/// شاشة «نسخة احتياطية للبيانات المحلية» (S7-08).
///
/// جمهورها: قارئ ميداني بأسبوع عمل غير مُزامن وجهاز قد يتعطّل. النسخة تُكتب
/// داخل مجلد التطبيق ثم تُشارك (Drive/واتساب) أو تُستعاد من نفس القائمة.
class BackupRestoreScreen extends StatelessWidget {
  const BackupRestoreScreen({required this.service, super.key});

  final LocalBackupService service;

  @override
  Widget build(BuildContext context) => BlocProvider(
        create: (_) => BackupCubit(service)..load(),
        child: const _BackupView(),
      );
}

class _BackupView extends StatelessWidget {
  const _BackupView();

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<BackupCubit, BackupState>(
      listenWhen: (previous, current) =>
          previous.message != current.message || previous.errorMessage != current.errorMessage,
      listener: (context, state) {
        final message = state.message;
        final error = state.errorMessage;
        if (message != null) {
          AppFeedback.success(context, message);
          context.read<BackupCubit>().clearMessages();
        } else if (error != null) {
          AppFeedback.error(context, error);
          context.read<BackupCubit>().clearMessages();
        }
      },
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: Text(AppStrings.of(context).backupTitle),
            actions: <Widget>[
              IconButton(
                tooltip: 'تحديث القائمة',
                onPressed: state.isWorking ? null : () => context.read<BackupCubit>().load(),
                icon: const Icon(Icons.refresh_rounded),
              ),
            ],
          ),
          body: state.status == BackupStatus.loading && state.files.isEmpty
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  children: <Widget>[
                    const _IntroCard(),
                    const SizedBox(height: AppSpacing.md),
                    _RestoreOptions(state: state),
                    const SizedBox(height: AppSpacing.md),
                    FilledButton.icon(
                      onPressed: state.isWorking
                          ? null
                          : () => _create(context),
                      icon: const Icon(Icons.save_alt_rounded),
                      label: const Text('إنشاء نسخة الآن'),
                    ),
                    const SizedBox(height: AppSpacing.lg),
                    Text(
                      'النسخ المحفوظة (${state.files.length})',
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    if (state.files.isEmpty)
                      const AppCard(
                        child: Text(
                          'لا توجد نسخ بعد. اضغط «إنشاء نسخة الآن» — الملف يبقى على '
                          'الجهاز ويمكن مشاركته إلى Drive أو واتساب.',
                        ),
                      )
                    else
                      for (final file in state.files)
                        Padding(
                          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                          child: _BackupCard(file: file, state: state),
                        ),
                  ],
                ),
        );
      },
    );
  }

  Future<void> _create(BuildContext context) async {
    final created = await context.read<BackupCubit>().createBackup();
    if (created == null || !context.mounted) return;
    final share = await ConfirmDialog.show(
      context,
      title: 'مشاركة النسخة؟',
      message: 'حُفظت النسخة على الجهاز (${created.sizeLabel}). مشاركتها تنقلها خارج '
          'التطبيق إلى Drive أو واتساب — مفيدة إن تعطّل الجهاز.',
      confirmLabel: 'مشاركة',
    );
    if (share && context.mounted) {
      await context.read<BackupCubit>().share(created.name);
    }
  }
}

class _IntroCard extends StatelessWidget {
  const _IntroCard();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return AppCard(
      color: scheme.surfaceContainerHighest,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(Icons.inventory_2_outlined, color: scheme.primary),
          const SizedBox(width: AppSpacing.sm),
          const Expanded(
            child: Text(
              'النسخة تحتوي المربعات والمشتركين والعدادات والقراءات وقراءات الفترة '
              'وعمليات المزامنة غير المرسلة. لا تحتوي كلمات المرور ولا التوكِنات.',
              style: TextStyle(height: 1.5),
            ),
          ),
        ],
      ),
    );
  }
}

class _RestoreOptions extends StatelessWidget {
  const _RestoreOptions({required this.state});

  final BackupState state;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: EdgeInsets.zero,
      child: SwitchListTile.adaptive(
        value: state.includePendingActions,
        onChanged: state.isWorking
            ? null
            : (value) => context.read<BackupCubit>().setIncludePendingActions(value),
        title: const Text('استعادة عمليات المزامنة غير المرسلة'),
        subtitle: const Text(
          'أطفئها إن كانت النسخة من جهاز آخر شغّال — لتجنّب إرسال نفس القراءة مرتين.',
        ),
        secondary: const Icon(Icons.sync_problem_outlined),
      ),
    );
  }
}

class _BackupCard extends StatelessWidget {
  const _BackupCard({required this.file, required this.state});

  final BackupFile file;
  final BackupState state;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final isRestoring = state.restoring == file.name;
    final disabled = state.isWorking;
    return AppCard(
      borderColor: file.isCompatible ? null : scheme.error,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(
                file.isCompatible ? Icons.archive_outlined : Icons.report_gmailerrorred_rounded,
                color: file.isCompatible ? scheme.primary : scheme.error,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(_formatDate(file.createdAt),
                        style: Theme.of(context).textTheme.titleSmall),
                    Text(
                      <String>[
                        file.sizeLabel,
                        if (file.totalRows > 0) '${file.totalRows} صفاً',
                        if (file.appVersion != null) 'إصدار ${file.appVersion}',
                        if (!file.isCompatible) 'ملف غير متوافق',
                      ].join(' • '),
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(color: scheme.onSurfaceVariant),
                    ),
                  ],
                ),
              ),
              if (isRestoring)
                const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          if (file.counts.isNotEmpty)
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: <Widget>[
                for (final entry in file.counts.entries)
                  if (entry.value > 0)
                    AppPill(label: '${_labelOf(entry.key)}: ${entry.value}', dense: true),
              ],
            ),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.xs,
            children: <Widget>[
              TextButton.icon(
                onPressed: disabled || !file.isCompatible ? null : () => _restore(context),
                icon: const Icon(Icons.settings_backup_restore_rounded),
                label: Text(AppStrings.of(context).actionRestore),
              ),
              TextButton.icon(
                onPressed: disabled ? null : () => context.read<BackupCubit>().share(file.name),
                icon: const Icon(Icons.ios_share_rounded),
                label: Text(AppStrings.of(context).actionShare),
              ),
              TextButton.icon(
                onPressed: disabled ? null : () => _delete(context),
                icon: Icon(Icons.delete_outline_rounded, color: scheme.error),
                label: Text(
                  AppStrings.of(context).actionDelete,
                  style: TextStyle(color: scheme.error),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _restore(BuildContext context) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'استعادة هذه النسخة؟',
      message: 'تُستبدل بيانات الميدان المحفوظة على هذا الجهاز بمحتوى النسخة. '
          'البيانات على الخادم لا تتأثر.',
      confirmLabel: 'استعادة',
      destructive: true,
      details: <String>[
        if (file.counts['readings'] != null) 'قراءات: ${file.counts['readings']}',
        if (file.counts['meters'] != null) 'عدادات: ${file.counts['meters']}',
        if (file.counts['pending_actions'] != null)
          'عمليات غير مرسلة: ${file.counts['pending_actions']}',
      ],
    );
    if (!confirmed || !context.mounted) return;
    await context.read<BackupCubit>().restore(file.name);
  }

  Future<void> _delete(BuildContext context) async {
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'حذف ملف النسخة؟',
      message: 'سيُحذف الملف من الجهاز. إن لم تكن قد شاركته فلن يمكن استرجاعه.',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (confirmed && context.mounted) {
      await context.read<BackupCubit>().delete(file.name);
    }
  }

  static String _labelOf(String table) => switch (table) {
        'squares' => 'مربعات',
        'subscribers' => 'مشتركون',
        'meters' => 'عدادات',
        'meter_periods' => 'قراءات فترة',
        'readings' => 'قراءات',
        'pending_actions' => 'غير مرسلة',
        'settings' => 'إعدادات',
        _ => table,
      };

  static String _formatDate(DateTime utc) {
    final local = utc.toLocal();
    String two(int value) => value.toString().padLeft(2, '0');
    return '${local.year}/${two(local.month)}/${two(local.day)} — '
        '${two(local.hour)}:${two(local.minute)}';
  }
}
