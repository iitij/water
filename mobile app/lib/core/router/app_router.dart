import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/pages/edit_profile_screen.dart';
import '../../features/auth/presentation/pages/login_screen.dart';
import '../../features/auth/presentation/pages/splash_screen.dart';
import '../../features/dashboard/dashboard.dart';
import '../../features/exports/exports.dart';
import '../../features/home/presentation/home_screen.dart';
import '../../features/home/presentation/services_screen.dart';
import '../../features/invoices/data/models/invoice_model.dart';
import '../../features/invoices/domain/accounting/finance_enums.dart';
import '../../features/meters/data/models/meter.dart';
import '../../features/meters/domain/meters_repository.dart';
import '../../features/meters/presentation/pages/meter_form_screen.dart';
import '../../features/meters/presentation/pages/meters_list_screen.dart';
import '../../features/offline_sync/presentation/offline_sync_monitor_screen.dart';
import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../../features/payments/presentation/sheets/collect_payment_sheet.dart';
import '../../features/readings/data/services/reading_invoice_actions_service.dart';
import '../../features/readings/domain/readings_repository.dart';
import '../../features/readings/presentation/pages/field_reader_screen.dart';
import '../../features/readings/presentation/pages/readings_list_screen.dart';
import '../../features/settings/settings.dart';
import '../../features/users_roles/users_roles.dart';
import '../auth/session_service.dart';
import '../finance/finance_locator.dart';
import '../finance/finance_module.dart';
import '../fouad/fouad_module.dart';
import '../permissions/permission_service.dart';
import '../responsive/adaptive_scaffold.dart';
import '../services/whatsapp_launcher_service.dart';
import '../theme/app_theme.dart';
import 'route_names.dart';

class AppRouter {
  AppRouter({required SessionService session}) : router = _createRouter(session);

  final GoRouter router;

