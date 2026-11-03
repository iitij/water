/// Copy for invoice actions after recording a reading.
abstract final class ReadingInvoiceStrings {
  static const sms = 'رسالة SMS';
  static const whatsapp = 'واتساب مع الصورة';
  static const sharingHelp =
      'لمشاركة الصورة، اختر واتساب من قائمة المشاركة ثم المشترك وأكّد الإرسال. '
      'رسالة SMS تفتح تطبيق الرسائل على جهاز يدعم إرسالها.';
  static const unavailable = 'خدمة مشاركة الفاتورة غير متاحة. أعد فتح الشاشة.';
  static const missingPhone = 'رقم هاتف المشترك غير متاح لإرسال الفاتورة.';
  static const smsUnavailable =
      'تعذر فتح الرسائل النصية. استخدم جهازاً يدعم SMS أو اربط الهاتف بالحاسوب.';
  static const missingInvoice = 'رقم الفاتورة غير متاح لتحميل صورتها.';
  static const invalidPdf =
      'تعذر تحميل ملف الفاتورة. أعد المحاولة بعد التحقق من الاتصال.';
  static const imageFailed =
      'تعذر تجهيز صورة الفاتورة. أعد المحاولة أو اطبع الفاتورة.';
  static const shareFailed = 'تعذر فتح قائمة المشاركة على هذا الجهاز.';
  static const invoiceTitle = 'فاتورة استهلاك مياه';
  static String number(String value) => 'رقم الفاتورة: $value';
  static String period(String start, String end) => 'الفترة: $start — $end';
  static String meter(String value) => 'العداد: $value';
  static String consumption(double value) => 'الاستهلاك: $value وحدة';
  static String total(double value) => 'الإجمالي المستحق: $value ريال';
  static String recipient(String? name, String phone) =>
      'المستلم: ${name == null || name.trim().isEmpty ? '' : '$name — '}+$phone';
}
