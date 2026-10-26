import '../../data/models/payment_model.dart';
import '../../data/models/payments_page.dart';

/// Optional capability preserving the existing list contract for embedded
/// receipt widgets while exposing Laravel's full paginator to the main list.
abstract interface class PaginatedPaymentsRepository {
  Future<PaymentsPage> listPage(PaymentQuery query);
}
