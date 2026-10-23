# تكامل حزمة Dev 5 — لوحة القيادة والإعدادات والصلاحيات والتصدير

هذه الحزمة feature-first ولا تعدّل `lib/core/` أو `app_router.dart`. جميع المسارات مبنية كـ feature route factories، وجميع نقاط الاتصال ببقية الفريق عبر adapters حتى تبقى ملكية الملفات واضحة.

## 1. المتطلبات والتثبيت

- Flutter **3.33 أو أحدث** وDart **3.8 أو أحدث**؛ هذا يطابق `DropdownButtonFormField.initialValue` وبقية واجهات Material المستخدمة.
- دمج dependencies الموجودة في `pubspec.yaml` مع ملف المشروع الرئيسي ثم:

```bash
flutter pub get
dart format lib test
flutter analyze
flutter test
```

لا تحتاج الحزمة إلى `main()` مستقل. الملفات الأساسية هي barrels التالية:

```dart
import 'package:go_router/go_router.dart';
import 'package:water_project/features/admin_shared/data/admin_api_client.dart';
import 'package:water_project/features/dashboard/dashboard.dart';
import 'package:water_project/features/settings/settings.dart';
import 'package:water_project/features/users_roles/users_roles.dart';
import 'package:water_project/features/exports/exports.dart';
```

## 2. حقن Dio والكاش والمستودعات

يستلم `DioAdminApiClient` نسخة Dio التي يهيئها Dev 1، بما فيها `baseUrl` وBearer token وinterceptors. لا تنشئ نسخة ثانية بلا مصادقة.

```dart
final api = DioAdminApiClient(coreDio);

final settingsCache = SharedPreferencesSettingsCache();
await settingsCache.rehydrate();
final settingsRepository = SettingsRepositoryImpl(
  SettingsRemoteDataSource(api),
  settingsCache,
);

final dashboardRepository = DashboardRepositoryImpl(
  DashboardRemoteDataSource(api),
  DashboardLocalDataSource(),
);
final rbacRepository = RbacRepositoryImpl(RbacRemoteDataSource(api));
final exportRepository = ExportRepositoryImpl(ExportRemoteDataSource(api));
final excelService = ExcelExportService.platform(exportRepository);
```

يفضّل تسجيل هذه الكائنات مرة واحدة في service locator الخاص بالفريق. `DashboardLocalDataSource` و`SharedPreferencesSettingsCache` يستخدمان SharedPreferences؛ لا توجد قاعدة بيانات جديدة.

## 3. ربط الصلاحيات والمسارات

مرّر دالة Mohammed إلى كل screen. المطلوبة صلاحيات Shield PascalCase مثل `ViewAny:Invoice` و`Update:Setting` وليست snake_case القديمة.

```dart
bool can(String permission) => permissionService.can(permission);

final dev5Routes = <RouteBase>[
  ...DashboardRoutes.routes(
    dashboardBuilder: () => DashboardScreen(
      repository: dashboardRepository,
      userName: currentUser.name,
      roleLabel: currentUser.roleLabel,
      can: can,
      onCollectPayment: collectPayment,
      onSendReminder: sendReminder,
      onOpenInvoice: openInvoice,
    ),
    reportsBuilder: () => ReportsScreen(repository: dashboardRepository),
  ),
  ...SettingsRoutes.routes(
    studioBuilder: () => SettingsStudioScreen(
      repository: settingsRepository,
      can: can,
    ),
  ),
  ...UsersRolesRoutes.routes(
    usersBuilder: () => UsersManagementScreen(
      repository: rbacRepository,
      can: can,
      currentUserId: currentUser.id,
    ),
    rolesBuilder: () => RolesAndPermissionsScreen(
      repository: rbacRepository,
      can: can,
    ),
  ),
  ...ExportRoutes.routes(
    centerBuilder: () => ExportCenterScreen(service: excelService, can: can),
  ),
];
```

المسارات الافتراضية:

