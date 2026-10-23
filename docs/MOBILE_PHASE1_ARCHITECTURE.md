# معمارية المرحلة الأولى بعد الخطة الرئيسية — التوحيد والمزامنة الآمنة

> هذه الوثيقة تصف تغييرات ما بعد `FLUTTER_MOBILE_MASTER_PLAN.md`: توحيد طوابير
> المزامنة، عزل الحسابات، منع ازدواج السندات، ضبط صلاحيات الواجهة الرئيسية،
> وإتاحة تغيير الخادم بأمان. تخص المطورين العاملين على `mobile app/` والـ API.

---

## 1. طابور مزامنة واحد (Drift / SQLite)

قبل هذه المرحلة كانت هناك ثلاث طوابير منفصلة:

| الطابور | التخزين | المستخدم في |
| --- | --- | --- |
| `SharedPreferencesSyncQueueRepository` | SharedPreferences | `SCR-28`، الشاشة الرئيسية |
| `core/database/sync_queue_manager.dart` | Drift | القراءات الميدانية (Dev 2) |
| `LocalSyncQueueRepository` | SharedPreferences | التحصيل الأوفلاين (Dev 3) |

الآن يوجد **طابور واحد** في جدول `pending_sync_actions` داخل
`water_offline.sqlite`:

- `features/offline_sync/data/drift_sync_queue_repository.dart` يطبّق واجهة
  `SyncQueueRepository` فوق `AppDatabase`، ويُسجَّل مرة واحدة في
  `core/di/injection_container.dart`.
- `core/finance/finance_module.dart` يربط `FinanceSyncQueueRepository` بمحوّل
  `UnifiedFinanceSyncQueueAdapter` الذي يكتب في نفس الطابور عبر
  `SyncQueueManager.enqueuePayment`.
- `FieldOperationsModule.initialize(autoStartSync: false)` يمنع محرك المزامنة
  القديم من العمل بالتوازي: **مدير مزامنة واحد فقط** يستمع للشبكة ويعيد الإرسال
  بترتيب FIFO صارم.
- `AppDatabase.onSyncQueueChanged` بثّ داخلي يجعل `SyncQueueManager` و`SCR-28`
  والشاشة الرئيسية تتحدّث فوراً عند أي إضافة أو تغيير من أي وحدة.

### ترحيل البيانات القديمة

`DriftSyncQueueRepository.migrateLegacyQueuesIfNeeded()` يعمل مرة واحدة عند
الإقلاع:

1. يقرأ `water.pending_sync_actions.v1` (طابور Dev 1) ويحوّل كل عنصر إلى صف في
   `pending_sync_actions` مع توليد UUID صالح عند الحاجة.
2. يقرأ `sync.queue.pending` (طابور Dev 3 المالي) ويحوّله بنفس الطريقة مع حفظ
   المفتاح المحلي في `local_receipt_number`.
3. يحذف مفاتيح SharedPreferences القديمة **بعد** نجاح الكتابة فقط.

أي صف تالف يُتجاهل وحده ولا يُسقط بقية الطابور.

---

## 2. عزل الحسابات (Account Isolation)

- كل صف في `pending_sync_actions` يحمل `owner_user_id` (عمود جديد، ترقية مخطط
  `schemaVersion` من 1 إلى 2 تتم بـ `ALTER TABLE` آمن).
- `SyncQueueManager._send` يرفض إرسال عملية تخص مستخدماً آخر:
  «هذه العملية محفوظة لحساب مستخدم آخر…».
- الصفوف القديمة بلا مالك تُنسب إلى المستخدم المسجّل الحالي عند أول مزامنة، حتى
  لا تعلق عمليات سابقة للأبد.
- `ReadingsRepositoryImpl` يمرّر `ownerUserIdProvider` من `SessionService` عند
  حفظ القراءة أوفلاين.

---

## 3. منع ازدواج السندات والقراءات (Idempotency)

- **الدفعات**: `PaymentsRepositoryImpl` يولّد `sync_uuid` (UUID v4) **قبل** محاولة
  الشبكة ويعيد استخدامه في الطابور عند السقوط، ويُرسله في
  `POST /api/payments` و`POST /api/invoices/{id}/pay`. الخادم
  (`PaymentService::collectPayment`) يرجع السند الموجود لنفس `sync_uuid` بدل
  إنشاء سند مكرر.
- **القراءات**: حمولة القراءة تحمل `sync_uuid` و`client_action_id` و`local_id`،
  و`ReadingController::createReading` يستنتج `sync_uuid` من
  `client_action_id` أو `local_id` أو ترويسة `Idempotency-Key` إن لم يُرسَل
  صريحاً، ثم يعيد `client_action_id` داخل كل نتيجة في `results[]` ليربطها
  التطبيق بصفّه المحلي بدقة.
- **الصور**: `sync-batch` يقبل الآن `image_base64` + `image_filename` إضافة إلى
  `image` متعدد الأجزاء، فتُرفع صور الميدان من الطابور دون فقدانها.

---

## 4. صلاحيات الواجهة الرئيسية

`HomeScreen` و`AppHomeShell` يعرضان الآن البطاقات وأزرار الشريط العلوي حسب
صلاحيات Shield الفعلية:

- القارئ الميداني: `Create:Reading` / `Update:Reading`.
- المحصل السريع: `Create:Payment` / `payments.collect`.
- لوحة القيادة: `View:StatsOverview` أو أي ودجة إحصائية.
- بطاقات المشتركين/المربعات/الرسوم/الطلبات/الأستاذ: `ViewAny:*`.
- الإعدادات/المستخدمون/الأدوار/التصدير: صلاحيات `View:*Settings`،
  `ViewAny:User`، `ViewAny:Role`، `View:ExportDataPage`.

`PermissionService` توسّعت بجسور بين صيغة Shield (`ViewAny:Tariff`) والصيغة
القديمة (`tariffs.view`)، فلا تختفي بطاقة بسبب اختلاف التسمية بين الخادم
والتطبيق.

---

## 5. تغيير عنوان الخادم بأمان

`core/setup/server_config_sheet.dart` يوفّر شاشة موحّدة (من شاشة الدخول ومن
الملف الشخصي):

1. يتحقق من صيغة الرابط ويرفض `localhost` على أندرويد.
2. يمنع التبديل إذا كان `getPendingActionCount() > 0` أو كان مدير المزامنة يحمل
   عمليات معلقة.
3. عند النجاح: تسجيل خروج للجلسة الحالية، مسح لقطات الخادم من SQLite
   (`clearCachedServerData`)، مسح مفاتيح `dev5.*`/`finance.*`، تحديث
   `Dio.options.baseUrl` و`AppConfig`، ثم حفظ الرابط الجديد.

لا حاجة بعد اليوم لمسح بيانات التطبيق من إعدادات النظام، ولن تنتقل أي عملية
مالية معلقة إلى خادم آخر.

---

## 6. بوابات الجودة

`.github/workflows/mobile.yml` يشغّل الآن على `main`/`master`/`develop` وكل
فروع `arena/**` وسحب الطلبات:

- `flutter analyze --no-fatal-infos --no-fatal-warnings` (الأخطاء فقط تُفشل).
- `flutter test` على **أندرويد وويندوز**.
- بناء APK وZIP ونشر إصدارات اختبار (يُتخطى تلقائياً في سحب الطلبات).

التحذيرات المتبقية (Deprecations قديمة) موثّقة كعمل لاحق حتى تُصفَّر ثم يُرفع
`--fatal-warnings`.
