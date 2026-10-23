import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// حوار تأكيد موحّد (حذف فاتورة/سند، إعادة احتساب جماعي، حذف نهائي للتعرفة).
class ConfirmDialog extends StatelessWidget {
  const ConfirmDialog({
    super.key,
    required this.title,
    required this.message,
    this.confirmLabel = 'تأكيد',
    this.cancelLabel = 'إلغاء',
    this.destructive = false,
    this.details,
  });

  final String title;
  final String message;
  final String confirmLabel;
  final String cancelLabel;
  final bool destructive;

  /// أسطر توضيحية (مثال: «سيتم فك ارتباط 3 قراءات وإعادة توزيع الدفعات»).
  final List<String>? details;

  static Future<bool> show(
    BuildContext context, {
    required String title,
    required String message,
    String confirmLabel = 'تأكيد',
    bool destructive = false,
    List<String>? details,
  }) async {
    final result = await showDialog<bool>(
      context: context,
      builder: (_) => ConfirmDialog(
        title: title,
        message: message,
        confirmLabel: confirmLabel,
        destructive: destructive,
        details: details,
      ),
    );
    return result ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final color = destructive ? AppColors.danger : AppColors.primary;
    return AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      title: Row(
        children: <Widget>[
          Icon(destructive ? Icons.warning_amber_rounded : Icons.help_outline,
              color: color),
          const SizedBox(width: 8),
          Expanded(child: Text(title)),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(message, style: Theme.of(context).textTheme.bodyLarge),
          if (details != null && details!.isNotEmpty) ...<Widget>[
            const SizedBox(height: 12),
            ...details!.map(
              (d) => Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    const Text('• '),
                    Expanded(child: Text(d)),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
      actions: <Widget>[
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: Text(cancelLabel),
        ),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: color),
          onPressed: () => Navigator.of(context).pop(true),
          child: Text(confirmLabel),
        ),
      ],
    );
  }
}

/// سنيackbar موحّد للنتائج (نجاح/فشل) — يفصل منطق العرض عن الشاشات.
class AppFeedback {
  AppFeedback._();

  static void success(BuildContext context, String message) =>
      _show(context, message, AppColors.success, Icons.check_circle_outline);

  static void error(BuildContext context, String message) =>
      _show(context, message, AppColors.danger, Icons.error_outline);

  static void info(BuildContext context, String message) =>
      _show(context, message, AppColors.info, Icons.info_outline);

  static void _show(
    BuildContext context,
    String message,
    Color color,
    IconData icon,
  ) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          backgroundColor: color,
          duration: const Duration(seconds: 3),
          content: Row(
            children: <Widget>[
              Icon(icon, color: Colors.white),
              const SizedBox(width: 10),
              Expanded(child: Text(message)),
            ],
          ),
        ),
      );
  }
}
