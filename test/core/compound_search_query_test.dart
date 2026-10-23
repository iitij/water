import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/utils/compound_search_query.dart';

void main() {
  group('CompoundSearchQuery', () {
    test('parses square/path written with Arabic digits', () {
      final query = CompoundSearchQuery.parse('٤/١');

      expect(query.isCompoundPathSearch, isTrue);
      expect(query.rawQuery, '4/1');
      expect(query.squareId, 4);
      expect(query.pathNumber, 1);
    });

    test('accepts hyphen, underscore, and backslash separators', () {
      for (final input in <String>['4-1', '4_1', '4${String.fromCharCode(92)}1']) {
        final query = CompoundSearchQuery.parse(input);
        expect(query.isCompoundPathSearch, isTrue, reason: input);
        expect(query.squareId, 4, reason: input);
        expect(query.pathNumber, 1, reason: input);
      }
    });

    test('leaves ordinary searches normalized but unparsed', () {
      final query = CompoundSearchQuery.parse('شارع ۱۲');
      expect(query.rawQuery, 'شارع 12');
      expect(query.isCompoundPathSearch, isFalse);
      expect(query.squareId, isNull);
    });
  });
}
