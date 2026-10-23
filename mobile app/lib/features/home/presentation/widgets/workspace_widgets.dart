import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../domain/workspace_catalog.dart';

Color workspaceColor(WorkspaceGroup group) => switch (group) {
  WorkspaceGroup.customers => AppColors.info,
  WorkspaceGroup.field => AppColors.primary,
  WorkspaceGroup.finance => AppColors.success,
  WorkspaceGroup.management => const Color(0xFF7C3AED),
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
    final color = workspaceColor(entry.group);
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: theme.dividerColor),
      ),
      clipBehavior: Clip.antiAlias,
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        leading: Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: color.withValues(alpha: .10),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Icon(entry.icon, color: color),
        ),
        title: Text(entry.title, style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(entry.description),
        trailing: const Icon(Icons.arrow_forward_ios_rounded, size: 16),
        onTap: onTap,
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
    final color = workspaceColor(entry.group);
    return Material(
      color: theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: theme.dividerColor),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: color.withValues(alpha: .10),
                    borderRadius: BorderRadius.circular(16)),
                child: Icon(entry.icon, color: color, size: 28),
              ),
              const SizedBox(height: 16),
              Text(entry.title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(entry.description, style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
            ],
          ),
        ),
      ),
    );
  }
}

class WorkspaceSearchButton extends StatelessWidget {
  const WorkspaceSearchButton({super.key, required this.onTap});
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
    style: OutlinedButton.styleFrom(
      alignment: AlignmentDirectional.centerStart,
      padding: const EdgeInsets.all(18),
      foregroundColor: Theme.of(context).colorScheme.onSurfaceVariant,
      side: BorderSide(color: Theme.of(context).dividerColor),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
    ),
    onPressed: onTap,
    icon: const Icon(Icons.search_rounded),
    label: const Text('ابحث عن خدمة…'),
  );
}
