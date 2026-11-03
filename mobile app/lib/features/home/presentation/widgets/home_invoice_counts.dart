import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/l10n/invoice_summary_strings.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';

/// Opens the same status groups counted by the dashboard summary.
class HomeInvoiceCounts extends StatelessWidget {
  const HomeInvoiceCounts({
    super.key,
    required this.unpaid,
    required this.overdue,
    required this.canViewInvoices,
  });

  final int unpaid;
  final int overdue;
  final bool canViewInvoices;

  @override
  Widget build(BuildContext context) {
    const style = TextStyle(
      color: AppColors.onPanelMuted,
      fontSize: 10.5,
      height: 1.4,
    );
    if (!canViewInvoices) {
      return Text(
        '${InvoiceSummaryStrings.unpaidCount(unpaid)} · ${InvoiceSummaryStrings.overdueCount(overdue)}',
        style: style,
      );
    }

    Widget link(String label, String tooltip, String status) => Tooltip(
      message: tooltip,
      child: TextButton(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.onPanelMuted,
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 4),
          textStyle: style.copyWith(decoration: TextDecoration.underline),
        ),
        onPressed: () => context.push(
          Uri(
            path: RouteNames.invoices,
            queryParameters: {'status': status},
          ).toString(),
        ),
        child: Text(label),
      ),
    );

    return Wrap(
      spacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        link(
          InvoiceSummaryStrings.unpaidCount(unpaid),
          InvoiceSummaryStrings.showUnpaid,
          'unpaid',
        ),
        link(
          InvoiceSummaryStrings.overdueCount(overdue),
          InvoiceSummaryStrings.showOverdue,
          'overdue',
        ),
      ],
    );
  }
}
