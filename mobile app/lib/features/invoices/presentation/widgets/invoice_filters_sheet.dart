import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/finance/finance_locator.dart';
import '../../../../core/l10n/invoice_summary_strings.dart';
import '../../../../core/ui/app_filters.dart';
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
      AppFilterSheet.show<void>(
        context: context,
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
    ('unpaid', InvoiceSummaryStrings.unpaid),
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
    return AppFilterSheet(
      title: 'تصفية الفواتير',
      resultCountLabel: _draft.activeFilterCount == 0
          ? 'كل الفواتير'
          : '${_draft.activeFilterCount} فلتر مطبّق',
      applyLabel: 'عرض النتائج',
      onApply: () {
        widget.onSubmit(_draft);
        Navigator.of(context).maybePop();
      },
      onReset: () => setState(() => _draft = const InvoiceQuery()),
      resetEnabled: _draft.activeFilterCount > 0,
      sections: <Widget>[
        AppFilterSection(
          title: 'حالة الفاتورة',
          child: _chipWrap(
            _statusOptions.map((option) {
              final value = option.$1.isEmpty ? null : option.$1;
              return AppFilterChip(
                label: option.$2,
                selected: _draft.status == value,
                onTap: () => setState(
                  () => _draft = value == null
                      ? _draft.copyWith(clearStatus: true)
                      : _draft.copyWith(status: value),
                ),
              );
            }).toList(),
          ),
        ),
        AppFilterSection(
          title: 'نوع الفاتورة',
          child: _chipWrap(
            _typeOptions.map((option) {
              final value = option.$1.isEmpty ? null : option.$1;
              return AppFilterChip(
                label: option.$2,
                selected: _draft.invoiceType == value,
                onTap: () => setState(
                  () => _draft = value == null
                      ? _draft.copyWith(clearInvoiceType: true)
                      : _draft.copyWith(invoiceType: value),
                ),
              );
            }).toList(),
          ),
        ),
        AppFilterSection(
          title: 'سلة المحذوفات',
          child: _chipWrap(
            _trashedOptions.map(
              (option) => AppFilterChip(
                label: option.$2,
                selected: _draft.trashed == option.$1,
                onTap: () => setState(
                  () => _draft = _draft.copyWith(trashed: option.$1),
                ),
              ),
            ).toList(),
          ),
        ),
        AppFilterSection(
          title: 'فترة الفوترة',
          child: AppFilterDropdown<String?>(
            label: 'الفترة',
            icon: Icons.date_range_rounded,
            value: _draft.billingPeriod,
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
          ),
        ),
        AppFilterSection(
          title: 'المربع السكني',
          child: AppFilterDropdown<int?>(
            label: 'المربع',
            icon: Icons.grid_view_rounded,
            value: widget.squares.any((s) => s.id == _draft.squareId)
                ? _draft.squareId
                : null,
            items: <DropdownMenuItem<int?>>[
              const DropdownMenuItem<int?>(value: null, child: Text('كل المربعات')),
              ...widget.squares.map(
                (square) => DropdownMenuItem<int?>(
                  value: square.id,
                  child: Text(
                    square.subscribersCount == null
                        ? square.displayName
                        : '${square.displayName} (${square.subscribersCount})',
                  ),
                ),
              ),
            ],
            onChanged: (value) => setState(
              () => value == null
                  ? _draft = _draft.copyWith(clearSquare: true)
                  : _draft = _draft.copyWith(squareId: value),
            ),
          ),
        ),
        AppFilterSection(
          title: 'المشترك',
          subtitle: 'ابحث بالاسم أو رقم الهاتف',
          child: _subscriberPicker(),
        ),
        const SizedBox(height: 16),
      ],
    );
  }

  Widget _chipWrap(List<Widget> chips) => Wrap(
        spacing: 6,
        runSpacing: 6,
        children: chips,
      );

  Widget _subscriberPicker() {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        TextField(
          onSubmitted: _loadSubscribers,
          textInputAction: TextInputAction.search,
          style: const TextStyle(fontSize: 13.5),
          decoration: InputDecoration(
            hintText: 'اسم المشترك أو الهاتف',
            isDense: true,
            fillColor: scheme.surfaceContainerHighest,
            contentPadding: const EdgeInsetsDirectional.fromSTEB(0, 12, 0, 12),
            prefixIcon: Icon(Icons.search_rounded, size: 18, color: scheme.onSurfaceVariant),
            prefixIconConstraints: const BoxConstraints(minWidth: 40, minHeight: 40),
            suffixIcon: _loadingSubscribers
                ? const Padding(
                    padding: EdgeInsets.all(13),
                    child: SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
                : IconButton(
                    tooltip: 'بحث',
                    visualDensity: VisualDensity.compact,
                    icon: Icon(Icons.arrow_forward_rounded, size: 17, color: scheme.onSurfaceVariant),
                    onPressed: () => _loadSubscribers(''),
                  ),
          ),
        ),
        if (_draft.subscriberId != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: AppFilterChip(
                label: 'مشترك محدد · ${_selectedSubscriberName()}',
                selected: true,
                icon: Icons.person_rounded,
                onRemove: () => setState(
                  () => _draft = _draft.copyWith(clearSubscriber: true),
                ),
              ),
            ),
          ),
        if (_subscribers.isNotEmpty) ...<Widget>[
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: _subscribers
                .take(12)
                .map(
                  (subscriber) => AppFilterChip(
                    label: subscriber.name,
                    selected: _draft.subscriberId == subscriber.id,
                    icon: Icons.person_outline_rounded,
                    onTap: () => setState(
                      () => _draft = _draft.subscriberId == subscriber.id
                          ? _draft.copyWith(clearSubscriber: true)
                          : _draft.copyWith(subscriberId: subscriber.id),
                    ),
                  ),
                )
                .toList(),
          ),
        ],
      ],
    );
  }

  String _selectedSubscriberName() {
    for (final subscriber in _subscribers) {
      if (subscriber.id == _draft.subscriberId) return subscriber.name;
    }
    return '#${_draft.subscriberId}';
  }
}
