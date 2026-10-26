import '../../data/models/payment_model.dart';

/// Sorting is optional so older embedded repositories remain compatible.
abstract interface class SortedVoidRequestsRepository {
  Future<List<PaymentVoidRequest>> sortedVoidRequests({
    required int page,
    required int perPage,
    required String sortBy,
    required String sortDirection,
  });
}
