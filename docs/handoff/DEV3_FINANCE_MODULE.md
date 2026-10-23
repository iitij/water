# DEV 3 — وحدة الفواتير والتحصيل والطباعة (مالية الموبايل)

**الفرع:** `feat/akram-invoices-payments-print` · **المطور:** أكرم (Dev 3)
**الوحدات:** `lib/features/invoices/**`, `lib/features/payments/**`, `lib/features/tariffs/**`
+ محركات النواة التي طلبتها الخطة من Dev 3 (`pdf/thermal/whatsapp/accounting`).
**حجم التسليم:** 99 ملف Dart، ~17.7 ألف سطر (منها 5 ملفات اختبارات منطق محاسبي).

---

## 0. حالة التحقق (اقرأ هذا أولًا)

لا يحتوي صندوق العمل على Dart/Flutter SDK، ولا يمكن تنزيله (المسموح للشبكة فقط:
`github.com`, `casks/npmjs`, `pypi`). لذلك **لم يُنفَّذ** `flutter analyze` ولا
`flutter test`. ما تم بدلاً من ذلك:

1. فحص آلي مكتوب خصيصًا (`import` مفقود/زائد، توازن أقواس، أنواع `sl<T>()` مقابل
   التسجيلات الفعلية في `core/di/injection_container.dart`، وجود أعضاء
   `state.x` / `cubit.x`، وجود أسماء الوسوم `named args` في استدعاءات أصناف
   المشروع) — النتيجة الحالية: **صفر أخطاء حقيقية** بعد إصلاح آخر بلاغين
   (`AppTextField.validator` أُضيف، `widget.subscribersLoader` كان إنذارًا كاذبًا).
2. مقارنة يدوية سطر‑بسطر مع مصادر Laravel: `InvoiceService`, `PaymentService`,
   `WhatsAppService`, `Invoice/Payment/Tariff/Setting`, `InvoiceResource`,
   `PaymentResource`, `TariffResource`, `resources/views/pdf/{invoice,receipt}.blade.php`,
   `routes/api.php`, وملفات الـ migrations.

**أوامر يجب تشغيلها بعد الدمج (مسؤولة عنها Dev 1 في CI):**

```bash
cd app && flutter pub get && flutter analyze && flutter test
# إن ظهرت أخطاء `DropdownButtonFormField initialValue` → نسخة Flutter >3.32؛
# الكود مكتوب على `value:` التزامن مع 3.27 (الحد الأدنى في pubspec).
```

مخاطر معروفة مرتبطة بالإصدار: `Color.withValues(alpha:)` (يتطلب 3.27+)،
`share_plus ^10` (`Share.shareXFiles`)، `printing 5.13` (`PdfPreview` بلا
`canPrint`/`canShare` مخصصًا)، وغياب `esc_pos_utils_plus` (استُبدل بمحرك ESC/POS
مكتوب يدويًا — §5).

---

## 1. خريطة الملفات

