# وضوح اختصار العملة — 2026-11-04

طلب المستخدم الإبقاء على «ر.ي» مع تكبير الخط وجعله Bold.

- الرئيسية: اختصار العملة بحجم 16 ووزن 700 ولون أوضح، مع مسافة 8 عن الرقم.
- المبلغ المحصل أسفل التقدم: حجم 13 ووزن 700.
- بطاقات المؤشرات: الوحدة بحجم 14 ووزن 700 وبلون النص الأساسي، مع مسافة 6 عن الرقم.
- مصدر الاختصار في `DashboardMetricStrings.currencyShort`؛ لم تتغير الأرقام أو طريقة حسابها.
- شبكة المؤشرات تراعي تكبير النص في عرض وارتفاع البطاقات حتى لا تُقص الوحدة.

## التحقق

`flutter test --no-pub test/dashboard/currency_readability_test.dart --dart-define=WRITE_CURRENCY_PREVIEWS=true --reporter expanded`: نجحت الحالات الـ12 عند 320 و390 و1280، بالثيمين وتكبير النص 1x و2x. يثبت الاختبار احتواء الوحدة وعدم تداخلها مع الرقم وبقاء الرقم باتجاه LTR.

راجعت صورتي 390 داكن 1x و320 داكن 2x من `mobile app/build/currency-preview/` بصرياً؛ الاختصار أوضح وبلا قص. أيقونات Material غير محملة في معاينة الاختبار، فلا تمثل الصور قبولاً لأيقونات التطبيق.

سجل الاختبار المركز: `mobile app/build/currency-targeted.log`.
الفحص الشامل: `mobile app/build/currency-full-tests.log`.
التحليل: `mobile app/build/currency-analyze.log`.

`flutter analyze --no-pub`: لا ملاحظات، exit 0، خلال 85.5 ثانية.
`flutter test --no-pub --concurrency=2 --reporter expanded`: 736 PASS / 27 SKIP / 0 FAIL، exit 0، خلال 131 ثانية.
`git diff --check` على الملفات المعنية: exit 0. ملف backlog صالح JSON.

لم تُستبدل النسخة المثبتة، ولم يُنفذ قبول على هاتف.
