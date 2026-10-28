# الإصدار 2.0 — دفعة S1 (الصندوق، الإلغاء، التدقيق، الجهاز)

> هذه الوثيقة تشرح ما نُفِّذ فعلياً في الشيفرة، وكيف يُشغَّل ويُتحقَّق منه، وما لم
> يُنفَّذ بعد. مرجع القرارات: `docs/V2_MOBILE_PLAN_AR.pdf` (الفصول 5.2، 5.3، 8.2، 9.1، 9.3، 9.4، 9.6، 9.7، 10.2).

---

## 1. ما أُنجز في هذه الدفعة

### أ) الخادم (Laravel)

| المكوّن | الملف | الوظيفة |
|---|---|---|
| جداول جديدة | `database/migrations/2026_10_24_000100..000400` | `cash_sessions`، `audit_events`، `devices`، وحقول الصندوق والإلغاء في `payments` |
| النماذج | `app/Models/{CashSession,AuditEvent,Device}.php` + `Payment.php` | حالات الصندوق، سكوب المنتظر للاعتماد، علاقات الملغِي والجهاز |
| خدمة الصندوق | `app/Services/CashSessionService.php` | فتح/إغلاق/اعتماد/رفض + ملخّص وتقرير، `variance = counted − expected` |
| سجل التدقيق | `app/Services/AuditLogger.php` | كتابة الحدث مع `before/after` والمستخدم والجهاز والمصدر؛ فشل التسجيل لا يُفشل الطلب |
| إلغاء السند | `PaymentService::voidPayment()` | السبب ≥ 10 أحرف، idempotent، حذف حركات دفتر الأستاذ، إعادة احتساب الفواتير والأرصدة |
| التحصيل داخل صندوق | `PaymentService::collectPayment()` | يربط السند بالصندوق المفتوح للمحصّل (`cash_session_id`) |
| واجهات API | `app/Http/Controllers/Api/{CashSession,AuditEvent,Device}Controller.php` | 12 مساراً جديداً (الجدول أدناه) |
| تجديد التوكن | `AuthController::refresh()` | يُدير التوكن ويحفظ الصلاحيات ويحذف القديم، ومدة الصلاحية من إعداد `api_token_ttl_hours` |
| إبطال الجهاز | `app/Http/Middleware/EnsureDeviceIsActive.php` | `X-Device-Id` مُبطَل ⇒ 401 بالرمز `DEVICE_REVOKED` |
| لوحة الإدارة | `app/Filament/Resources/CashSessions/*` | شاشة اعتماد الصناديق مع عدّاد في القائمة وسبب رفض إلزامي |
| لوحة الإدارة | `app/Filament/Resources/AuditEvents/*` | عارض سجل التدقيق (قراءة فقط، للمشرفين) |
| الصلاحيات | `database/seeders/CashSessionPermissionsSeeder.php` | `cash_sessions.manage`، `cash_sessions.approve`، `audit.view` |

### ب) التطبيق (Flutter)

| المكوّن | الملف | الوظيفة |
|---|---|---|
| تجديد الجلسة | `lib/core/network/token_refresh_service.dart` + `interceptors/auth_interceptor.dart` | محاولة تحديث واحدة مشتركة عند 401، إعادة الطلب مرة واحدة، تهدئة بعد الفشل، بلا حلقات |
| هوية الجهاز | `lib/core/network/device_identity.dart` + `interceptors/client_headers_interceptor.dart` | UUID ثابت في التخزين الآمن يُرسل في `X-Device-Id` مع الإصدار والنظام |
| المراقبة | `lib/core/diagnostics/error_reporter.dart` | تقرير داخلي بأخطاء محجوبة الهوية (أرقام/بريد)؛ الواجهة جاهزة لمزوّد خارجي لاحقاً |
| سياسة الإعادة | `lib/core/sync/sync_retry_policy.dart` | 30 ثانية → 2 د → 8 د → 30 د → 2 س → 6 س، و4xx دائم، و429 بحد أدنى دقيقتين |
| الطابور | `lib/features/offline_sync/**` | `nextAttemptAt`/`lastStatusCode`، حالة `retrying`، أنواع `payment.void` و`cash_session.close`، احترام مهلة التهدئة |
| قاعدة البيانات | `lib/core/database/app_database.dart` | ترقية المخطط إلى 3 (`next_attempt_at`, `last_status_code`) |
| الصندوق | `lib/features/cash_sessions/**` | نموذج + عميل API + خدمة تحجب التحصيل بلا صندوق + شاشة فتح/إغلاق وحساب الفرق |
| إلغاء السند | `lib/features/payments/**` | `voidPayment` في العقد والمستودع، وحوار سبب إلزامي من قائمة إجراءات السند |
| الأمان | `android/app/build.gradle.kts` + `res/xml/network_security_config.xml` | توقيع الإنتاج من `key.properties`، تصغير R8، ومنع HTTP خارج نسخة debug |
| التوجيه | `lib/core/router/*`, `module_catalog.dart`, `workspace_catalog.dart` | دخول إلى «صندوق المحصّل» من مراكز التحصيل والبحث الشامل |