```
lib/
  bootstrap.dart                    تهيئة DI + Session + FinanceConfig + ScreenUtil
  app.dart                          WaterApp (MaterialApp.router، RTL، توطين عربي)
  main.dart                           نقطة الدخول (runZonedGuarded)
  core/
    config/app_config.dart          نسخة تعاقدية (المالك: Dev 1)
    network/{api_client,finance_endpoints,network_info,auth_token_provider}.dart
    error/failures.dart             AppFailure + ValidationFailure + FailureMapper
    auth/session_store.dart         token + role + permissions (has/canAny/canAll)
    permissions/finance_permissions.dart  FinanceAbilities + context.can + PermissionGate/RouteShell
    theme/{app_colors,app_theme}.dart      Tajawal، #02687E، بطاقات، Dark
    responsive/{breakpoints,adaptive_scaffold}.dart  phone/tablet/desktop + Rail/BottomNav
    widgets/{app_numeric_field,compound_search_bar,confirm_dialog,empty_state_widget,
             info_notice,loading_skeleton,pdf_preview_sheet,status_badge,
             thermal_printer_prompt,finance_scaffold}.dart
    settings/finance_config.dart    FinanceConfig + FinanceConfigRepository (cache+refresh)
    local_storage/sync_queue.dart   طابور مزامنة على SharedPreferences
    accounting/account_isolation.dart  نفس خوارزمية عزل الحساب (Meter vs Subscriber)
    services/pdf_print_service.dart        A5 Landscape: preview/print/share/save
    services/thermal_printer_service.dart  ESC/POS بلوتوث 58/80mm
    services/whatsapp_launcher_service.dart  click_to_chat / meta_api / local_api
    services/esc_pos/{esc_pos_buffer,thermal_ticket,thermal_raster_renderer}.dart
    di/injection_container.dart     `sl` + registerCoreContracts + [DEV-3] block
    router/{finance_route_names,finance_routes,app_router}.dart
    utils/{currency_formatter,arabic_number_spellout,compound_search_parser,
           phone_formatter,json_utils}.dart
  features/
    invoices/
      data/models/{invoice_model,invoice_query}.dart
      data/datasources/{invoices_remote_datasource,references_remote_datasource}.dart
      data/repositories/invoices_repository_impl.dart
      domain/accounting/{finance_enums,invoice_accounting}.dart
      domain/repositories/invoices_repository.dart
      presentation/cubit/{invoices_cubit,invoices_state,invoice_detail_cubit,
                         invoice_detail_state,invoice_generation_cubit,
                         invoice_generation_state}.dart
      presentation/pages/{invoices_list_screen,invoice_detail_screen,
                         invoice_create_edit_screen}.dart
      presentation/sheets/{generate_invoice_sheets,invoice_filters_sheet}.dart  (الثاني في widgets/)
      presentation/widgets/{invoice_card,invoice_actions,invoice_filters_sheet}.dart
      presentation/thermal/invoice_thermal_ticket.dart
      presentation/whatsapp/invoice_whatsapp_builder.dart
    payments/
      data/models/{payment_model,collect_payment_models}.dart
      data/datasources/payments_remote_datasource.dart
      data/repositories/payments_repository_impl.dart
      domain/accounting/payment_allocator.dart
      domain/repositories/payments_repository.dart
      presentation/cubit/{collect_payment_cubit,collect_payment_state,
                         payments_cubit,quick_collector_cubit,quick_collector_state}.dart
      presentation/pages/{quick_collector_screen,payments_list_screen,payment_form_screen}.dart
      presentation/sheets/{collect_payment_sheet,receipt_success_sheet}.dart
      presentation/widgets/{payment_card,collect_payment_form,payment_actions}.dart
      presentation/thermal/payment_thermal_ticket.dart
      presentation/whatsapp/payment_whatsapp_builder.dart
    tariffs/  data + domain + presentation (SCR-21 مع Trashed/Restore/ForceDelete)
test/
  accounting/{invoice_accounting,payment_allocator}_test.dart
  utils/{arabic_number_spellout,compound_search_parser}_test.dart
  services/whatsapp_template_processor_test.dart
```

> `pubspec.yaml` و`analysis_options.yaml` داخل `app/`؟ لا — وُضعا في جذر المستودع
> بجانب `lib/` (المسار المتفق في الخطة §3.1). عند إنشاء مجلد `app/` انقلهما مع `lib/`.

---

## 2. القواعد المحاسبية الثلاث (100% مطابقة للـ Laravel)

### 2.1 عزل الحسابات — `core/accounting/account_isolation.dart` + `InvoiceAccounting`

| السياق | الحساب المتأثر | المصدر في Laravel |
|---|---|---|
| `invoice.meter_id != null` | `meters.balance` فقط | `InvoiceService::generateInvoice` (سطر 62-90) |
| `invoice.meter_id == null` | `subscribers.balance` | نفس السطر، فرع `subscriber` |

* `AccountIsolation.sameAllocationAccount(paymentMeterId:, invoiceMeterId:)` — قرار
  «هل تنطبق هذه الدفعة على هذه الفاتورة؟»؛ تُستدعى قبل أي توزيع.
* `AccountIsolation.arrearsGroupKey(subscriberId:, invoiceType:, meterId:)` — مجموعة
  الدين المتراكمة (نفس مفتاح `getTrueRemainingAmount`).
* الرصيد الدائن المتاح يُقرأ من `MeterOption.credit` أو `SubscriberOption.availableGeneralCredit`
  حسب وجود `meter_id` — لا يُمزجان أبدًا.

### 2.2 الحقول: `total_amount` ≠ `total_due` — `InvoiceAccounting` / `InvoiceModel`

```
total_due   = total_amount + previous_balance
net_payable = max(0, total_due - credit_applied)
own_remaining   = max(0, total_amount - amount_paid)      // Invoice::getRemainingAmount()
true_remaining    = own_remaining + Σ own_remaining(الأقدم في نفس مجموعة العزل)
display_remaining = الأحدث فقط ? true_remaining : null   // العمود يعرض «-» للقدمى
```

