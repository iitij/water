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
    this.presentation = const ListPresentationSettings(),
  });

  final Subscriber subscriber;
  final bool selected;
  final VoidCallback onToggleSelect;
  final VoidCallback onTap;
  final void Function(Subscriber) onOpen360;
  final void Function(Subscriber) onEdit;
  final void Function(Subscriber) onDelete;
  final CollectPaymentLauncher? onCollectPayment;
  final ListPresentationSettings presentation;

  @override
  Widget build(BuildContext context) {
    final s = subscriber;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 12),
      child: AppCard(
        padding: EdgeInsets.zero,
        radius: 16,
        borderColor: selected ? scheme.primary : null,
        child: InkWell(
          onTap: onTap,
          onLongPress: onToggleSelect,
          borderRadius: BorderRadius.circular(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: EdgeInsetsDirectional.fromSTEB(
                  12,
                  presentation.compact ? 8 : 14,
                  8,
                  8,
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Semantics(
                      label: s.name,
                      child: Checkbox(
                        value: selected,
                        onChanged: (_) => onToggleSelect(),
                      ),
                    ),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            s.name,
                            style: theme.textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const Gap(5),
                          if (presentation.isVisible('path'))
                            Text(
                              s.pathNumbers.isEmpty
                                  ? SubscriberExperienceStrings.noPath
                                  : SubscriberExperienceStrings.pathsLabel(
                                      s.pathNumbers,
                                    ),
                              style: theme.textTheme.bodySmall,
                            ),
                          if (presentation.isVisible('square') ||
                              presentation.isVisible('meters'))
                            Text(
                              '${presentation.isVisible('square') ? s.squareName ?? SubscriberExperienceStrings.noSquare : ''}'
                              '${presentation.isVisible('meters') ? ' • ${SubscriberExperienceStrings.meterCount(s.metersCount)}' : ''}',
                              style: theme.textTheme.bodySmall,
                            ),
                          if (presentation.isVisible('phone') &&
                              s.phone?.isNotEmpty == true) ...[
                            const Gap(4),
                            Directionality(
                              textDirection: TextDirection.ltr,
                              child: Text(
                                s.phone!,
                                style: theme.textTheme.labelMedium,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    PopupMenuButton<String>(
                      tooltip: SubscriberExperienceStrings.options,
                      onSelected: (value) {
                        if (value == 'edit') {
                          onEdit(s);
                        }
                        if (value == 'delete') {
                          onDelete(s);
                        }
                      },
                      itemBuilder: (_) => const [
                        PopupMenuItem(
                          value: 'edit',
                          child: Text(SubscriberExperienceStrings.edit),
                        ),
                        PopupMenuItem(
                          value: 'delete',
                          child: Text(SubscriberExperienceStrings.delete),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Wrap(
                  spacing: 12,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (presentation.isVisible('status'))
                      StatusBadge(
                        label: s.status.label,
                        color: switch (s.status) {
                          SubscriberStatus.active => AppColors.success,
                          SubscriberStatus.suspended => AppColors.warning,
                          SubscriberStatus.inactive => AppColors.danger,
                        },
                      ),
                    if (presentation.isVisible('balance'))
                      Text(
                        SubscriberExperienceStrings.balance,
                        style: theme.textTheme.labelSmall,
                      ),
                    if (presentation.isVisible('balance'))
                      _SubscriberCardMoney(amount: s.netBalance),
                  ],
                ),
              ),
              const Gap(12),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 6,
                ),
                decoration: BoxDecoration(
                  color: scheme.surfaceContainerHighest.withValues(alpha: .5),
                  border: Border(top: BorderSide(color: scheme.outlineVariant)),
                ),
                child: Wrap(
                  spacing: 6,
                  runSpacing: 2,
                  alignment: WrapAlignment.spaceBetween,
                  children: [
                    TextButton.icon(
                      icon: const Icon(Icons.payments_outlined, size: 18),
                      label: const Text(SubscriberExperienceStrings.collect),
                      onPressed: () => _collect(context),
                    ),
                    TextButton.icon(
                      icon: const Icon(Icons.folder_open_outlined, size: 18),
                      label: const Text(SubscriberExperienceStrings.details),
                      onPressed: () => onOpen360(s),
                    ),
                    IconButton(
                      icon: const Icon(Icons.local_shipping_outlined, size: 20),
                      tooltip: SubscriberExperienceStrings.tanker,
                      onPressed: () => _quickTanker(context),
                    ),
                  ],
                ),
              ),
            ],
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
    final amountCtrl = TextEditingController(
      text: defaultPrice.toStringAsFixed(0),
    );
    final notesCtrl = TextEditingController();
    var serviceDate = DateTime.now();
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text('طلب وايت سريع — ${subscriber.name}'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
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
                  if (picked != null) {
                    setDialogState(() => serviceDate = picked);
                  }
                },
              ),
              const SizedBox(height: 8),
              TextField(
                controller: notesCtrl,
                decoration: const InputDecoration(labelText: 'ملاحظات'),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: () async {
                final amount = double.tryParse(
                  normalizeDigits(amountCtrl.text),
                );
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
            RecordLocationLabel(
              location: RecordLocation(
                squareId: subscriber.squareId,
                squareName: subscriber.squareName,
                paths: subscriber.pathNumbers,
              ),
            ),
            const Gap(4),
            Text('ملخص سريع لملف المشترك', style: theme.textTheme.bodySmall),
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
    if (square.id == state.squareId) return square.displayName;
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

class _SubscriberCardMoney extends StatelessWidget {
  const _SubscriberCardMoney({required this.amount});
  final double amount;
  @override
  Widget build(BuildContext context) {
    final color = amount < 0
        ? AppSemantic.resolve(context, AppColors.danger)
        : amount > 0
        ? AppSemantic.resolve(context, AppColors.success)
        : Theme.of(context).colorScheme.onSurface;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text(
            CurrencyFormatter.format(
              amount == 0 ? 0 : amount,
            ).replaceAll(' ريال', ''),
            style: TextStyle(
              color: color,
              fontWeight: FontWeight.w700,
              fontSize: 19,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ),
        const Gap(5),
        Text(
          SubscriberExperienceStrings.currency,
          style: TextStyle(color: color, fontSize: 12),
        ),
      ],
    );
  }
}
