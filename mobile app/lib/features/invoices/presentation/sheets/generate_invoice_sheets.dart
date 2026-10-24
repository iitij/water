import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_colors.dart';
import '../../../../core/finance/finance_fields.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/permissions/finance_permissions.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/widgets/info_notice.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../tariffs/domain/repositories/tariffs_repository.dart';
import '../../data/datasources/invoices_remote_datasource.dart';
import '../../data/datasources/references_remote_datasource.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/repositories/invoices_repository.dart';
import '../cubit/invoice_generation_cubit.dart';
import '../cubit/invoice_generation_state.dart';
import '../pages/invoice_create_edit_screen.dart';
import '../widgets/invoice_actions.dart';

part 'invoice_generate_sheet_body.dart';

/// `SCR-16` — محركات إصدار الفواتير الثلاثة كأدراج سفلية، مطابقةً لـ
/// `InvoiceResource` Header Actions:
///  - عداد (`primary`/آلة حاسبة): من القراءات غير المفوترة + مفتاحا قسط الاشتراك
///    ورسوم الوايت (`generateMeterInvoice` في Laravel يقرأ `has_subscription_fee`
///    و`has_tanker_fee`).
///  - اشتراك (`warning`/وثيقة) ووايت (`info`/شاحنة).
///
/// الحقول كلها حية الحساب (subtotal → credit → net) قبل الإرسال، وتُطابق
/// `InvoiceService::generateInvoice` سطر 62-108، مع التزام قاعدة العزل §3.4-1:
/// فاتورة العداد تخصم من `meters.balance` وغيرُها من `subscribers.balance`.
enum GenerateInvoiceMode { meter, subscription, tanker, manual }

/// قائمة اختيار محرّك الإصدار (تُفتح من FAB في `SCR-14` ومن مسار `/invoices/generate`).
Future<GenerateInvoiceMode?> showGenerateInvoicePicker(BuildContext context) async {
  final selected = await showModalBottomSheet<GenerateInvoiceMode>(
    context: context,
    showDragHandle: true,
    builder: (sheetContext) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 4, 16, 8),
            child: Text(
              'إصدار فاتورة',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
            ),
          ),
          _PickerTile(
            icon: Icons.calculate_outlined,
            color: FinanceColors.meter,
            title: 'فاتورة عداد',
            subtitle: 'من القراءات غير المفوترة + مفاتيح قسط الاشتراك ورسوم الوايت',
            onTap: () => Navigator.of(sheetContext).pop(GenerateInvoiceMode.meter),
          ),
          _PickerTile(
            icon: Icons.assignment_outlined,
            color: FinanceColors.subscription,
            title: 'فاتورة اشتراك',
            subtitle: 'للمشتركين الذين لديهم رسوم اشتراك غير مفوترة',
            onTap: () => Navigator.of(sheetContext).pop(GenerateInvoiceMode.subscription),
          ),
          _PickerTile(
            icon: Icons.local_shipping_outlined,
            color: FinanceColors.tanker,
            title: 'فاتورة وايت',
            subtitle: 'لطلبات الوايت المكتملة غير المفوترة',
            onTap: () => Navigator.of(sheetContext).pop(GenerateInvoiceMode.tanker),
          ),
          if (context.canCreateInvoices)
            _PickerTile(
              icon: Icons.edit_note_outlined,
              color: AppColors.neutral,
              title: 'إنشاء يدوي',
              subtitle: 'إدخال المبالغ يدوياً (يطابق CreateInvoice)',
              onTap: () => Navigator.of(sheetContext).pop(GenerateInvoiceMode.manual),
            ),
          const SizedBox(height: 8),
        ],
      ),
    ),
  );

  if (selected == null || !context.mounted) return null;

  if (selected == GenerateInvoiceMode.manual) {
    await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(builder: (_) => const InvoiceCreateEditScreen()),
    );
    return selected;
  }

  await _openSheet(context, selected);
  return selected;
}

Future<void> _openSheet(BuildContext context, GenerateInvoiceMode mode) {
  if (mode == GenerateInvoiceMode.subscription) {
    return GenerateSubscriptionInvoiceSheet.show(context);
  }
  if (mode == GenerateInvoiceMode.tanker) {
    return GenerateTankerInvoiceSheet.show(context);
  }
  return GenerateMeterInvoiceSheet.show(context);
}

class GenerateMeterInvoiceSheet extends StatelessWidget {
  const GenerateMeterInvoiceSheet({super.key, this.preselectedSubscriberId});

  final String? preselectedSubscriberId;