* `total_amount` = رسوم الفترة الحالية فقط (استهلاك + اشتراك + وايت) — لا تُضاف
  المتأخرات إليه (مطابق لـ `InvoiceService` سطر 96-108).
* `serverTrueRemaining` (`true_remaining_amount`) و`isLatestInvoice` من الخادم
  يُقدَّمان على أي حساب محلي؛ عند غيابهما يُحسبان من الصفحة الحالية.
* `computeStatus()` يعيد إنتاج `Invoice::updateStatus()` (paid / partially_paid /
  overdue مع `grace_period` / issued) + مفتاح `strict_overdue_on_new_invoice`
  للفواتير الأقدم — مستعمل في «المعاينة الفورية بعد الدفع أوفلاين» فقط.

### 2.3 توزيع الدفعات FIFO — `payments/domain/accounting/payment_allocator.dart`

`allocate(invoices:, amount:, targetInvoiceId:)`:
1. الفاتورة المستهدفة أولًا (إن حُددت وكانت مفتوحة للتحصيل).
2. ثم FIFO على الأقدم داخل نفس الحساب المعزول.
3. السقف = `invoice.total_amount` فقط (لا تُسدد المتأخرات المحمولة من الدفعة).
4. كل خطوة تُقرَّب لخانتين (مطابق `round($x, 2)` في `PaymentService`).
5. الفائض → `AllocationResult.surplusCredit` → يصبح رصيدًا دائنًا للخادم.

`recalculate(invoices:, payments:, subscriberId:, meterId:, …)` يطابق
`PaymentService::recalculateInvoices` (سطر 16-73): تصفير `amount_paid` لكل فواتير
النطاق، إعادة توزيع كل الدفعات بترتيب `payment_date, id` (والمدفوع لفاتورة معينة
يُقدَّم)، ثم `updateStatus()` لكل فاتورة.

`validate(invoice:, amount:)` → `PaymentShortfall` (رسائل عربية للواجهة: الفائض
يتحول رصيدًا دائنًا، رفض المبلغ ≤ 0).

**اختبارات تثبت ما سبق:** `test/accounting/payment_allocator_test.dart` (12 اختبارًا)
و`test/accounting/invoice_accounting_test.dart` (16 اختبارًا).

---

## 3. الشاشات المسلَّمة

| SCR | الملف | ما ينفّذه |
|---|---|---|
| SCR-13 المحصل السريع | `payments/presentation/pages/quick_collector_screen.dart` | بحث فوري (اسم مشترك/مستأجر/رقم فاتورة/كود عداد/`4/1`) + فلتر المربع، تحصيل بلمسة، لافتة آخر سند، تحديث صامت بعد التحصيل |
| SCR-14 قائمة الفواتير | `invoices/presentation/pages/invoices_list_screen.dart` | الفلاتر الأربعة (`billing_period`, `square_id`, `status`, `subscriber_id`)، `beneficiary_name` (المستأجر ثم المشترك)، تحديد متعدد + إعادة احتساب/حذف جماعي، master/detail على التابلت، FAB محرّك الإصدار |
| SCR-15 تفاصيل الفاتورة | `invoices/presentation/pages/invoice_detail_screen.dart` + `InvoiceDetailPanel` | بنود/قراءات/دفعات/ملاحظات + شبكة إجراءات (تحصيل، إعادة احتساب، PDF، حراري، واتساب، تعديل، حذف) |
| SCR-16 نوافذ الإصدار | `invoices/presentation/sheets/generate_invoice_sheets.dart` | عداد (قراءات + مفتاحا الاشتراك/الوايت)، اشتراك، وايت — بحساب حي ومتابعة فورية للطباعة/الواتساب |
| SCR-17 السندات | `payments/presentation/pages/payments_list_screen.dart` | بحث + فلتر طريقة الدفع والفترة، ملخص (عدد/إجمالي/نقدي)، حذف سحبي + تحديد متعدد وحذف جماعي |
| SCR-18 نموذج الدفعة | `payments/presentation/pages/payment_form_screen.dart` | تعبئة تلقائية لأحدث فاتورة غير مسددة، حقول مشروطة، ووضع «تعديل سند» عبر `PUT /payments/{id}` |
| SCR-21 التسعيرات | `tariffs/presentation/pages/tariffs_screen.dart` | قائمة + السعر النشط في الترويسة + `TrashedFilter` (with/only/without) + استعادة/حذف نهائي + نموذج إنشاء/تعديل |

