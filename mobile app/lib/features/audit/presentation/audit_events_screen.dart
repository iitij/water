import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../core/error/failures.dart';
import '../../../core/l10n/app_strings.dart';
import '../../../core/l10n/list_options_strings.dart';
import '../../../core/navigation/sub_page.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/ui/list_presentation_controls.dart';
import '../../admin_shared/data/admin_api_client.dart';
import '../data/audit_events_repository.dart';

class AuditEventsScreen extends StatefulWidget {
  const AuditEventsScreen({super.key, this.repository, this.permissions});
  final AuditEventsRepository? repository;
  final PermissionService? permissions;

  static bool canView(PermissionService permissions) =>
      permissions.isAdmin ||
      permissions.can('audit.view') ||
      permissions.can('ViewAny:AuditEvent');

  @override
  State<AuditEventsScreen> createState() => _AuditEventsScreenState();
}

class _AuditEventsScreenState extends State<AuditEventsScreen> {
  final _query = TextEditingController();
  final _entityId = TextEditingController();
  AuditEventsPage? _page;
  AuditEventFilters _filters = const AuditEventFilters();
  ListPresentationSettings _view = const ListPresentationSettings();
  bool _customView = false;
  String? _event, _source, _entityType, _error;
  int? _actor;
  DateTime? _from, _to;
  bool _loading = false;
  int _request = 0;
  bool get _canView {
    final permissions =
        widget.permissions ??
        (GetIt.I.isRegistered<PermissionService>()
            ? GetIt.I<PermissionService>()
            : null);
    return permissions != null && AuditEventsScreen.canView(permissions);
  }

