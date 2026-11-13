import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/database/app_database.dart';
import 'package:water_project/features/meters/data/repositories/meters_repository_impl.dart';
import 'package:water_project/features/meters/data/models/meter.dart';
import 'package:water_project/features/readings/data/repositories/readings_repository_impl.dart';
import 'package:water_project/features/readings/data/models/reading.dart';

void main() {
  test('cached subscriber count ignores historical and stale multi-meter data', () async {
    final db = AppDatabase.inMemory();
    addTearDown(db.close);
    await db.cacheSubscribers([
      {
        'id': 101,
        'name': 'مشترك ذو عداد مستبدل',
        'meters': [
          {'id': 4, 'removed_at': '2026-09-01'},
          {'id': 5, 'removed_at': null},
        ],
      },
      {
        'id': 102,
        'name': 'مشترك ينتظر عداداً جديداً',
        'meters': [
          {'id': 6, 'removed_at': '2026-08-01'},
        ],
      },
      {
        'id': 103,
        'name': 'عدّاد واحد مع عداد تاريخي',
        'meters_count': 3,
      },
    ]);
    final cached = await db.getCachedSubscribers();
    final byId = {for (final row in cached) row['server_id']: row};
    expect(byId[101]?['meters_count'], 1);
    expect(byId[102]?['meters_count'], 0);
    expect(byId[103]?['meters_count'], 1);
  });

  test('offline meter list excludes retired assignment and keeps new current meter', () async {
    final db = AppDatabase.inMemory();
    addTearDown(db.close);
    await db.cacheSubscribers([
      {'id': 3, 'name': 'مشترك اختبار', 'square_id': 7, 'meters_count': 1},
    ]);
    await db.cacheMeters([
      {
        'id': 10, 'unique_code': 'OLD', 'subscriber_id': 3, 'square_id': 7,
        'status': 'inactive', 'removed_at': '2026-10-01', 'path_number': 2,
      },
      {
        'id': 11, 'unique_code': 'NEW', 'subscriber_id': 3, 'square_id': 7,
        'status': 'active', 'removed_at': null, 'path_number': 2,
      },
    ]);

    final dio = Dio(BaseOptions(baseUrl: 'https://example.invalid'));
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) => handler.reject(DioException(
        requestOptions: options, type: DioExceptionType.connectionError,
      )),
    ));

    final repo = MetersRepositoryImpl(dio: dio, database: db);
    final meters = await repo.getMeters();
    expect(meters.map((m) => m.id), [11]);
    expect(await repo.isPathNumberAvailable(pathNumber: 2, squareId: 7), isFalse);
    expect(await repo.meterCountForSubscriber(3), 1);
    final subscriber = (await repo.getSubscribers()).single;
    expect(subscriber.currentMeterId, 11);
    expect(subscriber.hasCurrentMeter, isTrue);
    expect(subscriber.meterCount, 1);

    final readings = ReadingsRepositoryImpl(dio: dio, database: db);
    final old = Meter.fromJson({
      'id': 10, 'unique_code': 'OLD', 'subscriber_id': 3,
      'removed_at': '2026-10-01',
    });
    await expectLater(readings.getMeterPeriod(old), throwsStateError);
    await expectLater(
      readings.submitReading(ReadingSubmission(
        meterId: old.id,
        readingDate: DateTime(2026, 11, 13),
        periodStart: DateTime(2026, 11, 1),
        periodEnd: DateTime(2026, 11, 30),
        previousReading: 10,
        currentReading: 20,
      )),
      throwsStateError,
    );
    expect(await db.getLatestCachedReading(old.id), isNull);
  });
}
