import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/error/failures.dart';
import 'package:water_project/core/finance/finance_api_client.dart';
import 'package:water_project/core/network/network_info.dart';
import 'package:water_project/core/network/finance_endpoints.dart';
import 'package:water_project/features/invoices/data/datasources/invoices_remote_datasource.dart';
import 'package:water_project/features/payments/data/datasources/payments_remote_datasource.dart';
import 'package:water_project/features/payments/data/models/payment_model.dart';
import 'package:water_project/features/payments/data/repositories/payments_repository_impl.dart';

class _Client extends Mock implements FinanceApiClient {}
class _Invoices extends Mock implements InvoicesRemoteDataSource {}
class _Network extends Mock implements NetworkInfo {}

PaymentVoidRequest request(int? requestId) => PaymentVoidRequest.fromJson({
  'id': 7,
  'request_id': requestId,
  'receipt_number': 'QA-7',
  'amount': '1.00',
});

void main() {
  late _Client client;
  late PaymentsRepositoryImpl repository;
  late List<Map<String, dynamic>> sent;
  var wrongReply = false;
  var lostReply = false;

  setUp(() {
    client = _Client();
    repository = PaymentsRepositoryImpl(
      remote: PaymentsRemoteDataSource(client),
      invoices: _Invoices(),
      networkInfo: _Network(),
    );
    sent = [];
    wrongReply = false;
    lostReply = false;
    when(() => client.post(FinanceEndpoints.paymentVoidDecision(7),
      body: any(named: 'body'), sessionExtra: any(named: 'sessionExtra')))
        .thenAnswer((invocation) async {
      final body = Map<String, dynamic>.from(invocation.namedArguments[#body] as Map);
      sent.add(body);
      if (lostReply) {
        lostReply = false;
        throw const NetworkFailure();
      }
      return <String, dynamic>{
        'request_id': wrongReply ? 999 : body['request_id'],
        'operation_uuid': body['operation_uuid'],
        'payment': {'id': 7, 'voided_at': body['approve'] == true ? '2026-11-25' : null},
      };
    });
  });

  test('captured approval identity and UUID survive lost response and differ for a new request', () async {
    final first = request(101);
    lostReply = true;
    await expectLater(repository.decideBoundVoidRequest(first, approve: true, notes: 'Review'),
        throwsA(isA<NetworkFailure>()));
    expect(await repository.decideBoundVoidRequest(first, approve: true, notes: 'Review'), isTrue);
    expect(sent[0], sent[1]);
    expect(sent[0]['request_id'], 101);
    expect(sent[0]['operation_uuid'], matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
    expect(await repository.decideBoundVoidRequest(request(102), approve: true), isTrue);
    expect(sent.last['operation_uuid'], isNot(sent.first['operation_uuid']));
  });

  test('explicit null bound request is refused without sending a legacy decision', () async {
    final unresolved = request(null);
    expect(unresolved.requiresBoundDecision, isTrue);
    expect(unresolved.requestId, isNull);
    await expectLater(repository.decideBoundVoidRequest(unresolved, approve: false),
        throwsA(isA<ValidationFailure>()));
    expect(sent, isEmpty);
    expect(PaymentVoidRequest.fromJson({'id': 7}).requiresBoundDecision, isFalse);
  });

  test('reject has no posting UUID and mismatched acknowledgement is not success', () async {
    expect(await repository.decideBoundVoidRequest(request(101), approve: false), isTrue);
    expect(sent.single.containsKey('operation_uuid'), isFalse);
    wrongReply = true;
    await expectLater(repository.decideBoundVoidRequest(request(102), approve: true),
        throwsA(isA<UnexpectedFailure>()));
    expect(sent.last['request_id'], 102);
  });
}