بحث `4/1`: `core/utils/compound_search_parser.dart` (`CompoundSearchQuery.parse`)
يطابق `preg_match('/^(\d+)[\/\-\\\\_](\d+)$/')` في `InvoiceController::search`
و`ReadingController::search`، ويطبّع الأرقام العربية/الفارسية، ويرسل
`square_id`+`path_number` منفصلتين عندما يدعمهما المسار.

---

## 4. مكوّنات التسليم للفرق الأخرى (Dev 4 / Dev 5)

### 4.1 `CollectPaymentSheet` — العقد المستقر

```dart
// lib/features/payments/presentation/sheets/collect_payment_sheet.dart
static Future<bool> CollectPaymentSheet.show(
  BuildContext context, {
  InvoiceModel? invoice,          // تحصيل فاتورة محددة
  SubscriberOption? subscriber,   // أو تحصيل عام (FIFO تلقائي من الخادم)
  int? meterId,                   // لتثبيت العزل إن كان السياق عدادًا
});
```
`true` = نجح الحفظ على الخادم أو أُدرج في طابور المزامنة (لا تُظهر «تم الحفظ» بل
«في الطابور» — تنفّذه `ReceiptSuccessSheet`). الواجهة الداخلية = `CollectPaymentForm`
(نفس النموذج في SCR-18)، ويمكن استدعاؤه مباشرة:

```dart
CollectPaymentForm(invoice: invoice, onSubmitted: (ok) { /* … */ })
```

### 4.2 `GenerateSubscriptionInvoiceSheet` / `GenerateTankerInvoiceSheet`

```dart
await GenerateSubscriptionInvoiceSheet.show(context, preselectedSubscriberId: '42');
await GenerateTankerInvoiceSheet.show(context);
// أو معا: اختيار المحرّك
await showGenerateInvoicePicker(context);
```

### 4.3 البطاقات

```dart
InvoiceCard(invoice: i, remainingLabel: remainingLabelFor(i, siblings),
            canCollect: canCollectInvoice(i, siblings), onCollect: …,
            whatsappEnabled: config.whatsappEnabled, onWhatsapp: …,
            thermalEnabled: true, onPrintThermal: …, dense: true,
            showCheckbox: …, selected: …, onToggleSelect: …, busy: …)
PaymentCard(payment: p, onTap: …, dense: true, showAccountScope: true)
```
* `remainingLabelFor` يلتزم بقاعدة «`-` لغير الأحدث» — لا تحسباها في وحداتكم.
* `InvoicesSummaryBar(count:, openCount:, overdueCount:, totalDue:)` للترويسة المموّهة.

### 4.4 إجراءات جاهزة (لا تلتفّ على الـ Cubits)

```dart
openInvoicePdfPreview(context, invoice);   printInvoiceThermal(context, invoice);
sendInvoiceWhatsapp(context, invoice);     collectInvoicePayment(context, invoice, cubit: c);
showInvoiceActionSheet(context, invoice: i, cubit: c);
openPaymentPdfPreview(context, payment);   printPaymentThermal(context, payment, receiptData: r);
sendPaymentWhatsapp(context, payment, receiptData: r);
```
كلها تستعلم عن `FinanceConfig` بنفسها (واتساب معطّل ⇒ لا زر/لا إرسال).

---

## 5. المحركات — ما تستدعيه Dev 2 (والوحدات الأخرى)

### 5.1 `PdfPrintService` (A5 Landscape)
`fetchPdf(path, {fallbackUrl})`, `printBytes(bytes)` — `layoutPdf` بـ
`PdfPageFormat.a5Landscape`, `sharePdf`, `saveAndOpen`, `filamentFallbackUrl`.
معاينة عبر `PdfPreviewSheet.open(context, title:, service:, fileName:, loader:)`.

