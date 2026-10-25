import 'package:flutter/material.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/finance_badges.dart';
import '../../data/models/meter.dart';

/// بطاقة عداد — تصميم v3:
///  - اسم المشترك أولاً وكود العداد سطر ثانوي هادئ.
///  - سطر معلومات واحد بلا إطارات (المربع · آخر قراءة · الرصيد).
///  - زر «تسجيل قراءة» أساسي + قائمة خيارات (تعديل/حذف) بدل أيقونات متناثرة.
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
    final hasMenu = onEdit != null || onDelete != null;

    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
      child: AppCard(
        padding: EdgeInsets.zero,
        borderColor: selected ? colorScheme.primary.withValues(alpha: .45) : null,
        color: selected ? colorScheme.primary.withValues(alpha: .04) : null,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.xl),
          onTap: selectionMode && onSelectChanged != null
              ? () => onSelectChanged!(!selected)
              : onTap,
          onLongPress: onLongPress,
          child: Padding(
            padding: const EdgeInsets.all(13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // ── الترويسة: المشترك + الحالة ──
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (onSelectChanged != null) ...[
                      SizedBox(
                        width: 28,
                        height: 28,
                        child: Checkbox(value: selected, onChanged: onSelectChanged),
                      ),
                      const SizedBox(width: 8),
                    ],
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            meter.subscriberName?.isNotEmpty == true
                                ? meter.subscriberName!
                                : 'مشترك غير محدد',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 14.5,
                              fontWeight: FontWeight.w600,
                              height: 1.3,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Row(
                            children: [
                              Flexible(
                                child: LtrNumberText(
                                  meter.uniqueCode,
                                  style: TextStyle(
                                    fontSize: 11.5,
                                    fontWeight: FontWeight.w500,
                                    letterSpacing: .3,
                                    color: colorScheme.onSurfaceVariant,
                                  ),
                                ),
                              ),
                              if (meter.pathNumber != null) ...[
                                const SizedBox(width: 6),
                                Icon(
                                  Icons.alt_route_rounded,
                                  size: 12,
                                  color: meter.isPathMatch
                                      ? colorScheme.primary
                                      : colorScheme.onSurfaceVariant,
                                ),
                                const SizedBox(width: 3),
                                Text(
                                  'مسار ${meter.pathNumber}',
                                  style: TextStyle(
                                    fontSize: 11,
                                    fontWeight:
                                        meter.isPathMatch ? FontWeight.w600 : FontWeight.w500,
                                    color: meter.isPathMatch
                                        ? colorScheme.primary
                                        : colorScheme.onSurfaceVariant,
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    _StatusDot(isActive: meter.isActive),
                  ],
                ),
                if (meter.tenantName?.isNotEmpty == true) ...[
                  const SizedBox(height: 4),
                  Text(
                    'المستأجر: ${meter.tenantName}',
                    style: TextStyle(fontSize: 11, color: colorScheme.onSurfaceVariant),
                  ),
                ],
                const SizedBox(height: 10),

                // ── سطر المعلومات الصامت ──
                Wrap(
                  spacing: 12,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (meter.squareName?.isNotEmpty == true)
                      _QuietMeta(
                        icon: Icons.location_on_outlined,
                        text: meter.squareName!,
                      ),
                    _QuietMeta(
                      icon: Icons.speed_outlined,
                      text: 'آخر قراءة ${_number(meter.lastReading)}',
                      ltr: true,
                    ),
                    _QuietMeta(
                      icon: Icons.account_balance_wallet_outlined,
                      text: 'الرصيد ${_number(meter.balance)} ريال',
                      ltr: true,
                      tone: meter.balance < 0 ? AppColors.danger : AppColors.success,
                    ),
                  ],
                ),

                // ── الإجراءات ──
                if (onRecordReading != null || hasMenu) ...[
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      if (onRecordReading != null)
                        Expanded(
                          child: SizedBox(
                            height: 36,
                            child: FilledButton.tonalIcon(
                              onPressed: onRecordReading,
                              style: FilledButton.styleFrom(
                                padding: const EdgeInsets.symmetric(horizontal: 10),
                                minimumSize: const Size(0, 36),
                                textStyle: const TextStyle(
                                  fontSize: 12.5,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              icon: const Icon(Icons.add_chart_outlined, size: 16),
                              label: const Text('تسجيل قراءة'),
                            ),
                          ),
                        )
                      else
                        const Spacer(),
                      if (hasMenu) ...[
                        const SizedBox(width: 4),
                        PopupMenuButton<String>(
                          tooltip: 'خيارات العداد',
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(AppRadius.lg),
                          ),
                          onSelected: (value) {
                            if (value == 'edit') onEdit?.call();
                            if (value == 'delete') onDelete?.call();
                          },
                          itemBuilder: (context) => [
                            if (onEdit != null)
                              const PopupMenuItem(
                                value: 'edit',
                                child: Row(
                                  children: [
                                    Icon(Icons.edit_outlined, size: 18),
                                    SizedBox(width: 10),
                                    Text('تعديل العداد'),
                                  ],
                                ),
                              ),
                            if (onDelete != null)
                              const PopupMenuItem(
                                value: 'delete',
                                child: Row(
                                  children: [
                                    Icon(Icons.delete_outline, size: 18, color: AppColors.danger),
                                    SizedBox(width: 10),
                                    Text('حذف العداد', style: TextStyle(color: AppColors.danger)),
                                  ],
                                ),
                              ),
                          ],
                          icon: Icon(
                            Icons.more_vert_rounded,
                            size: 20,
                            color: colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
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

  static String _number(num value) => value.toStringAsFixed(
        value % 1 == 0 ? 0 : 2,
      );
}

/// حالة العداد كنقطة ملوّنة + كلمة — بلا صندوق مُحيّط.
class _StatusDot extends StatelessWidget {
  const _StatusDot({required this.isActive});

  final bool isActive;

  @override
  Widget build(BuildContext context) {
    final color = isActive ? AppColors.success : AppColors.neutral;
    final resolved = AppSemantic.resolve(context, color);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: AppTint.soft(color, context),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 6,
            height: 6,
            decoration: BoxDecoration(color: resolved, shape: BoxShape.circle),
          ),
          const SizedBox(width: 5),
          Text(
            isActive ? 'نشط' : 'غير نشط',
            style: TextStyle(
              color: resolved,
              fontSize: 10.5,
              height: 1.2,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

/// عنصر معلومات صامت — أيقونة + نص بلا إطار.
class _QuietMeta extends StatelessWidget {
  const _QuietMeta({required this.icon, required this.text, this.tone, this.ltr = false});

  final IconData icon;
  final String text;
  final Color? tone;
  final bool ltr;

  @override
  Widget build(BuildContext context) {
    final color =
        tone == null ? Theme.of(context).colorScheme.onSurfaceVariant : AppSemantic.resolve(context, tone!);
    final style = TextStyle(
      fontSize: 11,
      fontWeight: FontWeight.w500,
      color: color,
      height: 1.3,
    );
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 13, color: color),
        const SizedBox(width: 4),
        if (ltr)
          Directionality(textDirection: TextDirection.ltr, child: Text(text, style: style))
        else
          Text(text, style: style),
      ],
    );
  }
}