| الشاشة | المسار |
|---|---|
| SCR-03 لوحة القيادة | `/dashboard` |
| SCR-27 التقارير | `/reports` |
| SCR-23 مراكز الإعدادات | `/settings` |
| SCR-24 المستخدمون | `/users` |
| SCR-25 الأدوار وShield | `/roles` |
| SCR-26 التصدير | `/exports` |

تستخدم المصانع الثوابت والأسماء المعلنة في classes الخاصة بالمسارات. لا تُسجّل شاشة بلا PermissionGuard إذا كانت ستُستخدم خارج studio؛ `SettingsStudioScreen` يخفي المراكز غير المسموحة، وشاشات المستخدمين/الأدوار تتحقق من صلاحيات العرض والتعديل. زر التحصيل يظهر فقط مع `Create:Payment`، والحذف الجماعي للمستخدمين مع `DeleteAny:User`.

## 4. ربط تحصيل Akram والتذكير

توقيع `DashboardPaymentLauncher` هو:

```dart
Future<bool> Function(BuildContext context, DashboardInvoice invoice)
```

مثال الربط دون استيراد مباشر داخل feature لوحة القيادة:

```dart
Future<bool> collectPayment(
  BuildContext context,
  DashboardInvoice invoice,
) async {
  final paid = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (_) => CollectPaymentSheet(
      invoiceId: invoice.id,
      subscriberId: invoice.subscriberId,
      amountDue: invoice.remainingAmount,
    ),
  );
  return paid ?? false;
}
```

عدّل أسماء parameters فوق بما يطابق توقيع `CollectPaymentSheet` الفعلي عند دمج عمل Akram. عند عودتها `true` يعيد dashboard الجلب فوراً. أما التذكير فيستقبل `DashboardInvoice` ويستدعي قناة WhatsApp الخاصة بالفريق؛ `DashboardReminderLauncher` يرجع `Future<void>`.

**قاعدة المتبقي:** عند غياب `true_remaining_amount` من الخادم تستخدم الحزمة:

```text
(total_amount + previous_balance) - credit_applied - amount_paid
```

لا يجوز جمع `previous_balance` مرة أخرى في طبقة العرض. إذا أرسل backend قيمة `true_remaining_amount` فهي التي تُعرض.

## 5. الكاش المشترك مع Anwar وAkram

كل حفظ ناجح لإعدادات القراءة أو الفاتورة أو السند أو WhatsApp أو رفع صورة أو CRUD لإعداد عام يحدّث الكاش **بعد** نجاح API. المفتاح المخزن في SharedPreferences هو `dev5.settings.cache.v1`.

```dart
final period = await settingsCache.read('reading_period');
final graceDays = await settingsCache.read('grace_period');

final subscription = settingsCache.changes.listen((event) {
  if (event.changedKeys.contains('reading_invoice_message_template')) {
    // أعد بناء قالب الرسالة في الوحدة المستهلكة.
  }
});
```

- `changes` broadcast ويحمل القسم والمفاتيح المتغيرة ووقتها ونسخة غير قابلة للتعديل من القيم.
- `whatsapp_access_token` و`access_token` لا يدخلان الكاش أبداً.
- حفظ token فارغ في WhatsApp لا يُرسل المفتاح؛ بذلك لا يمسح السر المحفوظ في Laravel. إدخال قيمة جديدة يرسلها.
- على المستهلك استدعاء `rehydrate()` عند بدء التطبيق قبل القراءة.
- القوالب التشغيلية تُحفظ بالمفاتيح الجديدة والقديمة معاً: `reading_invoice_message_template` و`payment_receipt_message_template` و`subscription_invoice_template` و`tanker_invoice_template`.

## 6. عقود API

يكون `baseUrl` المنتهي بـ`/api` في Dio المشترك؛ لذلك يصبح `/admin/dashboard` مثلاً `/api/admin/dashboard`، بينما تصبح `/settings/reading` مسار `/api/settings/reading`:

