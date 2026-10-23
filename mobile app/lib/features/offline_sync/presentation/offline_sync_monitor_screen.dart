import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../core/responsive/breakpoints.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/empty_state_widget.dart';
import '../../../core/widgets/stat_card.dart';
import '../../../core/widgets/status_badge.dart';
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
        final width = MediaQuery.sizeOf(context).width;
        final hPadding = width < 360 ? 14.0 : 22.0;
        final online = manager.isOnline;
        return SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(hPadding, 18, hPadding, 32),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: AppBreakpoints.contentMax),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _SyncHero(online: online, isSyncing: manager.isSyncing),
                  const SizedBox(height: 16),
                  LayoutBuilder(builder: (context, constraints) {
                    final stats = <Widget>[
                      StatCard(title: 'إجمالي العمليات', value: manager.pendingCount.toString(), icon: Icons.pending_actions_rounded, color: AppColors.info),
                      StatCard(title: 'تحتاج إلى إعادة محاولة', value: manager.failedCount.toString(), icon: Icons.error_outline_rounded, color: AppColors.danger),
                    ];
                    if (constraints.maxWidth < 520) {
                      return Column(children: stats.map((card) => Padding(padding: const EdgeInsets.only(bottom: 10), child: card)).toList());
                    }
                    return Row(children: <Widget>[
                      Expanded(child: stats[0]),
                      const SizedBox(width: 12),
                      Expanded(child: stats[1]),
                    ]);
                  }),
                  const SizedBox(height: 15),
                  if (online == false) const _OfflineNotice(),
                  if (manager.items.isNotEmpty) ...<Widget>[
                    Row(children: <Widget>[
                      Expanded(child: Text('قائمة العمليات', style: theme.textTheme.titleLarge)),
                      if (manager.failedCount > 0)
                        TextButton.icon(onPressed: manager.isSyncing ? null : manager.retryFailed, icon: const Icon(Icons.replay_rounded, size: 18), label: const Text('إعادة الفاشلة')),
                    ]),
                    const SizedBox(height: 10),
                    ...manager.items.map((item) => Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: _QueueItemCard(
                            item: item,
                            isSyncing: manager.isSyncing,
                            onRetry: () => manager.retry(item.id),
                          ),
                        )),
                  ] else ...<Widget>[
                    const SizedBox(height: 24),
                    const EmptyStateWidget(
                      icon: Icons.cloud_done_outlined,
                      title: 'لا توجد عمليات معلّقة',
                      message: 'ستظهر هنا القراءات والمدفوعات التي تُحفظ أوفلاين من وحدات الميدان، مع حالتها وعدد محاولات المزامنة.',
                    ),
                  ],
                  const SizedBox(height: 16),
                  AppButton(
                    label: manager.isSyncing ? 'جارٍ مزامنة العمليات…' : 'مزامنة الآن',
                    icon: manager.isSyncing ? null : Icons.sync_rounded,
                    loading: manager.isSyncing,
                    onPressed: manager.isSyncing || manager.items.isEmpty ? null : manager.syncPending,
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'ترسل القراءات إلى /api/readings/sync-batch، والمدفوعات إلى /api/invoices/pay. لكل عملية معرّف فريد يمنع تكرارها عند إعادة المحاولة.',
                    style: theme.textTheme.bodySmall,
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _SyncHero extends StatelessWidget {
  const _SyncHero({required this.online, required this.isSyncing});

  final bool? online;
  final bool isSyncing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final statusText = isSyncing ? 'جارٍ إرسال العمليات إلى الخادم' : online == true ? 'الاتصال متاح' : online == false ? 'أنت تعمل دون اتصال' : 'جارٍ فحص الشبكة';
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        gradient: const LinearGradient(begin: AlignmentDirectional.topStart, end: AlignmentDirectional.bottomEnd, colors: <Color>[Color(0xFF0B2B40), Color(0xFF02687E), Color(0xFF0E7490)]),
      ),
      child: Row(children: <Widget>[
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: <Widget>[
          Text('كل عملية محفوظة', style: theme.textTheme.headlineMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          Text('تابع القراءات والمدفوعات المحلية وأعد إرسال ما تعذر عند عودة الشبكة.', style: theme.textTheme.bodyMedium?.copyWith(color: Colors.white.withOpacity(.79), height: 1.55)),
          const SizedBox(height: 15),
          Row(mainAxisSize: MainAxisSize.min, children: <Widget>[
            Icon(online == true ? Icons.wifi_rounded : Icons.wifi_off_rounded, color: Colors.white, size: 18),
            const SizedBox(width: 7),
            Flexible(child: Text(statusText, style: theme.textTheme.bodyMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w700))),
          ]),
        ])),
        const SizedBox(width: 12),
        Container(width: 62, height: 62, decoration: BoxDecoration(color: Colors.white.withOpacity(.13), borderRadius: BorderRadius.circular(21)), child: Icon(isSyncing ? Icons.cloud_sync_rounded : Icons.cloud_queue_rounded, color: Colors.white, size: 31)),
      ]),
    );
  }
}

