# إصلاح تثبيت بناء Windows — 2026-10-29

النتيجة: نجح بناء Windows وتشغيل التطبيق محلياً بعد إعادة تهيئة مسار التثبيت في ذاكرة CMake المولدة. لم يتغير كود التطبيق أو ملفات Windows المصدرية.

## السبب المثبت

أعاد الأمر `flutter build windows --debug --no-pub -v` إنتاج الخطأ (exit 1). كانت القيمة المحفوظة في `mobile app/build/windows/x64/CMakeCache.txt`:

```text
CMAKE_INSTALL_PREFIX:PATH=C:/Program Files/water_project
```

وسجلت خطوة `INSTALL.vcxproj`:

```text
file cannot create directory: C:/Program Files/water_project.
Maybe need administrative privileges.
```

هذا يثبت أن الفشل في وجهة نسخ الملفات. سبب بقاء القيمة الخاطئة في الذاكرة قبل التحقيق غير مثبت.

## الإجراء المنفذ

من مجلد `mobile app`، أزيلت قيمة مسار التثبيت وحدها وأعيد توليد ملفات CMake، دون حذف مجلد البناء:

```powershell
& 'C:\Program Files\Microsoft Visual Studio\18\Community\Common7\IDE\CommonExtensions\Microsoft\CMake\CMake\bin\cmake.exe' -U CMAKE_INSTALL_PREFIX -S windows -B build/windows/x64
```

نجح الأمر (exit 0)، وصارت القيمة `$<TARGET_FILE_DIR:water_project>` حسب إعداد المشروع الحالي. أكد `cmake_install.cmake` أن وجهة Debug هي `build/windows/x64/runner/Debug`، ولـ Profile وRelease وجهتاهما المنفصلتان.

## دليل التحقق

```powershell
flutter run -d windows --no-pub --no-resident -v
```

النتيجة الفعلية (exit 0):

```text
Build succeeded.
0 Warning(s)
0 Error(s)
Building Windows application... (completed in 23.1s)
Built build\windows\x64\runner\Debug\water_project.exe
Successfully connected to service protocol
Connected to _flutterView
exiting with code 0
```

بعد انتهاء الأمر بقيت العملية `water_project.exe` مفتوحة بعنوان «مشروع مياه الوشاح». لم يضف `git diff -- windows` أي تغيير مصدري.

السجلان المحليان الكاملان في `%TEMP%/water-windows-build-20261029-before.log` و`%TEMP%/water-windows-run-20261029-after.log`. لا تُنشر سجلات التشغيل الكاملة دون مراجعتها للبيانات الحساسة.

حد التحقق: بناء وتشغيل Windows واتصال Flutter فقط؛ لم يجر فحص بصري للشاشات أو اختبار عمليات الخادم أو قبول مالك/إطلاق إنتاجي. لم تُشغّل اختبارات Dart لأن الإصلاح اقتصر على قيمة محلية مولدة؛ اختبار البناء والتشغيل أعلاه يتحقق مباشرة من العطل.
