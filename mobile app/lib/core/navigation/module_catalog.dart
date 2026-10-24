import 'package:flutter/material.dart';

import '../permissions/permission_service.dart';
import '../router/route_names.dart';
import '../theme/app_colors.dart';

/// Logical grouping of the platform's modules. Each group powers one hub page
/// and its own section on the overview screen.
enum AppModuleGroup { field, billing, crm, insights, admin, system }

extension AppModuleGroupX on AppModuleGroup {
  String get label => switch (this) {
        AppModuleGroup.field => 'العمليات الميدانية',
        AppModuleGroup.billing => 'التحصيل والفواتير',
        AppModuleGroup.crm => 'المشتركون والخدمات',
        AppModuleGroup.insights => 'التحليلات والتقارير',
        AppModuleGroup.admin => 'الإدارة والإعدادات',
        AppModuleGroup.system => 'النظام والحساب',
      };

  String get description => switch (this) {
        AppModuleGroup.field => 'القراءات والعدادات والقارئ الميداني',
        AppModuleGroup.billing => 'سندات القبض والفواتير وشرائح التعرفة',
        AppModuleGroup.crm => 'ملفات المشتركين والمربعات وطلبات الخدمة',
        AppModuleGroup.insights => 'لوحة القيادة والتقارير والتصدير',
        AppModuleGroup.admin => 'المستخدمون والصلاحيات وإعدادات النظام',
        AppModuleGroup.system => 'المزامنة والملف الشخصي والمظهر',
      };

  IconData get icon => switch (this) {
        AppModuleGroup.field => Icons.sensors_rounded,
        AppModuleGroup.billing => Icons.account_balance_wallet_rounded,
        AppModuleGroup.crm => Icons.groups_2_rounded,
        AppModuleGroup.insights => Icons.insights_rounded,
        AppModuleGroup.admin => Icons.admin_panel_settings_rounded,
        AppModuleGroup.system => Icons.settings_suggest_rounded,
      };
}

/// A single navigable module. [requiredPermissions] is an "any of" list; an
/// empty list means the module is available to every signed-in user.
class AppModule {
  const AppModule({
    required this.id,
    required this.group,
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.color,
    required this.route,
    this.requiredPermissions = const <String>[],
    this.adminOnly = false,
  });

  final String id;
  final AppModuleGroup group;
  final String title;
  final String subtitle;
  final IconData icon;
  final Color color;
  final String route;
  final List<String> requiredPermissions;
  final bool adminOnly;

  bool isVisible(PermissionService permissions) {
    if (permissions.isAdmin) return true;
    if (adminOnly) return false;
    if (requiredPermissions.isEmpty) return true;
    return permissions.hasAnyPermission(requiredPermissions);
  }
}

/// The single source of truth for platform navigation.
abstract final class ModuleCatalog {
  const ModuleCatalog._();

