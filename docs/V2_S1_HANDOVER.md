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

## 5. ما لم يُنفَّذ بعد (S2 وما بعدها)

- اختبارات Flutter لشاشة الصندوق (تدفق واجهة) — الموجود الآن اختبارات منطق وخدمة.
- دفع أحداث التدقيق من التطبيق بشكل دوري (`POST /audit-events` جاهز لكن لا يوجد
  مجمِّع دوري في العميل بعد).
- إشعارات Push لاعتماد الصناديق، وتقرير يومي مجدول.
- تصدير تقرير الصندوق إلى PDF من التطبيق.
- مؤشرات المراقبة الخارجية (Sentry/… ) — الواجهة `ErrorReporter` جاهزة للتبديل.
- إثبات تشغيل الاختبارات: يلزم تشغيل الأوامر أعلاه وتسجيل النتيجة قبل الانتقال
  إلى S2.
