abstract final class InvoiceSummaryStrings {
  static const unpaid = 'غير مسددة';
  static const showUnpaid = 'عرض الفواتير غير المسددة';
  static const showOverdue = 'عرض الفواتير المتأخرة';

  static String unpaidCount(int count) => '$count فاتورة غير مسددة';
  static String overdueCount(int count) => '$count متأخرة';
}
