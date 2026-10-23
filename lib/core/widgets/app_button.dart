import 'package:flutter/material.dart';

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
    final child = loading
        ? const SizedBox.square(dimension: 19, child: CircularProgressIndicator(strokeWidth: 2.2))
        : Row(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              if (icon != null) ...<Widget>[Icon(icon, size: 19), const SizedBox(width: 9)],
              Flexible(child: Text(label, overflow: TextOverflow.ellipsis)),
            ],
          );

    final button = switch (kind) {
      AppButtonKind.primary => FilledButton(onPressed: loading ? null : onPressed, child: child),
      AppButtonKind.tonal => FilledButton.tonal(onPressed: loading ? null : onPressed, child: child),
      AppButtonKind.outline => OutlinedButton(onPressed: loading ? null : onPressed, child: child),
    };
    return SizedBox(width: expanded ? double.infinity : null, height: 50, child: button);
  }
}

enum AppButtonKind { primary, tonal, outline }
