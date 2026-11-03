import 'package:flutter/material.dart';

import '../../../core/error/failures.dart';
import '../../../core/finance/finance_feedback.dart';
import '../../../core/finance/finance_locator.dart';
import '../../../core/l10n/app_strings.dart';
import '../../../core/l10n/list_options_strings.dart';
import '../../../core/navigation/sub_page.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tiles.dart';
import '../../../core/ui/list_presentation_controls.dart';
import '../../../core/utils/compound_search_query.dart';
import '../../../core/utils/currency_formatter.dart';
import '../domain/cash_session.dart';
import '../domain/cash_session_page.dart';
import '../domain/cash_session_review_permissions.dart';
import '../services/cash_session_service.dart';
import 'widgets/cash_session_report.dart';

/// Supervisor review, separate from the collector's active cash session.
class CashSessionReviewScreen extends StatefulWidget {
  const CashSessionReviewScreen({super.key, this.service, this.permissions});

  final CashSessionService? service;
  final PermissionService? permissions;

  @override
  State<CashSessionReviewScreen> createState() =>
      _CashSessionReviewScreenState();
}

class _CashSessionReviewScreenState extends State<CashSessionReviewScreen> {
  final _collector = TextEditingController();
  CashSessionState? _state = CashSessionState.closed;
  DateTime? _fromDate;
  DateTime? _toDate;
  CashSessionFilters _filters = const CashSessionFilters(
    state: CashSessionState.closed,
  );
  CashSessionPage? _page;
  ListPresentationSettings _presentation = const ListPresentationSettings();
  List<CashSessionCollectorOption> _collectors = const [];
  bool _loading = false;
  final _busy = <int>{};
  String? _error;
  int _request = 0;

