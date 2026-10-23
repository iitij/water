import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
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

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withOpacity(.1),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withOpacity(.22)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Container(width: 6, height: 6, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(text, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: color)),
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
