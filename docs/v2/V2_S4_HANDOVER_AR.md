# تسليم الإصدار 2.0 — الدورتان S4 وS5 (المال والمساءلة والنضج)

> **تنبيه صدق أولاً:** كل ما في هذا الملف **كُتب** ولم يُشغَّل. لا يوجد في بيئة
> الإعداد PHP ولا Composer ولا Flutter ولا Docker، لذلك لا يجوز قراءة أي سطر
> هنا على أنه «اختبار ناجح». أوامر التحقق في القسم الأخير هي التي تحوّل هذا
> العمل من «مكتوب» إلى «مُثبَت».

---

## 1. ما أُضيف في هذه الدورة

### 1.1 اعتماد المشرف لإلغاء السندات الكبيرة — S4-04

| الطبقة | الملف | الأثر |
|---|---|---|
| قاعدة البيانات | `database/migrations/2026_10_24_000500_add_void_approval_to_payments_table.php` | أعمدة `void_requested_at/by/reason` و`void_decision_notes` و`void_decided_by/at` + فهرس للطلبات المعلّقة |
| الخادم | `app/Services/PaymentService.php` | `voidApprovalThreshold()`، `requiresVoidApproval()`، `requestVoid()`، `decideVoidRequest()` |
| الواجهة البرمجية | `app/Http/Controllers/Api/PaymentController.php` + `routes/api.php` | `POST /api/payments/{id}/void` (200 أو 202)، `GET /api/payments/void-requests`، `POST /api/payments/{id}/void-decision` |
| اللوحة | `app/Filament/Resources/VoidRequests/**` | اعتماد/رفض بسبب مكتوب + شارة عدد الطلبات المعلّقة |
| التطبيق | `lib/features/payments/**` | `VoidPaymentOutcome` (معلّق/أوفلاين) ورسائل عربية واضحة |

**قواعد لا تُخترق:**
- السند لا يُحذف أبداً؛ الإلغاء علامة (`voided_at`) مع سبب ومُلغٍ.
- الحد صفر = الخاصية معطّلة (توافق v1). المبلغ **≥ الحد** ينتظر اعتماداً.
- الموافقة تمرّ عبر نفس `voidPayment(..., source: 'approval')` — مسار إلغاء واحد فقط.
- الحفظ بعد الإلغاء يستخدم `saveQuietly()` لتفادي إعادة احتساب الدفتر مرتين.
- طلب معلّق مكرر لا يُنشئ طلباً ثانياً (idempotent).

### 1.2 تقرير الصندوق اليومي PDF — S4-06

- `GET /api/cash-sessions/{id}/report/pdf` (A4 عربي، تفقيط عبر `App\Support\ArabicNumber`).
- القالب: `resources/views/pdf/cash_session.blade.php` (ملخص + السندات + الملاحظات + خطوط التوقيع).
- التطبيق: زر «تقرير الصندوق (PDF)» في شاشة الصندوق يفتح معاينة/طباعة/مشاركة
  (`lib/features/cash_sessions/presentation/widgets/cash_session_report.dart`).
- البناء على الخادم مقصود: تشكيل العربية والخطوط لا يُضمنان على كل جهاز.

### 1.3 سجل تدقيق محلي + رفع مجمّع — S4-05 (جزئي)

- `lib/features/audit/` — `AuditLogService` + `SharedPreferencesAuditStore`
  (سقف 300 حدث، تحمّل الصفوف التالفة) + رفع دفعات ≤ 50 حدثاً.
- مربوط بـ: فتح/إغلاق/اعتماد/رفض الصندوق. **غير مربوط بعد:** أحداث السندات
  والإلغاء من الجهاز (الخادم يسجّلها عند الاتصال، لكن الحدث الأوفلاين لا يُسجَّل محلياً).
- `POST /api/audit-events` كان جاهزاً؛ أُضيف له اختبار يثبت أن الفاعل يُؤخذ من
  التوكن ولا يُقبل انتحال `actor_user_id`.

### 1.4 إلغاء السند بلا شبكة

- عند `NetworkFailure` يُحفظ **طلب** الإلغاء في طابور المزامنة
  (`payment.void`) مع السبب، ويُرسل تلقائياً عند العودة.
- السند يبقى صالحاً حتى يقرر الخادم (قد يطلبه اعتماد مشرف).
- `VoidPaymentOutcome.queuedOffline` يعرض للمحصّل أن الطلب محفوظ لا منفّذ.

### 1.5 شاشة التشخيص والدعم — S5-06

- `lib/features/diagnostics/presentation/diagnostics_screen.dart` على المسار `/diagnostics`.
- تعرض: حالة الاتصال، عدّادات الطابور (بانتظار تهدئة/فاشل/يحتاج تدخلاً)،
  أحداث التدقيق المعلّقة مع زر رفع، آخر الأخطاء (بعد التنقية)، وزر
  «نسخ تقرير الدعم» لنص واحد يُرسل عبر واتساب.

---

## 2. ما زال ناقصاً في S4/S5

| البطاقة | الناقص |
|---|---|
| S4-05 | ربط أحداث السندات/التحصيل المحلي بالسجل، واختبار تكامل للرفع المتكرر |
| S5-01 | قفل حيوي + PIN + مهلة خمول |
| S5-02 | تشفير قاعدة البيانات (SQLCipher) |
| S5-04 | استخراج نصوص الشاشات إلى مفاتيح توطين (لا نصوص عربية داخل الشاشات) |
| S5-05 | فصل أول ملفين ضخمين |
| S3-01..06 | وضع المسار، التحقق الفوري الكامل، الشارة/الشريط الأوفلاين، قياس زمن القراءة |

---

## 3. أوامر التحقق (لم تُنفَّذ بعد)

```bash
# الخادم
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --force
php artisan db:seed
php artisan test --filter='PaymentVoidApprovalTest|AuditEventsApiTest|CashSessionApiTest'

# التطبيق
cd "mobile app"
flutter pub get
dart run build_runner build --delete-conflicting-outputs   # إن لزم
flutter analyze
flutter test test/features/audit test/features/offline_sync test/core

# بيئة كاملة (Docker)
docker compose up --build
```

## 4. ملاحظات تشغيلية

- **حد الإلغاء** يُضبط من جدول `settings` بالمفتاح `void_approval_threshold`
  (مثال: `10000`). صفر أو غياب المفتاح = إلغاء مباشر كما في v1.
- **اعتماد الإلغاء** يتطلب مديراً أو `payments.void.approve` / `Approve:Payment`.
- **تقرير الصندوق** لا يعمل بلا Chrome/Browsershot على الخادم (نفس شرط سند القبض).
- **سجل التدقيق**: إذا فشل الرفع يبقى الحدث محلياً؛ الشاشة تعرض العدد المعلّق.