  static Future<void> show(BuildContext context, {String? preselectedSubscriberId}) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _GenerateSheetHost(
        parentContext: context,
        mode: GenerateInvoiceMode.meter,
        preselectedSubscriberId: preselectedSubscriberId,
      ),
    );
  }

  @override
  Widget build(BuildContext context) => _GenerateSheetHost(
        parentContext: context,
        mode: GenerateInvoiceMode.meter,
        preselectedSubscriberId: preselectedSubscriberId,
      );
}

/// فاتورة اشتراك — يُسلَّم لفؤاد (`SCR-07` لوحة 360°) ولأسامة (`SCR-03`).
class GenerateSubscriptionInvoiceSheet extends StatelessWidget {
  const GenerateSubscriptionInvoiceSheet({super.key, this.preselectedSubscriberId});

  final String? preselectedSubscriberId;

  static Future<void> show(BuildContext context, {String? preselectedSubscriberId}) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _GenerateSheetHost(
        parentContext: context,
        mode: GenerateInvoiceMode.subscription,
        preselectedSubscriberId: preselectedSubscriberId,
      ),
    );
  }

  @override
  Widget build(BuildContext context) => _GenerateSheetHost(
        parentContext: context,
        mode: GenerateInvoiceMode.subscription,
        preselectedSubscriberId: preselectedSubscriberId,
      );
}

/// فاتورة وايت — نفس التسليم أعلاه (طلبات مكتملة غير مفوترة).
class GenerateTankerInvoiceSheet extends StatelessWidget {
  const GenerateTankerInvoiceSheet({super.key, this.preselectedSubscriberId});

  final String? preselectedSubscriberId;

  static Future<void> show(BuildContext context, {String? preselectedSubscriberId}) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _GenerateSheetHost(
        parentContext: context,
        mode: GenerateInvoiceMode.tanker,
        preselectedSubscriberId: preselectedSubscriberId,
      ),
    );
  }

  @override
  Widget build(BuildContext context) => _GenerateSheetHost(
        parentContext: context,
        mode: GenerateInvoiceMode.tanker,
        preselectedSubscriberId: preselectedSubscriberId,
      );
}

class LiveSummaryCard extends StatelessWidget {
  const LiveSummaryCard({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<InvoiceGenerationCubit, InvoiceGenerationState>(
      builder: (context, state) {
        final rows = <(String, String, Color?)>[
          ('استهلاك متوقع', CurrencyFormatter.withLabel(state.consumptionFee), null),
          ('قسط الاشتراك', CurrencyFormatter.withLabel(state.subscriptionInstallment), null),
          ('رسوم الوايت', CurrencyFormatter.withLabel(state.tankerInstallment), null),
          (
            'رصيد دائن يُخصم',
            '- ${CurrencyFormatter.amount(state.creditApplied)}',
            AppColors.success,
          ),
          ('متأخرات محمولة', CurrencyFormatter.withLabel(state.previousBalance), AppColors.warning),
        ];

        return Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.primary.withValues(alpha: 0.05),
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: AppColors.primary.withValues(alpha: 0.15)),
          ),
          child: Column(
            children: <Widget>[
              ...rows.map(
                (row) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(
                    children: <Widget>[
                      Expanded(child: Text(row.$1, style: const TextStyle(fontSize: 12))),
                      Text(
                        row.$2,
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: row.$3,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const Divider(height: 18),
              Row(
                children: <Widget>[
                  const Expanded(
                    child: Text(
                      'صافي الفاتورة',
                      style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                    ),
                  ),
                  Text(
                    CurrencyFormatter.withLabel(state.netTotal),
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: AppColors.danger,
                    ),
                  ),
                ],
              ),
              if (state.totalDue > state.netTotal)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Row(
                    children: <Widget>[
                      const Expanded(
                        child: Text('إجمالي المستحق مع المتأخرات', style: TextStyle(fontSize: 11)),
                      ),
                      Text(
                        CurrencyFormatter.withLabel(state.totalDue),
                        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

/// بعد الإصدار: نتيجة فورية بأزرار PDF/حراري/واتساب (مسار «التالي» في قاعدة النقرتين).
Future<void> _afterGenerated(BuildContext context, InvoiceModel invoice) async {
  final config = sl<FinanceConfigRepository>().cached;

  await showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            const Icon(Icons.check_circle, color: AppColors.success, size: 40),
            const SizedBox(height: 10),
            Text(
              'تم إصدار الفاتورة ${invoice.billNumber}',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 4),
            Text(
              'الصافي ${CurrencyFormatter.withLabel(invoice.netPayable)} — '
              'لصالح ${invoice.beneficiaryName}',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12, color: AppColors.neutral),
            ),
            const SizedBox(height: 18),
            Row(
              children: <Widget>[
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {
                      Navigator.of(sheetContext).pop();
                      openInvoicePdfPreview(context, invoice);
                    },
                    icon: const Icon(Icons.picture_as_pdf_outlined),
                    label: const Text('PDF'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {
                      Navigator.of(sheetContext).pop();
                      printInvoiceThermal(context, invoice);
                    },
                    icon: const Icon(Icons.print_outlined),
                    label: const Text('حراري'),
                  ),
                ),
                if (config.whatsappEnabled) ...<Widget>[
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () {
                        Navigator.of(sheetContext).pop();
                        sendInvoiceWhatsapp(context, invoice);
                      },
                      icon: const Icon(Icons.chat_outlined),
                      label: const Text('واتساب'),
                    ),
                  ),
                ],
              ],
            ),
            const SizedBox(height: 10),
            TextButton(
              onPressed: () => Navigator.of(sheetContext).pop(),
              child: const Text('إغلاق'),
            ),
          ],
        ),
      ),
    ),
  );
}

// ─── الحقول المشتركة ───────────────────────────────────────────────────────

/// بحث المشترك/المستأجر الفوري + شارة التحديد.
class SubscriberSearchField extends StatefulWidget {
  const SubscriberSearchField({super.key});

  @override
  State<SubscriberSearchField> createState() => _SubscriberSearchFieldState();
}

class _SubscriberSearchFieldState extends State<SubscriberSearchField> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<InvoiceGenerationCubit>();

    return BlocBuilder<InvoiceGenerationCubit, InvoiceGenerationState>(
      buildWhen: (previous, next) =>
          previous.selectedSubscriber != next.selectedSubscriber ||
          previous.subscribers != next.subscribers ||
          previous.loadingReferences != next.loadingReferences,
      builder: (context, state) {
        final selected = state.selectedSubscriber;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            AppTextField(
              controller: _controller,
              label: 'المشترك / المستأجر',
              hint: 'اكتب للبحث: اسم، هاتف، أو كود عداد',
              onSubmitted: (value) => cubit.searchSubscribers(value),
              suffix: IconButton(
                icon: const Icon(Icons.search),
                onPressed: () => cubit.searchSubscribers(_controller.text),
              ),
            ),
            if (selected != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Chip(
                  avatar: const Icon(Icons.person_outline, size: 18),
                  label: Text(
                    '${selected.name}'
                    '${selected.squareName == null ? '' : ' • ${selected.squareName}'}',
                  ),
                  onDeleted: cubit.clearSubscriber,
                ),
              )
            else if (state.loadingReferences)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: ShimmerSkeleton(height: 4),
              ),
            if (selected == null && state.subscribers.isNotEmpty)
              _QuickPickRow(
                options: state.subscribers,
                onPick: cubit.selectSubscriber,
              ),
          ],
        );
      },
    );
  }
}

