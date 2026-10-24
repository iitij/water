# قائمة إصدار Google Play — نظام إدارة المياه 2.0

> هذه قائمة **تنفيذية** لا وصفية: كل خطوة مكتوبة بحيث ينفّذها من يملك حساب
> Play Console ولا يعرف الكود. لا يوجد في المستودع أي مفتاح أو كلمة مرور،
> وهذا مقصود.

---

## 1. مفاتيح التوقيع (لا تدخل Git أبداً)

```bash
# على جهاز مسؤول الإصدار فقط (ليس على جهاز مطوّر)
keytool -genkeypair -v \
  -keystore ~/keys/water-release.jks \
  -alias water \
  -keyalg RSA -keysize 4096 -validity 10000 \
  -dname "CN=Water Project, OU=Release, O=Water, L=Sanaa, C=YE"
```

- خزّن `water-release.jks` في مدير كلمات مرور/خزنة فريق، ونسخة احتياطية لدى شخصين.
- أنشئ `android/key.properties` (متجاهَل في `.gitignore`) بالشكل:

```properties
storeFile=/absolute/path/water-release.jks
storePassword=********
keyAlias=water
keyPassword=********
```

- الملف `android/key.properties.example` موجود كنموذج؛ **لا تضع كلمات مرور حقيقية فيه**.
- تحقّق قبل كل إصدار: `bash tool/ci/check_secrets.sh` يجب أن يمرّ.

> فقدان مفتاح الرفع = عدم القدرة على تحديث التطبيق إلا بطلب من Google.
> لذلك **Play App Signing إلزامي** (القسم التالي).

---

## 2. Play App Signing + المسار الداخلي

1. Play Console → التطبيق → **Release → Setup → App signing**.
2. اختر **Use Play App Signing** وارفع المفتاح أعلاه كـ *upload key* (أو دع Google
   يولّد مفتاح التطبيق واحتفظ بمفتاح الرفع عندك).
3. أنشئ **Internal testing** track أولاً (وليس Production):
   - رفع `app-release.aab`.
   - إضافة 5–10 مختبِرين بالبريد.
   - مراجعة «Pre-launch report» قبل أي شيء آخر.
4. لا ترفع للإنتاج قبل نجاح S6-01 (مصفوفة الأجهزة) وS6-02 (اختبار ميداني).

### أوامر البناء

```bash
cd "mobile app"
flutter build appbundle --release \
  --dart-define=API_BASE_URL=https://water.example.ye/api \
  --build-name=2.0.0 --build-number=1
```

- `versionCode` يجب أن يزيد في كل رفع؛ لا تعده لرقم سابق.
- `API_BASE_URL` يجب أن يكون HTTPS؛ التطبيق يمنع HTTP الصريح في الإصدار
  (شبكة `network_security_config` + `usesCleartextTraffic=false`)، ويسمح به
  لبناء التطوير فقط للشبكة المحلية.

---

## 3. النشر المتدرّج (S6-05)

| المرحلة | النسبة | شرط الانتقال | مدة المراقبة |
|---|---|---|---|
| 1 | 5% | صفر أعطال حرجة في Crashlytics/تقارير الدعم | 48 ساعة |
| 2 | 25% | صفر سندات مالية خاطئة، والمزامنة تعمل على أجهزة حقيقية | 72 ساعة |
| 3 | 100% | اعتماد المشرف المالي + نسخة احتياطية حديثة للقاعدة | — |

**معايير الإيقاف الفوري (Rollback):**

- أي خلل يمسّ سلامة السندات (تكرار سند/فقدان سند).
- فشل مزامنة متكرر يمنع إغلاق الصندوق اليومي.
- قفل تطبيق يمنع الدخول عند مستخدمين شرعيين.

الإيقاف = `Halt rollout` في Play Console، **ولا** حذف الإصدار؛ ثم إصدار إصلاح
برقم `versionCode` أعلى.

---

## 4. قبل كل إصدار — فحص سريع

```bash
bash tool/ci/check_secrets.sh                     # لا أسرار متتبَّعة
cd "mobile app" && flutter analyze && flutter test  # يحتاج بيئة Flutter
cd .. && php artisan test                           # يحتاج PHP + MySQL
```

- [ ] لا `android/key.properties` ولا `.jks` في `git ls-files`.
- [ ] `API_BASE_URL` إنتاجي HTTPS.
- [ ] الترحيلات تعمل على نسخة من قاعدة الإنتاج: `php artisan migrate --pretend`.
- [ ] نسخة احتياطية من القاعدة قبل الترحيل.
- [ ] حدّ الإلغاء `void_approval_threshold` مضبوط من إعدادات النظام.
- [ ] حساب مشرف واحد على الأقل يملك `payments.void.approve` و`cash_sessions.approve`.

---

## 5. ما لا تغطيه هذه القائمة

- توقيع Windows/Linux (إن لزم التوزيع خارج Play).
- شهادات HTTPS للخادم (مسؤولية فريق الخادم).
- اختبار الأجهزة الفعلية (S6-01) والتدريب (S6-04) — خارج نطاق هذه القائمة.