### 5.2 `ThermalPrinterService` (58/80mm Bluetooth)
`printTicket(ThermalTicket)`, `buildBytes(ticket, {widthDots})`
(576 نقطة لـ80mm، 384 لـ58mm)، `testPrint()`, `savedAddress`/`saveAddress`
(المفتاح `thermal.printer.address`)، وعرض الورق من `thermal_paper_width`.
العربية تُرسم **كرسترة نقطيّة** (`ThermalRasterRenderer` عبر `dart:ui`) لأن
رموز ESC/POS النصية لا تنضبط مع التشكيل العربي؛ لا حاجة لحزمة `esc_pos_utils_plus`.
اللون: `ThermalSyntax.field(label, value)` + `big()` + التفقيط
(`ArabicNumberSpellout`) — بنّاءو التذاكر:
`invoices/presentation/thermal/invoice_thermal_ticket.dart`,
`payments/presentation/thermal/payment_thermal_ticket.dart`.

### 5.3 `WhatsAppLauncherService` + `WhatsAppTemplateProcessor`
* الوضعان: `click_to_chat` (رابط `wa.me`) و`meta_api`/`local_api`
  (`POST /{kind}/{id}/send-whatsapp`؛ `local_api` يستخدم
  `AppConfig.localWhatsappSendUrl`).
* الأولوية في الهاتف: `meter.tenant_phone` ثم `subscriber.phone`
  (`invoice.messageRecipientPhone`)؛ التنسيق عبر `PhoneFormatter.toWhatsappDigits`
  (مطابق `WhatsAppService::formatPhoneNumber`).
* `WhatsAppTemplateProcessor.render(template:, variables:, conditionalValues:)`
  يطبّق البلوكات `{if:key}…{/if:key}` (تُحذف عند `value <= 0`) ويُنظّم الأسطر الفارغة.
* المتغيرات المتوفّرة للفاتورة: `subscriber_name, bill_number, total_amount,
  pdf_url, period_start/end, meter_code, square, previous_reading, current_reading,
  consumption_units, unit_price, consumption_fee, subscription_fee, tanker_fee,
  previous_balance, credit_applied, collector, name`.
  للسند: `name, receipt_number, amount, invoice_remaining, outstanding_balance,
  credit_balance, pdf_link, date, method, bill_number, collector`.

### 5.4 `FinanceConfigRepository`
`cached` (بدون انتظار) و`refresh({force})` — يقرأ `GET /settings`، يطبّع المفاتيح،
ويفلت إلى الكاش المحلي ثم إلى الافتراضيات المطابقة لـ Laravel عند انقطاع الشبكة.

---

## 6. الأوفلاين

`PaymentsRepositoryImpl.collect` يتحقق من `NetworkInfo`؛ عند الانقطاع يُدرج
`SyncAction(type: 'invoice.pay' | 'payment.create', payload:, idempotencyKey:
'REC-LOCAL-<ms>')` في `SyncQueueRepository` (المفتاح `sync.queue.pending`) ويعيد
`CollectPaymentOutcome(queuedOffline: true)` مع رقم سند محلي مؤقت. أرقام السندات
الرسمية `REC-YYYYMM-XXXX` يولّدها الخادم وحده — لا توليد محلي لها.
`registerSyncQueueFallback(prefs)` يضمن وجود التسجيل حتى قبل دمج طبقة Dev 1
(Drift/Workmanager) — عند الدمج: احذفوا هذا الاحتياط واسحبوا `SyncQueueRepository`
الحقيقي.

---

## 7. ما ينقص الـ Backend (قائمة طلبات Dev 3)

`routes/api.php` يحتوي حاليًا 24 مسارًا فقط؛ الكود مكتوب على مواصفة الـ 68 مسارًا
(الخطة §2.3/§8.1) ويسقُط بلطف (قائمة فارغة/قيمة محلية) عند غياب المسار. المطلوب إضافته:

| المسار | المستعمل في |
|---|---|
| `POST /api/invoices/bulk-recalculate`, `POST /api/invoices/bulk-delete` | SCR-14 |
| `POST /api/invoices/generate-{meter,subscription,tanker}` | SCR-16 |
| `GET /api/invoices/{id}/pdf`, `GET /api/invoices/{id}/whatsapp-link`, `POST /api/invoices/{id}/send-whatsapp` | SCR-15 |
| `POST /api/invoices/{id}/pay` (يرجّع `receipt_data`) | CollectPaymentSheet |
| `PUT/DELETE /api/payments/{id}`, `POST /api/payments/bulk-delete`, `GET /api/payments/{id}/pdf` | SCR-17/18 |
| `GET/POST/PUT/DELETE /api/tariffs[/{id}]` + `?trashed=with|only` + `restore`, `force-delete` | SCR-21 |
| `GET /api/squares`, `GET /api/subscribers?with=meters`, `GET /api/subscribers/{id}/due-summary` | الفلاتر/النوافذ |

