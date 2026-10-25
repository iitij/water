part of '../pages/subscribers_screen.dart';

class _SubscriberCard extends StatelessWidget {
  const _SubscriberCard({
    required this.subscriber,
    required this.selected,
    required this.onToggleSelect,
    required this.onTap,
    required this.onOpen360,
    required this.onEdit,
    required this.onDelete,
    this.onCollectPayment,
  });

  final Subscriber subscriber;
  final bool selected;
  final VoidCallback onToggleSelect;
  final VoidCallback onTap;
  final void Function(Subscriber) onOpen360;
  final void Function(Subscriber) onEdit;
  final void Function(Subscriber) onDelete;
  final CollectPaymentLauncher? onCollectPayment;

  @override
  Widget build(BuildContext context) {
    final s = subscriber;
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
      child: AppCard(
        padding: EdgeInsets.zero,
        color: selected ? scheme.primary.withValues(alpha: .04) : null,
        borderColor: selected ? scheme.primary.withValues(alpha: .4) : null,
        onTap: onTap,
        child: InkWell(
        onLongPress: onToggleSelect,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Checkbox(
                  value: selected,
                  onChanged: (_) => onToggleSelect(),
                  visualDensity: VisualDensity.compact,
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 14.5,
                          height: 1.3,
                          color: scheme.onSurface,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Flexible(
                            child: Text(
                              '${s.squareName ?? 'بدون مربع'} · ${s.metersCount} عداد',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                          ),
                          if (s.phone != null) ...[
                            const SizedBox(width: 8),
                            Icon(Icons.call_outlined, size: 12, color: scheme.onSurfaceVariant),
                            const SizedBox(width: 3),
                            Directionality(
                              textDirection: TextDirection.ltr,
                              child: Text(
                                s.phone!,
                                style: TextStyle(
                                  fontSize: 11,
                                  color: scheme.onSurfaceVariant,
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 6),
                StatusBadge(
                  label: s.status.label,
                  color: switch (s.status) {
                    SubscriberStatus.active => AppColors.success,
                    SubscriberStatus.suspended => AppColors.warning,
                    SubscriberStatus.inactive => AppColors.danger,
                  },
                ),
                PopupMenuButton<String>(
                  tooltip: 'خيارات المشترك',
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                  ),
                  onSelected: (value) {
                    if (value == 'edit') onEdit(s);
                    if (value == 'delete') onDelete(s);
                  },
                  itemBuilder: (context) => [
                    const PopupMenuItem(
                      value: 'edit',
                      child: Row(
                        children: [
                          Icon(Icons.edit_outlined, size: 18),
                          SizedBox(width: 10),
                          Text('تعديل المشترك'),
                        ],
                      ),
                    ),
                    const PopupMenuItem(
                      value: 'delete',
                      child: Row(
                        children: [
                          Icon(Icons.delete_outline, size: 18, color: AppColors.danger),
                          SizedBox(width: 10),
                          Text('حذف المشترك', style: TextStyle(color: AppColors.danger)),
                        ],
                      ),
                    ),
                  ],
                  icon: Icon(
                    Icons.more_vert_rounded,
                    size: 20,
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: scheme.surfaceContainerHighest.withValues(alpha: .45),
                borderRadius: BorderRadius.circular(AppRadius.lg),
              ),
              child: Row(children: [
                Text('الرصيد الصافي', style: Theme.of(context).textTheme.labelSmall),
                const SizedBox(width: 6),
                BalanceText(s.netBalance),
                const Spacer(),
                if (s.debitBalance > 0)
                  Text(
                    'مدين ${CurrencyFormatter.format(s.debitBalance)}',
                    style: TextStyle(
                      color: AppSemantic.resolve(context, AppColors.danger),
                      fontSize: 11,
                      fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
                    ),
                  ),
              ]),
            ),
            const SizedBox(height: 6),
            Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              TextButton.icon(
                icon: Icon(Icons.payments_rounded, size: 17, color: AppSemantic.resolve(context, AppColors.success)),
                label: const Text('تحصيل دفعة'),
                onPressed: () => _collect(context),
              ),
              TextButton.icon(
                icon: Icon(Icons.local_shipping_outlined, size: 17, color: AppSemantic.resolve(context, AppColors.info)),
                label: const Text('طلب وايت'),
                onPressed: () => _quickTanker(context),
              ),
              TextButton.icon(
                icon: Icon(Icons.folder_open_rounded, size: 17, color: scheme.primary),
                label: const Text('ملف 360°'),
                onPressed: () => onOpen360(s),
              ),
            ]),
          ]),
        ),
        ),
      ),
    );
  }

  Future<void> _collect(BuildContext context) async {
    if (onCollectPayment != null) return onCollectPayment!(context, subscriber);
    final cubit = context.read<SubscribersCubit>();
    final saved = await launchDefaultSubscriberPayment(
      context,
      subscriber,
      fallbackCollect: (amount) => cubit.collectPayment(subscriber.id, amount),
    );
    if (saved && context.mounted) {
      await cubit.load();
    }
  }

  /// طلب وايت سريع: السعر الافتراضي = آخر طلب للمشترك أو 5000 + اختيار تاريخ الخدمة كما في Filament.
  Future<void> _quickTanker(BuildContext context) async {
    final cubit = context.read<SubscribersCubit>();
    final requestsCubit = context.read<ServiceRequestsCubit>();
    final defaultPrice = await cubit.quickTankerDefaultPrice(subscriber.id);
    if (!context.mounted) return;
    final amountCtrl = TextEditingController(text: defaultPrice.toStringAsFixed(0));
    final notesCtrl = TextEditingController();
    var serviceDate = DateTime.now();
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text('طلب وايت سريع — ${subscriber.name}'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(
              controller: amountCtrl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'سعر الوايت (ريال)',
              ),
            ),
            const SizedBox(height: 8),
            AppSettingRow(
              icon: Icons.calendar_month_rounded,
              color: Theme.of(ctx).colorScheme.primary,
              title: 'تاريخ الخدمة',
              subtitle: serviceDate.toIso8601String().substring(0, 10),
              onTap: () async {
                final picked = await showDatePicker(
                  context: ctx,
                  initialDate: serviceDate,
                  firstDate: DateTime(2020),
                  lastDate: DateTime(2100),
                );
                if (picked != null) setDialogState(() => serviceDate = picked);
              },
            ),
            const SizedBox(height: 8),
            TextField(
              controller: notesCtrl,
              decoration: const InputDecoration(
                labelText: 'ملاحظات',
              ),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
            FilledButton(
              onPressed: () async {
                final amount = double.tryParse(normalizeDigits(amountCtrl.text));
                if (amount == null || amount <= 0) return;
                await requestsCubit.create(
                  subscriberId: subscriber.id,
                  amount: amount,
                  serviceDate: serviceDate.toIso8601String().substring(0, 10),
                  notes: notesCtrl.text.isEmpty ? null : notesCtrl.text,
                );
                if (ctx.mounted) {
                  Navigator.pop(ctx);
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('تم إضافة طلب الوايت بنجاح')),
                  );
                }
              },
              child: const Text('إضافة الطلب'),
            ),
          ],
        ),
      ),
    );
  }
}

