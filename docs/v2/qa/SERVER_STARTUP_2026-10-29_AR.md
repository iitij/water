# إزالة صفحة إعداد الخادم من الإقلاع — 2026-10-29

طلب المالك فتح التطبيق مباشرة لأن تغيير الخادم متاح من وضع المطور، واستعمال
عناوين Laravel المحلية على Windows والمحاكي والهاتف.

## السلوك

- حُذفت `ServerSetupApp` واستدعاؤها من `main.dart`.
- يُقرأ `water.server_url` قبل تهيئة عملاء الشبكة وطابور المزامنة؛ وهو نفس
  المفتاح الذي يحفظه محرر الخادم في وضع المطور وشاشة الدخول.
- عند غياب الرابط المحفوظ: `API_BASE_URL` إن حُدّد وقت البناء، وإلا
  `http://localhost:8000/api` على Windows، و`http://10.0.2.2:8000/api` على Android.
- الهاتف الحقيقي يحتاج عنوان الكمبيوتر `http://<PC-LAN-IP>:8000/api` من
  محرر شاشة الدخول أو وضع المطور، مع اتصال الهاتف والكمبيوتر بالشبكة نفسها.
  لا يُفترض عنوان LAN ثابت ولا يُكتشف جهاز الخادم آلياً.
- الرابط المحفوظ غير الصالح يبقي عنوان الشبكة فارغاً حتى إصلاحه؛ لا تُنقل
  جلسة سابقة أو عمليات مالية معلقة تلقائياً إلى خادم افتراضي مختلف.
- release يقبل HTTP لـ localhost وloopback وIPv4 الخاص RFC1918؛ الخادم العام
  يحتاج HTTPS إلا عند تفعيل خيار البناء الاختباري الصريح السابق.
- يسمح إعداد Android بالاتصال غير المشفر لدعم عناوين LAN المتغيرة؛ تقيّد
  `AppConfig` اختيار عنوان API. بقيت الثقة بشهادات HTTPS صادرة عن النظام فقط.
- بقي منع تبديل الخادم مع عمليات أوفلاين معلقة وتنظيف الجلسة والكاش عند
  التبديل مسارين قائمين دون تعديل.

## التحقق المحلي

من مجلد `mobile app`:

```powershell
flutter test --no-pub --concurrency=1 test/core/config/app_config_test.dart test/core/setup/server_connection_service_test.dart test/core/l10n/app_strings_test.dart --reporter expanded
```

النتيجة: **27 PASS، exit 0**. منها اختبار حفظ رابط المطور وإعادة تحميله،
وأولوية المحفوظ وافتراضيات المنصات، وعزل الرابط غير الصالح، وحدود عناوين
HTTP في release، ومنع التبديل مع سند مالي معلق باستخدام قاعدة بيانات ذاكرة.
السجل: `mobile app/build/server-startup-focused-tests.log`.

```powershell
dart analyze lib test/core/config test/core/setup test/core/l10n
```

النتيجة: **No issues found!، exit 0**.
السجل: `mobile app/build/server-startup-analyze.log`.

```powershell
flutter test --no-pub --concurrency=1 --reporter expanded
```

النتيجة: **523 PASS، 26 SKIP، 1 FAIL، exit 1**. الفشل في الاختبار الموجود
محلياً قبل المهمة `test/qa_audit_20261029_screens/screen_smoke_test.dart`:
`settings hub permissions and navigation width 320.0`؛ أخطاء RenderFlex في
قائمة إعدادات بعرض ضيق، خارج ملفات هذا التعديل. لم يُعدّل ذلك الاختبار أو
الشاشة ضمن هذه المهمة. السجل: `mobile app/build/server-startup-tests.log`.

## حدود القبول

```powershell
flutter build windows --release --no-pub
powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\installer\build-installer.ps1 -SkipBuild
```

نجح بناء Windows (**99.7s، exit 0**) وتجهيز المثبت (**28.578s، exit 0**).
الملف `mobile app/build/installer/setup.exe` بحجم **22,811,200 بايت**؛ SHA256:
`6e9f547a62c1c674a88e21156d0f9f359bd0c9f67aed27259fbdbc12cec2542a`.
السجلان `server-startup-windows-build.log` و`server-startup-installer.log` داخل
`mobile app/build`. بُنيت الحزمة من مساحة العمل المشتركة بما فيها تغييرات
الأعمال المتزامنة؛ لم تُرجع تلك التغييرات أو تُعدّل ملفاتها ضمن هذه المهمة.

لم يكتمل الفحص البصري: أعادت أداة إطلاق Windows فتح النسخة المثبتة في
`C:\WaterProject` بدلاً من إثبات تشغيل مسار البناء الجديد، ثم أوقف المستخدم
Computer Use بمفتاح Escape؛ لم يصدر أي إدخال آخر للتطبيق بعد ذلك. لا ندعي
تحققاً بصرياً من نسخة release الجديدة أو اختبار تثبيت الحزمة المحدثة.

لم تُختبر هذه التغييرات على هاتف Android فعلي أو محاكي Android أو اتصال
API مصادق. نجاح الاختبارات وبناء Windows لا يعني قبول الأجهزة أو الإنتاج.
