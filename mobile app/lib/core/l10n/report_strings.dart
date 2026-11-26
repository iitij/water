abstract final class ReportStrings {
  static const title = 'التقارير المالية';
  static const subtitle = 'الفواتير والتحصيل خلال الفترة المحددة';
  static const selectPeriod = 'تحديد الفترة';
  static const refresh = 'تحديث';
  static const thisMonth = 'هذا الشهر';
  static const previousMonth = 'الشهر السابق';
  static const customPeriod = 'فترة أخرى';
  static const showReport = 'عرض التقرير';
  static const cancel = 'إلغاء';
  static const invoiced = 'قيمة الفواتير';
  static const collected = 'التحصيل';
  static const activeReceipts = 'السندات الفعالة خلال الفترة';
  static const outstanding = 'المتبقي الحالي من فواتير الفترة';
  static const overdue = 'المتأخرات';
  static const revenue = 'حركة التحصيل';
  static const revenueSubtitle = 'السندات الفعالة حسب يوم التحصيل';
  static const averageDay = 'متوسط أيام التحصيل';
  static const bestDay = 'أعلى يوم';
  static const statuses = 'الفواتير حسب الحالة';
  static const squares = 'التحصيل حسب المربع';
  static const noInvoices = 'لا توجد فواتير في هذه الفترة';
  static const noCollections = 'لا توجد تحصيلات في هذه الفترة';
  static const emptyPeriod = 'لا توجد حركة خلال الفترة المحددة';
  static const chooseAnotherPeriod =
      'اختر فترة أخرى للاطلاع على السجلات السابقة';
  static const detailsUnavailable = 'تفاصيل التقرير غير متاحة الآن';
  static const totalsOnly =
      'تتوفر قيمة الفواتير والتحصيل فقط. أعد التحديث لاحقًا لعرض المتبقي والتوزيعات';
  static const cached = 'تقرير محفوظ على الجهاز';
  static const failed = 'تعذر تحديث التقرير';
  static const loadFailed = 'تعذر تحميل التقرير';
  static String invoices(String count) => '$count فاتورة';
  static String unpaid(String count) => '$count غير مسددة';
  static String lastUpdate(String date) => 'آخر تحديث $date';
}
