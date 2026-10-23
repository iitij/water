import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/session_service.dart';
import '../../../core/permissions/permission_service.dart';
import '../../../core/responsive/breakpoints.dart';
import '../../../core/router/route_names.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/widgets/stat_card.dart';
import '../../../core/widgets/status_badge.dart';
import '../../offline_sync/services/sync_queue_manager.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = GetIt.I<SessionService>();
    final permissions = GetIt.I<PermissionService>();
    final queue = GetIt.I<SyncQueueManager>();
    final name = session.user?['name']?.toString() ?? 'موظف المشروع';
    final roles = permissions.currentRoles;
    final isAdmin = permissions.isAdmin;
    final isCollector = permissions.isCollector;
    final isReader = permissions.isReader;

    final roleTitle = isAdmin
        ? 'إدارة النظام الشاملة'
        : isCollector
            ? 'التحصيل الميداني والمحاسبة'
            : isReader
                ? 'القراءات والعمليات الميدانية'
                : 'مساحة العمل الموحدة';

    return AnimatedBuilder(
      animation: queue,
      builder: (context, _) {
        final width = MediaQuery.sizeOf(context).width;
        final horizontal = width < 360 ? 14.0 : 22.0;

        final canUseFieldReader = isAdmin ||
            permissions.hasAnyPermission(
              const <String>['Create:Reading', 'Update:Reading'],
            );
        final canUseQuickCollector = isAdmin ||
            permissions.hasAnyPermission(
              const <String>['Create:Payment', 'payments.collect'],
            );
        final canOpenDashboard = isAdmin ||
            permissions.hasAnyPermission(const <String>[
              'View:StatsOverview',
              'View:MonthlyRevenueChart',
              'View:LatestInvoicesWidget',
              'View:OverdueInvoicesWidget',
            ]);

        final fieldActions = <_ModuleEntry>[
          if (canUseFieldReader)
            _ModuleEntry(
              icon: Icons.speed_rounded,
              color: AppColors.primary,
              title: 'القارئ الميداني السريع',
              subtitle: 'بحث المسار المركب 4/1 ومسح الباركود وتسجيل القراءات',
              route: RouteNames.fieldReader,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Meter', 'View:Meter'],
              ))
            _ModuleEntry(
              icon: Icons.gas_meter_outlined,
              color: AppColors.info,
              title: 'إدارة العدادات',
              subtitle: 'قائمة العدادات، المسارات، المستأجرين، والأرصدة المعزولة',
              route: RouteNames.meters,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Reading', 'View:Reading'],
              ))
            _ModuleEntry(
              icon: Icons.fact_check_outlined,
              color: AppColors.success,
              title: 'سجل القراءات الشامل',
              subtitle: 'فلترة القراءات والإصدار الجماعي للفواتير',
              route: RouteNames.readings,
            ),
        ];

        final financeActions = <_ModuleEntry>[
          if (canUseQuickCollector)
            _ModuleEntry(
              icon: Icons.point_of_sale_rounded,
              color: AppColors.warning,
              title: 'المحصل الميداني السريع',
              subtitle: 'تحصيل فوري للفواتير غير المسددة وطباعة السندات',
              route: RouteNames.quickCollector,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Invoice', 'View:Invoice', 'invoices.view'],
              ))
            _ModuleEntry(
              icon: Icons.receipt_long_rounded,
              color: AppColors.primary,
              title: 'قائمة الفواتير ومحركات الإصدار',
              subtitle: 'فواتير العدادات والاشتراكات والوايت وإعادة الاحتساب',
              route: RouteNames.invoices,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Payment', 'View:Payment', 'payments.view'],
              ))
            _ModuleEntry(
              icon: Icons.payments_outlined,
              color: AppColors.success,
              title: 'سندات القبض والمدفوعات',
              subtitle: 'توزيع FIFO التلقائي، الطباعة الحرارية وPDF، والواتساب',
              route: RouteNames.payments,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Tariff', 'View:Tariff', 'tariffs.view'],
              ))
            _ModuleEntry(
              icon: Icons.price_change_outlined,
              color: AppColors.info,
              title: 'شرائح التعرفة والتسعير',
              subtitle: 'إدارة سعر الوحدة والتسعيرات النشطة والمحذوفة',
              route: RouteNames.tariffs,
            ),
        ];

        final crmActions = <_ModuleEntry>[
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Subscriber', 'View:Subscriber'],
              ))
            _ModuleEntry(
              icon: Icons.people_alt_outlined,
              color: AppColors.primary,
              title: 'المشتركون والملف الشامل 360°',
              subtitle: 'إدارة المشتركين، كشف الحساب، والتبويبات الـ 6',
              route: RouteNames.subscribers,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Square', 'View:Square'],
              ))
            _ModuleEntry(
              icon: Icons.grid_view_rounded,
              color: AppColors.info,
              title: 'المربعات السكنية',
              subtitle: 'إدارة الحارات والمربعات ومشتركي كل مربع',
              route: RouteNames.squares,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:SubscriptionFee', 'View:SubscriptionFee'],
              ))
            _ModuleEntry(
              icon: Icons.card_membership_outlined,
              color: AppColors.warning,
              title: 'رسوم الاشتراك والأقساط',
              subtitle: 'متابعة رسوم التأسيس وإصدار فواتير الأقساط',
              route: RouteNames.subscriptionFees,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:ServiceRequest', 'View:ServiceRequest'],
              ))
            _ModuleEntry(
              icon: Icons.local_shipping_outlined,
              color: AppColors.success,
              title: 'طلبات الخدمة والوايت',
              subtitle: 'تسجيل طلبات الوايت، الإنجاز، والفوترة المباشرة',
              route: RouteNames.serviceRequests,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['ViewAny:Transaction', 'View:Transaction'],
              ))
            _ModuleEntry(
              icon: Icons.account_balance_outlined,
              color: AppColors.primary,
              title: 'دفتر الأستاذ العام',
              subtitle: 'سجل الحركات المالية الدائنة والمدينة المفصل',
              route: RouteNames.transactions,
            ),
        ];

        final adminActions = <_ModuleEntry>[
          if (canOpenDashboard)
            _ModuleEntry(
              icon: Icons.dashboard_customize_outlined,
              color: AppColors.primary,
              title: 'لوحة القيادة الإحصائية',
              subtitle: 'مؤشرات الأداء، إيرادات الأشهر، والفواتير المتأخرة',
              route: RouteNames.dashboard,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(
                const <String>['View:StatsOverview', 'View:ExportDataPage'],
              ))
            _ModuleEntry(
              icon: Icons.analytics_outlined,
              color: AppColors.info,
              title: 'التقارير المالية والتحليلية',
              subtitle: 'تقارير التحصيل، المديونية، وتوزيع المربعات',
              route: RouteNames.reports,
            ),
          if (isAdmin || permissions.can('View:ExportDataPage'))
            _ModuleEntry(
              icon: Icons.table_view_outlined,
              color: AppColors.success,
              title: 'مركز تصدير بيانات Excel',
              subtitle: 'تصدير المشتركين والفواتير والقراءات بصيغة XLSX',
              route: RouteNames.exports,
            ),
          if (isAdmin ||
              permissions.hasAnyPermission(const <String>[
                'View:InvoiceSettings',
                'View:ReadingSettings',
                'View:ReceiptSettings',
                'View:WhatsAppSettings',
                'ViewAny:Setting',
              ]))
            _ModuleEntry(
              icon: Icons.tune_rounded,
              color: AppColors.warning,
              title: 'استوديو الإعدادات وخادم الواتساب',
              subtitle: 'مصمم الفاتورة والسند، إعدادات القراءة، وربط QR للواتساب',
              route: RouteNames.settings,
            ),
          if (isAdmin || permissions.can('ViewAny:User'))
            _ModuleEntry(
              icon: Icons.manage_accounts_outlined,
              color: AppColors.primary,
              title: 'إدارة المستخدمين',
              subtitle: 'حسابات الموظفين والمحصلين والقراء',
              route: RouteNames.users,
            ),
          if (isAdmin || permissions.can('ViewAny:Role'))
            _ModuleEntry(
              icon: Icons.admin_panel_settings_outlined,
              color: AppColors.info,
              title: 'الأدوار ومصفوفة صلاحيات Shield',
              subtitle: 'التحكم الدقيق بـ 167 صلاحية للأدوار',
              route: RouteNames.roles,
            ),
        ];

        final systemActions = <_ModuleEntry>[
          _ModuleEntry(
            icon: Icons.sync_rounded,
            color: AppColors.info,
            title: 'مراقب المزامنة الأوفلاين',
            subtitle: queue.pendingCount == 0
                ? 'جميع العمليات متزامنة مع الخادم'
                : 'يوجد ${queue.pendingCount} عملية بانتظار المزامنة',
            route: RouteNames.offlineSync,
          ),
          _ModuleEntry(
            icon: Icons.person_outline_rounded,
            color: AppColors.primary,
            title: 'الملف الشخصي',
            subtitle: 'تحديث الاسم، البريد، كلمة المرور، والصورة الشخصية',
            route: RouteNames.profile,
          ),
        ];

        return SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(horizontal, 18, horizontal, 32),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: AppBreakpoints.contentMax),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _WelcomeCard(
                    name: name,
                    roleTitle: roleTitle,
                    online: queue.isOnline,
                    onPrimaryAction: () {
                      if ((isReader && !isAdmin) || (!canOpenDashboard && canUseFieldReader)) {
                        context.push<Object?>(RouteNames.fieldReader);
                      } else if ((isCollector && !isAdmin) || (!canOpenDashboard && canUseQuickCollector)) {
                        context.push<Object?>(RouteNames.quickCollector);
                      } else if (canOpenDashboard) {
                        context.push<Object?>(RouteNames.dashboard);
                      } else {
                        context.go(RouteNames.offlineSync);
                      }
                    },
                    primaryActionLabel: (isReader && !isAdmin) || (!canOpenDashboard && canUseFieldReader)
                        ? 'فتح القارئ الميداني'
                        : (isCollector && !isAdmin) || (!canOpenDashboard && canUseQuickCollector)
                            ? 'فتح المحصل السريع'
                            : canOpenDashboard
                                ? 'فتح لوحة القيادة'
                                : 'فتح مراقب المزامنة',
                  ),
                  const SizedBox(height: 18),
                  LayoutBuilder(
                    builder: (context, constraints) {
                      final columns = constraints.maxWidth >= 520 ? 3 : 1;
                      final cards = <Widget>[
                        StatCard(
                          title: 'عمليات تنتظر المزامنة',
                          value: queue.pendingCount.toString(),
                          icon: Icons.cloud_upload_outlined,
                          color: AppColors.warning,
                          caption: queue.failedCount > 0 ? '${queue.failedCount} فاشلة' : 'جاهز للمزامنة',
                        ),
                        StatCard(
                          title: 'صلاحيات حسابك',
                          value: isAdmin ? 'كاملة' : permissions.currentPermissions.length.toString(),
                          icon: Icons.verified_user_outlined,
                          color: AppColors.success,
                          caption: roles.isEmpty ? 'مفعّلة' : roles.join(' • '),
                        ),
                        StatCard(
                          title: 'اتصال الخادم',
                          value: queue.isOnline == true
                              ? 'متصل'
                              : (queue.isOnline == false ? 'أوفلاين' : 'جارٍ الفحص'),
                          icon: queue.isOnline == true ? Icons.wifi_rounded : Icons.wifi_off_rounded,
                          color: queue.isOnline == true ? AppColors.info : AppColors.warning,
                          caption: 'حالة الشبكة',
                        ),
                      ];
                      if (columns == 1) {
                        return Column(
                          children: cards
                              .map((card) => Padding(
                                    padding: const EdgeInsets.only(bottom: 10),
                                    child: SizedBox(width: double.infinity, child: card),
                                  ))
                              .toList(),
                        );
                      }
                      return Row(
                        children: cards
                            .asMap()
                            .entries
                            .map((entry) => Expanded(
                                  child: Padding(
                                    padding: EdgeInsetsDirectional.only(
                                      end: entry.key == cards.length - 1 ? 0 : 10,
                                    ),
                                    child: entry.value,
                                  ),
                                ))
                            .toList(),
                      );
                    },
                  ),
                  if (fieldActions.isNotEmpty) ...<Widget>[
                    const SizedBox(height: 22),
                    _SectionBlock(
                      title: 'العمليات الميدانية والعدادات والقراءات',
                      entries: fieldActions,
                    ),
                  ],
                  if (financeActions.isNotEmpty) ...<Widget>[
                    const SizedBox(height: 18),
                    _SectionBlock(
                      title: 'الفواتير والتحصيل والنظام المالي',
                      entries: financeActions,
                    ),
                  ],
                  if (crmActions.isNotEmpty) ...<Widget>[
                    const SizedBox(height: 18),
                    _SectionBlock(
                      title: 'المشتركون والمربعات والخدمات المساندة',
                      entries: crmActions,
                    ),
                  ],
                  if (adminActions.isNotEmpty) ...<Widget>[
                    const SizedBox(height: 18),
                    _SectionBlock(
                      title: 'لوحة القيادة، التقارير، الإعدادات، والصلاحيات',
                      entries: adminActions,
                    ),
                  ],
                  const SizedBox(height: 18),
                  _SectionBlock(
                    title: 'المزامنة والحساب الشخصي',
                    entries: systemActions,
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _ModuleEntry {
  const _ModuleEntry({
    required this.icon,
    required this.color,
    required this.title,
    required this.subtitle,
    required this.route,
  });

  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final String route;
}

class _SectionBlock extends StatelessWidget {
  const _SectionBlock({required this.title, required this.entries});

  final String title;
  final List<_ModuleEntry> entries;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text(title, style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 10),
        LayoutBuilder(
          builder: (context, constraints) {
            final isWide = constraints.maxWidth >= 680;
            if (!isWide) {
              return Column(
                children: entries
                    .map(
                      (entry) => Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: _ActionCard(
                          icon: entry.icon,
                          color: entry.color,
                          title: entry.title,
                          description: entry.subtitle,
                          onTap: () => context.push<Object?>(entry.route),
                        ),
                      ),
                    )
                    .toList(),
              );
            }
            final rows = <Widget>[];
            for (var i = 0; i < entries.length; i += 2) {
              final first = entries[i];
              final second = i + 1 < entries.length ? entries[i + 1] : null;
              rows.add(
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Row(
                    children: <Widget>[
                      Expanded(
                        child: _ActionCard(
                          icon: first.icon,
                          color: first.color,
                          title: first.title,
                          description: first.subtitle,
                          onTap: () => context.push<Object?>(first.route),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: second == null
                            ? const SizedBox.shrink()
                            : _ActionCard(
                                icon: second.icon,
                                color: second.color,
                                title: second.title,
                                description: second.subtitle,
                                onTap: () => context.push<Object?>(second.route),
                              ),
                      ),
                    ],
                  ),
                ),
              );
            }
            return Column(children: rows);
          },
        ),
      ],
    );
  }
}

