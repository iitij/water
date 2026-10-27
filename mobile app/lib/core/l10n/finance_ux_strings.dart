/// Plain-language guidance for collecting payments and closing the cashbox.
abstract final class FinanceUxStrings {
  static const paymentDetails = 'تفاصيل إضافية';
  static const paymentDetailsHint = 'سبب الدفع والملاحظات وتاريخ السند';
  static const reviewPayment = 'راجع المبلغ وطريقة الدفع ثم احفظ السند.';
  static const automaticAllocation = 'تسديد الأقدم أولاً';
  static const allocationExplanation =
      'سيُوزّع المبلغ على أقدم الفواتير المستحقة.';
  static const collectorTitle = 'تحصيل مبلغ';
  static const collectorSubtitle =
      'ابحث عن المشترك، ثم اختر الفاتورة وسجّل المبلغ المستلم';
  static const searchClear = 'مسح البحث';
  static const filteredEmptyTitle = 'لا توجد فواتير تطابق اختيارك';
  static const filteredEmptyHint =
      'جرّب اسماً آخر أو أزل اختيار المربع لعرض الفواتير المتاحة.';
  static const clearFilters = 'عرض كل الفواتير';
  static const noInvoices = 'لا توجد فواتير متاحة للتحصيل في هذه القائمة.';
  static const closeDay = 'إنهاء التحصيل وإغلاق الصندوق';
  static const closeDayHint =
      'عند انتهاء العمل، عدّ النقد الموجود معك ثم أدخل المبلغ للمطابقة.';
  static const cashMatches = 'المبلغ مطابق للصندوق';
  static const cashShortage = 'يوجد نقص في النقد';
  static const cashSurplus = 'يوجد مبلغ زائد';
  static const cashCountHint = 'أدخل ما عدَدته فعلياً، وليس المبلغ المتوقع.';
}
