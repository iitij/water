import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/finance/finance_locator.dart';
import '../../../../core/theme/app_colors.dart';
import '../../data/datasources/references_remote_datasource.dart';
import '../../data/models/invoice_model.dart';
import '../../data/models/invoice_query.dart';

/// غلاف مختصر تستخدمه الشاشات والنوافذ المنبثقة.
Future<void> showInvoiceFiltersSheet(
  BuildContext context, {
  required InvoiceQuery query,
  required List<BillingPeriodOption> billingPeriods,
  required ValueChanged<InvoiceQuery> onSubmit,
  Future<List<SubscriberOption>> Function(String search)? subscribersLoader,
}) async {
  List<SquareOption> squares = const <SquareOption>[];
  Future<List<SubscriberOption>> Function(String search)? resolvedLoader = subscribersLoader;
  if (GetIt.I.isRegistered<ReferencesRemoteDataSource>()) {
    final refs = sl<ReferencesRemoteDataSource>();
    try {
      squares = await refs.squares();
    } catch (_) {}
    resolvedLoader ??= (search) => refs.subscribers(search: search);
  }
  if (!context.mounted) return;
  await InvoiceFiltersSheet.show(
    context,
    query: query,
    billingPeriods: billingPeriods,
    squares: squares,
    onSubmit: onSubmit,
    subscribersLoader: resolvedLoader,
  );
}

/// الفلاتر المطابقة لـ `InvoiceResource::table()->filters()`:
/// `billing_period`, `square_id`, `status`, `invoice_type`, `trashed`, `subscriber_id`.
class InvoiceFiltersSheet extends StatefulWidget {
  const InvoiceFiltersSheet({
    super.key,
    required this.query,
    required this.billingPeriods,
    required this.squares,
    required this.onSubmit,
    this.subscribersLoader,
  });

  final InvoiceQuery query;
  final List<BillingPeriodOption> billingPeriods;
  final List<SquareOption> squares;
  final ValueChanged<InvoiceQuery> onSubmit;
  final Future<List<SubscriberOption>> Function(String search)? subscribersLoader;

  static Future<void> show(
    BuildContext context, {
    required InvoiceQuery query,
    required List<BillingPeriodOption> billingPeriods,
    required List<SquareOption> squares,
    required ValueChanged<InvoiceQuery> onSubmit,
    Future<List<SubscriberOption>> Function(String search)? subscribersLoader,
  }) =>
      showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => InvoiceFiltersSheet(
          query: query,
          billingPeriods: billingPeriods,
          squares: squares,
          onSubmit: onSubmit,
          subscribersLoader: subscribersLoader,
        ),
      );

  @override
  State<InvoiceFiltersSheet> createState() => _InvoiceFiltersSheetState();
}

class _InvoiceFiltersSheetState extends State<InvoiceFiltersSheet> {
  static const List<(String, String)> _statusOptions = <(String, String)>[
    ('', 'كل الحالات'),
    ('issued', 'صادرة'),
    ('partially_paid', 'مسددة جزئياً'),
    ('paid', 'مسددة'),
    ('overdue', 'متأخرة'),
    ('draft', 'مسودة'),
  ];

  static const List<(String, String)> _typeOptions = <(String, String)>[
    ('', 'كل الأنواع'),
    ('meter', 'فاتورة عداد'),
    ('subscription', 'رسوم اشتراك'),
    ('tanker', 'طلب وايت'),
  ];

  static const List<(String, String)> _trashedOptions = <(String, String)>[
    ('without', 'بدون المحذوف'),
    ('with', 'مع المحذوف'),
    ('only', 'سلة المحذوفات فقط'),
  ];

  late InvoiceQuery _draft = widget.query;
  List<SubscriberOption> _subscribers = const <SubscriberOption>[];
  bool _loadingSubscribers = false;

  @override
  void initState() {
    super.initState();
    _loadSubscribers('');
  }

