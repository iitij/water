import 'package:flutter_test/flutter_test.dart';
import 'package:water_mobile/core/utils/compound_search_parser.dart';

void main() {
  group('CompoundSearchQuery', () {
    test('parses all supported square/path separators', () {
      for (final input in <String>['4/1', '4-1', '4_1', r'4\1']) {
        final query = CompoundSearchQuery.parse(input);
        expect(query.isCompoundPathSearch, isTrue, reason: input);
        expect(query.squareId, 4, reason: input);
        expect(query.pathNumber, 1, reason: input);
      }
    });

    test('normalizes Arabic and Persian numerals before parsing', () {
      final arabic = CompoundSearchQuery.parse('٤/١');
      final persian = CompoundSearchQuery.parse('۴-۱');

      expect(arabic.rawQuery, '4/1');
      expect(arabic.squareId, 4);
      expect(arabic.pathNumber, 1);
      expect(persian.rawQuery, '4-1');
      expect(persian.isCompoundPathSearch, isTrue);
    });

    test('keeps ordinary text and partial paths as normal search terms', () {
      for (final input in <String>['4', 'meter-12', '4/', 'name']) {
        final query = CompoundSearchQuery.parse(input);
        expect(query.isCompoundPathSearch, isFalse, reason: input);
        expect(query.rawQuery, input, reason: input);
      }
    });
  });
}
