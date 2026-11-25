import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/core/finance/finance_api_client.dart';
import 'package:water_project/core/finance/finance_sync_queue.dart';
import 'package:water_project/core/network/finance_endpoints.dart';
import 'package:water_project/core/network/network_info.dart';
import 'package:water_project/features/invoices/data/datasources/invoices_remote_datasource.dart';
import 'package:water_project/features/payments/data/datasources/payments_remote_datasource.dart';
import 'package:water_project/features/payments/data/repositories/payments_repository_impl.dart';

class _Client extends Mock implements FinanceApiClient {}
class _Invoices extends Mock implements InvoicesRemoteDataSource {}
class _Network extends Mock implements NetworkInfo {}
class _Queue extends Mock implements FinanceSyncQueueRepository {}

void main() {
  const reason = 'Independent documented void intent';
  late _Client client;
  late _Network network;
  late _Queue queue;
  late PaymentsRepositoryImpl repository;
  late List<Map<String, dynamic>> sent;
  var lost = false;
  var pending = true;
  var wrong = false;
  setUp(() {
    client = _Client();
    network = _Network();
    queue = _Queue();
    repository = PaymentsRepositoryImpl(
      remote: PaymentsRemoteDataSource(client),
      invoices: _Invoices(),
      networkInfo: network,
      syncQueue: queue,
      prospectiveVoidRequests: true,
    );
    sent = [];
    lost = false;
    pending = true;
    wrong = false;
    when(() => network.isConnected).thenAnswer((_) async => true);
    when(() => client.post(FinanceEndpoints.paymentVoid(7),
      body: any(named: 'body'), sessionExtra: any(named: 'sessionExtra')))
        .thenAnswer((invocation) async {
      final body = Map<String, dynamic>.from(invocation.namedArguments[#body] as Map);
      sent.add(body);
      if (lost) throw const NetworkFailure();
      return <String, dynamic>{
        'intent_uuid': wrong ? 'wrong' : body['intent_uuid'],
        'pending_approval': pending,
        if (pending) 'request_id': 101,
        'payment': {'id': 7, 'voided_at': pending ? null : '2026-11-25'},
      };
    });
  });

  test('explicit prospective intent confirms pending and immediate outcomes', () async {
    expect((await repository.voidPayment(7, reason: reason)).pendingApproval, isTrue);
    expect(sent.single['intent_uuid'], matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
    pending = false;
    expect((await repository.voidPayment(7, reason: reason)).pendingApproval, isFalse);
    expect(sent.last['intent_uuid'], isNot(sent.first['intent_uuid']));
    verifyZeroInteractions(queue);
  });

  test('offline and ambiguous transport failures never enqueue a correction', () async {
    when(() => network.isConnected).thenAnswer((_) async => false);
    await expectLater(repository.voidPayment(7, reason: reason), throwsA(isA<NetworkFailure>()));
    expect(sent, isEmpty);
    when(() => network.isConnected).thenAnswer((_) async => true);
    lost = true;
    await expectLater(repository.voidPayment(7, reason: reason), throwsA(isA<NetworkFailure>()));
    expect(sent, hasLength(1));
    verifyZeroInteractions(queue);
  });

  test('wrong intent acknowledgement cannot report success', () async {
    wrong = true;
    await expectLater(repository.voidPayment(7, reason: reason), throwsA(isA<UnexpectedFailure>()));
    verifyZeroInteractions(queue);
  });
}
