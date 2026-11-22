import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/database/app_database.dart';

void main() {
  test('offline cache rejects overlapping periods but allows shared observation boundaries', () async {
    final db = AppDatabase(NativeDatabase.memory());
    addTearDown(db.close);
    await db.cacheReadings([
      {
        'id': 101,
        'meter_id': 7,
        'reading_date': '2026-02-05',
        'actual_reading_date': '2026-02-05',
        'period_start': '2026-01-01',
        'period_end': '2026-02-05',
        'previous_reading': 0,
        'current_reading': 120,
        'consumption_units': 120,
      },
    ]);

    Future<bool> overlap(DateTime start, DateTime end) =>
        db.hasCachedReadingPeriod(
          meterId: 7,
          periodStart: start,
          periodEnd: end,
        );

    expect(await overlap(DateTime(2026, 1, 8), DateTime(2026, 2, 6)), isTrue);
    expect(await overlap(DateTime(2026, 1, 1), DateTime(2026, 2, 5)), isTrue);
    expect(await overlap(DateTime(2026, 2, 5), DateTime(2026, 3, 8)), isFalse);
    expect(await overlap(DateTime(2025, 12, 1), DateTime(2026, 1, 1)), isFalse);
    expect(await overlap(DateTime(2026, 1, 8), DateTime(2026, 2, 6).add(const Duration(days: 30))), isTrue);
    expect(await db.hasCachedReadingPeriod(
      meterId: 8,
      periodStart: DateTime(2026, 1, 8),
      periodEnd: DateTime(2026, 2, 6),
    ), isFalse);
  });
}
