import 'package:flutter/material.dart';

import '../ui/app_controls.dart';

/// زر موحّد: الإجراء الأساسي بلون التأكيد المسطّح، والثانوي بحد رفيع.
class AppButton extends StatelessWidget {
  const AppButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expanded = true,
    this.kind = AppButtonKind.primary,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;
  final bool expanded;
  final AppButtonKind kind;

  @override
  Widget build(BuildContext context) {
    if (kind == AppButtonKind.outline) {
      return AppSecondaryButton(
        label: label,
        icon: icon,
        onPressed: loading ? null : onPressed,
        expand: expanded,
        height: 48,
      );
    }
    return AppPrimaryButton(
      label: label,
      icon: icon,
      loading: loading,
      onPressed: onPressed,
      expand: expanded,
      height: 48,
    );
  }
}

enum AppButtonKind { primary, tonal, outline }
