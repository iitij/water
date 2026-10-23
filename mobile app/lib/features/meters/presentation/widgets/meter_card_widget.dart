import 'package:flutter/material.dart';

import '../../data/models/meter.dart';

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

  @override
  Widget build(BuildContext context) {
    final colorScheme = Theme.of(context).colorScheme;
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: InkWell(
        onTap: selectionMode && onSelectChanged != null
            ? () => onSelectChanged!(!selected)
            : onTap,
        onLongPress: onLongPress,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Wrap(
                alignment: WrapAlignment.spaceBetween,
                crossAxisAlignment: WrapCrossAlignment.center,
                spacing: 8,
                runSpacing: 8,
                children: [
                  Wrap(
                    spacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      if (onSelectChanged != null)
                        SizedBox(
                          width: 28,
                          height: 28,
                          child: Checkbox(
                            value: selected,
                            onChanged: onSelectChanged,
                          ),
                        ),
                      if (meter.pathNumber != null)
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                          decoration: BoxDecoration(
                            color: meter.isPathMatch
                                ? colorScheme.primary
                                : colorScheme.primaryContainer,
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Text(
                            'مسار #${meter.pathNumber}',
                            style: TextStyle(
                              color: meter.isPathMatch
                                  ? colorScheme.onPrimary
                                  : colorScheme.onPrimaryContainer,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      _StatusChip(isActive: meter.isActive),
                    ],
                  ),
                  Directionality(
                    textDirection: TextDirection.ltr,
                    child: Text(
                      meter.uniqueCode,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                            letterSpacing: .2,
                          ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                meter.subscriberName?.isNotEmpty == true
                    ? meter.subscriberName!
                    : 'مشترك غير محدد',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
              ),
              if (meter.tenantName?.isNotEmpty == true) ...[
                const SizedBox(height: 3),
                Text(
                  'المستأجر: ${meter.tenantName}',
                  style: TextStyle(color: colorScheme.tertiary),
                ),
              ],
              const SizedBox(height: 8),
              Wrap(
                spacing: 16,
                runSpacing: 6,
                children: [
                  if (meter.squareName?.isNotEmpty == true)
                    _InfoText(icon: Icons.location_on_outlined, text: meter.squareName!),
                  if (meter.pathNumber != null)
                    _InfoText(icon: Icons.route_outlined, text: 'المسار ${meter.pathNumber}'),
                  _InfoText(
                    icon: Icons.speed_outlined,
                    text: 'آخر قراءة ${_number(meter.lastReading)}',
                    ltr: true,
                  ),
                  _InfoText(
                    icon: Icons.account_balance_wallet_outlined,
                    text: 'الرصيد ${_number(meter.balance)} ريال',
                    ltr: true,
                  ),
                ],
              ),
              if (onRecordReading != null || onEdit != null || onDelete != null) ...[
                const SizedBox(height: 8),
                const Divider(height: 1),
                Row(
                  children: [
                    if (onRecordReading != null)
                      Expanded(
                        child: TextButton.icon(
                          onPressed: onRecordReading,
                          icon: const Icon(Icons.add_chart_outlined),
                          label: const Text('تسجيل قراءة'),
                        ),
                      )
                    else
                      const Spacer(),
                    if (onEdit != null)
                      IconButton(
                        tooltip: 'تعديل العداد',
                        onPressed: onEdit,
                        icon: const Icon(Icons.edit_outlined),
                      ),
                    if (onDelete != null)
                      IconButton(
                        tooltip: 'حذف العداد',
                        onPressed: onDelete,
                        icon: const Icon(Icons.delete_outline, color: Colors.redAccent),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  static String _number(num value) => value.toStringAsFixed(
        value % 1 == 0 ? 0 : 2,
      );
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.isActive});

  final bool isActive;

  @override
  Widget build(BuildContext context) {
    final color = isActive ? Colors.green : Colors.grey;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: color.withAlpha(31),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        isActive ? 'نشط' : 'غير نشط',
        style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700),
      ),
    );
  }
}

class _InfoText extends StatelessWidget {
  const _InfoText({required this.icon, required this.text, this.ltr = false});

  final IconData icon;
  final String text;
  final bool ltr;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 16, color: Theme.of(context).colorScheme.primary),
        const SizedBox(width: 4),
        if (ltr)
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(text, style: Theme.of(context).textTheme.bodySmall),
          )
        else
          Text(text, style: Theme.of(context).textTheme.bodySmall),
      ],
    );
  }
}
