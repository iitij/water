# تشفير قاعدة بيانات الميدان (SQLCipher) — S5-02

> **الحالة بصراحة: `partial`.**
> كود التشفير والترقية موجود ومُختبَر نحوياً، لكن **لم يُنفَّذ** `flutter pub get`
> ولا بناء على جهاز، ولم يُقَس أثر الأداء. لا تدّعي هذه البطاقة أنها مكتملة حتى
> تُنفّذ الخطوات في «التحقق» أدناه على جهاز حقيقي.

## ما نُفّذ في الكود

| الملف | الوظيفة |
|---|---|
| `lib/core/database/database_encryption.dart` | مفتاح 256-بت (base64Url) يُولَّد مرة ويُحفظ في `flutter_secure_storage` تحت `water.db.key`، `PRAGMA key` آمن ضد علامات الاقتباس، `PRAGMA rekey` لترقية ملف قائم، وكشف الملف غير المشفّر بسحر `SQLite format 3` |
| `lib/core/database/app_database.dart` | `AppDatabase.open(keyProvider:)` صار `async`، و`setup:` يمرّر `PRAGMA key` قبل أي استعلام، و`_encryptLegacyFile` يشغّل `PRAGMA rekey` في Isolate قبل فتح الملف بمفتاح |
| `lib/core/field_operations/field_operations_module.dart` | يمرّر `DatabaseKeyProvider` إلى `AppDatabase.open` |
| `lib/core/di/injection_container.dart` | يستخدم `SecureStorageDatabaseKeyProvider` افتراضياً |
| `pubspec.yaml` | استُبدل `sqlite3_flutter_libs` بـ`sqlcipher_flutter_libs: ^0.6.8` (SQLCipher 4.9 على Android) وحُذف `drift_flutter` غير المستخدم، وأُضيف `sqlite3` كاعتماد مباشر |
| `test/core/database/database_encryption_test.dart` | 11 اختباراً: توليد المفتاح، صياغة `PRAGMA key/rekey`، كشف الملف القديم، المزوّدات |

## التفعيل (على جهازك)

```bash
cd "mobile app"
flutter pub get
flutter analyze
flutter test

# بناء مشفّر
flutter build appbundle --release \
  --dart-define=ENCRYPT_LOCAL_DB=true \
  --dart-define=API_BASE_URL=https://your-host/api
```

بدون `--dart-define=ENCRYPT_LOCAL_DB=true` يبقى التطبيق على SQLite العادي بلا أي
تغيير سلوك (`NoopDatabaseKeyProvider`)، وهذا مقصود حتى لا تنكسر دورات التطوير
أو اختبارات `flutter test`.

## التحقق اليدوي على الجهاز

1. ثبّت البناء المشفّر، سجّل الدخول، ثم أنشئ عملية ميدانية (قراءة/تحصيل) لتُكتب
   بيانات في القاعدة.
2. اسحب الملف:
   ```bash
   adb shell run-as com.example.water_project ls -l files/water_offline.sqlite
   adb exec-out run-as com.example.water_project cat files/water_offline.sqlite > /tmp/db.bin
   ```
   (استبدل اسم الحزمة باسم حزمتك الفعلي.)
3. `head -c 16 /tmp/db.bin` يجب **ألا** يكون `SQLite format 3`.
4. افتح القاعدة بـ`sqlcipher` بالمفتاح نفسه (من `flutter_secure_storage` عبر شاشة
   التشخيص أو بأداة اختيار مفتاح) وتأكد من وجود الصفوف.
5. قِس أثر الأداء: زمن حفظ قراءة أوفلاين وفتح شاشة قائمة القراءات قبل/بعد
   التشفير، وسجّل الرقمين في تقرير البطاقة.

## الترقية من نسخة غير مشفّرة

`AppDatabase.open` يفحص أول 16 بايت: إن كانت `SQLite format 3` (أي ملف قديم غير
مشفر) يُنفّذ `PRAGMA rekey` قبل الفتح بمفتاح. **قبل التجربة على جهاز فيه بيانات
ميدان حقيقية، خذ نسخة احتياطية من الملف**، لأن `rekey` عملية كتابة على الملف.

## ما بقي

- تنفيذ الخطوات أعلاه وتسجيل أثر الأداء (شرط إغلاق البطاقة).
- إن كان البناء يستخدم `minSdk < 21` فسيلزم رفعه (SQLCipher يتطلب 21+).
- (اختياري) تمرير المفتاح عبر شاشة التشخيص لأغراض الاستعادة فقط، بحذر.
