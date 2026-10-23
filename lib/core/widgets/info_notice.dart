import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// لافتة معلومات مختصرة تُستخدم داخل البطاقات والأدراج.
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
    final tone = color ?? AppColors.neutral;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: tone.withValues(alpha: 0.2)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icon, size: 18, color: tone),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(fontSize: 12, height: 1.5, color: tone),
            ),
          ),
        ],
      ),
    );
  }
}
