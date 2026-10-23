/// Parsed form of the field-reader's compound `square/path` search.
///
/// Accepted examples include `4/1`, `4-1`, `4_1`, and `4\\1`. Arabic and
/// Persian digits are normalized to ASCII digits before matching.
class CompoundSearchQuery {
  const CompoundSearchQuery({
    required this.rawQuery,
    this.squareId,
    this.pathNumber,
    this.isCompoundPathSearch = false,
  });

  final String rawQuery;
  final int? squareId;
  final int? pathNumber;
  final bool isCompoundPathSearch;

  factory CompoundSearchQuery.parse(String input) {
    final normalized = normalizeDigits(input.trim());
    final match = RegExp(r'^(\d+)[/\\_-](\d+)$').firstMatch(normalized);
    if (match == null) return CompoundSearchQuery(rawQuery: normalized);

    return CompoundSearchQuery(
      rawQuery: normalized,
      squareId: int.tryParse(match.group(1)!),
      pathNumber: int.tryParse(match.group(2)!),
      isCompoundPathSearch: true,
    );
  }

  bool get hasSplitParams =>
      isCompoundPathSearch && squareId != null && pathNumber != null;

  bool get isEmpty => rawQuery.trim().isEmpty;

  Map<String, dynamic> toQueryMap({String key = 'query'}) {
    if (hasSplitParams) {
      return <String, dynamic>{
        key: rawQuery,
        'square_id': squareId,
        'path_number': pathNumber,
      };
    }
    return <String, dynamic>{key: rawQuery};
  }

  static String normalizeDigits(String value) {
    const arabic = '٠١٢٣٤٥٦٧٨٩';
    const persian = '۰۱۲۳۴۵۶۷۸۹';
    final output = StringBuffer();
    for (final rune in value.runes) {
      final character = String.fromCharCode(rune);
      final arabicIndex = arabic.indexOf(character);
      final persianIndex = persian.indexOf(character);
      if (arabicIndex >= 0) {
        output.write(arabicIndex);
      } else if (persianIndex >= 0) {
        output.write(persianIndex);
      } else {
        output.write(character);
      }
    }
    return output.toString();
  }
}

typedef FinanceSearchQuery = CompoundSearchQuery;
