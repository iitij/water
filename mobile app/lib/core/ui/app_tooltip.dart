import 'package:flutter/material.dart';

/// Keeps each tooltip portal attached to its own accessibility node.
///
/// Flutter can merge adjacent portal anchors inside scrollable content and
/// discard one of their traversal identifiers. The Windows accessibility bridge
/// then rejects the orphaned overlay node. A boundary outside the entire
/// tooltip prevents that merge while preserving its label and visual overlay.
class AppTooltip extends StatelessWidget {
  const AppTooltip({super.key, required this.message, required this.child});

  final String message;
  final Widget child;

  @override
  Widget build(BuildContext context) => Semantics(
    container: true,
    child: Tooltip(message: message, child: child),
  );
}