  CashSessionService get _service => widget.service ?? sl<CashSessionService>();
  PermissionService? get _permissions =>
      widget.permissions ?? trySl<PermissionService>();
  bool get _canView =>
      _permissions != null &&
      CashSessionReviewPermissions.canView(_permissions!);
  bool get _canDecide =>
      _permissions != null &&
      CashSessionReviewPermissions.canDecide(_permissions!);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _request++;
    _collector.dispose();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    if (!mounted) return;
    if (!_canView) {
      setState(() {
        _page = null;
        _collectors = const [];
      });
      return;
    }
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final result = await _service.list(filters: _filters, page: page);
      if (!mounted || request != _request) return;
      if (!_canView) {
        setState(() {
          _page = null;
          _collectors = const [];
        });
        return;
      }
      // A decision can remove the last row on a filtered page.
      if (result.sessions.isEmpty && page > result.lastPage) {
        await _load(page: result.lastPage);
        return;
      }
      setState(() {
        _page = result;
        _collectors = result.collectors;
      });
    } catch (error) {
      if (!mounted || request != _request) return;
      setState(() {
        _error = _failureMessage(
          error,
          AppStrings.of(context).cashReviewLoadError,
        );
        // Do not keep another user's summaries after an authorization failure.
        if (error is ForbiddenFailure || error is UnauthorizedFailure) {
          _page = null;
          _collectors = const [];
        }
      });
    } finally {
      if (mounted && request == _request) setState(() => _loading = false);
    }
  }

  Future<void> _applyFilters() async {
    final strings = AppStrings.of(context);
    final raw = CompoundSearchQuery.normalizeDigits(_collector.text.trim());
    final collectorId = raw.isEmpty ? null : int.tryParse(raw);
    if (raw.isNotEmpty && (collectorId == null || collectorId <= 0)) {
      setState(() => _error = strings.cashReviewInvalidCollector);
      return;
    }
    if (_fromDate != null && _toDate != null && _fromDate!.isAfter(_toDate!)) {
      setState(() => _error = strings.cashReviewInvalidDates);
      return;
    }
    _filters = CashSessionFilters(
      state: _state,
      addedFrom: _presentation.createdFrom,
      addedTo: _presentation.createdTo,
      collectorId: collectorId,
      fromDate: _fromDate,
      toDate: _toDate,
      pageSize: _presentation.pageSize,
      sortBy: _presentation.sortBy,
      sortDirection: _presentation.descending ? 'desc' : 'asc',
    );
    setState(() => _page = null);
    await _load();
  }

  Future<void> _applyPresentation(ListPresentationSettings settings) async {
    final reload =
        _presentation.sortBy != settings.sortBy ||
        _presentation.descending != settings.descending ||
        _presentation.pageSize != settings.pageSize ||
        _presentation.createdFrom != settings.createdFrom ||
        _presentation.createdTo != settings.createdTo;
    setState(() => _presentation = settings);
    if (!reload) return;
    _filters = _filters.withPresentation(
      pageSize: settings.pageSize,
      addedFrom: settings.createdFrom,
      addedTo: settings.createdTo,
      sortBy: settings.sortBy,
      sortDirection: settings.descending ? 'desc' : 'asc',
    );
    setState(() => _page = null);
    await _load();
  }

  Future<void> _pickDate({required bool from}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: (from ? _fromDate : _toDate) ?? DateTime.now(),
      firstDate: DateTime(1900),
      lastDate: DateTime(2100, 12, 31),
    );
    if (picked == null || !mounted) return;
    setState(() {
      if (from) {
        _fromDate = picked;
      } else {
        _toDate = picked;
      }
    });
  }

  Future<void> _decide(CashSession session, {required bool approve}) async {
    if (!_canDecide ||
        session.state != CashSessionState.closed ||
        _busy.contains(session.id)) {
      return;
    }
    setState(() => _busy.add(session.id));
    try {
      final latest = await _service.byId(session.id);
      if (!mounted || !_canDecide) return;
      if (latest.state != CashSessionState.closed) {
        AppFeedback.error(context, AppStrings.of(context).conflictError);
        await _load(page: _page?.currentPage ?? 1);
        return;
      }
      final saved = await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (_) => _CashDecisionDialog(
          session: latest,
          approve: approve,
          service: _service,
          canDecide: () => _canDecide,
        ),
      );
      if (saved != true || !mounted) return;
      final strings = AppStrings.of(context);
      AppFeedback.success(
        context,
        approve ? strings.cashReviewApproved : strings.cashReviewRejected,
      );
      await _load(page: _page?.currentPage ?? 1);
    } catch (error) {
      if (mounted) {
        AppFeedback.error(
          context,
          _failureMessage(
            error,
            AppStrings.of(context).cashReviewDecisionError,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy.remove(session.id));
    }
  }

  Future<void> _details(CashSession session) async {
    if (!_canView || _busy.contains(session.id)) return;
    setState(() => _busy.add(session.id));
    try {
      final latest = await _service.byId(session.id);
      if (!mounted || !_canView) return;
      await showModalBottomSheet<void>(
        context: context,
        showDragHandle: true,
        useSafeArea: true,
        isScrollControlled: true,
        builder: (context) => SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                '${AppStrings.of(context).cashReviewDetails} #${latest.id}',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              Text(
                '${latest.collector ?? '—'} · ${CashSessionFilters.dateValue(latest.sessionDate)}',
              ),
              Text(latest.state.arabicLabel),
              const SizedBox(height: 12),
              _SessionFigures(session: latest),
              if (latest.notes?.isNotEmpty == true) Text(latest.notes!),
              if (latest.closingNotes?.isNotEmpty == true)
                Text(
                  '${AppStrings.of(context).cashReviewClosingNotes}: ${latest.closingNotes}',
                ),
              if (latest.approvalNotes?.isNotEmpty == true)
                Text(
                  '${AppStrings.of(context).cashReviewApprovalNotes}: ${latest.approvalNotes}',
                ),
              if (latest.approvedBy != null) Text(latest.approvedBy!),
              const SizedBox(height: 16),
              CashSessionReportButton(session: latest),
            ],
          ),
        ),
      );
    } catch (error) {
      if (mounted) {
        AppFeedback.error(
          context,
          _failureMessage(error, AppStrings.of(context).cashReviewLoadError),
        );
      }
    } finally {
      if (mounted) setState(() => _busy.remove(session.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    return SubPageScaffold(
      title: strings.cashReviewTitle,
      subtitle: strings.cashReviewSubtitle,
      actions: [
        if (_canView)
          IgnorePointer(
            ignoring: _loading,
            child: ListPresentationControls(
              settings: _presentation,
              filterOptions: const ListFilterOptions(
                hasCreatedAt: true,
                search: false,
              ),
              sortOptions: const {
                'id': ListOptionsStrings.id,
                'created_at': ListOptionsStrings.createdAt,
                'session_date': ListOptionsStrings.date,
                'collector_name': ListOptionsStrings.collector,
                'expected_amount': ListOptionsStrings.expected,
                'counted_amount': ListOptionsStrings.counted,
                'variance_amount': ListOptionsStrings.variance,
                'closed_at': ListOptionsStrings.closedAt,
              },
              fields: const {
                'collector': ListOptionsStrings.collector,
                'date': ListOptionsStrings.date,
                'square': ListOptionsStrings.square,
                'expected': ListOptionsStrings.expected,
                'counted': ListOptionsStrings.counted,
                'variance': ListOptionsStrings.variance,
              },
              onChanged: _applyPresentation,
            ),
          ),
        IconButton(
          tooltip: strings.cashReviewRefresh,
          onPressed: !_canView || _loading
              ? null
              : () => _load(page: _page?.currentPage ?? 1),
          icon: const Icon(Icons.refresh),
        ),
      ],
      child: !_canView
          ? Center(child: Text(strings.forbiddenError))
          : RefreshIndicator(
              onRefresh: () => _load(page: _page?.currentPage ?? 1),
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                children: [
                  _filterCard(strings),
                  if (_loading) const LinearProgressIndicator(),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    AppNotice(
                      title: strings.errorTitle,
                      message: _error!,
                      icon: Icons.error_outline,
                    ),
                    TextButton(
                      onPressed: _loading
                          ? null
                          : () => _load(page: _page?.currentPage ?? 1),
                      child: Text(strings.retry),
                    ),
                  ],
                  if (!_loading &&
                      _error == null &&
                      _page?.sessions.isEmpty == true)
                    Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(strings.cashReviewEmpty),
                    ),
                  for (final session
                      in _page?.sessions ?? const <CashSession>[]) ...[
                    const SizedBox(height: 12),
                    _sessionCard(session, strings),
                  ],
                  if (_page != null) ...[
                    const SizedBox(height: 12),
                    Wrap(
                      alignment: WrapAlignment.center,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      spacing: 12,
                      children: [
                        TextButton(
                          onPressed: !_loading && _page!.hasPrevious
                              ? () => _load(page: _page!.currentPage - 1)
                              : null,
                          child: Text(strings.cashReviewPrevious),
                        ),
                        Text(
                          '${_page!.currentPage} / ${_page!.lastPage} · ${_page!.total}',
                        ),
                        TextButton(
                          onPressed: !_loading && _page!.hasNext
                              ? () => _load(page: _page!.currentPage + 1)
                              : null,
                          child: Text(strings.cashReviewNext),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
    );
  }

  Widget _filterCard(AppStrings strings) => AppCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DropdownButtonFormField<CashSessionState?>(
          key: ValueKey(_state),
          initialValue: _state,
          hint: Text(strings.cashReviewAllStates),
          isExpanded: true,
          decoration: InputDecoration(labelText: strings.cashReviewState),
          items: [
            DropdownMenuItem(
              value: null,
              child: Text(strings.cashReviewAllStates),
            ),
            for (final state in CashSessionState.values)
              DropdownMenuItem(value: state, child: Text(state.arabicLabel)),
          ],
          onChanged: _loading
              ? null
              : (state) => setState(() => _state = state),
        ),
        const SizedBox(height: 12),
        if (_collectors.isNotEmpty)
          DropdownButtonFormField<int?>(
            key: ValueKey('collector-${_collector.text}'),
            initialValue:
                _collectors.any((item) => '${item.id}' == _collector.text)
                ? int.tryParse(_collector.text)
                : null,
            isExpanded: true,
            hint: Text(strings.cashReviewAllCollectors),
            decoration: InputDecoration(labelText: strings.cashReviewCollector),
            items: [
              DropdownMenuItem(
                value: null,
                child: Text(strings.cashReviewAllCollectors),
              ),
              for (final collector in _collectors)
                DropdownMenuItem(
                  value: collector.id,
                  child: Text(collector.name),
                ),
            ],
            onChanged: _loading
                ? null
                : (id) =>
                      setState(() => _collector.text = id?.toString() ?? ''),
          )
        else
          TextField(
            controller: _collector,
            enabled: !_loading,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(
              labelText: strings.cashReviewCollectorId,
            ),
            onSubmitted: (_) => _applyFilters(),
          ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            _dateButton(strings.cashReviewFromDate, _fromDate, true, strings),
            _dateButton(strings.cashReviewToDate, _toDate, false, strings),
          ],
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          children: [
            FilledButton(
              onPressed: _loading ? null : _applyFilters,
              child: Text(strings.cashReviewApplyFilters),
            ),
            TextButton(
              onPressed: _loading
                  ? null
                  : () {
                      setState(() {
                        _state = null;
                        _fromDate = null;
                        _toDate = null;
                        _collector.clear();
                      });
                      _applyFilters();
                    },
              child: Text(strings.cashReviewResetFilters),
            ),
          ],
        ),
      ],
    ),
  );

  Widget _dateButton(
    String label,
    DateTime? date,
    bool from,
    AppStrings strings,
  ) => Wrap(
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      OutlinedButton.icon(
        onPressed: _loading ? null : () => _pickDate(from: from),
        icon: const Icon(Icons.calendar_today_outlined, size: 16),
        label: Text(
          '$label${date == null ? '' : ': ${CashSessionFilters.dateValue(date)}'}',
        ),
      ),
      if (date != null)
        IconButton(
          tooltip: strings.cashReviewClearDate,
          onPressed: _loading
              ? null
              : () => setState(() {
                  if (from) {
                    _fromDate = null;
                  } else {
                    _toDate = null;
                  }
                }),
          icon: const Icon(Icons.close, size: 18),
        ),
    ],
  );

  Widget _sessionCard(CashSession session, AppStrings strings) {
    final busy = _busy.contains(session.id);
    return AppCard(
      padding: EdgeInsets.all(_presentation.compact ? 10 : 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            '#${session.id}${_presentation.isVisible('collector') ? ' — ${session.collector ?? '—'}' : ''}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          Text(
            '${_presentation.isVisible('date') ? '${CashSessionFilters.dateValue(session.sessionDate)} · ' : ''}${session.state.arabicLabel}',
          ),
          if (_presentation.isVisible('square') && session.square != null)
            Text(session.square!),
          const SizedBox(height: 8),
          _SessionFigures(
            session: session,
            hiddenFields: _presentation.hiddenFields,
          ),
          if (busy) const LinearProgressIndicator(),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              TextButton.icon(
                onPressed: busy ? null : () => _details(session),
                icon: const Icon(Icons.info_outline),
                label: Text(strings.cashReviewDetails),
              ),
              TextButton.icon(
                onPressed: busy
                    ? null
                    : () => openCashSessionReportPdf(context, session),
                icon: const Icon(Icons.picture_as_pdf_outlined),
                label: Text(strings.cashReviewReport),
              ),
              if (_canDecide && session.state == CashSessionState.closed) ...[
                FilledButton(
                  onPressed: busy || _loading
                      ? null
                      : () => _decide(session, approve: true),
                  child: Text(strings.cashReviewApprove),
                ),
                OutlinedButton(
                  onPressed: busy || _loading
                      ? null
                      : () => _decide(session, approve: false),
                  child: Text(strings.cashReviewReject),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _SessionFigures extends StatelessWidget {
  const _SessionFigures({required this.session, this.hiddenFields = const {}});
  final CashSession session;
  final Set<String> hiddenFields;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!hiddenFields.contains('expected'))
          Text(
            '${strings.cashReviewExpected}: ${CurrencyFormatter.amount(session.expectedAmount)}',
          ),
        if (!hiddenFields.contains('counted'))
          Text(
            '${strings.cashReviewCounted}: ${session.countedAmount == null ? '—' : CurrencyFormatter.amount(session.countedAmount!)}',
          ),
        if (!hiddenFields.contains('variance'))
          Text(
            '${strings.cashReviewVariance}: ${session.varianceAmount == null ? '—' : CurrencyFormatter.amount(session.varianceAmount!)}',
          ),
      ],
    );
  }
}

class _CashDecisionDialog extends StatefulWidget {
  const _CashDecisionDialog({
    required this.session,
    required this.approve,
    required this.service,
    required this.canDecide,
  });
  final CashSession session;
  final bool approve;
  final CashSessionService service;
  final bool Function() canDecide;

  @override
  State<_CashDecisionDialog> createState() => _CashDecisionDialogState();
}

class _CashDecisionDialogState extends State<_CashDecisionDialog> {
  final _form = GlobalKey<FormState>();
  final _notes = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || !_form.currentState!.validate()) return;
    if (!widget.canDecide()) {
      setState(() => _error = AppStrings.of(context).forbiddenError);
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.service.approve(
        widget.session.id,
        approve: widget.approve,
        notes: _notes.text.trim(),
      );
      if (mounted) Navigator.pop(context, true);
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = _failureMessage(
            error,
            AppStrings.of(context).cashReviewDecisionError,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final label = widget.approve
        ? strings.cashReviewApprove
        : strings.cashReviewReject;
    return PopScope(
      canPop: !_saving,
      child: AlertDialog(
        title: Text('$label #${widget.session.id}'),
        content: SingleChildScrollView(
          child: Form(
            key: _form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _SessionFigures(session: widget.session),
                if (widget.session.closingNotes?.isNotEmpty == true)
                  Text(
                    '${strings.cashReviewClosingNotes}: ${widget.session.closingNotes}',
                  ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _notes,
                  enabled: !_saving,
                  minLines: 2,
                  maxLines: 4,
                  maxLength: 1000,
                  decoration: InputDecoration(
                    labelText: widget.approve
                        ? strings.cashReviewApprovalNotes
                        : strings.cashReviewRejectionReason,
                  ),
                  validator: (value) =>
                      !widget.approve && (value ?? '').trim().length < 10
                      ? strings.cashReviewReasonRequired
                      : null,
                ),
                if (_error != null)
                  Text(
                    _error!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                if (_saving) const LinearProgressIndicator(),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: _saving ? null : () => Navigator.pop(context, false),
            child: Text(strings.cancel),
          ),
          FilledButton(onPressed: _saving ? null : _save, child: Text(label)),
        ],
      ),
    );
  }
}

String _failureMessage(Object error, String fallback) {
  if (error is ValidationFailure) {
    final fields = error.fieldErrors.values.expand((messages) => messages);
    if (fields.isNotEmpty) return fields.first;
  }
  return error is AppFailure ? error.message : fallback;
}
