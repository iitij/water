/// Defaults and keys mirror InvoiceSettings.php, ReceiptSettings.php and the
/// current SettingController templates. Keeping them in the domain layer makes
/// them available to forms, fakes and offline cache restoration.
abstract final class SettingsDefaults {
  static const sharedHeader = <String, Object?>{
    'invoice_project_name_ar': 'مشروع مياه الوشاح',
    'invoice_project_name_en': 'Washah Water Project',
    'invoice_address_ar': 'حارة الفوارس - سعوان',
    'invoice_address_en': "Harat Al-Fawaris - Sa'wan",
    'invoice_phone_1': '1234567890',
    'invoice_phone_2': '1234567890',
    'invoice_logo': null,
    'invoice_watermark': null,
  };

  static final invoice = <String, Object?>{
    ...sharedHeader,
    'invoice_collector_name': 'علي الوشاح',
    'site_favicon': null,
    'inv_title_main': 'فاتورة استهلاك مياه',
    'inv_title_subscriber': 'بيانات المشترك',
    'inv_title_consumption': 'تفاصيل الاستهلاك',
    'inv_title_total': 'المبلغ المستحق',
    ..._fieldPairs('inv', const {
      'bill_number': 'رقم الفاتورة',
      'date_from': 'من تاريخ',
      'date_to': 'إلى تاريخ',
      'sub_name': 'اسم المشترك',
      'meter_no': 'رقم العداد',
      'sub_phone': 'الهاتف',
      'square': 'المربع',
      'address': 'العنوان',
      'account_no': 'رقم الحساب',
      'prev_read': 'القراءة السابقة',
      'curr_read': 'القراءة الحالية',
      'units': 'وحدات الاستهلاك',
      'unit_price': 'سعر الوحدة',
      'cons_value': 'قيمة الاستهلاك',
      'sub_fee': 'الاشتراك',
      'arrears': 'المتأخرات',
      'credit_applied': 'رصيد مخصوم',
      'collector': 'المحصل',
      'signature': 'توقيع المشترك',
      'thank_you': 'شكراً لدفعكم في الموعد / Thank you for your timely payment',
      'notes': 'الملاحظات',
    }),
    'sub_inv_title_main': 'فاتورة اشتراك',
    'sub_inv_title_details': 'تفاصيل رسوم الاشتراك',
    'sub_show_amount': true,
    'sub_lbl_amount': 'قيمة الاشتراك',
    'sub_show_arrears': true,
    'sub_lbl_arrears': 'المتأخرات',
    'sub_show_notes': true,
    'sub_lbl_notes': 'ملاحظات',
  };

  static final receipt = <String, Object?>{
    ...sharedHeader,
    'rect_title_main': 'سند قبض',
    'rect_show_title_subscriber': true,
    'rect_title_subscriber': 'بيانات المشترك',
    'rect_show_title_payment': true,
    'rect_title_payment': 'بيانات الدفع',
    ..._fieldPairs('rect', const {
      'receipt_number': 'رقم السند',
      'receipt_date': 'تاريخ السند',
      'sub_name': 'اسم المشترك',
      'sub_phone': 'الهاتف',
      'square': 'المربع السكني',
      'account_no': 'رقم الحساب',
      'payment_method': 'طريقة الدفع',
      'linked_invoice': 'الفاتورة المرتبطة',
      'reference': 'رقم الحوالة / الشيك',
      'invoice_total': 'إجمالي الفاتورة',
      'prev_payments': 'مدفوعات سابقة',
      'amount_received': 'المبلغ المستلم',
      'remaining_invoice': 'المتبقي من الفاتورة',
      'outstanding_total': 'إجمالي المتبقي للحساب',
      'credit_balance': 'رصيد دائن للمشترك',
      'reason': 'وذلك مقابل',
      'amount_in_words': 'المبلغ المستلم كتابتا',
      'collector_sig': 'توقيع المحصل',
      'receiver_sig': 'توقيع المستلم',
      'notes': 'ملاحظات',
    }),
    'rect_amount_words_suffix': 'ريال يمني لا غير',
  };

  static Map<String, Object?> _fieldPairs(
    String prefix,
    Map<String, String> labels,
  ) {
    return {
      for (final entry in labels.entries) ...{
        '${prefix}_show_${entry.key}': true,
        '${prefix}_lbl_${entry.key}': entry.value,
      },
    };
  }

  static const invoiceMessage = '''فاتورة استهلاك مياه
الأخ:  {subscriber_name}،
تم إصدار فاتورتك رقم (#{bill_number})
للفترة :
من {period_start} إلى {period_end}.

تفاصيل القراءة والاستهلاك:
• رقم العداد: {meter_code}
• القراءة السابقة: {previous_reading}
• القراءة الحالية: {current_reading}
• كمية الاستهلاك: {consumption_units} وحدة
(سعر الوحدة: {unit_price}) ريال

تفاصيل الرسوم والحساب:
• قيمة الاستهلاك: {consumption_fee} ريال
{if:subscription_fee}• رسوم الاشتراك: {subscription_fee} ريال{/if:subscription_fee}
{if:previous_balance}• متأخرات سابق: {previous_balance} ريال{/if:previous_balance}
{if:credit_applied}• رصيد مخصوم: {credit_applied} ريال{/if:credit_applied}
------------------------
الإجمالي المستحق: {total_amount} ريال
------------------------
المحصل: {collector}

لمعاينة وتحميل الفاتورة الرسمية (PDF):
{pdf_url}

شكراً لدفعكم في الموعد المحدد''';

  static const paymentMessage = '''سند قبض
الأخ: {subscriber_name}،
تم استلام مبلغ {amount} ريال بنجاح.
رقم السند: {receipt_number}
تاريخ الدفع: {date}
طريقة الدفع: {payment_method}

المتبقي من الفاتورة: {invoice_remaining} ريال
{if:credit_balance}الرصيد الدائن: {credit_balance} ريال{/if:credit_balance}

المحصل: {collector}

للاطلاع على السند وتحميله (PDF):
{pdf_url}

شكراً لتعاملكم معنا''';

  static const subscriptionMessage = '''فاتورة رسوم اشتراك
الأخ: {subscriber_name}،
تم إصدار فاتورة رسوم اشتراك جديدة رقم (#{bill_number}).

قيمة الرسوم: {subscription_fee} ريال
{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}
------------------------
الإجمالي المستحق: {total_amount} ريال
------------------------
المحصل: {collector}

للاطلاع على الفاتورة وتحميلها (PDF):
{pdf_url}

شكراً لتعاملكم معنا''';
}
