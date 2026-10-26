import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/l10n/field_experience_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/widgets/finance_badges.dart';
import '../../data/models/meter.dart';

/// Meter identity, reading and account balance remain separate scan targets.
class MeterCardWidget extends StatelessWidget {
  const MeterCardWidget({
    required this.meter,
    this.onTap,
    this.onLongPress,
    this.onRecordReading,
    this.onEdit,
    this.onDelete,
    this.selected = false,
    this.selectionMode = false,
    this.onSelectChanged,
    this.presentation = const ListPresentationSettings(
      hiddenFields: {'created_at'},
    ),
    super.key,
  });

  final Meter meter;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;
  final VoidCallback? onRecordReading;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;
  final bool selected;
  final bool selectionMode;
  final ValueChanged<bool?>? onSelectChanged;
  final ListPresentationSettings presentation;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final hasMenu = onEdit != null || onDelete != null;
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 12),
      child: AppCard(
        padding: EdgeInsets.zero,
        borderColor: selected ? scheme.primary : null,
        color: selected ? scheme.primaryContainer : null,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.xl),
          onTap: selectionMode && onSelectChanged != null
              ? () => onSelectChanged!(!selected)
              : onTap,
          onLongPress: onLongPress,
          child: Padding(
            padding: EdgeInsets.all(presentation.compact ? 10 : 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (onSelectChanged != null)
                      Checkbox(
                        value: selected,
                        semanticLabel: FieldExperienceStrings.selectMeter,
                        onChanged: onSelectChanged,
                      )
                    else
                      Container(
                        width: 42,
                        height: 42,
                        decoration: BoxDecoration(
                          color: scheme.primaryContainer,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Icon(
                          Icons.water_drop_outlined,
                          color: scheme.onPrimaryContainer,
                          size: 23,
                        ),
                      ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (presentation.isVisible('subscriber'))
                            Text(
                              meter.subscriberName?.isNotEmpty == true
                                  ? meter.subscriberName!
                                  : FieldExperienceStrings.unknownSubscriber,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          const SizedBox(height: 4),
                          if (presentation.isVisible('code'))
                            Wrap(
                              spacing: 6,
                              runSpacing: 4,
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                Text(
                                  FieldExperienceStrings.meterCode,
                                  style: theme.textTheme.labelSmall,
                                ),
                                LtrNumberText(
                                  meter.uniqueCode,
                                  style: theme.textTheme.labelMedium?.copyWith(
                                    color: scheme.onSurface,
                                  ),
                                ),
                              ],
                            ),
                        ],
                      ),
                    ),
                    if (hasMenu)
                      PopupMenuButton<String>(
                        tooltip: FieldExperienceStrings.meterOptions,
                        onSelected: (value) {
                          if (value == 'edit') onEdit?.call();
                          if (value == 'delete') onDelete?.call();
                        },
                        itemBuilder: (_) => [
                          if (onEdit != null)
                            const PopupMenuItem(
                              value: 'edit',
                              child: Text(FieldExperienceStrings.editMeter),
                            ),
                          if (onDelete != null)
                            PopupMenuItem(
                              value: 'delete',
                              child: Text(
                                FieldExperienceStrings.deleteMeter,
                                style: TextStyle(color: scheme.error),
                              ),
                            ),
                        ],
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 12,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (presentation.isVisible('status'))
                      _MeterStatus(isActive: meter.isActive),
                    if (presentation.isVisible('square') &&
                        meter.squareName?.isNotEmpty == true)
                      _MeterLocation(
                        icon: Icons.location_on_outlined,
                        label: meter.squareName!,
                      ),
                    if (presentation.isVisible('path') &&
                        meter.pathNumber != null)
                      _MeterLocation(
                        icon: Icons.alt_route_rounded,
                        label: FieldExperienceStrings.path,
                        number: '${meter.pathNumber}',
                        highlighted: meter.isPathMatch,
                      ),
                  ],
                ),
                if (presentation.isVisible('tenant') &&
                    meter.tenantName?.isNotEmpty == true) ...[
                  const SizedBox(height: 8),
                  Text(
                    FieldExperienceStrings.tenantName(meter.tenantName!),
                    style: theme.textTheme.bodySmall,
                  ),
                ],
                const SizedBox(height: 16),
                if (presentation.isVisible('last_reading') ||
                    presentation.isVisible('balance'))
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: scheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (presentation.isVisible('last_reading'))
                          Expanded(
                            child: _MeterFigure(
                              label: FieldExperienceStrings.lastReading,
                              value: _number(meter.lastReading),
                              prominent: true,
                            ),
                          ),
                        if (presentation.isVisible('last_reading') &&
                            presentation.isVisible('balance'))
                          const SizedBox(width: 16),
                        if (presentation.isVisible('balance'))
                          Expanded(
                            child: _MeterFigure(
                              label: FieldExperienceStrings.balance,
                              value: _number(meter.balance),
                              unit: FieldExperienceStrings.currency,
                              color: meter.balance < 0 ? scheme.error : null,
                            ),
                          ),
                      ],
                    ),
                  ),
                if (presentation.isVisible('created_at') &&
                    meter.createdAt != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    '${ListOptionsStrings.createdAt}: ${DateFormat('yyyy-MM-dd HH:mm', 'en').format(meter.createdAt!)}',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
                if (onRecordReading != null) ...[
                  const SizedBox(height: 12),
                  FilledButton.icon(
                    onPressed: onRecordReading,
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                      padding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 12,
                      ),
                    ),
                    icon: const Icon(Icons.add_chart_rounded, size: 20),
                    label: const Text(FieldExperienceStrings.recordReading),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  static String _number(num value) =>
      NumberFormat('#,##0.##', 'en').format(value);
}