  static GoRouter _createRouter(SessionService session) => GoRouter(
        initialLocation: RouteNames.splash,
        refreshListenable: session,
        redirect: (context, state) {
          final location = state.matchedLocation;
          final isPublic = location == RouteNames.splash || location == RouteNames.login;
          if (!session.isReady) {
            return location == RouteNames.splash ? null : RouteNames.splash;
          }
          if (!session.isAuthenticated) {
            return location == RouteNames.login ? null : RouteNames.login;
          }
          if (isPublic) return RouteNames.home;
          return null;
        },
        routes: <RouteBase>[
          GoRoute(
            path: RouteNames.splash,
            builder: (context, state) => const SplashScreen(),
          ),
          GoRoute(
            path: RouteNames.login,
            builder: (context, state) => const LoginScreen(),
          ),
          StatefulShellRoute.indexedStack(
            builder: (context, state, navigationShell) => AppHomeShell(shell: navigationShell),
            branches: <StatefulShellBranch>[
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.home,
                    builder: (context, state) => const HomeScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.services,
                    builder: (context, state) => ServicesScreen(
                      autofocus: state.uri.queryParameters['search'] == '1',
                    ),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.offlineSync,
                    builder: (context, state) => const OfflineSyncMonitorScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.profile,
                    builder: (context, state) => const EditProfileScreen(),
                  ),
                ],
              ),
            ],
          ),

          // ── Dev 2: Field Operations, Meters & Readings (SCR-08..SCR-12) ──
          GoRoute(
            path: RouteNames.fieldReader,
            name: 'field-reader',
            builder: (context, state) => FieldReaderScreen(
              metersRepository: GetIt.I<MetersRepository>(),
              readingsRepository: GetIt.I<ReadingsRepository>(),
              invoiceActions: GetIt.I<ReadingInvoiceActionsService>(),
            ),
          ),
          GoRoute(
            path: RouteNames.meters,
            name: 'meters',
            builder: (context, state) => MetersListScreen(
              metersRepository: GetIt.I<MetersRepository>(),
              readingsRepository: GetIt.I<ReadingsRepository>(),
              invoiceActions: GetIt.I<ReadingInvoiceActionsService>(),
              onOpenFieldReader: () => context.push<Object?>(RouteNames.fieldReader),
            ),
            routes: <RouteBase>[
              GoRoute(
                path: 'new',
                name: 'meter-new',
                builder: (context, state) => MeterFormScreen(
                  repository: GetIt.I<MetersRepository>(),
                  meter: state.extra is Meter ? state.extra as Meter : null,
                  initialSubscriberId: int.tryParse(
                    state.uri.queryParameters['subscriber_id'] ?? '',
                  ),
                ),
              ),
            ],
          ),
          GoRoute(
            path: RouteNames.readings,
            name: 'readings',
            builder: (context, state) => ReadingsListScreen(
              readingsRepository: GetIt.I<ReadingsRepository>(),
              metersRepository: GetIt.I<MetersRepository>(),
            ),
          ),

          // ── Dev 3: Finance, Invoices, Payments & Tariffs (SCR-13..18, 21) ──
          ...FinanceModule.routes,

          // ── Dev 4: Squares, Subscribers 360°, Fees, Tankers & Ledger (SCR-04..07, 19, 20, 22) ──
          ...FouadModule.routes,

          // ── Dev 5: Dashboard, Reports, Settings, RBAC & Exports (SCR-03, 23..27) ──
          ...DashboardRoutes.routes(
            dashboardBuilder: () => Builder(
              builder: (context) {
                final permissions = GetIt.I<PermissionService>();
                final queue = GetIt.I<SyncQueueManager>();
                final user = session.user;
                final roles = permissions.currentRoles;
                final roleLabel = roles.contains('super_admin') || roles.contains('admin')
                    ? 'مدير النظام'
                    : roles.contains('collector')
                        ? 'محصل ميداني'
                        : roles.contains('reader') || roles.contains('meter_reader')
                            ? 'قارئ عدادات'
                            : 'موظف';
                return DashboardScreen(
                  repository: GetIt.I<DashboardRepository>(),
                  userName: user?['name']?.toString() ?? 'مدير النظام',
                  roleLabel: roleLabel,
                  avatarUrl: user?['avatar']?.toString(),
                  isOnline: queue.isOnline ?? true,
                  pendingSyncCount: queue.pendingCount,
                  can: permissions.can,
                  onAddReading: () => context.push<Object?>(RouteNames.fieldReader),
                  onAddSubscriber: () => context.push<Object?>(RouteNames.subscriberNew),
                  onOpenReports: () => context.push<Object?>(RouteNames.reports),
                  onOpenInvoice: (invoice) => context.push<Object?>('${RouteNames.invoices}/${invoice.id}'),
                  onCollectPayment: (ctx, dashInvoice) async {
                    await CollectPaymentSheet.show(
                      ctx,
                      invoice: InvoiceModel(
                        id: dashInvoice.id,
                        subscriberId: dashInvoice.subscriberId ?? 0,
                        meterId: dashInvoice.meterId,
                        invoiceType: dashInvoice.meterId != null
                            ? InvoiceType.meter
                            : InvoiceType.subscription,
                        billNumber: dashInvoice.billNumber,
                        billDate: dashInvoice.billDate,
                        totalAmount: dashInvoice.totalAmount,
                        previousBalance: dashInvoice.previousBalance,
                        creditApplied: dashInvoice.creditApplied,
                        amountPaid: dashInvoice.amountPaid,
                        status: InvoiceStatus.parse(dashInvoice.status),
                        serverTrueRemaining: dashInvoice.trueRemainingAmount ?? dashInvoice.remainingAmount,
                        isLatestInvoice: true,
                        subscriber: InvoiceSubscriberRef(
                          id: dashInvoice.subscriberId ?? 0,
                          name: dashInvoice.subscriberName,
                          phone: dashInvoice.subscriberPhone,
                        ),
                      ),
                    );
                    return true;
                  },
                  onSendReminder: (ctx, dashInvoice) async {
                    final phone = dashInvoice.subscriberPhone;
                    if (phone == null || phone.trim().isEmpty) return;
                    final whatsapp = sl<WhatsAppLauncherService>();
                    await whatsapp.dispatch(
                      recipientPhone: phone,
                      message:
                          'تذكير بسداد فاتورة المياه رقم ${dashInvoice.billNumber} بمبلغ متبقٍ ${dashInvoice.remainingAmount.toStringAsFixed(0)} ريال.',
                      serverEndpoint: 'invoices/${dashInvoice.id}/send-whatsapp',
                      payload: <String, dynamic>{'invoice_id': dashInvoice.id},
                    );
                  },
                );
              },
            ),
            reportsBuilder: () => ReportsScreen(
              repository: GetIt.I<DashboardRepository>(),
            ),
          ),
          ...SettingsRoutes.routes(
            sectionBuilder: (section) => SettingsStudioScreen(
              initialSection: section,
              repository: GetIt.I<SettingsRepository>(),
              can: GetIt.I<PermissionService>().can,
            ),
          ),
          ...UsersRolesRoutes.routes(
            usersBuilder: () {
              final rawId = session.user?['id'];
              final currentUserId = rawId is int ? rawId : int.tryParse(rawId?.toString() ?? '');
              return UsersManagementScreen(
                repository: GetIt.I<RbacRepository>(),
                can: GetIt.I<PermissionService>().can,
                currentUserId: currentUserId,
              );
            },
            rolesBuilder: () => RolesAndPermissionsScreen(
              repository: GetIt.I<RbacRepository>(),
              can: GetIt.I<PermissionService>().can,
            ),
          ),
          ...ExportRoutes.routes(
            centerBuilder: () => ExportCenterScreen(
              service: GetIt.I<ExcelExportService>(),
              can: GetIt.I<PermissionService>().can,
            ),
          ),
        ],
      );
}

