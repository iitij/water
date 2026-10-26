import '../../../../core/finance/finance_api_client.dart';
import '../../../../core/finance/finance_search.dart';
import '../../../../core/network/finance_endpoints.dart';
import '../../domain/repositories/invoices_repository.dart';
import '../../domain/repositories/paginated_invoices_repository.dart';
import '../datasources/invoices_remote_datasource.dart';
import '../models/invoice_model.dart';
import '../models/invoice_query.dart';
import '../models/invoices_page.dart';

/// تنفيذ المستودع. حالياً شبكة فقط؛ يُضاف `InvoicesLocalDataSource` (Drift) كطبقة
/// Cache-First عندما يدمج Dev 2 قاعدة البيانات المحلية، عبر نفس العقد.
class InvoicesRepositoryImpl
    implements InvoicesRepository, PaginatedInvoicesRepository {
  const InvoicesRepositoryImpl({required InvoicesRemoteDataSource remote})
    : _remote = remote;

  final InvoicesRemoteDataSource _remote;

  @override
  Future<InvoicesPage> listPage(InvoiceQuery query) => _remote.listPage(query);

  @override
  Future<List<InvoiceModel>> list(InvoiceQuery query) => _remote.list(query);

  @override
  Future<List<InvoiceModel>> collectorList({
    FinanceSearchQuery search = const FinanceSearchQuery(rawQuery: ''),
    int? squareId,
  }) {
    if (search.isEmpty && squareId == null) return _remote.unpaid();
    return _remote.search(query: search.rawQuery, squareId: squareId);
  }

  @override
  Future<List<BillingPeriodOption>> billingPeriods() =>
      _remote.billingPeriods();

  @override
  Future<InvoiceModel> byId(int id) => _remote.byId(id);

  @override
  Future<InvoiceModel> createManual(Map<String, dynamic> payload) =>
      _remote.create(payload);

  @override
  Future<InvoiceModel> update(int id, Map<String, dynamic> payload) =>
      _remote.update(id, payload);

  @override
  Future<InvoiceModel> generateMeter(GenerateMeterInvoiceRequest request) =>
      _remote.generateMeter(request);

  @override
  Future<InvoiceModel> generateSubscription(
    GenerateSubscriptionInvoiceRequest request,
  ) => _remote.generateSubscription(request);

  @override
  Future<InvoiceModel> generateTanker(GenerateTankerInvoiceRequest request) =>
      _remote.generateTanker(request);

  @override
  Future<void> delete(int id) => _remote.delete(id);

  @override
  Future<void> forceDelete(int id) => _remote.forceDelete(id);

  @override
  Future<BulkActionResult> bulkDelete(List<int> ids) => _remote.bulkDelete(ids);

  @override
  Future<void> restore(int id) => _remote.restore(id);

  @override
  Future<BulkActionResult> bulkRestore(List<int> ids) =>
      _remote.bulkRestore(ids);

  @override
  Future<BulkActionResult> bulkForceDelete(List<int> ids) =>
      _remote.bulkForceDelete(ids);

  @override
  Future<InvoiceModel> recalculate(int id) => _remote.recalculate(id);

  @override
  Future<BulkActionResult> bulkRecalculate(List<int> ids) =>
      _remote.bulkRecalculate(ids);

  @override
  String pdfPath(int id) => FinanceEndpoints.invoicePdf(id);

  @override
  String whatsappLinkPath(int id) => FinanceEndpoints.invoiceWhatsappLink(id);

  @override
  String sendWhatsappPath(int id) => FinanceEndpoints.invoiceSendWhatsapp(id);

  @override
  String printFallbackPath(int id) =>
      financeAdminPrintUrl('/invoices/$id/print');
}
