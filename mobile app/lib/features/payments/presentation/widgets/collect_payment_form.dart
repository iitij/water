import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/finance/finance_fields.dart';
import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/widgets/info_notice.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../domain/accounting/payment_allocator.dart';
import '../cubit/collect_payment_cubit.dart';
import '../cubit/collect_payment_state.dart';
import '../sheets/receipt_success_sheet.dart';

/// نموذج التحصيل المشترك — يستخدمه كل من `CollectPaymentSheet` (تحصيل بلمسة من
/// `SCR-13`/`SCR-15`) و`PaymentFormScreen` (`SCR-18`)، ويسلَّم جاهزاً لوحدة
/// فؤاد (`SCR-19/20`).
///
/// القواعد المنفذة في الواجهة (مرجع `PaymentResource::form` سطر 36-52 و
/// `InvoiceResource::collect` سطر 391-410):
///  - المستحق المعروض = `total_amount + previous_balance - amount_paid`.
///  - `reference_number` يظهر ويُلزم فقط مع `bank_transfer`/`check`.
///  - `reason` يُرسل كما هو ويُعرض خلف عبارة «وذلك مقابل» في السند.
///  - معاينة توزيع FIFO + الفائض كرصيد دائن في نفس الحساب المعزول.
class CollectPaymentForm extends StatelessWidget {
  const CollectPaymentForm({
    super.key,
    this.invoice,
    this.onSubmitted,
    this.compact = false,
    this.scrollController,
  });

  /// يُمرَّر من `DraggableScrollableSheet` ليظل السحب داخل الدراج سلساً.
  final ScrollController? scrollController;

  final InvoiceModel? invoice;
  final ValueChanged<bool>? onSubmitted;

  /// `true` داخل الدراج (أقل مساحة)، `false` داخل الشاشة الكاملة.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<CollectPaymentCubit>();
    return BlocBuilder<CollectPaymentCubit, CollectPaymentState>(
      builder: (context, state) => ListView(
        controller: scrollController,
        padding: EdgeInsets.fromLTRB(16, compact ? 12 : 16, 16, 16),
        children: <Widget>[
          DueHeaderCard(state: state, invoice: invoice),
          const SizedBox(height: 12),
          if (state.openInvoices.length > 1) InvoicePickerRow(state: state),
          AmountField(cubit: cubit, state: state),
          const SizedBox(height: 6),
          QuickAmountRow(state: state, cubit: cubit),
          const SizedBox(height: 12),
          MethodPickerRow(state: state, cubit: cubit),
          if (state.requiresReference) ...<Widget>[
            const SizedBox(height: 10),
            ReferenceNumberField(cubit: cubit),
          ],
          const SizedBox(height: 10),
          ReasonField(cubit: cubit),
          const SizedBox(height: 10),
          PaymentDateField(state: state, cubit: cubit),
          const SizedBox(height: 12),
          AllocationPreviewCard(state: state),
          const SizedBox(height: 12),
          CollectSubmitButton(
            state: state,
            cubit: cubit,
            invoice: invoice,
            onSubmitted: onSubmitted ?? (_) {},
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}

/// رأس البطاقة: المستفيد + المبلغ المطلوب + تنبيه FIFO.
class DueHeaderCard extends StatelessWidget {
  const DueHeaderCard({super.key, required this.state, this.invoice});

  final CollectPaymentState state;
  final InvoiceModel? invoice;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.05),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.15)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  state.subscriberName ?? '—',
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800),
                ),
              ),
              if (invoice != null)
                Text(
                  invoice!.invoiceType.label,
                  style: const TextStyle(fontSize: 11, color: AppColors.neutral),
                ),
            ],
          ),
          if (invoice != null)
            Text(
              '${invoice!.billNumber} • ${DateFormat.range(invoice!.periodStart, invoice!.periodEnd)}',
              style: const TextStyle(fontSize: 11, color: AppColors.neutral),
            ),
          if (state.loadingInvoices)
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: ShimmerSkeleton(height: 4),
            ),
          const SizedBox(height: 6),
          Row(
            children: <Widget>[
              const Expanded(
                child: Text('المطلوب تحصيله', style: TextStyle(fontSize: 12)),
              ),
              Text(
                CurrencyFormatter.debt(state.dueAmount),
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                  color: AppColors.danger,
                ),
              ),
            ],
          ),
          if (invoice != null && invoice!.previousBalance > 0)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'يشمل ${CurrencyFormatter.withLabel(invoice!.previousBalance)} متأخرات سابقة',
                style: const TextStyle(fontSize: 11, color: AppColors.warning),
              ),
            ),
          if (state.invoiceId == null)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: InfoNotice(
                icon: Icons.auto_awesome,
                text: 'بدون فاتورة محددة: يوزّع الخادم المبلغ على أقدم الفواتير (FIFO).',
              ),
            ),
        ],
      ),
    );
  }
}

class InvoicePickerRow extends StatelessWidget {
  const InvoicePickerRow({super.key, required this.state});

