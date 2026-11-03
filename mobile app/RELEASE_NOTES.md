# Water — testing prerelease

Testing builds of the integrated Arabic Water app. Android APKs are attached to
`mobile-test-*` releases; Windows ZIPs are attached to `windows-test-*` releases.

## What's new in this prerelease

- شاشة نتيجة القراءة تعرض زر «رسالة SMS» لفتح رسالة الفاتورة برقم المشترك،
  وزر «واتساب مع الصورة» الذي يجهّز صور PNG لكل صفحات الفاتورة الرسمية
  ويفتح قائمة المشاركة لاختيار واتساب والمستلم وتأكيد الإرسال.
  تتطلب SMS جهازاً أو تطبيقاً يدعم الرسائل؛ لا يُسجّل فتح المشاركة كإرسال مؤكد.

- أدوات موحدة للبحث والفلترة والترتيب في القوائم: المربع، المسار، وفترة تاريخ الإضافة،
  مع عرض المربع والمسارات في بطاقات المشتركين والقراءات والسجلات المالية.

- كتابة أرقام فقط في البحث الشامل تعني رقم المسار المطابق بالكامل؛ تظهر
  سجلات ذلك المسار فقط، بالأرقام العربية أو الإنجليزية ومن خانة واحدة.
  البحث النصي يبقى من حرفين.
- رقم المسار يُكتب في خانة البحث الرئيسية مباشرة؛ أُزيل الحقل المكرر من
  خيارات عرض البحث الشامل. تظهر مجموعات النتائج بترتيب المشتركين، ثم
  العدادات، ثم الفواتير، ثم سندات القبض.

- أُزيلت صفحة إعداد الخادم عند الإقلاع؛ يستخدم التطبيق الرابط المحفوظ من وضع
  المطور مباشرة، أو عنوان Windows المحلي/محاكي Android عند أول تشغيل.

- Web/mobile parity: cash supervision and central audit, subscriber → meter →
  subscription-fee creation, role guards and read-only matrices, actual reading
  date, metadata edits on invoiced requests, subscription invoice settings, and
  complete paginated lists with sort/display controls. Evidence:
  `docs/v2/qa/MOBILE_WEB_PARITY_2026-10-26.md` at the Laravel root.

- **Design system v2 (radical redesign)** — one teal accent, no decorative
  gradients, hairline cards instead of shadows, a single ink summary panel per
  page, and heavier weight replaced by a calmer 600/400 scale. Home, shell, hubs,
  login, splash and the dashboard widgets now share it.
- Numbers are shown as full amounts with a muted `ر.ي` unit (no more
  `م ريال` / `ألف ريال` abbreviations on cards), in tabular figures with Latin
  digits inside the RTL layout.
- A full interface redesign: new design system, light/dark themes and a calmer,
  higher-contrast Arabic layout.
- Five-tab navigation (Overview, Field, Billing, Subscribers, More) replacing the
  single long home page, plus redesigned dashboard, login, splash and sync
  screens.
- Richer dashboard widgets: KPI cards with sparklines, collection health ring,
  revenue chart summary and actionable overdue-invoice cards.
- A real app identity: the new water-drop mark is now the launcher icon on
  Android (legacy, round, adaptive and themed), and it matches the splash and
  login screens, the navigation rail and the Windows/Linux icons.
- The IBM Plex Sans Arabic type scale and the search-first services directory
  from `main` are preserved: field collectors still land on the action-first
  workspace, while office roles get the analytics overview.

**Testing only — not a production release.** Android uses the generated debug
signing key, so a later build may require uninstalling the previous APK. Sync
pending operations before uninstalling; uninstalling deletes local app data.
Do not use this build for live financial collection until device/backend QA passes.

## Connect to a local Laravel server

1. On the PC, install/configure Laravel dependencies and database, apply migrations,
   then run `php artisan serve --host=0.0.0.0 --port=8000`.
2. Allow port 8000 through the PC firewall on your trusted private network only.
3. The app opens directly with the saved server URL, or its platform default:
   - Windows on the backend PC: `http://localhost:8000/api` by default.
   - Physical Android phone: set `http://<PC-LAN-IP>:8000/api` from the login
     screen's server button or developer mode; use the same Wi-Fi.
   - Android emulator: `http://10.0.2.2:8000/api` by default.
4. Sign in using an existing backend account. There are no bundled credentials.

`localhost` on a phone refers to the phone, not your PC. HTTP is enabled for
local testing and is not encrypted. Use HTTPS before production.

The saved developer-mode URL takes priority over `API_BASE_URL` and platform
defaults. It can be changed from the login screen (server button) or
**المزيد ← وضع المطور ← تغيير رابط الخادم** without clearing app data:
the app signs the current session out, clears cached server snapshots and keeps
local data intact. The change is **blocked while any offline operation is still
waiting in the sync queue** so receipts/readings can never move between servers.

Extract the whole Windows ZIP and run `water_project.exe`; keep the DLLs and data
folder alongside it. Windows camera, Bluetooth, printing and platform plugins
need separate hardware testing. The APK build does not verify those operations.

Automated analyzer errors and Flutter tests gate publication on both Android and
Windows, but legacy lint warnings are not yet blocking. Full master-plan acceptance,
backend integration, physical-device testing and production signing remain
release-readiness work. No production-readiness claim is made by this prerelease.

## What changed in this build

- **One offline queue**: readings and cash receipts now share a single durable
  SQLite (Drift) queue. Pending items saved by previous builds in
  `SharedPreferences` are migrated automatically on first launch, and the sync
  monitor (`SCR-28`) plus the home badge show one unified pending count.
- **Account isolation**: every queued operation stores its owner user id, so a
  shared device can never upload one employee's receipts under another account.
- **Idempotent receipts**: each payment gets a UUID before the request is sent and
  reuses it after a network drop, so a lost response cannot create a duplicate
  receipt (`sync_uuid` on the backend).
- **Role-aware home screen**: module cards and the app-bar shortcuts are filtered by
  the user's Shield permissions instead of showing every screen to every role.
- **Server switching** from inside the app, guarded by the pending-sync check.


## 2026-11-04 — وضوح الميدان والتحصيل والتقارير (تحديث محلي)

- بطاقات قارئ ميداني أقصر مع قراءة ورصيد واضحين، وإمكانية فتح التفاصيل كاملة.
- تقديم اسم المشترك والمتبقي في الفواتير وإخفاء التفاصيل الثانوية داخل قسم قابل للفتح.
- اقتراح المتبقي الفعلي للتحصيل بعد خصم المدفوع سابقاً، والحفاظ على الفاتورة المختارة عند تحديث الحساب.
- قائمة للإجراءات الإضافية في السندات، وترتيب أوضح للقيود المالية والمراجع العربية.
- مؤشرات تقارير مختصرة مع الفترة الفعلية، وتوافق مع أسماء الإجماليات القديمة.
- إضافة تفاصيل التحصيل اليومي وحالات الفواتير والمربعات إلى عقد تقرير الخادم؛ استبعاد السندات الملغاة.
- تمييز الفترة الخالية فعلاً عن عدم توافر تفاصيل التقرير، وتجنب عرض أصفار غير مؤكدة من الخادم القديم.

التفاصيل وأدلة التحقق: `docs/v2/qa/FIELD_FINANCE_CLARITY_2026-11-04_AR.md`.
التحديث غير منشور على الخادم، ولم يُعتمد على هاتف فعلي.
