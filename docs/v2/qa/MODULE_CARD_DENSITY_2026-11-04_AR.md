# تصغير بطاقات الأدوات والخدمات — 2026-11-04

طلب المستخدم إزالة الحجم الزائد من بطاقات الصورة ومن كل البطاقات المشابهة في التطبيق.

## المراجعة والاتجاه

- المشكلة: ارتفاع 152dp ثابت وفراغ `Spacer` بين الأيقونة والعنوان في بطاقات الميدان والفوترة والمشتركين والمزيد واختصارات الرئيسية. دليل الخدمات يكرر التصميم بارتفاع 148dp.
- المنتج: تطبيق إدارة المياه للقراءات والتحصيل وخدمة المشتركين.
- الطابع: عملي، هادئ، واضح.
- البيئة: قوائم أدوات العمل الميداني، مع الحفاظ على ألوان التطبيق وخطه الحاليين.
- موضع التركيز: عنوان الأداة ووصفها، بجانب أيقونة صغيرة.
- العلامة المشتركة: صف RTL مع سهم انتقال ومسافات موحّدة.

## التنفيذ

`AppModuleTile` أصبح صفاً واحداً بحشو 12dp وأيقونة 36dp، دون ارتفاع ثابت أو فراغ مرن يفصل المحتوى. العنوان والوصف يلتفان دون اقتطاع، وتتمدد البطاقة عند تكبير الخط. تستخدم شبكات الأقسام ودليل الخدمات واختصارات الرئيسية هذا المكوّن نفسه. الشبكة تعرض عموداً في الجوال وأعمدة إضافية حسب العرض وحجم النص؛ الشريط الأفقي يحتفظ بالتمرير مع ارتفاع يتبع المحتوى.

لم تتغير المسارات أو شروط الصلاحيات أو عقد API. حُفظت التعديلات الأخرى الموجودة مسبقاً في الشجرة المشتركة.

## التحقق

- `flutter test --no-pub --concurrency=1 --dart-define=WRITE_MODULE_PREVIEWS=true test/ui/module_card_density_test.dart test/home/workspace_views_test.dart --reporter expanded`: **33 PASS، exit 0**. تغطية جميع أدوات الكتالوج بعروض 320/390/768/1280، تكبير 1/1.6، فاتح/داكن، وأهداف لمس 48dp على الأقل، وفتح الوجهة الصحيحة والتمرير الأفقي. اختبارات الرئيسية والدليل تغطي أيضاً تكبير 2x والبحث وتبديل الصلاحيات والتصنيف.
- `dart analyze lib/core/ui/app_tiles.dart lib/features/navigation/presentation/widgets/module_section.dart lib/features/home/presentation/widgets/workspace_widgets.dart lib/features/home/presentation/services_screen.dart test/ui/module_card_density_test.dart test/home/workspace_views_test.dart`: **No issues found، exit 0**.
- `flutter analyze --no-pub`: **No issues found، exit 0**، 9.2s؛ `build/module-density-analyze-final.log`.
- `flutter build windows --release --no-pub -v`: **PASS، exit 0**، 15s؛ `build/module-density-windows-final.log`، الناتج `build/windows/x64/runner/Release/water_project.exe`.
- `flutter build apk --debug --no-pub`: **PASS، exit 0**، 71s؛ `build/module-density-android-final.log`، الناتج `build/app/outputs/flutter-apk/app-debug.apk`. تحذير KGP من إضافتي `flutter_image_compress_common` و`share_plus` لم يمنع البناء.
- `flutter test --no-pub --concurrency=1 --reporter expanded`: **720 PASS / 27 SKIP / 4 FAIL، exit 1**، 3m28s؛ `build/module-density-full-final.log`. الفشلان في `home_invoice_counts_test.dart` عند فتح قائمة الفواتير يسجلان `RenderFlex overflow 8px`، والفشلان في `screen_smoke_test.dart` لا يجدان النص المتوقع «القراءات» في إعدادات التطبيق بعرضي 320/1440. لم تُعدل هذه الاختبارات أو شاشتا الفواتير والإعدادات ضمن هذه المهمة. اختبارات كثافة البطاقات والرئيسية ودليل الخدمات ناجحة؛ نتيجة الفحص الشامل غير خضراء ولا تُنسب أسبابها إلى مصدر محدد دون عزل إضافي.

أُعيد التحقق بعد آخر تعديل: **33 PASS، exit 0**؛ `build/module-density-focused-final.log`. الصور في `mobile app/build/module-density-preview/` مشتقة من مكوّنات Flutter الفعلية مع تحميل الخط العربي وخط MaterialIcons، وجرى فحص عرضي 390/1280 بصرياً. لا تمثل قبولاً على جهاز فعلي أو تثبيتاً للنسخة المبنية.

استُخدمت إرشادات unslop، Damir Sokolovsky (@heydamir)، CC BY 4.0، لتحديد المسافات والتناسب؛ لم تُنسخ أصول أو صور خارجية.

## متابعة: توحيد مقاسات البطاقات المتجاورة

بناءً على صورة المستخدم التالية، أصبحت جميع بطاقات الشريط الأفقي متساوية العرض والارتفاع، وبطاقات كل صف من الشبكة متساوية أيضاً. يعتمد ارتفاع الصف على أطول محتوى فيه باستخدام `IntrinsicHeight` وامتداد العناصر عمودياً؛ يستمر التفاف النص عند تكبير الخط دون قصه أو فرض ارتفاع ضخم. تنطبق المعالجة المشتركة على أقسام التنقل ودليل الخدمات واختصارات الرئيسية.

أضيفت مقارنات فعلية لأبعاد البطاقات في مصفوفة المقاسات واختبار الشريط الأفقي. قبل الإصلاح فشل اختبار الشريط: المتوقع `304×180` والفعلي `304×143` عند تكبير الخط مرتين؛ `build/module-equal-before.log`. بعده نجحت الاختبارات المركزة **33 PASS، exit 0**؛ `build/module-equal-focused.log`. فُحصت معاينة الكمبيوتر بصرياً، وأصبح الحد السفلي واحداً للبطاقات المتجاورة.

النتائج النهائية لهذه المتابعة:

- `flutter test --no-pub --concurrency=1 --reporter expanded`: **724 PASS / 27 SKIP، exit 0**، 5m24s؛ `build/module-equal-full.log`.
- `flutter analyze --no-pub`: **No issues found، exit 0**؛ `build/module-equal-analyze.log`.
- `flutter build windows --release --no-pub`: **PASS، exit 0**، 137.2s؛ `build/module-equal-windows.log`.
- `flutter build apk --debug --no-pub`: **PASS، exit 0**، 51.1s؛ `build/module-equal-android.log`.

الفحص الشامل الحالي ناجح، وهو لقطة للشجرة المشتركة وقت التشغيل؛ لا يعني أن هذه المتابعة أصلحت المشكلات الأربع المسجلة في الجولة السابقة. لم تُستبدل النسخة المثبتة ولم يُجر قبول على جهاز فعلي.