---

## 2. سطح الـ API المضاف

| المسار | الطريقة | ملاحظات |
|---|---|---|
| `/api/refresh` | POST | يجدد التوكن؛ يحتاج ترويسة `Authorization` الحالية |
| `/api/payments/{id}/void` | POST | `reason` ≥ 10 أحرف؛ إعادة الطلب على سند ملغى لا تُغيّر شيئاً |
| `/api/cash-sessions` | GET | المحصّل يرى صناديقه، المشرف يرى الكل ويُفلتر |
| `/api/cash-sessions` | POST | فتح صندوق (`opening_amount`, `square_id`, `notes`) |
| `/api/cash-sessions/current` | GET | الصندوق المفتوح أو `cash_session: null` |
| `/api/cash-sessions/{id}` | GET | تفاصيل صندوق (الصاحب أو المشرف) |
| `/api/cash-sessions/{id}/close` | POST | `counted_amount` مطلوب، و`notes` تفسير الفرق |
| `/api/cash-sessions/{id}/approve` | POST | `approve: true|false` + `notes`؛ يتطلب صلاحية مشرف |
| `/api/cash-sessions/{id}/report` | GET | تقرير الصندوق المجمّع |
| `/api/audit-events` | GET | مفلتر ومُصفّح |
| `/api/audit-events` | POST | دفع أحداث من التطبيق (≤ 100)، `actor` يُفرض من الجلسة |
| `/api/devices` | GET/POST | تسجيل جهاز (العملية idempotent بـ `device_uuid`) |
| `/api/devices/{id}` | DELETE | إبطال الجهاز |
| `/api/tariffs/bulk-restore` / `bulk-force-delete` | POST | سد فجوة v1 رقم 1 |

---

## 3. أوامر التشغيل والتحقق (لم تُنفَّذ في بيئة العمل هذه)

> **مهم:** البيئة التي كُتب فيها هذا الكود لا تحتوي PHP ولا Composer ولا Flutter،
> لذلك **لم يُشغَّل أي اختبار بعد**. تم التحقق من الصياغة فقط (tree-sitter لـ PHP
> وDart، 20 ملف PHP و43 ملف Dart بلا أخطاء تركيبية). الأوامر التالية هي خطوة
> القبول الأولى عندك أو في CI.

### أ) الخادم

```bash
cd /path/to/water
composer install
cp .env.example .env    # لو لم يوجد .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=CashSessionPermissionsSeeder
php artisan test --filter=CashSession          # اختبارات الصندوق والإلغاء والتجديد والإبطال
php artisan test                               # لا تراجع في بقية الاختبارات
```

بعد الترحيل تأكد يدوياً من:

```bash
php artisan tinker --execute="dump(App\Models\CashSession::query()->pendingApproval()->count());"
php artisan route:list --path=api | grep -E "cash-sessions|void|refresh|audit|devices"
```

### ب) التطبيق

```bash
cd "mobile app"
flutter pub get
flutter analyze                                 # يجب أن يمر بلا أخطاء
flutter test test/sync test/core/network test/core/diagnostics \
             test/features/cash_sessions test/features/offline_sync test/core/config
flutter test                                    # الشريحة الكاملة
```

