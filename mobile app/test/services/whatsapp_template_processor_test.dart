import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/services/whatsapp_launcher_service.dart';
import 'package:water_project/core/settings/finance_config.dart';
import 'package:water_project/core/utils/phone_formatter.dart';
import 'package:water_project/features/invoices/data/models/invoice_model.dart';
import 'package:water_project/features/invoices/domain/accounting/finance_enums.dart';
import 'package:water_project/features/invoices/presentation/whatsapp/invoice_whatsapp_builder.dart';

/// اختبارات محرك رسائل الواتساب — المرجع `WhatsAppService` (`app/Services/WhatsAppService.php`):
///  - المتغيرات `{name}` `{receipt_number}` `{amount}` `{invoice_remaining}`
///    `{outstanding_balance}` `{credit_balance}` `{pdf_link}` (سطر 187-215).
///  - البلوكات الشرطية `{if:key}...{/if:key}` تظهر فقط إذا كان الرقم > 0.
///  - هاتف المشترك هو جهة الاتصال المعتمدة.
void main() {
  group('WhatsAppTemplateProcessor.render', () {
    test('يستبدل المتغيرات المعروفة ويترك المجهولة كما هي', () {
      final text = WhatsAppTemplateProcessor.render(
        template: 'أهلاً {name}، سندك {receipt_number} بقيمة {amount}',
        variables: const <String, String>{
          'name': 'أحمد',
          'receipt_number': 'REC-202601-0007',
          'amount': '1,500',
        },
      );

      expect(text, 'أهلاً أحمد، سندك REC-202601-0007 بقيمة 1,500');
    });

    test('يُبقي على `{unknown}` غير المعرَّف (يشخّص قالباً خاطئاً بدل الصمت)', () {
      final text = WhatsAppTemplateProcessor.render(
        template: 'رصيد: {credit_balance} — {unknown}',
        variables: const <String, String>{'credit_balance': '250'},
      );

      expect(text, contains('250'));
      expect(text, contains('{unknown}'));
    });

    test('البلوك الشرطي يظهر عندما تكون القيمة أكبر من صفر', () {
      const template = 'فاتورتك\n{if:previous_balance}متأخرات: {previous_balance}\n{/if:previous_balance}شكراً';

      final withDebt = WhatsAppTemplateProcessor.render(
        template: template,
        variables: const <String, String>{'previous_balance': '300'},
        conditionalValues: const <String, num>{'previous_balance': 300},
      );
      expect(withDebt, contains('متأخرات: 300'));

      final withoutDebt = WhatsAppTemplateProcessor.render(
        template: template,
        variables: const <String, String>{'previous_balance': '0'},
        conditionalValues: const <String, num>{'previous_balance': 0},
      );
      expect(withoutDebt, isNot(contains('متأخرات')));
      expect(withoutDebt, 'فاتورتك\nشكراً');
    });

    test('البلوك الشرطي متعدد الأسطر يُزال كاملاً (dotAll)', () {
      const template = 'رأس\n{if:subscription_fee}سطر ١\nسطر ٢\n{/if:subscription_fee}ذيل';

      final removed = WhatsAppTemplateProcessor.render(
        template: template,
        variables: const <String, String>{},
        conditionalValues: const <String, num>{'subscription_fee': 0},
      );
      expect(removed, 'رأس\nذيل');

      final kept = WhatsAppTemplateProcessor.render(
        template: template,
        variables: const <String, String>{},
        conditionalValues: const <String, num>{'subscription_fee': 500},
      );
      expect(kept, contains('سطر ١'));
      expect(kept, contains('سطر ٢'));
    });

    test('يدعم أكثر من بلوك لنفس المفتاح', () {
      const template = '{if:credit}أ{credit}{/if:credit} و {if:credit}ب{/if:credit}';

      final text = WhatsAppTemplateProcessor.render(
        template: template,
        variables: const <String, String>{'credit': '10'},
        conditionalValues: const <String, num>{'credit': 10},
      );

      expect(text, 'أ10 و ب');
    });

    test('يزال الأسطر الفارغة المتكررة ويقصّ الأطراف', () {
      const template = '\n\n  مرحبًا  \n\n\n\n';

      expect(WhatsAppTemplateProcessor.render(template: template, variables: const {}), 'مرحبًا');
    });
  });

  group('PhoneFormatter.formatYemeniPhone', () {
    test('أرقام محلية تبدأ بـ7 ⇒ يضيف 967', () {
      expect(WhatsAppTemplateProcessor.formatYemeniPhone('0771234567'), '967771234567');
      expect(WhatsAppTemplateProcessor.formatYemeniPhone('+967 77 123 4567'), '967771234567');
    });

    test('أرقام أرضية تبدأ بـ1 (9 خانات) ⇒ يضيف 967', () {
      expect(WhatsAppTemplateProcessor.formatYemeniPhone('0123456789'), '967123456789');
      // 8 خانات فقط: لا يطابق قاعدة الخادم، فيُرسل كما هو بعد إزالة الصفر.
      expect(WhatsAppTemplateProcessor.formatYemeniPhone('012345678'), '12345678');
    });

    test('يزيل 00 الافتتاحية والصفر المحلي', () {
      expect(WhatsAppTemplateProcessor.formatYemeniPhone('00967771234567'), '967771234567');
    });

    test('لا رقم صالح ⇒ null (تُخفى أزرار الواتساب)', () {
      expect(WhatsAppTemplateProcessor.formatYemeniPhone(null), isNull);
      expect(WhatsAppTemplateProcessor.formatYemeniPhone('—'), isNull);
      expect(WhatsAppTemplateProcessor.formatYemeniPhone('123'), '123');
    });

    test('رابط wa.me يُبنى مع ترميز النص', () {
      final link = PhoneFormatter.waMeLink('0771234567', 'مرحبًا أحمد');

      expect(link, startsWith('https://wa.me/967771234567?text='));
      expect(link, contains('%D9%85'));
    });
  });

  group('InvoiceWhatsAppBuilder.renderMessage', () {
    const config = FinanceConfig(
      invoiceTemplate: 'فاتورة {bill_number} لـ {subscriber_name}\n'
          'الإجمالي: {total_amount}\n'
          '{if:previous_balance}متأخرات: {previous_balance}\n{/if:previous_balance}'
          '{if:credit_applied}رصيد دائن: {credit_applied}\n{/if:credit_applied}'
          ' Collector: {collector}',
    );

    test('يجمع المتأخرات في `total_amount` مثل WhatsAppService', () {
      final invoice = InvoiceModel(
        id: 9,
        subscriberId: 3,
        invoiceType: InvoiceType.meter,
        billNumber: 'INV-202601-0009',
        totalAmount: 1000,
        previousBalance: 250,
        creditApplied: 0,
        billDate: DateTime(2026, 1, 1),
        periodStart: DateTime(2025, 12, 1),
        periodEnd: DateTime(2025, 12, 31),
        subscriber: const InvoiceSubscriberRef(id: 3, name: 'صالح'),
      );

      final message = InvoiceWhatsAppBuilder.renderMessage(config, invoice);

      expect(message, contains('الإجمالي: 1,250'));
      expect(message, contains('متأخرات: 250'));
      expect(message, isNot(contains('رصيد دائن')));
      expect(message, contains('INV-202601-0009'));
      expect(message, contains('صالح'));
    });

    test('يرسل إشعار الفاتورة إلى هاتف واسم المشترك حصراً', () {
      final invoice = const InvoiceModel(
        id: 10,
        subscriberId: 3,
        invoiceType: InvoiceType.meter,
        billNumber: 'INV-10',
        subscriber: InvoiceSubscriberRef(id: 3, name: 'صالح', phone: '0700000000'),
        meter: InvoiceMeterRef(
          id: 5,
          uniqueCode: 'M-5',
        ),
      );

      expect(invoice.messageRecipientPhone, '0700000000');
      expect(invoice.beneficiaryName, 'صالح');
      expect(invoice.messageRecipientName, 'صالح');
    });

  });
}
