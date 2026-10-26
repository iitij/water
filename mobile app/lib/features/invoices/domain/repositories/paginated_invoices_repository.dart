import '../../data/models/invoice_query.dart';
import '../../data/models/invoices_page.dart';

/// Optional capability keeps embedded invoice consumers compatible.
abstract interface class PaginatedInvoicesRepository {
  Future<InvoicesPage> listPage(InvoiceQuery query);
}
