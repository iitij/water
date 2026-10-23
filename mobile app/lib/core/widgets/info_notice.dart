import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../ui/app_tokens.dart';

/// لافتة معلومات مختصرة تُستخدم داخل البطاقات والأدراج.
///
/// السطح حيادي وحدود رفيعة؛ اللون يظهر على الأيقونة فقط، حتى لا تتحول
/// اللافتة إلى كتلة ملوّنة داخل النموذج.
class InfoNotice extends StatelessWidget {
  const InfoNotice({
    super.key,
    required this.text,
    this.icon = Icons.info_outline,
    this.color,
  });

  final String text;
  final IconData icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tone = AppSemantic.resolve(context, color ?? AppColors.neutral);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.only(top: 1),
            child: Icon(icon, size: 16, color: tone),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: theme.textTheme.bodySmall?.copyWith(height: 1.6),
            ),
          ),
        ],
      ),
    );
  }
}