/// رقائق اختيار سريع لنتائج البحث (لمسة واحدة تكفي في الميدان).
class _QuickPickRow extends StatelessWidget {
  const _QuickPickRow({required this.options, required this.onPick});

  final List<SubscriberOption> options;
  final ValueChanged<SubscriberOption> onPick;

  @override
  Widget build(BuildContext context) {
    final visible = options.length > 8 ? options.sublist(0, 8) : options;

    return SizedBox(
      height: 40,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        itemCount: visible.length,
        itemBuilder: (context, index) {
          final option = visible[index];
          return Padding(
            padding: const EdgeInsets.only(left: 8),
            child: AppFilterChip(
              label: option.label,
              onTap: () => onPick(option),
            ),
          );
        },
      ),
    );
  }
}

/// اختيار العداد — إلزامي لفاتورة العداد (يعزل الحساب إلى `meters.balance`).
class MeterPickerField extends StatelessWidget {
  const MeterPickerField({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<InvoiceGenerationCubit, InvoiceGenerationState>(
      buildWhen: (previous, next) =>
          previous.selectedMeter != next.selectedMeter ||
          previous.selectedSubscriber != next.selectedSubscriber,
      builder: (context, state) {
        final meters = state.selectedSubscriber?.meters ?? const <MeterOption>[];
        if (meters.isEmpty) {
          return const InfoNotice(text: 'لا توجد عدادات مسجّلة لهذا المشترك.');
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            const Text('العداد', style: TextStyle(fontSize: 12, color: AppColors.neutral)),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: meters.map((meter) {
                final selected = state.selectedMeter?.id == meter.id;
                return AppFilterChip(
                  label: '${meter.label} — مدين ${CurrencyFormatter.amount(meter.debit)}',
                  selected: selected,
                  onTap: () =>
                      context.read<InvoiceGenerationCubit>().selectMeter(meter),
                );
              }).toList(),
            ),
          ],
        );
      },
    );
  }
}

