import 'package:flutter/material.dart';

import '../../../core/l10n/app_strings.dart';
import '../../../core/router/route_names.dart';

enum WorkspaceGroup {
  customers('المشتركون والخدمات'),
  field('العدادات والقراءات'),
  finance('الفواتير والتحصيل'),
  management('التقارير والإدارة'),
  settings('الإعدادات'),
  account('حسابي');

  const WorkspaceGroup(this.label);
  final String label;
}

/// One navigation inventory for home shortcuts and the full service directory.
/// Visibility is presentation only; Laravel still authorizes every request.
class WorkspaceEntry {
  const WorkspaceEntry({
    required this.title,
    required this.description,
    required this.icon,
    required this.route,
    required this.group,
    this.permissions = const [],
    this.anyPermissions = const [],
    this.keywords = '',
    this.shortcut = false,
  });

  final String title;
  final String description;
  final IconData icon;
  final String route;
  final WorkspaceGroup group;
  final List<String> permissions;
  final List<String> anyPermissions;
  final String keywords;
  final bool shortcut;

  bool isVisible(bool Function(String) can) =>
      permissions.every(can) && (anyPermissions.isEmpty || anyPermissions.any(can));

  bool matches(String query) {
    final text = normalizeWorkspaceSearch('$title $description $keywords');
    return normalizeWorkspaceSearch(query)
        .split(RegExp(r'\s+'))
        .where((word) => word.isNotEmpty)
        .every(text.contains);
  }
}

String normalizeWorkspaceSearch(String value) => value
    .toLowerCase()
    .replaceAll(RegExp('[أإآٱ]'), 'ا')
    .replaceAll('ى', 'ي')
    .replaceAll('ة', 'ه')
    .replaceAll(RegExp('[\u064B-\u065F\u0670\u0640]'), '')
    .trim();