class _WelcomeCard extends StatelessWidget {
  const _WelcomeCard({
    required this.name,
    required this.roleTitle,
    required this.online,
    required this.onPrimaryAction,
    required this.primaryActionLabel,
  });

  final String name;
  final String roleTitle;
  final bool? online;
  final VoidCallback onPrimaryAction;
  final String primaryActionLabel;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        gradient: const LinearGradient(
          begin: AlignmentDirectional.topStart,
          end: AlignmentDirectional.bottomEnd,
          colors: <Color>[Color(0xFF0B2B40), Color(0xFF02687E), Color(0xFF0EA5E9)],
        ),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: AppColors.primary.withValues(alpha: .18),
            blurRadius: 26,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  'أهلاً بك، $name',
                  style: theme.textTheme.headlineMedium?.copyWith(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 6),
                Text(
                  roleTitle,
                  style: theme.textTheme.titleMedium?.copyWith(
                    color: Colors.white.withValues(alpha: .78),
                  ),
                ),
                const SizedBox(height: 14),
                Wrap(
                  spacing: 10,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: <Widget>[
                    StatusBadge(
                      status: online == true
                          ? 'connected'
                          : (online == false ? 'offline' : 'waiting'),
                    ),
                    FilledButton.tonalIcon(
                      onPressed: onPrimaryAction,
                      icon: const Icon(Icons.bolt_rounded, size: 18),
                      label: Text(primaryActionLabel),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: .14),
              borderRadius: BorderRadius.circular(22),
              border: Border.all(color: Colors.white.withValues(alpha: .2)),
            ),
            child: const Icon(Icons.water_drop_rounded, color: Colors.white, size: 34),
          ),
        ],
      ),
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({
    required this.icon,
    required this.color,
    required this.title,
    required this.description,
    required this.onTap,
  });

  final IconData icon;
  final Color color;
  final String title;
  final String description;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surface,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.all(17),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: theme.dividerColor.withValues(alpha: .7)),
          ),
          child: Row(
            children: <Widget>[
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: .1),
                  borderRadius: BorderRadius.circular(15),
                ),
                child: Icon(icon, color: color),
              ),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(title, style: theme.textTheme.titleMedium),
                    const SizedBox(height: 3),
                    Text(
                      description,
                      style: theme.textTheme.bodySmall,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_left_rounded),
            ],
          ),
        ),
      ),
    );
  }
}