class _OfflineNotice extends StatelessWidget {
  const _OfflineNotice();

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: const Color(0xFFFFFBEB), borderRadius: BorderRadius.circular(16), border: Border.all(color: const Color(0xFFFDE68A))),
        child: const Row(crossAxisAlignment: CrossAxisAlignment.start, children: <Widget>[
          Icon(Icons.info_outline_rounded, color: AppColors.warning, size: 20),
          SizedBox(width: 9),
          Expanded(child: Text('لا يوجد اتصال حالياً. العمليات آمنة على الجهاز وستتم إعادة المحاولة تلقائياً عند عودة الشبكة.', style: TextStyle(color: Color(0xFF92400E), height: 1.45))),
        ]),
      );
}

class _QueueItemCard extends StatelessWidget {
  const _QueueItemCard({required this.item, required this.isSyncing, required this.onRetry});

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
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: theme.colorScheme.surface, borderRadius: BorderRadius.circular(20), border: Border.all(color: theme.dividerColor.withOpacity(.7))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: <Widget>[
        Row(children: <Widget>[
          Container(width: 42, height: 42, decoration: BoxDecoration(color: (isReading ? AppColors.info : AppColors.success).withOpacity(.1), borderRadius: BorderRadius.circular(14)), child: Icon(isReading ? Icons.speed_rounded : Icons.payments_outlined, color: isReading ? AppColors.info : AppColors.success)),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: <Widget>[Text(title, style: theme.textTheme.titleMedium), Text(detail, style: theme.textTheme.bodySmall, maxLines: 2, overflow: TextOverflow.ellipsis)])),
          const SizedBox(width: 8),
          StatusBadge(status: status),
        ]),
        const SizedBox(height: 12),
        Wrap(spacing: 12, runSpacing: 6, children: <Widget>[
          _MetaText(icon: Icons.schedule_rounded, label: dateLabel.length > 16 ? dateLabel.substring(0, 16) : dateLabel),
          _MetaText(icon: Icons.refresh_rounded, label: 'محاولات: ${item.attempts}'),
          _MetaText(icon: Icons.fingerprint_rounded, label: shortId),
        ]),
        if (item.lastError != null && item.lastError!.isNotEmpty) ...<Widget>[
          const SizedBox(height: 10),
          Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: AppColors.danger.withOpacity(.06), borderRadius: BorderRadius.circular(12)), child: Text(item.lastError!, style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger), maxLines: 3, overflow: TextOverflow.ellipsis)),
        ],
        if (item.status == SyncQueueStatus.failed) ...<Widget>[
          const SizedBox(height: 10),
          Align(alignment: AlignmentDirectional.centerEnd, child: TextButton.icon(onPressed: isSyncing ? null : onRetry, icon: const Icon(Icons.replay_rounded, size: 18), label: const Text('إعادة المحاولة'))),
        ],
      ]),
    );
  }
}

class _MetaText extends StatelessWidget {
  const _MetaText({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: <Widget>[
        Icon(icon, size: 14, color: Theme.of(context).hintColor),
        const SizedBox(width: 4),
        Text(label, style: Theme.of(context).textTheme.bodySmall),
      ]);
}
