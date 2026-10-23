import 'package:flutter/material.dart';
import 'package:intl/intl.dart' hide TextDirection;

import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../theme/admin_palette.dart';

abstract final class AdminFormat {
  static final _number = NumberFormat('#,##0.##', 'en_US');
  static final _money = NumberFormat('#,##0.00', 'en_US');

  static String number(num value) => _number.format(value);
  static String money(num value) => '${_money.format(value)} ريال';

  /// Dense money label for small cards and chart legends: keeps the digits
  /// left-to-right and the Arabic magnitude word separate, e.g. `1.07 مليون`.
  static String compactMoney(num value) {
    if (value.abs() >= 1000000) {
      return '${_number.format(value / 1000000)} مليون';
    }
    if (value.abs() >= 1000) {
      return '${_number.format(value / 1000)} ألف';
    }
    return _money.format(value);
  }

  static String date(DateTime? value) =>
      value == null ? '—' : DateFormat('yyyy-MM-dd', 'en_US').format(value);

  static String dateTime(DateTime? value) => value == null
      ? '—'
      : DateFormat('yyyy-MM-dd HH:mm', 'en_US').format(value.toLocal());
}

class LtrText extends StatelessWidget {
  const LtrText(this.text, {this.style, this.textAlign, super.key});

  final String text;
  final TextStyle? style;
  final TextAlign? textAlign;

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Text(text, style: style, textAlign: textAlign),
    );
  }
}

class AdminCard extends StatelessWidget {
  const AdminCard({
    required this.child,
    this.padding = const EdgeInsets.all(20),
    this.color,
    this.borderColor,
    super.key,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final Color? color;
  final Color? borderColor;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: padding,
      color: color,
      borderColor: borderColor,
      radius: AppRadius.lg,
      child: child,
    );
  }
}

class AdminSectionHeader extends StatelessWidget {
  const AdminSectionHeader({
    required this.title,
    this.subtitle,
    this.icon,
    this.trailing,
    super.key,
  });

  final String title;
  final String? subtitle;
  final IconData? icon;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final titleText = Text(
      title,
      style: Theme.of(context).textTheme.titleLarge?.copyWith(
            fontWeight: FontWeight.w600,
          ),
    );
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (icon != null) ...[
          Icon(icon, color: AdminPalette.primary),
          const SizedBox(width: 10),
        ],
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              titleText,
              if (subtitle != null) ...[
                const SizedBox(height: 4),
                Text(
                  subtitle!,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                ),
              ],
            ],
          ),
        ),
        if (trailing != null) ...[
          const SizedBox(width: 12),
          trailing!,
        ],
      ],
    );
  }
}

class AdminResponsiveGrid extends StatelessWidget {
  const AdminResponsiveGrid({
    required this.children,
    this.minItemWidth = 250,
    this.spacing = 16,
    this.childAspectRatio = 1.5,
    super.key,
  });

  final List<Widget> children;
  final double minItemWidth;
  final double spacing;
  final double childAspectRatio;

  @override
  Widget build(BuildContext context) {
    if (children.isEmpty) return const SizedBox.shrink();
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = (constraints.maxWidth / minItemWidth)
            .floor()
            .clamp(1, children.length)
            .toInt();
        final width = (constraints.maxWidth - spacing * (columns - 1)) / columns;
        return Wrap(
          spacing: spacing,
          runSpacing: spacing,
          children: children
              .map(
                (child) => SizedBox(
                  width: width,
                  height: width / childAspectRatio,
                  child: child,
                ),
              )
              .toList(growable: false),
        );
      },
    );
  }
}

class AdminLoadingView extends StatelessWidget {
  const AdminLoadingView({this.itemCount = 4, super.key});

  final int itemCount;

  @override
  Widget build(BuildContext context) {
    final color = Theme.of(context).colorScheme.surfaceContainerHighest;
    return AdminResponsiveGrid(
      minItemWidth: 240,
      childAspectRatio: 1.4,
      children: List.generate(
        itemCount,
        (_) => AdminCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Skeleton(width: 90, height: 16, color: color),
              const Spacer(),
              _Skeleton(width: 150, height: 30, color: color),
              const SizedBox(height: 12),
              _Skeleton(width: double.infinity, height: 10, color: color),
            ],
          ),
        ),
      ),
    );
  }
}

class _Skeleton extends StatelessWidget {
  const _Skeleton({required this.width, required this.height, required this.color});

  final double width;
  final double height;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(AppRadius.xs),
        ),
      );
}

class AdminErrorView extends StatelessWidget {
  const AdminErrorView({required this.message, this.onRetry, super.key});

  final String message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: AdminCard(
          borderColor: AdminPalette.danger.withAlpha(77),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.cloud_off_rounded, size: 44, color: AdminPalette.danger),
              const SizedBox(height: 12),
              Text(message, textAlign: TextAlign.center),
              if (onRetry != null) ...[
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('إعادة المحاولة'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class AdminEmptyView extends StatelessWidget {
  const AdminEmptyView({
    required this.title,
    this.message,
    this.icon = Icons.inbox_outlined,
    this.color,
    super.key,
  });

  final String title;
  final String? message;
  final IconData icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final effectiveColor = color ?? Theme.of(context).colorScheme.onSurfaceVariant;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 28, horizontal: 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 44, color: effectiveColor),
          const SizedBox(height: 12),
          Text(
            title,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: effectiveColor,
                  fontWeight: FontWeight.w600,
                ),
          ),
          if (message != null) ...[
            const SizedBox(height: 6),
            Text(message!, textAlign: TextAlign.center),
          ],
        ],
      ),
    );
  }
}

class AdminStatusBadge extends StatelessWidget {
  const AdminStatusBadge({required this.label, required this.color, super.key});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: color.withAlpha(31),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        child: Text(
          label,
          style: Theme.of(context).textTheme.labelMedium?.copyWith(
                color: color,
                fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}

String adminErrorMessage(Object error) {
  final text = error.toString();
  return text.startsWith('Exception: ') ? text.substring(11) : text;
}

void showAdminSnackBar(
  BuildContext context,
  String message, {
  bool isError = false,
}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? AdminPalette.danger : AdminPalette.success,
        behavior: SnackBarBehavior.floating,
      ),
    );
}
