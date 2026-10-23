import '../../../invoices/data/models/invoice_model.dart';
import '../../data/models/collect_payment_models.dart';
import '../../data/models/payment_model.dart';

/// عقد مستودع سندات القبض والتحصيل.
///
/// ملاحظة معمارية (Dev 3): نعتمد «النموذج ككيان» (Model-as-Entity) بدل تكرار
/// `entity + freezed model`، لأن خمسة مطورين يعملون بالتوازي على فروع متباعدة ولا
/// نريد توليد كود (`build_runner`) في كل دمج. العقد يبقى هو نفس سطح API.
abstract interface class PaymentsRepository {
  Future<List<PaymentModel>> list(PaymentQuery query);
  Future<PaymentModel> byId(int id);

  /// التحصيل: يُفضَّل `/invoices/{id}/pay` لأنه يرجع `receipt_data` الكاملة
  /// (المتبقي من الفاتورة، إجمالي المتبقي، الرصيد الدائن، اسم المحصل)،
  /// ويُسند إلى `POST /payments` عند عدم تحديد فاتورة (توزيع FIFO تلقائي).
  Future<CollectPaymentOutcome> collect(CollectPaymentRequest request);

  Future<PaymentModel> update(int id, Map<String, dynamic> payload);
  Future<void> delete(int id);
  Future<int> bulkDelete(List<int> ids);

  /// أحدث فاتورة غير مسددة للمشترك (تعبئة تلقائية في `SCR-18`).
  Future<InvoiceModel?> latestUnpaidInvoice(int subscriberId);

  String pdfPath(int paymentId);
  String whatsappLinkPath(int paymentId);
  String sendWhatsappPath(int paymentId);
  String printFallbackPath(int paymentId);
}
