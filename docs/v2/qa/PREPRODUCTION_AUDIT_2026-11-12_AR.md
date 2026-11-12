# تقرير المراجعة التقنية وسجل إصلاحات ما قبل الإنتاج — Water

> **قرار صاحب المشروع في 2026-11-12:** المشروع لا يزال في **مرحلة التطوير** وليس للإنتاج. تُوثَّق الملاحظات ويُفتح لها عمل منظّم، لكن **لا يبدأ إصلاحها حالياً**؛ تُعالَج وتُختبر عند **بوابة ما قبل الطرح للإنتاج**. هذا القرار يتعلق بجدولة الإصلاح، وليس بإثبات أمان النظام أو السماح بنشره.
>
> **حالة البنود الجديدة:** `0 done / 12 todo (deferred until pre-production)`. لم يُنفّذ أي إصلاح لهذه البنود أثناء إعداد التقرير.

## 1. معلومات التدقيق وحدوده

- **المستودع:** `5krm/water` (خاص)، **الفرع:** `main`.
- **لقطة الشيفرة المفحوصة قبل إضافة التقرير:** `52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6`، بتاريخ المراجعة 2026-11-12.
- **الأسلوب:** قراءة شجرة Git والمكونات وملفات التهيئة والاختبارات والمواضع البرمجية ذات الصلة عبر GitHub.
- **لم يُنفّذ:** تشغيل فعلي لـ PHP/Flutter، اختبارات اختراق، فحص قاعدة بيانات حية، اختبار على هاتف/خادم، تدقيق شامل لكل السطور أو إثبات استغلال للثغرات.
- **معنى التصنيف:** مخاطر مستنتجة من السلوك الظاهر في الشيفرة وقد تتغير خطورتها بحسب البيئة والصلاحيات الفعلية؛ تتطلب اختبارات قبول قبل الإغلاق.

## 2. صورة النظام

المشروع يتضمن:
1. **الخادم:** Laravel 13 / PHP 8.3، لوحة Filament 5، API باستخدام Sanctum، MySQL، Spatie Permissions، وظائف المحاسبة والفوترة والمشتركين والعدادات والتحصيل وسجلات التدقيق والتكامل.
2. **العميل:** Flutter/Dart (Android وWindows، وتكوينات منصات أخرى)، Drift/SQLite، عمل دون اتصال وطابور مزامنة، حفظ التوكن في التخزين الآمن.
3. **الإدارة والتوثيق:** خطط عربية للإصدار 2.0، تنظيم فريق وكلاء A1–A10 في `AGENTS.md`، وتتبع أعمال التطوير في `docs/v2/agents/backlog.json`.

في اللقطة المفحوصة: **21 ملف اختبار Laravel، و112 ملف اختبار Flutter تحت test، وملف Flutter integration_test واحد**. العدد ليس نسبة تغطية ولا دليلاً على نجاح جميع الاختبارات. لم تظهر أي ملفات `.github/workflows/` في شجرة اللقطة، رغم ذكر CI في `mobile app/README.md`.

## 3. سجل مخاطر الإصدار (12 مهمة مفتوحة مؤجّلة)

