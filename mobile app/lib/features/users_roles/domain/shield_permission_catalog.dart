import '../../../core/l10n/laravel_screen_strings.dart';
import 'entities/rbac_entities.dart';

ShieldPermissionCatalog standardShieldPermissionCatalog() {
  const resources = <String, String>{
    'Invoice': LaravelScreenStrings.invoices,
    'Payment': LaravelScreenStrings.payments,
    'Reading': LaravelScreenStrings.readings,
    'Subscriber': LaravelScreenStrings.subscribers,
    'Meter': LaravelScreenStrings.meters,
    'Square': LaravelScreenStrings.squares,
    'SubscriptionFee': LaravelScreenStrings.subscriptionFees,
    'ServiceRequest': LaravelScreenStrings.serviceRequests,
    'Tariff': LaravelScreenStrings.tariffs,
    'Transaction': LaravelScreenStrings.transactions,
    'User': LaravelScreenStrings.users,
    'Role': 'الأدوار',
    'Setting': LaravelScreenStrings.settings,
  };
  const actions = <String, String>{
    'ViewAny': 'عرض الكل',
    'View': 'عرض',
    'Create': 'إنشاء',
    'Update': 'تعديل',
    'Delete': 'حذف',
    'DeleteAny': 'حذف جماعي',
    'Restore': 'استعادة',
    'RestoreAny': 'استعادة جماعية',
    'ForceDelete': 'حذف نهائي',
    'ForceDeleteAny': 'حذف نهائي جماعي',
    'Replicate': 'تكرار',
    'Reorder': 'إعادة ترتيب',
  };
  const pages = <String, String>{
    'ExportDataPage': LaravelScreenStrings.exports,
    'InvoiceSettings': LaravelScreenStrings.invoiceSettings,
    'ReadingSettings': LaravelScreenStrings.readingSettings,
    'ReceiptSettings': LaravelScreenStrings.receiptSettings,
    'WhatsAppSettings': LaravelScreenStrings.whatsappNavigation,
    'EditProfilePage': LaravelScreenStrings.profileNavigation,
  };
  const widgets = <String, String>{
    'StatsOverview': 'البطاقات الإحصائية',
    'MonthlyRevenueChart': 'مخطط الإيرادات',
    'LatestInvoicesWidget': 'أحدث الفواتير',
    'OverdueInvoicesWidget': 'الفواتير المتأخرة',
    'CustomAccountWidget': 'بطاقة الحساب',
  };
  return ShieldPermissionCatalog(
    groups: [
      for (final resource in resources.entries)
        ShieldPermissionGroup(
          key: resource.key,
          label: resource.value,
          kind: ShieldPermissionKind.resource,
          permissions: [
            for (final action in actions.entries)
              ShieldPermission(
                name: '${action.key}:${resource.key}',
                label: action.value,
              ),
          ],
        ),
      for (final page in pages.entries)
        ShieldPermissionGroup(
          key: page.key,
          label: page.value,
          kind: ShieldPermissionKind.page,
          permissions: [ShieldPermission(name: 'View:${page.key}', label: 'عرض الصفحة')],
        ),
      for (final widget in widgets.entries)
        ShieldPermissionGroup(
          key: widget.key,
          label: widget.value,
          kind: ShieldPermissionKind.widget,
          permissions: [ShieldPermission(name: 'View:${widget.key}', label: 'عرض الودجة')],
        ),
    ],
  );
}
