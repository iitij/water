import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../features/invoices/data/models/invoice_model.dart';
import '../../features/invoices/data/models/invoice_query.dart';
import '../../features/invoices/domain/repositories/invoices_repository.dart';
import '../../features/invoices/presentation/pages/invoice_create_edit_screen.dart';
import '../../features/invoices/presentation/pages/invoice_detail_screen.dart';
import '../../features/invoices/presentation/pages/invoices_list_screen.dart';
import '../../features/invoices/presentation/sheets/generate_invoice_sheets.dart';
import '../../features/payments/data/models/payment_model.dart';
import '../../features/payments/domain/repositories/payments_repository.dart';
import '../../features/payments/presentation/pages/payment_form_screen.dart';
import '../../features/payments/presentation/pages/payments_list_screen.dart';
import '../../features/payments/presentation/pages/quick_collector_screen.dart';
import '../../features/tariffs/presentation/pages/tariffs_screen.dart';
import '../finance/finance_locator.dart';
import '../permissions/finance_permissions.dart';
import '../finance/finance_feedback.dart';
import 'finance_route_names.dart';

/// مسارات الوحدة المالية (`SCR-13`…`SCR-21`) — قائمة `RouteBase` جاهزة للإضافة إلى
/// `GoRouter` الرئيسي عند الدمج مع فرع Dev 1:
///
/// ```dart
/// GoRouter(routes: <RouteBase>[...authRoutes, ...financeRoutes])
/// ```
///
/// كل مسار مغلّف بـ [PermissionRouteShell] بمفتاح صلاحية من `FinanceAbilities`،
/// لأن دور `collector` في Laravel يملك `invoices.view` + `payments.collect` فقط.
List<RouteBase> buildFinanceRoutes() => <RouteBase>[
      GoRoute(
        path: FinanceRouteNames.quickCollector,
        name: 'quick-collector',
        builder: (context, state) => const PermissionRouteShell(
          ability: FinanceAbilities.viewInvoices,
          title: 'هذه الشاشة للمحصلين فقط (invoices.view)',
          child: QuickCollectorScreen(),
        ),
      ),
      GoRoute(
        path: FinanceRouteNames.invoices,
        name: 'invoices',
        builder: (context, state) => PermissionRouteShell(
          ability: FinanceAbilities.viewInvoices,
          child: InvoicesListScreen.create(initialQuery: _invoiceQueryOf(state)),
        ),
        routes: <RouteBase>[
          GoRoute(
            path: 'create',
            name: 'invoice-create',
            builder: (context, state) => const PermissionRouteShell(
              ability: FinanceAbilities.createInvoice,
              child: InvoiceCreateEditScreen(),
            ),
          ),
          GoRoute(
            path: 'generate/:engine',
            name: 'invoice-generate',
            builder: (context, state) => PermissionRouteShell(
              ability: FinanceAbilities.createInvoice,
              child: _GenerateEngineRoute(
                engine: state.pathParameters['engine'] ?? 'meter',
                subscriberId: state.uri.queryParameters['subscriber_id'],
              ),
            ),
          ),
          GoRoute(
            path: ':id',
            name: 'invoice-detail',
            builder: (context, state) {
              final id = int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
              final extra = state.extra;
              return PermissionRouteShell(
                ability: FinanceAbilities.viewInvoices,
                child: InvoiceDetailScreen.create(
                  invoiceId: id,
                  preset: extra is InvoiceModel ? extra : null,
                ),
              );
            },
          ),
          GoRoute(
            path: ':id/edit',
            name: 'invoice-edit',
            builder: (context, state) {
              final id = int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
              final extra = state.extra;
              if (extra is InvoiceModel) {
                return PermissionRouteShell(
                  ability: FinanceAbilities.editInvoice,
                  child: InvoiceCreateEditScreen(invoice: extra),
                );
              }
              return PermissionRouteShell(
                ability: FinanceAbilities.editInvoice,
                child: _InvoiceEditLoader(invoiceId: id),
              );
            },
          ),
        ],
      ),
      GoRoute(
        path: FinanceRouteNames.payments,
        name: 'payments',
        builder: (context, state) => PermissionRouteShell(
          ability: FinanceAbilities.viewPayments,
          child: PaymentsListScreen.create(
            initialQuery: _paymentQueryOf(state),
          ),
        ),
        routes: <RouteBase>[
          GoRoute(
            path: 'create',
            name: 'payment-create',
            builder: (context, state) {
              final extra = state.extra;
              return PermissionRouteShell(
                ability: FinanceAbilities.collectPayment,
                child: PaymentFormScreen(
                  invoice: extra is InvoiceModel ? extra : null,
                ),
              );
            },
          ),
          GoRoute(
            path: ':id/edit',
            name: 'payment-edit',
            builder: (context, state) {
              final id = int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
              final extra = state.extra;
              if (extra is PaymentModel) {
                return PermissionRouteShell(
                  ability: FinanceAbilities.editPayment,
                  child: PaymentFormScreen(payment: extra),
                );
              }
              return PermissionRouteShell(
                ability: FinanceAbilities.editPayment,
                child: _PaymentEditLoader(paymentId: id),
              );
            },
          ),
        ],
      ),
      GoRoute(
        path: FinanceRouteNames.tariffs,
        name: 'tariffs',
        builder: (context, state) => const PermissionRouteShell(
          ability: FinanceAbilities.viewTariffs,
          child: TariffsScreen(),
        ),
      ),
    ];

