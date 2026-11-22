# ربط GitHub Actions باستضافة Namecheap — Water

الحالة: **يتطلب تفويض مفتاح SSH خاص بالـ Actions وإضافة Secret قبل أول تشغيل**.

المستودع: `iitij/water` (خاص)، الفرع `main`.
الاستضافة: المستخدم `alweufti`، العنوان `66.29.146.205`، منفذ SSH `21098`.
نسخة Laravel المستنسخة: `/home/alweufti/repositories/water`، خارج `public_html`.

## تفويض اتصال SSH من GitHub Actions (مرة واحدة)

**لا تستخدم مفتاح `water_github` الحالي هنا**؛ هذا المفتاح يسمح للاستضافة بقراءة GitHub وهو مختلف عن مفتاح دخول GitHub Actions إلى الاستضافة.

1. على جهاز Ubuntu المحلي، أنشئ مفتاحًا مستقلًا (احتفظ بالمفتاح الخاص على الجهاز؛ اضغط Enter مرتين لإنشاء مفتاح بدون passphrase للاستخدام غير التفاعلي):

   ```bash
   mkdir -p ~/.ssh && chmod 700 ~/.ssh
   if [ -e ~/.ssh/water_namecheap_actions ]; then
     echo "A dedicated Actions key already exists; do not overwrite it."
   else
     ssh-keygen -t ed25519 -f ~/.ssh/water_namecheap_actions -C "water-github-actions"
   fi
   cat ~/.ssh/water_namecheap_actions.pub
   ```

2. في cPanel: **Security → SSH Access → Manage SSH Keys → Import Key**. استورد **المفتاح العام فقط** (النص الذي يبدأ بـ `ssh-ed25519`) باسم `water_namecheap_actions`. لا تملأ خانة Private Key. ثم اختر **Manage → Authorize** للمفتاح. يمكن بدلاً من ذلك إضافة المفتاح العام يدويًا إلى `~/.ssh/authorized_keys`، مع ضبط صلاحيات `.ssh` إلى `700` والملف إلى `600`.
3. في حساب GitHub افتح **Settings → Secrets and variables → Actions → New repository secret**:
   - Name: `NAMECHEAP_SSH_PRIVATE_KEY`
   - Secret: محتويات ملف **`~/.ssh/water_namecheap_actions` الخاص** من جهاز Ubuntu، بما في ذلك BEGIN/END، وليس الملف المنتهي بـ `.pub`.
   - لا تضع السر في Git، أو تعليقات Issues، أو هذه المحادثة أو سجلات GitHub Actions.
4. تحقّق من بصمة SSH الخاصة بخادم Namecheap بواسطة الدعم من قناة مستقلة. ملف التشغيل يتحقق من بصمة ED25519 التي ظهرت مسبقًا عند اتصال صاحب الاستضافة: `SHA256:30IXGrYxD3pXnfOdvFZMCJ5YShzaMeRCYXRWXYnl4Rw`. إذا اختلفت، **أوقف التنفيذ** حتى يتم التحقق من هوية الخادم، ولا تُضعف التحقق من SSH.
5. من تبويب **Actions** شغّل `Water | Namecheap remote operations` باستخدام **Run workflow** على الفرع `main`، واختر عملية `audit` أولًا، ثم افحص سجل التشغيل. لا يُفعّل الملف التشغيل مع كل Push.

## العمليات المتاحة الآن

- `audit`: فحص إصدار PHP 8.4 وإضافاته، أدوات Composer/Node، مساحة القرص وحالة Git. لا يقرأ أو يطبع ملف `.env` أو كلمات مرور قاعدة البيانات.
- `sync`: جلب أحدث فرع `main` في المسار الخاص فقط، بشرط عدم وجود تعديلات محلية وأن يكون التحديث fast-forward. لا ينفذ Composer أو Artisan ولا يمس قواعد البيانات أو `public_html`.

هذه الآلية تتصل بصلاحيات مستخدم cPanel `alweufti`، **وليست Root**، والمفتاح نفسه يتيح لذلك المستخدم الوصول إلى ملفاته. لهذا لا تسمح بتشغيل Workflows من مصادر غير موثوقة، وراجع كل تغيير إلى Workflows على `main`.

## ما لم يُفعّل بعد

- تثبيت Composer واعتماديات Laravel 13.
- إنتاج ملفات Vite وNode.
- إعداد `.env` وبيانات قاعدة البيانات.
- توجيه الموقع إلى `public/`، وتشغيل Queue وScheduler.
- نشر Production وترحيلات قاعدة البيانات.

تأتي هذه الخطوات بعد نجاح اختبار `audit` ومراجعة توافق بيئة Namecheap مع متطلبات التطبيق. **لا تنقل جذر المشروع إلى `public_html`؛ المستودع يحتوي أيضًا على ملفات SQL وأدوات تطوير.**

## التعطيل والاستجابة للحوادث

يمكن إلغاء الوصول من `~/.ssh/authorized_keys` في cPanel أو من SSH Access / Manage SSH Keys، وحذف Secret من GitHub. احذف أي مفتاح مفقود أو مكشوف واستبدله بمفتاح جديد. إيقاف GitHub Actions وحده لا يُلغي صلاحية المفتاح المصرّح به في الخادم.
