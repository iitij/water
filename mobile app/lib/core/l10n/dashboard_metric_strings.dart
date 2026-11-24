abstract final class DashboardMetricStrings {
  static const currencyShort = 'ر.ي';
  static const activeSubscribers = 'المشتركون النشطون';
  static const outstanding = 'الديون المستحقة';
  static const monthlyRevenue = 'تحصيل هذا الشهر';
  static const overdueInvoices = 'الفواتير المتأخرة';
  static const needsFollowUp = 'تحتاج متابعة';
  static const noOverdue = 'لا توجد متأخرات';
  static String totalSubscribers(String count) => 'من أصل $count مشترك';
  static String unpaidInvoices(String count) => '$count فاتورة غير مسددة';
  static String monthlyReadings(String count) => '$count قراءة هذا الشهر';
}