### ج) بناء نسخة الإنتاج (اختياري الآن)

```bash
cd "mobile app"
cp android/key.properties.example android/key.properties   # ثم املأ القيم
flutter build apk --release --dart-define=API_BASE_URL=https://water.example.com/api
```

`android/key.properties` و`*.jks` مستثناة في `.gitignore` — لا تُرفع أبداً.

---

## 4. قرارات تصميم متخذة في S1 (يلزم معرفتها للمراجعة)

1. **الإلغاء بدل الحذف:** `DELETE /payments/{id}` ما زال موجوداً لتوافق الواجهة
   الإدارية، لكن مسار الميدان الوحيد للتصحيح هو `void`. الواجهة تعرض «إلغاء السند
   بسبب مكتوب» بدل «حذف».
2. **صندوق واحد مفتوح لكل محصّل:** محاولة فتح ثانٍ تُرجع 422 برسالة عربية. لا
   يوجد مسار `POST /cash-sessions/open`؛ الفتح هو `POST /cash-sessions`.
3. **الفرق يُحسب على الخادم فقط:** التطبيق يعرض ويُدخل المعدود، لكن
   `expected_amount` و`variance_amount` يُحسبان من السندات غير الملغاة.
4. **الاعتماد للمشرف:** `state` ينتقل `open → closed → approved|rejected`.
   الرفض يبقي الفرق ظاهراً مع ملاحظة المشرف.
5. **معرّف الجهاز:** يُولَّد مرة واحدة ويُحفظ في التخزين الآمن، ويُرسل مع كل طلب؛
   إبطاله من لوحة الإدارة يقطع الوصول بـ 401 `DEVICE_REVOKED`.
6. **سياسة الإعادة:** الأخطاء 4xx (عدا 408 و429) لا تُعاد تلقائياً لأنها تحتاج
   تدخلاً بشرياً؛ 5xx والشبكة تتباعد أسّياً حتى 6 محاولات ثم تظهر «استُنفدت».
7. **HTTP في الإنتاج ممنوع** على مستوى Android (network security config) وعلى
   مستوى Dart (`AppConfig` يرفض أي رابط غير `https`)، مع استثناءات debug للشبكة
   المحلية أثناء التطوير.
8. **المراقبة بلا بيانات شخصية:** أي رسالة خطأ تُحجب أرقامها الطويلة وبريدها قبل
   التخزين، ولا يُرسل شيء لطرف ثالث في هذه الدفعة.

---

### ملحق: صقل ما بعد الدفعة الأولى

- `main.dart` لم يعد ينهار إذا كان العنوان المحفوظ HTTP في نسخة الإنتاج؛ يعرض
  شاشة ربط الخادم، وشاشة الربط تشرح شرط HTTPS برسالة صريحة.
- رسالة الجهاز المُبطَل تُعرض نصّاً عربياً من الخادم (`code: DEVICE_REVOKED`)
  بدل رسالة 401 العامة، ثم يُنهى التسجيل.

## 5. ما لم يُنفَّذ بعد (S2 وما بعدها)

- اختبارات Flutter لشاشة الصندوق (تدفق واجهة) — الموجود الآن اختبارات منطق وخدمة.
- دفع أحداث التدقيق من التطبيق بشكل دوري (`POST /audit-events` جاهز لكن لا يوجد
  مجمِّع دوري في العميل بعد).
- إشعارات Push لاعتماد الصناديق، وتقرير يومي مجدول.
- تصدير تقرير الصندوق إلى PDF من التطبيق.
- مؤشرات المراقبة الخارجية (Sentry/… ) — الواجهة `ErrorReporter` جاهزة للتبديل.
- إثبات تشغيل الاختبارات: يلزم تشغيل الأوامر أعلاه وتسجيل النتيجة قبل الانتقال
  إلى S2.

## 6. استكمال تطابق عمليات الويب والموبايل — 2026-10-26

هذا الملحق يصف تعديلات عقد API الإضافية؛ المسارات القديمة وأسماء حقولها باقية.
تحديث حالة الاختبارات الشاملة والدليل النهائي في `docs/MOBILE_PARITY_AUDIT_AR.md`
و`docs/v2/agents/backlog.json`؛ لا تُفهم مراجعة المصدر أو `php -l` على أنها
اختبار على جهاز ميداني.