| المهمة / GitHub Issue | خلاصة المشكلة | مستوى الخطورة عند فتح البوابة | الحالة | الفريق المقترح | إصلاح مُثبت؟ |
|---|---|---|---|---|---|
| [PREPROD-01](https://github.com/5krm/water/issues/19) | مستندات مالية تُطبع دون auth/تفويض | حرجة | `todo` — مؤجّلة | A2+A6+A10 | لا |
| [PREPROD-02](https://github.com/5krm/water/issues/20) | تنقيح سجلات API وتأمين وصولها | حرجة | `todo` — مؤجّلة | A2+A6+A10 | لا |
| [PREPROD-03](https://github.com/5krm/water/issues/21) | حساب مدير تجريبي وseed في الحاوية | حرجة عند النشر | `todo` — مؤجّلة | A3+A6+A10 | لا |
| [PREPROD-04](https://github.com/5krm/water/issues/22) | تدقيق النسخ SQL وبيانات الاعتماد في Git | عالية | `todo` — مؤجّلة | A2+A3+A6 | لا |
| [PREPROD-05](https://github.com/5krm/water/issues/23) | منع تجاوز إبطال الجهاز/ربط التوكن | عالية | `todo` — مؤجّلة | A2+A6+A10 | لا |
| [PREPROD-06](https://github.com/5krm/water/issues/24) | تشفير قاعدة SQLite في الإنتاج | عالية | `todo` — مؤجّلة | A5+A2+A3+A10 | لا |
| [PREPROD-07](https://github.com/5krm/water/issues/25) | ضبط الحذف المحاسبي ومسار الإلغاء | عالية | `todo` — مؤجّلة | A7+A6+A10 | لا |
| [PREPROD-08](https://github.com/5krm/water/issues/26) | CI وإلزام توقيع نشر حقيقي | عالية | `todo` — مؤجّلة | A3+A10 | لا |
| [PREPROD-09](https://github.com/5krm/water/issues/27) | الدقة النقدية بدلاً من float | متوسطة | `todo` — مؤجّلة | A7+A6+A10 | لا |
| [PREPROD-10](https://github.com/5krm/water/issues/28) | معدل محاولات تسجيل الدخول | متوسطة | `todo` — مؤجّلة | A2+A6+A10 | لا |
| [PREPROD-11](https://github.com/5krm/water/issues/29) | تقييد دخول لوحة Filament | متوسطة | `todo` — مؤجّلة | A2+A6+A10 | لا |
| [PREPROD-12](https://github.com/5krm/water/issues/30) | HTTPS وضبط cleartext في إنتاج Android | متوسطة | `todo` — مؤجّلة | A2+A3+A10 | لا |

**ملحوظة مهمة:** تعني `todo` أن إصلاح المشكلة **غير منفذ أو غير مثبت باختبارات قبول خاصة به**؛ ولا يُنقل إلى `done` لمجرد وجود خدمات وقائية أو اختبارات لوظائف مجاورة. أما كلمة «مؤجلة» فهي **سياسة الجدولة** وليست حالة تقنية تدّعي إنجازاً.

### 3.1 مشكلات حرجة أو تمنع إطلاق الإنتاج

**PREPROD-01 — مستندات مالية دون مصادقة/تفويض:**
- في [`routes/web.php` الأسطر 13–49](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/routes/web.php#L13-L49) مساران لطباعة الفاتورة والسند، معلّقان على `web` فقط، دون `auth` أو Policy خاصة بالمستند. هذا يعرّض بيانات مالية للكشف حسب وصول الشبكة ومعرفة المعرّفات.
- عند البوابة: يجب منع الزائر وغير المخوّل والتحقق من نطاق المستند والاختبارات.

**PREPROD-02 — تسرب توكنات عبر سجلات API والصلاحيات:**
- [`LogApiRequests.php` الأسطر 63–92 و180–202](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/app/Http/Middleware/LogApiRequests.php#L63-L92) يلتقط `response_preview` من محتوى JSON، بينما [`AuthController.php` الأسطر 40–45](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/app/Http/Controllers/Api/AuthController.php#L40-L45) يعيد `access_token`. تُقرأ/تُمسح السجلات عبر [`routes/api.php` الأسطر 226–230](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/routes/api.php#L226-L230) دون authorization متخصص ظاهر في `SystemLogController`.
- عند البوابة: تنقيح الاستجابات والحمولات والـURLs وتقارير الأعطال، عزل صلاحيات قراءة/حذف السجلات، وتقييم رموز قديمة.

**PREPROD-03 — تهيئة إدارية تجريبية غير آمنة للإنتاج:**
- [`DatabaseSeeder.php` الأسطر 24–30](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/database/seeders/DatabaseSeeder.php#L24-L30) ينشئ مديراً بكلمة مرور تجريبية معروفة؛ [`docker/Dockerfile` السطر 29](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/docker/Dockerfile#L29) يجري `db:seed --force` عند التشغيل. `User::create` ليس idempotent عند إعادة البذر.
- عند البوابة: منع استخدام الحساب التجريبي وبذر البيانات الافتراضية في الإنتاج، وتثبيت مسار تهيئة سرّية وآمنة.

### 3.2 مسائل عالية الأولوية

**PREPROD-04 — ملفات SQL واعتمادات محتملة في Git:**
- تتضمن اللقطة `2026-10-29.sql` و`water_project (4).sql` و`water_project4.sql` و`login_body.json` و`sub_5_response.json`، إضافة إلى `ArchivedDataSeeder.php`. ظهر في الأمثلة بريد وكلمة مرور غير فارغين وبيانات مشترك؛ **لم نثبت** أن البيانات حقيقية أو أن كلمة المرور صالحة. لم تُنسخ القيم في هذا التقرير.
- عند البوابة: تصنيف البيانات وتنقيحها، مراجعة سجل Git، تدوير أي اعتماد صالح، ونشر فحص أسرار.

**PREPROD-05 — إبطال الأجهزة:**
- [`EnsureDeviceIsActive.php` الأسطر 18–37](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/app/Http/Middleware/EnsureDeviceIsActive.php#L18-L37) يسمح بمرور الطلب في غياب `X-Device-Id`. هوية الجهاز لا ترتبط بصورة كافية بالتوكن من جهة الخادم.
- عند البوابة: ربط التوكن بالجهاز وإبطال الجلسات المرتبطة به، واختبار حالات الترويسة المفقودة أو المعدلة.

**PREPROD-06 — تشفير قاعدة الموبايل:**
- [`database_encryption.dart` الأسطر 7–26](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/mobile%20app/lib/core/database/database_encryption.dart#L7-L26) يصرّح بأن SQLCipher غير مفعّل افتراضياً ويستخدم مفتاح بناء اختياري؛ `pubspec.yaml` يتضمن SQLite العادي.
- عند البوابة: تشفير مثبت في إصدار الإنتاج، ترحيل واختبار الاستعادة دون فقد بيانات، ومنع الرجوع إلى تخزين غير مشفر بصمت.

**PREPROD-07 — حذف السجلات المحاسبية:**
- [`PaymentController.php` الأسطر 272–292](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/app/Http/Controllers/Api/PaymentController.php#L272-L292) يسمح بالحذف المنطقي بجانب دورة إلغاء/اعتماد مستقلة؛ [`InvoiceController.php` الأسطر 332–378](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/app/Http/Controllers/Api/InvoiceController.php#L332-L378) يسمح بالحذف النهائي بعد فحص صلاحية. لا يعني ذلك عدم وجود صلاحيات، بل ضرورة توحيد السياسة المالية وعدم فقد الأثر.
- عند البوابة: توثيق قواعد إلغاء/استرداد/حذف وإخضاع السجلات المحاسبية للتدقيق والاختبارات.

**PREPROD-08 — CI وتوقيع الإصدارات:**
- لا يوجد `.github/workflows/` في شجرة اللقطة، و[`build.gradle.kts` الأسطر 62–79](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/mobile%20app/android/app/build.gradle.kts#L62-L79) يسمح بالتوقيع بمفتاح debug عند غياب مفتاح الإنتاج.
- عند البوابة: Workflow قابل لإعادة الإنتاج، نتائج اختبار خضراء، وتوقيع إنتاجي إلزامي للملفات المنشورة.

### 3.3 مسائل متوسطة الأولوية

**PREPROD-09 — الدقة المالية:** تستخدم `PaymentService.php` و`InvoiceService.php` و`TransactionService.php` النوع `float` لمبالغ نقدية. اختبارات الدقة والتقريب وتسوية الرصيد مطلوبة قبل الإنتاج.

**PREPROD-10 — الحماية من تكرار تسجيل الدخول:** [`routes/api.php` السطر 32](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/routes/api.php#L32) لا يضع `throttle` متخصصاً على `/login`. يجب اختبار سياسة تقليل المحاولات. وقد توجد حماية على طبقة نشر خارجية لم تُفحص.

**PREPROD-11 — دخول لوحة Filament:** [`app/Models/User.php` الأسطر 45–49](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/app/Models/User.php#L45-L49) يعيد `true` من `canAccessPanel`، رغم وجود سياسات موارد منفصلة. يلزم فصل حق دخول اللوحة عن صلاحيات كل وظيفة.

**PREPROD-12 — HTTPS:** [`network_security_config.xml`](https://github.com/5krm/water/blob/52d589dae7eaa04cf2db63cea2fe97c50ad4d5d6/mobile%20app/android/app/src/main/res/xml/network_security_config.xml) يسمح `cleartextTrafficPermitted=true`، و`AppConfig` يسمح HTTP في بيئات خاصة/اختبارية. يجب اختبار السياسة النهائية للإنتاج وتطابق إعدادات Android وDart.

## 4. ما أُنجز فعلاً في المشروع (ضوابط قائمة لا تُغلق المخاطر)

هذه **مكونات موجودة بالشيفرة** ولا تمثل إنجاز الإصلاحات 01–12:

| عنصر موجود | دليل الشيفرة | وضعه بالنسبة لمهام التقرير |
|---|---|---|
| حماية مجموعة API الأساسية بـ Sanctum | `routes/api.php` | موجود؛ لا يحمي تلقائياً مسارات الويب العامة |
| أدوار وصلاحيات Spatie و`authorizeApi` | `app/Http/Controllers/Api/ApiController.php` | موجود؛ يحتاج تغطية وتوحيد على كل المسارات |
| مسار إلغاء/اعتماد سند القبض مع Audit | `PaymentService.php`، `PaymentVoidApprovalTest.php` | موجود؛ لا يغلق PREPROD-07 |
| `sync_uuid` فريد لعمليات التحصيل | Migration `2026_10_23_000002_...` | موجود؛ يحتاج تحقق مزامنة وتزامن فعلي |
| حفظ توكن Flutter في `flutter_secure_storage` | `secure_storage_service.dart` | موجود؛ لا يثبت ربط الجهاز أو تشفير قاعدة SQLite |
| اختبارات Flutter وLaravel | `mobile app/test/` و`tests/` | موجودة؛ اجتيازها الكامل في بيئة قبول غير مثبت هنا |
| ملفات تثبيت الاعتماديات | `composer.lock`، `package-lock.json`، `mobile app/pubspec.lock` | موجودة؛ دون إثبات CI للإصدار |

**النتيجة الصادقة للمهام الجديدة:** `0 done` و`0 doing` و`12 todo/deferred`. لا يُعد أي عنصر أعلاه دليلاً على إغلاق مهمة حتى يُتحقق من شرط قبولها الخاص.

## 5. سياسة التأجيل وبوابة الإصدار

1. **خلال التطوير الحالي:** الاستمرار في الميزات والتصميم والتجارب والاختبارات العادية حسب خطة التطوير؛ تبقى مهام `PREPROD-*` مفتوحة في GitHub، وموسومة وصفياً بمرحلة `pre-production`، وبحالة `todo`.
2. **عند بدء الاستعداد للطرح:** إعادة فحص الشيفرة الحالية، تحديث شدة المخاطر، واختبار إعادة الإنتاج دون افتراض استمرار المشكلة أو حلها.
3. **قبل الموافقة على النشر:** يجب أن يجتاز كل بند بوابة القبول، مع نتيجة اختبار ومرجع commit/PR، وتحديث الحالة في `docs/v2/agents/backlog.json`. المخاطر الحرجة والعالية غير المقبولة تمنع قرار الإطلاق.
4. **خلال التطوير مع بيانات فعلية أو خادم مكشوف للإنترنت:** قرار التأجيل لا يجعل البيئة آمنة؛ يُنصح بعدم استخدام بيانات عملاء حقيقية أو تعريض هذه النسخة للعامة. أي تعرّض فعلي أو تسرب مشتبه به يستدعي إعادة تقييم عاجلة ولا ينتظر تاريخ الإطلاق.
5. **لا حذف أو تحوير صامت للتاريخ:** وثّق المشكلة والحل والدليل، ثم أغلق GitHub Issue عند تحقق القبول فقط.

## 6. كيفية تحديث الحالات

- **المصدر التشغيلي:** `docs/v2/agents/backlog.json`، البنود `PREPROD-01`…`PREPROD-12`، مع `status: "todo"` و`phase: "pre-production"` و`deferred: true`.
- **المصدر البشري القابل للنقاش:** [GitHub Issues 19–30](https://github.com/5krm/water/issues?q=is%3Aissue+is%3Aopen+PREPROD).
- **بداية التنفيذ لاحقاً:** تُحوَّل المهمة إلى `doing` ويُرفع عنها التأجيل بعد قرار صريح بالبدء.
- **الإغلاق:** لا `done` بلا دليل اختبارات وقبول قابل للتتبع؛ احتفظ برابط PR والنتيجة وتاريخ الاعتماد.

---

**هذا تقرير توثيقي فقط. لا تغييرات على التطبيق ولا إصلاحات أمنية أو محاسبية أُجريت ضمن هذه الإضافة.**
