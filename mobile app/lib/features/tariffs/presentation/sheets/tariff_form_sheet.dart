import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_fields.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../data/models/tariff_model.dart';
import '../cubit/tariffs_cubit.dart';

/// نموذج تسعيرة (`SCR-21` → `TariffResource::form`): الاسم، سعر الوحدة،
/// التفعيل، نطاق التاريخ، والشرائح الاختيارية (`min_usage`/`max_usage`).
///
/// ملاحظة Dev 3: جدول `tariffs` الحقيقي يعرّف `unit_price` + تواريخ سريان فقط؛
/// الحقول `min_usage`/`max_usage` اختيارية لدعم التسعير الشرائحي الموصوف في
/// `FLUTTER_MOBILE_MASTER_PLAN.md` §2.2 دون كسر العقد الحالي.
class TariffFormSheet extends StatefulWidget {
  const TariffFormSheet({super.key, this.tariff});

  /// `null` = إنشاء تسعيرة جديدة.
  final TariffModel? tariff;

  static Future<TariffModel?> show(BuildContext context, {TariffModel? tariff}) {
    return showModalBottomSheet<TariffModel>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => BlocProvider<TariffsCubit>.value(
        value: context.read<TariffsCubit>(),
        child: TariffFormSheet(tariff: tariff),
      ),
    );
  }

  @override
  State<TariffFormSheet> createState() => _TariffFormSheetState();
}

