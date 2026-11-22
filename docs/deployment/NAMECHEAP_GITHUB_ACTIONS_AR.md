# ربط GitHub Actions باستضافة Namecheap — Water

الحالة: **يتطلب تفويض مفتاح SSH خاص بالـ Actions وإضافة Secret قبل أول تشغيل**.

المستودع: `iitij/water` (خاص)، الفرع `main`.
الاستضافة: المستخدم `alweufti`، العنوان `66.29.146.205`، منفذ SSH `21098`.
نسخة Laravel المستنسخة: `/home/alweufti/repositories/water`، خارج `public_html`.

## تفويض اتصال SSH من GitHub Actions (مرة واحدة)

**لا تستخدم مفتاح `water_github` الحالي هنا**؛ هذا المفتاح يسمح للاستضافة بقراءة GitHub وهو مختلف عن مفتاح دخول GitHub Actions إلى الاستضافة.

1. **لا حاجة إلى جهاز محلي:** افتح Terminal من cPanel نفسه لإنشاء مفتاح منفصل مخصص لاتصال GitHub Actions إلى الاستضافة، ثم تفويضه لحساب cPanel `alweufti`:

   ```bash
   umask 077
   mkdir -p "$HOME/.ssh"
   chmod 700 "$HOME/.ssh"
   key="$HOME/.ssh/water_namecheap_actions"
   if [ -e "$key" ] || [ -e "$key.pub" ]; then
     echo "A dedicated Actions key already exists; not overwriting."
   else
     ssh-keygen -q -t ed25519 -f "$key" -N "" -C "water-github-actions"
   fi

   touch "$HOME/.ssh/authorized_keys"
   chmod 600 "$HOME/.ssh/authorized_keys"
   pub="$(cat "$key.pub")"
   grep -Fxq "$pub" "$HOME/.ssh/authorized_keys" ||
     printf '%s\n' "$pub" >> "$HOME/.ssh/authorized_keys"
   chmod 600 "$key"
   chmod 644 "$key.pub"
   echo "SSH public key authorized for cPanel user $(id -un)."
   ```

   بديل تفويض المفتاح العام: استخدم واجهة **Security → SSH Access → Manage SSH Keys → Manage → Authorize** في cPanel. لا تغيّر المفتاح القديم `water_github` المخصص لجلب GitHub من الخادم.

2. **داخل cPanel Terminal فقط** افتح المفتاح الخاص لنسخه مباشرة إلى GitHub Actions Secret. لا تشارك محتوياته في أي محادثة:

   ```bash
   cat "$HOME/.ssh/water_namecheap_actions"
   ```

   يبدأ النص بـ `-----BEGIN OPENSSH PRIVATE KEY-----` وينتهي بـ `-----END OPENSSH PRIVATE KEY-----`. انسخ النص الكامل، بما فيه السطران وحدود الأسطر.

3. في حساب GitHub افتح **Settings → Secrets and variables → Actions → New repository secret**:
   - Name: `NAMECHEAP_SSH_PRIVATE_KEY`
   - Secret: المحتوى الخاص الذي نسخته مباشرة من cPanel Terminal.
   - لا تضع السر في Git، أو تعليقات Issues، أو هذه المحادثة، أو أي سجل عام. أغلق جلسة Terminal بعد النقل وتجنّب أخذ لقطات شاشة للمفتاح.
   - إذا لم تعد بحاجة إلى النسخة الخاصة على الاستضافة، يمكن حذف الملف `water_namecheap_actions` **بعد نجاح اختبار تشغيل Actions** مع الاحتفاظ بالمفتاح العام المصرّح به في `authorized_keys`؛ لا تحذف المفتاح `water_github`.

4. تحقّق من بصمة SSH الخاصة بخادم Namecheap بواسطة الدعم من قناة مستقلة. ملف التشغيل يتحقق من بصمة ED25519 التي ظهرت مسبقًا عند اتصال صاحب الاستضافة: `SHA256:30IXGrYxD3pXnfOdvFZMCJ5YShzaMeRCYXRWXYnl4Rw`. إذا اختلفت، **أوقف التنفيذ** حتى يتم التحقق من هوية الخادم، ولا تُضعف التحقق من SSH.
5. من تبويب **Actions** شغّل `Water | Namecheap remote operations` باستخدام **Run workflow** على الفرع `main`، واختر عملية `audit` أولًا، ثم افحص سجل التشغيل. لا يُفعّل الملف التشغيل مع كل Push.

## العمليات المتاحة الآن

- `audit`: فحص إصدار PHP 8.4 وإضافاته، أدوات Composer/Node، مساحة القرص وحالة Git. لا يقرأ أو يطبع ملف `.env` أو كلمات مرور قاعدة البيانات.
- `diagnose`: فحص إعدادات PHP 8.4 وملفات الإضافات المتوفرة دون تغييرها.
- `selector-status`: عرض حالة CloudLinux PHP Selector للمستخدم `alweufti` وقائمة إضافات PHP 8.4 المفعلة والمعطلة (قراءة فقط).
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
