import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../core/navigation/sub_page.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_controls.dart';
import '../../../core/ui/app_motion.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tiles.dart';
import '../../../core/ui/app_tokens.dart';
import '../domain/sync_queue_item.dart';
import '../services/sync_queue_manager.dart';

class OfflineSyncMonitorScreen extends StatelessWidget {
  const OfflineSyncMonitorScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final manager = GetIt.I<SyncQueueManager>();
    return AnimatedBuilder(
      animation: manager,
      builder: (context, _) {
        final theme = Theme.of(context);
        final online = manager.isOnline;
        final syncing = manager.isSyncing;

        return AppPageBody(
          topPadding: 16,
          bottomPadding: 32,
          children: <Widget>[
            AppReveal(
              child: AppHeroPanel(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    const HeroCopy(
                      eyebrow: 'المزامنة الذكية',
                      title: 'كل عملية محفوظة',
                      subtitle:
                          'تابع القراءات والمدفوعات المحفوظة على جهازك، وأعد إرسال ما تعذر عند عودة الشبكة.',
                    ),
                    const Gap(AppSpacing.md),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: <Widget>[
                        HeroMetaChip(
                          icon: online == true ? Icons.wifi_rounded : Icons.wifi_off_rounded,
                          label: online == true
                              ? 'الاتصال متاح'
                              : online == false
                                  ? 'أنت تعمل دون اتصال'
                                  : 'جارٍ فحص الشبكة',
                        ),
                        if (syncing) const HeroMetaChip(icon: Icons.sync_rounded, label: 'جارٍ الإرسال…'),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const Gap(AppSpacing.md),
            AppStatStrip(
              items: <AppStatItem>[
                AppStatItem(
                  label: 'إجمالي العمليات',
                  value: '${manager.pendingCount}',
                  icon: Icons.pending_actions_rounded,
                  color: AppColors.info,
                ),
                AppStatItem(
                  label: 'تحتاج إعادة محاولة',
                  value: '${manager.failedCount}',
                  icon: Icons.error_outline_rounded,
                  color: manager.failedCount > 0 ? AppColors.danger : AppColors.neutral,
                ),
                AppStatItem(
                  label: 'حالة الاتصال',
                  value: online == true ? 'متصل' : online == false ? 'أوفلاين' : 'فحص',
                  icon: online == true ? Icons.cloud_done_rounded : Icons.cloud_off_rounded,
                  color: online == true ? AppColors.success : AppColors.warning,
                ),
              ],
            ),
            if (online == false) ...<Widget>[
              const Gap(AppSpacing.md),
              const AppNotice(
                title: 'لا يوجد اتصال حالياً',
                message: 'العمليات آمنة على الجهاز وستتم إعادة المحاولة تلقائياً عند عودة الشبكة.',
                icon: Icons.info_outline_rounded,
                color: AppColors.warning,
              ),
            ],
            const Gap(AppSpacing.xl),
            if (manager.items.isNotEmpty) ...<Widget>[
              Row(
                children: <Widget>[
                  Expanded(
                    child: SectionHeading(
                      title: 'قائمة العمليات',
                      subtitle: '${manager.items.length} عملية في الطابور',
                      icon: Icons.list_alt_rounded,
                    ),
                  ),
                  if (manager.failedCount > 0)
                    TextButton.icon(
                      onPressed: syncing ? null : manager.retryFailed,
                      icon: const Icon(Icons.replay_rounded, size: 17),
                      label: const Text('إعادة الفاشلة'),
                    ),
                ],
              ),
              const Gap(AppSpacing.sm),
              for (final item in manager.items)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _QueueItemCard(
                    item: item,
                    isSyncing: syncing,
                    onRetry: () => manager.retry(item.id),
                  ),
                ),
            ] else ...<Widget>[
              AppCard(
                child: Column(
                  children: <Widget>[
                    const AppIconBubble(
                      icon: Icons.cloud_done_rounded,
                      color: AppColors.success,
                      size: 56,
                    ),
                    const SizedBox(height: 14),
                    Text(
                      'لا توجد عمليات معلّقة',
                      style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 5),
                    Text(
                      'ستظهر هنا القراءات والمدفوعات التي تُحفظ أوفلاين من وحدات الميدان، مع حالتها وعدد محاولات المزامنة.',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ],
            const Gap(AppSpacing.lg),
            AppPrimaryButton(
              label: syncing ? 'جارٍ مزامنة العمليات…' : 'مزامنة الآن',
              icon: syncing ? null : Icons.sync_rounded,
              loading: syncing,
              onPressed: syncing || manager.items.isEmpty ? null : manager.syncPending,
            ),
            const Gap(AppSpacing.md),
            Text(
              'تُرسل القراءات إلى /api/readings/sync-batch، والمدفوعات إلى /api/invoices/pay. لكل عملية معرّف فريد يمنع تكرارها عند إعادة المحاولة.',
              style: theme.textTheme.bodySmall?.copyWith(height: 1.6),
              textAlign: TextAlign.center,
            ),
          ],
        );
      },
    );
  }
}

class _QueueItemCard extends StatelessWidget {
  const _QueueItemCard({
    required this.item,
    required this.isSyncing,
    required this.onRetry,
  });

  final SyncQueueItem item;
  final bool isSyncing;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isReading = item.type == SyncQueueType.reading;
    final payload = item.payload;
    final amount = payload['amount']?.toString();
    final title = isReading ? 'قراءة عداد' : 'دفعة تحصيل';
    final detail = isReading
        ? 'عداد ${payload['meter_id'] ?? '—'} • القراءة ${payload['current_reading'] ?? '—'}'
        : 'فاتورة ${payload['invoice_id'] ?? '—'} • المبلغ ${amount ?? '—'} ريال';
    final status = switch (item.status) {
      SyncQueueStatus.queued => 'queued',
      SyncQueueStatus.syncing => 'syncing',
      SyncQueueStatus.failed => 'failed',
    };
    final dateLabel = item.createdAt.toLocal().toString();
    final shortId = item.id.length > 8 ? item.id.substring(0, 8) : item.id;
    final color = isReading ? AppColors.info : AppColors.success;

    return AppCard(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              AppIconBubble(
                icon: isReading ? Icons.speed_rounded : Icons.payments_outlined,
                color: color,
                size: 42,
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(title, style: theme.textTheme.titleMedium),
                    const SizedBox(height: 2),
                    Text(
                      detail,
                      style: theme.textTheme.bodySmall,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _StatusPill(status: status),
            ],
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: <Widget>[
              AppPill(
                label: dateLabel.length > 16 ? dateLabel.substring(0, 16) : dateLabel,
                icon: Icons.schedule_rounded,
                color: AppColors.neutral,
                dense: true,
              ),
              AppPill(
                label: 'محاولات ${item.attempts}',
                icon: Icons.refresh_rounded,
                color: AppColors.info,
                dense: true,
              ),
              AppPill(
                label: shortId,
                icon: Icons.fingerprint_rounded,
                color: AppColors.violet,
                dense: true,
              ),
            ],
          ),
          if (item.lastError != null && item.lastError!.isNotEmpty) ...<Widget>[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppColors.danger.withValues(alpha: .07),
                borderRadius: BorderRadius.circular(AppRadius.sm),
              ),
              child: Text(
                item.lastError!,
                style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
                maxLines: 3,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
          if (item.status == SyncQueueStatus.failed) ...<Widget>[
            const SizedBox(height: 6),
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton.icon(
                onPressed: isSyncing ? null : onRetry,
                icon: const Icon(Icons.replay_rounded, size: 17),
                label: const Text('إعادة المحاولة'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final (label, color) = switch (status) {
      'failed' => ('فشلت', AppColors.danger),
      'syncing' => ('جارٍ الإرسال', AppColors.info),
      _ => ('بالانتظار', AppColors.warning),
    };
    return AppPill(label: label, color: color, dense: true);
  }
}
