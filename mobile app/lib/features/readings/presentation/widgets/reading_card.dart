part of '../pages/readings_list_screen.dart';

/// بطاقة القراءة وعناصرها الصغيرة — جزء من مكتبة شاشة قائمة القراءات.

class _ReadingCard extends StatelessWidget {
  const _ReadingCard({
    required this.reading,
    required this.selected,
    required this.selectionMode,
    required this.onTap,
    required this.onLongPress,
    this.onPreviewPhoto,
    this.onEdit,
    this.onDelete,
  });

  final Reading reading;
  final bool selected;
  final bool selectionMode;
  final VoidCallback onTap;
  final VoidCallback onLongPress;
  final VoidCallback? onPreviewPhoto;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final actualDate = reading.actualReadingDate;
    final hasActions =
        !selectionMode && (onPreviewPhoto != null || onEdit != null || onDelete != null);

    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
      child: AppCard(
        padding: EdgeInsets.zero,
        borderColor: selected ? scheme.primary.withValues(alpha: .45) : null,
        color: selected ? scheme.primary.withValues(alpha: .04) : scheme.surface,
        onTap: selectionMode ? onTap : null,
        child: InkWell(
          onLongPress: onLongPress,
          borderRadius: BorderRadius.circular(AppRadius.xl),
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    if (selectionMode) ...<Widget>[
                      SizedBox(
                        width: 26,
                        height: 26,
                        child: Checkbox(
                          value: selected,
                          onChanged: (_) => onTap(),
                        ),
                      ),
                      const SizedBox(width: 6),
                    ],
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          LtrNumberText(
                            reading.meterCode ?? 'عداد ${reading.meterId}',
                            style: theme.textTheme.titleSmall?.copyWith(
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                              letterSpacing: .1,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            '${reading.subscriberName ?? 'مشترك'}'
                            '${reading.tenantName == null ? '' : ' · ${reading.tenantName}'}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: <Widget>[
                        StatusBadge(
                          status: reading.isInvoiced ? 'success' : 'warning',
                          label: reading.isInvoiced ? 'مفوترة' : 'غير مفوترة',
                        ),
                        if (!reading.isSynced)
                          const Padding(
                            padding: EdgeInsets.only(top: 4),
                            child: StatusBadge(
                              status: 'pending',
                              label: 'بانتظار المزامنة',
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 11),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: _ReadingStat(
                        label: 'السابقة',
                        value: _number(reading.previousReading),
                        muted: true,
                      ),
                    ),
                    _Arrow(context: context),
                    Expanded(
                      child: _ReadingStat(
                        label: 'الحالية',
                        value: _number(reading.currentReading),
                      ),
                    ),
                    Container(
                      width: 1,
                      height: 30,
                      margin: const EdgeInsets.symmetric(horizontal: 10),
                      color: scheme.outlineVariant,
                    ),
                    Expanded(
                      child: _ReadingStat(
                        label: 'الاستهلاك',
                        value: _number(reading.consumptionUnits),
                        unit: 'وحدة',
                        accent: true,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: <Widget>[
                    _ReadingTag(
                      icon: Icons.event_rounded,
                      label: _date(actualDate ?? reading.readingDate),
                    ),
                    _ReadingTag(
                      icon: Icons.date_range_rounded,
                      label: '${_date(reading.periodStart)} — ${_date(reading.periodEnd)}',
                      ltr: true,
                    ),
                    if (reading.hasPhoto && onPreviewPhoto != null)
                      _ReadingTag(
                        icon: Icons.photo_camera_rounded,
                        label: 'صورة',
                        onTap: onPreviewPhoto,
                        tinted: true,
                      ),
                  ],
                ),
                if (reading.syncError?.isNotEmpty == true) ...<Widget>[
                  const SizedBox(height: 8),
                  Text(
                    reading.syncError!,
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
                  ),
                ],
                if (hasActions) ...<Widget>[
                  const SizedBox(height: 8),
                  Container(height: 1, color: scheme.outlineVariant),
                  Row(
                    children: <Widget>[
                      if (onPreviewPhoto != null)
                        _CardAction(
                          icon: Icons.photo_outlined,
                          label: 'الصورة',
                          onPressed: onPreviewPhoto,
                        ),
                      if (onEdit != null)
                        _CardAction(
                          icon: Icons.edit_outlined,
                          label: 'تعديل',
                          onPressed: onEdit,
                        ),
                      const Spacer(),
                      if (onDelete != null)
                        _CardAction(
                          icon: Icons.delete_outline_rounded,
                          label: 'حذف',
                          onPressed: onDelete,
                          danger: true,
                        ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Arrow extends StatelessWidget {
  const _Arrow({required this.context});

  final BuildContext context;

  @override
  Widget build(BuildContext _) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: Icon(
          Icons.arrow_back_rounded,
          size: 13,
          color: Theme.of(context).colorScheme.outline,
        ),
      );
}

class _ReadingStat extends StatelessWidget {
  const _ReadingStat({
    required this.label,
    required this.value,
    this.unit,
    this.muted = false,
    this.accent = false,
  });

  final String label;
  final String value;
  final String? unit;
  final bool muted;
  final bool accent;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: theme.textTheme.labelSmall?.copyWith(fontSize: 10)),
        const SizedBox(height: 2),
        Row(
          textDirection: TextDirection.rtl,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: <Widget>[
            Flexible(
              child: LtrNumberText(
                value,
                style: TextStyle(
                  fontSize: accent ? 17 : 15,
                  height: 1.2,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -.2,
                  color: accent
                      ? scheme.primary
                      : (muted ? scheme.onSurfaceVariant : scheme.onSurface),
                  fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                ),
              ),
            ),
            if (unit != null)
              Padding(
                padding: const EdgeInsets.only(right: 0, bottom: 1),
                child: Text(' $unit', style: theme.textTheme.labelSmall?.copyWith(fontSize: 9.5)),
              ),
          ],
        ),
      ],
    );
  }
}

class _ReadingTag extends StatelessWidget {
  const _ReadingTag({
    required this.icon,
    required this.label,
    this.onTap,
    this.ltr = false,
    this.tinted = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool ltr;
  final bool tinted;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final color = tinted ? scheme.primary : scheme.onSurfaceVariant;
    final content = Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(icon, size: 12, color: color),
        const SizedBox(width: 4),
        if (ltr)
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(
              label,
              style: TextStyle(fontSize: 10.5, height: 1.2, fontWeight: FontWeight.w500, color: color),
            ),
          )
        else
          Text(
            label,
            style: TextStyle(fontSize: 10.5, height: 1.2, fontWeight: FontWeight.w500, color: color),
          ),
      ],
    );
    final chip = Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(
          color: tinted ? scheme.primary.withValues(alpha: .28) : scheme.outlineVariant,
        ),
      ),
      child: content,
    );
    if (onTap == null) return chip;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: chip,
    );
  }
}

class _CardAction extends StatelessWidget {
  const _CardAction({
    required this.icon,
    required this.label,
    required this.onPressed,
    this.danger = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return TextButton.icon(
      onPressed: onPressed,
      style: TextButton.styleFrom(
        minimumSize: const Size(0, 32),
        padding: const EdgeInsets.symmetric(horizontal: 8),
        foregroundColor: danger ? scheme.error : scheme.onSurfaceVariant,
        textStyle: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w500),
      ),
      icon: Icon(icon, size: 16),
      label: Text(label),
    );
  }
}


String _date(DateTime value) =>
    '${value.year.toString().padLeft(4, '0')}-'
    '${value.month.toString().padLeft(2, '0')}-'
    '${value.day.toString().padLeft(2, '0')}';

String _number(num value) => value.toStringAsFixed(value % 1 == 0 ? 0 : 2);
