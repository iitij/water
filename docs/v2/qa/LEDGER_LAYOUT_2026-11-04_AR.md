# إصلاح فتح دفتر الأستاذ — 2026-11-04

أبلغ المستخدم عن أخطاء `mouse_tracker.dart: !_debugDuringDeviceUpdate` و`Cannot hit test a render box that has never been laid out` عند فتح دفتر الأستاذ.

## السبب والإصلاح

أعاد اختبار الشاشة إنتاج فشل التخطيط الأولي: `An InputDecorator ... cannot have an unbounded width` داخل `DropdownButtonFormField<String>`؛ السجل `mobile app/build/ledger-before.log` (exit 1). يحتوي شريط الفلاتر على صف داخل تمرير أفقي، ويترك عرض أبنائه غير محدود، بينما يحتاج حقل القائمة إلى عرض محدود.

حُدّد عرض فلتر الحساب بـ240 وفلتر نوع القيد بـ200 في `transactions_screen.dart` مع إبقاء التمرير الأفقي. حُفظت تعديلات الملفات الموجودة مسبقاً في الشجرة المشتركة، ومنها عرض معرّف المربع. لم يتغيّر عقد API أو السلوك المحاسبي.

## التحقق

- `flutter test --no-pub test/features/transactions/transactions_screen_test.dart --reporter expanded`: **3 PASS، exit 0**؛ السجل `mobile app/build/ledger-focused.log`.
- تغطي الاختبارات فتح المسار من زر دفتر الأستاذ، حالة التحميل، وصول الحركات، تحريك مؤشر الماوس، الوصول للفلاتر بالتمرير الأفقي، تغيير الحساب إلى الحساب العام وتغيير النوع إلى تسوية مع حفظ فلتر الحساب، وإزالة الشاشة دون أخطاء Flutter. الأحجام: 320 و390 و1280؛ واجهة عربية RTL. بيانات الاختبار محلية، دون طلب API حي.
- `flutter test --no-pub --concurrency=1 --reporter expanded`: **635 PASS / 26 SKIP / 2 FAIL، exit 1**؛ السجل `mobile app/build/ledger-full-tests.log`. الفشلان في `test/ui/water_experience_test.dart`: `fees keep filters and rows usable at 320.0, dark=false/true` عند تكبير النص 1.6، برسالة `A RenderFlex overflowed by 17 pixels on the right`. يبني هذان الاختباران شاشة رسوم الاشتراك، ولا يفتحان دفتر الأستاذ. ملف اختبار الواجهة وشاشة الرسوم معدّلان في الشجرة المشتركة خارج هذا الإصلاح؛ لم نعدّلهما هنا. لم تُعَد المجموعة الكاملة بعد ذلك لعدم وجود تعديل إضافي في الكود.
- `flutter analyze --no-pub`: **No issues found، exit 0** (57.5 ثانية)؛ السجل `mobile app/build/ledger-analyze.log`.
- `flutter build apk --debug --no-pub`: **PASS، exit 0** (Gradle: 56.6 ثانية)؛ السجل `mobile app/build/ledger-android.log`. الناتج: `mobile app/build/app/outputs/flutter-apk/app-debug.apk`. ظهرت تحذيرات ترحيل Kotlin Gradle Plugin مستقبلية؛ البناء الحالي ناجح.

هذه نتائج محلية للشجرة المشتركة في وقت الفحص. لا تثبيت على جهاز المستخدم أو قبول ميداني ضمن هذا التحقق.