class _TariffFormSheetState extends State<TariffFormSheet> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();

  late final TextEditingController _name =
      TextEditingController(text: widget.tariff?.name ?? 'التسعيرة الأساسية');
  late final TextEditingController _price = TextEditingController(
    text: (widget.tariff?.unitPrice ?? 0).toString(),
  );
  late final TextEditingController _minUsage =
      TextEditingController(text: _num(widget.tariff?.minUsage));
  late final TextEditingController _maxUsage =
      TextEditingController(text: _num(widget.tariff?.maxUsage));

  late bool _isActive = widget.tariff?.isActive ?? true;
  late DateTime? _start = widget.tariff?.startDate;
  late DateTime? _end = widget.tariff?.endDate;

  static String _num(double? value) => value == null ? '' : value.toString();

  @override
  void dispose() {
    _name.dispose();
    _price.dispose();
    _minUsage.dispose();
    _maxUsage.dispose();
    super.dispose();
  }

  Future<void> _pickDate({required bool isStart}) async {
    final now = DateTime.now();
    final initial = isStart ? (_start ?? now) : (_end ?? now.add(const Duration(days: 365)));
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(now.year - 5),
      lastDate: DateTime(now.year + 5),
    );
    if (picked == null) return;
    setState(() {
      if (isStart) {
        _start = picked;
      } else {
        _end = picked;
      }
    });
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final price = double.tryParse(_price.text.trim());
    if (price == null || price <= 0) {
      AppFeedback.error(context, 'سعر الوحدة يجب أن يكون أكبر من صفر.');
      return;
    }

    final tariff = TariffModel(
      id: widget.tariff?.id ?? 0,
      name: _name.text.trim(),
      unitPrice: price,
      isActive: _isActive,
      startDate: _start,
      endDate: _end,
      minUsage: double.tryParse(_minUsage.text.trim()),
      maxUsage: double.tryParse(_maxUsage.text.trim()),
      createdAt: widget.tariff?.createdAt,
      deletedAt: widget.tariff?.deletedAt,
    );

    final cubit = context.read<TariffsCubit>();
    final saved = await cubit.save(tariff, isNew: widget.tariff == null);
    if (!mounted) return;
    if (!saved) {
      AppFeedback.error(context, cubit.state.errorMessage ?? 'فشل الحفظ.');
      return;
    }
    Navigator.of(context).pop(tariff);
  }

  @override
  Widget build(BuildContext context) {
    final saving = context.select<TariffsCubit, bool>((c) => c.state.saving);

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.8,
      builder: (sheetContext, controller) => ColoredBox(
        color: Theme.of(sheetContext).scaffoldBackgroundColor,
        child: Column(
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 8, 6),
              child: Row(
                children: <Widget>[
                  const AppGlyph(icon: Icons.sell_outlined, tone: AppColors.primary, size: 36),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      widget.tariff == null ? 'تسعيرة جديدة' : 'تعديل التسعيرة',
                      style: Theme.of(sheetContext).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                  ),
                  AppIconButton(
                    tooltip: 'إغلاق',
                    size: 38,
                    onPressed: () => Navigator.of(sheetContext).pop(),
                    icon: Icons.close_rounded,
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: Form(
                key: _formKey,
                child: ListView(
                  controller: controller,
                  padding: const EdgeInsets.all(16),
                  children: <Widget>[
                    AppTextField(
                      controller: _name,
                      label: 'اسم التسعيرة',
                      required: true,
                      validator: (value) =>
                          value == null || value.trim().isEmpty ? 'الاسم مطلوب' : null,
                    ),
                    const SizedBox(height: 12),
                    AppNumericField(
                      controller: _price,
                      label: 'سعر الوحدة',
                      prefixText: 'ريال / وحدة',
                      required: true,
                      helperText: 'يُستخدم في فوترة الاستهلاك تلقائياً (BillingService)',
                    ),
                    const SizedBox(height: 8),
                    InkWell(
                      onTap: () => setState(() => _isActive = !_isActive),
                      borderRadius: BorderRadius.circular(AppRadius.md),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Row(
                          children: <Widget>[
                            const Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                mainAxisSize: MainAxisSize.min,
                                children: <Widget>[
                                  Text('مفعّلة', style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600)),
                                  SizedBox(height: 1),
                                  Text(
                                    'تسعيرة واحدة فقط تكون نشطة للسعر الافتراضي',
                                    style: TextStyle(fontSize: 11, color: AppColors.neutral),
                                  ),
                                ],
                              ),
                            ),
                            Switch.adaptive(
                              value: _isActive,
                              onChanged: (value) => setState(() => _isActive = value),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Row(
                      children: <Widget>[
                        Expanded(
                          child: _DateChip(
                            label: 'تبدأ',
                            date: _start,
                            onTap: () => _pickDate(isStart: true),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: _DateChip(
                            label: 'تنتهي',
                            date: _end,
                            onTap: () => _pickDate(isStart: false),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    const Text(
                      'شرائح استهلاك اختيارية',
                      style: TextStyle(fontSize: 12, color: AppColors.neutral),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: <Widget>[
                        Expanded(
                          child: AppNumericField(
                            controller: _minUsage,
                            label: 'من (وحدة)',
                            decimal: false,
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: AppNumericField(
                            controller: _maxUsage,
                            label: 'إلى (وحدة)',
                            decimal: false,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 18),
                    AppPrimaryButton(
                      label: widget.tariff == null ? 'إضافة التسعيرة' : 'حفظ التعديلات',
                      icon: Icons.save_outlined,
                      loading: saving,
                      onPressed: saving ? null : _submit,
                    ),
                    const SizedBox(height: 12),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DateChip extends StatelessWidget {
  const _DateChip({required this.label, required this.date, required this.onTap});

  final String label;
  final DateTime? date;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: InputDecorator(
        decoration: InputDecoration(labelText: label),
        child: Row(
          children: <Widget>[
            const Icon(Icons.event_outlined, size: 16),
            const SizedBox(width: 6),
            Text(date == null ? 'بدون' : DateFormat.day(date)),
          ],
        ),
      ),
    );
  }
}

/// بطاقة تسعيرة مختصرة داخل `SCR-21`.
class TariffCard extends StatelessWidget {
  const TariffCard({
    super.key,
    required this.tariff,
    required this.isActive,
    this.busy = false,
    this.onTap,
    this.onSoftDelete,
    this.onRestore,
    this.onForceDelete,
    this.selected = false,
    this.onSelectToggle,
  });

  final TariffModel tariff;
  final bool isActive;
  final bool busy;
  final VoidCallback? onTap;

  /// وضع التحديد الجماعي: يظهر مربع اختيار ويُستبدل سلوك النقر بالتحديد.
  final bool selected;
  final VoidCallback? onSelectToggle;
  final VoidCallback? onSoftDelete;
  final VoidCallback? onRestore;
  final VoidCallback? onForceDelete;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: AppCard(
        padding: EdgeInsets.zero,
        onTap: onSelectToggle ?? (tariff.isTrashed ? null : onTap),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: <Widget>[
              if (onSelectToggle != null) ...<Widget>[
                Icon(
                  selected ? Icons.check_box_rounded : Icons.check_box_outline_blank_rounded,
                  size: 20,
                  color: selected ? AppColors.primary : AppColors.neutral,
                ),
                const SizedBox(width: 10),
              ],
              AppGlyph(
                icon: isActive ? Icons.bolt_rounded : Icons.sell_outlined,
                tone: isActive ? AppColors.success : AppColors.neutral,
                size: 40,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      tariff.name,
                      style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${tariff.periodLabel}'
                      '${tariff.minUsage == null ? '' : ' • ${CurrencyFormatter.amount(tariff.minUsage)}-${CurrencyFormatter.amount(tariff.maxUsage)} وحدة'}',
                      style: const TextStyle(fontSize: 11, color: AppColors.neutral),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: <Widget>[
                  Text(
                    CurrencyFormatter.withLabel(tariff.unitPrice),
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 4),
                  if (busy)
                    const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  else if (tariff.isTrashed)
                    PopupMenuButton<String>(
                      tooltip: 'خيارات',
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(AppRadius.md),
                      ),
                      constraints: const BoxConstraints(minWidth: 44, minHeight: 38),
                      onSelected: (value) {
                        if (value == 'restore') onRestore?.call();
                        if (value == 'force') onForceDelete?.call();
                      },
                      itemBuilder: (context) => <PopupMenuEntry<String>>[
                        const PopupMenuItem<String>(
                          value: 'restore',
                          child: Text('استعادة'),
                        ),
                        const PopupMenuItem<String>(
                          value: 'force',
                          child: Text('حذف نهائي'),
                        ),
                      ],
                      icon: const Icon(
                        Icons.more_horiz_rounded,
                        size: 18,
                        color: AppColors.neutral,
                      ),
                    )
                  else
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text(
                          tariff.isActive ? 'مفعّلة' : 'غير مفعّلة',
                          style: TextStyle(
                            fontSize: 11,
                            color: tariff.isActive ? AppColors.success : AppColors.neutral,
                          ),
                        ),
                        if (onSoftDelete != null)
                          PopupMenuButton<String>(
                            tooltip: 'خيارات',
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(AppRadius.md),
                            ),
                            constraints: const BoxConstraints(minWidth: 44, minHeight: 38),
                            onSelected: (value) {
                              if (value == 'trash') onSoftDelete?.call();
                            },
                            itemBuilder: (context) => <PopupMenuEntry<String>>[
                              const PopupMenuItem<String>(
                                value: 'trash',
                                child: Text('نقل إلى سلة المحذوفات'),
                              ),
                            ],
                            icon: const Icon(
                              Icons.more_horiz_rounded,
                              size: 18,
                              color: AppColors.neutral,
                            ),
                          ),
                      ],
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
