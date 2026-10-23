import '../../../../core/network/finance_endpoints.dart';
import '../../../../core/services/whatsapp_launcher_service.dart';
import '../../../../core/settings/finance_config.dart';
import '../../../../core/utils/currency_formatter.dart';
import '../../../../core/utils/json_utils.dart';
import '../../data/models/invoice_model.dart';
import '../../domain/accounting/finance_enums.dart';

/// يبني رسالة واتساب الفاتورة مطابقةً لـ `WhatsAppService::getInvoiceMessageUrl`
/// (`app/Services/WhatsAppService.php` سطر 60-186):
///  - اختيار القالب حسب `invoice_type` (عداد/اشتراك/وايت).
///  - أولوية `meter.tenant_phone` ثم `subscriber.phone`.
///  - المتغيرات `total_amount = total_amount + previous_balance - credit_applied`.
///  - البلوكات الشرطية `{if:subscription_fee}` و`{if:tanker_fee}` و`{if:previous_balance}`
///    و`{if:credit_applied}`.
class InvoiceWhatsAppBuilder {
  InvoiceWhatsAppBuilder({required FinanceConfigRepository configRepository})
      : _configRepository = configRepository;

  final FinanceConfigRepository _configRepository;

  WhatsAppPayload build(
    InvoiceModel invoice, {
    FinanceConfig? overrideConfig,
    String? collectorName,
  }) {
    final config = overrideConfig ?? _configRepository.cached;

    return WhatsAppPayload(
      kind: 'invoice',
      recordId: invoice.id,
      phone: invoice.messageRecipientPhone,
      message: renderMessage(
        config,
        invoice,
        collectorName: collectorName,
      ),
      linkPath: FinanceEndpoints.invoiceWhatsappLink(invoice.id),
      sendPath: FinanceEndpoints.invoiceSendWhatsapp(invoice.id),
      attachmentName: 'invoice-${invoice.billNumber}.pdf',
    );
  }

  /// قابل للاختبار مباشرة (بدون DI): يُمرَّر إليه `FinanceConfig` صراحة.
  static String renderMessage(
    FinanceConfig config,
    InvoiceModel invoice, {
    String? collectorName,
    String? pdfUrl,
  }) {
    final template = switch (invoice.invoiceType) {
      InvoiceType.subscription => config.subscriptionInvoiceTemplate,
      InvoiceType.tanker => config.tankerInvoiceTemplate,
      InvoiceType.meter => config.invoiceTemplate,
    };

    final firstReading = invoice.readings.isEmpty ? null : invoice.readings.first;
    final lastReading = invoice.readings.isEmpty ? null : invoice.readings.last;
    final unitPrice = invoice.impliedUnitPrice ?? 0.0;

    final variables = <String, String>{
      'subscriber_name': invoice.messageRecipientName.isEmpty
          ? (invoice.subscriber?.name ?? '')
          : invoice.messageRecipientName,
      'bill_number': invoice.billNumber,
      'total_amount': CurrencyFormatter.amount(invoice.netPayable),
      'pdf_url': pdfUrl ?? '',
      'period_start': DateFormat.day(invoice.periodStart),
      'period_end': DateFormat.day(invoice.periodEnd),
      'meter_code': invoice.meter?.uniqueCode ?? '',
      'square': invoice.subscriber?.squareName ?? '',
      'previous_reading': _num(firstReading?.previousReading),
      'current_reading': _num(lastReading?.currentReading),
      'consumption_units': _num(invoice.consumptionUnits),
      'unit_price': CurrencyFormatter.amount(unitPrice),
      'consumption_fee': CurrencyFormatter.amount(invoice.consumptionFee),
      'subscription_fee': CurrencyFormatter.amount(invoice.subscriptionInstallment),
      'tanker_fee': CurrencyFormatter.amount(invoice.tankerCharges),
      'previous_balance': CurrencyFormatter.amount(invoice.previousBalance),
      'credit_applied': CurrencyFormatter.amount(invoice.creditApplied),
      'collector': collectorName ?? 'إدارة المشروع',
      // متغيرات مرادفة تُستعمل في بعض القوالب المحفوظة في جدول `settings`.
      'name': invoice.messageRecipientName,
    };

    final conditional = <String, num>{
      'subscription_fee': invoice.subscriptionInstallment,
      'tanker_fee': invoice.tankerCharges,
      'previous_balance': invoice.previousBalance,
      'credit_applied': invoice.creditApplied,
    };

    return WhatsAppTemplateProcessor.render(
      template: template,
      variables: variables,
      conditionalValues: conditional,
    );
  }

  static String _num(double? value) => CurrencyFormatter.amount(value);
}
