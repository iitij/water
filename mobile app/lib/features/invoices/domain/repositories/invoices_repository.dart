import '../../../../core/finance/finance_search.dart';
import '../../data/models/invoice_model.dart';
import '../../data/models/invoice_query.dart';

/// عقد مستودع الفواتير — يعزّل الكيوبتات عن الشبكة ويترك مجالاً لطبقة الكاش
/// المحلية (Drift) يضيفها Dev 2 لاحقاً دون تعديل الواجهات.
abstract interface class InvoicesRepository {
  Future<List<InvoiceModel>> list(InvoiceQuery query);
  Future<List<InvoiceModel>> collectorList({
    FinanceSearchQuery search,
    int? squareId,
  });

  Future<List<BillingPeriodOption>> billingPeriods();
  Future<InvoiceModel> byId(int id);

  Future<InvoiceModel> createManual(Map<String, dynamic> payload);
  Future<InvoiceModel> update(int id, Map<String, dynamic> payload);

  Future<InvoiceModel> generateMeter(GenerateMeterInvoiceRequest request);
  Future<InvoiceModel> generateSubscription(GenerateSubscriptionInvoiceRequest request);
  Future<InvoiceModel> generateTanker(GenerateTankerInvoiceRequest request);

  Future<void> delete(int id);
  Future<void> forceDelete(int id);
  Future<BulkActionResult> bulkDelete(List<int> ids);
  Future<void> restore(int id);
  Future<BulkActionResult> bulkRestore(List<int> ids);
  Future<BulkActionResult> bulkForceDelete(List<int> ids);

  Future<InvoiceModel> recalculate(int id);
  Future<BulkActionResult> bulkRecalculate(List<int> ids);

  /// مسارات الملفات (PDF/واتساب) تُمرَّر كما هي إلى خدمات النواة.
  String pdfPath(int id);
  String whatsappLinkPath(int id);
  String sendWhatsappPath(int id);
  String printFallbackPath(int id);
}