  AuditEventsRepository get _repository =>
      widget.repository ?? AuditEventsRepository(GetIt.I<AdminApiClient>());

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _request++;
    _query.dispose();
    _entityId.dispose();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    if (!_canView) return;
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      var result = await _repository.fetch(_filters, page: page);
      if (!mounted || request != _request) return;
      // Laravel legitimately returns an empty page beyond last_page when the
      // data shrinks between requests. Return to its last available page.
      if (_canView && result.events.isEmpty && result.currentPage > result.lastPage) {
        result = await _repository.fetch(_filters, page: result.lastPage);
        if (!mounted || request != _request) return;
      }
      if (!_canView) {
        setState(() {
          _page = null;
          _loading = false;
        });
        return;
      }
      setState(() {
        _page = result;
        _loading = false;
      });
    } catch (error) {
      if (!mounted || request != _request) return;
      setState(() {
        _loading = false;
        if (!_canView ||
            error is AdminApiException &&
                (error.statusCode == 401 || error.statusCode == 403)) {
          _page = null;
        }
        _error = error is AdminApiException
            ? error.message
            : error is AppFailure
            ? error.message
            : AppStrings.of(context).unknownError;
      });
    }
  }

  void _apply() {
    final filters = AuditEventFilters(
      query: _query.text,
      event: _event,
      source: _source,
      actorId: _actor,
      entityType: _entityType,
      entityId: _entityId.text,
      fromDate: _from,
      toDate: _to,
      pageSize: _view.pageSize,
      sortBy: _customView ? _view.sortBy : null,
      descending: _view.descending,
    );
    if (!filters.validDates) {
      setState(() => _error = AppStrings.of(context).cashReviewInvalidDates);
      return;
    }
    _filters = filters;
    _load();
  }

  Future<void> _pickDate(bool from) async {
    final date = await showDatePicker(
      context: context,
      initialDate: (from ? _from : _to) ?? DateTime.now(),
      firstDate: DateTime(2000),
      lastDate: DateTime(2200),
    );
    if (date != null && mounted) {
      setState(() {
        if (from) {
          _from = date;
        } else {
          _to = date;
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    return SubPageScaffold(
      title: strings.auditTitle,
      subtitle: strings.auditSubtitle,
      actions: _canView
          ? [
              ListPresentationControls(
                settings: _view,
                sortOptions: {
                  'id': ListOptionsStrings.id,
                  'event': strings.auditEvent,
                  'source': strings.auditSource,
                  'occurred_at': strings.auditOccurredAt,
                  'created_at': ListOptionsStrings.createdAt,
                  'actor_name': strings.auditActor,
                },
                fields: {
                  'actor': strings.auditActor,
                  'source': strings.auditSource,
                  'date': strings.auditOccurredAt,
                  'entity': strings.auditEntity,
                },
                onChanged: (view) {
                  setState(() {
                    _view = view;
                    _customView = true;
                  });
                  _apply();
                },
              ),
            ]
          : const [],
      child: !_canView
          ? Center(child: Text(strings.forbiddenError))
          : Column(
              children: [
                ExpansionTile(
                  title: Text(strings.cashReviewApplyFilters),
                  initiallyExpanded: false,
                  childrenPadding: const EdgeInsets.all(16),
                  children: [
                    ConstrainedBox(
                      constraints: BoxConstraints(
                        maxHeight: MediaQuery.sizeOf(context).height * .4,
                      ),
                      child: SingleChildScrollView(
                        child: Column(
                          children: [
                            TextField(
                              controller: _query,
                              decoration: InputDecoration(
                                labelText: strings.auditSearch,
                              ),
                              onSubmitted: (_) => _apply(),
                            ),
                            _filter<String>(
                              strings.auditEvent,
                              _event,
                              _page?.eventTypes ?? [],
                              (value) => setState(() => _event = value),
                            ),
                            _filter<String>(
                              strings.auditSource,
                              _source,
                              const ['mobile', 'api', 'web', 'console'],
                              (value) => setState(() => _source = value),
                              labelOf: (value) =>
                                  strings.auditSourceLabel(value),
                            ),
                            DropdownButtonFormField<int>(
                              key: ValueKey('actor-$_actor'),
                              initialValue: _actor,
                              decoration: InputDecoration(
                                labelText: strings.auditActor,
                              ),
                              isExpanded: true,
                              items: [
                                DropdownMenuItem<int>(
                                  child: Text(strings.auditAll),
                                ),
                                for (final actor
                                    in _page?.actors ??
                                        <Map<String, dynamic>>[])
                                  DropdownMenuItem(
                                    value: int.tryParse('${actor['id']}'),
                                    child: Text('${actor['name']}'),
                                  ),
                              ],
                              onChanged: (value) =>
                                  setState(() => _actor = value),
                            ),
                            _filter<String>(
                              strings.auditEntity,
                              _entityType,
                              _page?.entityTypes ?? [],
                              (value) => setState(() => _entityType = value),
                            ),
                            TextField(
                              controller: _entityId,
                              decoration: InputDecoration(
                                labelText: strings.auditEntityId,
                              ),
                            ),
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                OutlinedButton(
                                  onPressed: () => _pickDate(true),
                                  child: Text(
                                    '${strings.cashReviewFromDate}: ${_date(_from)}',
                                  ),
                                ),
                                OutlinedButton(
                                  onPressed: () => _pickDate(false),
                                  child: Text(
                                    '${strings.cashReviewToDate}: ${_date(_to)}',
                                  ),
                                ),
                                FilledButton(
                                  onPressed: _loading ? null : _apply,
                                  child: Text(strings.cashReviewApplyFilters),
                                ),
                                TextButton(
                                  onPressed: _loading
                                      ? null
                                      : () {
                                          _query.clear();
                                          _entityId.clear();
                                          setState(() {
                                            _actor = null;
                                            _event = null;
                                            _source = null;
                                            _entityType = null;
                                            _from = null;
                                            _to = null;
                                          });
                                          _apply();
                                        },
                                  child: Text(strings.cashReviewResetFilters),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
                if (_loading) const LinearProgressIndicator(),
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      children: [
                        Text(
                          _error!,
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                        TextButton(
                          onPressed: () => _load(page: _page?.currentPage ?? 1),
                          child: Text(strings.retry),
                        ),
                      ],
                    ),
                  ),
                Expanded(
                  child: _page == null && _loading
                      ? const SizedBox.shrink()
                      : RefreshIndicator(
                          onRefresh: () => _load(page: _page?.currentPage ?? 1),
                          child: (_page?.events.isEmpty ?? true)
                              ? ListView(
                                  physics:
                                      const AlwaysScrollableScrollPhysics(),
                                  children: [
                                    Padding(
                                      padding: const EdgeInsets.all(32),
                                      child: Center(
                                        child: Text(strings.auditEmpty),
                                      ),
                                    ),
                                  ],
                                )
                              : ListView.separated(
                                  physics:
                                      const AlwaysScrollableScrollPhysics(),
                                  itemCount: _page!.events.length,
                                  separatorBuilder: (_, index) =>
                                      const Divider(height: 1),
                                  itemBuilder: (context, index) {
                                    final event = _page!.events[index];
                                    final actor = _actorName(event, strings);
                                    return ListTile(
                                      dense: _view.compact,
                                      title: Text('${event['event'] ?? ''}'),
                                      subtitle: Text(
                                        [
                                          if (_view.isVisible('actor')) actor,
                                          if (_view.isVisible('source'))
                                            strings.auditSourceLabel(
                                              event['source']?.toString(),
                                            ),
                                          if (_view.isVisible('date'))
                                            '${event['occurred_at'] ?? event['created_at'] ?? ''}',
                                          if (_view.isVisible('entity'))
                                            '${event['entity_type'] ?? ''} #${event['entity_id'] ?? ''}',
                                        ].join(' • '),
                                      ),
                                      trailing: const Icon(
                                        Icons.chevron_left_rounded,
                                      ),
                                      onTap: () => _showDetails(event),
                                    );
                                  },
                                ),
                        ),
                ),
                if (_page != null)
                  SafeArea(
                    top: false,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 16,
                        vertical: 8,
                      ),
                      child: Wrap(
                        spacing: 12,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Text(
                            '${strings.cashReviewPage} ${_page!.currentPage} / ${_page!.lastPage} • ${strings.cashReviewTotal} ${_page!.total}',
                          ),
                          TextButton(
                            onPressed: !_loading && _page!.currentPage > 1
                                ? () => _load(page: _page!.currentPage - 1)
                                : null,
                            child: Text(strings.cashReviewPrevious),
                          ),
                          TextButton(
                            onPressed:
                                !_loading &&
                                    _page!.currentPage < _page!.lastPage
                                ? () => _load(page: _page!.currentPage + 1)
                                : null,
                            child: Text(strings.cashReviewNext),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
    );
  }

  Widget _filter<T>(
    String label,
    T? value,
    List<T> values,
    ValueChanged<T?> onChanged, {
    String Function(T)? labelOf,
  }) {
    final options = {if (value != null) value, ...values};
    return DropdownButtonFormField<T>(
      key: ValueKey('$label-$value'),
      initialValue: value,
      isExpanded: true,
      decoration: InputDecoration(labelText: label),
      items: [
        DropdownMenuItem<T>(child: Text(AppStrings.of(context).auditAll)),
        for (final option in options)
          DropdownMenuItem(
            value: option,
            child: Text(labelOf?.call(option) ?? '$option'),
          ),
      ],
      onChanged: onChanged,
    );
  }

  String _date(DateTime? date) =>
      date?.toIso8601String().substring(0, 10) ?? '—';
  String _actorName(Map<String, dynamic> event, AppStrings strings) {
    final actor = event['actor'];
    if (actor is Map) return actor['name']?.toString() ?? strings.auditSystem;
    return actor?.toString().trim().isNotEmpty == true
        ? actor.toString()
        : strings.auditSystem;
  }

  void _showDetails(Map<String, dynamic> event) {
    final strings = AppStrings.of(context);
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (context) => FractionallySizedBox(
        heightFactor: .85,
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                strings.auditDetails,
                style: Theme.of(context).textTheme.titleLarge,
              ),
              for (final entry in <String, dynamic>{
                strings.auditActor: _actorName(event, strings),
                strings.auditEvent: event['event'],
                strings.auditOccurredAt:
                    event['occurred_at'] ?? event['created_at'],
                strings.auditEntity: event['entity_type'],
                strings.auditEntityId: event['entity_id'],
                strings.auditSource: event['source'],
                strings.auditDevice: event['device_id'],
                strings.auditIp: event['ip_address'],
              }.entries)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: SelectableText('${entry.key}: ${entry.value ?? '—'}'),
                ),
              for (final entry in {
                strings.auditBefore: event['before'],
                strings.auditAfter: event['after'],
              }.entries) ...[
                const Divider(),
                Text(entry.key, style: Theme.of(context).textTheme.titleMedium),
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: SelectableText(
                    entry.value == null
                        ? strings.auditNoValue
                        : const JsonEncoder.withIndent(
                            '  ',
                          ).convert(entry.value),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
