import 'dart:math' as math;

import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../../invoices/data/models/invoice_model.dart';
import '../../data/models/payment_model.dart';

/// يبني رسالة واتساب سند القبض مطابقةً لـ `WhatsAppService::getPaymentMessageUrl`
/// (`app/Services/WhatsAppService.php` سطر 172-222):
///  - الهاتف: `meter.tenant_phone` ثم `subscriber.phone` (في النموذج يُقرأ
///    `subscriber_phone` الذي يجمع الأولوية في `PaymentModel.fromPaymentJson`).
///  - الاسم: `meter.tenant_name` ثم `subscriber.name` → `displayName`.
///  - `{invoice_remaining}` = `(total_amount + previous_balance) - credit_applied
///    - المدفوع قبل هذه الدفعة - مبلغها` (سطر 187-199).
///  - `{outstanding_balance}` و`{credit_balance}` من حساب المشترك العام.
class PaymentWhatsAppBuilder {
  const PaymentWhatsAppBuilder({required FinanceConfigRepository configRepository});

  final FinanceConfigRepository _configRepository;

  WhatsAppPayload build(
    PaymentModel payment, {
    FinanceConfig? overrideConfig,
    ReceiptDataModel? receiptData,
    InvoiceModel? invoice,
  }) {
    final config = overrideConfig ?? _configRepository.cached;

    return WhatsAppPayload(
      kind: 'payment',
      recordId: payment.id,
      phone: payment.subscriberPhone,
      message: renderMessage(
        config,
        payment,
        receiptData: receiptData,
        invoice: invoice,
      ),
      linkPath: FinanceEndpoints.paymentWhatsappLink(payment.id),
      sendPath: FinanceEndpoints.paymentSendWhatsapp(payment.id),
      attachmentName: 'receipt-${payment.receiptNumber}.pdf',
    );
  }

  /// قابل للاختبار مباشرة بدون DI.
  static String renderMessage(
    FinanceConfig config,
    PaymentModel payment, {
    ReceiptDataModel? receiptData,
    InvoiceModel? invoice,
    String? pdfUrl,
  }) {
    final remaining = receiptData?.invoiceRemaining ?? _localRemaining(payment, invoice);
    final credit = receiptData?.creditBalance ?? 0.0;
    final outstanding = receiptData?.outstandingBalance ?? 0.0;

    final variables = <String, String>{
      'name': payment.displayName,
      'subscriber_name': payment.displayName,
      'receipt_number': payment.receiptNumber,
      'amount': CurrencyFormatter.amount(payment.amount),
      'invoice_remaining': CurrencyFormatter.amount(remaining),
      'outstanding_balance': CurrencyFormatter.amount(outstanding),
      'credit_balance': CurrencyFormatter.amount(credit),
      'pdf_link': pdfUrl ?? '',
      'date': DateFormat.day(payment.paymentDate),
      'method': payment.method.label,
      'bill_number': payment.invoiceBillNumber ?? '',
      'collector': payment.collectorName ?? 'إدارة المشروع',
    };

    return WhatsAppTemplateProcessor.render(
      template: config.paymentTemplate,
      variables: variables,
      conditionalValues: <String, num>{
        'invoice_remaining': remaining,
        'credit_balance': credit,
        'outstanding_balance': outstanding,
      },
    );
  }

  /// تقدير محلي لمتبقي الفاتورة بعد الدفعة عندما لا يرد `receipt_data`.
  static double _localRemaining(PaymentModel payment, InvoiceModel? invoice) {
    if (invoice == null) return 0;
    return math.max(0, double.parse((invoice.netPayable - payment.amount).toStringAsFixed(2)));
  }
}
