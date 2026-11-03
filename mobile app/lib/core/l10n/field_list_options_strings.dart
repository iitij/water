/// Arabic copy for route-aware field list controls.
abstract final class FieldListOptionsStrings {
  static const searchHint = 'ابحث بالعداد أو المشترك أو رقم المسار';
  static const searchHelp =
      'للبحث داخل مربع ومسار اكتب 4/1 (المربع 4، المسار 1).';
  static const pathNumber = 'رقم المسار';
  static const pathHelp = 'اتركه فارغاً لعرض كل المسارات';
  static const invalidPath = 'أدخل رقم مسار صحيحاً من صفر فأكثر';
  static const subscriptionStart = 'تاريخ بدء الاشتراك';
  static const subscriberAddedOrder = 'ترتيب إضافة المشترك';
  static const installedAt = 'تاريخ تركيب العداد';
  static const meterCreatedAt = 'تاريخ إضافة العداد';
  static const invoicingStatus = 'حالة الفوترة';
  static String pathFilter(int number) => 'المسار $number';
}
