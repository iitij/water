import '../../../../core/fouad/json_read.dart';
import '../../domain/entities/subscription_fee.dart';
import '../../domain/repositories/subscription_fees_repository.dart';
import '../datasources/subscription_fees_remote_datasource.dart';

class SubscriptionFeesRepositoryImpl implements SubscriptionFeesRepository {
  SubscriptionFeesRepositoryImpl(this._remote);
  final SubscriptionFeesRemoteDatasource _remote;

  @override
  Future<FeesPage> getFees({FeeStatus? status, int? subscriberId}) async {
    final j = await _remote.getFees(status: status?.apiValue, subscriberId: subscriberId);
    final fees = jMapList(j).map(SubscriptionFeesRemoteDatasource.parse).toList();
    return FeesPage(
      fees: fees,
      totalSum: SubscriptionFeesRemoteDatasource.d(j['total_sum']),
      invoicedSum: SubscriptionFeesRemoteDatasource.d(j['invoiced_sum']),
      remainingSum: SubscriptionFeesRemoteDatasource.d(j['remaining_sum']),
    );
  }

  @override
  Future<SubscriptionFee> createFee({required int subscriberId, int? meterId, required double amount, FeeStatus status = FeeStatus.uninvoiced, String? notes}) =>
      _remote.create({'subscriber_id': subscriberId, 'meter_id': meterId, 'amount': amount, 'status': status.apiValue, 'notes': notes});

  @override
  Future<SubscriptionFee> updateFee(SubscriptionFee f) => _remote.update(f.id, {
        'subscriber_id': f.subscriberId,
        'meter_id': f.meterId,
        'amount': f.amount,
        'status': f.status.apiValue,
        'notes': f.notes,
      });

  @override
  Future<void> deleteFee(int id) => _remote.delete(id);

  @override
  Future<int> bulkDelete(List<int> ids) => _remote.bulkDelete(ids);

  @override
  Future<Map<String, dynamic>> invoiceFee(int id) => _remote.invoice(id);
}
