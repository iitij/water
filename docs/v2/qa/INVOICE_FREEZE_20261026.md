# إصلاح تعليق الفواتير — 2026-10-26

## السبب المثبت محلياً

`FlutterError.onError` يسجل الخطأ في `ApiLogManager`، الذي يستدعي المستمعين فوراً. إذا فشل مستمع (مثل تحديث واجهة أثناء بنائها)، يعود الخطأ إلى نفس المسجل وتتكرر الإشعارات حتى استنفاد المكدس. اختبار محدود بثماني دورات أثبت 8 استدعاءات بدل استدعاء واحد قبل الإصلاح.

نموذج إصدار فاتورة العداد احتوى `ColoredBox` فوق `SwitchListTile`، ما أنتج خطأ Flutter عن حجب خلفية وطبقة الحبر. استبداله بـ `Material` أزال الخطأ المثبت في اختبار النموذج.

## التغيير

- منع إعادة دخول إشعارات المسجل مع الاحتفاظ بسجل الخطأ المتداخل.
- تجنب إعادة بناء شارة السجلات عندما تكون مخفية؛ تأجيل التحديث إلى نهاية الإطار إذا كان البناء جارياً.
- توفير Material لنموذج الفاتورة.
- اختبارات لفتح النموذج وتغيير الخيار والإغلاق في الوضعين، وللتسجيل أثناء بناء الواجهة مع الشارة ظاهرة ومخفية، ولعرض تبويب فواتير مشترك بقائمة غير فارغة.
- استكمال سبعة مفاتيح نصوص قراءات كانت تمنع تجميع الاختبارات بسبب تغييرات متزامنة.

## دليل التنفيذ

```powershell
flutter test --no-pub --concurrency=1 test/core/diagnostics/api_log_manager_test.dart test/core/diagnostics/floating_api_log_overlay_test.dart test/features/invoices/invoice_generation_sheet_test.dart test/ui/app_amount_layout_test.dart test/ui/water_experience_test.dart
```

النتيجة: `27 tests passed`.

```powershell
flutter analyze --no-pub lib/core/diagnostics/api_log_manager.dart lib/features/diagnostics/presentation/widgets/floating_api_log_overlay.dart lib/features/invoices/presentation/sheets/generate_invoice_sheets.dart lib/features/invoices/presentation/sheets/invoice_generate_sheet_body.dart test/core/diagnostics/floating_api_log_overlay_test.dart test/features/invoices/invoice_generation_sheet_test.dart
```

النتيجة: `No issues found`.

## حدود التحقق

الاختبارات تستخدم بيانات محلية وتبعيات محاكاة؛ لم تُختبر جلسة المشترك 43 على الخادم الحي، ولم يُستبدل التطبيق الجاري تشغيله. نجاح HTTP 200 لا يثبت سلامة بناء الواجهة. لم يتغير عقد API أو الحساب المالي. يلزم إعادة تشغيل التطبيق وتجربة الفواتير وفاتورة العداد للتحقق من الحالة المبلغ عنها على الجهاز.
