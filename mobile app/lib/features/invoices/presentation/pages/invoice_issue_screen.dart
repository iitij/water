import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/router/finance_route_names.dart';
import '../../../../core/ui/app_surfaces.dart';

/// A small, explicit first step instead of dropping users into a manual form.
/// Reuses the existing Laravel-backed generation engines without duplicating
/// billing logic or submitting anything until the user confirms its form.
class InvoiceIssueScreen extends StatelessWidget {
  const InvoiceIssueScreen({super.key});

  @override
  Widget build(BuildContext context) {
    const options = [
      (title: 'فاتورة عداد', description: 'إصدار فاتورة من القراءات المسجلة',
        icon: Icons.speed_rounded, route: FinanceRouteNames.invoiceGenerateMeter),
      (title: 'فاتورة اشتراك', description: 'فوترة رسوم الاشتراك والأقساط',
        icon: Icons.card_membership_outlined, route: FinanceRouteNames.invoiceGenerateSubscription),
      (title: 'فاتورة يدوية', description: 'تحديد المبالغ وإصدار الفاتورة يدوياً',
        icon: Icons.edit_note_rounded, route: FinanceRouteNames.invoiceCreate),
    ];
    return Scaffold(
      appBar: AppBar(title: const Text('إصدار فاتورة')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('اختر نوع الفاتورة', style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 8),
                const Text('ستراجع البيانات والمبلغ قبل الإصدار.'),
                const SizedBox(height: 24),
                for (final option in options)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: AppCard(
                    padding: EdgeInsets.zero,
                    child: ListTile(
                    contentPadding: const EdgeInsetsDirectional.fromSTEB(14, 14, 14, 14),
                    leading: AppGlyph(icon: option.icon, size: 44),
                    title: Text(option.title, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
                    subtitle: Text(option.description, style: const TextStyle(fontSize: 12.5)),
                    trailing: Icon(
                      Icons.chevron_left_rounded,
                      size: 20,
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                    onTap: () => context.push(option.route),
                    ),
                  ),
                  ),
              ],
            ),
          ),
        )],
      ),
    );
  }
}
