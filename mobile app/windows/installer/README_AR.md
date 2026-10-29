# مثبت Windows — Inno Setup

المثبت باسم **مشروع مياه الوشاح**، بواجهة عربية، ويستهدف Windows 10 إصدار
1809 فأحدث على أجهزة تدعم x64. يثبت التطبيق لحساب المستخدم الحالي دون طلب
صلاحيات المدير، ويضيف اختصار قائمة ابدأ واختصار سطح مكتب اختياري.

## البناء بأمر واحد

المتطلبات على جهاز البناء: Flutter، وVisual Studio مع Desktop development with
C++، و[Inno Setup 6.5 أو أحدث](https://jrsoftware.org/isdl.php) مع ملفات اللغات.
يمكن استخدام Inno Setup 7 أيضًا.

من مجلد `mobile app`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\installer\build-installer.ps1
```

النتيجة:

```text
build\installer\setup.exe
build\installer\SHA256SUMS
```

السكربت يبني نسخة Flutter Release، ويقرأ الإصدار من `pubspec.yaml`، ويتحقق من
تطابقه مع الملف التنفيذي. يجمع كامل ملفات Flutter والإضافات والأصول، مع مكتبات
Microsoft Visual C++ x64 القابلة لإعادة التوزيع من Visual Studio بجانب التطبيق.
لذلك لا يحتاج جهاز العميل إلى Flutter أو Visual Studio، ولا تنزيل متطلبات أثناء
التثبيت. استخدم مجلد CRT من Visual Studio المرخّص على جهاز البناء وفق شروط Microsoft.

إذا كانت نسخة Release الحالية مبنية بالفعل:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\installer\build-installer.ps1 -SkipBuild
```

لتمرير إعداد الخادم إلى بناء Flutter:

```powershell
.\windows\installer\build-installer.ps1 -FlutterBuildArguments @('--dart-define=API_BASE_URL=https://example.com/api')
```

إذا لم يكتشف مسار المترجم أو مكتبات Microsoft تلقائيًا:

```powershell
.\windows\installer\build-installer.ps1 -SkipBuild `
  -IsccPath 'C:\Tools\Inno Setup 6\ISCC.exe' `
  -CrtDirectory 'C:\path\to\x64\Microsoft.VC145.CRT'
```

يمكن أيضًا فتح `water_project.iss` في Inno Setup والضغط على Compile بعد تجهيز
`build\installer\payload` بالسكربت. يحتفظ المعرّف `AppId` بالقيمة نفسها في الإصدارات
القادمة لتحديث التثبيت السابق. ملفات البناء والناتج داخل `build/` متجاهلة في Git.

## التثبيت والتحديث والإزالة

المسار الافتراضي `%LOCALAPPDATA%\Programs\WaterProject`. المثبت يغلق التطبيق عند
الحاجة إلى تحديث ملفاته، ولا يعيد تشغيله تلقائيًا في التثبيت الصامت. الإزالة تحذف
الملفات التي ثبتها Inno فقط؛ لا توجد أوامر لحذف قواعد البيانات أو الإعدادات أو
النسخ الاحتياطية في مجلدات المستخدم. ملف `setup.exe` غير موقع ما لم تضف توقيع
إصدار خاصًا؛ توقيع الإنتاج مستقل عن إنشاء المثبت.

اختبار قبل التوزيع: تثبيت على حساب Windows تجريبي، فتح التطبيق، إعادة التثبيت
كتحديث، ثم الإزالة والتحقق من بقاء بيانات المستخدم. نجاح تجميع المثبت وحده لا
يثبت قبول الطباعة أو الكاميرا أو الاتصال بالخادم.

اختبار آلي لدورة التثبيت وإعادة التثبيت والإزالة، بعد بناء المثبت:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\test\windows_installer\smoke-installer.ps1
```

يرفض الاختبار العمل عند وجود تثبيت سابق لهذا التطبيق، ويستخدم مسارًا تجريبيًا
داخل `build/installer-smoke` دون إنشاء اختصارات. يتحقق من بصمات الملفات، وتنظيف
التثبيت وسجله، وبقاء ملف بيانات مستخدم تجريبي بعد الإزالة. لا يفتح التطبيق ولا
يختبر الاتصال بالخادم.

المراجع: [توزيع تطبيق Flutter على Windows](https://docs.flutter.dev/platform-integration/windows/building)،
[تثبيت Inno بصلاحيات المستخدم](https://jrsoftware.org/ishelp/topic_setup_privilegesrequired.htm).