  Future<void> _loadSubscribers(String search) async {
    final loader = widget.subscribersLoader;
    if (loader == null) return;
    setState(() => _loadingSubscribers = true);
    try {
      final results = await loader(search);
      if (mounted) setState(() => _subscribers = results);
    } finally {
      if (mounted) setState(() => _loadingSubscribers = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                'فلاتر الفواتير',
                style: Theme.of(context)
                    .textTheme
                    .titleMedium
                    ?.copyWith(fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 12),
              _sectionLabel('حالة الفاتورة'),
              _statusChips(),
              const SizedBox(height: 14),
              _sectionLabel('نوع الفاتورة'),
              _invoiceTypeChips(),
              const SizedBox(height: 14),
              _sectionLabel('سلة المحذوفات (Trashed)'),
              _trashedChips(),
              const SizedBox(height: 16),
              _periodDropdown(),
              const SizedBox(height: 16),
              _squareDropdown(),
              const SizedBox(height: 16),
              _subscriberPicker(),
              const SizedBox(height: 20),
              Row(
                children: <Widget>[
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () {
                        setState(() {
                          _draft = const InvoiceQuery();
                        });
                      },
                      child: const Text('تصفير الفلاتر'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: FilledButton(
                      onPressed: () {
                        widget.onSubmit(_draft);
                        Navigator.of(context).pop();
                      },
                      child: Text(
                        'تطبيق (${_draft.activeFilterCount})',
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _sectionLabel(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text(
          text,
          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
        ),
      );

  Widget _statusChips() {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: _statusOptions.map((option) {
        final value = option.$1.isEmpty ? null : option.$1;
        final selected = _draft.status == value;
        return ChoiceChip(
          selected: selected,
          onSelected: (_) => setState(
            () => _draft = value == null
                ? _draft.copyWith(clearStatus: true)
                : _draft.copyWith(status: value),
          ),
          label: Text(option.$2),
          avatar: selected
              ? const Icon(Icons.check, size: 16, color: AppColors.primary)
              : null,
        );
      }).toList(),
    );
  }

  Widget _invoiceTypeChips() {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: _typeOptions.map((option) {
        final value = option.$1.isEmpty ? null : option.$1;
        final selected = _draft.invoiceType == value;
        return ChoiceChip(
          selected: selected,
          onSelected: (_) => setState(
            () => _draft = value == null
                ? _draft.copyWith(clearInvoiceType: true)
                : _draft.copyWith(invoiceType: value),
          ),
          label: Text(option.$2),
          avatar: selected
              ? const Icon(Icons.check, size: 16, color: AppColors.primary)
              : null,
        );
      }).toList(),
    );
  }

  Widget _trashedChips() {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: _trashedOptions.map((option) {
        final selected = _draft.trashed == option.$1;
        return ChoiceChip(
          selected: selected,
          onSelected: (_) => setState(
            () => _draft = _draft.copyWith(trashed: option.$1),
          ),
          label: Text(option.$2),
          avatar: selected
              ? const Icon(Icons.delete_sweep_outlined, size: 16, color: AppColors.danger)
              : null,
        );
      }).toList(),
    );
  }

  Widget _periodDropdown() {
    return DropdownButtonFormField<String?>(
      initialValue: _draft.billingPeriod,
      decoration: const InputDecoration(labelText: 'فترة الفوترة'),
      isExpanded: true,
      items: <DropdownMenuItem<String?>>[
        const DropdownMenuItem<String?>(value: null, child: Text('كل الفترات')),
        ...widget.billingPeriods.map(
          (period) => DropdownMenuItem<String?>(
            value: period.wireValue,
            child: Text(period.label, overflow: TextOverflow.ellipsis),
          ),
        ),
      ],
      onChanged: (value) => setState(
        () => value == null
            ? _draft = _draft.copyWith(clearBillingPeriod: true)
            : _draft = _draft.copyWith(billingPeriod: value),
      ),
    );
  }

  Widget _squareDropdown() {
    final squareId =
        widget.squares.any((s) => s.id == _draft.squareId) ? _draft.squareId : null;
    return DropdownButtonFormField<int?>(
      initialValue: squareId,
      decoration: const InputDecoration(labelText: 'المربع السكني'),
      isExpanded: true,
      items: <DropdownMenuItem<int?>>[
        const DropdownMenuItem<int?>(value: null, child: Text('كل المربعات')),
        ...widget.squares.map(
          (square) => DropdownMenuItem<int?>(
            value: square.id,
            child: Text(
              square.subscribersCount == null
                  ? square.name
                  : '${square.name} (${square.subscribersCount})',
            ),
          ),
        ),
      ],
      onChanged: (value) => setState(
        () => value == null
            ? _draft = _draft.copyWith(clearSquare: true)
            : _draft = _draft.copyWith(squareId: value),
      ),
    );
  }

  Widget _subscriberPicker() {
    final selected = _firstWhereOrNull(
      _subscribers,
      (s) => s.id == _draft.subscriberId,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        TextFormField(
          decoration: InputDecoration(
            labelText: 'المشترك',
            hintText: 'ابحث بالاسم أو الهاتف',
            suffixIcon: _loadingSubscribers
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : null,
          ),
          onFieldSubmitted: _loadSubscribers,
          initialValue: selected?.name ?? '',
        ),
        if (_subscribers.isNotEmpty) ...<Widget>[
          const SizedBox(height: 8),
          SizedBox(
            height: 92,
            child: ListView.builder(
              scrollDirection: Axis.horizontal,
              itemCount: _subscribers.length,
              itemBuilder: (context, index) {
                final subscriber = _subscribers[index];
                final isSelected = _draft.subscriberId == subscriber.id;
                return Padding(
                  padding: const EdgeInsets.only(left: 8),
                  child: ActionChip(
                    onPressed: () => setState(
                      () => _draft = isSelected
                          ? _draft.copyWith(clearSubscriber: true)
                          : _draft.copyWith(subscriberId: subscriber.id),
                    ),
                    avatar: isSelected
                        ? const Icon(Icons.check_circle, color: AppColors.primary)
                        : const Icon(Icons.person_outline),
                    label: SizedBox(
                      width: 130,
                      child: Text(subscriber.name, overflow: TextOverflow.ellipsis),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ],
    );
  }
}

T? _firstWhereOrNull<T>(Iterable<T> items, bool Function(T) test) {
  for (final item in items) {
    if (test(item)) return item;
  }
  return null;
}
