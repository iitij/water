import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/dashboard_entities.dart';

typedef DashboardInvoiceCallback = void Function(DashboardInvoice invoice);
typedef DashboardPaymentLauncher = Future<bool> Function(
  BuildContext context,
  DashboardInvoice invoice,
);
typedef DashboardReminderLauncher = Future<void> Function(
  BuildContext context,
  DashboardInvoice invoice,
);

class LatestInvoicesSection extends StatelessWidget {
  const LatestInvoicesSection({
    required this.invoices,
    this.onOpenInvoice,
    super.key,
  });

  final List<DashboardInvoice> invoices;
  final DashboardInvoiceCallback? onOpenInvoice;

  @override
  Widget build(BuildContext context) {
    final visible = invoices.take(5).toList(growable: false);
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SectionHeading(
            title: 'أحدث الفواتير',
            subtitle: visible.isEmpty ? null : 'آخر ${visible.length} حركة على الحسابات',
            icon: Icons.receipt_long_outlined,
          ),
          const Gap(AppSpacing.sm),
          if (visible.isEmpty)
            const AdminEmptyView(
              title: 'لا توجد فواتير بعد',
              message: 'ستظهر أحدث الفواتير هنا فور إصدارها.',
              icon: Icons.receipt_long_outlined,
            )
          else
            for (var index = 0; index < visible.length; index++) ...<Widget>[
              _InvoiceTile(
                invoice: visible[index],
                onTap: onOpenInvoice == null ? null : () => onOpenInvoice!(visible[index]),
              ),
              if (index != visible.length - 1)
                Divider(height: 18, color: Theme.of(context).colorScheme.outlineVariant.withValues(alpha: .5)),
            ],
        ],
      ),
    );
  }
}

class OverdueInvoicesSection extends StatelessWidget {
  const OverdueInvoicesSection({
    required this.invoices,
    required this.onCollectPayment,
    this.onSendReminder,
    this.onOpenInvoice,
    this.isRefreshing = false,
    super.key,
  });

  final List<DashboardInvoice> invoices;
  final DashboardPaymentLauncher? onCollectPayment;
  final DashboardReminderLauncher? onSendReminder;
  final DashboardInvoiceCallback? onOpenInvoice;
  final bool isRefreshing;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SectionHeading(
            title: 'الفواتير المتأخرة',
            subtitle: invoices.isEmpty ? null : 'مرتبة من الأقدم إلى الأحدث',
            icon: Icons.notification_important_outlined,
            trailing: isRefreshing
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : (invoices.isEmpty
                    ? null
                    : AppPill(
                        label: '${invoices.length}',
                        color: AppColors.danger,
                        dense: true,
                      )),
          ),
          const Gap(AppSpacing.sm),
          if (invoices.isEmpty)
            Container(
              decoration: BoxDecoration(
                color: AppColors.success.withValues(alpha: .07),
                borderRadius: BorderRadius.circular(AppRadius.lg),
                border: Border.all(color: AppColors.success.withValues(alpha: .16)),
              ),
              child: const AdminEmptyView(
                title: 'لا توجد فواتير متأخرة 🎉',
                message: 'جميع الفواتير مسددة أو في حالة جيدة',
                icon: Icons.celebration_rounded,
                color: AdminPalette.success,
              ),
            )
          else
            for (final invoice in invoices)
              _OverdueTile(
                invoice: invoice,
                onOpen: onOpenInvoice == null ? null : () => onOpenInvoice!(invoice),
                onCollect: onCollectPayment == null
                    ? null
                    : () => onCollectPayment!(context, invoice),
                onRemind: onSendReminder == null
                    ? null
                    : () => onSendReminder!(context, invoice),
              ),
        ],
      ),
    );
  }
}

class _InvoiceTile extends StatelessWidget {
  const _InvoiceTile({required this.invoice, this.onTap});

  final DashboardInvoice invoice;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = _statusColor(invoice.status);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 7, horizontal: 2),
        child: Row(
          children: <Widget>[
            AppIconBubble(icon: Icons.receipt_outlined, color: color, size: 40),
            const SizedBox(width: 11),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      Flexible(
                        child: Directionality(
                          textDirection: TextDirection.ltr,
                          child: Text(
                            invoice.billNumber,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      AdminStatusBadge(label: invoice.statusLabel, color: color),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(
                    '${invoice.subscriberName} • ${AdminFormat.date(invoice.billDate)}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: <Widget>[
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(
                    AdminFormat.money(invoice.totalAmount),
                    maxLines: 1,
                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
                  ),
                ),
                Text('الإجمالي', style: theme.textTheme.labelSmall),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _OverdueTile extends StatelessWidget {
  const _OverdueTile({
    required this.invoice,
    this.onCollect,
    this.onOpen,
    this.onRemind,
  });

  final DashboardInvoice invoice;
  final Future<bool> Function()? onCollect;
  final Future<void> Function()? onRemind;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    var busy = false;
    return StatefulBuilder(
      builder: (context, setState) {
        Future<void> collect() async {
          if (busy) return;
          setState(() => busy = true);
          try {
            await onCollect!();
          } finally {
            if (context.mounted) setState(() => busy = false);
          }
        }

        return Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              InkWell(
                onTap: onOpen,
                borderRadius: BorderRadius.circular(AppRadius.md),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    const AppIconBubble(
                      icon: Icons.schedule_rounded,
                      color: AppColors.danger,
                      size: 40,
                    ),
                    const SizedBox(width: 11),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Directionality(
                            textDirection: TextDirection.ltr,
                            child: Text(
                              invoice.billNumber,
                              style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            invoice.subscriberName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodyMedium,
                          ),
                          const SizedBox(height: 2),
                          Text(
                            '${AdminFormat.date(invoice.billDate)} • المسدد ${AdminFormat.money(invoice.amountPaid)}',
                            maxLines: 2,
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: <Widget>[
                        Directionality(
                          textDirection: TextDirection.ltr,
                          child: Text(
                            AdminFormat.money(invoice.remainingAmount),
                            style: theme.textTheme.titleSmall?.copyWith(
                              color: AppColors.danger,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                        Text('المتبقي المطلوب', style: theme.textTheme.labelSmall),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 11),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: <Widget>[
                  if (onCollect != null)
                    FilledButton.icon(
                      onPressed: busy ? null : collect,
                      style: FilledButton.styleFrom(
                        minimumSize: const Size(0, 40),
                        padding: const EdgeInsets.symmetric(horizontal: 14),
                        textStyle: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
                      ),
                      icon: busy
                          ? const SizedBox(
                              width: 15,
                              height: 15,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : const Icon(Icons.payments_outlined, size: 17),
                      label: const Text('تحصيل فوري'),
                    ),
                  if (onRemind != null)
                    OutlinedButton.icon(
                      onPressed: busy ? null : onRemind,
                      style: OutlinedButton.styleFrom(
                        minimumSize: const Size(0, 40),
                        padding: const EdgeInsets.symmetric(horizontal: 13),
                        textStyle: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700),
                      ),
                      icon: const Icon(Icons.chat_outlined, size: 16),
                      label: const Text('تذكير واتساب'),
                    ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }
}

Color _statusColor(String status) => switch (status) {
      'paid' => AdminPalette.success,
      'partially_paid' => AdminPalette.warning,
      'overdue' => AdminPalette.danger,
      'issued' => AdminPalette.info,
      _ => AdminPalette.neutral,
    };
