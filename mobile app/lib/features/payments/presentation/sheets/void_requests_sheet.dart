import 'package:flutter/material.dart';

import '../../../../core/error/failures.dart';
import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/l10n/app_strings.dart';
import '../../../../core/l10n/list_options_strings.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/list_presentation_controls.dart';
import '../../../../core/ui/record_location_label.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../data/models/payment_model.dart';
import '../../domain/repositories/payments_repository.dart';
import '../../domain/repositories/sorted_void_requests_repository.dart';

/// ورقة «طلبات الإلغاء المعلّقة» للمشرف (خطة 2.0 — الفصل 5.3).
///
/// سابقاً كانت طلبات الإلغاء الكبيرة تُنشأ من التطبيق (`pending_approval`)
/// ولا يمكن الوصول إليها من الجوال إطلاقاً — كان القرار متاحاً في لوحة
/// Filament فقط. هذه الورقة تعرض الطلبات وتتيح الاعتماد أو الرفض مع ملاحظات.
class VoidRequestsSheet extends StatefulWidget {
  const VoidRequestsSheet({required this.repository, super.key});

  final PaymentsRepository repository;

  /// يفتح الورقة ويعيد عدد الطلبات المتبقية بعد الإغلاق (لتحديث الشارة).
  static Future<int?> show(
    BuildContext context,
    PaymentsRepository repository,
  ) => showModalBottomSheet<int>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: Colors.transparent,
    builder: (_) => FractionallySizedBox(
      heightFactor: .92,
      child: ClipRRect(
        borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
        child: VoidRequestsSheet(repository: repository),
      ),
    ),
  );

  @override
  State<VoidRequestsSheet> createState() => _VoidRequestsSheetState();
}

class _VoidRequestsSheetState extends State<VoidRequestsSheet> {
  List<PaymentVoidRequest>? _requests;
  String? _error;
  final Set<int> _busy = <int>{};
  int _page = 1;
  bool _hasNext = false;
  bool _loading = false;
  ListPresentationSettings _presentation = const ListPresentationSettings(
    sortBy: 'void_requested_at',
  );

