# نشر Laravel على Debian 13

هذه الأدوات مستقلة عن Docker التجريبي الموجود في المستودع، ومخصصة لخادم صغير
بذاكرة 1 GB. لا تزرع حسابات تجريبية أو بيانات مالية، ولا ترفع أسرار البيئة المحلية.

1. ابنِ الواجهة محلياً: `npm ci --ignore-scripts` ثم `npm run build`.
2. ارفع السكربت الأول وشغّله كمسؤول:

   ```bash
   sudo bash bootstrap-debian.sh akrmsalah79 34.57.211.224
   ```

3. ارفع مصدر Laravel و`composer.lock` و`package-lock.json` و`public/build` إلى
   `/var/www/water`. استثنِ `.env` و`vendor` و`node_modules` و`public/hot` والسجلات
   والجلسات المحلية وبيانات المستخدمين وتطبيق Flutter.
4. شغّل السكربت الثاني:

   ```bash
   sudo bash /var/www/water/docker/production/deploy.sh akrmsalah79 34.57.211.224
   ```

في أول تشغيل فقط ينشئ السكربت قاعدة `water` ومستخدماً محلياً وكلمة مرور عشوائية
ومفتاح Laravel عشوائياً، ويحفظها في `.env` بصلاحية `640`. في التشغيل اللاحق يحفظ
البيئة والبيانات الموجودة ويأخذ نسخة SQL مضغوطة قبل أي ترحيل في
`/var/backups/water` بصلاحيات مقيدة. إذا كانت القاعدة موجودة والبيئة مفقودة يتوقف
بدلاً من تغيير بيانات اتصالها.

يلزم فتح 80 و443 للخدمة في جدار Google Cloud، وتفعيل HTTPS وتحديث `APP_URL`
و`SESSION_SECURE_COOKIE=true` ثم `php artisan config:cache` قبل إدخال حسابات أو
بيانات حقيقية عبر الشبكة العامة. إعداد الشهادة يعتمد على النطاق أو شهادة عنوان IP
المختارة خارج هذين السكربتين. إنشاء المدير واستيراد قاعدة موجودة عمليتان منفصلتان
تحتاجان قرار المالك؛ لا يستخدم السكربت `db:seed`.

الفحص التشغيلي: `/up` يعيد نجاحاً، `/` يوجّه إلى `/admin`، وتتحقق شاشة الدخول
وصلاحيات API. خدمة `water-queue` ومؤقت `water-scheduler` يعملان عبر systemd.
هذا السكربت لا يدعي نجاح الاختبارات أو اكتمال قبول المستخدم.

## محرك الطباعة للخادم الصغير

تحتفظ خدمة `water-pdf-browser` بمتصفح Chromium، وتحتفظ خدمة
`water-pdf-worker` بـ Node/Puppeteer وصفحة رسم واحدة. إعادة تشغيل هذه المكونات
لكل فاتورة تسبب ضغط ذاكرة وقراءة قرص شديداً على خادم 1 GB. العامل يمسح محتوى
الصفحة بعد كل مستند، ويمنع الطلبات الخارجية؛ الخطوط والصور مضمنة داخل القوالب.
لا تُخزّن ملفات الفواتير في ذاكرة تخزين مشتركة بين المستخدمين.

بعد رفع المصدر وتثبيت Puppeteer/Chromium، جهّز مسار المتصفح القابل للكتابة:

```bash
sudo install -d -o www-data -g www-data -m 700 /var/lib/water-chromium/pdf-worker
sudo install -m 644 docker/production/water-pdf-browser.service /etc/systemd/system/
sudo install -m 644 docker/production/water-pdf-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now water-pdf-browser water-pdf-worker
curl --fail http://127.0.0.1:9310/health
```

بعد نجاح فحص الصحة، اضبط `WATER_PDF_REUSE_BROWSER=true` في بيئة الخادم ثم
نفّذ `php artisan config:cache` وأعد تحميل PHP-FPM. المنفذان 9222 و9310 مرتبطان
بـ `127.0.0.1` فقط؛ لا تفتحهما للإنترنت. يبقى المسار الأصلي متاحاً عند تعطيل
المتغير على بيئات التطوير. قفل الملفات يمنع رسم ملفين بالتزامن؛ يرجع الانشغال
503 برسالة عربية. راجع سجلات الخدمتين عند الفشل؛ لا يسجل العامل محتوى الفواتير.
