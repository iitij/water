import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../features/admin_shared/data/admin_api_client.dart';
import '../../features/audit/presentation/audit_events_screen.dart';
import '../../features/auth/presentation/pages/edit_profile_screen.dart';
import '../../features/auth/presentation/pages/login_screen.dart';
import '../../features/auth/presentation/pages/splash_screen.dart';
import '../../features/cash_sessions/presentation/cash_session_review_screen.dart';
import '../../features/cash_sessions/presentation/cash_session_screen.dart';
import '../../features/dashboard/dashboard.dart';
import '../../features/data_quality/data/data_quality_remote_data_source.dart';
import '../../features/data_quality/presentation/pages/data_quality_screen.dart';
import '../../features/devices/devices.dart';
import '../../features/diagnostics/presentation/api_logs_screen.dart';
import '../../features/diagnostics/presentation/diagnostics_screen.dart';
import '../../features/exports/exports.dart';
import '../../features/field_route/presentation/route_mode_screen.dart';
import '../../features/global_search/data/global_search_remote_datasource.dart';
import '../../features/global_search/data/global_search_repository.dart';
import '../../features/global_search/presentation/cubit/global_search_cubit.dart';
import '../../features/global_search/presentation/pages/global_search_screen.dart';
import '../../features/home/presentation/home_screen.dart';
import '../../features/home/presentation/services_screen.dart';
import '../../features/invoices/data/models/invoice_model.dart';
import '../../features/invoices/domain/accounting/finance_enums.dart';
import '../../features/meters/data/models/meter.dart';
import '../../features/meters/domain/meters_repository.dart';
import '../../features/meters/presentation/pages/meter_form_screen.dart';
import '../../features/meters/presentation/pages/meters_list_screen.dart';
import '../../features/navigation/presentation/pages/billing_hub_screen.dart';
import '../../features/navigation/presentation/pages/crm_hub_screen.dart';
import '../../features/navigation/presentation/pages/field_hub_screen.dart';
import '../../features/navigation/presentation/pages/more_screen.dart';
import '../../features/offline_sync/presentation/offline_sync_monitor_screen.dart';
import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../../features/payments/presentation/sheets/collect_payment_sheet.dart';
import '../../features/readings/data/services/reading_invoice_actions_service.dart';
import '../../features/readings/domain/readings_repository.dart';
import '../../features/readings/presentation/pages/field_reader_screen.dart';
import '../../features/readings/presentation/pages/readings_list_screen.dart';
import '../../features/security/presentation/app_lock_settings_screen.dart';
import '../../features/settings/settings.dart';
import '../auth/session_service.dart';
import '../backup/backup_screen.dart';
import '../backup/local_backup.dart';
import '../finance/finance_locator.dart';
import '../finance/finance_module.dart';
import '../fouad/api_client.dart';
import '../fouad/fouad_module.dart';
import '../navigation/app_shell.dart';
import '../navigation/sub_page.dart';
import '../notifications/notification_service.dart';
import '../notifications/notification_settings_screen.dart';
import '../permissions/permission_service.dart';
import '../services/whatsapp_launcher_service.dart';
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
                    path: RouteNames.fieldHub,
                    builder: (context, state) => const FieldHubScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.billingHub,
                    builder: (context, state) => const BillingHubScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.crmHub,
                    builder: (context, state) => const CrmHubScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: <RouteBase>[
                  GoRoute(
                    path: RouteNames.moreHub,
                    builder: (context, state) => const MoreScreen(),
                  ),
                ],
              ),
            ],
          ),
          GoRoute(
            path: RouteNames.services,
            builder: (context, state) => SubPageScaffold(
              title: 'كل الخدمات',
              subtitle: 'ابحث عن أي شاشة أو إجراء في المشروع',
              child: ServicesScreen(
                autofocus: state.uri.queryParameters['search'] == '1',
              ),
            ),
          ),
          GoRoute(
            path: RouteNames.globalSearch,
            name: 'global-search',
            builder: (context, state) => BlocProvider<GlobalSearchCubit>(
              create: (_) => GlobalSearchCubit(
                GlobalSearchRepository(GlobalSearchRemoteDatasource(ApiClient())),
              ),
              child: const GlobalSearchScreen(),
            ),
          ),
          GoRoute(
            path: RouteNames.offlineSync,
            builder: (context, state) => const SubPageScaffold(
              title: 'مراقب المزامنة',
              subtitle: 'العمليات المحفوظة محلياً وحالة الاتصال',
              child: OfflineSyncMonitorScreen(),
            ),
          ),
          GoRoute(
            path: RouteNames.cashSession,
            builder: (context, state) => const SubPageScaffold(
              title: 'صندوق المحصّل',
              subtitle: 'فتح الصندوق، متابعة السندات، وإقفال العدّ',
              child: CashSessionScreen(),
            ),
          ),
          GoRoute(
            path: RouteNames.cashSessionReview,
            builder: (context, state) => const CashSessionReviewScreen(),
          ),
          GoRoute(
            path: RouteNames.auditEvents,
            builder: (context, state) => const AuditEventsScreen(),
          ),
          GoRoute(
            path: RouteNames.appLock,
            builder: (context, state) => const SubPageScaffold(
              title: 'قفل التطبيق',
              subtitle: 'رقم حماية محلي ومهلة خمول',
              child: AppLockSettingsScreen(),
            ),
          ),
          GoRoute(
            path: RouteNames.routeMode,
            builder: (context, state) => const SubPageScaffold(
              title: 'وضع المسار',
              subtitle: 'ترتيب الجولة والتقدّم والمحطة التالية',
              child: RouteModeScreen(),
            ),
          ),
          GoRoute(
            path: RouteNames.diagnostics,
            builder: (context, state) => const SubPageScaffold(
              title: 'التشخيص والدعم',
              subtitle: 'حالة المزامنة وسجل التدقيق وآخر الأخطاء',
              child: DiagnosticsScreen(),
            ),
          ),
          GoRoute(
            path: RouteNames.apiLogs,
            builder: (context, state) => const ApiLogsScreen(),
          ),
          GoRoute(
            path: RouteNames.profile,
            builder: (context, state) => const SubPageScaffold(
              title: 'الملف الشخصي',
              subtitle: 'بياناتك وكلمة المرور والصورة',
              child: EditProfileScreen(),
            ),
          ),

          // ── Dev 2: Field Operations, Meters & Readings (SCR-08..SCR-12) ──
          GoRoute(
            path: RouteNames.fieldReader,
            name: 'field-reader',
            builder: (context, state) => FieldReaderScreen(
              metersRepository: GetIt.I<MetersRepository>(),
              readingsRepository: GetIt.I<ReadingsRepository>(),
              invoiceActions: GetIt.I<ReadingInvoiceActionsService>(),
              // ?meter= من Route Mode — يفتح شاشة القراءة معبّأة بمحطة الجولة.
              initialMeterQuery: state.uri.queryParameters['meter'],
            ),
          ),
          GoRoute(
            path: RouteNames.meters,
            name: 'meters',
            builder: (context, state) => MetersListScreen(
              metersRepository: GetIt.I<MetersRepository>(),
              readingsRepository: GetIt.I<ReadingsRepository>(),
              invoiceActions: GetIt.I<ReadingInvoiceActionsService>(),
              onOpenFieldReader: () => context.push(RouteNames.fieldReader),
            ),
            routes: <RouteBase>[
              GoRoute(
                path: 'new',
                name: 'meter-new',
                builder: (context, state) => MeterFormScreen(
                  repository: GetIt.I<MetersRepository>(),
                  meter: state.extra is Meter ? state.extra as Meter : null,
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
                  onAddReading: () => context.push(RouteNames.fieldReader),
                  onAddSubscriber: () => context.push(RouteNames.subscriberNew),
                  onOpenReports: () => context.push(RouteNames.reports),
                  onOpenInvoice: (invoice) => context.push('${RouteNames.invoices}/${invoice.id}'),
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
            studioBuilder: () => SettingsStudioScreen(
              repository: GetIt.I<SettingsRepository>(),
              can: GetIt.I<PermissionService>().can,
            ),
          ),
          GoRoute(
            path: RouteNames.reminders,
            builder: (context, state) => NotificationSettingsScreen(
              scheduler: GetIt.I<ReminderScheduler>(),
              store: GetIt.I<ReminderPreferencesStore>(),
            ),
          ),
          GoRoute(
            path: RouteNames.backup,
            builder: (context, state) => BackupRestoreScreen(
              service: GetIt.I<LocalBackupService>(),
            ),
          ),
          GoRoute(
            path: RouteNames.dataQuality,
            builder: (context, state) => DataQualityScreen(
              remote: DataQualityRemoteDataSource(GetIt.I<AdminApiClient>()),
              can: GetIt.I<PermissionService>().can,
            ),
          ),
          ...DeviceRoutes.routes(
            screenBuilder: () => DevicesScreen(
              repository: GetIt.I<DevicesRepository>(),
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
