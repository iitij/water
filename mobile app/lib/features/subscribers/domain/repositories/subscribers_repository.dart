import '../../../squares/domain/entities/square.dart';
import '../entities/subscriber.dart';

/// تفاصيل ملف 360° كاملة (SCR-07).
class Subscriber360 {
  const Subscriber360({
    required this.subscriber,
    required this.summary,
    this.metersRaw = const [],
    this.invoices = const [],
    this.payments = const [],
  });
  final Subscriber subscriber;
  final FinancialSummary summary;
  final List<Map<String, dynamic>> metersRaw;
  final List<Map<String, dynamic>> invoices;
  final List<Map<String, dynamic>> payments;
}

abstract class SubscribersRepository {
  Future<List<Subscriber>> getSubscribers({String? query, int? squareId, SubscriberStatus? status, int? perPage});
  Future<Subscriber360> getSubscriber360(int id);
  Future<Subscriber> createSubscriber(Subscriber draft);
  Future<Subscriber> updateSubscriber(Subscriber subscriber);
  Future<void> deleteSubscriber(int id);
  Future<int> bulkDelete(List<int> ids);
  Future<List<LedgerEntry>> getStatement(int subscriberId, {PartyFilter party = const PartyAll()});
  Future<List<Square>> getSquaresLookup();
  Future<Map<String, dynamic>> collectPayment(int subscriberId, {required double amount, int? invoiceId, String method = 'cash', String? reason});
  /// آخر مبلغ وايت للمشترك (السعر الافتراضي لزر "طلب وايت" السريع) أو null.
  Future<double?> lastTankerAmount(int subscriberId);
}
