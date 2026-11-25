import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/field_operations/field_session_scope.dart';
import 'package:water_project/core/finance/finance_api_client.dart';
import 'package:water_project/core/finance/finance_sync_queue.dart';
import 'package:water_project/core/network/interceptors/auth_interceptor.dart';
import 'package:water_project/core/network/network_info.dart';
import 'package:water_project/features/invoices/data/datasources/invoices_remote_datasource.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/payments/data/datasources/payments_remote_datasource.dart';
import 'package:water_project/features/payments/data/models/collect_payment_models.dart';
import 'package:water_project/features/payments/data/repositories/payments_repository_impl.dart';

class _Network extends Mock implements NetworkInfo {}

class _Queue implements FinanceSyncQueueRepository {
  final actions = <SyncAction>[];
  @override
  Future<void> enqueue(SyncAction action) async => actions.add(action);
  @override
  Future<List<SyncAction>> pending({String? module}) async => actions;
  @override
  Future<void> markSynced(SyncAction action) async {}
}

void main() {
  late Dio dio;
  late _Network network;
  late _Queue queue;
  late int generation;
  late PaymentsRepositoryImpl repo;
  const request = CollectPaymentRequest(subscriberId: 10, amount: 10.25,
      method: PaymentMethod.cash);

  setUp(() {
    dio = Dio(BaseOptions(baseUrl: 'https://water.test/api/'));
    network = _Network();
    queue = _Queue();
    generation = 1;
    final client = FinanceApiClient(dio);
    repo = PaymentsRepositoryImpl(
      remote: PaymentsRemoteDataSource(client),
      invoices: InvoicesRemoteDataSource(client),
      networkInfo: network, syncQueue: queue,
      captureScope: () {
        final g = generation;
        final server = dio.options.baseUrl;
        return FieldSessionScope(server: server, userId: '10', generation: g,
            isCurrent: () => generation == g && dio.options.baseUrl == server);
      },
    );
  });

  test('old account cannot enqueue after connectivity await changes generation', () async {
    final check = Completer<bool>();
    when(() => network.isConnected).thenAnswer((_) => check.future);
    final pending = repo.collect(request);
    final rejected = expectLater(pending, throwsStateError);
    generation++;
    check.complete(false);
    await rejected;
    expect(queue.actions, isEmpty);
  });

  test('lost response after session change cannot become a new-account offline receipt', () async {
    when(() => network.isConnected).thenAnswer((_) async => true);
    final started = Completer<void>();
    final release = Completer<void>();
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) async {
      expect(options.extra[AuthInterceptor.sessionGenerationKey], 1);
      expect(options.extra[AuthInterceptor.sessionServerKey], 'https://water.test/api/');
      started.complete();
      await release.future;
      handler.reject(DioException(requestOptions: options,
          type: DioExceptionType.connectionError));
    }));
    final pending = repo.collect(request);
    final rejected = expectLater(pending, throwsStateError);
    await started.future;
    generation++;
    release.complete();
    await rejected;
    expect(queue.actions, isEmpty);
  });

  test('same session retry preserves exact UUID and returns a pending receipt', () async {
    when(() => network.isConnected).thenAnswer((_) async => true);
    String? sent;
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      sent = (options.data as Map)['sync_uuid'] as String;
      handler.reject(DioException(requestOptions: options,
          type: DioExceptionType.connectionError));
    }));
    final result = await repo.collect(request);
    expect(result.queuedOffline, isTrue);
    expect(queue.actions.single.payload['sync_uuid'], sent);
    expect(queue.actions.single.idempotencyKey, sent);
    expect(result.payment?.receiptNumber, 'REC-LOCAL-$sent');
  });

  test('late successful response after server switch cannot be shown as current-account payment', () async {
    when(() => network.isConnected).thenAnswer((_) async => true);
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      dio.options.baseUrl = 'https://other.test/api/';
      handler.resolve(Response(requestOptions: options, statusCode: 201,
          data: {'payment': {'id': 123, 'subscriber_id': 10,
            'receipt_number': 'REC-202611-1', 'amount': '10.25',
            'payment_method': 'cash'}}));
    }));
    await expectLater(repo.collect(request), throwsStateError);
    expect(queue.actions, isEmpty);
  });

  test('late void failure cannot queue an old account cancellation', () async {
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      generation++;
      handler.reject(DioException(requestOptions: options,
          type: DioExceptionType.connectionError));
    }));
    await expectLater(repo.voidPayment(123, reason: 'Synthetic void reason'),
        throwsStateError);
    expect(queue.actions, isEmpty);
  });
}
