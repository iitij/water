import 'package:flutter/material.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/finance/finance_fields.dart';
import '../../../../core/finance/finance_locator.dart';
import '../../../../core/finance/finance_responsive.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_filters.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../../core/widgets/finance_badges.dart';
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
  late final TextEditingController _credit = _num(widget.invoice?.creditApplied);
  late final TextEditingController _total = _num(
    (widget.invoice?.totalAmount ?? 0) > 0
        ? widget.invoice!.totalAmount
        : _autoTotal(widget.invoice),
  );

  static double _autoTotal(InvoiceModel? invoice) => (invoice?.consumptionFee ?? 0);
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
      _parse(_consumption);

  static double _parse(TextEditingController controller) =>
      double.tryParse(controller.text.trim()) ?? 0;

  Future<void> _save() async {
    if (isEditing && widget.invoice!.status != InvoiceStatus.draft) {
      setState(() => _error = 'لا يجوز تعديل فاتورة معتمدة؛ يلزم إجراء تصحيح محاسبي موثق.');
      return;
    }
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
    if (isEditing && widget.invoice!.status != InvoiceStatus.draft) {
      return SubPageScaffold(
        title: 'فاتورة معتمدة',
        child: Center(child: Text('البيانات المالية للفاتورة المعتمدة غير قابلة للتعديل.')),
      );
    }

    return SubPageScaffold(
      title: isEditing ? 'تعديل فاتورة' : 'فاتورة يدوية',
      subtitle: isEditing ? 'تحديث بيانات الفاتورة والمبالغ' : 'إصدار فاتورة بمبالغ مدخلة يدوياً',
      child: SafeArea(
        top: false,
        child: Form(
        key: _formKey,
        child: ListView(
          padding: EdgeInsets.all(gutter),
          children: <Widget>[
            const SectionHeading(
              title: 'بيانات الفاتورة',
              icon: Icons.description_outlined,
            ),
            const SizedBox(height: 8),
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _SubscriberPicker(
                    initialId: _subscriberId,
                    initialName: widget.invoice?.subscriber?.name,
                    onSelected: (id) => setState(() => _subscriberId = id),
                  ),
                  const SizedBox(height: 12),
                  if (isEditing) ...<Widget>[
                    LtrNumberTextTile(
                      label: 'رقم الفاتورة',
                      value: widget.invoice!.billNumber,
                    ),
                    const SizedBox(height: 10),
                  ],
                  AppSettingRow(
                    icon: Icons.calendar_today_outlined,
                    color: Theme.of(context).colorScheme.primary,
                    title: 'تاريخ الفاتورة',
                    showChevron: false,
                    trailing: Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(
                        DateFormat.day(_billDate),
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w500,
                            ),
                      ),
                    ),
                    onTap: () async {
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: _billDate ?? DateTime.now(),
                        firstDate: DateTime(2015),
                        lastDate: DateTime.now().add(const Duration(days: 365)),
                      );
                      if (picked != null) setState(() => _billDate = picked);
                    },
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            const SectionHeading(
              title: 'تفاصيل المبالغ',
              subtitle: 'الإجمالي يُحسب من الاستهلاك',
              icon: Icons.calculate_outlined,
            ),
            const SizedBox(height: 8),
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
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
              controller: _credit,
              label: 'خصم رصيد دائن',
              prefixText: 'ريال',
            ),
            const SizedBox(height: 10),
            AppNumericField(
              controller: _total,
              label: 'الإجمالي',
              prefixText: 'ريال',
              helperText: 'فارغ = محسوب تلقائياً من الاستهلاك',
            ),
            const SizedBox(height: 10),
            AppTextField(
              controller: _notes,
              label: 'ملاحظات',
              maxLines: 3,
            ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            if (isEditing) ...<Widget>[
              const SectionHeading(title: 'الحالة', icon: Icons.flag_outlined),
              const SizedBox(height: 8),
              AppCard(
                child: Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: InvoiceStatus.values
                      .where((s) => s != InvoiceStatus.cancelled)
                      .map(
                        (s) => AppFilterChip(
                          label: s.label,
                          selected: _status == s,
                          onTap: () => setState(() => _status = s),
                        ),
                      )
                      .toList(),
                ),
              ),
              const SizedBox(height: 16),
            ],
            if (_error != null) ...<Widget>[
              Text(
                _error!,
                style: const TextStyle(color: AppColors.danger, fontSize: 12),
              ),
              const SizedBox(height: 10),
            ],
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.primary.withValues(alpha: 0.06),
                borderRadius: BorderRadius.circular(AppRadius.lg),
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
                      fontWeight: FontWeight.w600,
                      color: AppColors.danger,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            AppPrimaryButton(
              label: isEditing ? 'حفظ التعديلات' : 'إصدار الفاتورة',
              icon: Icons.save_outlined,
              loading: _saving,
              onPressed: _saving ? null : _save,
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
      ),
    );
  }

  void _syncTotal() {
    // يُبقي الحقل التلقائي متزامناً طالما لم يعدّله المستخدم يدوياً بقيمة مختلفة.
    if (_total.text.trim().isEmpty) setState(() {});
  }
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
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(label, style: const TextStyle(fontSize: 11, color: AppColors.neutral)),
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(
              value,
              style: const TextStyle(fontWeight: FontWeight.w600),
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
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: AppFilterChip(
                label: _selectedName ?? 'مشترك #$_selectedId',
                selected: true,
                icon: Icons.person_outline_rounded,
                onRemove: () {
                  setState(() {
                    _selectedId = null;
                    _selectedName = null;
                  });
                  widget.onSelected(null);
                },
              ),
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
            decoration: BoxDecoration(
              border: Border.all(color: AppColors.lightBorder),
              borderRadius: BorderRadius.circular(AppRadius.md),
            ),
            child: ListView.builder(
              shrinkWrap: true,
              itemCount: _results.length,
              itemBuilder: (context, index) {
                final option = _results[index];
                final meta = [
                  option.squareName,
                  option.phone,
                  if (option.meters.isNotEmpty)
                    option.meters.first.uniqueCode,
                ].whereType<String>().join(' • ');
                return InkWell(
                  onTap: () {
                    setState(() {
                      _selectedId = option.id;
                      _selectedName = option.name;
                      _results = const <SubscriberOption>[];
                    });
                    widget.onSelected(option.id);
                  },
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text(
                          option.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600),
                        ),
                        const SizedBox(height: 1),
                        Text(
                          meta,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 11, color: AppColors.neutral),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
      ],
    );
  }
}