/// بارامترات URL مطابقة لفلاتر Filament الأربعة في `InvoiceTable`.
InvoiceQuery _invoiceQueryOf(GoRouterState state) {
  final params = state.uri.queryParameters;
  final start = params['billing_period_start'];
  final end = params['billing_period_end'];

  return InvoiceQuery(
    search: params['search'] ?? '',
    squareId: int.tryParse(params['square_id'] ?? ''),
    status: params['status'],
    subscriberId: int.tryParse(params['subscriber_id'] ?? ''),
    invoiceType: params['invoice_type'],
    billingPeriod: (start != null || end != null) ? '$start|$end' : null,
    trashed: params['trashed'] ?? 'without',
  );
}

PaymentQuery? _paymentQueryOf(GoRouterState state) {
  final params = state.uri.queryParameters;
  if (params.isEmpty) return null;

  return PaymentQuery(
    search: params['search'] ?? '',
    method: params['method'],
    subscriberId: int.tryParse(params['subscriber_id'] ?? ''),
    invoiceId: int.tryParse(params['invoice_id'] ?? ''),
    fromDate: DateTime.tryParse(params['from'] ?? ''),
    toDate: DateTime.tryParse(params['to'] ?? ''),
  );
}

/// `SCR-16` كمسار مستقل (للتابلت/الشاشات الكبيرة): يفتح الدرج تلقائياً بعد
/// بناء الإطار، ويعيد المستخدم إلى المسار السابق عند إغلاقه.
class _GenerateEngineRoute extends StatefulWidget {
  const _GenerateEngineRoute({required this.engine, this.subscriberId});

  final String engine;
  final String? subscriberId;

  @override
  State<_GenerateEngineRoute> createState() => _GenerateEngineRouteState();
}

class _GenerateEngineRouteState extends State<_GenerateEngineRoute> {
  bool _opened = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _open());
  }

  Future<void> _open() async {
    if (_opened) return;
    _opened = true;

    if (widget.engine == 'subscription') {
      await GenerateSubscriptionInvoiceSheet.show(
        context,
        preselectedSubscriberId: widget.subscriberId,
      );
    } else if (widget.engine == 'tanker') {
      await GenerateTankerInvoiceSheet.show(
        context,
        preselectedSubscriberId: widget.subscriberId,
      );
    } else if (widget.engine == 'manual') {
      if (!mounted) return;
      await Navigator.of(context).push<bool>(
        MaterialPageRoute<bool>(builder: (_) => const InvoiceCreateEditScreen()),
      );
    } else {
      await GenerateMeterInvoiceSheet.show(
        context,
        preselectedSubscriberId: widget.subscriberId,
      );
    }

    if (!mounted) return;
    if (GoRouter.of(context).canPop()) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('إصدار فواتير')),
      body: EmptyStateWidget(
        title: 'جارٍ فتح نافذة الإصدار…',
        message: 'يمكنك أيضاً اختيار محرّك الإصدار من زر «إصدار» في قائمة الفواتير.',
        icon: Icons.bolt,
        actionLabel: 'اختيار محرّك',
        onAction: () async {
          await showGenerateInvoicePicker(context);
          if (!mounted) return;
          if (GoRouter.of(context).canPop()) context.pop();
        },
      ),
    );
  }
}

/// يجلب الفاتورة من الـ API ثم يفتح نموذج التعديل (للدخول المباشر عبر رابط).
class _InvoiceEditLoader extends StatelessWidget {
  const _InvoiceEditLoader({required this.invoiceId});

  final int invoiceId;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<InvoiceModel>(
      future: sl<InvoicesRepository>().byId(invoiceId),
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return Scaffold(
            appBar: AppBar(title: const Text('تعديل فاتورة')),
            body: ErrorStateWidget(message: '${snapshot.error}'),
          );
        }
        if (!snapshot.hasData) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        return InvoiceCreateEditScreen(invoice: snapshot.data);
      },
    );
  }
}

class _PaymentEditLoader extends StatelessWidget {
  const _PaymentEditLoader({required this.paymentId});

  final int paymentId;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<PaymentModel>(
      future: sl<PaymentsRepository>().byId(paymentId),
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return Scaffold(
            appBar: AppBar(title: const Text('تعديل سند')),
            body: ErrorStateWidget(message: '${snapshot.error}'),
          );
        }
        if (!snapshot.hasData) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        return PaymentFormScreen(payment: snapshot.data);
      },
    );
  }
}
