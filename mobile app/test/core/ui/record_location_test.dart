import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/models/record_location.dart';
import 'package:water_project/features/invoices/data/models/invoice_query.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';

void main() {
  test(
    'linked meter location remains isolated from other subscriber routes',
    () {
      final json = <String, dynamic>{
        'subscriber': {
          'square_id': 4,
          'square': {'name': 'مربع النور'},
          'meters': [
            {'path_number': 1},
            {'path_number': 10},
          ],
        },
        'meter': {'path_number': 10},
      };
      final location = RecordLocation.fromJson(json);
      expect(location.squareId, 4);
      expect(location.squareName, 'مربع النور');
      expect(location.paths, [10]);
    json['meter'] = {'path_number': null};
    expect(RecordLocation.fromJson(json).paths, isEmpty);
      json.remove('meter');
      expect(RecordLocation.fromJson(json).paths, [1, 10]);
      expect(RecordLocation.fromJson({}).firstPath, isNull);
    },
  );

  test(
    'invoice facets survive pagination and clear independently of status',
    () {
      final query = InvoiceQuery(
        pathNumber: 10,
        addedFrom: DateTime(2026, 11, 1),
        addedTo: DateTime(2026, 11, 3),
        status: 'issued',
      );
      final page = query.copyWith(page: 2);
      expect(page.toQueryMap(), containsPair('path_number', 10));
      expect(page.toQueryMap(), containsPair('added_to', '2026-11-03'));
      expect(page.toQueryMap(), containsPair('page', 2));
      expect(page.signature, isNot(query.signature));
      final clear = page.copyWith(clearListFilters: true);
      expect(clear.toQueryMap().containsKey('added_from'), isFalse);
      expect(clear.pathNumber, isNull);
      expect(clear.status, 'issued');
    },
  );

  test('payment facets use addition dates independently of payment dates', () {
    final query = PaymentQuery(
      pathNumber: 2,
      addedFrom: DateTime(2026, 11, 1),
      fromDate: DateTime(2020, 1, 1),
    );
    expect(query.toQueryMap(), containsPair('added_from', '2026-11-01'));
    expect(query.toQueryMap(), containsPair('from_date', '2020-01-01'));
    expect(query.copyWith(page: 2).pathNumber, 2);
    expect(query.copyWith(clearFilters: true).pathNumber, isNull);
    expect(
      query.copyWith(clearListFilters: true).fromDate,
      DateTime(2020, 1, 1),
    );
  });
}
