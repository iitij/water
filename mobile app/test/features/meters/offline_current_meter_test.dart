import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/database/app_database.dart';
import 'package:water_project/features/meters/data/repositories/meters_repository_impl.dart';

void main() {
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
  });
}
