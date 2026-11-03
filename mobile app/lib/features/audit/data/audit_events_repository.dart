import '../../../core/error/failures.dart';
import '../../../core/l10n/app_strings.dart';
import '../../admin_shared/data/admin_api_client.dart';

class AuditEventFilters {
  const AuditEventFilters({
    this.query = '',
    this.addedFrom,
    this.addedTo,
    this.event,
    this.source,
    this.actorId,
    this.entityType,
    this.entityId,
    this.fromDate,
    this.toDate,
    this.pageSize = 25,
    this.sortBy,
    this.descending = true,
  });
  final String query;
  final DateTime? addedFrom, addedTo;
  final String? event, source, entityType, entityId;
  final int? actorId;
  final DateTime? fromDate, toDate;
  final int pageSize;
  final String? sortBy;
  final bool descending;

  bool get validDates =>
      fromDate == null || toDate == null || !fromDate!.isAfter(toDate!);
  Map<String, dynamic> toQuery({int page = 1}) => {
    'page': page,
    if (addedFrom != null) 'added_from': _date(addedFrom!),
    if (addedTo != null) 'added_to': _date(addedTo!),
    'per_page': pageSize,
    if (sortBy != null) 'sort_by': sortBy,
    if (sortBy != null) 'sort_direction': descending ? 'desc' : 'asc',
    if (query.trim().isNotEmpty) 'q': query.trim(),
    if (event != null) 'event': event,
    if (source != null) 'source': source,
    if (actorId != null) 'actor_user_id': actorId,
    if (entityType != null) 'entity_type': entityType,
    if (entityId?.trim().isNotEmpty == true) 'entity_id': entityId!.trim(),
    if (fromDate != null) 'from_date': _date(fromDate!),
    if (toDate != null) 'to_date': _date(toDate!),
  };
  static String _date(DateTime date) => date.toIso8601String().substring(0, 10);
}

class AuditEventsPage {
  const AuditEventsPage({
    this.events = const [],
    this.currentPage = 1,
    this.lastPage = 1,
    this.total = 0,
    this.actors = const [],
    this.eventTypes = const [],
    this.entityTypes = const [],
  });
  final List<Map<String, dynamic>> events, actors;
  final List<String> eventTypes, entityTypes;
  final int currentPage, lastPage, total;

  factory AuditEventsPage.fromJson(dynamic raw) {
    if (raw is! Map ||
        raw['data'] is! List ||
        (raw['data'] as List).any((dynamic row) => row is! Map)) {
      throw const ServerFailure(AppStringsDefaults.invalidResponse);
    }
    final json = _map(raw);
    final current = int.tryParse('${json['current_page']}');
    final last = int.tryParse('${json['last_page']}');
    final total = int.tryParse('${json['total']}');
    if (current == null ||
        current < 1 ||
        last == null ||
        last < 1 ||
        total == null ||
        total < 0) {
      throw const ServerFailure(AppStringsDefaults.invalidResponse);
    }
    final options = _map(json['filter_options']);
    return AuditEventsPage(
      events: _rows(json['data']),
      actors: _rows(options['actors']),
      eventTypes: _strings(options['events']),
      entityTypes: _strings(options['entity_types']),
      currentPage: current,
      lastPage: last,
      total: total,
    );
  }
  static Map<String, dynamic> _map(dynamic value) => value is Map
      ? value.map((key, dynamic value) => MapEntry(key.toString(), value))
      : {};
  static List<Map<String, dynamic>> _rows(dynamic value) =>
      value is List ? value.whereType<Map>().map(_map).toList() : [];
  static List<String> _strings(dynamic value) => value is List
      ? value.map((dynamic item) => item.toString()).toList()
      : [];
}

class AuditEventsRepository {
  const AuditEventsRepository(this.client);
  final AdminApiClient client;
  Future<AuditEventsPage> fetch(
    AuditEventFilters filters, {
    int page = 1,
  }) async => AuditEventsPage.fromJson(
    await client.get('audit-events', query: filters.toQuery(page: page)),
  );
}
