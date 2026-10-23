import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../domain/workspace_catalog.dart';

Color workspaceColor(WorkspaceGroup group) => switch (group) {
  WorkspaceGroup.customers => AppColors.info,
  WorkspaceGroup.field => AppColors.primary,
  WorkspaceGroup.finance => AppColors.success,
  WorkspaceGroup.management => AppColors.violet,
  WorkspaceGroup.settings => AppColors.warning,
  WorkspaceGroup.account => AppColors.neutral,
};

class WorkspaceTile extends StatelessWidget {
  const WorkspaceTile({super.key, required this.entry, required this.onTap});
  final WorkspaceEntry entry;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppCard(
      padding: EdgeInsets.zero,
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(14, 12, 10, 12),
        child: Row(
          children: <Widget>[
            AppGlyph(icon: entry.icon, tone: workspaceColor(entry.group), size: 40),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    entry.title,
                    style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w500),
                  ),
                  const SizedBox(height: 1),
                  Text(
                    entry.description,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.labelSmall,
                  ),
                ],
              ),
            ),
            Icon(
              Icons.chevron_left_rounded,
              size: 18,
              color: theme.colorScheme.onSurfaceVariant.withValues(alpha: .7),
            ),
          ],
        ),
      ),
    );
  }
}

/// Wrap rather than fixed-height GridView: cards grow with accessibility text.
class WorkspaceShortcutGrid extends StatelessWidget {
  const WorkspaceShortcutGrid({super.key, required this.entries, required this.onNavigate});
  final List<WorkspaceEntry> entries;
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      final largeText = MediaQuery.textScalerOf(context).scale(16) > 23;
      final columns = constraints.maxWidth >= 840 ? 4 :
          constraints.maxWidth >= 340 && !largeText ? 2 : 1;
      final width = (constraints.maxWidth - (columns - 1) * 12) / columns;
      return Wrap(
        spacing: 12,
        runSpacing: 12,
        children: [for (final entry in entries) SizedBox(
          width: width,
          child: _ShortcutCard(entry: entry, onTap: () => onNavigate(entry.route)),
        )],
      );
    },
  );
}

class _ShortcutCard extends StatelessWidget {
  const _ShortcutCard({required this.entry, required this.onTap});
  final WorkspaceEntry entry;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppCard(
      padding: EdgeInsets.zero,
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(14, 14, 14, 13),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            AppGlyph(icon: entry.icon, tone: workspaceColor(entry.group), size: 36),
            const SizedBox(height: 12),
            Text(
              entry.title,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w500),
            ),
            const SizedBox(height: 3),
            Text(
              entry.description,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.labelSmall?.copyWith(height: 1.5),
            ),
          ],
        ),
      ),
    );
  }
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
              Icon(Icons.search_rounded, size: 19, color: scheme.onSurfaceVariant),
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
