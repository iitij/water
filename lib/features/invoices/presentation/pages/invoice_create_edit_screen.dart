import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_responsive.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/finance/finance_fields.dart';
import '../../data/datasources/references_remote_datasource.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/accounting/finance_enums.dart';
import '../../domain/repositories/invoices_repository.dart';

/// إنشاء/تعديل يدوي لفاتورة — يطابق مخطط `InvoiceResource::form` حقلاً حقلاً:
/// بيانات الفاتورة (مشترك/رقم مولَّد/تاريخ/حالة)، تفاصيل المبالغ السبعة مع حساب
/// تلقائي للإجمالي، ثم الملاحظات. `amount_paid` معطل (يُدار عبر السندات).
class InvoiceCreateEditScreen extends StatefulWidget {
  const InvoiceCreateEditScreen({super.key, this.invoice});

  /// `null` = إنشاء جديد.
  final InvoiceModel? invoice;

  @override
  State<InvoiceCreateEditScreen> createState() => _InvoiceCreateEditScreenState();
}

class _InvoiceCreateEditScreenState extends State<InvoiceCreateEditScreen> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();

  late final TextEditingController _consumption =
      _num(widget.invoice?.consumptionFee);
  late final TextEditingController _previousBalance = _num(widget.invoice?.previousBalance);
  late final TextEditingController _subscription = _num(widget.invoice?.subscriptionInstallment);
  late final TextEditingController _tanker = _num(widget.invoice?.tankerCharges);
  late final TextEditingController _credit = _num(widget.invoice?.creditApplied);
  late final TextEditingController _total = _num(
    (widget.invoice?.totalAmount ?? 0) > 0
        ? widget.invoice!.totalAmount
        : _autoTotal(widget.invoice),
  );

  static double _autoTotal(InvoiceModel? invoice) =>
      (invoice?.consumptionFee ?? 0) +
      (invoice?.subscriptionInstallment ?? 0) +
      (invoice?.tankerCharges ?? 0);
  late final TextEditingController _notes =
      TextEditingController(text: widget.invoice?.notes ?? '');

  int? _subscriberId;
  DateTime? _billDate;
  InvoiceStatus _status = InvoiceStatus.issued;
  bool _saving = false;
  String? _error;

  bool get isEditing => widget.invoice != null;

  @override
  void initState() {
    super.initState();
    _subscriberId = widget.invoice?.subscriberId;
    _billDate = widget.invoice?.billDate ?? DateTime.now();
    _status = widget.invoice?.status ?? InvoiceStatus.issued;
  }

  @override
  void dispose() {
    for (final controller in <TextEditingController>[
      _consumption,
      _previousBalance,
      _subscription,
      _tanker,
      _credit,
      _total,
      _notes,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  static TextEditingController _num(double? value) =>
      TextEditingController(text: value == null || value == 0 ? '' : value.toString());

  double get _liveTotal =>
      _parse(_consumption.text) + _parse(_subscription.text) + _parse(_tanker.text);

  static double _parse(TextEditingController controller) =>
      double.tryParse(controller.text.trim()) ?? 0;

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    if (_subscriberId == null) {
      setState(() => _error = 'اختر المشترك أولاً.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    final repository = sl<InvoicesRepository>();
    final payload = <String, dynamic>{
      'subscriber_id': _subscriberId,
      'bill_date': DateFormat.day(_billDate),
      'status': isEditing ? _status.wire : InvoiceStatus.issued.wire,
      'consumption_fee': _parse(_consumption),
      'previous_balance': _parse(_previousBalance),
      'subscription_installment': _parse(_subscription),
      'tanker_charges': _parse(_tanker),
      'credit_applied': _parse(_credit),
      'total_amount': _parse(_total) > 0 ? _parse(_total) : _liveTotal,
      if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
    };

    try {
      if (isEditing) {
        await repository.update(widget.invoice!.id, payload);
      } else {
        await repository.createManual(payload);
      }
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on AppFailure catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = failure.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final gutter = context.gutter;

    return Scaffold(
      appBar: AppBar(title: Text(isEditing ? 'تعديل فاتورة' : 'فاتورة يدوية')),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: EdgeInsets.all(gutter * 0.6),
          child: Row(
            children: <Widget>[
              if (_error != null)
                Expanded(
                  child: Text(
                    _error!,
                    style: const TextStyle(color: AppColors.danger, fontSize: 12),
                  ),
                ),
              if (_error != null) const SizedBox(width: 12),
              Expanded(
                child: FilledButton(
                  onPressed: _saving ? null : _save,
                  child: _saving
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : Text(isEditing ? 'حفظ التعديلات' : 'إصدار الفاتورة'),
                ),
              ),
            ],
          ),
        ),
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: EdgeInsets.all(gutter),
          children: <Widget>[
            const _SectionTitle('بيانات الفاتورة'),
            _SubscriberPicker(
              initialId: _subscriberId,
              initialName: widget.invoice?.subscriber?.name,
              onSelected: (id) => setState(() => _subscriberId = id),
            ),
            const SizedBox(height: 12),
            if (isEditing)
              Row(
                children: <Widget>[
                  Expanded(
                    child: LtrNumberTextTile(
                      label: 'رقم الفاتورة',
                      value: widget.invoice!.billNumber,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () async {
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: _billDate ?? DateTime.now(),
                          firstDate: DateTime(2015),
                          lastDate: DateTime.now().add(const Duration(days: 365)),
                        );
                        if (picked != null) setState(() => _billDate = picked);
                      },
                      icon: const Icon(Icons.calendar_today_outlined, size: 18),
                      label: Text(DateFormat.day(_billDate)),
                    ),
                  ),
                ],
              )
            else
              OutlinedButton.icon(
                onPressed: () async {
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: _billDate ?? DateTime.now(),
                    firstDate: DateTime(2015),
                    lastDate: DateTime.now().add(const Duration(days: 365)),
                  );
                  if (picked != null) setState(() => _billDate = picked);
                },
                icon: const Icon(Icons.calendar_today_outlined, size: 18),
                label: Text('تاريخ الفاتورة: ${DateFormat.day(_billDate)}'),
              ),
            const SizedBox(height: 16),
            const _SectionTitle('تفاصيل المبالغ'),
            AppNumericField(
              controller: _consumption,
              label: 'قيمة الاستهلاك',
              prefixText: 'ريال',
              onChanged: (_) => _syncTotal(),
            ),
            const SizedBox(height: 10),
            AppNumericField(
              controller: _previousBalance,
              label: 'المتأخرات السابقة',
              prefixText: 'ريال',
              helperText: 'تُعرض ولا تُضاف إلى إجمالي الفاتورة لتفادي المضاعفة',
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 10),
            AppNumericField(
              controller: _subscription,
              label: 'قسط الاشتراك',
              prefixText: 'ريال',
              onChanged: (_) => _syncTotal(),
            ),
            const SizedBox(height: 10),
            AppNumericField(
              controller: _tanker,
              label: 'رسوم الوايت',
              prefixText: 'ريال',
              onChanged: (_) => _syncTotal(),
            ),
            const SizedBox(height: 10),
            AppNumericField(
              controller: _credit,
              label: 'خصم رصيد دائن',
              prefixText: 'ريال',
            ),
            const SizedBox(height: 10),
            AppNumericField(
              controller: _total,
              label: 'الإجمالي',
              prefixText: 'ريال',
              helperText: 'فارغ = محسوب تلقائياً من الاستهلاك + الاشتراك + الوايت',
            ),
            const SizedBox(height: 10),
            AppTextField(
              controller: _notes,
              label: 'ملاحظات',
              maxLines: 3,
            ),
            const SizedBox(height: 16),
            if (isEditing) ...<Widget>[
              const _SectionTitle('الحالة'),
              Wrap(
                spacing: 8,
                children: InvoiceStatus.values
                    .where((s) => s != InvoiceStatus.cancelled)
                    .map(
                      (s) => ChoiceChip(
                        label: Text(s.label),
                        selected: _status == s,
                        onSelected: (_) => setState(() => _status = s),
                      ),
                    )
                    .toList(),
              ),
              const SizedBox(height: 16),
            ],
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.primary.withValues(alpha: 0.06),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Row(
                children: <Widget>[
                  const Expanded(
                    child: Text('صافي المطلوب بعد المتأخرات والخصم'),
                  ),
                  LtrNumberText(
                    CurrencyFormatter.withLabel(
                      (_parse(_total) > 0 ? _parse(_total) : _liveTotal) +
                          _parse(_previousBalance) -
                          _parse(_credit),
                    ),
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      color: AppColors.danger,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _syncTotal() {
    // يُبقي الحقل التلقائي متزامناً طالما لم يعدّله المستخدم يدوياً بقيمة مختلفة.
    if (_total.text.trim().isEmpty) setState(() {});
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.title);

  final String title;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Text(
          title,
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800),
        ),
      );
}

/// خانة عرض قيمة رقمية داخل LTR (لأرقام المستندات).
class LtrNumberTextTile extends StatelessWidget {
  const LtrNumberTextTile({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.lightBorder),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(label, style: const TextStyle(fontSize: 11, color: AppColors.neutral)),
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(
              value,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

/// منتقي المشترك — يعتمد `GET /api/subscribers?q=` (متوفر حالياً في Laravel).
class _SubscriberPicker extends StatefulWidget {
  const _SubscriberPicker({
    required this.initialId,
    required this.initialName,
    required this.onSelected,
  });

  final int? initialId;
  final String? initialName;
  final ValueChanged<int?> onSelected;

  @override
  State<_SubscriberPicker> createState() => _SubscriberPickerState();
}

class _SubscriberPickerState extends State<_SubscriberPicker> {
  final TextEditingController _search = TextEditingController();
  List<SubscriberOption> _results = const <SubscriberOption>[];
  bool _loading = false;
  int? _selectedId;
  String? _selectedName;

  @override
  void initState() {
    super.initState();
    _selectedId = widget.initialId;
    _selectedName = widget.initialName;
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _searchSubscribers() async {
    setState(() => _loading = true);
    try {
      final results = await ReferencesRemoteDataSource(sl<FinanceApiClient>())
          .subscribers(search: _search.text, limit: 30);
      if (!mounted) return;
      setState(() {
        _results = results;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _results = const <SubscriberOption>[];
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (_selectedId != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Chip(
              avatar: const Icon(Icons.person_outline),
              label: Text(_selectedName ?? 'مشترك #$_selectedId'),
              onDeleted: () {
                setState(() {
                  _selectedId = null;
                  _selectedName = null;
                });
                widget.onSelected(null);
              },
            ),
          ),
        AppTextField(
          controller: _search,
          label: 'ابحث عن المشترك',
          hint: 'الاسم أو الهاتف أو كود العداد',
          onChanged: (_) {},
          onSubmitted: (_) => _searchSubscribers(),
          suffix: IconButton(
            icon: const Icon(Icons.search),
            onPressed: _searchSubscribers,
          ),
        ),
        if (_loading) const LinearProgressIndicator(minHeight: 2),
        if (_results.isNotEmpty)
          Container(
            constraints: const BoxConstraints(maxHeight: 220),
            margin: const EdgeInsets.only(top: 8),
            child: ListView.builder(
              itemCount: _results.length,
              itemBuilder: (context, index) {
                final option = _results[index];
                return ListTile(
                  dense: true,
                  title: Text(option.name),
                  subtitle: Text(
                    [
                      option.squareName,
                      option.phone,
                      if (option.meters.isNotEmpty)
                        option.meters.map((m) => m.uniqueCode).join('، '),
                    ].whereType<String>().join(' • '),
                  ),
                  onTap: () {
                    setState(() {
                      _selectedId = option.id;
                      _selectedName = option.name;
                      _results = const <SubscriberOption>[];
                    });
                    widget.onSelected(option.id);
                  },
                );
              },
            ),
          ),
      ],
    );
  }
}
