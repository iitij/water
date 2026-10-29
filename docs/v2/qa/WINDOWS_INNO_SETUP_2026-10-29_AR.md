# مثبت Windows باستخدام Inno Setup — 2026-10-29

تم تجهيز سكربت Inno Setup وإنتاج مثبت فعلي باسم `mobile app/build/installer/setup.exe` للتطبيق «مشروع مياه الوشاح»، بالإصدار `1.0.0+1`. توزيع العمل: A1 للتنسيق والتوثيق، A3 لملفات `mobile app/windows/installer/**`، وA10 لاختبار `mobile app/test/windows_installer/**`.

## الاستخدام

من مجلد `mobile app`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\installer\build-installer.ps1
```

يبني الأمر Flutter Release، ويجمع كامل ملفات التشغيل مع مكتبات Microsoft CRT من Visual Studio، ثم يشغّل Inno Setup. إذا كانت نسخة Release الحالية جاهزة يمكن إضافة `-SkipBuild`. التفاصيل والخيارات في `mobile app/windows/installer/README_AR.md`، والسكربت الرئيسي `water_project.iss` في المجلد نفسه.

التثبيت لحساب المستخدم الحالي في `%LOCALAPPDATA%\Programs\WaterProject`، بواجهة عربية واختصار قائمة ابدأ وسطح مكتب اختياري. يحتفظ `AppId` بقيمته بين الإصدارات. الإزالة لا تحتوي أوامر لحذف بيانات المستخدم أو قواعد بياناته.

## الأدلة المنفذة محليًا

1. `flutter build windows --release`: نجح، exit 0، خلال 170.0 ثانية؛ الناتج `build\windows\x64\runner\Release\water_project.exe`.
2. `powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\installer\build-installer.ps1 -SkipBuild`: نجح على Windows PowerShell 5.1 وInno Setup 6.7.3، exit 0؛ التجميع النهائي 27.813 ثانية. حجم `setup.exe` النهائي 17,108,528 بايت. تحذير Inno الوحيد متعلق بالاسم العام `setup.exe`؛ احتُفظ بالاسم المطلوب.
3. `powershell -NoProfile -ExecutionPolicy Bypass -File .\test\windows_installer\smoke-installer.ps1`: PASS، exit 0. تثبيت أول وإعادة تثبيت الإصدار نفسه: تطابق SHA256 لكل الملفات الـ39 مع الحزمة المجهزة. بعد الإزالة حُذفت الملفات المثبتة وقيد إزالة التثبيت، وبقي ملف بيانات اصطناعي غير تابع للحزمة دون تغيير. وجهة الاختبار داخل `build`، مع رفض الاختبار إذا وجد تثبيت سابق بنفس AppId، وتعطيل الاختصارات وتشغيل التطبيق.
4. نجح تحليل PowerShell AST وحارسا مسار مترجم غير موجود ومجلد CRT ناقص. ملف التثبيت غير موقع رقميًا (`Get-AuthenticodeSignature`: `NotSigned`).

بصمة SHA256 النهائية، المحفوظة أيضًا في `build/installer/SHA256SUMS`:

```text
e7dbcab9dab846b2464d7604eb7664497707baad8b1f97bff239a055ae239063  setup.exe
```

نتيجة الاختبار وسجلات التثبيت وإعادة التثبيت والإزالة المحلية في:

```text
mobile app/build/installer-smoke/69e32ef078164c08b8930f4e6f80afe5/result.json
mobile app/build/installer-smoke/69e32ef078164c08b8930f4e6f80afe5/install.log
mobile app/build/installer-smoke/69e32ef078164c08b8930f4e6f80afe5/upgrade.log
mobile app/build/installer-smoke/69e32ef078164c08b8930f4e6f80afe5/uninstall.log
```

حد التحقق: إعادة تثبيت الإصدار نفسه، لا انتقال بين إصدارين مختلفين. لم يجر تشغيل التطبيق المثبت أو فحص جهاز نظيف أو اتصال API مصادق أو اختبار الطباعة والكاميرا. نجاح بناء المثبت واختباره لا يمثل قبول إصدار إنتاجي؛ التوقيع الرقمي وقبول المالك مستقلان.
