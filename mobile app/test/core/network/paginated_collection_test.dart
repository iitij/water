import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/network/paginated_collection.dart';

void main() {
  test('collects numbered pages, deduplicates identities and preserves metadata', () async {
    final requested = <int>[];
    final result = await collectPaginatedCollection((page) async {
      requested.add(page);
      return {
        'data': page == 1 ? [{'id': 1}, {'id': 2}] : [{'id': 2}, {'id': 3}],
        'current_page': page, 'last_page': 2, 'total': 3,
        'next_page_url': 'https://untrusted.invalid/leak',
        'filter_options': {'states': ['closed']},
      };
    });
    expect(requested, [1, 2]);
    expect((result['data'] as List<dynamic>).map((row) => (row as Map)['id']), [1, 2, 3]);
    expect(result['filter_options'], {'states': ['closed']});
    expect(result['total'], 3);
  });

  test('accepts plain legacy lists and named resource collections', () async {
    final plain = await collectPaginatedCollection((_) async => [{'id': 4}]);
    expect(plain['data'], [{'id': 4}]);
    final named = await collectPaginatedCollection((page) async => {
      'users': [{'id': page}], 'meta': {'current_page': page, 'last_page': 2},
    }, collectionKeys: ['users']);
    expect((named['data'] as List).length, 2);
    expect((named['users'] as List).length, 2);
  });

  test('a failed later page fails the collection instead of reporting partial success', () async {
    await expectLater(collectPaginatedCollection((page) async {
      if (page == 2) throw StateError('offline');
      return {'data': [{'id': 1}], 'current_page': 1, 'last_page': 2};
    }), throwsStateError);
  });

  for (final mode in ['repeat', 'empty', 'wrong-page', 'missing-boundary', 'malformed']) {
    test('rejects unreliable collection: $mode', () async {
      await expectLater(collectPaginatedCollection((page) async {
        if (mode == 'missing-boundary') return {'data': [{'id': 1}], 'next_page_url': '/page/2'};
        if (mode == 'malformed') return {'message': 'success'};
        return {
          'data': mode == 'empty' && page == 2 ? <Map<String, dynamic>>[] : [{'id': 1}],
          'current_page': mode == 'wrong-page' ? 9 : page,
          'last_page': mode == 'empty' ? 3 : 2,
        };
      }), throwsFormatException);
    });
  }

  test('cancellation prevents publishing or requesting subsequent pages', () async {
    var cancelled = false;
    final requested = <int>[];
    await expectLater(collectPaginatedCollection((page) async {
      requested.add(page);
      cancelled = true;
      return {'data': [{'id': 1}], 'current_page': 1, 'last_page': 2};
    }, isCancelled: () => cancelled), throwsStateError);
    expect(requested, [1]);
  });
}
