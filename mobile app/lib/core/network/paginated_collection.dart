/// Collect a Laravel collection before applying native local table controls.
/// Requests are made by page number through the authenticated caller. Pagination
/// URLs from a response are never fetched, including redirects to another host.
Future<Map<String, dynamic>> collectPaginatedCollection(
  Future<dynamic> Function(int page) loadPage, {
  List<String> collectionKeys = const [],
  bool Function()? isCancelled,
}) async {
  Map<String, dynamic>? first;
  final rows = <Map<String, dynamic>>[];
  final seen = <String>{};
  var page = 1;
  while (true) {
    if (isCancelled?.call() == true) {
      throw StateError('Collection request cancelled');
    }
    final raw = await loadPage(page);
    if (isCancelled?.call() == true) {
      throw StateError('Collection request cancelled');
    }
    final json = raw is Map
        ? raw.map((key, dynamic value) => MapEntry(key.toString(), value))
        : <String, dynamic>{'data': raw};
    first ??= json;
    final meta = json['meta'] is Map ? json['meta'] as Map : json;
    final current = int.tryParse('${meta['current_page']}');
    final last = int.tryParse('${meta['last_page']}') ?? 1;
    if (current != null && current != page) {
      throw const FormatException('Server returned a different page');
    }
    if (last < page || last > 10000) {
      throw const FormatException('Invalid pagination boundary');
    }
    dynamic collection = json['data'];
    if (collection is! List) {
      for (final key in collectionKeys) {
        if (json[key] is List) {
          collection = json[key];
          break;
        }
      }
    }
    if (collection is! List) {
      throw const FormatException('Collection data is missing');
    }
    if (collection.isEmpty && page < last) {
      throw const FormatException('Empty page before the collection ended');
    }
    var added = 0;
    for (final rawRow in collection) {
      if (rawRow is! Map) throw const FormatException('Invalid collection row');
      final row = rawRow.map(
        (key, dynamic value) => MapEntry(key.toString(), value),
      );
      final id = row['id'];
      if (id == null || seen.add('$id')) {
        rows.add(row);
        added++;
      }
    }
    if (collection.isNotEmpty && added == 0) {
      throw const FormatException('Server repeated the same collection page');
    }
    if (page >= last) {
      if (meta['last_page'] == null && json['next_page_url'] != null) {
        throw const FormatException(
          'Collection has no reliable pagination boundary',
        );
      }
      return {
        ...first,
        'data': rows,
        for (final key in collectionKeys)
          if (first[key] is List) key: rows,
      };
    }
    page++;
  }
}
