/// أسماء الشاشات المشتركة مع Laravel؛ المصدر هو موارد وصفحات Filament.
/// تبقى أسماء أدوات الميدان الخاصة بالتطبيق مستقلة عن أسماء هذه الشاشات.
abstract final class LaravelScreenStrings {
  static const subscribers = 'المشتركون';
  static const squares = 'المربعات السكنية';
  // قائمة Laravel تعرض navigationLabel بدلاً من اسم مجموعة السجلات.
  static const meters = 'إدارة العدادات';
  static const readings = 'قراءات العدادات';
  static const invoices = 'الفواتير';
  static const payments = 'المدفوعات';
  static const subscriptionFees = 'طلبات الاشتراك';
  static const transactions = 'سجل الحركات (الدفتر)';
  static const tariffs = 'التعرفة والتسعير';
  static const settings = 'الإعدادات';
  static const readingSettings = 'إعدادات فترة القراءات';
  static const invoiceSettings = 'إعدادات وتصميم الفاتورة';
  static const receiptSettings = 'إعدادات وتصميم سند القبض';
  static const exports = 'تصدير البيانات';
  static const cashReview = 'صناديق المحصّلين';
  static const audit = 'سجل التدقيق';
  static const voidRequests = 'اعتماد إلغاء السندات';
  static const users = 'المستخدمين';
  // ترجمة Filament العربية لعنوان Dashboard.
  static const dashboard = 'لوحة التحكم';
  static const profile = 'الملف الشخصي';
  static const profileNavigation = 'تعديل الملف الشخصي';
  static const whatsappSettings = 'إعدادات تنبيهات الواتساب';
  static const whatsappNavigation = 'التنبيهات والرسائل';
}
