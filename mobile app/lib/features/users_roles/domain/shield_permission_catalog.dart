import 'entities/rbac_entities.dart';

ShieldPermissionCatalog standardShieldPermissionCatalog() {
  const resources = <String, String>{
    'Invoice': 'الفواتير',
    'Payment': 'المدفوعات',
    'Reading': 'القراءات',
    'Subscriber': 'المشتركون',
    'Meter': 'العدادات',
    'Square': 'المربعات',
    'SubscriptionFee': 'رسوم الاشتراك',
    'ServiceRequest': 'طلبات الوايت',
    'Tariff': 'التعرفة',
    'Transaction': 'دفتر الأستاذ',
    'User': 'المستخدمون',
    'Role': 'الأدوار',
    'Setting': 'الإعدادات',
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
    'ExportDataPage': 'مركز التصدير',
    'InvoiceSettings': 'إعدادات الفاتورة',
    'ReadingSettings': 'إعدادات القراءات',
    'ReceiptSettings': 'إعدادات سند القبض',
    'WhatsAppSettings': 'إعدادات الواتساب',
    'EditProfilePage': 'الملف الشخصي',
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
