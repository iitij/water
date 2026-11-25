/// Pure bounded DECIMAL(12,2) amount. BigInt protects web intermediates.
final class ExactMoney {
  static final maxMinor = BigInt.parse('999999999999');
  static final _maxRateMinor = BigInt.parse('9999999999');
  static final _hundred = BigInt.from(100);
  static final _pattern = RegExp(r'^(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$');

  final BigInt minor;
  const ExactMoney._(this.minor);

  factory ExactMoney.fromMinor(Object? minor) {
    if (minor is! BigInt) {
      throw const FormatException('BigInt minor units required');
    }
    if (minor.abs() > maxMinor) throw RangeError('Money storage range');
    return ExactMoney._(minor);
  }

  factory ExactMoney.parse(Object? decimal) =>
      ExactMoney.fromMinor(_parseMinor(decimal, maxMinor));

  static BigInt _parseMinor(Object? decimal, BigInt max) {
    if (decimal is! String) {
      throw const FormatException('ASCII decimal string required');
    }
    final match = _pattern.firstMatch(decimal);
    if (match == null || match.end != decimal.length) {
      throw const FormatException('Invalid decimal syntax');
    }
    final digits = match[2]! + (match[3] ?? '').padRight(2, '0');
    if (digits.length > 12) throw RangeError('Decimal storage range');
    final minor = BigInt.parse(digits);
    if (minor > max) throw RangeError('Decimal storage range');
    return match[1] == '-' ? -minor : minor;
  }

  String get decimal {
    final digits = minor.abs().toString().padLeft(3, '0');
    final split = digits.length - 2;
    return (minor.isNegative ? '-' : '') + digits.substring(0, split) + '.' + digits.substring(split);
  }

  ExactMoney plus(ExactMoney other) => ExactMoney.fromMinor(minor + other.minor);
  ExactMoney minus(ExactMoney other) => ExactMoney.fromMinor(minor - other.minor);
  ExactMoney negate() => ExactMoney.fromMinor(-minor);
  int compare(ExactMoney other) => minor.compareTo(other.minor);

  static ExactMoney tariffLine(Object? quantity, Object? rate) {
    final q = _parseMinor(quantity, maxMinor);
    final r = _parseMinor(rate, _maxRateMinor);
    if (q.isNegative || r.isNegative) {
      throw const FormatException('Negative tariff input');
    }
    return ExactMoney.fromMinor((q * r + BigInt.from(50)) ~/ _hundred);
  }

  /// Caller determines the cap order; no IO, allocation policy or side effects.
  ({List<ExactMoney> allocations, ExactMoney surplus}) allocate(
    List<ExactMoney> caps,
  ) {
    if (minor <= BigInt.zero) {
      throw const FormatException('Positive payment required');
    }
    var remaining = minor;
    final allocations = <ExactMoney>[];
    for (final cap in caps) {
      if (cap.minor.isNegative) {
        throw const FormatException('Negative cap');
      }
      final part = remaining < cap.minor ? remaining : cap.minor;
      allocations.add(ExactMoney.fromMinor(part));
      remaining -= part;
    }
    return (
      allocations: List<ExactMoney>.unmodifiable(allocations),
      surplus: ExactMoney.fromMinor(remaining),
    );
  }
}