### سجل التدقيق المركزي

- `GET /api/audit-events` يقبل صلاحية لوحة الويب `audit.view` إضافة إلى
  `ViewAny:AuditEvent` والاسم القديم `view_any_audit_event` وأدوار المدير القائمة.
  يبقى السجل للقراءة فقط، ولا يُضاف مسار تعديل أو حذف.
- الفلاتر: `q` يبحث في اسم الحدث واسم المستخدم؛ `event`، `source`
  (`mobile/api/web/console/cli`)، `actor_user_id` (والاسم القديم `user_id`)،
  `entity_type`، `entity_id`، `from_date` و`to_date`، مع `page/per_page`.
  نطاق التاريخ يستمر على `created_at` حفاظاً على العقد السابق.
- كل سجل يعيد الحقول السابقة ويضيف `occurred_at` و`ip_address` لعرض نفس معلومات
  لوحة الويب. كائن `filter_options` في جذر الاستجابة يحتوي
  `actors:[{id,name}]` و`events:[string]` و`entity_types:[string]` من السجل نفسه؛
  لذلك لا يحتاج قارئ السجل لصلاحية إدارة المستخدمين كي يفلتر بالأسماء.

### صناديق المحصّلين

- `GET /api/cash-sessions` يحافظ على فلاتر `state/user_id/from_date/to_date`
  والترقيم، ويضيف `filter_options.collectors:[{id,name}]` مستقلًا عن الفلاتر
  الحالية. قائمة الأسماء مقيدة بنطاق الصناديق المسموح للمستخدم برؤيتها.
- قرار `POST /api/cash-sessions/{id}/approve` يبقى
  `{approve:boolean,notes:string?}`. الرفض يلزم سبباً بعد إزالة المسافات الطرفية
  بطول 10 إلى 1000 حرف؛ الخطأ 422 في `notes`. ملاحظات الاعتماد اختيارية وبحد
  1000 حرف.
- لا يُقبل القرار إلا لصندوق `closed`، مثل زر الويب. تفحص الخدمة الحالة تحت
  `lockForUpdate` داخل المعاملة: الصندوق المفتوح يظل 422، والصندوق الذي سبق
  اعتماده/رفضه يرجع 409 ولا يُبدّل قراره بطلب قديم أو مكرر. حساب النقد والفرق
  وأثر الاعتماد/الرفض المالي لم يتغيرا. راجع A7 تعديل الخدمة مالياً.

### حفظ تصميم الفاتورة وصلاحية رفع الصور

- `GET/POST /api/settings/invoice` أصبح يعيد ويحفظ حقول فاتورة الاشتراك
  `sub_show_* / sub_lbl_*` و`site_favicon`؛ كانت ظاهرة في التطبيق والويب لكن
  مرشحات البادئات في API كانت تُسقطها بصمت.
- `POST /api/settings/upload-logo` يبقى `multipart` بالحقلين `key` و`file`.
  صاحب `Update:InvoiceSettings` يستطيع رفع `invoice_logo/invoice_watermark/site_favicon`؛
  صاحب `Update:ReceiptSettings` يستطيع رفع `invoice_logo/invoice_watermark/receipt_logo`.
  لا تسمح صلاحية صفحة الفاتورة بتعديل أي مفتاح صورة عام آخر؛ صلاحية
  `Update:Setting` القائمة تبقى لمسار الإدارة العامة.
- أسماء صلاحيات صفحات النظام المكتوبة بصيغة snake_case تُركب من الفعل والمورد
  منفصلين (`update_invoice_settings` و`view_any_audit_event`)؛ تُقبل الأسماء ذات
  الشرطتين السفليتين التي ولدها الكود القديم أيضاً للتوافق.

### حارس الأدوار وصلاحياته