  final CollectPaymentState state;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<CollectPaymentCubit>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Text(
          'الفاتورة المستهدفة',
          style: TextStyle(fontSize: 12, color: AppColors.neutral),
        ),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: <Widget>[
            ChoiceChip(
              selected: state.invoiceId == null,
              onSelected: (_) => cubit.useFifoAutomatically(),
              label: const Text('FIFO تلقائي'),
            ),
            ...state.openInvoices.map(
              (invoice) => ChoiceChip(
                selected: state.invoiceId == invoice.id,
                onSelected: (_) => cubit.selectInvoice(invoice.id),
                label: SizedBox(
                  width: 150,
                  child: Text(
                    '${invoice.billNumber} — ${CurrencyFormatter.amount(invoice.netPayable)}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 11),
                  ),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
      ],
    );
  }
}

class AmountField extends StatefulWidget {
  const AmountField({super.key, required this.cubit, required this.state});

  final CollectPaymentCubit cubit;
  final CollectPaymentState state;

  @override
  State<AmountField> createState() => _AmountFieldState();
}

class _AmountFieldState extends State<AmountField> {
  final TextEditingController _controller = TextEditingController();

  @override
  void initState() {
    super.initState();
    final value = widget.state.amount;
    if (value > 0) {
      _controller.text = _trim(value);
    }
  }

  String _trim(double value) {
    final text = value.toStringAsFixed(2);
    return text.endsWith('.00') ? text.substring(0, text.length - 3) : text;
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AppNumericField(
        controller: _controller,
        label: 'مبلغ الدفعة',
        prefixText: 'ريال',
        required: true,
        autofocus: !widget.state.hasInvoice,
        onChanged: (value) => widget.cubit.setAmount(double.tryParse(value.trim()) ?? 0),
      );
}

class QuickAmountRow extends StatelessWidget {
  const QuickAmountRow({super.key, required this.state, required this.cubit});

  final CollectPaymentState state;
  final CollectPaymentCubit cubit;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      children: <Widget>[
        ActionChip(
          label: Text('الكل (${CurrencyFormatter.amount(state.dueAmount)})'),
          onPressed: state.dueAmount > 0 ? cubit.fillFullDue : null,
        ),
        ActionChip(
          label: const Text('النصف'),
          onPressed: state.dueAmount > 0 ? cubit.fillHalfDue : null,
        ),
        ActionChip(
          label: const Text('تصفير'),
          onPressed: cubit.resetAmount,
        ),
      ],
    );
  }
}

class MethodPickerRow extends StatelessWidget {
  const MethodPickerRow({super.key, required this.state, required this.cubit});

  final CollectPaymentState state;
  final CollectPaymentCubit cubit;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Text('طريقة الدفع', style: TextStyle(fontSize: 12, color: AppColors.neutral)),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          children: PaymentMethod.values
              .map(
                (method) => ChoiceChip(
                  selected: state.method == method,
                  onSelected: (_) => cubit.setMethod(method),
                  label: Text(method.label),
                  avatar: Icon(_methodIcon(method), size: 16),
                ),
              )
              .toList(),
        ),
      ],
    );
  }

  static IconData _methodIcon(PaymentMethod method) => switch (method) {
        PaymentMethod.cash => Icons.payments_outlined,
        PaymentMethod.bankTransfer => Icons.account_balance_outlined,
        PaymentMethod.check => Icons.event_note_outlined,
      };
}

/// حقل `reference_number` المشروط — إلزامي مع التحويل البنكي/الشيك فقط.
class ReferenceNumberField extends StatefulWidget {
  const ReferenceNumberField({super.key, required this.cubit});

  final CollectPaymentCubit cubit;

  @override
  State<ReferenceNumberField> createState() => _ReferenceNumberFieldState();
}

class _ReferenceNumberFieldState extends State<ReferenceNumberField> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          AppTextField(
            controller: _controller,
            label: 'رقم الحوالة / رقم الشيك',
            required: true,
            onChanged: widget.cubit.setReferenceNumber,
          ),
          const SizedBox(height: 4),
          const Text(
            'إلزامي مع التحويل البنكي والشيك فقط — يطابق منطق Filament.',
            style: TextStyle(fontSize: 11, color: AppColors.neutral),
          ),
        ],
      );
}

/// حقل `reason` (يظهر في السند خلف عبارة «وذلك مقابل»).
class ReasonField extends StatefulWidget {
  const ReasonField({super.key, required this.cubit});

  final CollectPaymentCubit cubit;

  @override
  State<ReasonField> createState() => _ReasonFieldState();
}

class _ReasonFieldState extends State<ReasonField> {
  final TextEditingController _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AppTextField(
        controller: _controller,
        label: 'البيان (وذلك مقابل)',
        hint: 'مثال: سداد فاتورة شهر سبتمبر',
        onChanged: widget.cubit.setReason,
      );
}

class PaymentDateField extends StatelessWidget {
  const PaymentDateField({super.key, required this.state, required this.cubit});

