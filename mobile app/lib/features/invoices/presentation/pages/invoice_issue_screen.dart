import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/router/finance_route_names.dart';

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
      (title: 'فاتورة وايت', description: 'فوترة طلب مياه مكتمل',
        icon: Icons.local_shipping_outlined, route: FinanceRouteNames.invoiceGenerateTanker),
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
                for (final option in options) Card(
                  elevation: 0,
                  margin: const EdgeInsets.only(bottom: 12),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(18),
                    side: BorderSide(color: Theme.of(context).dividerColor),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: ListTile(
                    contentPadding: const EdgeInsets.all(16),
                    leading: Icon(option.icon, color: Theme.of(context).colorScheme.primary, size: 28),
                    title: Text(option.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(option.description),
                    trailing: const Icon(Icons.arrow_forward_ios_rounded, size: 16),
                    onTap: () => context.push(option.route),
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