class _MeterFigure extends StatelessWidget {
  const _MeterFigure({
    required this.label,
    required this.value,
    this.unit,
    this.color,
    this.prominent = false,
  });
  final String label;
  final String value;
  final String? unit;
  final Color? color;
  final bool prominent;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.labelSmall),
        const SizedBox(height: 6),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: LtrNumberText(
            value,
            style: theme.textTheme.headlineSmall?.copyWith(
              fontSize: prominent ? 25 : 21,
              fontWeight: FontWeight.w700,
              height: 1.2,
              color: color ?? theme.colorScheme.onSurface,
            ),
          ),
        ),
        if (unit != null) Text(unit!, style: theme.textTheme.labelSmall),
      ],
    );
  }
}

class _MeterStatus extends StatelessWidget {
  const _MeterStatus({required this.isActive});
  final bool isActive;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = isActive ? scheme.primary : scheme.onSurfaceVariant;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(
          isActive
              ? Icons.check_circle_outline_rounded
              : Icons.pause_circle_outline,
          color: color,
          size: 15,
        ),
        const SizedBox(width: 4),
        Text(
          isActive
              ? FieldExperienceStrings.active
              : FieldExperienceStrings.inactive,
          style: Theme.of(context).textTheme.labelSmall?.copyWith(color: color),
        ),
      ],
    );
  }
}

class _MeterLocation extends StatelessWidget {
  const _MeterLocation({
    required this.icon,
    required this.label,
    this.number,
    this.highlighted = false,
  });
  final IconData icon;
  final String label;
  final String? number;
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = highlighted ? scheme.primary : scheme.onSurfaceVariant;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, color: color, size: 15),
        const SizedBox(width: 4),
        Flexible(
          child: Text(
            label,
            style: Theme.of(
              context,
            ).textTheme.labelSmall?.copyWith(color: color),
          ),
        ),
        if (number != null) ...[
          const SizedBox(width: 4),
          LtrNumberText(
            number!,
            style: Theme.of(
              context,
            ).textTheme.labelSmall?.copyWith(color: color),
          ),
        ],
      ],
    );
  }
}