**مفاتيح `settings` غير معروضة في `GET /settings`** (تُستخدم في الواجهة وتحتاج مصدرًا):
`whatsapp_invoice_message_template` (موجود كـ `reading_invoice_message_template`)،
`whatsapp_payment_message` (يُقرأ من `payment_receipt_message_template`)،
`thermal_paper_width` و`thermal_print_logo` (موجودان)، لكن **غير موجود**:
`whatsapp_api_mode` (قيمة `whatsapp_method` مبدئية)، `whatsapp_template_enabled`
بمعنى صريح، وقوالب `subscription_invoice_message_template`/`tanker_invoice_message_template`
(مصدرها حاليًا الـ defaults في `FinanceConfig` فقط)، ومفاتيح Meta API
(`whatsapp_access_token`, `whatsapp_phone_number_id`) — لا تُرسل من الموبايل، بل
تُستعمل على الخادم عبر `POST /{kind}/{id}/send-whatsapp`.

**أخطاء صغيرة في الخادم اكتُشفت أثناء المطابقة (للاطلاع، لم أعدّلها):**
* `WhatsAppService::getInvoiceMessageUrl` يستبدل `{name}` بنفس قيمة
  `{subscriber_name}`؛ القوالب التي تستخدم `{name}` مع الفواتير تحتاج توحيدًا.
* `{pdf_link}` في رسائل السند يولَّد من `route('payments.print')` وهو رابط لوحة
  admin — يحتاج `signed` رابط عام أو `asset()` بديل.

---

## 8. ملاحظات الدمج مع بقية الفريق

1. `core/**` مملوك لـ Dev 1؛ أضفت ملفات **جديدة** فقط، وفي الملفات المشتركة
   (مثال: `core/di/injection_container.dart`) حُصرت تعديلاتي بين
   `// BEGIN [DEV-3]` / `// END [DEV-3]`.
2. ملفات Dev 3 «تعاقدية» وتُستبدل عند الدمج: `core/config/app_config.dart`,
   `core/theme/*`, `core/responsive/*`, `core/widgets/*`, `core/router/app_router.dart`,
   `core/auth/session_store.dart`. المطلوب من Dev 1: إبقاء `registerFinanceModule`
   وتمرير `SessionStore` + `SharedPreferences` كما في `bootstrap.dart`.
3. `lib/core/permissions/finance_permissions.dart` سُمّي كذلك (وليس
   `permission_guard.dart`) لتفادي تعارض الاسم مع نواة Dev 1؛ الأسماء المستخدمة:
   `FinanceAbilities`, `context.can(...)`, `PermissionGate`, `PermissionRouteShell`.
   عند إضافة Dev 1 حارسه: استبدل `PermissionRouteShell` به وأبقِ `FinanceAbilities`.
4. قرارات معمارية موثّقة: «Model-as-Entity» بدل `freezed` (لا توليد كود في خمسة فروع
   متوازية)، `GetIt` يدوي بدل `injectable`، و`flutter_bloc` فقط (Cubits/Blocs بلا
   `BlocListener` معقّد داخل الشاشات).
5. الخطة طلبت تقسيم Dev 3 إلى أربع مهام متوازية عبر `invoke_subagent`؛ لم تكن الأداة
   متاحة في جلسة التنفيذ، فنُفّذت المسارات الأربعة **تباعًا** بنفس الترتيب
   المنطقي: (أ) المحركات النوائية → (ب) طبقة البيانات/المحاسبة → (ج) الشاشات →
   (د) الاختبارات وهذا المستند. لا شيء من النطاق حُذف.

## 9. ما تبقى (دفعة تالية)

* `flutter test` لاختبارات الـ widget (لم تُكتب لأنها لا تُنفَّذ هنا): SCR-14 فلاتر،
  SCR-18 حقول مشروطة (`reference_number` يظهر/يُلزم)، و`InvoiceCard` متوافق مع
  أعمدة Filament.
* دعم شعار الطابعة (`thermal_print_logo`) كـ raster PNG من `assets/`.
* رفع PDF إلى Meta API (`uploadMedia`) عند تفعيل `meta_api` — حاليًا `send-whatsapp`
 خادمي فقط.
* استبدال `SyncQueueRepository` بطبقة Drift عند وصولها من Dev 1.
* `GET /invoices/search` بترتيب `priority` (اسم المستأجر أولًا) عندما يضيفه الخادم.
