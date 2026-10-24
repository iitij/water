# حزمة ويندوز (MSIX) وبصمات الإصدار

> **الحالة: لم تُبنَ بعد.** لا يوجد مثبّت MSIX ولا ملف `SHA256SUMS` في هذا
> المستودع، لأن البناء يحتاج Visual Studio + شهادة Code Signing على جهاز ويندوز.
> هذا المستند هو الخطوات الدقيقة لمن يملك تلك البيئة.

## 1. لماذا MSIX لا ZIP

ZIP يدوي يعني: المستخدم يفكّ الملف، وربما يشغّل نسخة قديمة، ولا توجد ترقية
تلقائية ولا إلغاء تثبيت نظيف ولا تحقّق من الناشر. MSIX يحل الأربعة.

## 2. المتطلبات

| المكوّن | الإصدار |
| — | — |
| Windows SDK | 10.0.22621 أو أحدث |
| Flutter | مطابق لـ`pubspec.yaml` (≥ 3.33) |
| شهادة Code Signing | EV أو OV صادرة باسم الجهة |
| أدوات | `makeappx.exe`, `signtool.exe` (ضمن Windows SDK) |

## 3. خطوات البناء

```powershell
# 1) بناء نسخة الإصدار
flutter pub get
flutter build windows --release --dart-define=APP_VERSION=2.0.0+2

# 2) تجهيز مجلد الحزمة (راجع msix_config.json المقترح أدناه)
#    انسخ مخرج البناء: build\windows\x64\runner\Release\*
#    إلى: packaging\msix\WaterProject\

# 3) توليد الحزمة
makeappx pack /d packaging\msix\WaterProject /p WaterProject_2.0.0.2_x64.msix

# 4) التوقيع
signtool sign /fd SHA256 /a /f cert.pfx /p <password> `
  /tr http://timestamp.digicert.com /td SHA256 WaterProject_2.0.0.2_x64.msix

# 5) التحقّق
signtool verify /pa WaterProject_2.0.0.2_x64.msix
```

`Manifest.xml` (الحد الأدنى) يجب أن يحمل: `Identity Name/Publisher/Version`،
`PublisherDisplayName`، و`Application Executable="water_project.exe"`، مع
`ui:VisualElements` و`windows10:TargetDeviceFamily` (MinVersion 10.0.19041.0).

### إعداد مقترح عبر `msix` package (بديل أقصر)

أضف إلى `dev_dependencies`: `msix: ^3.16.8`، ثم في `pubspec.yaml`:

```yaml
msix_config:
  display_name: مشروع مياه الوشاح
  publisher_display_name: <الجهة المالكة>
  identity_name: com.waterproject.app
  msix_version: 2.0.0.2
  logo_path: assets/branding/app_mark.png
  capabilities: internetClient
  certificate_path: C:\secrets\water.pfx
  certificate_password: <من مدير الأسرار لا من الملف>
```

ثم `dart run msix:create` ثم `dart run msix:sign` (إن لم يُوقّع تلقائياً).
**لا تُخزّن كلمة سر الشهادة في المستودع**؛ مرّرها من متغيّر بيئة في وكيل
البناء.

## 4. ملف `SHA256SUMS`

لكل إصدار (AAB، APK، MSIX) يُولَّد ملف بصمات بجوار الملفات:

```bash
python3 scripts/make_sha256sums.py dist/            # يكتب dist/SHA256SUMS
python3 scripts/make_sha256sums.py --verify dist/   # يتحقّق
```

ثم يُنشر `SHA256SUMS` مع الإصدار، ويُذكر في ملاحظات الإصدار. المستخدم يتحقق:

```powershell
Get-FileHash .\WaterProject_2.0.0.2_x64.msix -Algorithm SHA256
```

## 5. قائمة تحقّق قبل النشر

- [ ] `flutter test` و`flutter analyze` بلا أخطاء (على جهاز البناء).
- [ ] `APP_VERSION` في البناء = نسخة `pubspec.yaml` = تاریخ `versionName`.
- [ ] رقم الإصدار ظاهر في شاشة «التشخيص والدعم» — يطابق الحزمة.
- [ ] MSIX موقّع ومُتحقَّق عبر `signtool verify`.
- [ ] `SHA256SUMS` مُولّد ومرفق.
- [ ] ملاحظات الإصدار العربية (`RELEASE_NOTES_2.0_AR.md`) منشورة.
- [ ] لا مفاتيح ولا كلمات مرور في Git (`git grep` قبل الدفع).