  void _applyPresentation(ListPresentationSettings settings) {
    if (_loading || _busy.isNotEmpty) return;
    final previous = _presentation;
    setState(() => _presentation = settings);
    if (previous.sortBy != settings.sortBy ||
        previous.descending != settings.descending ||
        previous.pageSize != settings.pageSize ||
        previous.pathNumber != settings.pathNumber ||
        previous.createdFrom != settings.createdFrom ||
        previous.createdTo != settings.createdTo) {
      _load();
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({int page = 1}) async {
    if (_loading) return;
    final hadError = _error != null;
    setState(() {
      _error = null;
      _loading = true;
      // بعد خطأ سابق نُظهر مؤشر التحميل من جديد.
      if (hadError) _requests = null;
    });
    try {
      final repository = widget.repository;
      final requests = repository is SortedVoidRequestsRepository
          ? await (repository as SortedVoidRequestsRepository)
                .sortedVoidRequests(
                  page: page,
                  pathNumber: _presentation.pathNumber,
                  addedFrom: _presentation.createdFrom,
                  addedTo: _presentation.createdTo,
                  perPage: _presentation.pageSize,
                  sortBy: _presentation.sortBy,
                  sortDirection: _presentation.descending ? 'desc' : 'asc',
                )
          : await repository.voidRequests(
              page: page,
              perPage: _presentation.pageSize,
            );
      if (!mounted) return;
      // The repository returns rows only, without paginator metadata. A full
      // page allows probing the next page; an empty probe retains this page.
      setState(() {
        if (requests.isEmpty && page > _page && _requests?.isNotEmpty == true) {
          _hasNext = false;
        } else {
          _requests = requests;
          _page = page;
          _hasNext = requests.length == _presentation.pageSize;
        }
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = _humanize(error);
      });
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _decide(
    PaymentVoidRequest request, {
    required bool approve,
  }) async {
    if (_busy.contains(request.id) || _loading) return;
    final notesController = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(approve ? 'اعتماد إلغاء السند' : 'رفض طلب الإلغاء'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              approve
                  ? 'سيُلغى السند ${request.receiptNumber} نهائياً وتُعاد احتساب الأرصدة.'
                  : 'سيُرفض الطلب ويبقى السند ${request.receiptNumber} صالحاً.',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: notesController,
              maxLines: 2,
              decoration: const InputDecoration(
                labelText: 'ملاحظات (اختياري)',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            style: approve
                ? FilledButton.styleFrom(backgroundColor: AppColors.danger)
                : null,
            onPressed: () => Navigator.pop(dialogContext, true),
            child: Text(approve ? 'اعتماد الإلغاء' : 'رفض الطلب'),
          ),
        ],
      ),
    );
    final notes = notesController.text;
    notesController.dispose();
    if (confirmed != true || !mounted) return;

    setState(() => _busy.add(request.id));
    try {
      await widget.repository.decideVoidRequest(
        request.id,
        approve: approve,
        notes: notes,
      );
      if (!mounted) return;
      AppFeedback.success(
        context,
        approve
            ? 'تم اعتماد الإلغاء وإعادة احتساب الأرصدة.'
            : 'تم رفض الطلب وبقي السند صالحاً.',
      );
      await _load();
      if (!mounted) return;
      Navigator.pop(
        context,
        (_requests ?? const <PaymentVoidRequest>[]).length,
      );
    } catch (error) {
      if (!mounted) return;
      AppFeedback.error(context, _humanize(error));
    } finally {
      if (mounted) setState(() => _busy.remove(request.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final requests = _requests;
    return Scaffold(
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      appBar: AppBar(
        title: const Text('طلبات الإلغاء المعلّقة'),
        actions: <Widget>[
          if (widget.repository is SortedVoidRequestsRepository)
            IgnorePointer(
              ignoring: _loading || _busy.isNotEmpty,
              child: ListPresentationControls(
                settings: _presentation,
                filterOptions: widget.repository is SortedVoidRequestsRepository
                    ? const ListFilterOptions(
                        hasPath: true,
                        hasCreatedAt: true,
                        search: false,
                      )
                    : null,
                sortOptions: const {
                  'void_requested_at': ListOptionsStrings.date,
                  'receipt_number': ListOptionsStrings.receiptNumber,
                  'amount': ListOptionsStrings.amount,
                  'subscriber_name': ListOptionsStrings.subscriber,
                  'id': ListOptionsStrings.id,
                  'created_at': ListOptionsStrings.createdAt,
                  'path_number': ListOptionsStrings.path,
                },
                fields: const {
                  'date': ListOptionsStrings.date,
                  'collector': ListOptionsStrings.collector,
                },
                onChanged: _applyPresentation,
              ),
            ),
          IconButton(
            tooltip: 'تحديث',
            onPressed: _loading || _busy.isNotEmpty
                ? null
                : () => _load(page: _page),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: Column(
        children: [
          if (_loading) const LinearProgressIndicator(),
          if (_error != null && requests?.isNotEmpty == true)
            Padding(padding: const EdgeInsets.all(12), child: Text(_error!)),
          Expanded(child: _buildBody(requests)),
          if (requests != null && requests.isNotEmpty)
            SafeArea(
              top: false,
              child: Wrap(
                alignment: WrapAlignment.center,
                crossAxisAlignment: WrapCrossAlignment.center,
                spacing: 16,
                children: [
                  TextButton(
                    onPressed: _loading || _busy.isNotEmpty || _page <= 1
                        ? null
                        : () => _load(page: _page - 1),
                    child: Text(AppStrings.of(context).cashReviewPrevious),
                  ),
                  Text('$_page'),
                  TextButton(
                    onPressed: _loading || _busy.isNotEmpty || !_hasNext
                        ? null
                        : () => _load(page: _page + 1),
                    child: Text(AppStrings.of(context).cashReviewNext),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  String _humanize(Object error) =>
      error is AppFailure ? error.message : AppStrings.of(context).unknownError;

  Widget _buildBody(List<PaymentVoidRequest>? requests) {
    if (requests == null && _error == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && (requests == null || requests.isEmpty)) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text('تعذر جلب الطلبات: $_error', textAlign: TextAlign.center),
        ),
      );
    }
    if (requests == null || requests.isEmpty) {
      return const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(Icons.verified_outlined, size: 48, color: AppColors.success),
            SizedBox(height: 12),
            Text('لا توجد طلبات إلغاء بانتظار الاعتماد.'),
          ],
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: () => _load(page: _page),
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
        itemCount: requests.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          final request = requests[index];
          final busy = _busy.contains(request.id);
          return Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: EdgeInsets.all(_presentation.compact ? 8 : 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      Expanded(
                        child: Text(
                          'سند ${request.receiptNumber} — ${CurrencyFormatter.withLabel(request.amount)}',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                      ),
                      if (busy)
                        const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                    ],
                  ),
                  RecordLocationLabel(location: request.location),
                  if (request.subscriber != null)
                    Text('المشترك: ${request.subscriber}'),
                  if (request.reason != null &&
                      request.reason!.trim().isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(
                        'السبب: ${request.reason}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                  const SizedBox(height: 4),
                  Text(
                    <String>[
                      if (_presentation.isVisible('collector') &&
                          request.requestedBy != null)
                        'طَلبها: ${request.requestedBy}',
                      if (_presentation.isVisible('date') &&
                          request.requestedAt != null)
                        'في ${request.requestedAt!.toIso8601String().substring(0, 16).replaceAll("T", " ")}',
                    ].join(' • '),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: <Widget>[
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: busy || _loading
                              ? null
                              : () => _decide(request, approve: false),
                          icon: const Icon(Icons.close),
                          label: const Text('رفض'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: FilledButton.icon(
                          style: FilledButton.styleFrom(
                            backgroundColor: AppColors.danger,
                          ),
                          onPressed: busy || _loading
                              ? null
                              : () => _decide(request, approve: true),
                          icon: const Icon(Icons.check),
                          label: const Text('اعتماد الإلغاء'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
