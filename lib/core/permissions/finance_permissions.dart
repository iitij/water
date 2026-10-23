import 'package:flutter/material.dart';

import '../auth/session_store.dart';
import '../di/injection_container.dart';

/// مصفوفة الصلاحيات الخاصة بالوحدة المالية — **ملف إضافي من Dev 3** يُدمج مع
/// `core/permissions/` الخاص بـ Dev 1 (لم يُنشأ هناك بعد باسمنا، فاستعملنا اسماً
/// مميزاً لتفادي تعارض الدمج).
///
/// الأسماء تتبع مصفوفة الخطة §3.3 (`role_matrix`): المفاتيح تُقرأ من
/// `SessionStore.permissions` كما يرجعها `GET /api/auth/me` في حقل `abilities`،
/// و`super_admin` يملك `*` ضمنياً (يُدار داخل `SessionStore.has`).
class FinanceAbilities {
  FinanceAbilities._();

  // الفواتير.
  static const String viewInvoices = 'invoices.view';
  static const String createInvoice = 'invoices.create';
  static const String editInvoice = 'invoices.edit';
  static const String deleteInvoice = 'invoices.delete';
  static const String recalculateInvoice = 'invoices.recalculate';

  // الدفعات والتحصيل.
  static const String viewPayments = 'payments.view';
  static const String collectPayment = 'payments.collect';
  static const String editPayment = 'payments.edit';
  static const String deletePayment = 'payments.delete';

  // التسعيرات (SCR-21).
  static const String viewTariffs = 'tariffs.view';
  static const String manageTariffs = 'tariffs.manage';

  // محركات المخرجات.
  static const String printPdf = 'print.pdf';
  static const String printThermal = 'print.thermal';
  static const String sendWhatsapp = 'whatsapp.send';

  /// مجموعة المفاتيح التي تحتاجها شاشة المحصل السريع — يكفي إحداها.
  static const List<String> quickCollector = <String>[
    viewInvoices,
    collectPayment,
  ];
}

/// امتداد سياق للتحقق السريع قبل تنفيذ إجراء.
extension FinancePermissionX on BuildContext {
  SessionStore get session => sl<SessionStore>();

  bool can(String ability) => session.has(ability);

  bool canAny(Iterable<String> abilities) => session.canAny(abilities);

  bool get canViewInvoices => can(FinanceAbilities.viewInvoices);
  bool get canCreateInvoices => can(FinanceAbilities.createInvoice);
  bool get canDeleteInvoices => can(FinanceAbilities.deleteInvoice);
  bool get canCollectPayments => can(FinanceAbilities.collectPayment);
  bool get canDeletePayments => can(FinanceAbilities.deletePayment);
  bool get canManageTariffs => can(FinanceAbilities.manageTariffs);
  bool get canPrintThermal => can(FinanceAbilities.printThermal);
  bool get canSendWhatsapp => can(FinanceAbilities.sendWhatsapp);
}

/// يخفي محتواه أو يستبدله حسب صلاحية واحدة أو أكثر (يُستخدم للأزرار الحساسة).
class PermissionGate extends StatelessWidget {
  const PermissionGate({
    super.key,
    required this.child,
    this.anyOf,
    this.allOf,
    this.fallback,
  }) : assert(anyOf != null || allOf != null, 'حدّد anyOf أو allOf');

  final Widget child;
  final List<String>? anyOf;
  final List<String>? allOf;
  final Widget? fallback;

  @override
  Widget build(BuildContext context) {
    final session = context.session;
    final any = anyOf;
    final all = allOf;

    final allowed = <bool>[
      if (any != null) session.canAny(any),
      if (all != null) session.canAll(all),
    ].every((value) => value);

    return allowed ? child : (fallback ?? const SizedBox.shrink());
  }
}

/// يمنع الدخول إلى مسار كامل (يُستخدم في `finance_routes`): يعرض شاشة «لا صلاحية»
/// بدل الشاشة المطلوبة، بدل قفز صامت يربك المستخدم في الميدان.
class PermissionRouteShell extends StatelessWidget {
  const PermissionRouteShell({
    super.key,
    required this.ability,
    required this.child,
    this.title = 'لا تملك صلاحية لهذه الشاشة',
  });

  final String ability;
  final Widget child;
  final String title;

  @override
  Widget build(BuildContext context) {
    if (context.can(ability)) return child;

    return Scaffold(
      appBar: AppBar(title: const Text('محمي بالصلاحيات')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              const Icon(Icons.lock_outline, size: 40, color: Colors.grey),
              const SizedBox(height: 12),
              Text(title, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              Text(
                'المفتاح المطلوب: $ability',
                style: const TextStyle(fontSize: 12, color: Colors.grey),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