- `GET /api/roles/permissions-matrix` يضيف `guard_names:[string]` من
  حراس `config('auth.guards')` الذين يملكون مزوّد مستخدمي المشروع، و
  `guard_permissions:{guard:[permission-name]}` لبناء
  حقل الحارس ومصفوفة صلاحياته في التطبيق. الإعداد الحالي يملك `web` فقط؛
  `permission.teams=false` ولذلك لا توجد عملية فريق مخفية مطلوبة في هذه البيئة.
  حارس Sanctum الرمزي الذي يضاف وقت التشغيل بلا مزوّد مستقل لا يُعرض كحارس
  صلاحيات إضافي.
- إنشاء وتعديل الدور يقبلان `guard_name` من الحراس المضبوطين فعلاً فقط، مع
  فحص تفرد الاسم داخل الحارس وحل الصلاحيات بالاسم أو الرقم داخل الحارس المختار.
  الصلاحية التابعة لحارس آخر تُرفض 422 وتُرجع المعاملة كاملة.
- تغيير حارس دور مرتبط بمستخدمين يُرفض 409. إذا كان الدور غير مرتبط لكنه يملك
  صلاحيات، يلزم إرسال `permissions` صراحة للحارس الجديد؛ لا تُنقل الصلاحيات
  بين الحراس ولا تُحذف تلقائياً. غياب القائمة في هذه الحالة يرجع 409.

### تحقق A6 المنفذ

مرّت فحوص `php -l` للملفات `ApiController.php` و`AuditEventController.php`
و`CashSessionController.php` و`SettingController.php` و`RoleController.php`
و`CashSessionService.php` دون أخطاء صياغة. اختبارات السلوك يتولى A10 تشغيلها
بقاعدة اختبار معزولة، وتسجل نتائجها في تقرير التطابق والـ backlog.

### ترتيب القوائم وترقيمها

قوائم API أدناه تقبل `sort_by` من الجدول و`sort_direction=asc|desc`.
إذا أُرسل الحقل وحده فالاتجاه `asc`. لاختيار الترتيب الافتراضي تُحذف المعلمتان؛
تبقى الترتيبات السابقة كما هي، مع إضافة مفتاح `id` عند الحاجة لمنع تبدّل ترتيب
الصفوف المتساوية بين الصفحات. عند اختيار ترتيب صريح يكون `id` بالاتجاه نفسه.
القيم الأخرى تعيد 422، ولا تُستخدم أسماء أعمدة أو تعابير SQL يرسلها العميل.
يُطبّق الترتيب على الاستعلام المسموح كاملاً قبل `paginate`، وتبقى فلاتر الصلاحيات
والمجاميع المحاسبية وأشكال الاستجابات القديمة كما هي.

| مسار GET (بعد `/api/`) | قيم `sort_by` المسموحة |
|---|---|
| `subscribers` | `id,name,subscription_start_date,balance,status,created_at,square_name,meters_count` |
| `meters` | `id,unique_code,path_number,tenant_name,status,created_at,subscriber_name,square_name` |
| `readings` | `id,reading_date,actual_reading_date,previous_reading,current_reading,consumption_units,created_at,meter_code,subscriber_name` |
| `invoices` و`invoices/unpaid` | `id,bill_number,bill_date,total_amount,amount_paid,status,created_at,beneficiary_name,subscriber_name,total_due` |
| `payments` | `id,receipt_number,payment_date,amount,created_at,subscriber_name,user_name` |
| `payments/void-requests` | `id,receipt_number,amount,void_requested_at,subscriber_name` |
| `subscription-fees` | `id,amount,status,created_at,subscriber_name,meter_code,invoice_number` |
| `service-requests` | `id,service_date,amount,status,created_at,subscriber_name` |
| `squares` | `id,name,created_at,subscribers_count` |
| `tariffs` | `id,name,unit_price,start_date,end_date,created_at,updated_at,deleted_at` |
| `transactions` | `id,created_at,amount,type,subscriber_name` |
| `users` | `id,name,email,email_verified_at,created_at,updated_at` |
| `roles` | `id,name,guard_name,created_at,updated_at,permissions_count` |
| `cash-sessions` | `id,session_date,expected_amount,counted_amount,variance_amount,closed_at,collector_name` |
| `audit-events` | `id,event,source,created_at,occurred_at,actor_name` |
| `settings/items` | `id,key,label,type,created_at,updated_at` |

