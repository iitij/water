import '../entities/subscription_fee.dart';

class FeesPage {
  const FeesPage({required this.fees, this.totalSum = 0, this.invoicedSum = 0, this.remainingSum = 0});
  final List<SubscriptionFee> fees;
  final double totalSum;
  final double invoicedSum;
  final double remainingSum;
}

abstract class SubscriptionFeesRepository {
  Future<FeesPage> getFees({FeeStatus? status, int? subscriberId});
  Future<SubscriptionFee> createFee({required int subscriberId, int? meterId, required double amount, FeeStatus status = FeeStatus.uninvoiced, String? notes});
  Future<SubscriptionFee> updateFee(SubscriptionFee fee);
  Future<void> deleteFee(int id);
  Future<int> bulkDelete(List<int> ids);
  Future<Map<String, dynamic>> invoiceFee(int id);
}
