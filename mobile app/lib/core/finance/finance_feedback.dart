/// عناصر تغذية راجعة مشتركة للوحدة المالية (حوار التأكيد، السناكبار، حالات القائمة، الهياكل).
///
/// ملكية Dev 3: نواة Dev 1 تقدّم `showConfirmDialog(...)` و`EmptyStateWidget(...)` و
/// `ShimmerSkeleton(...)`؛ نسخنا هنا تحافظ على واجهة Dev 3 (حوار بـ `details:`، حالة خطأ بـ
/// `onRetry:`، و`SkeletonList`) كي تبقى الشاشات كما هي. الدمج النهائي يمكن أن يوحّدها.
library;
import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';

import '../theme/app_colors.dart';
import '../ui/app_tokens.dart';
import '../widgets/empty_state_widget.dart' as core;

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
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.xl)),
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

/// غلاف متوافق مع نسخة Dev 3: يعرض عنصر الحالة الفارغة الموحّد في `core/widgets`.
class EmptyStateWidget extends StatelessWidget {
  const EmptyStateWidget({
    super.key,
    required this.title,
    this.message,
    this.icon = Icons.inbox_outlined,
    this.actionLabel,
    this.onAction,
    this.compact = false,
  });

  final String title;
  final String? message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return core.EmptyStateWidget(
      title: title,
      message: message ?? '',
      icon: icon,
      compact: compact,
      action: (actionLabel != null && onAction != null)
          ? TextButton.icon(
              onPressed: onAction,
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: Text(actionLabel!),
            )
          : null,
    );
  }
}

/// خطأ قابل لإعادة المحاولة.
class ErrorStateWidget extends StatelessWidget {
  const ErrorStateWidget({
    super.key,
    required this.message,
    this.onRetry,
    this.icon = Icons.wifi_off_rounded,
  });

  final String message;
  final VoidCallback? onRetry;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return EmptyStateWidget(
      title: 'تعذّر تحميل البيانات',
      message: message,
      icon: icon,
      actionLabel: onRetry == null ? null : 'إعادة المحاولة',
      onAction: onRetry,
    );
  }
}

class SkeletonBox extends StatelessWidget {
  const SkeletonBox({
    super.key,
    this.height = 16,
    this.width,
    this.radius = AppRadius.xs,
  });

  final double height;
  final double? width;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: height,
      width: width,
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.outlineVariant,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

/// بطاقة هيكل تحميل بحجم بطاقة الفاتورة/السند.
class SkeletonCard extends StatelessWidget {
  const SkeletonCard({super.key, this.lines = 3, this.dense = false});

  final int lines;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: Colors.transparent,
      highlightColor: Colors.white24,
      enabled: true,
      child: Container(
        padding: EdgeInsets.all(dense ? 10 : 14),
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          borderRadius: BorderRadius.circular(AppRadius.xl),
          border: Border.all(color: AppColors.lightBorder),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            const Row(
              children: <Widget>[
                SkeletonBox(height: 34, width: 34, radius: 12),
                SizedBox(width: 10),
                Expanded(child: SkeletonBox(height: 14, width: double.infinity)),
                SizedBox(width: 8),
                SkeletonBox(height: 20, width: 64, radius: 999),
              ],
            ),
            const SizedBox(height: 12),
            for (var i = 0; i < lines; i++) ...<Widget>[
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: SkeletonBox(
                  height: 12,
                  width: i.isEven ? double.infinity : 160,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// قائمة هياكل تحميل جاهزة للشاشات القائمة.
class SkeletonList extends StatelessWidget {
  const SkeletonList({super.key, this.count = 6, this.lines = 3});

  final int count;
  final int lines;

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      padding: const EdgeInsets.all(12),
      physics: const NeverScrollableScrollPhysics(),
      itemCount: count,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (_, __) => SkeletonCard(lines: lines),
    );
  }
}
