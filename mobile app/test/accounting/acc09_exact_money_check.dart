// Dedicated CLI test of actual product primitives; no shared call sites.
import 'dart:convert';
import 'dart:io';
import '../../lib/core/accounting/exact_money.dart';

int checks = 0;
BigInt parse(Object? value) => ExactMoney.parse(value).minor;
String format(BigInt minor) => ExactMoney.fromMinor(minor).decimal;
String line(Object? q, Object? r) => ExactMoney.tariffLine(q,r).decimal;

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
  final result = ExactMoney.parse(v['amount']).allocate((v['caps'] as List).map(ExactMoney.parse).toList());
  return (result.allocations.map((m) => m.decimal).toList(), result.surplus.decimal);
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
  for (final row in (v['add'] as List<Object?>).cast<List<Object?>>()) { equal(ExactMoney.parse(row[0]).plus(ExactMoney.parse(row[1])).decimal, row[2]); }
  for (final row in (v['invalidAdd'] as List<Object?>).cast<List<Object?>>()) { rejects(() => ExactMoney.parse(row[0]).plus(ExactMoney.parse(row[1]))); }
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
  final max = ExactMoney.fromMinor(ExactMoney.maxMinor);
  final min = max.negate();
  equal(max.plus(min).decimal, '0.00');
  equal(max.minus(max).decimal, '0.00');
  equal(min.compare(max), -1);
  equal(max.compare(max), 0);
  equal(max.compare(min), 1);
  equal(ExactMoney.parse('-0.00').decimal, '0.00');
  rejects(() => max.minus(min));
  rejects(() => min.minus(max));
  for (final bad in [1.0, 1, '1', null, true, ExactMoney.maxMinor + BigInt.one, -ExactMoney.maxMinor - BigInt.one]) {
    rejects(() => ExactMoney.fromMinor(bad));
  }
  for (final bad in ['1\n', '1\r', '١.٢', List.filled(1000, '9').join()]) {
    rejects(() => ExactMoney.parse(bad));
  }
  rejects(() => ExactMoney.parse('1').allocate([ExactMoney.parse('1'), ExactMoney.parse('-1')]));
  equal(ExactMoney.tariffLine('9999999999.99', '1.00').decimal, '9999999999.99');
  equal(ExactMoney.tariffLine('0.01', '0.50').plus(ExactMoney.tariffLine('0.01', '0.50')).decimal, '0.02');
  for (var q=0; q<=99; q++) {
    for (var r=0; r<=99; r++) {
      equal(ExactMoney.tariffLine(format(BigInt.from(q)),format(BigInt.from(r))).minor, BigInt.from((q*r+50) ~/ 100));
    }
  }
  stdout.writeln('ACC09_EXACT_MONEY_PASS $checks checks');
}
