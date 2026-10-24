import '../../../../core/fouad/json_read.dart';
import '../../../squares/domain/entities/square.dart';
import '../../domain/entities/subscriber.dart';
import '../../domain/repositories/subscribers_repository.dart';
import '../datasources/subscribers_remote_datasource.dart';
import '../models/subscriber_model.dart';

double _d(Object? v) => jDouble(v);

class SubscribersRepositoryImpl implements SubscribersRepository {
  SubscribersRepositoryImpl(this._remote, {this.tankerLookup});
  final SubscribersRemoteDatasource _remote;

  /// حقن اختياري من وحدة طلبات الوايت لجلب آخر مبلغ (تجنباً للاعتماد الدائري).
  final Future<double?> Function(int subscriberId)? tankerLookup;

  @override
  Future<List<Subscriber>> getSubscribers({String? query, int? squareId, SubscriberStatus? status, int? perPage}) =>
      _remote.getSubscribers(query: query, squareId: squareId, status: status?.apiValue, perPage: perPage);

  @override
  Future<Subscriber360> getSubscriber360(int id) async {
    final j = await _remote.get360(id);
    final summaryJson = jMap(j['financial_summary']);
    final metersJson = jMapList(j['meters']);
    final meters = metersJson.map(MeterBalanceModel.fromJson).toList();
    return Subscriber360(
      subscriber: SubscriberModel.fromJson(j),
      summary: FinancialSummary(
        generalBalance: _d(summaryJson['general_balance']),
        totalCredit: _d(summaryJson['total_credit_balance']),
        totalDebit: _d(summaryJson['total_debit_balance']),
        remainingSubscriptionFee: _d(summaryJson['remaining_subscription_fee']),
        subscriptionFeeStatus: jStr(summaryJson['subscription_fee_status']) ?? 'paid',
        meterBalances: meters,
      ),
      metersRaw: metersJson,
      invoices: jMapList(j['invoices']),
      payments: jMapList(j['payments']),
    );
  }

  @override
  Future<Subscriber> createSubscriber(Subscriber draft) => _remote.create(_payload(draft));

  @override
  Future<Subscriber> updateSubscriber(Subscriber s) => _remote.update(s.id, _payload(s));

  Map<String, dynamic> _payload(Subscriber s) => {
        'name': s.name,
        'phone': s.phone,
        'square_id': s.squareId,
        'status': s.status.apiValue,
        'subscription_start_date': s.subscriptionStartDate,
        'address': s.address,
      };

  @override
  Future<void> deleteSubscriber(int id) => _remote.delete(id);

  @override
  Future<int> bulkDelete(List<int> ids) => _remote.bulkDelete(ids);

  @override
  Future<List<LedgerEntry>> getStatement(int subscriberId, {PartyFilter party = const PartyAll()}) =>
      _remote.getTransactions(subscriberId, meterId: party.apiValue);

  @override
  Future<List<Square>> getSquaresLookup() => _remote.getSquares();

  @override
  Future<Map<String, dynamic>> collectPayment(int subscriberId,
          {required double amount, int? invoiceId, String method = 'cash', String? reason}) =>
      _remote.collectPayment(subscriberId, {
        'amount': amount,
        if (invoiceId != null) 'invoice_id': invoiceId,
        'payment_method': method,
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      });

  @override
  Future<double?> lastTankerAmount(int subscriberId) async =>
      tankerLookup == null ? null : tankerLookup!(subscriberId);
}