  final CollectPaymentState state;
  final CollectPaymentCubit cubit;

  @override
  Widget build(BuildContext context) {
    final date = state.paymentDate ?? DateTime.now();
    return InkWell(
      onTap: () async {
        final picked = await showDatePicker(
          context: context,
          initialDate: date,
          firstDate: DateTime(date.year - 2),
          lastDate: DateTime.now().add(const Duration(days: 1)),
        );
        if (picked != null) cubit.setDate(picked);
      },
      child: InputDecorator(
        decoration: const InputDecoration(labelText: 'تاريخ الدفعة'),
        child: Row(
          children: <Widget>[
            const Icon(Icons.calendar_today_outlined, size: 16),
            const SizedBox(width: 8),
            Text(DateFormat.day(date)),
          ],
        ),
      ),
    );
  }
}

/// معاينة محلية لتوزيع FIFO — نفس خوارزمية `PaymentService::distributePayment`.
class AllocationPreviewCard extends StatelessWidget {
  const AllocationPreviewCard({super.key, required this.state});

  final CollectPaymentState state;

  @override
  Widget build(BuildContext context) {
    final AllocationResult preview = state.previewAllocation;
    if (state.amount <= 0 || preview.allocations.isEmpty) {
      return const SizedBox.shrink();
    }

    final affectsMeter =
        state.openInvoices.isNotEmpty && state.openInvoices.first.meterId != null;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.neutral.withValues(alpha: 0.2)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Text(
            'معاينة التوزيع على الفواتير',
            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          ...preview.allocations.map((entry) {
            final invoice = preview.invoices.where((i) => i.id == entry.invoiceId);
            final bill = invoice.isEmpty ? '#${entry.invoiceId}' : invoice.first.billNumber;
            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(
                children: <Widget>[
                  const Icon(Icons.arrow_left, size: 16, color: AppColors.neutral),
                  const SizedBox(width: 4),
                  Expanded(child: Text(bill, style: const TextStyle(fontSize: 12))),
                  Text(
                    CurrencyFormatter.withLabel(entry.applied),
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                  ),
                ],
              ),
            );
          }),
          if (preview.surplusCredit > 0)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: InfoNotice(
                icon: Icons.account_balance_wallet_outlined,
                color: AppColors.success,
                text: 'الفائض ${CurrencyFormatter.withLabel(preview.surplusCredit)} يبقى '
                    'رصيداً دائناً في ${affectsMeter ? 'حساب العداد' : 'حساب المشترك'}.',
              ),
            ),
        ],
      ),
    );
  }
}

class CollectSubmitButton extends StatelessWidget {
  const CollectSubmitButton({
    super.key,
    required this.state,
    required this.cubit,
    required this.onSubmitted,
    this.invoice,
  });

  final CollectPaymentState state;
  final CollectPaymentCubit cubit;
  final ValueChanged<bool> onSubmitted;
  final InvoiceModel? invoice;

  Future<void> _submit(BuildContext context) async {
    final overpay = state.amount - state.dueAmount;
    if (overpay > 0 && state.hasInvoice) {
      final confirmed = await ConfirmDialog.show(
        context,
        title: 'مبلغ أكبر من المستحق',
        message: 'سيدفع ${CurrencyFormatter.withLabel(overpay)} زائداً ويُحوَّل رصيداً دائناً.',
        confirmLabel: 'تأكيد',
      );
      if (!confirmed || !context.mounted) return;
    }

    final ok = await cubit.submit();
    if (!context.mounted) return;
    if (!ok) return;

    final payment = state.outcome?.payment;
    if (payment == null) {
      onSubmitted(true);
      return;
    }

    await ReceiptSuccessSheet.show(
      context,
      payment: payment,
      receiptData: state.outcome?.receiptData,
      invoice: invoice,
      queuedOffline: state.outcome?.queuedOffline ?? false,
      onDone: () => onSubmitted(true),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (state.validationMessage != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              state.validationMessage!,
              style: const TextStyle(color: AppColors.danger, fontSize: 12),
            ),
          ),
        if (state.requiresReference && state.referenceNumber.trim().isEmpty)
          const Padding(
            padding: EdgeInsets.only(bottom: 8),
            child: InfoNotice(
              icon: Icons.priority_high,
              color: AppColors.warning,
              text: 'أدخل رقم الحوالة/الشيك للمتابعة.',
            ),
          ),
        FilledButton.icon(
          style: FilledButton.styleFrom(
            minimumSize: const Size(0, 46),
            backgroundColor: AppColors.success,
          ),
          onPressed: state.submitting ? null : () => _submit(context),
          icon: state.submitting
              ? const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                )
              : const Icon(Icons.check_circle_outline),
          label: Text(
            state.amount > 0
                ? 'تحصيل ${CurrencyFormatter.amount(state.amount)} ريال'
                : 'تحصيل الدفعة',
          ),
        ),
      ],
    );
  }
}
