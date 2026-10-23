class CompoundSearchQuery {
  const CompoundSearchQuery({
    required this.rawQuery,
    this.squareId,
    this.pathNumber,
    this.isCompoundPathSearch = false,
  });

  /// Normalized query text, safe to send to Laravel's search filters.
  final String rawQuery;
  final int? squareId;
  final int? pathNumber;
  final bool isCompoundPathSearch;

  factory CompoundSearchQuery.parse(String input) {
    final normalized = normalizeDigits(input.trim());
    final match = RegExp(r'^(\d+)[/\-\\_](\d+)$').firstMatch(normalized);
    if (match == null) return CompoundSearchQuery(rawQuery: normalized);

    return CompoundSearchQuery(
      rawQuery: normalized,
      squareId: int.tryParse(match.group(1)!),
      pathNumber: int.tryParse(match.group(2)!),
      isCompoundPathSearch: true,
    );
  }

  static String normalizeDigits(String input) =>
      input.replaceAllMapped(RegExp('[٠-٩۰-۹]'), (match) {
        const digits = <String, String>{
          '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
          '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
          '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
          '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        };
        return digits[match.group(0)] ?? match.group(0)!;
      });
}
