import 'dart:ui';

import 'package:flutter/material.dart';

class AquaGlassCard extends StatelessWidget {
  const AquaGlassCard({super.key, required this.child, this.padding = const EdgeInsets.all(26)});

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return ClipRRect(
      borderRadius: BorderRadius.circular(28),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 14, sigmaY: 14),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            color: (dark ? const Color(0xFF142338) : Colors.white).withValues(alpha: .93),
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: Colors.white.withValues(alpha: dark ? .12 : .72)),
            boxShadow: <BoxShadow>[
              BoxShadow(color: const Color(0xFF0B2B40).withValues(alpha: .12), blurRadius: 38, offset: const Offset(0, 18)),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}