  static const List<AppModule> modules = <AppModule>[
    // ── Field operations ───────────────────────────────────────────────────
    AppModule(
      id: 'field_reader',
      group: AppModuleGroup.field,
      title: 'القارئ الميداني',
      subtitle: 'بحث المسار، مسح الباركود وتسجيل القراءات دون اتصال',
      icon: Icons.speed_rounded,
      color: AppColors.primary,
      route: RouteNames.fieldReader,
    ),
    AppModule(
      id: 'meters',
      group: AppModuleGroup.field,
      title: 'إدارة العدادات',
      subtitle: 'العدادات والمسارات والأرصدة المعزولة',
      icon: Icons.water_damage_outlined,
      color: AppColors.info,
      route: RouteNames.meters,
      requiredPermissions: <String>['ViewAny:Meter', 'View:Meter'],
    ),
    AppModule(
      id: 'readings',
      group: AppModuleGroup.field,
      title: 'سجل القراءات',
      subtitle: 'فلترة القراءات والإصدار الجماعي للفواتير',
      icon: Icons.fact_check_outlined,
      color: AppColors.success,
      route: RouteNames.readings,
      requiredPermissions: <String>['ViewAny:Reading', 'View:Reading'],
    ),

    // ── Billing ────────────────────────────────────────────────────────────
    AppModule(
      id: 'quick_collector',
      group: AppModuleGroup.billing,
      title: 'المحصل السريع',
      subtitle: 'تحصيل فوري وطباعة السندات في الميدان',
      icon: Icons.point_of_sale_rounded,
      color: AppColors.warning,
      route: RouteNames.quickCollector,
    ),
    AppModule(
      id: 'cash_session',
      group: AppModuleGroup.billing,
      title: 'صندوق المحصّل',
      subtitle: 'افتح صندوقك، تابع السندات، وأقفل العدّ بنقد مطابق',
      icon: Icons.account_balance_wallet_outlined,
      color: AppColors.success,
      route: RouteNames.cashSession,
    ),
    AppModule(
      id: 'invoices',
      group: AppModuleGroup.billing,
      title: 'الفواتير',
      subtitle: 'فواتير العدادات والاشتراكات والوايت',
      icon: Icons.receipt_long_rounded,
      color: AppColors.primary,
      route: RouteNames.invoices,
      requiredPermissions: <String>['invoices.view'],
    ),
    AppModule(
      id: 'payments',
      group: AppModuleGroup.billing,
      title: 'سندات القبض',
      subtitle: 'توزيع الدفعات والطباعة والواتساب',
      icon: Icons.payments_outlined,
      color: AppColors.success,
      route: RouteNames.payments,
      requiredPermissions: <String>['payments.view'],
    ),
    AppModule(
      id: 'tariffs',
      group: AppModuleGroup.billing,
      title: 'التعرفة والتسعير',
      subtitle: 'سعر الوحدة والشرائح النشطة',
      icon: Icons.price_change_outlined,
      color: AppColors.info,
      route: RouteNames.tariffs,
      requiredPermissions: <String>['tariffs.view'],
    ),

    // ── CRM ────────────────────────────────────────────────────────────────
    AppModule(
      id: 'subscribers',
      group: AppModuleGroup.crm,
      title: 'المشتركون 360°',
      subtitle: 'الملف الشامل، كشف الحساب والتبويبات الستة',
      icon: Icons.people_alt_rounded,
      color: AppColors.primary,
      route: RouteNames.subscribers,
      requiredPermissions: <String>['ViewAny:Subscriber', 'View:Subscriber'],
    ),
    AppModule(
      id: 'squares',
      group: AppModuleGroup.crm,
      title: 'المربعات السكنية',
      subtitle: 'الحارات والمربعات ومشتركو كل مربع',
      icon: Icons.grid_view_rounded,
      color: AppColors.info,
      route: RouteNames.squares,
      requiredPermissions: <String>['ViewAny:Square', 'View:Square'],
    ),
    AppModule(
      id: 'fees',
      group: AppModuleGroup.crm,
      title: 'رسوم الاشتراك',
      subtitle: 'رسوم التأسيس وإصدار فواتير الأقساط',
      icon: Icons.card_membership_rounded,
      color: AppColors.warning,
      route: RouteNames.subscriptionFees,
    ),
    AppModule(
      id: 'service_requests',
      group: AppModuleGroup.crm,
      title: 'طلبات الخدمة',
      subtitle: 'طلبات الوايت والإنجاز والفوترة',
      icon: Icons.local_shipping_rounded,
      color: AppColors.success,
      route: RouteNames.serviceRequests,
    ),
    AppModule(
      id: 'transactions',
      group: AppModuleGroup.crm,
      title: 'دفتر الأستاذ',
      subtitle: 'الحركات الدائنة والمدينة المفصلة',
      icon: Icons.account_balance_outlined,
      color: AppColors.violet,
      route: RouteNames.transactions,
    ),

    // ── Insights ───────────────────────────────────────────────────────────
    AppModule(
      id: 'dashboard',
      group: AppModuleGroup.insights,
      title: 'لوحة القيادة',
      subtitle: 'مؤشرات الأداء والإيرادات والمتأخرات',
      icon: Icons.space_dashboard_rounded,
      color: AppColors.primary,
      route: RouteNames.dashboard,
      requiredPermissions: <String>[
        'View:CustomAccountWidget',
        'View:StatsOverview',
        'View:MonthlyRevenueChart',
        'View:LatestInvoicesWidget',
        'View:OverdueInvoicesWidget',
      ],
    ),
    AppModule(
      id: 'reports',
      group: AppModuleGroup.insights,
      title: 'التقارير المالية',
      subtitle: 'تقارير التحصيل والمديونية والمربعات',
      icon: Icons.analytics_rounded,
      color: AppColors.violet,
      route: RouteNames.reports,
      requiredPermissions: <String>['View:StatsOverview', 'View:MonthlyRevenueChart'],
    ),
    AppModule(
      id: 'exports',
      group: AppModuleGroup.insights,
      title: 'مركز التصدير',
      subtitle: 'تصدير المشتركين والفواتير والقراءات',
      icon: Icons.table_view_rounded,
      color: AppColors.success,
      route: RouteNames.exports,
      requiredPermissions: <String>['View:ExportDataPage'],
    ),

    // ── Administration ─────────────────────────────────────────────────────
    AppModule(
      id: 'settings',
      group: AppModuleGroup.admin,
      title: 'استوديو الإعدادات',
      subtitle: 'مصمم الفاتورة والسند وخادم الواتساب',
      icon: Icons.tune_rounded,
      color: AppColors.warning,
      route: RouteNames.settings,
      requiredPermissions: <String>[
        'View:InvoiceSettings',
        'View:ReadingSettings',
        'View:ReceiptSettings',
        'View:WhatsAppSettings',
        'ViewAny:Setting',
      ],
    ),
    AppModule(
      id: 'users',
      group: AppModuleGroup.admin,
      title: 'المستخدمون',
      subtitle: 'حسابات الموظفين والمحصلين والقراء',
      icon: Icons.manage_accounts_rounded,
      color: AppColors.info,
      route: RouteNames.users,
      requiredPermissions: <String>['ViewAny:User'],
    ),
    AppModule(
      id: 'data-quality',
      group: AppModuleGroup.admin,
      title: 'جودة البيانات',
      subtitle: 'هواتف ناقصة، عدادات بلا قراءة، وتعرفة غير سارية',
      icon: Icons.fact_check_outlined,
      color: AppColors.warning,
      route: RouteNames.dataQuality,
      requiredPermissions: <String>['ViewAny:User'],
    ),
    AppModule(
      id: 'devices',
      group: AppModuleGroup.admin,
      title: 'الأجهزة المسجّلة',
      subtitle: 'إبطال جهاز مفقود أو مسروق فوراً',
      icon: Icons.devices_rounded,
      color: AppColors.danger,
      route: RouteNames.devices,
      // بلا شرط صلاحية: كل موظف يحتاج إبطال جهازه المفقود، والخادم يقيّد
      // الإبطال بمالك الجهاز أو مشرف.
    ),
    AppModule(
      id: 'roles',
      group: AppModuleGroup.admin,
      title: 'الأدوار والصلاحيات',
      subtitle: 'مصفوفة الصلاحيات الدقيقة لكل دور',
      icon: Icons.policy_rounded,
      color: AppColors.violet,
      route: RouteNames.roles,
      requiredPermissions: <String>['ViewAny:Role'],
    ),

    // ── System ─────────────────────────────────────────────────────────────
    AppModule(
      id: 'sync',
      group: AppModuleGroup.system,
      title: 'مراقب المزامنة',
      subtitle: 'العمليات المحفوظة محلياً وحالة الاتصال',
      icon: Icons.sync_rounded,
      color: AppColors.info,
      route: RouteNames.offlineSync,
    ),
    AppModule(
      id: 'reminders',
      group: AppModuleGroup.system,
      title: 'تذكيرات الجهاز',
      subtitle: 'قراءات وفواتير وإقفال صندوق ونسخة احتياطية',
      icon: Icons.notifications_active_outlined,
      color: AppColors.warning,
      route: RouteNames.reminders,
    ),
    AppModule(
      id: 'backup',
      group: AppModuleGroup.system,
      title: 'نسخة احتياطية محلية',
      subtitle: 'احفظ عمل الميدان واستعده إن تعطّل الجهاز',
      icon: Icons.inventory_2_outlined,
      color: AppColors.success,
      route: RouteNames.backup,
    ),
    AppModule(
      id: 'profile',
      group: AppModuleGroup.system,
      title: 'الملف الشخصي',
      subtitle: 'الاسم والبريد وكلمة المرور والصورة',
      icon: Icons.person_rounded,
      color: AppColors.primary,
      route: RouteNames.profile,
    ),
  ];

  static List<AppModule> forGroup(AppModuleGroup group) =>
      modules.where((module) => module.group == group).toList(growable: false);

  /// Modules visible to [permissions], optionally limited to one group.
  static List<AppModule> visible(
    PermissionService permissions, {
    AppModuleGroup? group,
  }) {
    return modules
        .where((module) => group == null || module.group == group)
        .where((module) => module.isVisible(permissions))
        .toList(growable: false);
  }

  static AppModule? byId(String id) {
    for (final module in modules) {
      if (module.id == id) return module;
    }
    return null;
  }
}
