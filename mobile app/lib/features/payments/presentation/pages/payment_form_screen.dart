import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/finance/finance_fields.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/info_notice.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';
import '../../../invoices/data/datasources/references_remote_datasource.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../../invoices/domain/accounting/finance_enums.dart';
import '../../data/models/payment_model.dart';
import '../../domain/repositories/payments_repository.dart';
import '../cubit/collect_payment_cubit.dart';
import '../cubit/collect_payment_state.dart';
import '../widgets/collect_payment_form.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_tokens.dart';

/// `SCR-18` — شاشة إضافة/تعديل دفعة (`PaymentResource::form`).
///
/// وضع الإضافة: تعبئة تلقائية لأحدث فاتورة غير مسددة للمشترك المختار
/// (`PaymentResource` سطر 36-52)، ثم نفس نموذج التحصيل المشترك
/// [CollectPaymentForm] — بما فيه الحقل المشروط `reference_number` و`reason`
/// («وذلك مقابل»).
///
/// وضع التعديل: تعديل سند قائم عبر `PUT /api/payments/{id}`؛ التوزيع نفسه يُعاد
/// حسابه على الخادم في `PaymentService::recalculateInvoices`.
class PaymentFormScreen extends StatelessWidget {
  const PaymentFormScreen({
    super.key,
    this.payment,
    this.invoice,
    this.subscriber,
  });

  /// غير `null` = تعديل سند قائم.
  final PaymentModel? payment;
  final InvoiceModel? invoice;
  final SubscriberOption? subscriber;

  bool get _isEdit => payment != null;

  @override
  Widget build(BuildContext context) {
    if (_isEdit) {
      return _PaymentEditScreen(payment: payment!);
    }

    return BlocProvider<CollectPaymentCubit>(
      create: (_) {
        final cubit = CollectPaymentCubit(
          paymentsRepository: sl<PaymentsRepository>(),
          references: sl<ReferencesRemoteDataSource>(),
          meterId: invoice?.meterId ?? _singleMeterId(subscriber),
        );
        if (invoice != null) {
          unawaited(cubit.openForInvoice(invoice!));
        } else if (subscriber != null) {
          unawaited(cubit.openForSubscriber(subscriber!.id, name: subscriber!.name));
        }
        return cubit;
      },
      child: _PaymentCreateScreen(
        invoice: invoice,
        subscriber: subscriber,
      ),
    );
  }
}

/// عند وجود عداد واحد فقط نفتح النموذج على حسابه المعزول مباشرة (قاعدة §3.4-1)،
/// ومع أكثر من عداد نترك المحصل يختار الفاتورة (الحساب العام للمشترك).
int? _singleMeterId(SubscriberOption? subscriber) {
  final meters = subscriber?.meters;
  if (meters == null || meters.length != 1) return null;
  return meters.first.id;
}

class _PaymentCreateScreen extends StatelessWidget {
  const _PaymentCreateScreen({this.invoice, this.subscriber});

  final InvoiceModel? invoice;
  final SubscriberOption? subscriber;

