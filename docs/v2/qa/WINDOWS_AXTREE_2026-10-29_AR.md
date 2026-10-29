# معالجة شجرة إتاحة تلميحات Flutter على Windows — 2026-10-29

المالكون: A1 + A9 + A10.

## البلاغ وحدود التشخيص

السجل المرفق يحتوي تكرار:

```text
Failed to update ui::AXTree, error: 41 will not be in the tree and is not the new root
```

طلبا `audit-events` و`admin/dashboard` أعادا HTTP 200. الرقم 41 معرّف عقدة إتاحة؛ السجل لا يحدد الشاشة التي أنشأتها، ولا يثبت انهيار التطبيق. لا نغيّر API أو البيانات المالية لمعالجة هذا البلاغ.

البيئة التي جرى التحقق عليها: Windows، Flutter 3.44.3، framework `e1fd963c6f`، Dart 3.12.2.

## السبب الذي أُعيد إنتاجه والإصلاح

في SDK المثبت، تضيف `OverlayPortal` مرجع `traversalParentIdentifier` حول محتواها. دمج مرجعي بوابتين في عقدة واحدة قد يفقد أحدهما، وعند ظهور التلميح تُرسل عقدة غير قابلة للوصول من جذر شجرة الاجتياز إلى المحرك. هذا المسار موثق في [بلاغ Flutter رقم 182444](https://github.com/flutter/flutter/issues/182444) و[تحليل تعارض معرفات الاجتياز رقم 190344](https://github.com/flutter/flutter/pull/190344).

أثبت التحقيق المحلي أن تجاور `Tooltip` معلوماتي و`AppIconButton` داخل صف في `ListView` ينتج تحديثات بعقد يتيمة قبل الإصلاح. تجاور زري `AppIconButton` فقط، مفعّلين أو معطّلين، لم يُعد المشكلة في الاختبار نفسه؛ لذلك لا ننسب كل أزرار التطبيق أو الشاشة المذكورة في السجل إلى سبب مثبت.

أضفنا `AppTooltip` بحد `Semantics(container: true)` خارج **كامل** `Tooltip`، كي لا تندمج مراجع بوابته مع بوابة مجاورة. استُخدم في زر الأيقونة المشترك، وأزرار الماسح، وتلميح حالة رمز واتساب. وفُصل كل إجراء في رأس `SubPageScaffold` بحد مستقل في التخطيطين العريض والضيق.

التلميحات المرئية والتسميات العربية والنقر محفوظة. لم نعطّل الإتاحة أو نخْفِ سجل الخطأ، ولم نعدّل Flutter SDK المشترك. التلميحات التي تنشئها مكونات Material داخليًا خارج رؤوس الصفحات لم تُستبدل.

## أدلة التحقق الفعلية

1. `flutter test --no-pub --concurrency=1 test/core/ui/windows_tooltip_semantics_test.dart --reporter expanded`
   - النتيجة: **5 PASS**، `All tests passed!`، exit 0.
   - يلتقط الاختبار `SemanticsUpdateBuilder.updateNode` ويفحص وصول كل عقدة محدّثة من جذر `childrenInTraversalOrder` بعد تطبيق التحديثات التراكمية. يتضمن تحققًا من قدرة المراقب نفسه على كشف عقدة يتيمة.
   - الحالات: تلميحان معلوماتيان، تلميح Material بجوار الزر المشترك، زران مفعّلان، وزران معطّلان. تشمل حركة الماوس المتكررة، ظهور التلميح وإخفاءه، فتح مسار وإغلاقه أثناء ظهوره، وجود الأسماء في الشجرة، وحفظ فعل النقر.
   - السجل المحلي: `mobile app/build/windows-axtree-semantics.log`.
2. `flutter test --no-pub --concurrency=1 test/core/ui/list_presentation_test.dart test/features/audit/audit_events_screen_test.dart test/core/theme/app_theme_palette_test.dart test/ui/water_experience_test.dart --reporter expanded`
   - النتيجة: **26 PASS**، `All tests passed!`، exit 0.
   - تشمل صلاحيات سجل التدقيق، تفاصيله وترقيمه وإعادة المحاولة، وتخطيط RTL الضيق مع تكبير النص، وإجراءات الواجهات المشتركة.
   - السجل المحلي: `mobile app/build/windows-axtree-regression.log`.
3. `dart analyze lib/core/ui/app_tooltip.dart lib/core/ui/app_controls.dart lib/core/navigation/sub_page.dart lib/core/widgets/barcode_scanner_screen.dart lib/features/settings/presentation/pages/whatsapp_settings_screen.dart`
   - النتيجة: `No issues found!`، exit 0. تحليل اختبار الانحدار كذلك مرّ بلا مشاكل.
4. `flutter build windows --debug --no-pub`
   - النتيجة: بناء ناجح، exit 0، `build/windows/x64/runner/Debug/water_project.exe`.
   - السجل المحلي: `mobile app/build/windows-axtree-build.log`.

## ما لم يُثبت بعد

لم يُعَد تشغيل جلسة المستخدم المصادق عليها أو السيناريو الأصلي بقارئ شاشة Windows فعلي. الاختبارات تثبت سلامة التحديثات المرسلة في الحالات المذكورة والبناء المحلي، وليست قبول جهاز أو إثبات غياب جميع أخطاء محرك Flutter. يجب إعادة تشغيل التطبيق بالكامل بعد إعادة البناء لأن شجرة الإتاحة الأصلية قد تبقى تالفة داخل نافذته القديمة.

حُفظت التغييرات المتزامنة الأخرى في إعداد الخادم والمثبت والتوثيق؛ لم تُضمَّن ضمن نتيجة هذا الإصلاح.
