# استجابة بطاقات مؤشرات الرئيسية ولوحة القيادة — 2026-11-04

السبب: بطاقات `AppMetricCard` كانت تُنشأ دون `onTap` في كل من
`home_metrics.dart` و`stats_overview_grid.dart`، فتظهر الأرقام دون إجراء ضغط.

## الإصلاح

- مشاركة `StatsOverviewGrid` بين الرئيسية ولوحة القيادة لمنع اختلاف السلوك.
- المشتركون النشطون ← `/subscribers?status=active`؛ يبدأ Cubit بفلتر النشط
  قبل أول طلب وتظهر شريحة الفلتر، ويمكن إزالة الفلتر بالطريقة المعتادة.
- الديون المستحقة ← `/invoices?status=unpaid`.
- إيرادات الشهر ← `/payments?from=YYYY-MM-01&to=YYYY-MM-DD` بنهاية الشهر.
- المتأخرات ← `/invoices?status=overdue`.
- تفعيل الضغط حسب صلاحية الوجهة، مع إبقاء حماية المسارات الموجودة.
- إزالة Cubit مكرر في `PaymentsListScreen.create` كان يُسقط فلاتر المسار.
- جعل إحصاءات شريط ملخص الفواتير تلتف عند ضيق المساحة؛ كشف اختبار 320px
  تجاوز عرض 148px قبل الإصلاح.
- نصوص البطاقات المشتركة في `DashboardMetricStrings`.

تكامل عاجل بملكية A1 عبر A9/A10 ومنطق فلاتر المشتركين والتحصيل؛ لا تغيير
في الخادم أو عقد API أو بيانات مالية.

## التحقق

`flutter test --no-pub test/dashboard/stats_navigation_test.dart --reporter expanded`:
9 PASS. يستخدم المسارات وشاشات القوائم الحقيقية ومستودعات وهمية، ويتحقق من
الطلبات الفعلية الناتجة لكل بطاقة، الرجوع، الصلاحيات، وخلو العرضين 320/1280px
من استثناءات التخطيط. السجل `mobile app/build/dashboard-metrics-tests.log`.

أعيد الاختبار على المصدر المتغير: 9 PASS في `dashboard-metrics-tests-final.log`.

- `flutter analyze --no-pub` الأول: No issues found؛ الفحص اللاحق أظهر
  ملاحظات في اختبارات التقارير والتحصيل المتزامنة، وتحليل الملفات المحددة أظهر
  ملاحظات ترتيب imports بعد إدراج ملف توطين متزامن. لا ندّعي خلو الشجرة الأخيرة.
- الاختبار الشامل وصل 519 PASS / 26 SKIP، ثم لم يتقدم عند تحميل
  `screen_smoke_test.dart` فأوقف. السجل `dashboard-metrics-full-tests.log`؛ لا نجاح شامل.
- `flutter build windows --release --no-pub`: أول بناء PASS في 112.7 ثانية.
  النسخة المثبتة حُدّثت مع نسخة احتياطية
  `mobile app/build/dashboard-metrics-installed-backup-20261104-005309`.
  كان DLL المنسوخ من البناء مطابقاً لمحرك Debug أثناء وجود تشغيل Debug متزامن؛
  استبدل بمحرك SDK Release الصحيح. SHA256 لمحرك النسخة المثبتة:
  `58C1F365816BFF703A1D4B3B39B1E877938357FD2D5B03045580CBB9424122A0`.
  SHA256 لـ app.so المثبت:
  `C1FFC803B67742EA3FF4D1E576D4F567EA7C76D343939EC701A3386567586656`.
- Computer Use في النسخة المثبتة أثبت بطاقة النشطين ← قائمة بفلتر نشط و253
  نتيجة، وبطاقة الديون ← قائمة بفلتر unpaid و106 نتائج وتحميل سجلات حقيقية.
  أوقف المستخدم التحكم بزر Escape قبل فحص البطاقتين الباقيتين في التطبيق؛
  تغطيهما اختبارات القوائم. لم تُنشأ أو تعدل سجلات.
- محاولات البناء اللاحقة مع استمرار تعديلات أخرى لم تمر: Android أولاً بسبب
  getter مالي كان قيد الإضافة، ثم ملف ReportsScreen أثناء إعادة كتابته، ثم
  `kernel_snapshot_program failed: Exception`؛ Windows لاحقاً MSB8066.
  السجلات `dashboard-metrics-android-{build,retry,final}.log` و
  `dashboard-metrics-windows-{final,serial}.log`. لم تستبدل هذه المحاولات
  النسخة المثبتة الناجحة. لا قبول Android أو نجاح نهائي للشجرة المتزامنة.

أضيف تعريب فلتر unpaid باستخدام `InvoiceSummaryStrings.unpaid`؛ هذا التعديل
الصغير الأخير اجتاز اختبار القوائم، لكنه ليس ضمن النسخة المثبتة من البناء الأول.