class _SubscriberPeek extends StatelessWidget {
  const _SubscriberPeek({
    required this.subscriber,
    required this.onOpen360,
    required this.onEdit,
    required this.onDelete,
  });

  final Subscriber subscriber;
  final void Function(Subscriber) onOpen360;
  final void Function(Subscriber) onEdit;
  final void Function(Subscriber) onDelete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.all(20),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(subscriber.name, style: theme.textTheme.headlineSmall),
            const Gap(4),
            Text(
              'ملخص سريع لملف المشترك',
              style: theme.textTheme.bodySmall,
            ),
            const Gap(14),
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  AppField(
                    label: 'المربع',
                    value: subscriber.squareName ?? '-',
                    icon: Icons.grid_view_rounded,
                  ),
                  const Gap(10),
                  AppField(
                    label: 'الهاتف',
                    value: subscriber.phone ?? '-',
                    icon: Icons.call_outlined,
                    ltr: subscriber.phone != null,
                  ),
                  const Gap(10),
                  AppField(
                    label: 'العنوان',
                    value: subscriber.address ?? '-',
                    icon: Icons.location_on_outlined,
                  ),
                ],
              ),
            ),
            const Gap(12),
            AppCard(
              child: Row(
                children: [
                  Text('الرصيد الصافي', style: theme.textTheme.labelSmall),
                  const Spacer(),
                  BalanceText(subscriber.netBalance, fontSize: 18),
                ],
              ),
            ),
            const Gap(16),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                AppPrimaryButton(
                  label: 'فتح الملف المالي الشامل 360°',
                  icon: Icons.folder_open_rounded,
                  expand: false,
                  onPressed: () => onOpen360(subscriber),
                ),
                AppSecondaryButton(
                  label: 'تعديل المشترك',
                  icon: Icons.edit_outlined,
                  expand: false,
                  onPressed: () => onEdit(subscriber),
                ),
                AppIconButton(
                  icon: Icons.delete_outline,
                  tooltip: 'حذف المشترك',
                  size: 38,
                  onPressed: () => onDelete(subscriber),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

String? _squareName(SubscribersState state) {
  if (state.squareId == null) return null;
  for (final square in state.squares) {
    if (square.id == state.squareId) return square.name;
  }
  return 'مربع #${state.squareId}';
}

Future<void> _openFilters(
  BuildContext context,
  SubscribersState state,
  SubscribersCubit cubit,
) {
  return AppFilterSheet.show<void>(
    context: context,
    builder: (_) => _SubscribersFilterSheet(state: state, cubit: cubit),
  );
}

/// تصفية المشتركين — مسودة واحدة تُطبَّق مرة واحدة من تذييل الورقة.