class IncludeReadingsToggle extends StatelessWidget {
  const IncludeReadingsToggle({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<InvoiceGenerationCubit, InvoiceGenerationState>(
      buildWhen: (previous, next) => previous.includeReadings != next.includeReadings,
      builder: (context, state) => SwitchListTile.adaptive(
        contentPadding: EdgeInsets.zero,
        value: state.includeReadings,
        onChanged: context.read<InvoiceGenerationCubit>().setIncludeReadings,
        title: const Text('تضمين القراءات غير المفوترة', style: TextStyle(fontSize: 13)),
        subtitle: Text(
          state.uninvoicedUnits > 0
              ? '${CurrencyFormatter.amount(state.uninvoicedUnits)} وحدة × '
                  '${CurrencyFormatter.amount(state.unitPrice)}'
              : 'يُحسب الاستهلاك من قراءات وحدة القراءات (Dev 2)',
          style: const TextStyle(fontSize: 11),
        ),
      ),
    );
  }
}

/// مفتاحا `has_subscription_fee` و`has_tanker_fee` مع منع التفعيل عند انعدام الطلبات.
class FeeTogglesField extends StatelessWidget {
  const FeeTogglesField({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<InvoiceGenerationCubit, InvoiceGenerationState>(
      buildWhen: (previous, next) =>
          previous.hasSubscriptionFee != next.hasSubscriptionFee ||
          previous.hasUnpaidSubscriptionFee != next.hasUnpaidSubscriptionFee ||
          previous.hasTankerFee != next.hasTankerFee ||
          previous.hasPendingTanker != next.hasPendingTanker,
      builder: (context, state) {
        final cubit = context.read<InvoiceGenerationCubit>();

        return Column(
          children: <Widget>[
            SwitchListTile.adaptive(
              contentPadding: EdgeInsets.zero,
              value: state.hasSubscriptionFee,
              onChanged: state.hasUnpaidSubscriptionFee ? cubit.toggleSubscriptionFee : null,
              title: const Text('إضافة رسوم اشتراك', style: TextStyle(fontSize: 13)),
              subtitle: Text(
                state.hasUnpaidSubscriptionFee
                    ? 'غير مفوترة: ${CurrencyFormatter.withLabel(state.subscriptionInstallment)}'
                    : 'لا توجد طلبات اشتراك غير مفوترة لهذا المشترك',
                style: TextStyle(
                  fontSize: 11,
                  color: state.hasUnpaidSubscriptionFee ? AppColors.neutral : AppColors.danger,
                ),
              ),
            ),
            SwitchListTile.adaptive(
              contentPadding: EdgeInsets.zero,
              value: state.hasTankerFee,
              onChanged: state.hasPendingTanker ? cubit.toggleTankerFee : null,
              title: const Text('إضافة رسوم وايت', style: TextStyle(fontSize: 13)),
              subtitle: Text(
                state.hasPendingTanker
                    ? 'طلبات مكتملة: ${CurrencyFormatter.withLabel(state.tankerInstallment)}'
                    : 'لا توجد طلبات وايت مكتملة غير مفوترة',
                style: TextStyle(
                  fontSize: 11,
                  color: state.hasPendingTanker ? AppColors.neutral : AppColors.danger,
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

/// حقل مبلغ يقرأ من حالة الـ Cubit ويكتب فيها (اشتراك/وايت).
class AmountFromStateField extends StatefulWidget {
  const AmountFromStateField({
    super.key,
    required this.label,
    required this.helperText,
    required this.read,
    required this.write,
  });

  final String label;
  final String helperText;
  final double Function(InvoiceGenerationState state) read;
  final void Function(InvoiceGenerationCubit cubit, double value) write;

  @override
  State<AmountFromStateField> createState() => _AmountFromStateFieldState();
}

class _AmountFromStateFieldState extends State<AmountFromStateField> {
  final TextEditingController _controller = TextEditingController();

  @override
  void initState() {
    super.initState();
    final value = widget.read(context.read<InvoiceGenerationCubit>().state);
    if (value > 0) {
      _controller.text = value.toStringAsFixed(0);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AppNumericField(
        controller: _controller,
        label: widget.label,
        prefixText: 'ريال',
        helperText: widget.helperText,
        onChanged: (value) => widget.write(
          context.read<InvoiceGenerationCubit>(),
          double.tryParse(value.trim()) ?? 0,
        ),
      );
}

class GenerationNotesField extends StatefulWidget {
  const GenerationNotesField({super.key});

  @override
  State<GenerationNotesField> createState() => _GenerationNotesFieldState();
}

class _GenerationNotesFieldState extends State<GenerationNotesField> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AppTextField(
        controller: _controller,
        label: 'ملاحظات',
        hint: 'اختياري',
        maxLines: 2,
        onChanged: context.read<InvoiceGenerationCubit>().setNotes,
      );
}