  @override
  Widget build(BuildContext context) {
    final cubit = context.read<CollectPaymentCubit>();

    return Scaffold(
      appBar: AppBar(title: const Text('دفعة جديدة')),
      body: SafeArea(
        child: Column(
          children: <Widget>[
            if (invoice == null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                child: _SubscriberPicker(
                  selected: subscriber,
                  onPick: (picked) => cubit.openForSubscriber(picked.id, name: picked.name),
                ),
              ),
            Expanded(
              child: BlocBuilder<CollectPaymentCubit, CollectPaymentState>(
                builder: (context, state) {
                  if (invoice == null && state.subscriberId == null) {
                    return const EmptyStateWidget(
                      title: 'اختر المشترك أولاً',
                      message: 'سيُجلب تلقائياً أحدث فاتورة غير مسددة وقيمة المستحق.',
                      icon: Icons.person_search,
                      compact: true,
                    );
                  }
                  return CollectPaymentForm(invoice: invoice, onSubmitted: (saved) {
                    if (!context.mounted) return;
                    if (saved) Navigator.of(context).pop(true);
                  });
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// منتقي المشترك بالبحث الفوري — يعرض نتائج `/api/subscribers?q=`.
class _SubscriberPicker extends StatefulWidget {
  const _SubscriberPicker({this.selected, required this.onPick});

  final SubscriberOption? selected;
  final ValueChanged<SubscriberOption> onPick;

  @override
  State<_SubscriberPicker> createState() => _SubscriberPickerState();
}

class _SubscriberPickerState extends State<_SubscriberPicker> {
  final TextEditingController _controller = TextEditingController();
  List<SubscriberOption> _results = const <SubscriberOption>[];
  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await sl<ReferencesRemoteDataSource>().subscribers(
        search: _controller.text,
        status: 'active',
        limit: 25,
      );
      if (!mounted) return;
      setState(() {
        _results = results;
        _loading = false;
      });
    } on AppFailure catch (failure) {
      if (!mounted) return;
      setState(() {
        _error = failure.message;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = '$error';
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        AppTextField(
          controller: _controller,
          label: 'المشترك',
          hint: 'اسم، هاتف، أو كود عداد',
          onSubmitted: (_) => _search(),
          suffix: IconButton(icon: const Icon(Icons.search), onPressed: _search),
        ),
        if (widget.selected != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Chip(
              avatar: const Icon(Icons.person_outline, size: 18),
              label: Text(
                '${widget.selected!.name}'
                '${widget.selected!.squareName == null ? '' : ' • ${widget.selected!.squareName}'}',
              ),
            ),
          ),
        if (_loading)
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: ShimmerSkeleton(height: 4),
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_error!, style: const TextStyle(color: AppColors.danger, fontSize: 12)),
          ),
        if (_results.isNotEmpty)
          Container(
            constraints: const BoxConstraints(maxHeight: 220),
            margin: const EdgeInsets.only(top: 8),
            decoration: BoxDecoration(
              border: Border.all(color: AppColors.lightBorder),
              borderRadius: BorderRadius.circular(AppRadius.md),
            ),
            child: ListView.builder(
              shrinkWrap: true,
              itemCount: _results.length,
              itemBuilder: (context, index) {
                final option = _results[index];
                return ListTile(
                  dense: true,
                  title: Text(option.name),
                  subtitle: Text(option.label, maxLines: 1, overflow: TextOverflow.ellipsis),
                  onTap: () {
                    widget.onPick(option);
                    setState(() => _results = const <SubscriberOption>[]);
                  },
                );
              },
            ),
          ),
      ],
    );
  }
}

/// تعديل سند قائم (المبلغ/التاريخ/الطريقة/المرجع/البيان/الملاحظات).
class _PaymentEditScreen extends StatefulWidget {
  const _PaymentEditScreen({required this.payment});

  final PaymentModel payment;

  @override
  State<_PaymentEditScreen> createState() => _PaymentEditScreenState();
}

class _PaymentEditScreenState extends State<_PaymentEditScreen> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();

  late final TextEditingController _amount =
      TextEditingController(text: widget.payment.amount.toString());
  late final TextEditingController _reference =
      TextEditingController(text: widget.payment.referenceNumber ?? '');
  late final TextEditingController _reason =
      TextEditingController(text: widget.payment.reason ?? '');
  late final TextEditingController _notes =
      TextEditingController(text: widget.payment.notes ?? '');

  late PaymentMethod _method = widget.payment.method;
  late DateTime _date = widget.payment.paymentDate ?? DateTime.now();
  bool _saving = false;

  @override
  void dispose() {
    _amount.dispose();
    _reference.dispose();
    _reason.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    final parsed = double.tryParse(_amount.text.trim());
    if (parsed == null || parsed <= 0) {
      AppFeedback.error(context, 'أدخل مبلغاً صحيحاً أكبر من صفر.');
      return;
    }
    if (_method.requiresReference && _reference.text.trim().isEmpty) {
      AppFeedback.error(context, 'رقم الحوالة/الشيك مطلوب لهذه الطريقة.');
      return;
    }

    setState(() => _saving = true);
    try {
      await sl<PaymentsRepository>().update(
        widget.payment.id,
        <String, dynamic>{
          'amount': parsed,
          'payment_date': DateFormat.dateTime(_date),
          'payment_method': _method.wire,
          if (_method.requiresReference) 'reference_number': _reference.text.trim(),
          if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
          if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
        },
      );
      if (!mounted) return;
      AppFeedback.success(context, 'تم تحديث السند.');
      Navigator.of(context).pop(true);
    } on AppFailure catch (failure) {
      if (!mounted) return;
      AppFeedback.error(context, failure.message);
    } catch (error) {
      if (!mounted) return;
      AppFeedback.error(context, 'فشل التحديث: $error');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('تعديل سند ${widget.payment.receiptNumber}'),
        actions: <Widget>[
          IconButton(
            tooltip: 'حذف',
            onPressed: () async {
              final confirmed = await ConfirmDialog.show(
                context,
                title: 'حذف سند قبض',
                message: 'سيُحذف السند حذفًا ناعمًا وتُعاد احتساب الفواتير المرتبطة.',
                confirmLabel: 'حذف',
                destructive: true,
              );
              if (!confirmed) return;
              try {
                await sl<PaymentsRepository>().delete(widget.payment.id);
                if (!context.mounted) return;
                Navigator.of(context).pop(true);
              } on AppFailure catch (failure) {
                if (!context.mounted) return;
                AppFeedback.error(context, failure.message);
              }
            },
            icon: const Icon(Icons.delete_outline, color: AppColors.danger),
          ),
        ],
      ),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: <Widget>[
              const InfoNotice(
                icon: Icons.science_outlined,
                text: 'تعديل المبلغ يُعيد الخادم على أثره توزيع الدفعات واحتساب حالة '
                    'الفاتورة (PaymentService::recalculateInvoices).',
              ),
              const SizedBox(height: 14),
              _ReadOnlyRow(label: 'المستفيد', value: widget.payment.displayName),
              _ReadOnlyRow(
                label: 'الحساب المتأثر',
                value: widget.payment.affectsMeterAccount
                    ? 'رصيد العداد (${widget.payment.meterId})'
                    : 'رصيد المشترك (${widget.payment.subscriberId})',
              ),
              if (widget.payment.invoiceBillNumber != null)
                _ReadOnlyRow(label: 'الفاتورة', value: widget.payment.invoiceBillNumber!),
              _ReadOnlyRow(
                label: 'المبلغ المسجل حالياً',
                value: CurrencyFormatter.withLabel(widget.payment.amount),
              ),
              const SizedBox(height: 12),
              AppNumericField(
                controller: _amount,
                label: 'مبلغ الدفعة',
                prefixText: 'ريال',
                required: true,
              ),
              const SizedBox(height: 12),
              const Text('طريقة الدفع', style: TextStyle(fontSize: 12, color: AppColors.neutral)),
              const SizedBox(height: 6),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: PaymentMethod.values
                    .map(
                      (method) => AppFilterChip(
                        label: method.label,
                        selected: _method == method,
                        onTap: () => setState(() => _method = method),
                      ),
                    )
                    .toList(),
              ),
              if (_method.requiresReference) ...<Widget>[
                const SizedBox(height: 12),
                AppTextField(
                  controller: _reference,
                  label: 'رقم الحوالة / رقم الشيك',
                  required: true,
                ),
              ],
              const SizedBox(height: 12),
              AppTextField(controller: _reason, label: 'البيان (وذلك مقابل)'),
              const SizedBox(height: 12),
              AppTextField(controller: _notes, label: 'ملاحظات', maxLines: 2),
              const SizedBox(height: 12),
              InkWell(
                onTap: () async {
                  final pickedDate = await showDatePicker(
                    context: context,
                    initialDate: _date,
                    firstDate: DateTime(_date.year - 2),
                    lastDate: DateTime.now().add(const Duration(days: 1)),
                  );
                  if (pickedDate == null || !context.mounted) return;
                  final pickedTime = await showTimePicker(
                    context: context,
                    initialTime: TimeOfDay.fromDateTime(_date),
                  );
                  if (!mounted) return;
                  setState(() {
                    _date = DateTime(
                      pickedDate.year,
                      pickedDate.month,
                      pickedDate.day,
                      pickedTime?.hour ?? _date.hour,
                      pickedTime?.minute ?? _date.minute,
                    );
                  });
                },
                child: InputDecorator(
                  decoration: const InputDecoration(
                    labelText: 'تاريخ ووقت الدفعة',
                    prefixIcon: Icon(Icons.schedule_outlined),
                  ),
                  child: Directionality(
                    textDirection: TextDirection.ltr,
                    child: Text(DateFormat.dateTime(_date)),
                  ),
                ),
              ),
              const SizedBox(height: 18),
              FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.save_outlined),
                label: const Text('حفظ التعديلات'),
              ),
              const SizedBox(height: 20),
            ],
          ),
        ),
      ),
    );
  }
}

class _ReadOnlyRow extends StatelessWidget {
  const _ReadOnlyRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(label, style: const TextStyle(fontSize: 12))),
          Text(
            value,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}
