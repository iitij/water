import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../ui/app_tokens.dart';
export 'finance_badges.dart';

class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status, this.label});

  final String status;
  final String? label;

  @override
  Widget build(BuildContext context) {
    final normalized = status.toLowerCase();
    final (text, color) = switch (normalized) {
      'paid' || 'active' || 'connected' || 'success' || 'synced' => (label ?? _label(normalized), AppColors.success),
      'partially_paid' || 'pending' || 'waiting' || 'syncing' || 'queued' => (label ?? _label(normalized), AppColors.warning),
      'overdue' || 'failed' || 'offline' || 'error' => (label ?? _label(normalized), AppColors.danger),
      'issued' || 'qr' || 'info' => (label ?? _label(normalized), AppColors.info),
      _ => (label ?? _label(normalized), AppColors.neutral),
    };

    final tint = AppSemantic.resolve(context, color);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: tint.withValues(alpha: 0.2)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Container(width: 6, height: 6, decoration: BoxDecoration(color: tint, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(text, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w500, color: tint)),
        ],
      ),
    );
  }

  String _label(String value) => switch (value) {
        'paid' => 'مسددة',
        'partially_paid' => 'مسددة جزئياً',
        'issued' => 'صادرة',
        'overdue' => 'متأخرة',
        'active' => 'نشط',
        'connected' => 'متصل',
        'offline' => 'دون اتصال',
        'qr' => 'بانتظار مسح QR',
        'waiting' => 'بانتظار الاتصال',
        'pending' || 'queued' => 'بانتظار المزامنة',
        'syncing' => 'جارٍ الإرسال',
        'synced' || 'success' => 'تمت المزامنة',
        'failed' || 'error' => 'تعذرت المزامنة',
        _ => value,
      };
}