abstract final class WorkspaceCatalog {
  static const entries = <WorkspaceEntry>[
    WorkspaceEntry(
      title: AppStringsParity.cashReviewTitle, description: AppStringsParity.cashReviewSubtitle,
      icon: Icons.fact_check_outlined, route: RouteNames.cashSessionReview,
      group: WorkspaceGroup.management,
      anyPermissions: ['cash_sessions.approve', 'Approve:CashSession', 'ViewAny:CashSession'],
      keywords: 'اعتماد رفض صندوق محصل cash session review',
    ),
    WorkspaceEntry(
      title: AppStringsParity.auditTitle, description: AppStringsParity.auditSubtitle,
      icon: Icons.policy_outlined, route: RouteNames.auditEvents,
      group: WorkspaceGroup.management, anyPermissions: ['audit.view', 'ViewAny:AuditEvent'],
      keywords: 'تدقيق سجل احداث audit events',
    ),
    WorkspaceEntry(
      title: 'تسجيل قراءة', description: 'ابحث عن العداد وسجّل القراءة',
      icon: Icons.speed_rounded, route: RouteNames.fieldReader,
      group: WorkspaceGroup.field, permissions: ['Create:Reading', 'ViewAny:Meter', 'ViewAny:Subscriber'],
      keywords: 'قارئ ميداني باركود reading', shortcut: true,
    ),
    WorkspaceEntry(
      title: 'صندوق المحصّل', description: 'افتح الصندوق وأقفل العدّ اليومي',
      icon: Icons.account_balance_wallet_outlined, route: RouteNames.cashSession,
      group: WorkspaceGroup.finance, permissions: ['Create:Payment'],
      keywords: 'صندوق عهدة نقد محصل cash session', shortcut: true,
    ),
    WorkspaceEntry(
      title: 'تحصيل دفعة', description: 'اختر الفاتورة وسجّل المبلغ',
      icon: Icons.point_of_sale_rounded, route: RouteNames.quickCollector,
      group: WorkspaceGroup.finance, permissions: ['ViewAny:Invoice', 'Create:Payment'],
      keywords: 'محصل سداد دفع قبض collect payment', shortcut: true,
    ),
    WorkspaceEntry(
      title: 'مشترك جديد', description: 'أضف بيانات المشترك',
      icon: Icons.person_add_alt_1_rounded, route: RouteNames.subscriberNew,
      group: WorkspaceGroup.customers, permissions: ['Create:Subscriber'],
      keywords: 'إضافة subscriber', shortcut: true,
    ),
    WorkspaceEntry(
      title: 'إصدار فاتورة', description: 'عداد أو اشتراك أو وايت',
      icon: Icons.add_card_rounded, route: RouteNames.invoiceIssue,
      group: WorkspaceGroup.finance, permissions: ['Create:Invoice'],
      keywords: 'إنشاء فاتورة يدوية invoice', shortcut: true,
    ),
    WorkspaceEntry(
      title: 'المشتركون', description: 'البيانات وكشف الحساب والخدمات',
      icon: Icons.people_alt_outlined, route: RouteNames.subscribers,
      group: WorkspaceGroup.customers, permissions: ['ViewAny:Subscriber'],
      keywords: 'رصيد هاتف مشترك subscriber',
    ),
    WorkspaceEntry(
      title: 'المربعات', description: 'المناطق والمشتركون في كل مربع',
      icon: Icons.grid_view_rounded, route: RouteNames.squares,
      group: WorkspaceGroup.customers, permissions: ['ViewAny:Square'],
      keywords: 'حارات مناطق square',
    ),
    WorkspaceEntry(
      title: 'رسوم الاشتراك', description: 'الاشتراكات والأقساط وفواتيرها',
      icon: Icons.card_membership_outlined, route: RouteNames.subscriptionFees,
      group: WorkspaceGroup.customers, permissions: ['ViewAny:SubscriptionFee'],
      keywords: 'تأسيس رسوم subscription',
    ),
    WorkspaceEntry(
      title: 'طلبات الخدمة والوايت', description: 'الطلبات والإنجاز والفوترة',
      icon: Icons.local_shipping_outlined, route: RouteNames.serviceRequests,
      group: WorkspaceGroup.customers, permissions: ['ViewAny:ServiceRequest'],
      keywords: 'صهريج ناقلة مياه tanker',
    ),
    WorkspaceEntry(
      title: 'العدادات', description: 'العدادات والمسارات والمستأجرون',
      icon: Icons.gas_meter_outlined, route: RouteNames.meters,
      group: WorkspaceGroup.field, permissions: ['ViewAny:Meter'],
      keywords: 'عداد مسار meter',
    ),
    WorkspaceEntry(
      title: 'سجل القراءات', description: 'مراجعة القراءات وإصدار فواتيرها',
      icon: Icons.fact_check_outlined, route: RouteNames.readings,
      group: WorkspaceGroup.field, permissions: ['ViewAny:Reading'],
      keywords: 'استهلاك قراءة reading',
    ),
    WorkspaceEntry(
      title: 'الفواتير', description: 'الفواتير والأرصدة والطباعة',
      icon: Icons.receipt_long_outlined, route: RouteNames.invoices,
      group: WorkspaceGroup.finance, permissions: ['ViewAny:Invoice'],
      keywords: 'فاتورة مديونية متأخرات invoice',
    ),
    WorkspaceEntry(
      title: 'سندات القبض', description: 'المدفوعات والإيصالات والمشاركة',
      icon: Icons.payments_outlined, route: RouteNames.payments,
      group: WorkspaceGroup.finance, permissions: ['ViewAny:Payment'],
      keywords: 'سند قبض receipt payment',
    ),
    WorkspaceEntry(
      title: 'التعرفة', description: 'أسعار المياه والشرائح',
      icon: Icons.price_change_outlined, route: RouteNames.tariffs,
      group: WorkspaceGroup.finance, permissions: ['ViewAny:Tariff'],
      keywords: 'تسعير شريحة tariff',
    ),
    WorkspaceEntry(
      title: 'الحركات المالية', description: 'دفتر الأستاذ والحركات الدائنة والمدينة',
      icon: Icons.account_balance_outlined, route: RouteNames.transactions,
      group: WorkspaceGroup.finance, permissions: ['ViewAny:Transaction'],
      keywords: 'دفتر استاذ ledger transaction',
    ),
    WorkspaceEntry(
      title: 'لوحة المؤشرات', description: 'ملخص المشروع والفواتير المتأخرة',
      icon: Icons.dashboard_outlined, route: RouteNames.dashboard,
      group: WorkspaceGroup.management, permissions: ['ViewAny:Invoice'],
      keywords: 'احصائيات إيرادات dashboard',
    ),
    WorkspaceEntry(
      title: 'التقارير', description: 'التحصيل والفوترة حسب الفترة',
      icon: Icons.analytics_outlined, route: RouteNames.reports,
      group: WorkspaceGroup.management, permissions: ['ViewAny:Invoice'],
      keywords: 'تقرير مالي report',
    ),
    WorkspaceEntry(
      title: 'تصدير Excel', description: 'تنزيل بيانات المشتركين والفواتير والقراءات',
      icon: Icons.table_view_outlined, route: RouteNames.exports,
      group: WorkspaceGroup.management, permissions: ['View:ExportDataPage'],
      keywords: 'اكسل ملفات export xlsx',
    ),
    WorkspaceEntry(
      title: 'المستخدمون', description: 'حسابات الموظفين',
      icon: Icons.manage_accounts_outlined, route: RouteNames.users,
      group: WorkspaceGroup.management, permissions: ['ViewAny:User'],
      keywords: 'مستخدم موظف users',
    ),
    WorkspaceEntry(
      title: 'الأدوار والصلاحيات', description: 'صلاحيات الوصول والعمل',
      icon: Icons.admin_panel_settings_outlined, route: RouteNames.roles,
      group: WorkspaceGroup.management, permissions: ['ViewAny:Role'],
      keywords: 'دور صلاحية roles permissions shield',
    ),
    WorkspaceEntry(
      title: 'إعدادات القراءات', description: 'فترات القراءة وطريقة احتسابها',
      icon: Icons.calendar_month_outlined, route: '${RouteNames.settings}?section=reading',
      group: WorkspaceGroup.settings, permissions: ['View:ReadingSettings'],
      keywords: 'قراءة قراءات reading settings',
    ),
    WorkspaceEntry(
      title: 'تصميم الفاتورة', description: 'الشعار والحقول وشكل الفاتورة',
      icon: Icons.receipt_outlined, route: '${RouteNames.settings}?section=invoice',
      group: WorkspaceGroup.settings, permissions: ['View:InvoiceSettings'],
      keywords: 'إعدادات طباعة invoice settings',
    ),
    WorkspaceEntry(
      title: 'تصميم سند القبض', description: 'الحقول وشكل الإيصال',
      icon: Icons.article_outlined, route: '${RouteNames.settings}?section=receipt',
      group: WorkspaceGroup.settings, permissions: ['View:ReceiptSettings'],
      keywords: 'إعدادات طباعة receipt settings',
    ),
    WorkspaceEntry(
      title: 'الواتساب', description: 'الاتصال وقوالب الرسائل',
      icon: Icons.chat_outlined, route: '${RouteNames.settings}?section=whatsapp',
      group: WorkspaceGroup.settings, permissions: ['View:WhatsAppSettings'],
      keywords: 'واتس اب ربط qr whatsapp',
    ),
    WorkspaceEntry(
      title: 'الإعدادات العامة', description: 'بيانات المشروع وإعدادات النظام',
      icon: Icons.tune_rounded, route: '${RouteNames.settings}?section=general',
      group: WorkspaceGroup.settings, permissions: ['ViewAny:Setting'],
      keywords: 'اعدادات setting',
    ),
    WorkspaceEntry(
      title: 'المزامنة', description: 'راجع العمليات المعلقة وحاول إرسالها',
      icon: Icons.sync_rounded, route: RouteNames.offlineSync,
      group: WorkspaceGroup.account, keywords: 'اوفلاين اتصال دون انترنت sync offline',
    ),
    WorkspaceEntry(
      title: 'الملف الشخصي', description: 'الاسم والصورة وكلمة المرور',
      icon: Icons.person_outline_rounded, route: RouteNames.profile,
      group: WorkspaceGroup.account, keywords: 'حسابي profile password',
    ),
  ];

  static List<WorkspaceEntry> visible(bool Function(String) can, {
    String query = '', WorkspaceGroup? group,
  }) => entries.where((entry) => entry.isVisible(can) &&
      (group == null || entry.group == group) && entry.matches(query)).toList();
}