- `GET /admin/dashboard`
- `GET /admin/reports?from_date=YYYY-MM-DD&to_date=YYYY-MM-DD`
- `GET|POST /settings/{reading|invoice|receipt|whatsapp}`
- `GET /whatsapp/local-status` و`POST /whatsapp/local-start|local-stop`
- `GET|POST /settings/items` و`PUT|DELETE /settings/items/{id}`
- `POST /settings/upload-logo`
- CRUD `/users` و`/roles` و`GET /roles/permissions-matrix`
- `GET /exports/summary` و`GET /squares`
- `GET /exports/{subscribers|invoices|readings}` كـ bytes

استجابات Laravel المغلفة بـ`data` وunwrapped مدعومة. الأخطاء 422 تتحول إلى أول رسالة validation عربية/خادمية مع الاحتفاظ بكل `validationErrors`.

fallback موجود فقط للتوافق أثناء دمج backend، وفقط عند **404/405**:

| الأساسي | البديل |
|---|---|
| `/settings/{section}` | `/settings` |
| `/whatsapp/local-*` | `/whatsapp-server/*` |
| `/roles/permissions-matrix` | `/permissions` |
| `/exports/*` | `/export/*` |

لا يُنفّذ fallback عند 401/403/422/500، ولا عند خطأ شبكة. عند جلب إعداد section عبر `/settings` القديم تُرسل قيم القسم فقط عند الحفظ، لا كل أقسام التطبيق.

## 7. WhatsApp Local Server

`LocalServerControlPanel` ينشئ `Timer.periodic` كل ثلاث ثوانٍ، ويلغيه في `dispose`، ويمنع تداخل poll السابق مع التالي. الحالات هي `offline` و`waiting` و`qr` و`connected`، ويدعم QR كرابط أو Base64/data URI. الطرفيتان `whatsappLogs` و`queueLogs` تعرضان آخر 15 سطراً.

يجب أن ينفذ backend التشغيل/الإيقاف والاتصال بالخادم المحلي؛ لا يتصل الهاتف مباشرة بـ`localhost:3000`. عند استجابة start بلا status يعرض `waiting` حتى يصل poll التالي، وعند stop بلا status يعرض `offline`.

## 8. مصمّما المستندات وRBAC والتصدير

- الفاتورة: 7 تبويبات، صورة شعار/خلفية/ختم، `FieldConfigTile` موحّد للإظهار والاسم البديل.
- السند: 5 تبويبات ومفاتيح `rect_*` المطابقة لـFilament، بما فيها `rect_title_subscriber` و`rect_title_payment` وsuffix التفقيط.
- Shield: 13 مورداً × 12 عملية + 6 صفحات + 5 ودجات = 167 اسماً. `super_admin` للقراءة فقط ولا يُحذف. يمكن تحديد الكل أو مجموعة كاملة أو إذن منفرد.
- التصدير يتم في backend عبر `DataExportService.php` وليس بإعادة توليد الأعمدة في Flutter. الفلاتر هي `square_id,status` للمشتركين، و`square_id,from_date,to_date,status` للفواتير، و`square_id,from_date,to_date,is_invoiced` للقراءات.
- يُرفض أي download لا يبدأ بتوقيع ZIP/XLSX (`PK\x03\x04`)، بما فيه صفحة HTML لانتهاء الجلسة. يُحفظ الملف في `Documents/exports` ثم يمكن فتحه أو مشاركته.

## 9. إعدادات المنصة والتحقق

أضف `NSPhotoLibraryUsageDescription` في iOS لاختيار صور المصمم. تأكد من صلاحية الإنترنت في Android ومن إعداد FileProvider/LSApplicationQueriesSchemes إذا اشترطتها نسخة نظام التشغيل أو plugins لفتح/مشاركة الملفات.

قبل الدمج النهائي شغّل على محاكي/جهاز:

```bash
flutter analyze
flutter test
flutter test --coverage
```

اختبر يدوياً RTL على هاتف ضيق ولوحي، وفشل الشبكة مع cache، و403 لكل زر، و422 لكل نموذج، وQR غير الصالح، وانتقال `qr → connected`، وفتح ملف Excel فعلي، ونجاح التحصيل ثم اختفاء/تحديث الفاتورة المتأخرة.
