# الخطة الهندسية والتنفيذية الشاملة والمتكاملة لبناء تطبيق الموبايل (Flutter) لمنظومة إدارة مشاريع المياه (`Water Project`)

> **الوثيقة المرجعية العليا (Master Engineering & Execution Blueprint)**
> **الإصدار:** 1.0 (النسخة الكاملة المعتمدة للتنفيذ)
> **النظام المرجعي الخلفي (Backend):** Laravel 13.8 + Filament 5.6 + Sanctum 4.0 + Spatie Permission (Filament Shield)
> **إطار عمل تطبيق الموبايل:** Flutter (أحدث إصدار مستقر - يدعم Android, iOS, Tablets, Foldables, Handheld POS/Printers)
> **الهدف الاستراتيجي:** نقل **100%** من خصائص، دوال، عمليات، معادلات محاسبية، تقارير، قوالب طباعة، إشعارات واتساب، وإعدادات نظام Laravel إلى تطبيق موبايل احترافي، فائق السرعة، يعمل بكفاءة عالية (أونلاين وأوفلاين)، بتصميم عصري حديث وتجربة مستخدم سلسة ومفهومة لجميع الفئات (المدير العام، المحاسب، المحصل الميداني، قارئ العدادات).

---

## الفهرس العام للوثيقة

1. **الباب الأول: الدراسة التحليلية الشاملة للنظام الحالي (Laravel Codebase & Business Logic Audit)**
   - 1.1 البنية التحتية والمكتبات المستخدمة في النظام الخلفي.
   - 1.2 التشريح الكامل لقاعدة البيانات والنماذج الـ 13 (Models, Relations, Casts, Boot Hooks).
   - 1.3 القواعد المحاسبية والمعادلات البرمجية الدقيقة في النظام (Accounting & Core Services Engine).
   - 1.4 مصفوفة الأدوار والصلاحيات الأمنية (Roles, Policies & Filament Shield Permissions).
2. **الباب الثاني: دراسة الـ API الحالي وتحليل الفجوات والمواصفات الكاملة للـ API المطلوب (API Audit & Complete Specification)**
   - 2.1 التقييم التفصيلي للـ 24 مساراً الموجودة حالياً في `routes/api.php`.
   - 2.2 تحليل الفجوات (Gap Analysis): الوظائف الموجودة في الويب (Filament/Web) والغائبة عن الـ API.
   - 2.3 المواصفات الهندسية الكاملة للمسارات الجديدة والمحدثة (أكثر من 68 Endpoint لتغطية 100% من النظام).
3. **الباب الثالث: المعمارية الهندسية لتطبيق Flutter، التجاوب الشامل، ونظام التصميم العصري (Architecture, Responsiveness & UI/UX)**
   - 3.1 المعمارية البرمجية (Feature-First Clean Architecture + BLoC/Cubit) وهيكل المجلدات الكامل.
   - 3.2 الحزم والمكتبات المعتمدة (`pubspec.yaml`) وأسباب اختيارها.
   - 3.3 هندسة التجاوب الشامل (Responsive & Adaptive Engine) لدعم كافة الأجهزة ومقاسات الشاشات.
   - 3.4 نظام التصميم العصري وتجربة المستخدم (Modern UI/UX Design System - AquaGlass Theme).
   - 3.5 محرك الأداء الفائق والعمل بدون إنترنت (Offline-First Sync Engine) والطباعة الميدانية.
4. **الباب الرابع: الخريطة التفصيلية الشاملة لشاشات وعمليات تطبيق الموبايل (Screen-by-Screen & Feature Blueprint)**
   - تفصيل أكثر من 42 شاشة ونافذة عملية تغطي كل شبر في النظام (الحقول، التحقق، التفاعلات، الحالات، والربط البرمجي).
5. **الباب الخامس: هيكلة فريق التطوير وتقسيم المهام التفصيلي (Team Structure, Roles & Task Division)**
   - 5.1 هيكل الفريق وتوزيع الاختصاصات (من 2 إلى 6 مطورين).
   - 5.2 المهام الأساسية (Primary Tasks)، المهام الجانبية (Secondary Tasks)، وخطوات التنفيذ التفصيلية لكل مطور.
   - 5.3 عقود التكامل بين المطورين، استراتيجية الفروع (Git Flow)، ومعايير تسليم الكود.
6. **الباب السادس: دليل تسريع الإنجاز باستخدام الوكلاء والوكلاء الفرعيين (AI Agents & Subagents Playbook)**
   - 6.1 منهجية التطوير المعزز بالوكلاء (Agentic Engineering Workflow).
   - 6.2 توصيف الوكلاء الفرعيين (Subagents) ومهام كل واحد منهم وقوالب الأوامر (Prompts) الجاهزة.
   - 6.3 جدول توزيع الوكلاء الفرعيين على كل مطور حسب مهامه الأساسية والجانبية.
   - 6.4 خارطة الطريق الزمنية (Sprints Roadmap) ومعايير الفحص والقبول النهائي (QA Checklist).

---

# الباب الأول: الدراسة التحليلية الشاملة للنظام الحالي (Laravel Codebase & Business Logic Audit)

قبل كتابة سطر كود واحد في تطبيق الموبايل، تمت دراسة وفحص كامل الكود المصدري لمشروع Laravel الحالي (`water-main`) لضمان عدم إغفال أي عملية أو معادلة أو تفصيلة صغيرة.

## 1.1 البنية التحتية والمكتبات المستخدمة في النظام الخلفي (`composer.json`)

يعتمد النظام الحالي على حزمة تقنية حديثة تتكون من:
- **إطار العمل:** `laravel/framework: ^13.8` على لغة `PHP ^8.3`.
- **لوحة التحكم الحالية:** `filament/filament: 5.6` (تضم 13 Resource، و6 صفحات مخصصة Pages، و5 ودجات إحصائية Widgets، ومكون Livewire مخصص).
- **المصادقة لأجهزة الموبايل:** `laravel/sanctum: ^4.0` عبر رموز الوصول الشخصية (`Personal Access Tokens - Bearer Token`).
- **إدارة الأدوار والصلاحيات:** `spatie/laravel-permission` مع `bezhansalleh/filament-shield` (توليد صلاحيات دقيقة لكل نموذج وصفحة وودجة).
- **توليد ملفات الـ PDF:** `spatie/laravel-pdf: ^2.11` و `spatie/browsershot: ^5.4` و `mpdf/qrcode: ^1.2` لتوليد فواتير وسندات قبض بحجم `A5 Landscape` بهوامش صفرية.
- **تصدير ملفات الإكسل (`.xlsx`):** مكتبة `OpenSpout\Writer\XLSX\Writer` المدمجة عبر خدمة `DataExportService`.
- **إدخال وتنسيق أرقام الهواتف:** `ysfkaya/filament-phone-input: ^4.2` مع اعتماد الدولة الافتراضية اليمن (`YE` / `+967`).
- **خادم الواتساب المحلي الرديف:** خادم `Node.js` يعمل محلياً على المنفذ `http://localhost:3000` ويُدار عبر ملفات التشغيل (`start-whatsapp.bat` و `start-whatsapp-hidden.vbs`) مع عامل طابور Laravel (`php artisan queue:work`).

---

## 1.2 التشريح الكامل لقاعدة البيانات والنماذج الـ 13 (Models, Schema, Relations & Lifecycle Hooks)

فيما يلي تفصيل جميع النماذج (Models) الموجودة في `app/Models/` مع الحقول، العلاقات، الدوال المدمجة، والأحداث التلقائية (`booted` Hooks) التي يجب أن يحترمها تطبيق الموبايل والـ API:

### 1. نموذج المربعات السكنية `Square` ([Square.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Square.php))
- **الجدول:** `squares`
- **الحقول القابلة للتعبئة (`$fillable`):**
  - `name` (نص، مطلوب، بحد أقصى 100/255 حرفاً): اسم المربع السكني أو الحارة.
  - `description` (نص طويل، اختياري): وصف موقع المربع السكني أو ملاحظات عنه.
- **العلاقات:**
  - `subscribers()`: علاقة `HasMany` مع نموذج `Subscriber`.

### 2. نموذج المشتركين `Subscriber` ([Subscriber.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Subscriber.php))
- **الجدول:** `subscribers` (يدعم الحذف الناعم `SoftDeletes`)
- **الحقول القابلة للتعبئة (`$fillable`) والتحويلات (`$casts`):**
  - `name` (`string`): اسم المشترك الرباعي.
  - `phone` (`string`, nullable): رقم الهاتف (يدعم الصيغة المحلية والدولية `+967`).
  - `address` (`text`, nullable): العنوان التفصيلي أو الموقع.
  - `square_id` (`foreignId`): المربع السكني التابع له المشترك.
  - `balance` (`decimal:2`): رصيد الحساب العام للمشترك (غير المرتبط بعداد معين). **ملاحظة محاسبية هامة:** القيمة الموجبة (`> 0`) تعني **رصيداً دائناً (له)**، والقيمة السالبة (`< 0`) تعني **رصيداً مديناً / متأخرات (عليه)**.
  - `initial_subscription_fee` (`decimal:2`): رسوم الاشتراك المقررة الأولية.
  - `subscription_fee_paid` (`decimal:2`): المسدد من رسوم الاشتراك.
  - `is_subscription_fee_fully_paid` (`boolean`): هل تم سداد رسوم الاشتراك بالكامل.
  - `status` (`enum: active, inactive, suspended`): حالة المشترك (`active` نشط، `inactive` غير نشط، `suspended` موقوف).
  - `subscription_start_date` (`date`, nullable): تاريخ بدء الاشتراك (يُعتمد عليه في حساب أول فترة قراءة إذا لم توجد قراءات سابقة).
- **العلاقات:**
  - `square()`: `BelongsTo(Square::class)`
  - `meters()`: `HasMany(Meter::class)`
  - `readings()`: `HasManyThrough(Reading::class, Meter::class)`
  - `invoices()`: `HasMany(Invoice::class)`
  - `subscriptionFees()`: `HasMany(SubscriptionFee::class)`
  - `payments()`: `HasMany(Payment::class)`
  - `serviceRequests()`: `HasMany(ServiceRequest::class)`
  - `transactions()`: `HasMany(Transaction::class)`
- **الدوال المدمجة (Helper Methods):**
  - `getOutstandingBalance(): float`: تحسب الرصيد المدين للحساب العام للمشترك عبر المعادلة `max(0, -1 * (float) $this->balance)`.
  - `getRemainingSubscriptionFee(): float`: تحسب مجموع رسوم الاشتراك غير المفوترة (`subscriptionFees()->where('status', 'uninvoiced')->sum('amount')`).
  - `getSubscriptionFeeStatus(): string`: ترجع الحالة الدقيقة لرسوم الاشتراك بالأولوية التالية: `uninvoiced` ثم `partially_paid` ثم `invoiced` ثم `paid`.

### 3. نموذج العدادات `Meter` ([Meter.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Meter.php))
- **الجدول:** `meters` (يدعم `SoftDeletes`)
- **الحقول القابلة للتعبئة (`$fillable`) والتحويلات (`$casts`):**
  - `unique_code` (`string`, فريد `unique`): الرقم المكتوب على العداد.
  - `path_number` (`integer`, nullable): رقم المسار الميداني للعداد داخل المربع السكني. **قاعدة تحقق صارمة:** لا يمكن تكرار نفس `path_number` لعدادين مختلفين داخل نفس الـ `square_id`!
  - `subscriber_id` (`foreignId`): المشترك المالك للعداد.
  - `status` (`enum: active, inactive`): حالة العداد.
  - `balance` (`decimal:2`): الرصيد المالي المعزول الخاص بهذا العداد تحديداً (موجب = دائن، سالب = مدين).
  - `installed_at` (`date`, nullable): تاريخ التركيب.
  - `removed_at` (`date`, nullable): تاريخ السحب/الإزالة.
  - `initial_reading` (`integer`, default 0): القراءة الابتدائية عند التركيب.
  - `tenant_name` (`string`, nullable): اسم المستأجر المنتفع بالعداد (يظهر في الفواتير وسندات القبض والبحث).
  - `tenant_phone` (`string`, nullable): رقم هاتف المستأجر (إذا وُجد، تُرسل إشعارات الواتساب للفاتورة والسند إليه بدلاً من هاتف المالك!).
- **العلاقات:** `subscriber()`, `readings()`, `transactions()`, `payments()`.
- **الدوال المدمجة:**
  - `getLatestReading(?int $ignoreReadingId = null): ?Reading`: تجلب آخر قراءة مسجلة للعداد (مع إمكانية استثناء قراءة معينة عند التعديل).
  - `getLastReadingValue(?int $ignoreReadingId = null): float`: ترجع قيمة `current_reading` لآخر قراءة، أو `0` إذا لم توجد قراءات.
  - `getOutstandingBalance(): float`: ترجع المديونية على هذا العداد `max(0, -1 * (float) $this->balance)`.

### 4. نموذج قراءات العدادات `Reading` ([Reading.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Reading.php))
- **الجدول:** `readings` (يدعم `SoftDeletes`)
- **الحقول:**
  - `meter_id` (`foreignId`), `reading_date` (`date`), `actual_reading_date` (`date`, nullable), `previous_reading` (`decimal:2`), `current_reading` (`decimal:2`), `consumption_units` (`integer`), `is_invoiced` (`boolean`), `invoice_id` (`foreignId`, nullable), `created_by` (`foreignId` -> `users.id`), `period_start` (`string/date`), `period_end` (`string/date`).
- **الأحداث التلقائية (`booted` Hooks):**
  - `deleting`: يمنع حذف أي قراءة إذا كانت `is_invoiced == true` ويرمي استثناءً: *"لا يمكن حذف قراءة مفوترة. الرجاء حذف الفاتورة المرتبطة بها أولاً."*
  - `creating`: إذا كانت `previous_reading` فارغة، يجلبها تلقائياً من `$reading->meter->getLastReadingValue()`، ثم يحسب `consumption_units = max(0, (int) ($current_reading - $previous_reading))`.
  - `updating`: يعيد حساب `consumption_units` تلقائياً عند تعديل القراءة.

### 5. نموذج الفواتير `Invoice` ([Invoice.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Invoice.php))
- **الجدول:** `invoices` (يدعم `SoftDeletes`)
- **الحقول:**
  - `subscriber_id`, `meter_id` (nullable), `invoice_type` (`meter` | `subscription` | `tanker`), `bill_number` (فريد بصيغة `INV-YYYYMM-XXXX`), `bill_date` (`date`), `billing_period_start` (`date`), `billing_period_end` (`date`).
  - الحقول المالية (`decimal:2`): `consumption_fee` (قيمة الاستهلاك)، `subscription_installment` (قسط الاشتراك)، `tanker_charges` (رسوم الوايت)، `previous_balance` (المتأخرات السابقة المرحّلة عرضاً)، `credit_applied` (الرصيد الدائن المخصوم)، `total_amount` (إجمالي الفاتورة الحالية بدون المتأخرات السابقة لمنع ازدواجية الجمع)، `amount_paid` (المسدد من هذه الفاتورة).
  - `status` (`enum: draft, issued, partially_paid, paid, overdue`), `notes` (`text`, nullable).
- **الدوال المحاسبية المدمجة في `Invoice`:**
  - `getRemainingAmount(): float`: المتبقي من هذه الفاتورة وحدها = `max(0, total_amount - amount_paid)`.
  - `getTrueRemainingAmount(): float`: **المتبقي التراكمي الحقيقي** = المتبقي من هذه الفاتورة + مجموع `getRemainingAmount()` لجميع الفواتير الأقدم (`id < $this->id`) لنفس المشترك ونفس `invoice_type` ونفس `meter_id` (إذا كانت فاتورة عداد).
  - `isLatestInvoice(): bool`: تتحقق مما إذا كانت هذه الفاتورة هي الأحدث (`orderBy('id', 'desc')`) لنفس المشترك ونوع الفاتورة والعداد.
  - `updateStatus(): void`: تحدث حالة الفاتورة تلقائياً (وتحدث معها حالات `ServiceRequest` و`SubscriptionFee` المرتبطة بها) بناءً على ما إذا كانت هي الفاتورة الأحدث أم فاتورة سابقة، مع مراعاة إعداد `grace_period` (مدة السماح بالأيام) وإعداد `strict_overdue_on_new_invoice`.
  - `generateBillNumber(): string`: تولد رقم فاتورة متسلسلاً شهرياً (`INV-202610-0001`).
- **الأحداث التلقائية (`booted` Hooks):**
  - `created`: يستدعي `PaymentService::recalculateInvoices($subscriber, $meter_id)` لتوزيع أي رصيد دائن أو دفعات سابقة على الفاتورة الجديدة.
  - `updated`: إذا تغير `total_amount`، يحدث قيد `Transaction` المرتبط، ويعيد حساب رصيد العداد أو المشترك، ويعيد توزيع المدفوعات `recalculateInvoices`.
  - `deleting` & `deleted`: يفك ارتباط القراءات (`is_invoiced = false, invoice_id = null`)، يفك ارتباط رسوم الاشتراك (`status = 'uninvoiced', invoice_id = null`)، يفك ارتباط طلبات الوايت (`status = 'completed', invoice_id = null`)، يحذف القيد المالي من `transactions`، يفك ارتباط المدفوعات (`invoice_id = null`)، يحدث رصيد العداد/المشترك، ثم يعيد توزيع كافة المدفوعات على الفواتير المتبقية عبر `recalculateInvoices`!
  - `restored`: يستعيد قيد `Transaction` المحذوف ويعيد حساب رصيد العداد/المشترك.

### 6. نموذج بنود الفاتورة `InvoiceItem` ([InvoiceItem.php](file:///c:/Users/msi/Desktop/water-main/app/Models/InvoiceItem.php))
- **الجدول:** `invoice_items` (يدعم `SoftDeletes`)
- **الحقول:** `invoice_id`, `type` (`consumption`, `previous_balance`, `subscription`, `tanker`), `description`, `amount` (`decimal:2`).

### 7. نموذج المدفوعات وسندات القبض `Payment` ([Payment.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Payment.php))
- **الجدول:** `payments` (يدعم `SoftDeletes`)
- **الحقول:**
  - `subscriber_id`, `meter_id` (nullable - لعزل حساب العداد)، `invoice_id` (nullable)، `user_id` (المحصل الذي سجل الدفعة)، `receipt_number` (بصيغة `REC-YYYYMM-XXXX`)، `amount` (`decimal:2`)، `payment_date` (`date`)، `payment_method` (`cash` نقدي، `bank_transfer` تحويل بنكي، `check` شيك)، `reference_number` (رقم الحوالة/الشيك)، `reason` (وذلك مقابل)، `notes` (ملاحظات).
- **الأحداث التلقائية (`booted` Hooks):**
  - `updated`: يحدث قيد `Transaction`، وإذا تغير المشترك أو المبلغ يعيد حساب رصيد المشترك القديم والجديد ويعيد توزيع المدفوعات `recalculateInvoices`.
  - `deleted`: يحذف قيد `Transaction` المرتبط بالسند، يحدث رصيد العداد/المشترك، ويعيد توزيع المدفوعات المتبقية على الفواتير (`recalculateInvoices`).

### 8. نموذج رسوم الاشتراك `SubscriptionFee` ([SubscriptionFee.php](file:///c:/Users/msi/Desktop/water-main/app/Models/SubscriptionFee.php))
- **الجدول:** `subscription_fees` (يدعم `SoftDeletes`)
- **الحقول:** `subscriber_id`, `meter_id` (nullable), `amount` (`decimal:2`), `status` (`uninvoiced`, `invoiced`, `partially_paid`, `paid`), `invoice_id` (nullable), `notes`.

### 9. نموذج طلبات الخدمة / الوايت `ServiceRequest` ([ServiceRequest.php](file:///c:/Users/msi/Desktop/water-main/app/Models/ServiceRequest.php))
- **الجدول:** `service_requests` (يدعم `SoftDeletes`)
- **الحقول:** `subscriber_id`, `invoice_id` (nullable), `service_type` (افتراضياً `water_tanker`), `amount` (`decimal:2`), `service_date` (`date`), `status` (`pending` معلق، `completed` مكتمل، `invoiced` مفوتر، `paid` مسدد), `notes`.

### 10. نموذج التعرفة والتسعير `Tariff` ([Tariff.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Tariff.php))
- **الجدول:** `tariffs` (يدعم `SoftDeletes`)
- **الحقول:** `name`, `unit_price` (`decimal:2`), `is_active` (`boolean`), `start_date` (`date`, nullable), `end_date` (`date`, nullable).
- **الدالة المدمجة:** `Tariff::getActivePrice(): float`: تجلب أحدث تسعيرة نشطة تقع ضمن نطاق `start_date` و `end_date`، وإذا لم توجد ترجع السعر الافتراضي `600` ريال.

### 11. نموذج الحركات المالية (دفتر الأستاذ) `Transaction` ([Transaction.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Transaction.php))
- **الجدول:** `transactions` (يدعم `SoftDeletes`)
- **الحقول:** `subscriber_id`, `meter_id` (nullable), `type` (`invoice`, `payment`, `adjustment`), `amount` (`decimal:2` - موجب للدائن/سند القبض، سالب للمدين/الفاتورة), `description`, `reference_id`, `reference_type` (علاقة `MorphTo` تشير إلى `Invoice` أو `Payment`).

### 12. نموذج الإعدادات `Setting` ([Setting.php](file:///c:/Users/msi/Desktop/water-main/app/Models/Setting.php))
- **الجدول:** `settings`
- **الحقول:** `key` (فريد)، `type` (`text` | `image`), `value` (`text`), `image_path` (`string`, nullable), `label` (`string`).
- **الدوال:** `Setting::get($key, $default)` و `Setting::set($key, $value, $label)`.

### 13. نموذج المستخدمين `User` ([User.php](file:///c:/Users/msi/Desktop/water-main/app/Models/User.php))
- **الجدول:** `users`
- **السمات (Traits):** `HasRoles` (Spatie), `HasApiTokens` (Sanctum), `Notifiable`.
- **الحقول:** `name`, `email`, `password`, `role` (حقل قديم), `avatar` (مسار الصورة في `storage/app/public/avatars`).
- **الدوال:** `getFilamentAvatarUrl()`, `isAdmin()`, `isCollector()`, `isMeterReader()`.

---

## 1.3 القواعد المحاسبية والمعادلات البرمجية الدقيقة في النظام (Accounting & Core Services Engine)

يجب على جميع مطوري تطبيق الموبايل استيعاب القواعد الـ 6 التالية لأنها تمثل القلب النابض للنظام:

### القاعدة 1: عزل حسابات العدادات عن الحساب العام للمشترك (Account Isolation)
- المشترك الواحد (`Subscriber`) قد يمتلك عداداً واحداً أو عدة عدادات (`Meters`)، وقد يكون لكل عداد مستأجر مستقل (`tenant_name`, `tenant_phone`).
- الحركات المالية الخاصة باستهلاك عداد معين (فواتير العداد `invoice_type = 'meter'` والمدفوعات المرتبطة بها) تحمل `meter_id` وتؤثر في حقل `meters.balance` فقط عبر `TransactionService::updateMeterBalance($meter)`.
- الفواتير العامة (مثل فاتورة رسوم اشتراك غير مرتبطة بعداد أو فاتورة وايت `invoice_type = 'tanker'`) والمدفوعات غير المرتبطة بعداد يكون فيها `meter_id = null` وتؤثر في حقل `subscribers.balance` عبر `TransactionService::updateSubscriberBalance($subscriber)`.
- عند عرض إجمالي مديونية المشترك أو رصيده الدائن في قائمة المشتركين أو ملف المشترك:
  - **إجمالي الرصيد المدين الكلي:**
    $$\text{Total Debit} = \text{Subscriber::getOutstandingBalance()} + \sum_{m \in \text{meters}} m\text{::getOutstandingBalance()}$$
  - **إجمالي الرصيد الدائن الكلي:**
    $$\text{Total Credit} = \max(0, \text{subscriber.balance}) + \sum_{m \in \text{meters}} \max(0, m\text{.balance})$$

### القاعدة 2: خوارزمية توزيع المدفوعات (FIFO Payment Distribution) في [PaymentService.php](file:///c:/Users/msi/Desktop/water-main/app/Services/PaymentService.php)
عند تحصيل أي دفعة مالية عبر `collectPayment`:
1. يتم استنتاج `meter_id` تلقائياً من الفاتورة المستهدفة (`invoice_id`) إن وُجدت.
2. يُنشأ سجل `Payment` برقم سند فريد `REC-YYYYMM-XXXX`.
3. يُطبَّق مبلغ الدفعة أولاً على الفاتورة المحددة (`invoice_id`) إن كانت غير مسددة بالكامل.
4. إذا زاد مبلغ الدفعة عن المتبقي في الفاتورة المحددة (أو إذا لم يتم تحديد فاتورة أصلاً)، يتم توزيع المبلغ المتبقي تلقائياً بأسلوب **الأقدم فالأقدم (FIFO)** على الفواتير غير المسددة (`issued`, `partially_paid`, `overdue`) لنفس المشترك ونفس نطاق العزل (`meter_id`).
5. إذا بقي فائض مالي بعد تسديد جميع الفواتير، يبقى كـ **رصيد دائن (Credit Balance)** في حساب العداد أو المشترك، ويُخصم تلقائياً (`credit_applied`) عند إصدار الفاتورة القادمة!

