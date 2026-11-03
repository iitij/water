import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../domain/workspace_catalog.dart';

Color workspaceColor(WorkspaceGroup group) => switch (group) {
  WorkspaceGroup.customers => AppColors.info,
  WorkspaceGroup.field => AppColors.primary,
  WorkspaceGroup.finance => AppColors.success,
  WorkspaceGroup.management => AppColors.info,
  WorkspaceGroup.settings => AppColors.warning,
  WorkspaceGroup.account => AppColors.neutral,
};

class WorkspaceTile extends StatelessWidget {
  const WorkspaceTile({super.key, required this.entry, required this.onTap});
  final WorkspaceEntry entry;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return AppModuleTile(
      title: entry.title,
      subtitle: entry.description,
      icon: entry.icon,
      color: workspaceColor(entry.group),
      onTap: onTap,
    );
  }
}

/// Wrap rather than fixed-height GridView: cards grow with accessibility text.
class WorkspaceShortcutGrid extends StatelessWidget {
  const WorkspaceShortcutGrid({
    super.key,
    required this.entries,
    required this.onNavigate,
  });
  final List<WorkspaceEntry> entries;
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context) => AppModuleGrid(
    children: [
      for (final entry in entries)
        WorkspaceTile(entry: entry, onTap: () => onNavigate(entry.route)),
    ],
  );
}

class WorkspaceSearchButton extends StatelessWidget {
  const WorkspaceSearchButton({super.key, required this.onTap});
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Material(
      color: scheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.md),
        child: Container(
          height: 46,
          padding: const EdgeInsetsDirectional.fromSTEB(12, 0, 12, 0),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.md),
            border: Border.all(color: scheme.outlineVariant),
          ),
          child: Row(
            children: <Widget>[
              Icon(
                Icons.search_rounded,
                size: 19,
                color: scheme.onSurfaceVariant,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text('ابحث عن خدمة…', style: theme.textTheme.bodySmall),
              ),
              Icon(
                Icons.keyboard_command_key_rounded,
                size: 15,
                color: scheme.onSurfaceVariant.withValues(alpha: .6),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
