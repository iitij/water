import 'cash_session.dart';

/// A page of server summaries. Totals remain the values calculated by Laravel.
class CashSessionPage {
  const CashSessionPage({
    required this.sessions,
    required this.currentPage,
    required this.lastPage,
    required this.total,
    this.collectors = const [],
  });

  final List<CashSession> sessions;
  final int currentPage;
  final int lastPage;
  final int total;
  final List<CashSessionCollectorOption> collectors;

  bool get hasPrevious => currentPage > 1;
  bool get hasNext => currentPage < lastPage;

  static CashSessionPage? tryParse(Object? raw) {
    if (raw is! Map || raw['data'] is! List) return null;
    final rows = raw['data'] as List;
    final sessions = <CashSession>[];
    for (final row in rows) {
      if (row is! Map ||
          !CashSessionState.values.any((state) => state.name == row['state'])) {
        return null;
      }
      final session = CashSession.tryParse(row);
      if (session == null) return null;
      sessions.add(session);
    }
    int? number(String key) => int.tryParse(raw[key]?.toString() ?? '');
    final currentPage = number('current_page');
    final lastPage = number('last_page');
    final total = number('total');
    if (currentPage == null ||
        currentPage < 1 ||
        lastPage == null ||
        lastPage < 1 ||
        total == null ||
        total < 0) {
      return null;
    }
    return CashSessionPage(
      sessions: List.unmodifiable(sessions),
      currentPage: currentPage,
      lastPage: lastPage,
      total: total,
      collectors: List.unmodifiable(_collectors(raw['filter_options'])),
    );
  }

  static List<CashSessionCollectorOption> _collectors(Object? raw) {
    if (raw is! Map || raw['collectors'] is! List) return const [];
    return (raw['collectors'] as List)
        .whereType<Map>()
        .map((row) => CashSessionCollectorOption.tryParse(row))
        .whereType<CashSessionCollectorOption>()
        .toList();
  }
}

class CashSessionCollectorOption {
  const CashSessionCollectorOption({required this.id, required this.name});

  final int id;
  final String name;

  static CashSessionCollectorOption? tryParse(Map row) {
    final id = int.tryParse(row['id']?.toString() ?? '');
    final name = row['name']?.toString().trim() ?? '';
    if (id == null || id <= 0 || name.isEmpty) return null;
    return CashSessionCollectorOption(id: id, name: name);
  }
}

class CashSessionFilters {
  const CashSessionFilters({
    this.state,
    this.addedFrom,
    this.addedTo,
    this.collectorId,
    this.fromDate,
    this.toDate,
    this.pageSize = 25,
    this.sortBy = 'id',
    this.sortDirection = 'desc',
  });

  final CashSessionState? state;
  final DateTime? addedFrom, addedTo;
  final int? collectorId;
  final DateTime? fromDate;
  final DateTime? toDate;
  final int pageSize;
  final String sortBy;
  final String sortDirection;

  Map<String, dynamic> toQuery({int page = 1}) => <String, dynamic>{
    'page': page,
    if (addedFrom != null) 'added_from': dateValue(addedFrom!),
    if (addedTo != null) 'added_to': dateValue(addedTo!),
    'per_page': pageSize,
    'sort_by': sortBy,
    'sort_direction': sortDirection,
    if (state != null) 'state': state!.name,
    if (collectorId != null) 'user_id': collectorId,
    if (fromDate != null) 'from_date': dateValue(fromDate!),
    if (toDate != null) 'to_date': dateValue(toDate!),
  };

  CashSessionFilters withPresentation({
    required int pageSize,
    DateTime? addedFrom,
    DateTime? addedTo,
    required String sortBy,
    required String sortDirection,
  }) => CashSessionFilters(
    state: state,
    addedFrom: addedFrom,
    addedTo: addedTo,
    collectorId: collectorId,
    fromDate: fromDate,
    toDate: toDate,
    pageSize: pageSize,
    sortBy: sortBy,
    sortDirection: sortDirection,
  );

  static String dateValue(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';
}
