/// Arabic copy for the field workspace and meter cards.
abstract final class FieldExperienceStrings {
  static const title = 'القارئ الميداني';
  static const subtitle = 'العداد، القراءة، ثم التالي';
  static const searchHint = 'ابحث بالاسم أو رقم العداد أو المسار';
  static const searchHelp = 'للبحث بالمربع والمسار استخدم 4/1';
  static const downloadPack = 'تنزيل حزمة العمل أوفلاين';
  static const scanMeter = 'مسح باركود العداد';
  static const scanHint = 'وجّه الكاميرا إلى باركود أو QR الموجود على العداد.';
  static const loading = 'جارٍ تحميل العدادات…';
  static const noMeters = 'لا توجد عدادات';
  static const loadFailed = 'تعذر تحميل العدادات';
  static const emptyHelp = 'حمّل بيانات المربع قبل بدء الجولة الميدانية.';
  static const reload = 'إعادة التحميل';
  static const matchingMeters = 'العدادات المطابقة';
  static const allSquares = 'كل المربعات';
  static const filterSquare = 'فلترة بالمربع السكني';
  static const square = 'المربع السكني';
  static const squareHelp = 'اختر مربعاً واحداً أو ابحث في كل المربعات';
  static const unknownSubscriber = 'مشترك غير محدد';
  static const meterCode = 'رقم العداد';
  static const path = 'المسار';
  static const tenant = 'المستأجر';
  static const lastReading = 'آخر قراءة';
  static const balance = 'رصيد العداد';
  static const currency = 'ريال';
  static const recordReading = 'تسجيل قراءة';
  static const meterOptions = 'خيارات العداد';
  static const editMeter = 'تعديل العداد';
  static const deleteMeter = 'حذف العداد';
  static const active = 'نشط';
  static const inactive = 'غير نشط';
  static const selectMeter = 'تحديد العداد';
  static String resultCount(int count) => '$count عداد مطابق';
  static String tenantName(String name) => '$tenant: $name';
}
