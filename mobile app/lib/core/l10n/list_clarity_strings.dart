import 'laravel_screen_strings.dart';

/// Arabic copy for compact list toolbars and readable ledger metadata.
abstract final class ListClarityStrings {
  static const moreActions = 'إجراءات إضافية';
  static const multiSelect = 'تحديد متعدد';
  static const selectAll = 'تحديد الكل';
  static const deleteSelected = 'حذف المحدد';
  static const voidRequests = LaravelScreenStrings.voidRequests;
  static const exportToday = 'تصدير سندات اليوم (CSV)';
  static const ledgerSubtitle = 'القيود المالية للمشروع';
  static const unnamedSubscriber = 'مشترك غير محدد';
  static const generalAccount = 'الحساب العام';
  static String meter(int id) => 'عداد رقم: $id';
  static String reference(String type, int? id) {
    final model = type.split('\\').last.split('.').last.toLowerCase();
    final label = switch (model) {
      'invoice' => 'فاتورة',
      'payment' || 'receipt' || 'paymentreceipt' => 'سند قبض',
      _ => 'مرجع',
    };
    return id == null ? label : '$label #$id';
  }
}