الأسماء مثل `subscriber_name` و`collector_name` تُحل إلى علاقة معروفة داخل الخادم؛
الفرز لا يُضيف join يكرر الصفوف. `beneficiary_name` يرتب باسم المشترك كما في
الويب، و`total_due` بمجموع `total_amount + previous_balance` للعرض دون تعديل
الأرصدة. `occurred_at` اسم بديل لـ`created_at` في سجل التدقيق.
يستمر `page` و`per_page` (1 إلى 100، والافتراضي 25؛ unpaid افتراضيه 50).
`squares` و`transactions` يعيدان القائمة القديمة كاملة إذا لم يرسل `per_page`؛
عند إرساله يعيدان paginator المعتاد. لا يعتمد العميل على فرز صفحة جزئية ليحاكي
ترتيب كامل البيانات.

مرّ `php -l` للمتحكمات الخمسة عشر المشتركة و`SettingController` و`git diff --check`.
اختبارات ترتيب العلاقات والعدادات والمجاميع والحقول المرفوضة وثبات الصفحات
يكتبها ويشغّلها A10 ضمن اختبار التطابق؛ نتيجة التشغيل تُسجل في تقرير التدقيق.

---

## وضع مطور الهاتف (2026-10-27)

أُضيف PIN مبدئي `77288` إلى جدول `mobile_developer_settings` كتجزئة Hash، ولا
يُرسل الرقم إلى التطبيق. يغيّره مدير النظام من صفحة **إعدادات مطور الهاتف** في
Filament (`/admin/mobile-developer-settings`). تغييره يبطل منح المطور المفتوحة.
المنحة مؤقتة 15 دقيقة، مرتبطة بالمستخدم وتوكنه والجهاز، وتظل في ذاكرة التطبيق
فقط. وضع المطور لا يرفع صلاحيات المستخدم في بقية واجهات API.

| المسار | الطريقة | العقد والحماية |
|---|---|---|
| `/api/developer/verify-pin` | POST | جلسة Sanctum؛ `{"pin":"77288"}`؛ حد 5 محاولات/دقيقة؛ يعيد `authorized`, `grant`, `expires_at` عند النجاح |
| `/api/developer/config` | GET | جلسة Sanctum وترويسة `X-Developer-Grant`؛ يتحقق من سريان المنحة ويعيد `authorized: true` |
| `/api/developer/mobile-logs` | POST | الجلسة والمنحة؛ `logs` من 1 إلى 50، لكل عنصر `level`, `source`, `message`, `occurred_at`؛ حد 10 طلبات/دقيقة |

تظهر السجلات المرسلة للمدير فقط في **Mobile App Logs** (`/admin/mobile-app-logs`).
الإرسال يدوي من وضع المطور، ويقتصر العميل على طريقة الطلب والحالة والزمن ونوع
الخطأ؛ لا يرسل ترويسات المصادقة أو أجسام الطلبات والاستجابات. يخفي معترض سجلات
الشبكة رقم PIN والمنحة والرموز الحساسة قبل عرضها محلياً. مفاتيح إظهار واتساب
والتشخيص وسجلات API وبطاقة الخادم وإرسال السجلات تُغيّر من وضع المطور داخل
تطبيق الهاتف وتُحفظ على الجهاز. Laravel يغيّر PIN ويستقبل السجلات ويعرضها.

تغيير رابط الخادم يستعمل `ServerConnectionService`: يمنع التبديل مع عمليات
أوفلاين معلّقة، يمسح جلسة الخادم القديم وبياناته المؤقتة، ثم يطلب تسجيل الدخول
على الخادم الجديد. إذا تعطل الدومين القديم ولم يمكن التحقق من PIN، يبقى مدخل
إصلاح الرابط في شاشة تسجيل الدخول متاحاً. يتطلب إصدار الإنتاج HTTPS صالحاً.

قبل تشغيل هذه الإضافة: نفّذ `php artisan migrate` على خادم Laravel، ثم غيّر
PIN المبدئي من لوحة الإدارة. نجاح الاختبارات المحلية لا يثبت وصول جهاز فعلي أو
قبول إعدادات الإنتاج.