class AppHomeShell extends StatelessWidget {
  const AppHomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  static const _destinations = <AdaptiveDestination>[
    AdaptiveDestination(label: 'الرئيسية', icon: Icons.home_outlined, selectedIcon: Icons.home_rounded),
    AdaptiveDestination(label: 'الخدمات', icon: Icons.apps_outlined, selectedIcon: Icons.apps_rounded),
    AdaptiveDestination(label: 'المزامنة', icon: Icons.sync_outlined, selectedIcon: Icons.sync_rounded),
    AdaptiveDestination(label: 'حسابي', icon: Icons.person_outline_rounded, selectedIcon: Icons.person_rounded),
  ];

  @override
  Widget build(BuildContext context) {
    final titles = <String>['مشروع المياه', 'الخدمات', 'المزامنة', 'حسابي'];
    final currentIndex = shell.currentIndex.clamp(0, titles.length - 1).toInt();
    final session = GetIt.I<SessionService>();
    final permissions = GetIt.I<PermissionService>();

    return ListenableBuilder(
      listenable: session,
      builder: (context, _) {
        final canFieldRead = permissions.isAdmin ||
            permissions.hasAnyPermission(
              const <String>['Create:Reading', 'Update:Reading'],
            );
        final canQuickCollect = permissions.isAdmin ||
            permissions.hasAnyPermission(
              const <String>['Create:Payment', 'payments.collect'],
            );
        return AdaptiveScaffold(
          currentIndex: currentIndex,
          destinations: _destinations,
          onDestinationSelected: (index) => shell.goBranch(
            index,
            initialLocation: index == shell.currentIndex,
          ),
          appBar: AppBar(
            title: Text(titles[currentIndex]),
            actions: <Widget>[
              if (canFieldRead)
                IconButton(
                  tooltip: 'القارئ الميداني السريع',
                  onPressed: () => context.push<Object?>(RouteNames.fieldReader),
                  icon: const Icon(Icons.speed_rounded),
                ),
              if (canQuickCollect)
                IconButton(
                  tooltip: 'المحصل الميداني السريع',
                  onPressed: () => context.push<Object?>(RouteNames.quickCollector),
                  icon: const Icon(Icons.point_of_sale_rounded),
                ),
              IconButton(
                tooltip: 'تبديل المظهر',
                onPressed: () => GetIt.I<AppThemeController>().toggle(),
                icon: Icon(
                  Theme.of(context).brightness == Brightness.dark
                      ? Icons.light_mode_rounded
                      : Icons.dark_mode_rounded,
                ),
              ),
              const SizedBox(width: 6),
            ],
          ),
          body: shell,
        );
      },
    );
  }
}
