import 'package:flutter/material.dart';

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
    return AdminCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const AdminSectionHeader(
            title: 'أحدث 5 فواتير',
            icon: Icons.receipt_long_outlined,
          ),
          const SizedBox(height: 12),
          if (visible.isEmpty)
            const AdminEmptyView(title: 'لا توجد فواتير بعد')
          else
            ...visible.map(
              (invoice) => _InvoiceTile(
                invoice: invoice,
                onTap: onOpenInvoice == null ? null : () => onOpenInvoice!(invoice),
              ),
            ),
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
    return AdminCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AdminSectionHeader(
            title: '⚠️ الفواتير المتأخرة',
            subtitle: invoices.isEmpty ? null : 'مرتبة من الأقدم إلى الأحدث',
            icon: Icons.notification_important_outlined,
            trailing: isRefreshing
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : null,
          ),
          const SizedBox(height: 12),
          if (invoices.isEmpty)
            Container(
              decoration: BoxDecoration(
                color: AdminPalette.success.withAlpha(18),
                borderRadius: BorderRadius.circular(16),
              ),
              child: const AdminEmptyView(
                title: 'لا توجد فواتير متأخرة 🎉',
                message: 'جميع الفواتير مسددة أو في حالة جيدة',
                icon: Icons.celebration_rounded,
                color: AdminPalette.success,
              ),
            )
          else
            ...invoices.map(
              (invoice) => _OverdueTile(
                invoice: invoice,
                onOpen: onOpenInvoice == null ? null : () => onOpenInvoice!(invoice),
                onCollect: onCollectPayment == null
                    ? null
                    : () => onCollectPayment!(context, invoice),
                onRemind: onSendReminder == null
                    ? null
                    : () => onSendReminder!(context, invoice),
              ),
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
    return ListTile(
      onTap: onTap,
      contentPadding: const EdgeInsets.symmetric(vertical: 4),
      leading: CircleAvatar(
        backgroundColor: _statusColor(invoice.status).withAlpha(22),
        child: Icon(Icons.receipt_outlined, color: _statusColor(invoice.status)),
      ),
      title: Row(
        children: [
          Expanded(
            child: LtrText(
              invoice.billNumber,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
          const SizedBox(width: 8),
          AdminStatusBadge(label: invoice.statusLabel, color: _statusColor(invoice.status)),
        ],
      ),
      subtitle: Text(
        '${invoice.subscriberName} • ${AdminFormat.date(invoice.billDate)}',
        maxLines: 2,
      ),
      trailing: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 130),
        child: LtrText(
          AdminFormat.money(invoice.totalAmount),
          style: const TextStyle(fontWeight: FontWeight.w800),
          textAlign: TextAlign.end,
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
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AdminPalette.danger.withAlpha(10),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AdminPalette.danger.withAlpha(35)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              InkWell(
                onTap: onOpen,
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          LtrText(
                            invoice.billNumber,
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                          const SizedBox(height: 4),
                          Text(invoice.subscriberName),
                          Text(
                            '${AdminFormat.date(invoice.billDate)} • المسدد ${AdminFormat.money(invoice.amountPaid)}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        LtrText(
                          AdminFormat.money(invoice.remainingAmount),
                          style: const TextStyle(
                            color: AdminPalette.danger,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        const Text('المتبقي المطلوب'),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  if (onCollect != null)
                    FilledButton.icon(
                      onPressed: busy ? null : collect,
                      icon: busy
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.payments_outlined),
                      label: const Text('تحصيل فوري'),
                    ),
                  if (onRemind != null)
                    OutlinedButton.icon(
                      onPressed: busy ? null : onRemind,
                      icon: const Icon(Icons.chat_outlined),
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