### القاعدة 3: عدم مضاعفة المتأخرات في الفواتير (`total_amount` vs `previous_balance`)
- في [InvoiceService.php](file:///c:/Users/msi/Desktop/water-main/app/Services/InvoiceService.php)، حقل `total_amount` يمثل **صافي استحقاق الفترة الحالية فقط** (`consumption_fee + subscription_installment + tanker_charges`).
- حقل `previous_balance` يُحفظ في الفاتورة لأغراض العرض والطباعة على PDF الفاتورة وإشعار الواتساب.
- المبلغ الإجمالي المطلوب سداده الظاهر للمشترك في الفاتورة الأحدث هو:
  $$\text{Net Due} = (\text{total\_amount} + \text{previous\_balance}) - \text{credit\_applied}$$
- أو عبر الدالة التراكمية الدقيقة `$invoice->getTrueRemainingAmount()` التي تجمع المتبقي من الفاتورة الحالية + المتبقي غير المسدد من الفواتير الأقدم لنفس الحساب.

### القاعدة 4: حساب فترات القراءة الذكي في [ReadingService.php](file:///c:/Users/msi/Desktop/water-main/app/Services/ReadingService.php)
- يتم تحديد `period_start`:
  - إذا كان للعداد قراءة سابقة: `period_start = lastReading->period_end + 1 day` (أو نهاية شهر `reading_date + 1 day` للبيانات القديمة).
  - إذا لم تكن للعداد أي قراءة سابقة: `period_start = subscriber->subscription_start_date` (أو بداية الشهر الحالي إذا كان فارغاً).
- يتم تحديد `period_end` بناءً على إعداد `reading_period`:
  - `monthly` (شهرية): إذا كان `reading_day == 'last_day'` تكون نهاية الشهر الميلادي؛ وإذا كان يوماً محدداً (`20` إلى `28`) تكون في ذلك اليوم من الشهر الحالي أو الشهر التالي.
  - `half_monthly` (نصف شهرية): يوم `15` من الشهر (إذا كانت البداية $\le 15$) أو نهاية الشهر.
  - `ten_days` (كل 10 أيام): يوم `10` أو `20` أو نهاية الشهر.
  - `weekly` (أسبوعية): `period_start + 6 days`.
- يمنع النظام إدخال قراءتين لنفس العداد بنفس الفترة (`period_start` و `period_end`)، كما يمنع إدخال `current_reading < previous_reading`.

### القاعدة 5: البحث المركب السريع للمحصلين والقراء (`Square/Path Compound Search`)
- يدعم النظام في `ReadingController::searchMeter` و `InvoiceController::search` صيغة البحث المركب باستخدام الفواصل `/`, `-`, `\`, `_` (مثال: `4/1` أو `4-1`).
- الرقم الأول (`4`) يمثل رقم المربع `square_id`، والرقم الثاني (`1`) يمثل رقم المسار `path_number`.
- كما يرتب النتائج بحيث تظهر العدادات/الفواتير المطابقة تماماً لرقم المسار (`is_path_match = true`) في أعلى القائمة. يجب أن يدعم تطبيق الموبايل هذا البحث الذكي في شريط البحث الموحد مع لوحة مفاتيح سريعة.

### القاعدة 6: محرك الواتساب الثلاثي (`WhatsAppService`)
يدعم النظام 3 طرق لإرسال الفواتير وسندات القبض عبر الواتساب:
1. **`click_to_chat` (الرابط المباشر المجاني):** يولد رابط `https://wa.me/967XXXXXXXXX?text=...` بعد تعويض المتغيرات ومعالجة البلوكات الشرطية `{if:subscription_fee}...{/if:subscription_fee}`، ويفتحه مباشرة في تطبيق واتساب على الهاتف.
2. **`meta_api` (Meta Cloud API الرسمي):** يولد ملف الـ PDF في الخلفية عبر `SendWhatsAppInvoiceJob` أو `SendWhatsAppPaymentJob`، يرفعه إلى خوادم فيسبوك (`graph.facebook.com/v19.0/{phoneId}/media`)، ثم يرسله كرسالة Template مرفقة بملف الـ PDF.
3. **`local_api` (الخادم المحلي المجاني المرفق بالـ PDF):** يولد ملف الـ PDF ويرسله مع النص المخصص عبر طلب `POST http://localhost:3000/api/send` إلى خادم Node.js المحلي المتصل عبر QR Code.

---

## 1.4 مصفوفة الأدوار والصلاحيات الأمنية (Roles, Policies & Filament Shield Permissions)

يعتمد النظام على `Spatie Permission` و `Filament Shield`:
- **الأدوار الأساسية المعتمدة في النظام:**
  1. `super_admin` / `admin` (مدير النظام): صلاحية كاملة على كافة الموارد والصفحات والودجات والإعدادات والتصدير.
  2. `collector` (محصل ديون): صلاحيات عرض الفواتير، عرض وإنشاء وتعديل المدفوعات (سندات القبض)، عرض القراءات، وعرض المشتركين/المستأجرين.
  3. `reader` / `meter_reader` (قارئ عدادات): صلاحيات عرض وإنشاء وتعديل القراءات، وعرض العدادات والمشتركين/المستأجرين.
  4. **أي دور مخصص إضافي** يتم إنشاؤه من شاشة إدارة الأدوار (`RoleResource`).
- **تنسيق أسماء الصلاحيات في النظام:**
  - توجد 12 سياسة (`Policies`) في `app/Policies/` (`InvoicePolicy`, `MeterPolicy`, `PaymentPolicy`, `ReadingPolicy`, `RolePolicy`, `ServiceRequestPolicy`, `SettingPolicy`, `SquarePolicy`, `SubscriberPolicy`, `SubscriptionFeePolicy`, `TariffPolicy`, `TransactionPolicy`, `UserPolicy`).
  - كل سياسة تتحقق من الصلاحيات بصيغة Filament Shield القياسية:
    - `ViewAny:{Model}`, `View:{Model}`, `Create:{Model}`, `Update:{Model}`, `Delete:{Model}`, `DeleteAny:{Model}`, `Restore:{Model}`, `RestoreAny:{Model}`, `ForceDelete:{Model}`, `ForceDeleteAny:{Model}`, `Replicate:{Model}`, `Reorder:{Model}` (حيث `{Model}` هو: `Invoice`, `Meter`, `Payment`, `Reading`, `Role`, `ServiceRequest`, `Setting`, `Square`, `Subscriber`, `SubscriptionFee`, `Tariff`, `Transaction`, `User`).
  - بالإضافة إلى صلاحيات الصفحات المخصصة مثل `View:ExportDataPage`, `View:InvoiceSettings`, `View:ReadingSettings`, `View:ReceiptSettings`, `View:WhatsAppSettings`، وصلاحيات الودجات، فضلاً عن الصلاحيات الموجودة في `sync_permissions.php` (`view_any_reading`, `create_reading`, `view_any_payment`, `create_payment`, إلخ).
  - **في تطبيق الموبايل:** يرجع مسار `/api/login` و `/api/user` مصفوفتين للمستخدم: `roles` و `permissions`. سيقوم تطبيق Flutter ببناء `PermissionGuard` ذكي يتحقق من الدور (`super_admin` / `admin` / `collector` / `reader`) ومن مصفوفة `permissions` لإظهار أو إخفاء التبويبات والأزرار والعمليات ديناميكياً، مع حماية مماثلة في الـ Backend API.

---

# الباب الثاني: دراسة الـ API الحالي وتحليل الفجوات والمواصفات الكاملة للـ API المطلوب (API Audit & Complete Specification)

من أجل أن يحتوي تطبيق الموبايل على **كل شيء موجود في كود Laravel بنسبة 100%**، قمنا بمطابقة المسارات الموجودة حالياً في [routes/api.php](file:///c:/Users/msi/Desktop/water-main/routes/api.php) مع كافة الموارد والصفحات والعمليات الموجودة في `app/Filament/` و [routes/web.php](file:///c:/Users/msi/Desktop/water-main/routes/web.php).

## 2.1 التقييم التفصيلي للـ 24 مساراً الموجودة حالياً في `routes/api.php`

يوجد حالياً في [routes/api.php](file:///c:/Users/msi/Desktop/water-main/routes/api.php) **24 مساراً** موزعة على 6 متحكمات (Controllers). الجدول التالي يوضح حالتها وما ينقصها:

| م | الطريقة والمسار الحالي | المتحكم والدالة | الوظيفة الحالية | الملاحظات والقصور الحالي (Gap) |
|---|---|---|---|---|
| 1 | `POST /api/login` | [`AuthController@login`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/AuthController.php#L13-L42) | تسجيل الدخول وإصدار `Bearer Token` مع `roles` و`permissions` | ممتاز وجاهز، يحتاج فقط إرجاع قائمة صلاحيات موحدة أو علامة `is_super_admin`. |
| 2 | `GET /api/user` | [`AuthController@user`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/AuthController.php#L44-L57) | جلب بيانات المستخدم الحالي وأدواره وصلاحياته | جاهز ومكتمل. |
| 3 | `POST /api/logout` | [`AuthController@logout`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/AuthController.php#L100-L107) | حذف التوكن الحالي وتسجيل الخروج | جاهز ومكتمل. |
| 4 | `POST /api/profile` | [`AuthController@updateProfile`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/AuthController.php#L59-L98) | تحديث الاسم، الإيميل، كلمة المرور، والصورة الرمزية `avatar` | جاهز ومكتمل (يُفضّل إضافة `password_confirmation` اختياري للتطابق مع Filament). |
| 5 | `GET /api/readings` | [`ReadingController@index`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/ReadingController.php#L24-L29) | جلب آخر 50 قراءة فقط (`limit(50)`) | **ناقص:** لا يدعم التصفح (Pagination)، ولا الفلترة بحالة الفوترة `is_invoiced`، أو المربع `square_id`، أو التاريخ، أو البحث. |
| 6 | `GET /api/meters/search` | [`ReadingController@searchMeter`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/ReadingController.php#L31-L85) | بحث العدادات بالاسم/الكود/المسار أو المركب `4/1` مع فلتر المربع | ممتاز جداً، يحتاج فقط إرجاع `balance` و`readings_count` وآخر قراءة لتسهيل العرض. |
| 7 | `GET /api/meters/{id}/period` | [`ReadingController@getMeterPeriod`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/ReadingController.php#L87-L125) | جلب آخر قراءة، فترة القراءة المحسوبة، ورسوم الاشتراك/الوايت المعلقة | ممتاز ومطابق لمنطق `ReadingResource`. |
| 8 | `POST /api/readings` | [`ReadingController@store`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/ReadingController.php#L127-L252) | حفظ قراءة جديدة + صورة + إصدار فاتورة تلقائياً إن كان مفعلاً | ممتاز، لكن حقل `image` يُحفظ في المجلد دون تخزين مساره في جدول `readings` إذا لم يكن العمود موجوداً، ويحتاج دعم إرسال واتساب تلقائي. |
| 9 | `GET /api/invoices/unpaid` | [`InvoiceController@unpaidInvoices`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/InvoiceController.php#L15-L28) | جلب الفواتير غير المسددة مع `true_remaining_amount` | ممتاز للمحصل، لكن ينقصه مسار جلب **كل الفواتير** (بما فيها المسددة والمسودات) مع فلاتر Filament. |
| 10 | `GET /api/invoices/search` | [`InvoiceController@search`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/InvoiceController.php#L30-L94) | بحث الفواتير غير المسددة بالاسم/الرقم/المسار `4/1` | يبحث فقط في غير المسددة؛ نحتاج خيار `status=all` للبحث في كل الفواتير. |
| 11 | `GET /api/invoices/{id}` | [`InvoiceController@show`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/InvoiceController.php#L96-L107) | عرض تفاصيل فاتورة مع القراءات و`true_remaining_amount` | يحتاج تحميل العلاقات `items` و`payments` و`subscriber.square` لعرض الفاتورة كاملة. |
| 12 | `POST /api/invoices/{id}/pay` | [`InvoiceController@pay`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/InvoiceController.php#L109-L174) | تحصيل دفعة لفاتورة محددة عبر `PaymentService::collectPayment` | ممتاز، لكن يمرر `reason = null` دائماً؛ يجب تمرير `$request->reason` ("وذلك مقابل"). |
| 13 | `GET /api/squares` | [`SubscriberController@squares`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L17-L21) | جلب المربعات السكنية (`id, name` فقط) | **ناقص:** لا يرجع `description` ولا عدد المشتركين `subscribers_count` ولا `created_at`. |
| 14 | `POST /api/squares` | [`SubscriberController@storeSquare`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L23-L35) | إضافة مربع سكني (`name` فقط) | **ناقص:** لا يستقبل حقل `description` الموجود في `SquareResource`. |
| 15 | `GET /api/subscribers` | [`SubscriberController@index`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L131-L160) | جلب المشتركين مع البحث وحساب الرصيد ورقم العداد الأول | **ناقص:** لا يدعم الفلترة بـ `square_id` و`status`، ولا يرجع `square`, `subscription_start_date`, `meters_count`. |
| 16 | `POST /api/subscribers` | [`SubscriberController@store`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L37-L54) | إضافة مشترك جديد | ممتاز ومكتمل. |
| 17 | `GET /api/subscribers/{id}` | [`SubscriberController@show`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L162-L169) | عرض مشترك مع `meters` | **ناقص:** يحتاج إرجاع ملخص الحساب المالي (`credit_balance`, `debit_balance`, أرصدة العدادات المعزولة) والعلاقات الـ 5 (`invoices`, `payments`, `subscriptionFees`, `serviceRequests`, `transactions`). |
| 18 | `PUT /api/subscribers/{id}` | [`SubscriberController@update`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L171-L192) | تعديل بيانات المشترك | ينقصه السماح بتعديل `subscription_start_date`. |
| 19 | `GET /api/subscribers/{id}/statement` | [`SubscriberController@statement`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L233-L275) | كشف حساب مبسط يدمج الفواتير والمدفوعات | جيد، لكن في Filament يوجد أيضاً `SubscriberTransactionsTable` الذي يعرض جدول `transactions` الحقيقي مع فلتر الحساب العام (`general`) أو عداد محدد (`meter_id`). |
| 20 | `POST /api/meters` | [`SubscriberController@storeMeter`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L56-L91) | إضافة عداد جديد مع التحقق من فريدية `path_number` في المربع | ممتاز ومطابق لـ `MeterForm`. |
| 21 | `PUT /api/meters/{id}` | [`SubscriberController@updateMeter`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L194-L231) | تعديل بيانات العداد | ينقصه السماح بتغيير `subscriber_id` عند الحاجة. |
| 22 | `POST /api/subscription-fees` | [`SubscriberController@storeSubscriptionFee`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L93-L109) | إضافة رسوم اشتراك | ممتاز. |
| 23 | `POST /api/service-requests` | [`SubscriberController@storeServiceRequest`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L111-L129) | إضافة طلب وايت ماء | ممتاز. |
| 24 | `POST /api/subscription-fees/{id}/invoice` & `POST /api/service-requests/{id}/invoice` | [`SubscriberController`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SubscriberController.php#L277-L349) | إصدار فاتورة لرسوم اشتراك أو طلب وايت | ممتاز. |
| 25 | `GET /api/admin/stats` | [`AdminController@stats`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/AdminController.php#L15-L39) | إحصائيات مبسطة (5 أرقام فقط) | **ناقص:** لا يرجع بيانات الرسم البياني الشهري `MonthlyRevenueChart`، ولا `LatestInvoicesWidget`، ولا `OverdueInvoicesWidget`، ولا عدد المشتركين النشطين وعدد الفواتير المتأخرة كما في `StatsOverview`. |
| 26 | `GET /api/settings` & `POST /api/settings` | [`SettingController`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/SettingController.php#L11-L87) | قراءة وتحديث 10 مفاتيح إعدادات فقط | **ناقص بشدة:** يوجد في النظام أكثر من **115 مفتاح إعدادات** موزعة على `InvoiceSettings` (7 تبويبات + شعارات)، `ReceiptSettings` (5 تبويبات)، `ReadingSettings`، `WhatsAppSettings` (مع التحكم بالخادم المحلي وكود QR والسجلات)، و`SettingResource` العام! |

---

## 2.2 تحليل الفجوات الشامل (Gap Analysis: Web/Filament vs Current API)

عند مقارنة لوحة تحكم Filament والنظام الخلفي بالـ API الحالي، نجد أن **الوحدات والعمليات التالية غائبة كلياً أو جزئياً عن `routes/api.php`** ويجب إضافتها في المرحلة الأولى (Sprint 1) لكي يعمل تطبيق الموبايل بكامل طاقته:

1. **إدارة المربعات السكنية الكاملة (`SquareResource`):**
   - تعديل مربع سكني (`PUT /api/squares/{id}`).
   - حذف مربع سكني وحذف جماعي (`DELETE /api/squares/{id}`, `POST /api/squares/bulk-delete`).
   - عرض تفاصيل المربع مع قائمة المشتركين التابعين له (`GET /api/squares/{id}` - يعادل `SubscribersRelationManager`).
2. **إدارة المشتركين الكاملة وعلاقاتهم (`SubscriberResource`):**
   - حذف مشترك وحذف جماعي (`DELETE /api/subscribers/{id}`, `POST /api/subscribers/bulk-delete`).
   - تحصيل دفعة مباشرة من حساب المشترك (`collect_payment` Row Action في `SubscriberResource`: مع خيار تحديد فاتورة أو التوزيع التلقائي على الأقدم FIFO).
   - سجل الحركات المالية المفصل للمشترك (`SubscriberTransactionsTable`: جدول `transactions` مع الفلترة بـ `meter_id = general` أو رقم عداد محدد).
3. **إدارة العدادات المستقلة (`MeterResource`):**
   - قائمة كافة العدادات في النظام مع البحث والفلترة بالحالة والمربع (`GET /api/meters`).
   - عرض تفاصيل عداد محدد (`GET /api/meters/{id}`).
   - حذف عداد وحذف جماعي (`DELETE /api/meters/{id}`, `POST /api/meters/bulk-delete`).
4. **عمليات قراءات العدادات المتقدمة (`ReadingResource`):**
   - تعديل قراءة غير مفوترة (`PUT /api/readings/{id}`).
   - حذف قراءة غير مفوترة (`DELETE /api/readings/{id}`).
   - الحذف الجماعي الذكي للقراءات (`POST /api/readings/bulk-delete` الذي يتخطى القراءات المفوترة ويرجع عدد المحذوف وعدد المتخطى).
   - **الإصدار الجماعي للفواتير من القراءات المحددة** (`POST /api/readings/bulk-issue-invoices` الذي يجمع القراءات حسب `meter_id` ويستدعي `InvoiceService::generateInvoice` لكل عداد).
5. **عمليات الفواتير المتقدمة (`InvoiceResource`):**
   - قائمة كافة الفواتير مع الفلاتر الـ 4 (`billing_period` كأزواج `start|end`، `square_id`، `status`، `subscriber_id`) والبحث بـ `beneficiary_name` (المشترك أو المستأجر).
   - قائمة فترات الفوترة المتاحة للفلتر (`GET /api/invoices/billing-periods`).
   - الإنشاء اليدوي لفاتورة (`POST /api/invoices`) والتعديل اليدوي لفاتورة (`PUT /api/invoices/{id}` الذي يُشغّل `Invoice::updated` لتحديث القيد المالي وإعادة توزيع المدفوعات).
   - الحذف الفردي والجماعي للفواتير (`DELETE /api/invoices/{id}`, `POST /api/invoices/bulk-delete`) واستعادة الفاتورة المحذوفة (`POST /api/invoices/{id}/restore`).
   - مسارات الأزرار الثلاثة في أعلى شاشة الفواتير (`Header Actions`):
     - `POST /api/invoices/generate-meter` (إصدار فاتورة عداد).
     - `POST /api/invoices/generate-subscription` (إصدار فاتورة اشتراك لمشترك).
     - `POST /api/invoices/generate-tanker` (إصدار فاتورة وايت لمشترك).
   - توليد وتحميل ملف الـ PDF الرسمي للفاتورة عبر الـ API (`GET /api/invoices/{id}/pdf`) لأن مسار `/invoices/{invoice}/print` الحالي يستخدم `web` middleware.
   - جلب رابط واتساب المباشر (`GET /api/invoices/{id}/whatsapp-link`) وإرسال الفاتورة PDF عبر واتساب الخلفية (`POST /api/invoices/{id}/send-whatsapp`).
6. **إدارة المدفوعات وسندات القبض الكاملة (`PaymentResource`):**
   - قائمة كافة المدفوعات مع البحث والفلترة بـ `payment_method` والمشترك والتاريخ (`GET /api/payments`).
   - إنشاء سند قبض عام أو مرتبط بفاتورة (`POST /api/payments`) مع حقل `reason` ("وذلك مقابل") و`payment_date` و`reference_number`.
   - جلب بيانات الفاتورة المستحقة تلقائياً عند اختيار المشترك (`GET /api/subscribers/{id}/unpaid-invoices-summary`).
   - تعديل سند قبض (`PUT /api/payments/{id}`) وحذف سند قبض (`DELETE /api/payments/{id}`, `POST /api/payments/bulk-delete`).
   - تحميل ملف PDF لسند القبض (`GET /api/payments/{id}/pdf`)، ورابط واتساب السند (`GET /api/payments/{id}/whatsapp-link`)، وإرسال السند PDF عبر واتساب (`POST /api/payments/{id}/send-whatsapp`).
7. **إدارة رسوم الاشتراك الكاملة (`SubscriptionFeeResource`):**
   - عرض قائمة كافة رسوم الاشتراك مع الفلترة بالحالة وحساب الإجمالي (`GET /api/subscription-fees`).
   - تعديل وحذف رسوم الاشتراك (`PUT /api/subscription-fees/{id}`, `DELETE /api/subscription-fees/{id}`, `POST /api/subscription-fees/bulk-delete`).
8. **إدارة طلبات الوايت الكاملة (`ServiceRequestResource`):**
   - عرض قائمة كافة طلبات الوايت مع الفلترة بالحالة ونطاق التاريخ `created_from`..`created_until` وحساب الإجمالي (`GET /api/service-requests`).
   - زر **"إنجاز" (`mark_completed`)** لتحويل الطلب من `pending` إلى `completed` (`PATCH /api/service-requests/{id}/complete`).
   - تعديل وحذف طلبات الوايت (`PUT /api/service-requests/{id}`, `DELETE /api/service-requests/{id}`, `POST /api/service-requests/bulk-delete`).
9. **إدارة التعرفة والتسعير الكاملة (`TariffResource`):**
   - غائبة كلياً عن الـ API! نحتاج: `GET /api/tariffs` (مع فلتر المحذوفات `trashed`: `without`, `with`, `only`)، `POST /api/tariffs`، `PUT /api/tariffs/{id}`، `DELETE /api/tariffs/{id}` (حذف ناعم)، `POST /api/tariffs/{id}/restore` (استعادة)، `DELETE /api/tariffs/{id}/force` (حذف نهائي)، والإجراءات الجماعية المقابلة.
10. **سجل الحركات العام / دفتر الأستاذ (`TransactionResource`):**
    - غائب عن الـ API! نحتاج: `GET /api/transactions` (للقراءة فقط كما في Filament `canCreate() = false`) مع البحث والفلترة بالمشترك والعداد والنوع والتاريخ.
11. **إدارة المستخدمين والأدوار والصلاحيات (`UserResource` & `RoleResource`):**
    - غائبة كلياً عن الـ API! نحتاج:
      - `GET /api/users`, `POST /api/users`, `GET /api/users/{id}`, `PUT /api/users/{id}`, `DELETE /api/users/{id}`, `POST /api/users/bulk-delete`.
      - `GET /api/roles`, `GET /api/permissions` (مقسمة حسب الموارد والصفحات والودجات كما في Filament Shield)، `POST /api/roles`, `GET /api/roles/{id}`, `PUT /api/roles/{id}`, `DELETE /api/roles/{id}`, `POST /api/roles/bulk-delete`.
12. **الإعدادات الشاملة والـ 5 شاشات المتخصصة:**
    - `GET /api/settings/reading` & `POST /api/settings/reading` (`ReadingSettings`).
    - `GET /api/settings/invoice` & `POST /api/settings/invoice` (`InvoiceSettings` بجميع التبويبات الـ 7 ورفع الصور `invoice_logo`, `site_favicon`, `invoice_watermark`).
    - `GET /api/settings/receipt` & `POST /api/settings/receipt` (`ReceiptSettings` بجميع التبويبات الـ 5).
    - `GET /api/settings/whatsapp` & `POST /api/settings/whatsapp` (`WhatsAppSettings`).
    - التحكم بالخادم المحلي للواتساب: `GET /api/whatsapp/local-status` (يرجع حالة الخادم `connected`/`qr`/`waiting`/`offline`، وصورة `qr` بصيغة Base64، وآخر 15 سطراً من `whatsapp.log` و`queue.log`)، `POST /api/whatsapp/local-start`، و`POST /api/whatsapp/local-stop`.
    - إدارة جدول الإعدادات العام (`SettingResource`): `GET /api/settings/items`, `POST /api/settings/items`, `PUT /api/settings/items/{id}`, `DELETE /api/settings/items/{id}`.
13. **تصدير البيانات إلى إكسل (`ExportDataPage` & `ExportController`):**
    - مسارات محمية بـ `auth:sanctum`:
      - `GET /api/exports/summary` (إحصائيات صفحة التصدير: إجمالي المشتركين، الفواتير، القراءات، المربعات).
      - `GET /api/exports/subscribers` (تحميل ملف `.xlsx`).
      - `GET /api/exports/invoices` (تحميل ملف `.xlsx`).
      - `GET /api/exports/readings` (تحميل ملف `.xlsx`).

---

## 2.3 المواصفات الهندسية الكاملة لجميع مسارات الـ API (68 Endpoint Specification)

لتسهيل عمل مطوري الـ Backend والـ Mobile بدون أي التباس، هذا هو الدليل المرجعي الكامل لجميع الـ **68 Endpoint** (الحالية + الجديدة) التي سيعمل عليها تطبيق Flutter:

### المجموعة 1: المصادقة والحساب الشخصي (`Auth & Profile`)
1. `POST /api/login`
   - **المدخلات:** `{ "email": "string", "password": "string" }`
   - **المخرجات (`200 OK`):** `{ "access_token": "...", "token_type": "Bearer", "user": { "id", "name", "email", "avatar", "roles": [...], "permissions": [...] } }`
2. `GET /api/user` -> يرجع كائن `user` المحدث مع الأدوار والصلاحيات.
3. `POST /api/profile` (`multipart/form-data`)
   - **المدخلات:** `name`, `email`, `password` (nullable, min:6), `password_confirmation` (nullable, same:password), `avatar` (nullable, image, max:5120).
4. `POST /api/logout` -> يحذف التوكن الحالي.

### المجموعة 2: لوحة القيادة والودجات الإحصائية (`Dashboard & Analytics`)
5. `GET /api/admin/dashboard` (يحدّث أو يكمل `GET /api/admin/stats`)
   - **المخرجات (`200 OK`):**
     ```json
     {
       "stats_overview": {
         "active_subscribers_count": 145,
         "total_subscribers_count": 160,
         "outstanding_amount": 1250400.00,
         "monthly_collection": 480000.00,
         "overdue_invoices_count": 23,
         "unpaid_invoices_count": 41,
         "readings_this_month": 138,
         "subscribers_sparkline": [7, 3, 4, 5, 6, 3, 5],
         "collection_sparkline": [3, 5, 7, 4, 6, 8, 5]
       },
       "monthly_revenue_chart": {
         "labels": ["مايو 2026", "يونيو 2026", "يوليو 2026", "أغسطس 2026", "سبتمبر 2026", "أكتوبر 2026"],
         "data": [320000, 410000, 395000, 450000, 512000, 480000]
       },
       "latest_invoices": [ /* أحدث 5 فواتير مع المشترك */ ],
       "overdue_invoices": [ /* الفواتير المتأخرة مرتبة حسب bill_date */ ]
     }
     ```

### المجموعة 3: المربعات السكنية (`Squares Module`)
6. `GET /api/squares` -> يرجع المربعات مع `id, name, description, subscribers_count, created_at` مع دعم البحث `?search=`.
7. `GET /api/squares/{id}` -> يرجع بيانات المربع مع قائمة المشتركين التابعين له (`subscribers`: `id, name, phone, address, status, balance`).
8. `POST /api/squares` -> `{ "name": "required|max:100", "description": "nullable|string" }`.
9. `PUT /api/squares/{id}` -> `{ "name": "required|max:100", "description": "nullable|string" }`.
10. `DELETE /api/squares/{id}` -> حذف مربع سكني.
11. `POST /api/squares/bulk-delete` -> `{ "ids": [1, 2, 3] }`.

### المجموعة 4: المشتركون والملف المالي 360 درجة (`Subscribers Module`)
12. `GET /api/subscribers`
    - **الفلاتر:** `?q=` (بحث بالاسم/الهاتف/كود العداد)، `?square_id=`، `?status=` (`active`/`inactive`/`suspended`)، `?page=`، `?per_page=`.
    - **المخرجات:** بيانات المشترك + `square` + `meters_count` + `credit_balance` + `debit_balance` + `account_balance` + `meter_number` + `subscription_start_date`.
13. `POST /api/subscribers` -> `{ "name", "phone", "square_id", "status", "subscription_start_date", "address" }`.
14. `GET /api/subscribers/{id}`
    - **المخرجات الكاملة (تغطي كافة أقسام `SubscriberResource` والـ 5 Relation Managers):**
      - البيانات الأساسية للمشترك + `square`.
      - `financial_summary`: `{ "total_credit_balance": ..., "total_debit_balance": ..., "general_balance": ..., "remaining_subscription_fee": ..., "subscription_fee_status": "..." }`.
      - `meters`: قائمة العدادات مع رصيد كل عداد (`meter_credit`, `meter_debit`, `readings_count`, `tenant_name`, `tenant_phone`).
      - `invoices`: فواتير المشترك مرتبة تنازلياً.
      - `payments`: مدفوعات المشترك مرتبة تنازلياً.
      - `subscription_fees`: رسوم اشتراك المشترك.
      - `service_requests`: طلبات الوايت للمشترك.
15. `PUT /api/subscribers/{id}` -> تحديث بيانات المشترك (شاملاً `subscription_start_date`).
16. `DELETE /api/subscribers/{id}` & `POST /api/subscribers/bulk-delete`.
17. `GET /api/subscribers/{id}/statement` -> كشف الحساب المدمج (فواتير + مدفوعات).
18. `GET /api/subscribers/{id}/transactions` -> سجل الحركات الفعلي من جدول `transactions` مع فلتر `?meter_id=general` (حيث `meter_id IS NULL`) أو `?meter_id={id}` (مماثل تماماً لمكون `SubscriberTransactionsTable`).
19. `POST /api/subscribers/{id}/collect-payment` -> تحصيل دفعة مباشرة للمشترك (مع `invoice_id` اختياري، أو توزيع تلقائي FIFO على الأقدم عبر `PaymentService::collectPayment`).

### المجموعة 5: العدادات والمسارات (`Meters Module`)
20. `GET /api/meters` -> قائمة كافة العدادات مع البحث والفلترة بـ `status` و`square_id` و`subscriber_id`.
21. `GET /api/meters/search` -> البحث السريع والمركب (`4/1`) مع ترتيب `is_path_match`.
22. `GET /api/meters/{id}` -> تفاصيل العداد + آخر قراءة + سجل قراءات العداد.
23. `GET /api/meters/{id}/period` -> حساب فترة القراءة القادمة (`period_start`, `period_end`, `last_reading`, `auto_issue_invoice`, `has_subscription_fee`, `subscription_installment`, `has_tanker_fee`, `tanker_installment`).
24. `POST /api/meters` -> إنشاء عداد مع التحقق من عدم تكرار `path_number` في نفس المربع.
25. `PUT /api/meters/{id}` -> تعديل عداد مع التحقق من `path_number`.
26. `DELETE /api/meters/{id}` & `POST /api/meters/bulk-delete`.

### المجموعة 6: قراءات العدادات (`Readings Module`)
27. `GET /api/readings` -> قائمة القراءات مع الفلترة بـ `is_invoiced` (`true`/`false`)، `square_id`، `meter_id`، `subscriber_id`، `from_date`، `to_date`، والبحث.
28. `POST /api/readings` -> تسجيل قراءة جديدة (مع التحقق من أن `current_reading >= previous_reading`، وعدم تكرار الفترة `period_start`..`period_end`، وإصدار فاتورة تلقائياً إذا كان `auto_issue_invoice_on_reading == '1'` مع خيار تضمين `subscription_installment` و`tanker_installment`).
29. `PUT /api/readings/{id}` -> تعديل قراءة (مسموح فقط إذا كانت `is_invoiced == false`).
30. `DELETE /api/readings/{id}` -> حذف قراءة (مسموح فقط إذا كانت `is_invoiced == false`).
31. `POST /api/readings/bulk-delete` -> يستقبل `{ "ids": [...] }`، يحذف غير المفوترة ويتخطى المفوترة، ويرجع `{ "deleted_count": X, "skipped_count": Y }`.
32. `POST /api/readings/bulk-issue-invoices` -> يستقبل `{ "ids": [...] }` للقراءات غير المفوترة، يجمعها حسب `meter_id`، ويستدعي `InvoiceService::generateInvoice` لكل عداد، ويرجع عدد الفواتير الصادرة.

### المجموعة 7: الفواتير (`Invoices Module`)
33. `GET /api/invoices` -> جميع الفواتير مع البحث الذكي (رقم الفاتورة، اسم المشترك، اسم المستأجر، كود العداد، المسار `4/1`) والفلاتر (`billing_period` بصيغة `YYYY-MM-DD|YYYY-MM-DD`، `square_id`، `status`، `subscriber_id`، `invoice_type`).
34. `GET /api/invoices/billing-periods` -> يرجع قائمة الفترات المميزة (`billing_period_start|billing_period_end`) لملء قائمة فلتر فترات الفوترة.
35. `GET /api/invoices/unpaid` & `GET /api/invoices/search` -> مسارات المحصل السريعة.
36. `GET /api/invoices/{id}` -> تفاصيل الفاتورة الكاملة مع `subscriber.square`, `meter`, `items`, `readings`, `payments`, `true_remaining_amount`, `is_latest_invoice`.
37. `POST /api/invoices` -> الإنشاء اليدوي لفاتورة (`CreateInvoice` في Filament: يحول الحالة إلى `issued`، يسجل قيد `Transaction`، ويحدث رصيد العداد/المشترك ويعيد توزيع المدفوعات).
38. `POST /api/invoices/generate-meter` -> إصدار فاتورة عداد عبر `InvoiceService::generateInvoice` (مع `subscriber_id`, `meter_id`, `has_subscription_fee`, `subscription_installment`, `has_tanker_fee`, `tanker_installment`, `notes`).
39. `POST /api/invoices/generate-subscription` -> إصدار فاتورة اشتراك عبر `InvoiceService::generateInvoice(..., invoiceType: 'subscription')`.
40. `POST /api/invoices/generate-tanker` -> إصدار فاتورة وايت عبر `InvoiceService::generateInvoice(..., invoiceType: 'tanker')`.
41. `PUT /api/invoices/{id}` -> تعديل فاتورة (يُشغّل تلقائياً `Invoice::updated` لتحديث قيد الدفتر والرصيد وإعادة توزيع المدفوعات).
42. `DELETE /api/invoices/{id}` & `POST /api/invoices/bulk-delete` -> حذف فاتورة (يُشغّل `Invoice::deleted` لفك ارتباط القراءات والاشتراكات والوايت والمدفوعات وحذف القيد وإعادة احتساب الأرصدة).
43. `POST /api/invoices/{id}/restore` -> استعادة فاتورة محذوفة.
44. `POST /api/invoices/{id}/pay` -> تحصيل دفعة للفاتورة (مع دعم حقل `reason`).
45. `GET /api/invoices/{id}/pdf` -> توليد وإرجاع ملف `invoice-{bill_number}.pdf` (`application/pdf` A5 Landscape).
46. `GET /api/invoices/{id}/whatsapp-link` -> يرجع `{ "url": "https://wa.me/...", "message": "..." }` من `WhatsAppService::getInvoiceMessageUrl`.
47. `POST /api/invoices/{id}/send-whatsapp` -> يطلق `SendWhatsAppInvoiceJob::dispatch($invoice)` لإرسال الـ PDF عبر `meta_api` أو `local_api`.

### المجموعة 8: المدفوعات وسندات القبض (`Payments Module`)
48. `GET /api/payments` -> قائمة كافة سندات القبض مع البحث بـ `receipt_number` واسم المشترك، والفلترة بـ `payment_method`، `subscriber_id`، `from_date`، `to_date`.
49. `GET /api/payments/{id}` -> تفاصيل سند القبض مع `subscriber.square`, `meter`, `invoice`, `user`, وبيانات `receipt_data` الكاملة (بما فيها المبلغ كتابةً بالتفقيط العربي).
50. `POST /api/payments` -> تسجيل سند قبض جديد عبر `PaymentService::collectPayment` (`subscriber_id`, `invoice_id` اختياري، `amount`, `payment_date`, `payment_method`, `reference_number`, `reason`, `notes`).
51. `PUT /api/payments/{id}` -> تعديل سند قبض (يُشغّل `Payment::updated`).
52. `DELETE /api/payments/{id}` & `POST /api/payments/bulk-delete` -> حذف سند قبض (يُشغّل `Payment::deleted` ويعيد احتساب الفواتير والأرصدة).
53. `GET /api/payments/{id}/pdf` -> توليد وإرجاع ملف `receipt-{receipt_number}.pdf` (`application/pdf` A5 Landscape).
54. `GET /api/payments/{id}/whatsapp-link` -> يرجع رابط `WhatsAppService::getPaymentMessageUrl`.
55. `POST /api/payments/{id}/send-whatsapp` -> يطلق `SendWhatsAppPaymentJob::dispatch($payment)`.

### المجموعة 9: رسوم الاشتراك وطلبات الوايت والتعرفة والدفتر (`Auxiliary Financial Modules`)
56. **رسوم الاشتراك (`Subscription Fees`):**
    - `GET /api/subscription-fees` (مع فلتر `status`، `subscriber_id`، وإجمالي المبالغ `total_sum`).
    - `POST /api/subscription-fees`, `PUT /api/subscription-fees/{id}`, `DELETE /api/subscription-fees/{id}`, `POST /api/subscription-fees/bulk-delete`, `POST /api/subscription-fees/{id}/invoice`.
57. **طلبات الوايت (`Service Requests`):**
    - `GET /api/service-requests` (مع فلتر `status`، `created_from`، `created_until`، وإجمالي المبالغ `total_sum`).
    - `POST /api/service-requests`, `PUT /api/service-requests/{id}`, `DELETE /api/service-requests/{id}`, `POST /api/service-requests/bulk-delete`.
    - `PATCH /api/service-requests/{id}/complete` (تحويل الحالة من `pending` إلى `completed`).
    - `POST /api/service-requests/{id}/invoice` (إصدار فاتورة وايت).
58. **التعرفة والتسعير (`Tariffs`):**
    - `GET /api/tariffs` (مع فلتر `trashed=without|with|only` و`active_price` الحالي).
    - `POST /api/tariffs`, `PUT /api/tariffs/{id}`, `DELETE /api/tariffs/{id}`, `POST /api/tariffs/{id}/restore`, `DELETE /api/tariffs/{id}/force`.
59. **سجل الحركات العام (`Transactions Ledger`):**
    - `GET /api/transactions` (للقراءة فقط، مع البحث والفلترة بـ `subscriber_id`, `meter_id`, `type`, والتاريخ).

### المجموعة 10: المستخدمون، الأدوار، الصلاحيات، الإعدادات، والتصدير (`System Administration & Exports`)
60. **المستخدمون (`Users`):**
    - `GET /api/users` (مع فلتر الدور `role`)، `POST /api/users` (مع إسناد مصفوفة `roles`)، `GET /api/users/{id}`, `PUT /api/users/{id}`, `DELETE /api/users/{id}`, `POST /api/users/bulk-delete`.
61. **الأدوار والصلاحيات (`Roles & Shield Permissions`):**
    - `GET /api/roles` (مع `permissions_count`), `GET /api/permissions` (مصنفة حسب الموارد والصفحات والودجات)، `POST /api/roles`, `GET /api/roles/{id}`, `PUT /api/roles/{id}` (مع `syncPermissions`), `DELETE /api/roles/{id}`.
62. **مراكز الإعدادات الخمسة (`Settings Hub`):**
    - `GET /api/settings/reading` & `POST /api/settings/reading` (الـ 5 مفاتيح الخاصة بـ `ReadingSettings`).
    - `GET /api/settings/invoice` & `POST /api/settings/invoice` (`multipart/form-data` لدعم رفع `invoice_logo`, `site_favicon`, `invoice_watermark` + كافة مفاتيح التبويبات الـ 7 في `InvoiceSettings`).
    - `GET /api/settings/receipt` & `POST /api/settings/receipt` (`multipart/form-data` لكافة مفاتيح التبويبات الـ 5 في `ReceiptSettings`).
    - `GET /api/settings/whatsapp` & `POST /api/settings/whatsapp` (كافة مفاتيح `WhatsAppSettings`).
    - `GET /api/whatsapp/local-status`, `POST /api/whatsapp/local-start`, `POST /api/whatsapp/local-stop` (التحكم بخادم الواتساب المحلي وعرض الـ QR Code وسجلات `whatsapp.log` و`queue.log`).
    - `GET /api/settings/items`, `POST /api/settings/items`, `PUT /api/settings/items/{id}`, `DELETE /api/settings/items/{id}` (إدارة `SettingResource` العام).
63. **تصدير البيانات (`Data Exports`):**
    - `GET /api/exports/summary` -> يرجع أعداد السجلات (`totalSubscribers`, `totalInvoices`, `totalReadings`, `totalSquares`).
    - `GET /api/exports/subscribers?square_id=&status=` -> يرجع ملف `المشتركون_YYYY-MM-DD.xlsx`.
    - `GET /api/exports/invoices?square_id=&from_date=&to_date=&status=` -> يرجع ملف `فواتير_المشتركين_YYYY-MM-DD.xlsx`.
    - `GET /api/exports/readings?square_id=&from_date=&to_date=&is_invoiced=` -> يرجع ملف `قراءات_العدادات_YYYY-MM-DD.xlsx`.

---

# الباب الثالث: المعمارية الهندسية لتطبيق Flutter، التجاوب الشامل، ونظام التصميم العصري (Architecture, Responsiveness & UI/UX)

لبناء تطبيق موبايل فائق السرعة، سلس (60–120 إطاراً في الثانية)، سهل الاستخدام، يدعم كافة الأجهزة ومقاسات الشاشات، وقابل للتطوير المتوازي من قِبل فريق مكون من عدة مطورين دون تعارض في الكود، نعتمد المعمارية الهندسية التالية.

## 3.1 المعمارية البرمجية المعتمدة (Feature-First Clean Architecture + BLoC/Cubit)

نعتمد نمط **Feature-First Clean Architecture**، حيث يتم تقسيم المشروع إلى وحدات مستقلة (Features) بحيث يعمل كل مطور في مجلد الميزة الخاصة به دون المساس بملفات زملائه، مع طبقة `core` مشتركة وموحدة.

### هيكل المجلدات الكامل لتطبيق Flutter (`lib/`):

```text
lib/
├── main.dart
├── app.dart                                 # إعداد MaterialApp.router, Theme, Locale (ar), ScreenUtilInit
├── bootstrap.dart                           # تهيئة التبعيات (GetIt), التخزين المحلي (Drift/SharedPrefs), المزامنة
│
├── core/                                    # الطبقة الأساسية المشتركة (المسؤول عنها: المطور الأول - Architect)
│   ├── config/
│   │   ├── app_config.dart                  # عناوين الخادم، المهلة الزمنية، إعدادات البيئة
│   │   └── constants.dart
│   ├── di/
│   │   └── injection_container.dart         # تسجيل التبعيات (GetIt / Injectable)
│   ├── network/
│   │   ├── api_client.dart                  # Dio Instance الموحد
│   │   ├── api_endpoints.dart               # ثوابت كافة الـ 68 مساراً
│   │   ├── interceptors/
│   │   │   ├── auth_interceptor.dart        # إرفاق Bearer Token تلقائياً ومعالجة 401
│   │   │   ├── retry_interceptor.dart       # إعادة المحاولة الذكية عند ضعف الشبكة
│   │   │   └── offline_cache_interceptor.dart
│   │   └── network_info.dart                # فحص حالة الاتصال بالإنترنت (connectivity_plus)
│   ├── error/
│   │   ├── exceptions.dart
│   │   ├── failures.dart                    # معالجة رسائل أخطاء Laravel Validation (422) بالعربية
│   │   └── error_handler.dart
│   ├── local_storage/
│   │   ├── secure_storage_service.dart      # حفظ التوكن بأمان (flutter_secure_storage)
│   │   ├── app_database.dart                # قاعدة بيانات Drift (SQLite) للعمل أوفلاين
│   │   └── sync_queue_manager.dart          # طابور مزامنة القراءات والمدفوعات المسجلة أوفلاين
│   ├── permissions/
│   │   ├── permission_service.dart          # التحقق من Roles & Spatie/Shield Permissions
│   │   └── permission_guard_widget.dart     # ودجة لإخفاء/تعطيل الأزرار حسب صلاحية المستخدم
│   ├── responsive/
│   │   ├── breakpoints.dart                 # نقاط التوقف (Phone, Foldable, Tablet, Desktop)
│   │   ├── responsive_builder.dart          # بناء الواجهات المتكيفة (Master-Detail / Grid / List)
│   │   ├── adaptive_scaffold.dart           # الهيكل المتكيف (BottomNav vs NavigationRail)
│   │   └── size_extensions.dart             # امتدادات المقاسات الذكية
│   ├── theme/
│   │   ├── app_colors.dart                  # الهوية اللونية المائية العصرية (Light & Dark)
│   │   ├── app_typography.dart              # خطوط Tajawal / Cairo مع أحجام متجاوبة
│   │   ├── app_theme.dart                   # ThemeData الكامل للوضعين النهاري والليلي
│   │   └── app_shadows_gradients.dart       # التدرجات والظلال الزجاجية الناعمة
│   ├── router/
│   │   ├── app_router.dart                  # GoRouter مع حراس المصادقة والصلاحيات
│   │   └── route_names.dart
│   ├── services/
│   │   ├── pdf_print_service.dart           # معاينة وطباعة ومشاركة ملفات الـ PDF (A5 Landscape)
│   │   ├── thermal_printer_service.dart     # طباعة الفواتير والسندات عبر طابعات البلوتوث الحرارية
│   │   ├── whatsapp_launcher_service.dart   # فتح روابط wa.me وتوليد الرسائل الشرطية محلياً
│   │   └── excel_export_service.dart        # تحميل وحفظ ومشاركة ملفات الإكسل (.xlsx)
│   ├── utils/
│   │   ├── arabic_number_spellout.dart      # تفقيط الأرقام بالعربية ("ريال يمني لا غير")
│   │   ├── compound_search_parser.dart      # محلل البحث المركب (مربع/مسار مثل 4/1 أو 4-1)
│   │   ├── currency_formatter.dart          # تنسيق المبالغ المالية والأرقام بالاتجاه LTR
│   │   ├── date_period_calculator.dart      # نسخة محلية من ReadingService لحساب الفترات أوفلاين
│   │   └── phone_formatter.dart             # تنسيق أرقام الهواتف اليمنية (+967)
│   └── widgets/                             # مكتبة المكونات المشتركة (Design System Widgets)
│       ├── app_button.dart
│       ├── app_text_field.dart              # يدعم فرض LTR تلقائياً للأرقام والهواتف والإيميل
│       ├── compound_search_bar.dart         # شريط البحث الذكي بدعم المربع/المسار والفلاتر السريعة
│       ├── status_badge.dart                # شارات الحالة الملونة الموحدة
│       ├── stat_card.dart                   # بطاقات الإحصائيات المزودة بـ Sparkline
│       ├── shimmer_skeleton.dart            # هياكل التحميل السلسة
│       ├── empty_state_widget.dart
│       ├── confirm_dialog.dart
│       └── pdf_preview_sheet.dart
│
└── features/                                # وحدات النظام الـ 13 (مقسمة على المطورين)
    ├── auth/                                # تسجيل الدخول، الجلسة، الملف الشخصي
    ├── dashboard/                           # لوحة القيادة، الإحصائيات، الرسم البياني، الفواتير المتأخرة
    ├── readings/                            # القراءات الميدانية، إدارة القراءات، الإصدار الجماعي
    ├── meters/                              # إدارة العدادات، المسارات، المستأجرين
    ├── invoices/                            # الفواتير، محركات الإصدار الـ 3، الطباعة، الواتساب
    ├── payments/                            # التحصيل الميداني، سندات القبض، توزيع FIFO
    ├── subscribers/                         # المشتركون، الملف المالي 360°، التبويبات الـ 6، كشف الحساب
    ├── squares/                             # المربعات السكنية ومشتركوها
    ├── subscription_fees/                   # رسوم الاشتراك وفوترتها
    ├── service_requests/                    # طلبات الوايت، الإنجاز، والفوترة
    ├── transactions/                        # دفتر الأستاذ العام وسجل الحركات المالية
    ├── tariffs/                             # التعرفة والتسعير وسلة المحذوفات
    ├── users_roles/                         # المستخدمون، الأدوار، ومصفوفة الصلاحيات (Filament Shield)
    ├── settings/                            # مراكز الإعدادات الـ 5 + التحكم بخادم الواتساب المحلي (QR)
    └── exports/                             # مركز تصدير بيانات المشتركين والفواتير والقراءات (Excel)
```

داخل كل ميزة في `lib/features/{feature_name}/` يتم الالتزام بالهيكل الموحد التالي:
```text
{feature_name}/
├── data/
│   ├── datasources/
│   │   ├── {feature}_remote_datasource.dart
│   │   └── {feature}_local_datasource.dart
│   ├── models/
│   │   └── {feature}_model.dart             # JSON Serialization / Freezed
│   └── repositories/
│       └── {feature}_repository_impl.dart
├── domain/
│   ├── entities/
│   │   └── {feature}_entity.dart
│   ├── repositories/
│   │   └── {feature}_repository.dart
│   └── usecases/
└── presentation/
    ├── cubit/ (أو bloc/)
    │   ├── {feature}_cubit.dart
    │   └── {feature}_state.dart
    ├── pages/
    │   └── ...
    └── widgets/
        └── ...
```

---

## 3.2 الحزم والمكتبات المعتمدة (`pubspec.yaml`)

لضمان أعلى أداء واستقرار وتوافق مع كافة الأجهزة، نعتمد الحزم القياسية التالية:

| الفئة | الحزمة (Package) | الغرض الأساسي في المشروع |
|---|---|---|
| **إدارة الحالة** | `flutter_bloc` + `equatable` | إدارة حالة قوية، متوقعة، سريعة، وسهلة الاختبار والتقسيم بين الفريق. |
| **حقن التبعيات** | `get_it` + `injectable` | فصل الطبقات وتمكين كل مطور من ربط خدماته بشكل مستقل. |
| **الشبكة والـ API** | `dio` + `pretty_dio_logger` | طلبات HTTP السريعة، رفع الصور (`MultipartFile`)، تحميل ملفات PDF/Excel، والـ Interceptors. |
| **النماذج والبيانات** | `freezed_annotation` + `json_annotation` | توليد الكلاسات غير القابلة للتغيير (`Immutable Models`) و`fromJson`/`toJson` و`copyWith` تلقائياً. |
| **التخزين المحلي والأوفلاين** | `drift` + `sqlite3_flutter_libs` + `flutter_secure_storage` | تخزين العدادات والفواتير وطابور القراءات/التحصيل أوفلاين في SQLite سريع، وحفظ التوكن بأمان. |
| **التوجيه والملاحة** | `go_router` | نظام توجيه حديث يدعم الروابط العميقة، حماية المسارات بالصلاحيات، والـ StatefulShellRoute للتبويبات. |
| **التجاوب والمقاسات** | `flutter_screenutil` + `responsive_framework` | ضبط مقاسات الخطوط والحواف على كافة الشاشات وبناء نقاط التوقف للهواتف والتابلت. |
| **الرسوم البيانية** | `fl_chart` | رسم مخطط التحصيل الشهري (`MonthlyRevenueChart`) ومؤشرات البطاقات (`Sparklines`) بسلاسة 120fps. |
| **الطباعة والـ PDF** | `printing` + `pdf` + `share_plus` + `open_filex` | معاينة وطباعة ومشاركة ملفات الـ PDF الرسمية للفواتير وسندات القبض وتصدير الإكسل. |
| **الطباعة الحرارية** | `print_bluetooth_thermal` + `esc_pos_utils_plus` | طباعة فواتير وسندات قبض فورية للمحصلين في الميدان عبر طابعات البلوتوث المحمولة (58mm/80mm). |
| **الوسائط والروابط** | `image_picker` + `cached_network_image` + `url_launcher` | تصوير العداد والصورة الشخصية، تخزين الصور مؤقتاً، وفتح محادثات واتساب (`wa.me`) والاتصال الهاتفي. |
| **مسح الأكواد** | `mobile_scanner` | مسح الباركود/QR على العدادات أو الفواتير للوصول الفوري في الميدان. |
| **التحسينات البصرية** | `shimmer` + `flutter_animate` + `google_fonts` | تأثيرات التحميل، الحركات الانسيابية السريعة، وخط `Tajawal` الرسمي للمشروع. |

---

## 3.3 هندسة التجاوب الشامل ودعم كافة الأجهزة ومقاسات الشاشات (Universal Responsive & Adaptive Architecture)

التطبيق مصمم ليعمل بكفاءة تامة على **جميع الأجهزة دون استثناء**: الهواتف الصغيرة والاقتصادية (التي يحملها قراء العدادات والمحصلون في الميدان)، الهواتف الحديثة الكبيرة، الهواتف القابلة للطي (Foldables)، والأجهزة اللوحية (Tablets - التي يستخدمها المدير والمحاسب في المكتب).

### 1. نقاط التوقف المعتمدة (`Responsive Breakpoints`)
- **Compact (الهواتف الصغيرة والقياسية):** العرض `< 600dp` (وتشمل معالجة خاصة للشاشات الضيقة `< 360dp` بتقليص الحشوات `Padding` واستخدام `Wrap` و`FittedBox` لمنع أي `RenderFlex Overflow` نهائياً).
- **Medium (الهواتف القابلة للطي والتابلت الصغير / الوضع الأفقي للهاتف):** العرض من `600dp` إلى `840dp`.
- **Expanded (الأجهزة اللوحية الكبيرة Tablets 10"+ وسطح المكتب):** العرض `>= 840dp`.

### 2. هيكل الملاحة المتكيف (`AdaptiveScaffold`)
- **في الهواتف (`< 600dp`):** شريط تنقل سفلي عصري (`NavigationBar`) يضم أهم 5 أقسام حسب دور المستخدم (مثلاً للمدير: *الرئيسية، القراءات، الفواتير، المدفوعات، المشتركون* + قائمة جانبية `Drawer` لباقي الوحدات الإدارية والإعدادات؛ وللقارئ: *إدخال قراءة، سجل القراءات، بحث العدادات، الملف الشخصي*؛ وللمحصل: *تحصيل سريع، الفواتير غير المسددة، سندات القبض، المشتركون، الملف الشخصي*).
- **في الأجهزة اللوحية والوضع الأفقي (`>= 600dp`):** يتحول شريط التنقل السفلي تلقائياً إلى شريط جانبي عمودي (`NavigationRail` أو `Permanent Sidebar`) على يمين الشاشة (RTL)، مما يوفر مساحة رأسية كاملة للجداول والبطاقات.

### 3. نمط العرض المزدوج (`Master-Detail Split View`) على الشاشات الكبيرة
- في الهواتف: النقر على مشترك أو فاتورة أو مربع سكني يفتح شاشة التفاصيل الكاملة (`Push Page`).
- في التابلت (`>= 840dp`): تُعرض الشاشة مقسومة إلى عمودين:
  - **العمود الأيمن (40%):** قائمة البحث والفلترة (مثلاً قائمة المشتركين أو الفواتير غير المسددة).
  - **العمود الأيسر (60%):** تفاصيل العنصر المحدد (ملف المشترك بتبويباته الـ 6، أو تفاصيل الفاتورة ومعاينة الـ PDF ونافذة التحصيل) في نفس اللحظة دون الانتقال لشاشة أخرى!

### 4. القوائم المتكيفة (`Adaptive List / Grid / Table`)
- في الهواتف: تُعرض الفواتير والقراءات والمشتركين على هيئة **بطاقات ذكية مضغوطة (Smart Cards)** تبرز أهم البيانات وأزرار الإجراءات السريعة (تحصيل، واتساب، PDF، اتصال) بلمسة واحدة.
- في التابلت: يمكن للمستخدم التبديل بين **شبكة بطاقات متعددة الأعمدة (`SliverGrid` 2 أو 3 أعمدة)** أو **جدول بيانات احترافي (`PaginatedDataTable`)** مطابق لجدول Filament.

---

## 3.4 نظام التصميم العصري وتجربة المستخدم (Modern UI/UX Design System - "AquaGlass")

تم استلهام الهوية البصرية للتطبيق من ألوان قوالب الفاتورة والسند الرسمية في المشروع (`#02687E`, `#124C6B`, `#059669`, `#F59E0B`) مع دمجها في نظام تصميم عصري مريح للعين تحت أشعة الشمس في الميدان وفي الإضاءة المنخفضة.

### 1. لوحة الألوان المعتمدة (`AppColors`)
- **الألوان الأساسية (Water & Trust Palette):**
  - `Primary`: `#02687E` (أزرق مائي عميق - مستمد من عنوان الفاتورة الرسمي في `invoice.blade.php`).
  - `PrimaryDark`: `#0B2B40` (كحلي داكن للنصوص والعناوين).
  - `Secondary / Accent`: `#0EA5E9` (سماوي نقي للتفاعلات النشطة).
  - `BrandAmber`: `#F59E0B` (كهرماني - مستمد من لون Filament الرئيسي في `AdminPanelProvider` للأزرار البارزة والتنبيهات).
- **ألوان الحالات المالية (Financial Semantic Colors):**
  - `Success / Credit / Paid`: `#059669` (أخضر زمردي - للرصيد الدائن، الفواتير المسددة، وسندات القبض).
  - `Warning / Partially Paid / Pending`: `#D97706` (برتقالي كهرماني - للمسددة جزئياً والطلبات المعلقة).
  - `Danger / Debit / Overdue`: `#DC2626` (أحمر قرمزي - للمتأخرات، الرصيد المدين، والفواتير المتأخرة).
  - `Info / Issued`: `#0284C7` (أزرق معلوماتي - للفواتير الصادرة).
  - `Neutral / Draft`: `#64748B` (رمادي أردوازي - للمسودات).
- **الخلفيات والأسطح (Light & Dark Modes):**
  - **الوضع النهاري (Light Mode - افتراضي للميدان تحت الشمس لتباين عالٍ):** خلفية `#F8FAFC`، بطاقات `#FFFFFF` مع حدود ناعمة `#E2E8F0`.
  - **الوضع الليلي (Dark Mode):** خلفية `#0F172A`، بطاقات `#1E293B` مع حدود `#334155`.

### 2. الخطوط وقواعد اتجاه النصوص (`Typography & BiDi Rules`)
- **الخط الرسمي:** خط **`Tajawal`** (نفس الخط المستخدم في `pdf/invoice.blade.php` و`pdf/receipt.blade.php`) بأوزان `Regular (400)`, `Medium (500)`, `Bold (700)`, `ExtraBold (800)`.
- **اتجاه التطبيق العام:** عربي `RTL` (`TextDirection.rtl`).
- **القاعدة الذهبية لحقول الأرقام والهواتف (مطابقة لـ `AdminPanelProvider.php` سطر 51):**
  - جميع حقول إدخال وعرض الأرقام (`current_reading`, `amount`, `path_number`, `unique_code`, `bill_number`, `receipt_number`) وأرقام الهواتف (`phone`, `tenant_phone`) والبريد الإلكتروني وكلمات المرور تُجبر برمجياً على اتجاه **`Directionality(textDirection: TextDirection.ltr)`** مع محاذاة مناسبة، وتقبل الأرقام العربية (`٠١٢٣٤٥٦٧٨٩`) وتحولها فورياً إلى أرقام إنجليزية (`0123456789`) أثناء الكتابة لتجنب أي خطأ في الإدخال الميداني!

### 3. مبادئ تجربة المستخدم السلسة والسريعة (`UX Excellence`)
1. **قاعدة النقرتين (2-Tap Rule) للقارئ والمحصل:**
   - القارئ يكتب `4/1` (المربع 4 / المسار 1) $\leftarrow$ يظهر العداد فوراً مع القراءة السابقة وفترة القراءة جاهزة $\leftarrow$ يكتب القراءة الحالية ويضغط **"حفظ وإصدار"** $\leftarrow$ تصدر الفاتورة وتظهر أزرار **"إرسال واتساب"** و**"طباعة"** في نفس النافذة، مع زر **"الانتقال للمسار التالي (4/2)"** بلمسة واحدة!
2. **التغذية الراجعة الفورية (Instant Live Calculation):**
   - أثناء كتابة `current_reading`، يحسب التطبيق فوراً على الشاشة: `كمية الاستهلاك = القراءة الحالية - القراءة السابقة`، و`قيمة الاستهلاك المتوقعة = الاستهلاك × سعر الوحدة النشط` قبل حتى الضغط على زر الحفظ!
   - أثناء كتابة مبلغ التحصيل `amount` في نافذة الدفع، يعرض التطبيق فوراً: `المتبقي بعد الدفعة` أو `الرصيد الدائن الفائض`، مع أزرار تعبئة سريعة (`كامل المبلغ المستحق`, `نصف المبلغ`).
3. **Skeleton Shimmer بدلاً من شاشات الانتظار المزعجة:**
   - لا يتم تجميد الشاشة بـ `CircularProgressIndicator` ممل؛ بل تظهر هياكل `Shimmer` أنيقة تحاكي شكل البطاقات، مع عرض البيانات المخزنة محلياً (Cache) فوراً وتحديثها في الخلفية (`Stale-While-Revalidate`).

---

## 3.5 محرك الأداء الفائق والعمل بدون إنترنت (Offline-First Sync Engine)

بما أن قراء العدادات والمحصلين يعملون في حارات ومربعات سكنية قد ينقطع فيها اتصال الإنترنت أو يضعف:
1. **التحميل المسبق للمربع السكني (`Offline Pack Download`):**
   - بضغطة زر واحدة قبل النزول للميدان (أو تلقائياً عند فتح التطبيق)، يقوم التطبيق بتحميل وتخزين بيانات المربعات، المشتركين، العدادات، آخر القراءات، الفواتير غير المسددة، والإعدادات والتعرفة النشطة داخل قاعدة بيانات `Drift (SQLite)` المحلية.
2. **إدخال القراءات والتحصيل أوفلاين (`Offline Queue`):**
   - إذا انقطع الإنترنت، يستمر القارئ في البحث بـ `المربع/المسار` وإدخال القراءات وتصوير العدادات بشكل طبيعي 100%.
   - تُحفظ القراءة أو الدفعة في جدول `pending_sync_actions` محلياً مع شارة صفراء `⏳ بانتظار المزامنة`.
   - بمجرد عودة الاتصال بالإنترنت، يقوم `SyncQueueManager` بإرسال العمليات المتراكمة بالترتيب الزمني إلى الخادم وتحديث أرقام الفواتير وسندات القبض الرسمية وإشعار المستخدم بنجاح المزامنة.

---

# الباب الرابع: الخريطة التفصيلية الشاملة لشاشات وعمليات تطبيق الموبايل (Screen-by-Screen & Feature-by-Feature Blueprint)

يضم تطبيق الموبايل **44 شاشة ونافذة عملية تفاعلية** تغطي 100% من شاشات وعمليات ودوال مشروع Laravel وFilament. فيما يلي التوصيف الهندسي الدقيق لكل شاشة وما بداخلها من حقول، معادلات، تحققات، وأزرار:

---

## الوحدة الأولى: المصادقة، الجلسة، والملف الشخصي (`Auth & Profile Module`)

### 1. شاشة البداية الذكية (`SplashScreen`)
- **الوظيفة:** عرض شعار المشروع الديناميكي (`invoice_logo` واسم المشروع `invoice_project_name_ar` المخزن محلياً أو الافتراضي *"مشروع مياه الوشاح"*).
- **العمليات في الخلفية:**
  - فحص وجود `access_token` في `SecureStorage`.
  - استدعاء `GET /api/user` و `GET /api/settings` لتحديث صلاحيات المستخدم وإعدادات النظام.
  - توجيه المستخدم تلقائياً حسب دوره (`super_admin`/`admin` $\leftarrow$ لوحة القيادة الشاملة؛ `reader` $\leftarrow$ واجهة القارئ الميداني؛ `collector` $\leftarrow$ واجهة المحصل الميداني؛ أو تسجيل الدخول إذا انتهت الجلسة).

### 2. شاشة تسجيل الدخول (`LoginScreen`)
- **التصميم:** بطاقة زجاجية عصرية (`AquaGlass Card`) تتوسط الشاشة (في الهاتف) أو بجانب لوحة بصرية مائية (في التابلت).
- **الحقول:**
  - `email`: البريد الإلكتروني (اتجاه `LTR`، لوحة مفاتيح `emailAddress`، تحقق من صيغة الإيميل).
  - `password`: كلمة المرور (اتجاه `LTR`، مع زر إظهار/إخفاء كلمة المرور).
  - خيار **"تذكرني / الدخول بالبصمة (Biometric Login)"** لتسريع دخول الموظف الميداني.
- **الربط البرمجي:** `POST /api/login` $\leftarrow$ حفظ التوكن وبيانات المستخدم (`id, name, email, avatar, roles, permissions`) وتوجيهه للشاشة المناسبة.

### 3. شاشة الملف الشخصي وتغيير كلمة المرور (`EditProfileScreen` — تطابق [`EditProfilePage.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Pages/EditProfilePage.php))
- **قسم المعلومات الشخصية:**
  - `avatar`: صورة دائرية مع زر التقاط صورة من الكاميرا أو المعرض (`max: 5MB`) ومعاينة فورية.
  - `name`: الاسم الكامل (مطلوب، بحد أقصى 255 حرفاً).
  - `email`: البريد الإلكتروني (مطلوب، فريد `unique` مع استثناء المستخدم الحالي).
  - شارات تعرض أدوار المستخدم الحالية (`مدير نظام`, `محصل`, `قارئ عداد`).
- **قسم تغيير كلمة المرور:**
  - `password`: كلمة المرور الجديدة (اختياري، على الأقل 6 أحرف، مع زر إظهار/إخفاء).
  - `password_confirmation`: تأكيد كلمة المرور (مطلوب إذا تم ملء حقل كلمة المرور، ويطابق `password`).
- **الأزرار:** زر **"حفظ التعديلات"** (`POST /api/profile`) + زر **"تسجيل الخروج"** (`POST /api/logout`).

---

## الوحدة الثانية: لوحة القيادة الإحصائية والتحليلية (`Dashboard & Widgets Module`)

### 4. الشاشة الرئيسية للوحة القيادة (`DashboardScreen` — تطابق لوحة Filament + الودجات الـ 5)
تُعرض بنظام شبكي متكيف (عمودان في الهاتف، 4 أعمدة في التابلت) مع خاصية السحب للتحديث (`Pull-to-Refresh`):
1. **ودجة حساب المستخدم السريعة (`CustomAccountBanner` — تطابق `CustomAccountWidget`):**
   - ترحيب باسم المستخدم، صورته، دوره، وحالة الاتصال بالخادم (أونلاين / أوفلاين مع عدد العمليات في طابور المزامنة).
2. **البطاقات الإحصائية الأربع (`StatsOverviewGrid` — تطابق [`StatsOverview.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Widgets/StatsOverview.php)):**
   - **بطاقة 1 (المشتركون النشطون):** تعرض عدد المشتركين بحالة `active`، أيقونة المستخدمين، ولون `primary` مع مخطط `Sparkline` مصغر `[7, 3, 4, 5, 6, 3, 5]`.
   - **بطاقة 2 (المبالغ المستحقة / إجمالي المتأخرات):** تعرض `SUM(total_amount - amount_paid)` للفواتير (`issued`, `partially_paid`, `overdue`) باللون الأحمر `danger` مع تنسيق العملة `ريال`.
   - **بطاقة 3 (تحصيل الشهر):** تعرض مجموع المدفوعات في الشهر والسنة الحالية (`Payment::whereMonth...`) باللون الأخضر `success` مع مخطط `Sparkline` `[3, 5, 7, 4, 6, 8, 5]`.
   - **بطاقة 4 (فواتير متأخرة):** تعرض عدد الفواتير بحالة `overdue` (`تحتاج متابعة`) باللون الأحمر إذا كان `> 0` أو الأخضر إذا كان `0`.
   - *(إضافة من `AdminController@stats`):* شريط سريع يعرض **قراءات هذا الشهر (`readings_this_month`)** و**إجمالي الفواتير غير المسددة (`unpaid_invoices_count`)**.
3. **الرسم البياني التفاعلي للتحصيل الشهري (`MonthlyRevenueChartCard` — يطابق [`MonthlyRevenueChart.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Widgets/MonthlyRevenueChart.php)):**
   - مخطط خطي (`LineChart` عبر مكتبة `fl_chart`) يعرض إيرادات التحصيل لآخر **6 أشهر** (من الشهر الخامس السابق حتى الشهر الحالي)، باللون الأخضر الزمردي `rgb(5, 150, 105)` مع تعبئة متدرجة أسفل المنحنى وعرض القيمة الدقيقة عند لمس أي نقطة.
4. **جدول/قائمة أحدث الفواتير (`LatestInvoicesSection` — يطابق [`LatestInvoicesWidget.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Widgets/LatestInvoicesWidget.php)):**
   - يعرض أحدث **5 فواتير** مرتبة حسب `bill_date` تنازلياً (رقم الفاتورة، اسم المشترك، التاريخ، الإجمالي، شارة الحالة الملونة) مع إمكانية النقر لفتح تفاصيل الفاتورة مباشرة.
5. **جدول/قائمة الفواتير المتأخرة (`OverdueInvoicesSection` — يطابق [`OverdueInvoicesWidget.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Widgets/OverdueInvoicesWidget.php)):**
   - يعرض الفواتير ذات الحالة `overdue` مرتبة من الأقدم للأحدث (`bill_date` تصاعدياً) مع: رقم الفاتورة، المشترك، التاريخ، الإجمالي، المسدد، وأزرار سريعة (**تحصيل فوري**، **تذكير واتساب**).
   - في حال عدم وجود فواتير متأخرة، يعرض رسالة الاحتفال المطابقة لـ Filament: *"لا توجد فواتير متأخرة 🎉 — جميع الفواتير مسددة أو في حالة جيدة"*.

---

## الوحدة الثالثة: قراءات العدادات والعمليات الميدانية (`Readings Module` — تطابق [`ReadingResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/ReadingResource.php) و [`ReadingController.php`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/ReadingController.php))

### 5. شاشة القارئ الميداني السريع (`FieldMeterReaderScreen`)
مخصصة لقارئ العدادات (وللمدير) لإنجاز قراءة مربعات كاملة في دقائق:
- **شريط الفلترة والبحث المركب في الأعلى:**
  - قائمة منسدلة / شرائح اختيار سريعة (`FilterChips`) للمربعات السكنية (`الكل` أو مربع محدد `square_id`).
  - حقل بحث فوري يدعم:
    - **البحث المركب بالمربع والمسار:** كتابة `4/1` أو `4-1` أو `4_1` أو `4\1` تجلب فوراً عداد المسار `1` في المربع `4`.
    - البحث برقم المسار `path_number` فقط (مع وضع المطابق التام `is_path_match = true` في الصدارة).
    - البحث برقم العداد `unique_code` أو اسم المشترك `subscriber.name` أو اسم المستأجر `tenant_name`.
  - زر مسح الكود بالكاميرا (`QR/Barcode Scanner`).
- **قائمة العدادات الميدانية:**
  - كل بطاقة عداد تعرض: شارة رقم المسار بارزة (`مسار #1`)، كود العداد (`unique_code`)، اسم المشترك (واسم المستأجر إن وجد بلون مميز)، المربع السكني، وآخر قراءة مسجلة.

### 6. شاشة/نافذة تسجيل وتعديل قراءة عداد (`RecordReadingSheet / ReadingFormScreen`)
تطابق 100% منطق النموذج الذكي في [`ReadingResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/ReadingResource.php#L30-L185) و`ReadingController@store`:
- **عند اختيار المشترك/المستأجر أو العداد (`meter_id`):**
  - يتم استدعاء `GET /api/meters/{id}/period` (أو الحساب المحلي أوفلاين عبر `DatePeriodCalculator`).
  - يتم ملء الحقول التالية تلقائياً (للقراءة فقط `readOnly`):
    - `previous_reading` (القراءة السابقة).
    - `period_start` (فترة القراءة من تاريخ).
    - `period_end` (فترة القراءة إلى تاريخ).
- **الحقول القابلة للإدخال:**
  - `reading_date`: تاريخ إدخال القراءة الفعلي (افتراضياً تاريخ اليوم `now()`).
  - `current_reading`: القراءة الحالية (حقل رقمي كبير وواضح باتجاه `LTR`).
    - **معاينة حية أثناء الكتابة:** يعرض شريطاً فورياً يوضح: `كمية الاستهلاك = current_reading - previous_reading` وحدة $\times$ سعر الوحدة النشط = `قيمة الاستهلاك المتوقعة`.
    - **قاعدة التحقق الفورية:** إذا كانت `current_reading < previous_reading` يظهر خطأ فوري باللون الأحمر: *"القراءة الحالية يجب أن تكون أكبر من أو تساوي القراءة السابقة ({previous_reading})."*
  - `image`: زر اختياري لالتقاط صورة عداد المياه بالكاميرا (مع ضغط الصورة تلقائياً لتسريع الرفع).
- **قسم الفوترة التلقائية الذكي (يظهر عندما يكون `auto_issue_invoice_on_reading == true`):**
  - إذا كان على المشترك رسوم اشتراك غير مفوترة (`has_subscription_fee`) أو طلبات وايت مكتملة غير مفوترة (`has_tanker_fee`):
    - يظهر **صندوق تنبيه كهرماني (`Amber Alert Box`)** مطابق لـ `auto_invoice_warning` في `ReadingResource.php`: *"⚠️ تنبيه الفوترة التلقائية: نظام الفوترة التلقائية مفعل. سيتم إصدار فاتورة فور حفظ هذه القراءة..."* مع عرض المبالغ غير المفوترة.
    - مفتاح تبديل `has_subscription_fee` ("إضافة مبالغ الاشتراك"): عند تفعيله يظهر حقل `subscription_installment` مملوءاً تلقائياً بإجمالي رسوم الاشتراك غير المفوترة (مع إمكانية تعديل القسط).
    - مفتاح تبديل `has_tanker_fee` ("إضافة رسوم الوايت"): عند تفعيله يظهر حقل `tanker_installment` مملوءاً تلقائياً بإجمالي طلبات الوايت المكتملة (مع إمكانية تعديله).
- **ماذا يحدث بعد الضغط على "حفظ القراءة" (`POST /api/readings`):**
  - إذا كان إصدار الفاتورة التلقائي مفعلاً (`invoice_issued == true`):
    - تعرض نافذة نجاح فورية تحتوي على ملخص الفاتورة الصادرة (`invoice_data`) مع 3 أزرار سريعة:
      1. **إرسال الفاتورة عبر واتساب** (يفتح محادثة المشترك/المستأجر بالرسالة المنسقة ورابط PDF الفاتورة).
      2. **طباعة الفاتورة (PDF / طابعة بلوتوث حرارية)**.
      3. **العداد التالي في المسار (`Next Path`)** للانتقال الفوري للعداد الذي يليه في نفس المربع!

### 7. شاشة سجل وإدارة القراءات الشامل (`ReadingsManagementScreen` — تطابق جدول `ReadingResource`)
- **الأعمدة والبيانات المعروضة في كل بطاقة/صف:**
  - رقم القراءة (`#id`)، كود العداد (`meter.unique_code`)، اسم المشترك (مع `(المستأجر)` إن وجد)، تاريخ القراءة (`reading_date`)، التاريخ الفعلي (`actual_reading_date` قابل للإظهار)، القراءة السابقة (`previous_reading`)، القراءة الحالية (`current_reading`)، شارة كمية الاستهلاك (`consumption_units` باللون الأساسي)، وأيقونة حالة الفوترة (`is_invoiced`: مفوترة ✅ / غير مفوترة ⏳).
  - تمييز بصري للصفوف/البطاقات: القراءات غير المفوترة تحمل شريطاً جانبياً كهرمانياً، والمفوترة تحمل شريطاً أخضر.
- **الفلاتر:**
  - فلتر ثلاثي لحالة الفوترة (`الكل` / `مفوترة` / `غير مفوترة` — يطابق `TernaryFilter::make('is_invoiced')`).
  - فلتر المربع السكني (`square_id`) ونطاق التاريخ (`from_date` .. `to_date`).
- **الإجراءات الفردية (Row Actions):**
  - **تعديل (`Edit`):** يظهر **فقط** إذا كانت القراءة غير مفوترة (`!is_invoiced`).
  - **حذف (`Delete`):** يظهر **فقط** إذا كانت القراءة غير مفوترة (`!is_invoiced`).
- **الإجراءات الجماعية (Bulk Actions عند تحديد عدة قراءات):**
  - **1. زر "إصدار الفواتير" (`issue_invoices`):**
    - يظهر فقط إذا كانت جميع القراءات المحددة غير مفوترة (مطابق لشرط `reading-invoiced` في Filament).
    - يطلب تأكيد العملية، ثم يجمع القراءات حسب `meter_id` ويصدر الفواتير دفعة واحدة عبر `POST /api/readings/bulk-issue-invoices` ويعرض إشعاراً بعدد الفواتير الصادرة.
  - **2. زر "حذف المحدد" (`DeleteBulkAction`):**
    - يحذف القراءات غير المفوترة فقط ويتخطى المفوترة، ويعرض تنبيهاً تفصيلياً: *"تم حذف X قراءة، وتم تخطي Y قراءة لأنها مفوترة مسبقاً."*

---

## الوحدة الرابعة: إدارة الفواتير وإصدارها (`Invoices Module` — تطابق [`InvoiceResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/InvoiceResource.php))

### 8. شاشة قائمة وإدارة الفواتير الشاملة (`InvoicesListScreen`)
- **شريط الإجراءات العلوي (الـ 4 أزرار الرئيسية المطابقة لـ `InvoiceResource` Header Actions):**
  1. **إصدار فاتورة عداد (`generate_invoice` - لون أزرق أساسي، أيقونة الآلة الحاسبة):**
     - نافذة ذكية تحتوي على:
       - اختيار المشترك أو المستأجر (`subscriber_id` أو `subId|meterId`).
       - اختيار العداد (`meter_id` - يُختار تلقائياً إذا كان للمشترك عداد واحد).
       - فحص تلقائي لرسوم الاشتراك غير المفوترة (`has_subscription_fee` + `subscription_installment`) وطلبات الوايت المكتملة (`has_tanker_fee` + `tanker_installment`).
       - حقل ملاحظات (`notes`).
       - يستدعي `POST /api/invoices/generate-meter`.
  2. **إصدار فاتورة اشتراك (`generate_subscription_invoice` - لون كهرماني، أيقونة الوثيقة):**
     - يعرض فقط المشتركين النشطين الذين لديهم رسوم اشتراك `uninvoiced`.
     - عند اختيار المشترك، يملأ حقل `subscription_installment` بمجموع الرسوم غير المفوترة تلقائياً.
     - يستدعي `POST /api/invoices/generate-subscription`.
  3. **إصدار فاتورة وايت (`generate_tanker_invoice` - لون سماوي، أيقونة الشاحنة):**
     - يعرض فقط المشتركين النشطين الذين لديهم طلبات وايت بحالة `completed`.
     - عند اختيار المشترك، يملأ حقل `tanker_installment` بمجموع طلبات الوايت المكتملة تلقائياً.
     - يستدعي `POST /api/invoices/generate-tanker`.
  4. **إنشاء فاتورة يدوية (`CreateInvoice`):** يفتح شاشة إنشاء فاتورة مخصصة.
- **الفلاتر الـ 4 المتقدمة والبحث:**
  - بحث نصي شامل (رقم الفاتورة، اسم المشترك، اسم المستأجر، كود العداد، أو البحث المركب `4/1`).
  - **فلتر فترة الفوترة (`billing_period`):** قائمة منسدلة بالفترات الفعلية `من YYYY-MM-DD إلى YYYY-MM-DD` (مجلوبة من `/api/invoices/billing-periods`).
  - **فلتر المربع السكني (`square_id`)**.
  - **فلتر الحالة (`status`):** الكل، مسودة (`draft`)، صادرة (`issued`)، مسددة جزئياً (`partially_paid`)، مسددة (`paid`)، متأخرة (`overdue`).
  - **فلتر المشترك (`subscriber_id`)**.
- **البيانات المعروضة في بطاقة/صف الفاتورة:**
  - `bill_number` (رقم الفاتورة).
  - `beneficiary_name`: إذا كان للعداد `tenant_name` يعرض `"{tenant_name} (مستأجر)"` وإلا يعرض اسم المشترك `subscriber.name`.
  - `bill_date` (التاريخ)، `consumption_fee` (الاستهلاك)، `previous_balance` (متأخرات سابقة)، `total_amount` (قيمة الفاتورة)، `total_due` (`إجمالي المطلوب = total_amount + previous_balance` باللون الأحمر)، `amount_paid` (المسدد من الفاتورة).
  - `remaining_amount` (**المتبقي**): إذا كانت `isLatestInvoice() == true` يعرض `max(getTrueRemainingAmount(), 0)` باللون الأحمر (إذا `> 0`) أو الأخضر (إذا `0`)، وإذا كانت فاتورة قديمة يعرض `-`.
  - شارة الحالة الملونة (`status`).
- **الإجراءات على كل فاتورة (Row Actions):**
  1. **زر "تحصيل" (`collect_payment` - باللون الأخضر):** يظهر فقط إذا كانت الفاتورة في حالة (`issued`, `partially_paid`, `overdue`) **و** كانت هي الفاتورة الأحدث (`isLatestInvoice()`).
  2. **زر "PDF" (`generate_pdf`):** يحمل ويفتح ملف الـ PDF الرسمي للفاتورة (`A5 Landscape`).
  3. **زر "طباعة" (`print`):** يطبع الفاتورة مباشرة عبر طابعة النظام أو طابعة البلوتوث الحرارية.
  4. **زر "إرسال واتساب" (`send_whatsapp_link`):** يظهر عندما يكون الواتساب مفعلاً وطريقة الإرسال `click_to_chat`، فيفتح تطبيق واتساب مباشرة بنص الفاتورة الكامل.
  5. **زر "إرسال واتساب (PDF)" (`send_whatsapp_api`):** يظهر عندما تكون طريقة الإرسال `meta_api` أو `local_api`، فيطلق `SendWhatsAppInvoiceJob` في الخلفية ويرسل الفاتورة كملف PDF للمشترك/المستأجر!
  6. **تعديل الفاتورة (`EditInvoice`)** و**حذف الفاتورة (`DeleteInvoice`)** (مع الحذف الجماعي `DeleteBulkAction`).

### 9. شاشة الإنشاء والتعديل اليدوي للفاتورة (`CreateEditInvoiceScreen`)
تطابق مخطط `InvoiceResource::form`:
- **قسم بيانات الفاتورة:**
  - `subscriber_id`: اختيار المشترك (عند تغييره: يجلب `previous_balance = subscriber.getOutstandingBalance()` تلقائياً).
  - `bill_number`: رقم الفاتورة (مولد تلقائياً `INV-YYYYMM-XXXX`، للعرض فقط).
  - `bill_date`: تاريخ الفاتورة (افتراضياً اليوم).
  - `status`: الحالة (`draft`, `issued`, `partially_paid`, `paid`, `overdue` - وعند الإنشاء تتحول تلقائياً إلى `issued`).
- **قسم تفاصيل المبالغ (جميعها بحقول رقمية `LTR` مع بادئة `ريال` وحساب تلقائي للإجمالي):**
  - `consumption_fee` (قيمة الاستهلاك)، `previous_balance` (المتأخرات السابقة)، `subscription_installment` (قسط الاشتراك)، `tanker_charges` (رسوم الوايت)، `credit_applied` (خصم رصيد دائن)، `total_amount` (الإجمالي)، `amount_paid` (المسدد - معطل للقراءة فقط).
- **قسم ملاحظات:** `notes` (نص متعدد الأسطر).

---

## الوحدة الخامسة: التحصيل الميداني وسندات القبض (`Payments & Collection Module` — تطابق [`PaymentResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/PaymentResource.php) و [`InvoiceController.php`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/InvoiceController.php))

### 10. شاشة المحصل الميداني السريع (`FieldCollectorScreen`)
مخصصة للمحصل الميداني لتحصيل الفواتير بأقصى سرعة:
- بحث فوري بالاسم، رقم الفاتورة، كود العداد، أو **البحث المركب بالمربع والمسار (`4/1`)** مع فلتر المربع السكني.
- تعرض الفواتير غير المسددة (`issued`, `overdue`, `partially_paid`) مع إبراز **المتبقي التراكمي الحقيقي (`true_remaining_amount`)** بخط عريض وواضح.
- زر **"تحصيل فوري"** على كل بطاقة يفتح نافذة التحصيل مباشرة.

### 11. نافذة/شاشة تسجيل دفعة وإصدار سند قبض (`CollectPaymentSheet / CreatePaymentScreen`)
تغطي كلاً من التحصيل المباشر من فاتورة (`InvoiceController@pay` / `InvoiceResource::collect_payment`) وإنشاء سند قبض من قسم المدفوعات (`PaymentResource`):
- **الحقول والتفاعلات الذكية:**
  - `subscriber_id`: اختيار المشترك (في حال الفتح من قسم المدفوعات العام؛ وعند اختياره يجلب النظام تلقائياً أحدث فاتورة غير مسددة ويملأ `invoice_id` و`invoice_amount` و`amount` بالمبلغ المستحق!).
  - `invoice_id`: الفاتورة المرتبطة (اختياري — مع خيار *"تطبيق على الأقدم تلقائياً (FIFO)"*؛ وعند اختيار فاتورة محددة يُحدَّث حقل `المبلغ المستحق` تلقائياً: `total_amount + previous_balance - amount_paid`).
  - `invoice_amount`: المبلغ المستحق (للعرض فقط `disabled`).
  - `receipt_number`: رقم سند القبض المتوقع (`REC-YYYYMM-XXXX`، للعرض فقط).
  - `amount`: المبلغ المستلم (حقل رقمي مطلوب `min: 0.01`، مع أزرار سريعة: `كامل المستحق`، `تصفير`).
  - `payment_date`: تاريخ الدفع (افتراضياً اليوم).
  - `payment_method`: طريقة الدفع (`cash` نقدي، `bank_transfer` تحويل بنكي، `check` شيك).
  - `reference_number`: رقم الحوالة / الشيك (**يظهر ويصبح مطلوباً `required` تلقائياً عندما تكون طريقة الدفع `bank_transfer` أو `check`**).
  - `reason`: حقل **"وذلك مقابل"** (يظهر في سند القبض الرسمي).
  - `notes`: ملاحظات إضافية.
- **شاشة نجاح السند الفورية (بعد الحفظ):**
  - تعرض بيانات السند الكاملة (`receipt_data`): رقم السند، المبلغ المستلم، المتبقي من الفاتورة (`invoice_remaining`)، إجمالي المتبقي للحساب (`outstanding_balance`)، الرصيد الدائن (`credit_balance`)، واسم المحصل.
  - أزرار فورية: **إرسال سند القبض واتساب**، **معاينة وطباعة السند (PDF / بلوتوث حراري)**، **إغلاق والعودة للقائمة**.

### 12. شاشة سجل المدفوعات وسندات القبض الشامل (`PaymentsListScreen`)
- **الأعمدة والبيانات:** رقم سند القبض (`receipt_number`)، اسم المشترك (`subscriber.name`)، المبلغ (`amount` باللون الأخضر)، تاريخ الدفع (`payment_date`)، شارة طريقة الدفع (`نقدي` / `تحويل بنكي` / `شيك`)، ورقم الفاتورة المرتبطة (`invoice.bill_number` أو `غير محدد`).
- **الفلاتر والبحث:** بحث برقم السند أو اسم المشترك، وفلتر بطريقة الدفع (`payment_method`) والتاريخ.
- **الإجراءات على كل سند قبض (Row Actions):**
  - **PDF (`generate_pdf`):** تحميل وفتح سند القبض الرسمي (`A5 Landscape` مع التفقيط العربي للمبلغ *"ريال يمني لا غير"*).
  - **طباعة (`print`):** طباعة السند مباشرة.
  - **إرسال واتساب (`send_whatsapp_link`):** فتح رابط واتساب السند المباشر.
  - **إرسال واتساب PDF (`send_whatsapp_api`):** إرسال السند كملف PDF عبر `SendWhatsAppPaymentJob`.
  - **تعديل السند (`EditPayment`)** و**حذف السند (`DeletePayment`)** (مع إعادة احتساب الفواتير والأرصدة تلقائياً في الخلفية).

---

## الوحدة السادسة: إدارة المشتركين والملف المالي الشامل 360° (`Subscribers 360° Module` — تطابق [`SubscriberResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/SubscriberResource.php))

### 13. شاشة قائمة المشتركين (`SubscribersListScreen`)
- **البيانات المعروضة لكل مشترك:** الرقم التسلسلي (`#id`)، اسم المشترك (`name`)، تاريخ بدء الاشتراك (`subscription_start_date`)، رقم الهاتف (`phone` باتجاه `LTR` مع زر اتصال سريع)، اسم المربع السكني (`square.name`)، عدد العدادات (`meters_count`)، شارة الحالة (`نشط` أخضر، `موقوف` كهرماني، `غير نشط` أحمر)، والرصيد المالي الصافي (دائن أخضر / مدين أحمر).
- **الفلاتر والبحث:** بحث بالاسم أو الهاتف أو كود العداد، فلترة بالمربع السكني (`square_id`)، وفلترة بالحالة (`status`).
- **الأزرار السريعة على بطاقة المشترك (مطابقة لـ `SubscriberResource` Row Actions):**
  1. **تحصيل دفعة (`collect_payment`):** يفتح نافذة تحصيل دفعة مباشرة لهذا المشترك (مع اختيار فاتورة أو التوزيع على الأقدم تلقائياً).
  2. **طلب وايت (`add_service`):** يفتح نافذة سريعة لإضافة طلب وايت مكتمل (`amount`, `service_date`, `notes`).
  3. **عرض الملف الشامل (`View/Edit`)** أو **حذف المشترك**.

### 14. شاشة إضافة وتعديل مشترك والملف المالي 360° (`SubscriberDetailAndEditScreen`)
تضم هذه الشاشة المركزية كل ما يخص المشترك في مكان واحد، مقسمة إلى:
- **القسم الأول: البيانات الأساسية للمشترك:**
  - `name` (مطلوب)، `phone` (مفتاح اليمن `+967`)، `square_id` (اختيار المربع السكني مع زر `+` سريع لإضافة مربع جديد)، `status` (`active`, `inactive`, `suspended`)، `subscription_start_date`، و`address`.
- **القسم الثاني: لوحة الملخص المالي وأرصدة العدادات المعزولة (مطابقة لقسم `الحساب المالي وسجل الحركات` في `SubscriberResource.php`):**
  - **بطاقة إجمالي الرصيد الدائن (للمشترك والعدادات):** تعرض مجموع الأرصدة الموجبة باللون الأخضر الزمردي.
  - **بطاقة إجمالي الرصيد المدين / المتأخرات (للمشترك والعدادات):** تعرض مجموع المديونيات باللون الأحمر.
  - **بطاقات أرصدة العدادات التابعة (`Meters Balances Repeater`):** تعرض لكل عداد تابع للمشترك:
    - عنوان العداد: `"عداد رقم: {id} ({unique_code}) - {tenant_name ?? 'بدون مستأجر'}"`.
    - الرصيد الدائن للعداد (`meter_credit`) والرصيد المدين للعداد (`meter_debit`).
- **القسم الثالث: التبويبات الـ 6 التفاعلية (`6 Relation Manager Tabs`):**
  1. **تبويب العدادات (`MetersRelationManager`):**
     - يعرض عدادات المشترك (`unique_code`, `path_number`, `tenant_name`, `status`, `readings_count`).
     - زر **"+ إضافة عداد للمشترك"**.
     - زر إجرائي على كل عداد: **"تسجيل قراءة" (`record_reading`)** يفتح نافذة إدخال قراءة لهذا العداد تحديداً مع خيارات الفوترة التلقائية، بالإضافة إلى زري التعديل والحذف.
  2. **تبويب الفواتير (`InvoicesRelationManager`):**
     - يعرض كافة فواتير المشترك (`bill_number`, `bill_date`, `total_amount`, `amount_paid`, `status`) مع زر عرض وتحصيل وطباعة لكل فاتورة.
  3. **تبويب المدفوعات (`PaymentsRelationManager`):**
     - يعرض كافة سندات القبض للمشترك (`receipt_number`, `amount`, `payment_date`, `payment_method`, `invoice.bill_number`) مع زر طباعة السند.
  4. **تبويب رسوم الاشتراك (`SubscriptionFeesRelationManager`):**
     - يعرض رسوم الاشتراك الخاصة بالمشترك (`meter.unique_code`, `amount`, `status`, `invoice.bill_number`).
     - أزرار: **"+ إضافة رسوم اشتراك"**، **"إصدار فاتورة اشتراك"** (إذا كانت `uninvoiced`)، تعديل، وحذف.
  5. **تبويب طلبات الوايت (`ServiceRequestsRelationManager`):**
     - يعرض طلبات الوايت الخاصة بالمشترك (`service_date`, `amount`, `status`, `notes`).
     - أزرار: **"+ إضافة طلب وايت"**، **"إنجاز"** (إذا كان `pending`)، **"إصدار فاتورة وايت"** (إذا كان `completed`)، تعديل، وحذف.
  6. **تبويب سجل الحركات المالية / كشف الحساب (`SubscriberTransactionsTable` Livewire Component):**
     - يعرض حركات جدول `transactions` الخاصة بالمشترك (`created_at`, `type` دائن/مدين، `الجهة`: الحساب العام أو عداد رقم X، `amount`، `description`).
     - **فلتر الجهة (`meter_id`):** قائمة منسدلة تتيح الفلترة بين: `الكل`، `الحساب العام (بدون عداد)`، أو `عداد محدد (مع اسم المستأجر)`.
     - زر **"تصدير / مشاركة كشف الحساب PDF"**.

---

## الوحدة السابعة: المربعات السكنية والعدادات (`Squares & Meters Modules`)

### 15. شاشة إدارة المربعات السكنية (`SquaresListScreen` & `SquareDetailScreen` — تطابق [`SquareResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/SquareResource.php))
- **قائمة المربعات:** تعرض `#id`، اسم المربع (`name`)، الوصف (`description`)، وعدد المشتركين في المربع (`subscribers_count`) مع البحث والترتيب.
- **إضافة / تعديل مربع:** حقل `name` (مطلوب، بحد أقصى 100 حرف) وحقل `description` (3 أسطر).
- **تفاصيل المربع (`SubscribersRelationManager`):** عند النقر على مربع سكني، يعرض قائمة كافة المشتركين التابعين لهذا المربع (`name`, `phone`, `status`, `balance`) مع زر **"+ إضافة مشترك جديد في هذا المربع"** مباشرة!

### 16. شاشة إدارة كافة العدادات (`MetersManagementScreen` & `MeterFormScreen` — تطابق [`MeterResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/Meters/MeterResource.php))
- **قائمة العدادات:** تعرض `unique_code` (رقم العداد)، `path_number` (رقم المسار)، `subscriber.name` (اسم المشترك)، `subscriber.square.name` (المربع السكني)، `tenant_name` (المستأجر)، وشارة الحالة (`active` نشط / `inactive` غير نشط).
- **نموذج إنشاء وتعديل عداد (`MeterForm`):**
  - `unique_code`: رقم العداد (مطلوب، فريد `unique`).
  - `path_number`: رقم المسار (رقمي، مع التحقق الفوري من عدم استخدامه لعداد آخر في نفس المربع السكني).
  - `subscriber_id`: اختيار المشترك المالك (قابل للبحث).
  - `tenant_name` (اسم المستأجر) و`tenant_phone` (رقم هاتف المستأجر بمفتاح `YE`):
    - **قاعدة الإظهار الذكية (مطابقة لـ `MeterForm.php`):** يظهران تلقائياً عندما يكون للمشترك أكثر من عداد (أو مع زر *"إضافة بيانات مستأجر لهذا العداد"* عند الحاجة).
  - `status`: الحالة (`active` / `inactive`).

---

## الوحدة الثامنة: رسوم الاشتراك، طلبات الوايت، التعرفة، ودفتر الأستاذ (`Financial Auxiliaries`)

### 17. شاشة إدارة رسوم الاشتراك (`SubscriptionFeesScreen` — تطابق [`SubscriptionFeeResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/SubscriptionFeeResource.php))
- **شريط الملخص العلوي:** يعرض **إجمالي رسوم الاشتراك (`Sum Summarizer`)** لجميع السجلات المعروضة.
- **البيانات المعروضة:** اسم المشترك (`subscriber.name`)، كود العداد (`meter.unique_code`)، المبلغ (`amount` ريال)، شارة الحالة (`uninvoiced` غير مفوترة / `invoiced` مفوترة / `partially_paid` مسدد جزئياً / `paid` مسدد)، رقم الفاتورة المرتبطة (`invoice.bill_number` قابل للنقر لفتح الفاتورة)، وتاريخ الإنشاء.
- **الفلاتر والإجراءات:** فلتر حسب الحالة (`status`)، زر **"+ إضافة رسوم اشتراك"** (يختار المشترك، ثم يفلتر عداداته، ويحدد المبلغ والملاحظات)، زر **"إصدار فاتورة"** للرسوم غير المفوترة، وتعديل وحذف فردي وجماعي.

### 18. شاشة إدارة طلبات الوايت (`ServiceRequestsScreen` — تطابق [`ServiceRequestResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/ServiceRequestResource.php))
- **شريط الملخص العلوي:** يعرض **إجمالي مبالغ طلبات الوايت (`Sum Summarizer`)**.
- **البيانات المعروضة:** اسم المشترك (مع علامة `📝 يوجد ملاحظة` إذا كان حقل الملاحظات غير فارغ)، سعر الوايت (`amount` ريال)، تاريخ الخدمة (`service_date`)، شارة الحالة (`pending` معلق / `completed` مكتمل / `invoiced` مفوتر / `paid` مسدد)، ورقم الفاتورة المرتبطة (`invoice.bill_number`).
- **الفلاتر:** فلتر بالحالة (`status`) وفلتر بنطاق تاريخ الخدمة (`created_from` إلى `created_until`).
- **الإجراءات:**
  - **زر "إنجاز" (`mark_completed` - باللون الأخضر مع أيقونة صح):** يظهر على الطلبات المعلقة (`pending`)، يطلب التأكيد، ويحول حالتها فوراً إلى `completed`.
  - **زر "إصدار فاتورة وايت":** يظهر على الطلبات المكتملة (`completed`) ليصدر لها فاتورة فوراً.
  - إضافة طلب وايت جديد، تعديل، وحذف فردي وجماعي.

### 19. شاشة التعرفة والتسعير (`TariffsScreen` — تطابق [`TariffResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/Tariffs/TariffResource.php))
- تعرض بطاقة بارزة في الأعلى توضح **سعر الوحدة النشط حالياً (`Tariff::getActivePrice()`)**.
- **قائمة التعرفات:** الاسم (`name`)، سعر الوحدة (`unit_price`)، شارة التفعيل (`is_active`)، تاريخ البدء (`start_date`)، وتاريخ الانتهاء (`end_date`).
- **فلتر المحذوفات (`TrashedFilter`):** بدون المحذوفة / مع المحذوفة / المحذوفة فقط.
- **الإجراءات:** إضافة تسعيرة، تعديل، حذف ناعم (`Soft Delete`)، استعادة (`Restore`)، وحذف نهائي (`Force Delete`).

### 20. شاشة سجل الحركات العام / دفتر الأستاذ (`TransactionsLedgerScreen` — تطابق [`TransactionResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/Transactions/TransactionResource.php))
- **للقراءة والمراجعة فقط (`Read-Only Ledger`)** لضمان النزاهة المحاسبية.
- **البيانات المعروضة:** التاريخ والوقت (`Y-m-d H:i`)، اسم المشترك (`subscriber.name`)، نوع الحركة بشارة ملونة (`payment` $\leftarrow$ `سند قبض (دائن)` أخضر / `invoice` $\leftarrow$ `فاتورة (مدين)` أحمر / `adjustment` $\leftarrow$ `تسوية` كهرماني)، المبلغ (`amount` بالأخضر للموجب والأحمر للسالب)، البيان (`description`)، والمرجع المرتبط.

---

## الوحدة التاسعة: إدارة المستخدمين والأدوار والصلاحيات (`Users & RBAC Shield Module`)

### 21. شاشة إدارة المستخدمين (`UsersManagementScreen` — تطابق [`UserResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/Users/UserResource.php))
- **القائمة:** تعرض اسم المستخدم، البريد الإلكتروني، الصورة الشخصية، شارات الأدوار المسندة إليه (`مدير نظام`, `محصل`, `قارئ عداد`)، وتاريخ الإنشاء، مع فلتر حسب الدور.
- **نموذج إنشاء وتعديل مستخدم:**
  - `name` (مطلوب)، `email` (مطلوب)، `password` (مطلوب عند الإنشاء، واختياري عند التعديل)، و`roles` (اختيار متعدد `MultiSelect` من الأدوار المتاحة في النظام).

### 22. شاشة إدارة الأدوار ومصفوفة الصلاحيات (`RolesAndPermissionsScreen` — تطابق [`RoleResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/Roles/RoleResource.php))
- **قائمة الأدوار:** تعرض اسم الدور (`name`)، `guard_name` (`web`)، عدد الصلاحيات الممنوحة (`permissions_count`)، وتاريخ التحديث.
- **شاشة إنشاء / تعديل دور (`Shield Matrix UI`):**
  - حقل اسم الدور (`name`) + مفتاح **"تحديد الكل (`Select All`)"**.
  - تبويبات منظمة تماماً كما في `Filament Shield`:
    1. **صلاحيات الموارد (Resources - 13 مورداً):** لكل مورد (`Invoice`, `Payment`, `Reading`, `Subscriber`, `Meter`, `Square`, `SubscriptionFee`, `ServiceRequest`, `Tariff`, `Transaction`, `User`, `Role`, `Setting`) بطاقة قابلة للطي تضم مفاتيح (`ViewAny`, `View`, `Create`, `Update`, `Delete`, `DeleteAny`, `Restore`, `ForceDelete`, إلخ).
    2. **صلاحيات الصفحات المخصصة (Pages - 6 صفحات):** (`ExportDataPage`, `InvoiceSettings`, `ReadingSettings`, `ReceiptSettings`, `WhatsAppSettings`, `EditProfilePage`).
    3. **صلاحيات الودجات (Widgets - 5 ودجات):** (`StatsOverview`, `MonthlyRevenueChart`, `LatestInvoicesWidget`, `OverdueInvoicesWidget`, `CustomAccountWidget`).

---

## الوحدة العاشرة: مراكز الإعدادات الخمسة والتصدير (`Settings Studio & Data Export Center`)

### 23. شاشة إعدادات فترة القراءات (`ReadingSettingsScreen` — تطابق [`ReadingSettings.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Pages/ReadingSettings.php))
تتحكم في الـ 5 مفاتيح الحاسمة التالية:
1. `auto_issue_invoice_on_reading` (`Toggle`): إصدار الفاتورة تلقائياً عند إدخال القراءة.
2. `reading_period` (`Select`): دورة القراءات (`weekly` أسبوعية - كل 7 أيام، `ten_days` كل 10 أيام، `half_monthly` نصف شهرية - كل 15 يوم، `monthly` شهرية).
3. `reading_day` (`Select` - يظهر فقط عندما تكون الدورة `monthly`): يوم القراءة الشهري (`يوم 20` إلى `يوم 28`، أو `last_day` آخر يوم في الشهر).
4. `grace_period` (`TextInput` رقمي): مدة السماح بالأيام قبل اعتبار الفاتورة متأخرة (`overdue`) — افتراضياً `14` يوماً.
5. `strict_overdue_on_new_invoice` (`Toggle`): اعتبار الفاتورة السابقة متأخرة فور إصدار فاتورة جديدة.

### 24. شاشة استوديو إعدادات وتصميم الفاتورة (`InvoiceSettingsStudioScreen` — تطابق [`InvoiceSettings.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Pages/InvoiceSettings.php))
تضم **7 تبويبات منظمة** مع زر **"معاينة حية لشكل الفاتورة"**:
- **تبويب 1 (الأساسيات والشعارات):** `invoice_project_name_ar`, `invoice_project_name_en`, `invoice_address_ar`, `invoice_address_en`, `invoice_phone_1`, `invoice_phone_2`, `invoice_collector_name` + رفع الصور الثلاث (`invoice_logo` شعار المشروع، `site_favicon` أيقونة الموقع، `invoice_watermark` العلامة المائية).
- **تبويب 2 (عناوين الأقسام):** `inv_title_main`, `inv_title_subscriber`, `inv_title_consumption`, `inv_title_total`.
- **تبويب 3 (حقول المشترك والفاتورة - 11 زوجاً من مفتاح الإظهار `Toggle` + النص البديل `Label`):** رقم الفاتورة، من تاريخ، إلى تاريخ، اسم المشترك، رقم العداد، الهاتف، المربع، العنوان، رقم الحساب، المستأجر، هاتف المستأجر.
- **تبويب 4 (فاتورة العداد - 9 أزواج Toggle + Label):** القراءة السابقة، القراءة الحالية، وحدات الاستهلاك، سعر الوحدة، قيمة الاستهلاك، الاشتراك، رسوم الوايت، المتأخرات، رصيد مخصوم.
- **تبويب 5 (فاتورة الاشتراك):** عنوان فاتورة الاشتراك، عنوان التفاصيل، وأزواج إظهار/تسمية: قيمة الاشتراك، المتأخرات، الملاحظات.
- **تبويب 6 (فاتورة الوايت):** عنوان فاتورة الوايت، عنوان التفاصيل، وأزواج إظهار/تسمية: قيمة الوايت، المتأخرات، الملاحظات.
- **تبويب 7 (تذييل الفاتورة):** أزواج إظهار/تسمية: المحصل، توقيع المشترك، رسالة الشكر، والملاحظات العامة.

### 25. شاشة استوديو إعدادات وتصميم سند القبض (`ReceiptSettingsStudioScreen` — تطابق [`ReceiptSettings.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Pages/ReceiptSettings.php))
تضم **5 تبويبات منظمة**:
- **تبويب 1 (الأساسيات المشتركة مع الفاتورة):** النصوص الأساسية والهواتف والشعار والعلامة المائية.
- **تبويب 2 (عناوين السند):** `rect_title_main`، وإظهار/تسمية قسم بيانات المشترك وقسم بيانات الدفع.
- **تبويب 3 (حقول السند والمشترك - 8 أزواج Toggle + Label):** رقم السند، تاريخ السند، اسم المشترك، الهاتف، المربع السكني، رقم الحساب، المستأجر، هاتف المستأجر.
- **تبويب 4 (بيانات الدفع والمبالغ - 10 أزواج Toggle + Label + قسم التفقيط):** طريقة الدفع، الفاتورة المرتبطة، رقم الحوالة/الشيك، إجمالي الفاتورة، مدفوعات سابقة، المبلغ المستلم، المتبقي من الفاتورة، إجمالي المتبقي للحساب، رصيد دائن للمشترك، وذلك مقابل، وقسم **المبلغ كتابتاً** (`rect_show_amount_in_words`, `rect_lbl_amount_in_words`, `rect_amount_words_suffix` مثل *"ريال يمني لا غير"*).
- **تبويب 5 (التذييل والملاحظات):** إظهار/تسمية الملاحظات، توقيع المحصل، وتوقيع المستلم.

### 26. شاشة إعدادات تنبيهات الواتساب والتحكم بالخادم المحلي (`WhatsAppSettingsScreen` — تطابق [`WhatsAppSettings.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Pages/WhatsAppSettings.php) و [`qr.blade.php`](file:///c:/Users/msi/Desktop/water-main/resources/views/filament/settings/qr.blade.php))
- **قسم الضبط الأساسي:**
  - `whatsapp_enabled` (تفعيل الخدمة بشكل عام).
  - `whatsapp_method` (طريقة الإرسال المعتمدة):
    1. `click_to_chat`: الرابط المباشر (مجاناً وبدون قيود).
    2. `meta_api`: مباشر عبر Meta Cloud API (يرفق ملف PDF).
    3. `local_api`: الخادم المحلي (مجاني 100% + يرفق PDF).
  - `auto_send_whatsapp_invoice` (إرسال الفاتورة تلقائياً عند الإصدار - يظهر لـ `meta_api` و`local_api`).
  - `auto_send_whatsapp_payment` (إرسال سند القبض تلقائياً عند السداد - يظهر لـ `meta_api` و`local_api`).
- **قسم حالة الخادم المحلي المباشرة (`Local API Server Live Control` - يظهر عند اختيار `local_api`):**
  - يقوم التطبيق باستعلام دوري كل 3 ثوانٍ (`Timer.periodic` يستدعي `GET /api/whatsapp/local-status` — مطابق لـ `wire:poll.3s="checkLocalServerStatus"`):
    - إذا كانت الحالة `offline`: يعرض تنبيهاً أحمر *"الخادم لا يعمل حالياً"* مع زر **"▶️ تشغيل الخادم من هنا"** (`POST /api/whatsapp/local-start`).
    - إذا كانت الحالة `waiting`: يعرض مؤشر دوران *"جاري تجهيز الخادم وطلب كود QR من واتساب..."*.
    - إذا كانت الحالة `qr`: يعرض صورة كود الـ **QR Code** مباشرة على شاشة الموبايل ليتم مسحها، مع تحديثها تلقائياً!
    - إذا كانت الحالة `connected`: يعرض شارة خضراء *"✅ الخادم يعمل وجاهز لاستقبال طلبات إرسال الفواتير بصيغة PDF آلياً"* مع زر **"⏹️ إيقاف الخادم نهائياً"** (`POST /api/whatsapp/local-stop`).
  - **شاشات مراقبة السجلات الحية (`Live Terminal Logs`):** نافذتان بتصميم طرفية داكنة (`#111827`) تعرضان آخر 15 سطراً من `whatsappLogs` (باللون الأخضر `#34D399`) و`queueLogs` (باللون الأزرق `#60A5FA`).
- **قسم قوالب الرسائل النصية (مع أزرار إدراج المتغيرات بلمسة واحدة مثل `{subscriber_name}`, `{bill_number}`, `{total_amount}`, `{pdf_url}`):**
  - قوالب الفاتورة، سند القبض، فاتورة الاشتراك، وفاتورة الوايت.
- **قسم إعدادات Meta Cloud API (يظهر عند اختيار `meta_api`):**
  - `whatsapp_phone_number_id` (مخفي مع زر إظهار)، `whatsapp_access_token`، `whatsapp_invoice_template`، `whatsapp_payment_template`، مع صناديق نسخ النص المقترح لقوالب ميتا.

### 27. شاشة إدارة الإعدادات العامة (`GeneralSettingsResourceScreen` — تطابق [`SettingResource.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Resources/Settings/SettingResource.php))
- إدارة كافة سجلات جدول `settings` مباشرة (`key`, `label`, `type` نص أو صورة، `value`, `image_path`) مع البحث، الإضافة، التعديل، والحذف.

### 28. شاشة مركز تصدير البيانات إلى Excel (`ExportDataCenterScreen` — تطابق [`ExportDataPage.php`](file:///c:/Users/msi/Desktop/water-main/app/Filament/Pages/ExportDataPage.php) و [`export-data.blade.php`](file:///c:/Users/msi/Desktop/water-main/resources/views/filament/pages/export-data.blade.php))
- **شريط الإحصائيات العلوي (4 بطاقات):** إجمالي المشتركين، إجمالي الفواتير، إجمالي القراءات، وإجمالي المربعات السكنية.
- **3 بطاقات تصدير احترافية:**
  1. **بطاقة تصدير المشتركين (باللون الأزرق الداكن `#1E3A8A`):**
     - الفلاتر: المربع السكني (`square_id`) + حالة المشترك (`status`).
     - زر **"تصدير وتحميل ملف Excel (`.xlsx`)"** $\leftarrow$ يحمل ملف `المشتركون_YYYY-MM-DD.xlsx` ويفتحه أو يشاركه عبر واتساب/البريد/الجهاز.
  2. **بطاقة تصدير الفواتير (باللون الأخضر الزمردي `#065F46`):**
     - الفلاتر: المربع السكني (`square_id`) + حالة الفاتورة (`status`) + من تاريخ (`from_date`) + إلى تاريخ (`to_date`).
     - زر **"تصدير وتحميل ملف Excel (`.xlsx`)"** $\leftarrow$ يحمل ملف `فواتير_المشتركين_YYYY-MM-DD.xlsx`.
  3. **بطاقة تصدير قراءات العدادات (باللون التركوازي `#0E7490`):**
     - الفلاتر: المربع السكني (`square_id`) + حالة الفوترة (`is_invoiced`: الكل / مفوترة / غير مفوترة) + من تاريخ (`from_date`) + إلى تاريخ (`to_date`).
     - زر **"تصدير وتحميل ملف Excel (`.xlsx`)"** $\leftarrow$ يحمل ملف `قراءات_العدادات_YYYY-MM-DD.xlsx`.

---

# الباب الخامس: هيكلة فريق التطوير وتقسيم المهام التفصيلي (Team Structure, Developer Roles, Tasks & Execution Guide)

لضمان إنجاز تطبيق الموبايل بأقصى سرعة وأعلى جودة وبدون أي تداخل أو تعارض في الكود (Zero Merge Conflicts)، تم تقسيم العمل هندسياً إلى **5 أدوار وظيفية متوازية ومستقلة**.

## 5.1 مرونة توزيع الفريق (حجم الفريق من 2 إلى 5 مطورين)

يمكن تطبيق هذا التقسيم مهما كان عدد المطورين الفعليين في الفريق وفق الجدول التالي:

| حجم الفريق الفعلي | كيف يتم توزيع الأدوار الـ 5 بالأسماء؟ |
|---|---|
| **5 مطورين (السرعة القصوى)** | **1. م. محمد (Dev 1)** + **2. م. أنور (Dev 2)** + **3. م. أكرم (Dev 3)** + **4. م. فؤاد (Dev 4)** + **5. م. أسامة (Dev 5)** يعملون جميعاً بالتوازي في نفس الوقت. |
| **4 مطورين (محمد، أنور، أكرم، فؤاد)** | يتولى **محمد** دور (1)، و**أنور** دور (2)، و**أكرم** دور (3)، و**فؤاد** يدمج دوري (4 + 5 بمساعدة الوكلاء الفرعيين Subagents). |
| **3 مطورين (محمد، أنور، أكرم)** | **م. محمد:** الدور (1 + 5: المعمارية والـ Core والـ API + لوحة القيادة والإعدادات والصلاحيات).<br>**م. أنور:** الدور (2 + 4: القراءات والعدادات والأوفلاين + المشتركون والمربعات والوايت والاشتراكات).<br>**م. أكرم:** الدور (3: الفواتير والتحصيل وسندات القبض والطباعة والواتساب والتعرفة والدفتر). |
| **مطوران اثنان (محمد، أنور)** | **م. محمد (Core, Admin & CRM):** الأدوار (1 + 4 + 5).<br>**م. أنور (Field & Finance Operations):** الأدوار (2 + 3). |

---

## 5.2 التفصيل الكامل لوظيفة ومهام كل مطور بالاسم (الأساسية + الجانبية + كيف ينفذها خطوة بخطوة)

---

### 👨‍💼 1. المهندس محمد (Dev 1): قائد المعمارية والـ Core، الربط الشبكي، واستكمال مسارات الـ Backend API
**المسمى الوظيفي:** `Lead Mobile Architect, Core Infrastructure & Backend API Parity Engineer`

#### أولاً: نطاق المسؤولية الأساسي لمحمد
هو حجر الأساس للفريق؛ يتولى تجهيز البنية التحتية لتطبيق Flutter (`lib/core/`)، وبناء مكتبة المكونات المشتركة (`Design System Widgets`)، وإدارة المصادقة والجلسة، بالتوازي مع استكمال المسارات الناقصة في Laravel (`routes/api.php`) حتى لا يتعطل أي مطور في الفريق (أنور، أكرم، فؤاد، أسامة).

#### ثانياً: المهام الأساسية لمحمد (Primary Tasks)
1. **استكمال مسارات الـ API في Laravel (`Backend API Parity`):**
   - تحديث المتحكمات الحالية في `app/Http/Controllers/Api/` وإنشاء المتحكمات المكملة (`SquareApiController`, `MeterApiController`, `PaymentApiController`, `SubscriptionFeeApiController`, `ServiceRequestApiController`, `TariffApiController`, `TransactionApiController`, `UserRoleApiController`, `ExportApiController`) لتغطية الـ 68 مساراً المحددة في الباب الثاني.
   - إضافة مسارات توليد وتحميل ملفات الـ PDF عبر Sanctum (`GET /api/invoices/{id}/pdf` و`GET /api/payments/{id}/pdf`).
2. **تأسيس مشروع Flutter والطبقة الأساسية (`lib/core/`):**
   - إعداد `pubspec.yaml`، وهيكل المجلدات الكامل، وحقن التبعيات (`GetIt` في `injection_container.dart`).
   - بناء عميل الشبكة الموحد (`DioClient`) مع الـ Interceptors الثلاثة: `AuthInterceptor` (إرفاق التوكن تلقائياً)، `ErrorInterceptor` (تحويل أخطاء Laravel `422 Validation` و`403` و`404` و`500` إلى رسائل عربية واضحة)، و`OfflineCacheInterceptor`.
3. **بناء محرك التجاوب ونظام التصميم (`Responsive & Design System`):**
   - بناء `AppTheme` (الوضع النهاري والليلي، خط `Tajawal`، ألوان `#02687E` و`#F59E0B`).
   - بناء `AdaptiveScaffold` (الذي يتحول تلقائياً بين `BottomNavigationBar` للهواتف و`NavigationRail` للتابلت والوضع الأفقي).
   - بناء المكونات المشتركة في `lib/core/widgets/`:
     - `AppTextField` (مع فرض `Directionality.ltr` تلقائياً لحقول الأرقام والهواتف وتحويل الأرقام العربية إلى إنجليزية أثناء الكتابة).
     - `CompoundSearchBar` (شريط البحث الذكي الداعم لصيغة `المربع/المسار` مثل `4/1`).
     - `StatusBadge`, `StatCard`, `ShimmerSkeleton`, `EmptyStateWidget`, `ConfirmDialog`.
4. **بناء وحدة المصادقة، الصلاحيات، والملف الشخصي (`lib/features/auth/`):**
   - شاشة `SplashScreen`، شاشة `LoginScreen` (`SCR-01`)، وشاشة `EditProfileScreen` (`SCR-02`: تحديث الاسم، البريد، كلمة المرور، ورفع الصورة الشخصية `avatar`)، وشاشة مراقب المزامنة الأوفلاين (`SCR-28`).
   - بناء `PermissionService` وودجة `PermissionGuard` للتحقق من أدوار وصلاحيات `Spatie/FilamentShield`.

#### ثالثاً: المهام الجانبية والمساندة لمحمد (Secondary / Side Tasks)
1. إعداد مجموعة `Postman / Bruno Collection` شاملة لجميع الـ 68 Endpoint ومشاركتها مع أنور وأكرم وفؤاد وأسامة.
2. مراجعة طلبات الدمج (`Pull Requests Code Review`) من أنور وأكرم وفؤاد وأسامة والتأكد من التزامهم بمعايير التجاوب والأداء.
3. إعداد بناء النسخ التجريبية (`APK / AppBundle / IPA`) وإعداد أيقونة التطبيق الديناميكية وشاشة الإقلاع (`flutter_native_splash`).

#### رابعاً: كيف ينفذ محمد مهامه خطوة بخطوة (How to Implement)
1. **اليوم 1–2:** يبدأ فوراً بتجهيز مستودع Flutter وبناء `lib/core/` (Theme, Dio, GoRouter, AdaptiveScaffold, Shared Widgets) ويرفعها على الفرع الرئيسي `develop` حتى يسحبها باقي الفريق في اليوم الثاني.
2. **اليوم 2–4:** يستكمل مسارات الـ API الناقصة في Laravel بالترتيب حسب أولوية الفريق (أولاً: الفواتير والمدفوعات والقراءات لأنور وأكرم، ثانياً: المشتركون والعدادات والمربعات لفؤاد، ثالثاً: الإعدادات والتعرفة والمستخدمون والتصدير لأسامة).
3. **اليوم 5+:** ينجز شاشات `Auth & Profile`، ويدعم الفريق في ربط الـ `Offline Sync` وحل أي إشكالية في الـ API.

---

### 👷‍♂️ 2. المهندس أنور (Dev 2): مهندس العمليات الميدانية — القراءات، العدادات، والمزامنة الأوفلاين
**المسمى الوظيفي:** `Field Operations Engineer (Readings, Meters, Compound Search & Offline Sync)`

#### أولاً: نطاق المسؤولية الأساسي لأنور
مسؤول عن تجربة قارئ العدادات الميداني وإدارة العدادات وقراءاتها بالكامل (`lib/features/readings/` و `lib/features/meters/`)، بالإضافة إلى محرك العمل بدون إنترنت (`Drift Offline Database & Sync Queue`) وحاسبة فترات القراءة المحلية.

#### ثانياً: المهام الأساسية لأنور (Primary Tasks)
1. **بناء وحدة القارئ الميداني السريع (`FieldMeterReaderScreen` — `SCR-10`):**
   - ربط `GET /api/readings/meters` مع دعم فلتر المربع السكني (`square_id`) والبحث المركب (`4/1`, `4-1`) وترتيب `is_path_match`.
   - بناء ميزة **"الانتقال السريع للمسار التالي (`Next Path`)"** بحيث ينتقل القارئ من مسار `1` إلى مسار `2` بضغطة واحدة.
   - دمج ماسح الكاميرا (`mobile_scanner`) لمسح باركود/QR العداد.
2. **بناء نافذة/شاشة تسجيل وتعديل القراءة (`RecordReadingSheet` — `SCR-11`):**
   - ربط `GET /api/readings/calculate-dates` لجلب `last_reading`, `period_start`, `period_end`, `auto_issue_invoice`, ورسوم الاشتراك/الوايت غير المفوترة.
   - بناء الحاسبة الفورية للاستهلاك أثناء الكتابة (`consumption_units = current - previous`) والتحقق الصارم من أن `current_reading >= previous_reading`.
   - بناء قسم تنبيه الفوترة التلقائية الكهرماني (`auto_invoice_warning`) ومفاتيح تضمين قسط الاشتراك (`has_subscription_fee`, `subscription_installment`) ورسوم الوايت (`has_tanker_fee`, `tanker_installment`).
   - دعم التقاط وضغط صورة العداد (`image_picker`) ورفعها عبر `POST /api/readings`.
   - بناء نافذة ملخص الفاتورة الصادرة تلقائياً عقب حفظ القراءة مع أزرار الواتساب والطباعة الفورية.
3. **بناء شاشة سجل وإدارة القراءات الشامل (`ReadingsManagementScreen` — `SCR-12`):**
   - عرض كافة القراءات مع الفلترة الثلاثية (`الكل` / `مفوترة` / `غير مفوترة`)، المربع، والتاريخ.
   - تفعيل التعديل والحذف الفردي فقط للقراءات غير المفوترة (`!is_invoiced`).
   - بناء وضع التحديد المتعدد (`Multi-Selection Mode`) لتنفيذ الإجراءين الجماعيين:
     - **إصدار الفواتير الجماعي (`bulk-invoice`)** للقراءات غير المفوترة المحددة.
     - **الحذف الجماعي الذكي (`bulk-delete`)** الذي يتخطى القراءات المفوترة ويعرض تقرير الحذف والتخطي.
4. **بناء وحدة إدارة العدادات (`lib/features/meters/` — `SCR-08` & `SCR-09`):**
   - قائمة كافة العدادات (`MetersManagementScreen`) مع البحث والفلترة بالحالة والمربع.
   - شاشة إضافة وتعديل عداد (`MeterFormScreen`) مع التحقق من عدم تكرار `path_number` في نفس المربع السكني، ومنطق إظهار حقلي `tenant_name` و`tenant_phone` ديناميكياً.
5. **بناء محرك العمل أوفلاين (`Offline-First Engine` في `lib/core/local_storage/`):**
   - إنشاء جداول `Drift (SQLite)` لتخزين المربعات والعدادات وآخر القراءات محلياً.
   - بناء `DatePeriodCalculator` في Dart مطابق تماماً لـ `ReadingService.php` (`monthly`, `half_monthly`, `ten_days`, `weekly`, `reading_day`) لكي يحسب القارئ فترة القراءة حتى لو كان بدون إنترنت!
   - بناء `SyncQueueManager` لمزامنة القراءات المسجلة أوفلاين فور عودة الإنترنت.

#### ثالثاً: المهام الجانبية والمساندة لأنور (Secondary / Side Tasks)
1. بناء مكون بطاقة العداد المصغرة ومكون نافذة `تسجيل قراءة` القابل لإعادة الاستخدام داخل ملف المشترك (`MetersRelationManager` الذي يبنيه **فؤاد**).
2. تحسين أداء القوائم الطويلة (`SliverList` + Pagination + ضغط الصور قبل الرفع).

#### رابعاً: كيف ينفذ أنور مهامه خطوة بخطوة (How to Implement)
1. يبدأ بتوليد نماذج `MeterModel`, `ReadingModel`, `MeterPeriodResponseModel` باستخدام `freezed`.
2. يبني `DatePeriodCalculator` و`CompoundSearchParser` ويكتب لهما اختبارات وحدة (`Unit Tests`) للتأكد من تطابقهما 100% مع PHP.
3. يبني `ReadingsCubit` و`MetersCubit` ثم الشاشات المتجاوبة للهاتف والتابلت، ثم يربط طبقة التخزين المحلي `Drift`.

---

### 💰 3. المهندس أكرم (Dev 3): مهندس النظام المالي — الفواتير، التحصيل، سندات القبض، الطباعة والواتساب
**المسمى الوظيفي:** `Financial & Billing Systems Engineer (Invoices, Payments, PDF/Thermal Print & WhatsApp)`

#### أولاً: نطاق المسؤولية الأساسي لأكرم
مسؤول عن الدورة المالية الكاملة للنظام داخل التطبيق: الفواتير ومحركات إصدارها الثلاثة (`lib/features/invoices/`)، التحصيل الميداني وسندات القبض وتوزيع FIFO (`lib/features/payments/`)، التعرفة والتسعير (`lib/features/tariffs/`)، بالإضافة إلى خدمات معاينة وطباعة الـ PDF، الطباعة الحرارية عبر البلوتوث، وإرسال رسائل الواتساب.

#### ثانياً: المهام الأساسية لأكرم (Primary Tasks)
1. **بناء وحدة الفواتير الكاملة (`lib/features/invoices/` — `SCR-14`, `SCR-15`, `SCR-16`):**
   - شاشة قائمة الفواتير (`InvoicesListScreen`) مع الفلاتر الـ 4 (`billing_period`, `square_id`, `status`, `subscriber_id`) والبحث الذكي بـ `beneficiary_name` (المشترك أو المستأجر) والمسار `4/1`.
   - حساب وعرض `total_due` (`total_amount + previous_balance`) وعرض `remaining_amount` (`true_remaining_amount` للفاتورة الأحدث).
   - بناء النوافذ الثلاث لإصدار الفواتير (`Header Actions`):
     1. نافذة **إصدار فاتورة عداد (`GenerateMeterInvoiceSheet`)** مع الاختيار المدمج للمشترك/المستأجر والعداد ومفاتيح تضمين قسط الاشتراك ورسوم الوايت.
     2. نافذة **إصدار فاتورة اشتراك (`GenerateSubscriptionInvoiceSheet`)**.
     3. نافذة **إصدار فاتورة وايت (`GenerateTankerInvoiceSheet`)**.
   - شاشة الإنشاء والتعديل اليدوي للفاتورة (`CreateEditInvoiceScreen`)، إعادة الاحتساب الفردي والجماعي، والحذف الفردي والجماعي.
2. **بناء وحدة التحصيل وسندات القبض (`lib/features/payments/` — `SCR-13`, `SCR-17`, `SCR-18`):**
   - شاشة المحصل الميداني السريع (`FieldCollectorScreen`) لعرض الفواتير غير المسددة وتحصيلها بلمسة واحدة.
   - نافذة/شاشة تسجيل الدفعة (`CollectPaymentSheet` & `CreatePaymentScreen`):
     - الجلب التلقائي لأحدث فاتورة غير مسددة والمبلغ المستحق عند اختيار المشترك.
     - إظهار وإلزامية حقل `reference_number` (رقم الحوالة/الشيك) عندما تكون طريقة الدفع `bank_transfer` أو `check`.
     - دعم حقل `reason` ("وذلك مقابل") و`notes`.
   - شاشة سجل كافة المدفوعات (`PaymentsListScreen`) مع الفلترة بطريقة الدفع، التعديل، والحذف.
3. **بناء محرك الطباعة المزدوج (`PDF A5 Landscape + Bluetooth Thermal Printer`):**
   - بناء `PdfPrintService`: تحميل ملف الـ PDF الرسمي للفاتورة (`/api/invoices/{id}/pdf`) أو السند (`/api/payments/{id}/pdf`)، عرضه في نافذة معاينة داخلية (`PdfPreviewSheet`) مع أزرار الطباعة المباشرة (`Printing.layoutPdf`) والمشاركة كملف عبر واتساب أو تطبيقات المشاركة (`SharePlus`).
   - بناء `ThermalPrinterService`: تصميم قالب إيصال حراري عربي منسق (58mm/80mm) للفاتورة وسند القبض لطابعات البلوتوث الميدانية، مع أداة التفقيط العربي (`ArabicNumberSpellout`: *"خمسمائة ريال يمني فقط لا غير"* مطابقة لمنطق `receipt.blade.php`).
4. **بناء محرك إرسال الواتساب (`WhatsAppLauncherService`):**
   - دعم الطريقتين في الواجهة:
     - إذا كانت الطريقة `click_to_chat`: توليد رسالة الفاتورة أو السند (مع معالجة البلوكات الشرطية `{if:subscription_fee}...{/if:subscription_fee}` وتنسيق رقم الهاتف اليمني `967...` وإعطاء الأولوية لـ `tenant_phone` إن وجد) وفتح الرابط المباشر `https://wa.me/...` عبر `url_launcher`.
     - إذا كانت الطريقة `meta_api` أو `local_api`: إظهار زر **"إرسال واتساب (PDF)"** الذي يستدعي `/api/invoices/{id}/whatsapp` أو `/api/payments/{id}/whatsapp`.
5. **بناء وحدة التعرفة والتسعير (`lib/features/tariffs/` — `SCR-21`):**
   - شاشة إدارة التعرفة (`TariffsScreen`)، عرض سعر الوحدة النشط، الإضافة، التعديل، الحذف الناعم، الاستعادة (`Restore`)، والحذف النهائي (`Force Delete`) مع فلتر `TrashedFilter`.

#### ثالثاً: المهام الجانبية والمساندة لأكرم (Secondary / Side Tasks)
1. توفير مكون `CollectPaymentSheet` ومكون `InvoiceCard` و`PaymentCard` كمكونات جاهزة ليستخدمها **فؤاد** داخل ملف المشترك (التبويبين 2 و3) و**أسامة** داخل لوحة القيادة (`OverdueInvoicesWidget`).
2. بناء أداة `CurrencyFormatter` و`ArabicNumberSpellout` في `lib/core/utils/`.

#### رابعاً: كيف ينفذ أكرم مهامه خطوة بخطوة (How to Implement)
1. يبدأ ببناء `InvoicesCubit` و`PaymentsCubit` وربط مسارات الفواتير غير المسددة والتحصيل السريع.
2. يبني النوافذ الثلاث لإصدار الفواتير (`Meter`, `Subscription`, `Tanker`) ويتحقق من تطابق الحسابات مع `InvoiceService.php`.
3. يبني خدمات `PdfPrintService` و`WhatsAppLauncherService` و`ThermalPrinterService` ثم ينجز وحدة `Tariffs`.

---

### 👥 4. المهندس فؤاد (Dev 4): مهندس إدارة المشتركين 360°، المربعات، الاشتراكات، طلبات الوايت، ودفتر الأستاذ
**المسمى الوظيفي:** `CRM & Auxiliary Services Engineer (Subscribers 360°, Squares, Subscription Fees, Tankers & Ledger)`

#### أولاً: نطاق المسؤولية الأساسي لفؤاد
مسؤول عن إدارة المشتركين والملف المالي الشامل (`lib/features/subscribers/`) بتبويباته الـ 6، وإدارة المربعات السكنية (`lib/features/squares/`)، ووحدة رسوم الاشتراك (`lib/features/subscription_fees/`)، ووحدة طلبات الوايت (`lib/features/service_requests/`)، ووحدة دفتر الأستاذ العام (`lib/features/transactions/`).

#### ثانياً: المهام الأساسية لفؤاد (Primary Tasks)
1. **بناء وحدة المشتركين والملف المالي 360° (`lib/features/subscribers/` — `SCR-05`, `SCR-06`, `SCR-07`):**
   - شاشة قائمة المشتركين (`SubscribersListScreen`) مع البحث، فلتر المربع السكني، فلتر الحالة، وأزرار الإجراءات السريعة على كل مشترك (**تحصيل دفعة**، **طلب وايت**، **تعديل/عرض**).
   - شاشة إنشاء وتعديل المشترك والملف المالي الشامل (`SubscriberDetailAndEditScreen`):
     - نموذج البيانات الأساسية (مع حقل الهاتف بمفتاح اليمن `+967` وتاريخ بدء الاشتراك).
     - لوحة الملخص المالي: بطاقة إجمالي الرصيد الدائن، بطاقة إجمالي الرصيد المدين، وقائمة أرصدة العدادات التابعة المعزولة (`meter_credit` و`meter_debit` لكل عداد).
     - بناء **التبويبات الـ 6 (`6 Relation Manager Tabs`)** داخل صفحة المشترك:
       1. تبويب العدادات (مع زر إضافة عداد وزر **"تسجيل قراءة"** السريع بالتعاون مع مكون **أنور**).
       2. تبويب الفواتير (بالتعاون مع مكون **أكرم**).
       3. تبويب المدفوعات (بالتعاون مع مكون **أكرم**).
       4. تبويب رسوم الاشتراك (مع زر إضافة رسوم وزر إصدار فاتورة اشتراك).
       5. تبويب طلبات الوايت (مع زر إضافة طلب وزر إنجاز وزر إصدار فاتورة وايت).
       6. تبويب سجل الحركات المالية (`SubscriberTransactionsTab` المطابق لمكون Livewire `SubscriberTransactionsTable` مع فلتر الجهة: `الكل` / `الحساب العام` / `عداد محدد`).
2. **بناء وحدة المربعات السكنية (`lib/features/squares/` — `SCR-04`):**
   - قائمة المربعات (`SquaresListScreen`) مع عرض عدد المشتركين `subscribers_count` في كل مربع، الإضافة، التعديل، والحذف الفردي والجماعي.
   - شاشة تفاصيل المربع (`SquareDetailScreen`) التي تعرض المشتركين التابعين للمربع (`SubscribersRelationManager`) مع إمكانية إضافة/تعديل/حذف مشترك مباشرة داخل المربع.
3. **بناء وحدة رسوم الاشتراك (`lib/features/subscription_fees/` — `SCR-19`):**
   - شاشة قائمة رسوم الاشتراك (`SubscriptionFeesScreen`) مع شريط إجمالي المبالغ (`Sum Summarizer`)، الفلترة بالحالة، نافذة إضافة رسوم اشتراك (اختيار المشترك ثم عداده)، زر إصدار فاتورة اشتراك، والتعديل والحذف.
4. **بناء وحدة طلبات الوايت (`lib/features/service_requests/` — `SCR-20`):**
   - شاشة قائمة طلبات الوايت (`ServiceRequestsScreen`) مع شريط إجمالي المبالغ (`Sum Summarizer`)، الفلترة بالحالة ونطاق التاريخ (`created_from`..`created_until`)، مؤشر وجود ملاحظة (`📝 يوجد ملاحظة`)، زر **"إنجاز" (`mark_completed`)** للطلبات المعلقة، زر **"إصدار فاتورة وايت"**، والإضافة والتعديل والحذف.
5. **بناء وحدة سجل الحركات العام / دفتر الأستاذ (`lib/features/transactions/` — `SCR-22`):**
   - شاشة `TransactionsLedgerScreen` (للقراءة فقط) مع البحث والفلترة بالمشترك والعداد والنوع (`سند قبض (دائن)`, `فاتورة (مدين)`, `تسوية`).

#### ثالثاً: المهام الجانبية والمساندة لفؤاد (Secondary / Side Tasks)
1. بناء مكون اختيار المشترك والعداد القابل للبحث السريع (`SubscriberMeterSelectorWidget`) ليستخدمه **أنور** و**أكرم** في نوافذهم.
2. تصميم قالب PDF لكشف حساب المشترك التفصيلي (`Subscriber Statement PDF`) ومشاركته عبر واتساب.

#### رابعاً: كيف ينفذ فؤاد مهامه خطوة بخطوة (How to Implement)
1. يبدأ بوحدة `Squares` ووحدة `Subscribers` (القائمة + نموذج الإضافة والتعديل + الملخص المالي).
2. يبني وحدتي `SubscriptionFees` و`ServiceRequests` و`TransactionsLedger`.
3. يركّب التبويبات الـ 6 داخل `SubscriberDetailAndEditScreen` مع دعم `Master-Detail Split View` على شاشات التابلت.

---

### 📊 5. المهندس أسامة (Dev 5): مهندس لوحة القيادة، استوديو الإعدادات المتقدمة، الصلاحيات، والتصدير
**المسمى الوظيفي:** `Admin, Analytics, Settings Studio, RBAC & Data Export Engineer`

#### أولاً: نطاق المسؤولية الأساسي لأسامة
مسؤول عن الشاشة الرئيسية للوحة القيادة والرسوم البيانية والتقارير (`lib/features/dashboard/`)، ومراكز الإعدادات الخمسة المتقدمة والتحكم الحي بخادم الواتساب المحلي (`lib/features/settings/`)، وإدارة المستخدمين والأدوار ومصفوفة صلاحيات Shield (`lib/features/users_roles/`)، ومركز تصدير بيانات الإكسل (`lib/features/exports/`).

#### ثانياً: المهام الأساسية لأسامة (Primary Tasks)
1. **بناء شاشة لوحة القيادة والودجات الـ 5 والتقارير (`lib/features/dashboard/` — `SCR-03` & `SCR-27`):**
   - بناء `CustomAccountBanner` وبطاقات `StatsOverviewGrid` الأربع المزودة بمخططات `Sparkline`.
   - بناء الرسم البياني التفاعلي `MonthlyRevenueChartCard` لآخر 6 أشهر باستخدام `fl_chart`.
   - بناء قسمي `LatestInvoicesSection` (أحدث 5 فواتير) و`OverdueInvoicesSection` (الفواتير المتأخرة مع حالة الاحتفال الفارغة 🎉).
   - بناء شاشة التقارير المالية والتحليلية (`ReportsScreen`).
2. **بناء مراكز الإعدادات الخمسة (`lib/features/settings/` — `SCR-23`):**
   - **شاشة 1 (`ReadingSettingsScreen`):** إدارة دورة القراءات، يوم القراءة، مدة السماح، والفوترة التلقائية.
   - **شاشة 2 (`InvoiceSettingsStudioScreen`):** بناء التبويبات الـ 7 لتخصيص وتصميم الفاتورة، ورفع الصور الثلاث (`invoice_logo`, `site_favicon`, `invoice_watermark`)، وبناء مكون `FieldConfigTile` الموحد (مفتاح إظهار `Toggle` + حقل المسمى البديل `TextInput`).
   - **شاشة 3 (`ReceiptSettingsStudioScreen`):** بناء التبويبات الـ 5 لتخصيص وتصميم سند القبض شاملة إعدادات تفقيط المبلغ كتابةً.
   - **شاشة 4 (`WhatsAppSettingsScreen` & `LocalServerControlWidget`):**
     - إعدادات تفعيل الواتساب، اختيار الطريقة (`click_to_chat`, `meta_api`, `local_api`)، محرر قوالب الرسائل مع أزرار إدراج المتغيرات الذكية (`{subscriber_name}`, `{bill_number}`, إلخ)، وإعدادات Meta API.
     - بناء **لوحة التحكم الحية بالخادم المحلي (`Local Server Live Monitor`)** التي تستعلم كل 3 ثوانٍ (`Timer.periodic`) وتعرض: حالة الاتصال، زر تشغيل الخادم عن بُعد، زر إيقاف الخادم، صورة كود الـ **QR Code** للمسح المباشر، ونافذتي سجلات الطرفية الحية (`whatsappLogs` و`queueLogs`).
   - **شاشة 5 (`GeneralSettingsResourceScreen`):** إدارة جدول الإعدادات العام (`SettingResource`).
3. **بناء وحدة المستخدمين والأدوار ومصفوفة الصلاحيات (`lib/features/users_roles/` — `SCR-24` & `SCR-25`):**
   - شاشة إدارة المستخدمين (`UsersManagementScreen`) وإسناد الأدوار المتعددة.
   - شاشة إدارة الأدوار (`RolesAndPermissionsScreen`) وبناء مصفوفة الصلاحيات التفاعلية (`Filament Shield Matrix`: تحديد الكل، صلاحيات الـ 13 مورداً، الـ 6 صفحات، والـ 5 ودجات).
4. **بناء مركز تصدير البيانات إلى Excel (`lib/features/exports/` — `SCR-26`):**
   - شاشة `ExportDataCenterScreen` مع بطاقات الإحصائيات الأربع في الأعلى، والبطاقات الثلاث لتصدير (المشتركين، الفواتير، القراءات) مع الفلاتر الخاصة بكل بطاقة، وتحميل ملف `.xlsx` وفتحه أو مشاركته عبر `ExcelExportService`.

#### ثالثاً: المهام الجانبية والمساندة لأسامة (Secondary / Side Tasks)
1. بناء معاينة حية مصغرة (`Live Template Preview`) داخل شاشات إعدادات الفاتورة والسند والواتساب ليرى المدير أثر تغييراته فوراً قبل الحفظ.
2. كتابة اختبارات الواجهة (`Widget Tests`) واختبارات التكامل (`Integration Tests`) للشاشات الإدارية.

---

## 5.3 استراتيجية الفروع والتكامل بين الفريق (Git Workflow & Integration Contracts)

لمنع أي تعارض أو تعطيل أثناء عمل المهندسين الخمسة (**محمد، أنور، أكرم، فؤاد، أسامة**) في وقت واحد:
1. **الفروع المعتمدة (Git Branches):**
   - `main`: النسخة المستقرة الجاهزة للإصدار.
   - `develop`: فرع التكامل اليومي للفريق.
   - فروع المهندسين المستقلة:
     - `feat/mohammed-core-auth-api` (فرع المهندس محمد)
     - `feat/anwar-readings-meters-offline` (فرع المهندس أنور)
     - `feat/akram-invoices-payments-print` (فرع المهندس أكرم)
     - `feat/fouad-subscribers-squares-services` (فرع المهندس فؤاد)
     - `feat/osama-dashboard-settings-rbac-exports` (فرع المهندس أسامة)
2. **قاعدة عدم التعديل المتقاطع (Strict Ownership Rule):**
   - لا يقوم أي مطور من (أنور، أكرم، فؤاد، أسامة) بتعديل ملفات `lib/core/` أو `app_router.dart` مباشرة دون تنسيق؛ بل يضع مسارات وحدته في ملف فرعي `lib/features/{feature}/presentation/routes.dart` ويقوم **المهندس محمد** باستيرادها في `app_router.dart`.
3. **عقود البيانات المسبقة (Mock Data & Contracts Day 1):**
   - في اليوم الأول، يعتمد جميع المطورين على هياكل الـ JSON الموثقة في الباب الثاني من هذه الخطة لبناء الـ `Models` والـ `Fake/Mock Repositories` فوراً حتى لو كان مسار الـ API في Laravel قيد الاستكمال في نفس اليوم.

---

# الباب السادس: دليل تسريع الإنجاز باستخدام الذكاء الاصطناعي والوكلاء الفرعيين (The AI Agents & Subagents Acceleration Playbook)

لتنفيذ هذا المشروع الضخم في وقت قياسي (بسرعة تعادل **5 أضعاف** التطوير التقليدي)، **يحق بل ويُطلب من كل مطور من مطوري الفريق الخمسة (محمد، أنور، أكرم، فؤاد، أسامة) استخدام الوكلاء الذكيين (`Agents`) والوكلاء الفرعيين (`Subagents`)** لتفويض المهام المتوازية وإنجاز طبقات البيانات، المنطق، الواجهات، والاختبارات في آنٍ واحد.

---

## 6.1 كيف يعمل نظام الوكلاء والوكلاء الفرعيين (`Main Agent + Parallel Subagents`)؟

عندما يعمل المطور (محمد، أنور، أكرم، فؤاد، أو أسامة) داخل بيئة التطوير الذكية (مثل Antigravity / Cursor / Claude Code):
1. **الوكيل الرئيسي للمطور (`Main Agent`):** يعمل كقائد تقني لوحدة المطور، يقرأ هذه الوثيقة (`FLUTTER_MOBILE_MASTER_PLAN.md`)، ويقسم الميزة المطلوبة إلى مهام مستقلة لا تتعارض في الملفات.
2. **استدعاء الوكلاء الفرعيين بالتوازي (`Parallel Subagents Invocation`):**
   - بدلاً من كتابة الكود ملفاً تلو الآخر بشكل تسلسلي، يقوم الوكيل الرئيسي بتعريف واستدعاء **3 إلى 4 وكلاء فرعيين (`Subagents`) في نفس اللحظة** عبر أداة `define_subagent` و `invoke_subagent`:
     - **وكيل فرعي أول:** يقرأ كود Laravel للوحدة ويتحقق من الـ API والـ JSON (`research` subagent).
     - **وكيل فرعي ثانٍ:** يبني طبقة البيانات (`Models`, `RemoteDataSource`, `LocalDataSource`, `RepositoryImpl`).
     - **وكيل فرعي ثالث:** يبني طبقة المنطق والحالة (`Cubit/BLoC`, `States`, `Validators`).
     - **وكيل فرعي رابع:** يبني الشاشات والودجات المتجاوبة (`Responsive Pages & Widgets`) والاختبارات (`Unit/Widget Tests`).

---

## 6.2 القائمة الرسمية للوكلاء الفرعيين الـ 7 ومهام كل واحد منهم (Subagents Catalog & System Prompts)

فيما يلي توصيف الوكلاء الفرعيين السبعة الذين يمكن لكل مطور تعريفهم واستدعاؤهم، مع المهام الدقيقة لكل وكيل وأمر الاستدعاء الجاهز (`Ready-to-Use Prompt`):

### 1. وكيل مطابقة وتطوير الـ Backend API (`backend-api-parity-subagent`)
- **النوع والصلاحيات:** `enable_write_tools: true` (يقرأ ويعدل ملفات Laravel في `app/Http/Controllers/Api/` و`routes/api.php`).
- **مهامه الأساسية:**
  1. فحص موارد Filament (`app/Filament/Resources/`) والخدمات (`app/Services/`) ومقارنتها بمسارات `routes/api.php`.
  2. بناء دوال الـ API الناقصة (CRUD، الفلاتر، البحث المركب `4/1`، الإجراءات الجماعية `Bulk Actions`، وتوليد الـ PDF).
  3. اختبار كل Endpoint عبر أوامر `php artisan test` أو سكربتات فحص سريعة والتأكد من تطابق الـ JSON.
- **قالب الأمر الجاهز للمطور (`Prompt`):**
  > *"اقرأ ملف `app/Filament/Resources/{Resource}Resource.php` وخدماته المرتبطة في `app/Services/`، ثم أنشئ/حدّث المتحكم `app/Http/Controllers/Api/{Resource}ApiController.php` وسجل المسارات في `routes/api.php` تحت `auth:sanctum` لتغطية جميع الفلاتر، البحث، الإنشاء، التعديل، الحذف الفردي والجماعي، والإجراءات المخصصة، مع التحقق من الصلاحيات وإرجاع JSON موحد."*

### 2. وكيل توليد طبقة البيانات والمزامنة المحلية (`data-layer-subagent`)
- **النوع والصلاحيات:** `enable_write_tools: true` (يعمل داخل `lib/features/{feature}/data/` و`lib/features/{feature}/domain/`).
- **مهامه الأساسية:**
  1. إنشاء كيانات النطاق (`Entities`) ونماذج البيانات (`Freezed / JsonSerializable Models`) المطابقة تماماً لحقول Laravel.
  2. بناء `RemoteDataSource` باستخدام `Dio` لجميع مسارات الوحدة (GET, POST, PUT, DELETE, Multipart, File Download).
  3. بناء `LocalDataSource` باستخدام `Drift (SQLite)` لحفظ البيانات محلياً ودعم العمل بدون إنترنت (`Offline-First`).
  4. بناء `RepositoryImpl` الذي ينسق بين الاتصال الشبكي والتخزين المحلي ويعالج الأخطاء (`Either<Failure, T>`).
- **قالب الأمر الجاهز للمطور (`Prompt`):**
  > *"اعتماداً على مواصفات الـ API في الباب الثاني من `FLUTTER_MOBILE_MASTER_PLAN.md` لوحدة `{feature}`، أنشئ بالكامل مجلدي `domain/` و`data/` داخل `lib/features/{feature}/` شاملة النماذج (Models مع fromJson/toJson)، وRemoteDataSource عبر Dio، وLocalDataSource للتخزين المؤقت، وRepositoryImpl مع معالجة أخطاء Laravel 422 بالعربية."*

### 3. وكيل إدارة الحالة والمنطق المحاسبي (`state-logic-subagent`)
- **النوع والصلاحيات:** `enable_write_tools: true` (يعمل داخل `lib/features/{feature}/presentation/cubit/` و`lib/core/utils/`).
- **مهامه الأساسية:**
  1. بناء الـ `Cubit` أو `Bloc` وملف الحالات `State` (شاملاً حالات التحميل، النجاح، التصفح `Pagination`، الفلترة، التحديد المتعدد `Multi-Select`، والحساب الفوري).
  2. برمجة المعادلات الحية داخل الـ Cubit:
     - حساب الاستهلاك الفوري (`current_reading - previous_reading`) والتحقق من أن القراءة الحالية $\ge$ القراءة السابقة.
     - حساب صافي الفاتورة والمتبقي التراكمي (`true_remaining_amount`) وتوزيع الدفعات.
     - منطق إظهار وإلزامية حقل `reference_number` عند اختيار تحويل بنكي أو شيك، ومنطق إظهار حقلي المستأجر عند تعدد العدادات.
- **قالب الأمر الجاهز للمطور (`Prompt`):**
  > *"ابنِ `{Feature}Cubit` و`{Feature}State` داخل `lib/features/{feature}/presentation/cubit/` مع دعم البحث الفوري (Debounce 300ms)، الفلاتر المتعددة، التحديد الجماعي (Bulk Selection)، والحسابات التفاعلية الفورية للنموذج وفق القواعد المحددة في الباب الأول والرابع من الخطة."*

### 4. وكيل بناء الواجهات العصرية المتجاوبة (`responsive-ui-subagent`)
- **النوع والصلاحيات:** `enable_write_tools: true` (يعمل داخل `lib/features/{feature}/presentation/pages/` و`widgets/`).
- **مهامه الأساسية:**
  1. بناء الشاشات والنوافذ السفلية (`BottomSheets`) والبطاقات وفق نظام التصميم `AquaGlass` (ألوان `#02687E`, `#F59E0B`, `#059669`, خط `Tajawal`، ودعم الوضع الليلي والنهاري).
  2. تطبيق التجاوب الكامل (`ResponsiveBuilder`):
     - عرض البطاقات الذكية في الهواتف (`< 600dp`).
     - عرض الشبكة أو الجدول ونمط `Master-Detail Split View` في التابلت والوضع الأفقي (`>= 840dp`).
  3. فرض `Directionality(textDirection: TextDirection.ltr)` على كافة حقول الأرقام، المبالغ، الأكواد، وأرقام الهواتف.
  4. إضافة هياكل التحميل (`ShimmerSkeleton`) وحالات الفراغ (`EmptyStateWidget`) وربط الأزرار بودجة الصلاحيات (`PermissionGuard`).
- **قالب الأمر الجاهز للمطور (`Prompt`):**
  > *"ابنِ جميع شاشات وودجات وحدة `{feature}` داخل `lib/features/{feature}/presentation/pages/` و`widgets/` بحيث تدعم الهواتف الصغيرة والكبيرة والتابلت (Master-Detail)، وتلتزم بهوية AquaGlass والوضع الليلي، وتفرض اتجاه LTR للأرقام والهواتف، مع ربطها بـ `{Feature}Cubit`."*

### 5. وكيل الطباعة الحرارية والـ PDF والواتساب والتصدير (`print-whatsapp-export-subagent`)
- **النوع والصلاحيات:** `enable_write_tools: true` (يعمل داخل `lib/core/services/` وودجات الطباعة/المشاركة).
- **مهامه الأساسية:**
  1. بناء خدمة معاينة وطباعة ومشاركة ملفات الـ PDF الرسمية (`A5 Landscape`).
  2. بناء قوالب الطباعة الحرارية عبر البلوتوث (`58mm / 80mm ESC/POS`) للفواتير وسندات القبض وكشف الحساب.
  3. بناء مولد رسائل الواتساب المتوافق 100% مع `WhatsAppService.php` (بما في ذلك معالجة البلوكات الشرطية `{if:subscription_fee}...{/if:subscription_fee}` وتنسيق أرقام اليمن `967`).
  4. بناء خدمة تحميل ومشاركة ملفات الإكسل (`.xlsx`) للمشتركين والفواتير والقراءات.

### 6. وكيل البحث والتدقيق المرجعي (`laravel-reference-research-subagent`)
- **النوع والصلاحيات:** وكيل القراءة فقط (`research` subagent المدمج).
- **مهامه الأساسية:**
  - كلما احتاج المطور التأكد من تفصيلة دقيقة في كود Laravel (مثل: كيف يحسب `Invoice.php` حالة الفاتورة؟ أو ما هي المتغيرات المتاحة في قالب واتساب؟)، يطلق وكيل `research` لفحص ملفات PHP وإرجاع الإجابة الدقيقة دون تلويث سياق المحادثة الرئيسية.

### 7. وكيل الفحص والاختبار وضمان الجودة (`qa-test-inspector-subagent`)
- **النوع والصلاحيات:** `enable_write_tools: true` (يعمل في مجلد `test/` ويشغل `flutter analyze` و`flutter test`).
- **مهامه الأساسية:**
  1. تشغيل `flutter analyze` وإصلاح أي تحذيرات أو أخطاء برمجية.
  2. كتابة اختبارات الوحدة (`Unit Tests`) للمعادلات المحاسبية وحاسبة الفترات ومحلل البحث المركب.
  3. كتابة اختبارات الواجهة (`Widget Tests`) للتأكد من عدم حدوث `RenderFlex Overflow` على الشاشات الصغيرة (`320x568`) أو التابلت (`1024x768`).

---

## 6.3 جدول توزيع الوكلاء الفرعيين على كل مطور بالاسم

يوضح الجدول التالي كيف يستخدم كل مطور من المهندسين الخمسة الوكلاء الفرعيين لإنجاز مهامه الأساسية والجانبية بأقصى سرعة:

| المطور | الوكلاء الفرعيون الذين يشغلهم بالتوازي | كيف يوزع المهام على وكلائه الفرعيين؟ |
|---|---|---|
| **1. م. محمد (Dev 1)**<br>*(Architect, Core & API)* | 1. `backend-api-parity-subagent` (×2 بالتوازي)<br>2. `responsive-ui-subagent`<br>3. `qa-test-inspector-subagent` | • يطلق وكيلين فرعيين من نوع `backend-api-parity-subagent` في نفس الوقت: الأول ينجز مسارات API الفواتير والمدفوعات والقراءات، والثاني ينجز مسارات API المشتركين والإعدادات والصلاحيات والتصدير.<br>• بالتوازي، يبني الوكيل الرئيسي `lib/core/` (Dio, Theme, AdaptiveScaffold) ويفوض شاشات `Auth & Profile` لوكيل `responsive-ui-subagent`. |
| **2. م. أنور (Dev 2)**<br>*(Readings, Meters & Offline)* | 1. `data-layer-subagent`<br>2. `state-logic-subagent`<br>3. `responsive-ui-subagent`<br>4. `qa-test-inspector-subagent` | • يفوض `data-layer-subagent` لبناء نماذج `Reading` و`Meter` وجداول `Drift` المحلية وطابور المزامنة `SyncQueue`.<br>• يفوض `state-logic-subagent` لبناء `DatePeriodCalculator` و`CompoundSearchParser` (`4/1`) و`ReadingsCubit` و`MetersCubit`.<br>• يفوض `responsive-ui-subagent` لبناء شاشة القارئ الميداني، نافذة تسجيل القراءة مع تنبيه الفوترة التلقائية، وشاشة إدارة القراءات والعدادات. |
| **3. م. أكرم (Dev 3)**<br>*(Invoices, Payments, Print & WhatsApp)* | 1. `data-layer-subagent`<br>2. `state-logic-subagent`<br>3. `print-whatsapp-export-subagent`<br>4. `responsive-ui-subagent` | • يفوض `data-layer-subagent` لبناء طبقات بيانات `Invoices`, `Payments`, و`Tariffs`.<br>• يفوض `state-logic-subagent` لبناء منطق النوافذ الثلاث لإصدار الفواتير ومنطق التحصيل وتوزيع الدفعات.<br>• يفوض `print-whatsapp-export-subagent` لبناء `PdfPrintService` و`ThermalPrinterService` و`ArabicNumberSpellout` و`WhatsAppLauncherService`.<br>• يفوض `responsive-ui-subagent` لبناء شاشات الفواتير، المحصل السريع، سندات القبض، والتعرفة. |
| **4. م. فؤاد (Dev 4)**<br>*(Subscribers 360°, Squares, Fees, Tankers & Ledger)* | 1. `data-layer-subagent` (×2)<br>2. `state-logic-subagent`<br>3. `responsive-ui-subagent` | • يطلق وكيلي `data-layer-subagent` بالتوازي: الأول لبيانات `Subscribers` و`Squares`، والثاني لبيانات `SubscriptionFees`, `ServiceRequests`, و`Transactions`.<br>• يفوض `state-logic-subagent` لبناء الـ Cubits الخمسة وحسابات الأرصدة المعزولة.<br>• يفوض `responsive-ui-subagent` لبناء ملف المشترك ذي التبويبات الـ 6 (`Master-Detail` للتابلت) وشاشات المربعات والاشتراكات والوايت والدفتر. |
| **5. م. أسامة (Dev 5)**<br>*(Dashboard, Settings Studio, RBAC & Exports)* | 1. `data-layer-subagent`<br>2. `responsive-ui-subagent` (×2)<br>3. `print-whatsapp-export-subagent` | • يفوض `data-layer-subagent` لبناء طبقات بيانات لوحة القيادة، مراكز الإعدادات الـ 5، المستخدمين والأدوار، والتصدير.<br>• يطلق وكيلي `responsive-ui-subagent` بالتوازي: الأول يبني لوحة القيادة (`fl_chart` + الودجات الأربع) وشاشات المستخدمين ومصفوفة صلاحيات Shield، والثاني يبني استوديو إعدادات الفاتورة (7 تبويبات) والسند (5 تبويبات) ولوحة التحكم الحية بخادم الواتساب (`QR Code` + السجلات الحية).<br>• يفوض `print-whatsapp-export-subagent` لبناء شاشة وخدمة تصدير ملفات الـ Excel الثلاثة. |

---

# الباب السابع: الكود المرجعي للوحدات الحرجة، خارطة الطريق الزمنية، ومعايير الفحص والقبول (Reference Blueprints, Roadmap & QA Checklist)

لتسهيل انطلاق المطورين فوراً بدون أي غموض، نضع بين أيديهم الكود المرجعي الجاهز لأهم الخوارزميات والوحدات المشتركة في المشروع.

## 7.1 الكود المرجعي لخوارزميات Flutter الحرجة (Ready-to-Use Core Algorithms)

### 1. محلل البحث المركب بالمربع والمسار (`lib/core/utils/compound_search_parser.dart`)
يطابق تماماً التعبير النمطي `preg_match('/^(\d+)[\/\-\\\\_](\d+)$/', $query)` الموجود في [`ReadingController.php`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/ReadingController.php#L45) و [`InvoiceController.php`](file:///c:/Users/msi/Desktop/water-main/app/Http/Controllers/Api/InvoiceController.php#L45):

```dart
class CompoundSearchQuery {
  final String rawQuery;
  final int? squareId;
  final int? pathNumber;
  final bool isCompoundPathSearch;

  const CompoundSearchQuery({
    required this.rawQuery,
    this.squareId,
    this.pathNumber,
    this.isCompoundPathSearch = false,
  });

  factory CompoundSearchQuery.parse(String input) {
    final normalized = _normalizeArabicDigits(input.trim());
    final regex = RegExp(r'^(\d+)[/\-\\_](\d+)$');
    final match = regex.firstMatch(normalized);

    if (match != null) {
      return CompoundSearchQuery(
        rawQuery: normalized,
        squareId: int.tryParse(match.group(1)!),
        pathNumber: int.tryParse(match.group(2)!),
        isCompoundPathSearch: true,
      );
    }

    return CompoundSearchQuery(rawQuery: normalized);
  }

  static String _normalizeArabicDigits(String input) {
    const arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    const english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    var result = input;
    for (var i = 0; i < arabic.length; i++) {
      result = result.replaceAll(arabic[i], english[i]);
    }
    return result;
  }
}
```

### 2. حاسبة فترات القراءة المحلية للعمل أوفلاين (`lib/core/utils/date_period_calculator.dart`)
تطابق 100% منطق [`ReadingService.php`](file:///c:/Users/msi/Desktop/water-main/app/Services/ReadingService.php#L12-L72):

```dart
class ReadingPeriodResult {
  final DateTime periodStart;
  final DateTime periodEnd;

  const ReadingPeriodResult({required this.periodStart, required this.periodEnd});
}

class DatePeriodCalculator {
  static ReadingPeriodResult calculate({
    DateTime? lastReadingPeriodEnd,
    DateTime? lastReadingDate,
    DateTime? subscriptionStartDate,
    String readingPeriod = 'monthly', // monthly, half_monthly, ten_days, weekly
    String readingDay = 'last_day',   // last_day, or 20..28
  }) {
    final now = DateTime.now();
    late DateTime periodStart;

    if (lastReadingPeriodEnd != null) {
      periodStart = DateTime(
        lastReadingPeriodEnd.year,
        lastReadingPeriodEnd.month,
        lastReadingPeriodEnd.day,
      ).add(const Duration(days: 1));
    } else if (lastReadingDate != null) {
      final endOfLastMonth = DateTime(lastReadingDate.year, lastReadingDate.month + 1, 0);
      periodStart = endOfLastMonth.add(const Duration(days: 1));
    } else if (subscriptionStartDate != null) {
      periodStart = DateTime(
        subscriptionStartDate.year,
        subscriptionStartDate.month,
        subscriptionStartDate.day,
      );
    } else {
      periodStart = DateTime(now.year, now.month, 1);
    }

    final periodEnd = _calculateNextPeriodEnd(periodStart, readingPeriod, readingDay);
    return ReadingPeriodResult(periodStart: periodStart, periodEnd: periodEnd);
  }

  static DateTime _calculateNextPeriodEnd(DateTime start, String period, String readingDay) {
    final day = start.day;
    final endOfCurrentMonth = DateTime(start.year, start.month + 1, 0);

    if (period == 'monthly') {
      if (readingDay == 'last_day') {
        return endOfCurrentMonth;
      } else {
        final targetDay = int.tryParse(readingDay) ?? 28;
        if (day <= targetDay) {
          return DateTime(start.year, start.month, targetDay);
        } else {
          return DateTime(start.year, start.month + 1, targetDay);
        }
      }
    } else if (period == 'half_monthly') {
      if (day <= 15) {
        return DateTime(start.year, start.month, 15);
      } else {
        return endOfCurrentMonth;
      }
    } else if (period == 'ten_days') {
      if (day <= 10) return DateTime(start.year, start.month, 10);
      if (day <= 20) return DateTime(start.year, start.month, 20);
      return endOfCurrentMonth;
    } else if (period == 'weekly') {
      return start.add(const Duration(days: 6));
    }

    return endOfCurrentMonth;
  }
}
```

### 3. معالج قوالب رسائل الواتساب الشرطية وتنسيق الأرقام (`lib/core/services/whatsapp_launcher_service.dart`)
يطابق 100% منطق [`WhatsAppService.php`](file:///c:/Users/msi/Desktop/water-main/app/Services/WhatsAppService.php#L19-L222):

```dart
class WhatsAppTemplateProcessor {
  static String? formatYemeniPhone(String? phone) {
    if (phone == null || phone.trim().isEmpty) return null;
    var digits = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.startsWith('00')) {
      digits = digits.substring(2);
    } else if (digits.startsWith('0')) {
      digits = digits.substring(1);
    }
    if (digits.length == 9 && (digits.startsWith('7') || digits.startsWith('1'))) {
      digits = '967$digits';
    }
    return digits.isEmpty ? null : digits;
  }

  static String renderTemplate({
    required String template,
    required Map<String, String> variables,
    required Map<String, double> conditionalValues,
  }) {
    var result = template;

    // 1. Replace standard variables {subscriber_name}, {bill_number}, etc.
    variables.forEach((key, value) {
      result = result.replaceAll('{$key}', value);
    });

    // 2. Process conditional blocks {if:subscription_fee}...{/if:subscription_fee}
    conditionalValues.forEach((key, val) {
      final pattern = RegExp(r'\{if:' + key + r'\}(.*?)\{/if:' + key + r'\}', dotAll: true);
      if (val > 0) {
        result = result.replaceAllMapped(pattern, (match) => match.group(1) ?? '');
      } else {
        result = result.replaceAll(pattern, '');
      }
    });

    return result.trim();
  }
}
```

---

## 7.2 خارطة الطريق الزمنية للتنفيذ (4-Phase Sprint Roadmap)

| المرحلة (Sprint) | المدة المقترحة | المخرجات الرئيسية (Deliverables) | المسؤولون بالاسم |
|---|---|---|---|
| **المرحلة 1: التأسيس واستكمال الـ API**<br>*(Foundation & API Parity)* | **الأسبوع الأول**<br>(أيام 1 – 5) | • استكمال وتوثيق كافة الـ 68 Endpoint في Laravel (`routes/api.php`).<br>• تأسيس مشروع Flutter، إعداد `lib/core/`، `DioClient`، `AppTheme`، `AdaptiveScaffold`، والمكونات المشتركة.<br>• إنجاز وحدة المصادقة، الجلسة، الصلاحيات، والملف الشخصي (`Auth & Profile`). | **م. محمد (Dev 1)** (القيادة)<br>+ تهيئة طبقات البيانات من **أنور، أكرم، فؤاد، أسامة** |
| **المرحلة 2: العمليات الميدانية والمحاسبية الأساسية**<br>*(Core Field & Financial Modules)* | **الأسبوع الثاني**<br>(أيام 6 – 12) | • إنجاز شاشة القارئ الميداني السريع، تسجيل القراءات، وسجل القراءات الشامل مع الإصدار الجماعي.<br>• إنجاز شاشة المحصل السريع، قائمة الفواتير، محركات إصدار الفواتير الثلاثة، وسندات القبض.<br>• إنجاز قائمة المشتركين والملف المالي 360° بتبويباته الـ 6 والمربعات السكنية.<br>• إنجاز شاشة لوحة القيادة والرسوم البيانية. | **م. أنور (Dev 2)** (القراءات/العدادات)<br>**م. أكرم (Dev 3)** (الفواتير/المدفوعات)<br>**م. فؤاد (Dev 4)** (المشتركون/المربعات)<br>**م. أسامة (Dev 5)** (لوحة القيادة) |
| **المرحلة 3: الوحدات المتقدمة، الأوفلاين، الطباعة، والإعدادات**<br>*(Offline, Print, Settings Studio & RBAC)* | **الأسبوع الثالث**<br>(أيام 13 – 18) | • تفعيل قاعدة بيانات `Drift` وطابور المزامنة الأوفلاين (`SyncQueue`).<br>• تفعيل طباعة ومشاركة الـ PDF (`A5 Landscape`) والطباعة الحرارية عبر البلوتوث وإرسال الواتساب.<br>• إنجاز وحدات رسوم الاشتراك، طلبات الوايت، التعرفة، ودفتر الأستاذ.<br>• إنجاز مراكز الإعدادات الخمسة (بما فيها التحكم الحي بخادم الواتساب المحلي `QR`)، المستخدمين والأدوار، وتصدير الإكسل. | جميع المهندسين (**محمد، أنور، أكرم، فؤاد، أسامة**) بالتوازي مع الوكلاء الفرعيين |
| **المرحلة 4: الاختبار الشامل، تحسين الأداء، والإطلاق**<br>*(QA, Polish & Production Release)* | **الأسبوع الرابع**<br>(أيام 19 – 22) | • اختبار كافة الشاشات على الهواتف الصغيرة، الكبيرة، والتابلت، والوضعين الليلي والنهاري.<br>• التحقق من تطابق 100% من الأرقام والمعادلات بين الموبايل ولوحة تحكم Laravel.<br>• بناء نسخ الإنتاج النهائية (`Release APK / AAB / IPA`). | جميع المهندسين (**محمد، أنور، أكرم، فؤاد، أسامة**) + `qa-test-inspector-subagent` |

---

## 7.3 قائمة الفحص والقبول النهائية (Master QA & Definition of Done Checklist)

لا تعتبر أي وحدة مكتملة إلا بعد اجتياز البنود الـ 15 التالية بنجاح:

- [ ] **1. التطابق الوظيفي الكامل (100% Feature Parity):** جميع الموارد الـ 13، والصفحات الـ 6، والودجات الـ 5، والتقارير في Laravel موجودة وتعمل في تطبيق الموبايل.
- [ ] **2. دقة الحسابات المالية:** تطابق أرصدة المشتركين (العامة والمعزولة لكل عداد) و`true_remaining_amount` وتوزيع دفعات FIFO بنسبة 100% مع قاعدة البيانات.
- [ ] **3. البحث المركب السريع:** كتابة `4/1` أو `4-1` في شاشة القراءات أو الفواتير تجلب فوراً عداد/فاتورة المسار 1 في المربع 4.
- [ ] **4. التحقق من القراءات والفترات:** منع إدخال قراءة أقل من السابقة، ومنع تكرار القراءة لنفس الفترة، ومنع تعديل أو حذف قراءة مفوترة.
- [ ] **5. الفوترة التلقائية والمشروطة:** ظهور تنبيه الفوترة التلقائية عند تفعيلها، مع خيار تضمين قسط الاشتراك ورسوم الوايت، وإصدار الفاتورة فوراً بعد القراءة.
- [ ] **6. فريدية المسارات:** منع تكرار `path_number` لعدادين داخل نفس المربع السكني.
- [ ] **7. دعم المستأجرين:** ظهور اسم المستأجر في الفواتير والسندات والبحث، وإرسال إشعار الواتساب إلى `tenant_phone` عند توفره.
- [ ] **8. محرك الواتساب الثلاثي:** عمل الإرسال عبر الرابط المباشر (`click_to_chat`)، وعبر `meta_api`، وعبر `local_api` (مع إمكانية تشغيل/إيقاف الخادم المحلي ومسح كود `QR` من داخل الموبايل).
- [ ] **9. الطباعة المزدوجة والتفقيط:** تحميل وطباعة ومشاركة ملفات الـ PDF الرسمية (`A5 Landscape`) للفاتورة والسند، والطباعة عبر طابعات البلوتوث الحرارية، مع ظهور المبلغ كتابةً بالتفقيط العربي.
- [ ] **10. تصدير الإكسل:** تحميل ومشاركة ملفات `.xlsx` للمشتركين والفواتير والقراءات مع الفلاتر المحددة.
- [ ] **11. الصلاحيات والأدوار:** احترام صلاحيات `Spatie/FilamentShield` لكل من المدير، المحصل، وقارئ العدادات.
- [ ] **12. اتجاه النصوص والأرقام:** واجهة عربية `RTL` بالكامل مع فرض `LTR` لحقول الأرقام والهواتف والإيميل وتحويل الأرقام العربية إلى إنجليزية تلقائياً.
- [ ] **13. التجاوب مع كافة الشاشات:** صفر أخطاء `RenderFlex Overflow` على الشاشات الصغيرة (`320dp`)، وتفعيل `AdaptiveScaffold` و`Master-Detail` على شاشات التابلت والوضع الأفقي.
- [ ] **14. السرعة والسلاسة والعمل أوفلاين:** أداء `60/120fps`، تحميل `Shimmer`، وعمل القراءات والتحصيل في الميدان عند انقطاع الإنترنت مع المزامنة التلقائية.
- [ ] **15. نظافة الكود والاختبارات:** اجتياز `flutter analyze` بدون أخطاء، واجتياز كافة اختبارات الوحدة والواجهة.

---

# الفصل الثامن: الملاحق الهندسية التنفيذية والأكواد المرجعية الجاهزة (Executive Engineering Appendices)

يقدم هذا الفصل الأكواد المرجعية الكاملة الجاهزة للنسخ والاستخدام المباشر من قِبل فريق التطوير لضمان سرعة الإنجاز وتوحيد المعايير بين مطوري الـ Backend والـ Flutter.

---

## 8.1 المخطط الكامل لملف مسارات الـ API الموحد (`routes/api.php` — 68 Endpoints Blueprint)

يقوم **المطور الأول (Dev 1)** مع باقي المطورين بتحديث ملف `routes/api.php` ليطابق هذا الهيكل الشامل الذي يغطي 100% من عمليات النظام:

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\SquareController;
use App\Http\Controllers\Api\SubscriberController;
use App\Http\Controllers\Api\MeterController;
use App\Http\Controllers\Api\ReadingController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SubscriptionFeeController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\WhatsAppServerController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ExportController;

/*
|--------------------------------------------------------------------------
| Public Routes (بدون مصادقة)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Protected Routes (محمية بـ Sanctum Token)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // 1. Auth & Profile (4 Endpoints)
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // 2. Dashboard, Widgets & Reports (4 Endpoints)
    Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/admin/widgets/monthly-revenue', [AdminController::class, 'monthlyRevenue']);
    Route::get('/admin/widgets/latest-invoices', [AdminController::class, 'latestInvoices']);
    Route::get('/admin/widgets/overdue-invoices', [AdminController::class, 'overdueInvoices']);
    Route::get('/admin/reports', [AdminController::class, 'reports']);

    // 3. Squares Management (5 Endpoints)
    Route::apiResource('squares', SquareController::class);

    // 4. Subscribers & 360 Financial Profile (7 Endpoints)
    Route::get('/subscribers/{id}/summary', [SubscriberController::class, 'summary']);
    Route::get('/subscribers/{id}/statement', [SubscriberController::class, 'statement']);
    Route::apiResource('subscribers', SubscriberController::class);

    // 5. Meters Management (6 Endpoints)
    Route::post('/meters/validate-path', [MeterController::class, 'validatePath']);
    Route::apiResource('meters', MeterController::class);

    // 6. Readings & Field Reader Operations (9 Endpoints)
    Route::get('/readings/meters', [ReadingController::class, 'searchMeters']);
    Route::get('/readings/calculate-dates', [ReadingController::class, 'calculateDates']);
    Route::get('/readings/history/{meter_id}', [ReadingController::class, 'meterHistory']);
    Route::post('/readings/bulk-invoice', [ReadingController::class, 'bulkInvoice']);
    Route::post('/readings/sync-batch', [ReadingController::class, 'syncBatch']);
    Route::get('/readings', [ReadingController::class, 'index']);
    Route::post('/readings', [ReadingController::class, 'store']);
    Route::put('/readings/{id}', [ReadingController::class, 'update']);
    Route::delete('/readings/{id}', [ReadingController::class, 'destroy']);

    // 7. Invoices & 3 Billing Engines + PDF + WhatsApp (13 Endpoints)
    Route::get('/invoices/unpaid', [InvoiceController::class, 'unpaid']);
    Route::post('/invoices/pay', [InvoiceController::class, 'pay']);
    Route::post('/invoices/generate-meter', [InvoiceController::class, 'generateMeterInvoice']);
    Route::post('/invoices/generate-subscription', [InvoiceController::class, 'generateSubscriptionInvoice']);
    Route::post('/invoices/generate-tanker', [InvoiceController::class, 'generateTankerInvoice']);
    Route::post('/invoices/bulk-recalculate', [InvoiceController::class, 'bulkRecalculate']);
    Route::post('/invoices/{id}/recalculate', [InvoiceController::class, 'recalculate']);
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf']);
    Route::post('/invoices/{id}/whatsapp', [InvoiceController::class, 'sendWhatsApp']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);

    // 8. Payments & Receipts + PDF + WhatsApp (7 Endpoints)
    Route::get('/payments/{id}/pdf', [PaymentController::class, 'downloadPdf']);
    Route::post('/payments/{id}/whatsapp', [PaymentController::class, 'sendWhatsApp']);
    Route::apiResource('payments', PaymentController::class);

    // 9. Subscription Fees & Installments (5 Endpoints)
    Route::apiResource('subscription-fees', SubscriptionFeeController::class)->except(['show']);

    // 10. Tanker / Service Requests (5 Endpoints)
    Route::apiResource('service-requests', ServiceRequestController::class)->except(['show']);

    // 11. Tariffs / Pricing Slabs (5 Endpoints)
    Route::apiResource('tariffs', TariffController::class)->except(['show']);

    // 12. General Ledger / Transactions (2 Endpoints)
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);

    // 13. Settings Studio & Local WhatsApp Server Control (9 Endpoints)
    Route::get('/settings', [SettingController::class, 'index']);
    Route::post('/settings', [SettingController::class, 'update']);
    Route::post('/settings/upload-logo', [SettingController::class, 'uploadLogo']);
    Route::get('/whatsapp-server/status', [WhatsAppServerController::class, 'status']);
    Route::get('/whatsapp-server/qr', [WhatsAppServerController::class, 'qr']);
    Route::post('/whatsapp-server/start', [WhatsAppServerController::class, 'start']);
    Route::post('/whatsapp-server/stop', [WhatsAppServerController::class, 'stop']);
    Route::post('/whatsapp-server/logout', [WhatsAppServerController::class, 'logout']);
    Route::post('/whatsapp-server/test', [WhatsAppServerController::class, 'sendTest']);

    // 14. Users & Roles / FilamentShield RBAC (9 Endpoints)
    Route::get('/roles/permissions-matrix', [RoleController::class, 'permissionsMatrix']);
    Route::apiResource('users', UserController::class)->except(['show']);
    Route::apiResource('roles', RoleController::class)->except(['show']);

    // 15. Excel (.xlsx) Data Exports (3 Endpoints)
    Route::get('/export/subscribers', [ExportController::class, 'subscribers']);
    Route::get('/export/invoices', [ExportController::class, 'invoices']);
    Route::get('/export/readings', [ExportController::class, 'readings']);
});
```

---

## 8.2 الكود المرجعي لمحرك التفقيط العربي للمبالغ (`ArabicNumberSpellout`)

يطابق هذا الكود في `lib/core/utils/arabic_number_spellout.dart` منطق التفقيط العربي الموجود في `resources/views/pdf/receipt.blade.php` (بما في ذلك تصحيحات الإملاء العربي الرسمي وإضافة `"ريال يمني فقط لا غير"`):

```dart
/// يحول الأرقام المالية إلى نص عربي مفقط مطابق تماماً لسندات القبض في Laravel
class ArabicNumberSpellout {
  static const List<String> _ones = [
    '',
    'واحد',
    'اثنان',
    'ثلاثة',
    'أربعة',
    'خمسة',
    'ستة',
    'سبعة',
    'ثمانية',
    'تسعة',
    'عشرة',
    'أحد عشر',
    'اثنا عشر',
    'ثلاثة عشر',
    'أربعة عشر',
    'خمسة عشر',
    'ستة عشر',
    'سبعة عشر',
    'ثمانية عشر',
    'تسعة عشر',
  ];

  static const List<String> _tens = [
    '',
    'عشرة',
    'عشرون',
    'ثلاثون',
    'أربعون',
    'خمسون',
    'ستون',
    'سبعون',
    'ثمانون',
    'تسعون',
  ];

  static const List<String> _hundreds = [
    '',
    'مائة',
    'مئتان',
    'ثلاثمئة',
    'أربعمئة',
    'خمسمئة',
    'ستمئة',
    'سبعمئة',
    'ثمانمئة',
    'تسعمئة',
  ];

  /// الدالة الرئيسية لتحويل المبلغ إلى نص مفقط مع العملة
  static String spelloutYemeniRial(num amount) {
    final intValue = amount.round();
    if (intValue == 0) return 'صفر ريال يمني فقط لا غير';
    if (intValue < 0) {
      return 'سالب ${_convertBelowBillion(intValue.abs())} ريال يمني فقط لا غير';
    }
    return '${_convertBelowBillion(intValue)} ريال يمني فقط لا غير';
  }

  static String _convertBelowThousand(int n) {
    if (n == 0) return '';
    final List<String> parts = [];

    final int h = n ~/ 100;
    final int rem = n % 100;

    if (h > 0) {
      parts.add(_hundreds[h]);
    }

    if (rem > 0) {
      if (rem < 20) {
        parts.add(_ones[rem]);
      } else {
        final int u = rem % 10;
        final int t = rem ~/ 10;
        if (u > 0) {
          parts.add('${_ones[u]} و${_tens[t]}');
        } else {
          parts.add(_tens[t]);
        }
      }
    }

    return parts.join(' و');
  }

  static String _convertBelowBillion(int n) {
    if (n == 0) return 'صفر';
    final List<String> groups = [];

    final int millions = n ~/ 1000000;
    final int thousands = (n % 1000000) ~/ 1000;
    final int remainder = n % 1000;

    if (millions > 0) {
      if (millions == 1) {
        groups.add('مليون');
      } else if (millions == 2) {
        groups.add('مليونان');
      } else if (millions >= 3 && millions <= 10) {
        groups.add('${_convertBelowThousand(millions)} ملايين');
      } else {
        groups.add('${_convertBelowThousand(millions)} مليون');
      }
    }

    if (thousands > 0) {
      if (thousands == 1) {
        groups.add('ألف');
      } else if (thousands == 2) {
        groups.add('ألفان');
      } else if (thousands >= 3 && thousands <= 10) {
        groups.add('${_convertBelowThousand(thousands)} آلاف');
      } else {
        groups.add('${_convertBelowThousand(thousands)} ألف');
      }
    }

    if (remainder > 0) {
      groups.add(_convertBelowThousand(remainder));
    }

    return groups.join(' و');
  }
}
```

---

## 8.3 الكود المرجعي للهيكل المتجاوب الشامل (`AdaptiveScaffold` & `MasterDetailLayout`)

يوضع هذا الكود في `lib/shared/widgets/adaptive_scaffold.dart` ويضمن عمل التطبيق بسلاسة فائقة على أصغر الهواتف (`320dp`) وحتى شاشات التابلت الكبيرة (`1200dp+`):

```dart
import 'package:flutter/material.dart';

class AdaptiveDestination {
  final String label;
  final IconData icon;
  final IconData selectedIcon;

  const AdaptiveDestination({
    required this.label,
    required this.icon,
    required this.selectedIcon,
  });
}

class AdaptiveScaffold extends StatelessWidget {
  final int currentIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<AdaptiveDestination> destinations;
  final Widget body;
  final Widget? secondaryBody; // يظهر في وضع Master-Detail على التابلت
  final Widget? floatingActionButton;
  final PreferredSizeWidget? appBar;

  const AdaptiveScaffold({
    super.key,
    required this.currentIndex,
    required this.onDestinationSelected,
    required this.destinations,
    required this.body,
    this.secondaryBody,
    this.floatingActionButton,
    this.appBar,
  });

  static bool isCompact(BuildContext context) =>
      MediaQuery.sizeOf(context).width < 600;

  static bool isMedium(BuildContext context) {
    final w = MediaQuery.sizeOf(context).width;
    return w >= 600 && w < 840;
  }

  static bool isExpanded(BuildContext context) =>
      MediaQuery.sizeOf(context).width >= 840;

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;

    // 1. الهواتف المحمولة (< 600dp): Bottom NavigationBar
    if (width < 600) {
      return Scaffold(
        appBar: appBar,
        body: body,
        floatingActionButton: floatingActionButton,
        bottomNavigationBar: NavigationBar(
          selectedIndex: currentIndex,
          onDestinationSelected: onDestinationSelected,
          destinations: destinations
              .map(
                (d) => NavigationDestination(
                  icon: Icon(d.icon),
                  selectedIcon: Icon(d.selectedIcon),
                  label: d.label,
                ),
              )
              .toList(),
        ),
      );
    }

    // 2. الأجهزة القابلة للطي والتابلت (600dp+): NavigationRail + Master-Detail
    final bool isExtendedRail = width >= 1024;

    return Scaffold(
      appBar: appBar,
      floatingActionButton: floatingActionButton,
      body: Row(
        children: [
          NavigationRail(
            extended: isExtendedRail,
            selectedIndex: currentIndex,
            onDestinationSelected: onDestinationSelected,
            labelType: isExtendedRail
                ? NavigationRailLabelType.none
                : NavigationRailLabelType.all,
            destinations: destinations
                .map(
                  (d) => NavigationRailDestination(
                    icon: Icon(d.icon),
                    selectedIcon: Icon(d.selectedIcon),
                    label: Text(d.label),
                  ),
                )
                .toList(),
          ),
          const VerticalDivider(thickness: 1, width: 1),
          Expanded(
            flex: 4,
            child: body,
          ),
          if (secondaryBody != null && width >= 840) ...[
            const VerticalDivider(thickness: 1, width: 1),
            Expanded(
              flex: 6,
              child: secondaryBody!,
            ),
          ],
        ],
      ),
    );
  }
}
```

---

## 8.4 الكود المرجعي لحقل الأرقام والهواتف القياسي (`AppNumericField` — LTR & Digit Normalizer)

يوضع في `lib/shared/widgets/app_numeric_field.dart`، ويحل مشكلة شائعة جداً في تطبيقات المحاسبة العربية وهي إدخال الأرقام الهندية (`٠١٢٣٤٥٦٧٨٩`) أو انقلاب اتجاه الأرقام والهواتف في واجهة `RTL`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// محول فوري يحول الأرقام العربية/الفارسية (٠-٩) إلى إنجليزية (0-9) أثناء الكتابة
class ArabicToEnglishDigitsFormatter extends TextInputFormatter {
  static const Map<String, String> _digitMap = {
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
    '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  };

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    String converted = newValue.text;
    _digitMap.forEach((arabic, english) {
      converted = converted.replaceAll(arabic, english);
    });
    return newValue.copyWith(text: converted);
  }
}

/// حقل إدخال قياسي للأرقام، القراءات، المبالغ، الهواتف، والمسارات (يفرض LTR دائماً)
class AppNumericField extends StatelessWidget {
  final TextEditingController controller;
  final String label;
  final String? hint;
  final String? suffixText;
  final IconData? prefixIcon;
  final bool allowDecimal;
  final bool allowCompoundSearch; // يسمح برموز 4/1 أو 4-1
  final ValueChanged<String>? onChanged;
  final FormFieldValidator<String>? validator;
  final bool autofocus;

  const AppNumericField({
    super.key,
    required this.controller,
    required this.label,
    this.hint,
    this.suffixText,
    this.prefixIcon,
    this.allowDecimal = false,
    this.allowCompoundSearch = false,
    this.onChanged,
    this.validator,
    this.autofocus = false,
  });

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: TextFormField(
        controller: controller,
        autofocus: autofocus,
        textAlign: TextAlign.left,
        keyboardType: allowCompoundSearch
            ? TextInputType.text
            : TextInputType.numberWithOptions(decimal: allowDecimal),
        inputFormatters: [
          ArabicToEnglishDigitsFormatter(),
          if (!allowCompoundSearch && !allowDecimal)
            FilteringTextInputFormatter.digitsOnly,
          if (!allowCompoundSearch && allowDecimal)
            FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
        ],
        style: const TextStyle(
          fontWeight: FontWeight.w700,
          letterSpacing: 0.6,
          fontFeatures: [FontFeature.tabularFigures()],
        ),
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          suffixText: suffixText,
          prefixIcon: prefixIcon != null ? Icon(prefixIcon) : null,
        ),
        onChanged: onChanged,
        validator: validator,
      ),
    );
  }
}
```

---

## 8.5 مصفوفة التعاقد التنفيذي الشاملة (Master Contract Matrix: Screens × APIs × Developers × Subagents)

تعتبر هذه المصفوفة بمثابة **العقد الهندسي الموحد** الذي يربط كل شاشة في التطبيق بمسار الـ API المقابل لها، وملف الـ Laravel المرجعي، والمطور المسؤول عن تنفيذها، والوكيل الذكي (`Subagent`) الذي يساعده:

| كود الشاشة | اسم الشاشة / الوحدة في Flutter | مسارات الـ API المرتبطة | المرجع في Laravel | المطور المسؤول بالاسم | الوكيل الذكي المساند (`Subagent`) |
|---|---|---|---|---|---|
| **SCR-01** | تسجيل الدخول الذكي (`LoginScreen`) | `POST /api/login`<br>`GET /api/me` | `AuthController.php` | **م. محمد (Dev 1)** | `responsive-ui-subagent` |
| **SCR-02** | الملف الشخصي (`EditProfileScreen`) | `PUT /api/profile`<br>`POST /api/logout` | `EditProfilePage.php` | **م. محمد (Dev 1)** | `responsive-ui-subagent` |
| **SCR-03** | لوحة القيادة التفاعلية (`DashboardScreen`) | `GET /api/admin/dashboard`<br>`GET /api/admin/widgets/*` | `StatsOverview.php`<br>`MonthlyRevenueChart.php`<br>`LatestInvoicesWidget.php`<br>`OverdueInvoicesWidget.php` | **م. أسامة (Dev 5)** | `responsive-ui-subagent`<br>`data-layer-subagent` |
| **SCR-04** | إدارة المربعات السكنية (`SquaresScreen`) | `GET/POST/PUT/DELETE /api/squares` | `SquareResource.php`<br>`Square.php` | **م. فؤاد (Dev 4)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-05** | قائمة المشتركين (`SubscribersListScreen`) | `GET /api/subscribers`<br>`DELETE /api/subscribers/{id}` | `SubscriberResource.php`<br>`SubscribersTable.php` | **م. فؤاد (Dev 4)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-06** | نموذج إضافة/تعديل مشترك (`SubscriberFormScreen`) | `POST /api/subscribers`<br>`PUT /api/subscribers/{id}` | `SubscriberForm.php`<br>`CreateSubscriber.php` | **م. فؤاد (Dev 4)** | `responsive-ui-subagent` |
| **SCR-07** | الملف المالي 360° للمشترك (`Subscriber360Screen`) | `GET /api/subscribers/{id}/summary`<br>`GET /api/subscribers/{id}/statement` | `EditSubscriber.php`<br>`SubscriberTransactionsTable.php`<br>`RelationManagers/*` | **م. فؤاد (Dev 4)** | `state-logic-subagent`<br>`responsive-ui-subagent` |
| **SCR-08** | قائمة العدادات (`MetersListScreen`) | `GET /api/meters`<br>`DELETE /api/meters/{id}` | `MeterResource.php`<br>`MetersTable.php` | **م. أنور (Dev 2)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-09** | نموذج إضافة/تعديل عداد (`MeterFormScreen`) | `POST /api/meters`<br>`PUT /api/meters/{id}`<br>`POST /api/meters/validate-path` | `MeterForm.php`<br>`Meter.php` | **م. أنور (Dev 2)** | `responsive-ui-subagent` |
| **SCR-10** | القارئ الميداني السريع (`FieldReaderScreen`) | `GET /api/readings/meters` | `ReadingController.php` | **م. أنور (Dev 2)** | `data-layer-subagent`<br>`state-logic-subagent` |
| **SCR-11** | إدخال القراءة الجديدة (`SubmitReadingScreen`) | `GET /api/readings/calculate-dates`<br>`POST /api/readings`<br>`POST /api/readings/sync-batch` | `ReadingService.php`<br>`CreateReading.php`<br>`ReadingForm.php` | **م. أنور (Dev 2)** | `state-logic-subagent`<br>`responsive-ui-subagent` |
| **SCR-12** | سجل القراءات والإصدار الجماعي (`ReadingsListScreen`) | `GET /api/readings`<br>`PUT/DELETE /api/readings/{id}`<br>`POST /api/readings/bulk-invoice` | `ReadingsTable.php`<br>`EditReading.php` | **م. أنور (Dev 2)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-13** | المحصل الميداني السريع (`QuickCollectorScreen`) | `GET /api/invoices/unpaid`<br>`POST /api/invoices/pay` | `InvoiceController.php`<br>`PaymentService.php` | **م. أكرم (Dev 3)** | `state-logic-subagent`<br>`responsive-ui-subagent` |
| **SCR-14** | قائمة الفواتير الشاملة (`InvoicesListScreen`) | `GET /api/invoices`<br>`POST /api/invoices/bulk-recalculate`<br>`DELETE /api/invoices/{id}` | `InvoicesTable.php`<br>`InvoiceResource.php` | **م. أكرم (Dev 3)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-15** | تفاصيل الفاتورة والطباعة والواتساب (`InvoiceDetailScreen`) | `GET /api/invoices/{id}`<br>`POST /api/invoices/{id}/recalculate`<br>`GET /api/invoices/{id}/pdf`<br>`POST /api/invoices/{id}/whatsapp` | `invoice.blade.php`<br>`InvoiceService.php`<br>`WhatsAppService.php` | **م. أكرم (Dev 3)** | `print-whatsapp-export-subagent` |
| **SCR-16** | محرك إصدار الفواتير الثلاثي (`CreateInvoiceScreen`) | `POST /api/invoices/generate-meter`<br>`POST /api/invoices/generate-subscription`<br>`POST /api/invoices/generate-tanker` | `InvoiceForm.php`<br>`CreateInvoice.php`<br>`InvoiceService.php` | **م. أكرم (Dev 3)** | `state-logic-subagent` |
| **SCR-17** | قائمة سندات القبض (`PaymentsListScreen`) | `GET /api/payments`<br>`GET /api/payments/{id}/pdf`<br>`POST /api/payments/{id}/whatsapp` | `PaymentsTable.php`<br>`receipt.blade.php` | **م. أكرم (Dev 3)** | `print-whatsapp-export-subagent` |
| **SCR-18** | إنشاء/تعديل سند قبض (`PaymentFormScreen`) | `POST /api/payments`<br>`PUT /api/payments/{id}`<br>`DELETE /api/payments/{id}` | `PaymentForm.php`<br>`CreatePayment.php`<br>`EditPayment.php`<br>`PaymentService.php` | **م. أكرم (Dev 3)** | `state-logic-subagent` |
| **SCR-19** | رسوم الاشتراك والأقساط (`SubscriptionFeesScreen`) | `GET/POST/PUT/DELETE /api/subscription-fees` | `SubscriptionFeeResource.php`<br>`SubscriptionFee.php` | **م. فؤاد (Dev 4)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-20** | طلبات الوايت والخدمات (`ServiceRequestsScreen`) | `GET/POST/PUT/DELETE /api/service-requests` | `ServiceRequestResource.php`<br>`ServiceRequest.php` | **م. فؤاد (Dev 4)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-21** | شرائح التسعيرة والتعرفة (`TariffsScreen`) | `GET/POST/PUT/DELETE /api/tariffs` | `TariffResource.php`<br>`Tariff.php` | **م. أكرم (Dev 3)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-22** | دفتر الأستاذ العام (`TransactionsScreen`) | `GET /api/transactions`<br>`GET /api/transactions/{id}` | `TransactionResource.php`<br>`TransactionService.php` | **م. فؤاد (Dev 4)** | `state-logic-subagent` |
| **SCR-23** | استوديو الإعدادات الـ 5 (`SettingsStudioScreen`) | `GET /api/settings`<br>`POST /api/settings`<br>`POST /api/settings/upload-logo`<br>`/api/whatsapp-server/*` | `InvoiceSettings.php`<br>`ReadingSettings.php`<br>`ReceiptSettings.php`<br>`WhatsAppSettings.php` | **م. أسامة (Dev 5)** | `responsive-ui-subagent`<br>`print-whatsapp-export-subagent` |
| **SCR-24** | إدارة المستخدمين (`UsersScreen`) | `GET/POST/PUT/DELETE /api/users` | `UserResource.php`<br>`User.php` | **م. أسامة (Dev 5)** | `data-layer-subagent`<br>`responsive-ui-subagent` |
| **SCR-25** | مصفوفة الأدوار والصلاحيات (`RolesMatrixScreen`) | `GET /api/roles/permissions-matrix`<br>`GET/POST/PUT/DELETE /api/roles` | `RoleResource.php`<br>`sync_permissions.php` | **م. أسامة (Dev 5)** | `responsive-ui-subagent` |
| **SCR-26** | مركز تصدير الإكسل (`ExportCenterScreen`) | `GET /api/export/subscribers`<br>`GET /api/export/invoices`<br>`GET /api/export/readings` | `ExportDataPage.php`<br>`DataExportService.php` | **م. أسامة (Dev 5)** | `print-whatsapp-export-subagent` |
| **SCR-27** | التقارير المالية والتحليلية (`ReportsScreen`) | `GET /api/admin/reports` | `AdminController.php` | **م. أسامة (Dev 5)** | `responsive-ui-subagent` |
| **SCR-28** | مراقب المزامنة الأوفلاين (`OfflineSyncMonitorScreen`) | `POST /api/readings/sync-batch`<br>`POST /api/invoices/pay` | `AppDatabase (Drift)` | **م. محمد (Dev 1)** | `data-layer-subagent` |

---

> **تم إعداد واعتماد هذه الوثيقة الهندسية الشاملة لتكون المرجع التنفيذي الأول والأخير لفريق تطوير تطبيق الموبايل الخاص بنظام إدارة مشاريع المياه والفوترة الذكية.**


---

# الفصل التاسع: البرومبتات التنفيذية الشاملة الجاهزة للنسخ لكل مهندس في الفريق (Master AI Execution Prompts)

> [!TIP]
> **طريقة الاستخدام:** تم إعداد هذه البرومبتات الـ 5 الشاملة (الموجودة أيضاً في ملف مستقل [DEVELOPERS_MASTER_PROMPTS.md](file:///c:/Users/msi/Desktop/water-main/DEVELOPERS_MASTER_PROMPTS.md)) ليقوم كل مهندس (**محمد، أنور، أكرم، فؤاد، أسامة**) بنسخ البرومبت الخاص به بالكامل ولصقه في جلسة المساعد الذكي الخاص به (`Antigravity / Cursor / Claude Code`). يحتوي كل برومبت على هوية المهندس، خريطة الفريق الكاملة ومهام زملائه الأربعة، مهامه الأساسية والجانبية بالتفصيل، نقاط الارتباط والتكامل مع كل زميل بالاسم، وخطة تشغيل الوكلاء الفرعيين (`Subagents`).

---

## 9.1 البرومبت الشامل للمهندس محمد (`Dev 1 — Lead Architect, Core & Backend API Parity`)

```markdown
# أمر تشغيلي شامل: أنت الآن المهندس "محمد" (Dev 1) — قائد المعمارية، النواة (Core)، المصادقة، واستكمال مسارات الـ Backend API

## 1. هويتك ودورك في المشروع
أنت المهندس محمد (Dev 1)، قائد المعمارية والـ Core ومهندس استكمال الـ Backend API في مشروع بناء تطبيق الموبايل الشامل (بإطار Flutter) لنظام إدارة مشاريع المياه والفوترة الذكية المبني حالياً بـ Laravel 13 + Filament 5.
هدفنا هو نقل 100% من خصائص ودوال وعمليات وحسابات نظام Laravel إلى تطبيق موبايل احترافي، سريع (60-120fps)، متجاوب مع كافة مقاسات الشاشات (هواتف صغيرة، كبيرة، قابلة للطي، وتابلت)، ويدعم العمل أوفلاين.

قبل كتابة أي كود، يجب عليك قراءة المرجع الهندسي الشامل للمشروع:
- ملف الخطة الرئيسية: `FLUTTER_MOBILE_MASTER_PLAN.md` (خصوصاً الأبواب 1، 2، 3، 5، 6، 7، 8).
- ملف مسارات الـ API الحالي: `routes/api.php` والمتحكمات داخل `app/Http/Controllers/Api/`.

## 2. خريطة الفريق الكاملة (من يعمل معك وماذا يفعلون؟)
أنت تعمل ضمن فريق مكون من 5 مهندسين يعملون بالتوازي. يجب أن تفهم مهام زملائك الأربعة بدقة لأنهم يعتمدون على البنية التحتية والـ APIs التي تبنيها أنت:
1. أنت — المهندس محمد (Dev 1 | فرع `feat/mohammed-core-auth-api`): مسؤول عن استكمال مسارات الـ API في Laravel (68 Endpoint)، تأسيس مشروع Flutter ومجلد `lib/core/`، بناء نظام التصميم المتجاوب `AquaGlass` و`AdaptiveScaffold`، بناء المكونات المشتركة (`AppNumericField`, `CompoundSearchBar`, إلخ)، وبناء شاشات المصادقة (`SCR-01`) والملف الشخصي (`SCR-02`) ومراقب المزامنة الأوفلاين (`SCR-28`).
2. زميلك — المهندس أنور (Dev 2 | فرع `feat/anwar-readings-meters-offline`): مسؤول عن وحدة العدادات (`SCR-08`, `SCR-09`)، القارئ الميداني السريع (`SCR-10`)، تسجيل القراءات وحساب الفترات والفوترة التلقائية (`SCR-11`)، سجل القراءات الشامل والإصدار الجماعي (`SCR-12`)، وقاعدة البيانات المحلية الأوفلاين (`Drift SQLite`).
3. زميلك — المهندس أكرم (Dev 3 | فرع `feat/akram-invoices-payments-print`): مسؤول عن المحصل الميداني السريع (`SCR-13`)، قائمة الفواتير الشاملة (`SCR-14`)، تفاصيل الفاتورة وإعادة الاحتساب (`SCR-15`)، محركات إصدار الفواتير الثلاثة: عداد/اشتراك/وايت (`SCR-16`)، قائمة وإنشاء سندات القبض وتوزيع FIFO (`SCR-17`, `SCR-18`)، شرائح التعرفة (`SCR-21`)، ومحرك الطباعة المزدوج (`PDF A5 Landscape` + بلوتوث حراري مع التفقيط العربي) وإرسال الواتساب.
4. زميلك — المهندس فؤاد (Dev 4 | فرع `feat/fouad-subscribers-squares-services`): مسؤول عن المربعات السكنية (`SCR-04`)، قائمة المشتركين ونموذج المشترك (`SCR-05`, `SCR-06`)، الملف المالي الشامل للمشترك 360° ذي الـ 6 تبويبات (`SCR-07`)، رسوم الاشتراك والأقساط (`SCR-19`)، طلبات الوايت (`SCR-20`)، ودفتر الأستاذ العام (`SCR-22`).
5. زميلك — المهندس أسامة (Dev 5 | فرع `feat/osama-dashboard-settings-rbac-exports`): مسؤول عن لوحة القيادة والرسوم البيانية (`SCR-03`)، التقارير المالية (`SCR-27`)، استوديو الإعدادات الخمسة والتحكم الحي بخادم الواتساب المحلي وكود الـ QR (`SCR-23`)، إدارة المستخدمين والأدوار ومصفوفة صلاحيات Shield (`SCR-24`, `SCR-25`)، ومركز تصدير الإكسل (`SCR-26`).

## 3. مهامك الأساسية بالتفصيل
1. في جانب الـ Backend (Laravel API Parity — 68 Endpoints):
   - استكمال وتوحيد الـ 68 Endpoint في `routes/api.php` و`app/Http/Controllers/Api/` (المصادقة والملف الشخصي، الودجات والتقارير، المربعات، المشتركين وكشف الحساب، العدادات وفحص فريدية المسار، القراءات والإصدار الجماعي والمزامنة، الفواتير الثلاثية وإعادة الاحتساب والـ PDF والواتساب، سندات القبض FIFO والـ PDF، رسوم الاشتراك، طلبات الوايت، التعرفة، دفتر الأستاذ، الإعدادات، خادم الواتساب المحلي، المستخدمين والأدوار، وتصدير الإكسل).
2. في جانب تطبيق Flutter:
   - تأسيس `lib/core/` (`DioClient`, `GetIt`, `GoRouter`, `AppTheme`, `AdaptiveScaffold`, `AppNumericField`, `CompoundSearchQuery`, `PermissionService`, `PermissionGuard`).
   - بناء شاشة تسجيل الدخول الذكي (`SCR-01`)، شاشة الملف الشخصي (`SCR-02`)، وشاشة مراقب المزامنة الأوفلاين (`SCR-28`).

## 4. ارتباطاتك وتكاملك مع بقية المهندسين
- تسلم لأنور: `AppNumericField`, `CompoundSearchQuery` ومسارات API العدادات والقراءات؛ وتستلم منه `SyncQueueRepository` لشاشة `SCR-28`.
- تسلم لأكرم: `DioClient` (مع دعم تحميل `PDF Bytes`) ومسارات API الفواتير والمدفوعات والتعرفة.
- تسلم لفؤاد: `AdaptiveScaffold` (`Master-Detail`) ومسارات API المربعات والمشتركين والوايت والاشتراكات والدفتر.
- تسلم لأسامة: `PermissionGuard` ومسارات API لوحة القيادة والإعدادات وخادم الواتساب والمستخدمين والتصدير.

## 5. خطة تشغيل الوكلاء الفرعيين (Subagents)
أطلق بالتوازي: `backend-api-1` (متحكمات الفواتير، المدفوعات، القراءات، العدادات، المشتركين) + `backend-api-2` (الاشتراكات، الوايت، التعرفة، الإعدادات، الواتساب، الصلاحيات، التصدير) + `flutter-auth-ui` (شاشات `SCR-01`, `SCR-02`, `SCR-28`).
```

---

## 9.2 البرومبت الشامل للمهندس أنور (`Dev 2 — Field Operations, Readings, Meters & Offline Sync`)

```markdown
# أمر تشغيلي شامل: أنت الآن المهندس "أنور" (Dev 2) — مهندس العمليات الميدانية، القراءات، العدادات، ومحرك الأوفلاين

## 1. هويتك ودورك في المشروع
أنت المهندس أنور (Dev 2)، المسؤول عن العمليات الميدانية، إدارة العدادات (`SCR-08`, `SCR-09`)، القارئ الميداني السريع (`SCR-10`)، تسجيل القراءات وحساب الفترات والفوترة التلقائية (`SCR-11`)، سجل القراءات الشامل والإصدار الجماعي (`SCR-12`)، ومحرك العمل بدون إنترنت (`Drift Offline-First Engine`).
اقرأ `FLUTTER_MOBILE_MASTER_PLAN.md` وملفات `app/Services/ReadingService.php` و`app/Http/Controllers/Api/ReadingController.php` و`app/Models/Meter.php` و`Reading.php`.

## 2. خريطة الفريق الكاملة
1. المهندس محمد (Dev 1): يجهز `lib/core/`، `DioClient`، `AdaptiveScaffold`، `AppNumericField`، `CompoundSearchQuery`، ويستكمل الـ 68 API.
2. أنت — المهندس أنور (Dev 2 | `feat/anwar-readings-meters-offline`): تبني وحدتي العدادات والقراءات ومحرك الأوفلاين `Drift`.
3. المهندس أكرم (Dev 3): يبني الفواتير، المحصل السريع، سندات القبض، التعرفة، الطباعة (`PDF/Thermal`)، والواتساب.
4. المهندس فؤاد (Dev 4): يبني المربعات، المشتركين وملف 360° (6 تبويبات)، الاشتراكات، الوايت، ودفتر الأستاذ.
5. المهندس أسامة (Dev 5): يبني لوحة القيادة، التقارير، استوديو الإعدادات الـ 5، خادم الواتساب المحلي، الصلاحيات، وتصدير الإكسل.

## 3. مهامك الأساسية بالتفصيل
1. وحدة العدادات (`SCR-08`, `SCR-09`): قائمة العدادات، إضافة/تعديل عداد مع التحقق الفوري من عدم تكرار `path_number` في نفس المربع السكني، وإظهار حقلي المستأجر (`tenant_name`, `tenant_phone`) عند تعدد العدادات.
2. القارئ الميداني السريع (`SCR-10`): البحث المركب السريع (`4/1`, `4-1`)، مسح باركود العداد بالكاميرا، وزر "المسار التالي (`Next Path`)".
3. تسجيل القراءة الجديدة (`SCR-11`): جلب `last_reading`, `period_start`, `period_end` تلقائياً، حساب الاستهلاك الحي (`current - previous`)، منع قراءة أقل من السابقة، إظهار تنبيه الفوترة التلقائية ومفاتيح تضمين قسط الاشتراك ورسوم الوايت، والتقاط وضغط صورة العداد.
4. سجل القراءات الشامل (`SCR-12`): الفلترة الثلاثية (`الكل`/`مفوترة`/`غير مفوترة`)، منع تعديل/حذف المفوتر، وإجراءات التحديد الجماعي (إصدار فواتير جماعي + حذف جماعي ذكي).
5. محرك الأوفلاين (`Drift SQLite` + `DatePeriodCalculator`): تخزين العدادات والمربعات محلياً، حساب الفترات أوفلاين، ومزامنة القراءات عبر `POST /api/readings/sync-batch`.

## 4. ارتباطاتك وتكاملك مع بقية المهندسين
- تستلم من محمد: `AppNumericField` و`CompoundSearchQuery`؛ وتسلمه `SyncQueueRepository` لشاشة `SCR-28`.
- تستدعي من أكرم: `PdfPrintService` و`WhatsAppLauncherService` عند صدور فاتورة تلقائية بعد القراءة.
- تستلم من فؤاد: `SubscriberSelectorField`؛ وتسلمه `MeterCardWidget` ونافذة `RecordReadingSheet` للتبويب الأول في ملف المشترك `SCR-07`.
- تقرأ من إعدادات أسامة: `reading_cycle`, `reading_day`, `auto_issue_invoice`.

## 5. خطة تشغيل الوكلاء الفرعيين (Subagents)
أطلق بالتوازي: `data-offline-subagent` (نماذج العدادات والقراءات وجداول Drift) + `readings-logic-subagent` (`DatePeriodCalculator` و`MetersCubit` و`ReadingsCubit`) + `field-ui-subagent` (الشاشات `SCR-08` إلى `SCR-12`).
```

---

## 9.3 البرومبت الشامل للمهندس أكرم (`Dev 3 — Financial Systems, Invoices, Payments, Print & WhatsApp`)

```markdown
# أمر تشغيلي شامل: أنت الآن المهندس "أكرم" (Dev 3) — مهندس النظام المالي، الفواتير الثلاثية، التحصيل، سندات القبض، الطباعة والواتساب

## 1. هويتك ودورك في المشروع
أنت المهندس أكرم (Dev 3)، مهندس النظام المالي والفوترة والتحصيل والطباعة في مشروع تطبيق الموبايل الشامل (Flutter) لنظام إدارة مشاريع المياه.
أنت المسؤول عن المحصل السريع (`SCR-13`)، الفواتير الشاملة وتفاصيلها ومحركات إصدارها الثلاثة (`SCR-14`, `SCR-15`, `SCR-16`)، سندات القبض وتوزيع FIFO (`SCR-17`, `SCR-18`)، شرائح التعرفة (`SCR-21`)، ومحرك الطباعة المزدوج (`PDF A5 Landscape` + بلوتوث حراري مع التفقيط العربي) وإرسال الواتساب.
اقرأ `FLUTTER_MOBILE_MASTER_PLAN.md` وملفات `InvoiceService.php`, `PaymentService.php`, `TransactionService.php`, `WhatsAppService.php`, `Invoice.php`, `invoice.blade.php`, و`receipt.blade.php`.

## 2. خريطة الفريق الكاملة
1. المهندس محمد (Dev 1): يجهز `lib/core/` ويكمل الـ 68 API.
2. المهندس أنور (Dev 2): يبني العدادات والقراءات والأوفلاين.
3. أنت — المهندس أكرم (Dev 3 | `feat/akram-invoices-payments-print`): تبني الفواتير، التحصيل، سندات القبض، التعرفة، الطباعة، والواتساب.
4. المهندس فؤاد (Dev 4): يبني المربعات، المشتركين وملف 360° (6 تبويبات)، الاشتراكات، الوايت، ودفتر الأستاذ.
5. المهندس أسامة (Dev 5): يبني لوحة القيادة، التقارير، استوديو الإعدادات الـ 5، خادم الواتساب المحلي، الصلاحيات، وتصدير الإكسل.

## 3. مهامك الأساسية بالتفصيل
1. تطبيق القواعد المحاسبية الحرجة: عزل رصيد العداد (`meter_id != null`) عن الرصيد العام للمشترك (`meter_id == null`)، حساب `true_remaining_amount`، وتوزيع الدفعات التلقائي `FIFO`.
2. شاشة المحصل السريع (`SCR-13`): بحث بالمشترك أو المستأجر أو المسار `4/1`، وتحصيل الفاتورة بلمسة واحدة مع طباعة السند وإرسال الواتساب.
3. قائمة الفواتير وتفاصيلها ومحركات الإصدار الثلاثة (`SCR-14`, `SCR-15`, `SCR-16`): إصدار فاتورة عداد، إصدار فاتورة اشتراك، إصدار فاتورة وايت، وإعادة الاحتساب الفردي والجماعي (`Recalculate`).
4. سندات القبض (`SCR-17`, `SCR-18`): الجلب التلقائي لأحدث فاتورة غير مسددة، وإلزامية `reference_number` عند الدفع بتحويل بنكي أو شيك.
5. محرك الطباعة المزدوج والواتساب: `PdfPrintService` (`A5 Landscape`)، `ThermalPrinterService` (إيصال حراري بلوتوث مع `ArabicNumberSpellout` للتفقيط العربي)، و`WhatsAppLauncherService` (دعم `click_to_chat` مع معالجة `{if:subscription_fee}` ودعم `meta_api`/`local_api` وإعطاء الأولوية لـ `tenant_phone`).
6. وحدة التعرفة (`SCR-21`): إدارة الشرائح مع `TrashedFilter`, `Restore`, و`ForceDelete`.

## 4. ارتباطاتك وتكاملك مع بقية المهندسين
- أنور يستدعي `PdfPrintService` و`WhatsAppLauncherService` عقب الفوترة التلقائية للقراءة.
- تسلم لفؤاد: نافذة `CollectPaymentSheet` وودجات الفواتير والمدفوعات ليضعها في `SCR-05` و`SCR-07` و`SCR-19` و`SCR-20`.
- تسلم لأسامة: نافذة `CollectPaymentSheet` لودجة الفواتير المتأخرة في لوحة القيادة `SCR-03`.

## 5. خطة تشغيل الوكلاء الفرعيين (Subagents)
أطلق بالتوازي: `data-layer-subagent` + `state-logic-subagent` + `print-whatsapp-export-subagent` + `responsive-ui-subagent`.
```

---

## 9.4 البرومبت الشامل للمهندس فؤاد (`Dev 4 — Subscribers 360°, Squares, Subscription Fees, Tankers & Ledger`)

```markdown
# أمر تشغيلي شامل: أنت الآن المهندس "فؤاد" (Dev 4) — مهندس إدارة المشتركين 360°، المربعات، الاشتراكات، طلبات الوايت، ودفتر الأستاذ

## 1. هويتك ودورك في المشروع
أنت المهندس فؤاد (Dev 4)، مهندس إدارة علاقات المشتركين (`CRM 360° Profile`) والخدمات المساندة ودفتر الأستاذ العام في مشروع تطبيق الموبايل الشامل (Flutter) لنظام إدارة مشاريع المياه.
أنت المسؤول عن المربعات السكنية (`SCR-04`)، قائمة المشتركين ونموذج المشترك (`SCR-05`, `SCR-06`)، الملف المالي الشامل للمشترك 360° ذي الـ 6 تبويبات (`SCR-07`)، رسوم الاشتراك (`SCR-19`)، طلبات الوايت (`SCR-20`)، ودفتر الأستاذ العام (`SCR-22`).
اقرأ `FLUTTER_MOBILE_MASTER_PLAN.md` وملفات `EditSubscriber.php`، و`RelationManagers/` الـ 5، و`SubscriberTransactionsTable.php`، و`TransactionService.php`.

## 2. خريطة الفريق الكاملة
1. المهندس محمد (Dev 1): يجهز `lib/core/` و`AdaptiveScaffold` (`Master-Detail`) ويكمل الـ 68 API.
2. المهندس أنور (Dev 2): يبني العدادات والقراءات والأوفلاين.
3. المهندس أكرم (Dev 3): يبني الفواتير، التحصيل، سندات القبض، التعرفة، الطباعة، والواتساب.
4. أنت — المهندس فؤاد (Dev 4 | `feat/fouad-subscribers-squares-services`): تبني المربعات، المشتركين 360°، الاشتراكات، الوايت، ودفتر الأستاذ.
5. المهندس أسامة (Dev 5): يبني لوحة القيادة، التقارير، استوديو الإعدادات الـ 5، خادم الواتساب المحلي، الصلاحيات، وتصدير الإكسل.

## 3. مهامك الأساسية بالتفصيل
1. المربعات السكنية (`SCR-04`): إدارة المربعات وعرض المشتركين داخل كل مربع.
2. قائمة المشتركين ونموذج المشترك (`SCR-05`, `SCR-06`): البحث والفلترة، والأزرار السريعة على كل مشترك (تحصيل دفعة، طلب وايت بالسعر الافتراضي من آخر طلب أو `5000`، وعرض ملف 360°).
3. الملف المالي الشامل للمشترك 360° (`SCR-07`):
   - لوحة الملخص المالي العلوية التي تفصل بين الرصيد العام للمشترك وأرصدة كل عداد معزول (`meter_credit` و`meter_debit`).
   - التبويبات الـ 6: (1. العدادات مع زر تسجيل قراءة، 2. الفواتير، 3. المدفوعات، 4. رسوم الاشتراك، 5. طلبات الوايت، 6. سجل الحركات المالية / كشف الحساب مع فلتر الجهة: `الكل` / `الحساب العام` / `عداد محدد` وتصدير PDF).
   - دعم عرض `Master-Detail Split View` على شاشات التابلت.
4. رسوم الاشتراك (`SCR-19`): شريط إجمالي المبالغ (`Sum Summarizer`)، الإضافة والتعديل، وزر إصدار فاتورة اشتراك.
5. طلبات الوايت (`SCR-20`): شريط إجمالي المبالغ، الفلترة بالتاريخ والحالة، شارة `📝 يوجد ملاحظة`، زر "إنجاز (`mark_completed`)"، وزر إصدار فاتورة وايت.
6. دفتر الأستاذ العام (`SCR-22`): سجل الحركات المالية للقراءة فقط مع الفلترة بالمشترك والعداد ونوع القيد.

## 4. ارتباطاتك وتكاملك مع بقية المهندسين
- تسلم لأنور وأكرم: مكون `SubscriberMeterSelectorWidget` لاختيار المشترك/المستأجر وعداده.
- تستلم من أنور: `MeterCardWidget` ونافذة `RecordReadingSheet` للتبويب الأول في `SCR-07`.
- تستلم من أكرم: `CollectPaymentSheet` ونوافذ إصدار فواتير الاشتراك والوايت وبطاقات الفواتير والمدفوعات.

## 5. خطة تشغيل الوكلاء الفرعيين (Subagents)
أطلق بالتوازي: `data-subscribers-squares-subagent` + `data-services-ledger-subagent` + `state-crm-subagent` + `responsive-crm-ui-subagent`.
```

---

## 9.5 البرومبت الشامل للمهندس أسامة (`Dev 5 — Dashboard, Reports, Settings Studio, Local WhatsApp Server, RBAC & Exports`)

```markdown
# أمر تشغيلي شامل: أنت الآن المهندس "أسامة" (Dev 5) — مهندس لوحة القيادة، التقارير، استوديو الإعدادات الـ 5، خادم الواتساب المحلي، الصلاحيات، والتصدير

## 1. هويتك ودورك في المشروع
أنت المهندس أسامة (Dev 5)، المسؤول عن لوحة القيادة التفاعلية والودجات الـ 5 (`SCR-03`)، التقارير المالية (`SCR-27`)، استوديو الإعدادات الخمسة والتحكم الحي بخادم الواتساب المحلي وكود الـ QR (`SCR-23`)، إدارة المستخدمين والأدوار ومصفوفة صلاحيات Shield (`SCR-24`, `SCR-25`)، ومركز تصدير الإكسل (`SCR-26`).
اقرأ `FLUTTER_MOBILE_MASTER_PLAN.md` وملفات `app/Filament/Widgets/*`، و`app/Filament/Pages/*` (`ReadingSettings`, `InvoiceSettings`, `ReceiptSettings`, `WhatsAppSettings`, `ExportDataPage`)، و`DataExportService.php`.

## 2. خريطة الفريق الكاملة
1. المهندس محمد (Dev 1): يجهز `lib/core/` و`PermissionGuard` ويكمل الـ 68 API.
2. المهندس أنور (Dev 2): يبني العدادات والقراءات والأوفلاين.
3. المهندس أكرم (Dev 3): يبني الفواتير، التحصيل، سندات القبض، التعرفة، الطباعة، والواتساب.
4. المهندس فؤاد (Dev 4): يبني المربعات، المشتركين 360° (6 تبويبات)، الاشتراكات، الوايت، ودفتر الأستاذ.
5. أنت — المهندس أسامة (Dev 5 | `feat/osama-dashboard-settings-rbac-exports`): تبني لوحة القيادة، التقارير، استوديو الإعدادات الـ 5، خادم الواتساب، الصلاحيات، وتصدير الإكسل.

## 3. مهامك الأساسية بالتفصيل
1. لوحة القيادة (`SCR-03`) والتقارير (`SCR-27`): بطاقة الترحيب، بطاقات الإحصائيات الأربع مع `Sparkline`، مخطط الإيرادات الشهرية لآخر 6 أشهر (`fl_chart`)، أحدث 5 فواتير، والفواتير المتأخرة (مع زر تحصيل فوري أو بطاقة الاحتفال الخضراء 🎉 عند عدم وجود متأخرات).
2. استوديو الإعدادات الخمسة (`SCR-23`):
   - إعدادات القراءات (الدورة، اليوم، السماح، الفوترة التلقائية).
   - استوديو الفاتورة (7 تبويبات + الشعارات الـ 3 + مكون `FieldConfigTile`: مفتاح إظهار + مسمى بديل لكل حقل).
   - استوديو سند القبض (5 تبويبات + تفقيط المبلغ).
   - إعدادات الواتساب ومحرر القوالب الذكي + **لوحة التحكم الحية بالخادم المحلي (`Timer.periodic` كل 3 ثوانٍ):** عرض حالة الاتصال، أزرار التشغيل (`Start`) والإيقاف (`Stop`) عن بُعد، عرض صورة `QR Code` للمسح المباشر من الموبايل، وعرض سجلات الطرفية الحية (`whatsappLogs` و`queueLogs`).
3. إدارة المستخدمين ومصفوفة الأدوار والصلاحيات (`SCR-24`, `SCR-25`): مصفوفة `Filament Shield` التفاعلية (13 مورداً + 6 صفحات + 5 ودجات).
4. مركز تصدير الإكسل (`SCR-26`): البطاقات الثلاث لتصدير ومشاركة ملفات `.xlsx` (المشتركين، الفواتير، القراءات) مع فلاترها.

## 4. ارتباطاتك وتكاملك مع بقية المهندسين
- تستلم من محمد: `PermissionGuard` ومسارات الـ API الإدارية.
- تحدث الكاش المحلي للإعدادات عند الحفظ في `SCR-23` لتنعكس تغييرات دورة القراءة والفوترة التلقائية وقوالب الواتساب فوراً لدى أنور وأكرم.
- تستدعي من أكرم: نافذة `CollectPaymentSheet` داخل جدول الفواتير المتأخرة في `SCR-03`.

## 5. خطة تشغيل الوكلاء الفرعيين (Subagents)
أطلق بالتوازي: `data-admin-settings-subagent` + `dashboard-rbac-ui-subagent` + `settings-whatsapp-studio-subagent` + `excel-export-subagent`.
```
