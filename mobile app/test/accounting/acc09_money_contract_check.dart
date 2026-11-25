// Standalone pure Dart oracle, not product code. Run from any directory.
import 'dart:convert';
import 'dart:io';

final maxMoney = BigInt.parse('999999999999');
final maxRate = BigInt.parse('9999999999');
final hundred = BigInt.from(100);
int checks = 0;

BigInt parse(Object? value, [BigInt? max]) {
  if (value is! String) throw const FormatException('Decimal string required');
  final match = RegExp(r'^(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$').firstMatch(value);
  if (match == null || match.end != value.length) throw const FormatException('Decimal syntax');
  final minor = BigInt.parse(match[2]! + (match[3] ?? '').padRight(2, '0'));
  if (minor > (max ?? maxMoney)) throw RangeError('Storage range');
  return match[1] == '-' ? -minor : minor;
}

String format(BigInt minor) {
  final digits = minor.abs().toString().padLeft(3, '0');
  return (minor.isNegative ? '-' : '') +
      digits.substring(0, digits.length - 2) + '.' + digits.substring(digits.length - 2);
}

BigInt checked(BigInt value) {
  if (value.abs() > maxMoney) throw RangeError('Storage range');
  return value;
}

String line(Object? q, Object? r) {
  final quantity = parse(q);
  final rate = parse(r, maxRate);
  if (quantity.isNegative || rate.isNegative) throw const FormatException('Negative line');
  return format(checked((quantity * rate + BigInt.from(50)) ~/ hundred));
}

void equal(Object? actual, Object? expected) {
  if (actual != expected) throw StateError('Expected $expected, got $actual');
  checks++;
}

void rejects(void Function() call) {
  try {
    call();
  } on FormatException {
    checks++;
    return;
  } on RangeError {
    checks++;
    return;
  }
  throw StateError('Expected rejected financial value');
}

(List<String>, String) allocate(Map<String, dynamic> v) {
  var remaining = parse(v['amount']);
  if (remaining <= BigInt.zero) throw const FormatException('Payment must be positive');
  final allocated = <String>[];
  for (final cap in v['caps'] as List) {
    final limit = parse(cap);
    if (limit.isNegative) throw const FormatException('Negative cap');
    final part = remaining < limit ? remaining : limit;
    allocated.add(format(part));
    remaining -= part;
  }
  return (allocated, format(remaining));
}

void main() {
  final file = File.fromUri(Platform.script.resolve('../../../docs/accounting/ACC_09_MONEY_VECTORS.json'));
  final v = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
  for (final row in (v['parse'] as List<Object?>).cast<List<Object?>>()) {
    final minor = BigInt.from(row[1] as int);
    equal(parse(row[0]), minor);
    equal(parse(format(minor)), minor);
  }
  for (final input in v['invalidParse'] as List<Object?>) { rejects(() => parse(input)); }
  for (final row in (v['add'] as List<Object?>).cast<List<Object?>>()) { equal(format(checked(parse(row[0]) + parse(row[1]))), row[2]); }
  for (final row in (v['invalidAdd'] as List<Object?>).cast<List<Object?>>()) { rejects(() => checked(parse(row[0]) + parse(row[1]))); }
  for (final row in (v['line'] as List<Object?>).cast<List<Object?>>()) { equal(line(row[0], row[1]), row[2]); }
  for (final row in (v['invalidLine'] as List<Object?>).cast<List<Object?>>()) { rejects(() => line(row[0], row[1])); }
  for (final item in v['allocate'] as List<Object?>) {
    final row = item as Map<String, dynamic>;
    final (allocated, surplus) = allocate(row);
    equal(jsonEncode(allocated), jsonEncode(row['allocations']));
    equal(surplus, row['surplus']);
    var total = parse(surplus);
    for (var i = 0; i < allocated.length; i++) {
      final part = parse(allocated[i]);
      equal(part >= BigInt.zero, true);
      equal(part <= parse((row['caps'] as List<Object?>)[i]), true);
      total += part;
    }
    equal(total, parse(row['amount']));
  }
  for (final item in v['invalidAllocate'] as List<Object?>) { rejects(() => allocate(item as Map<String, dynamic>)); }
  stdout.writeln('ACC09_DART_CONTRACT_PASS $checks checks');
}
