# Mobile workspace and Laravel coverage

## Scope and verification status

This is a **source-level inventory**, not a claim of complete, device-tested
Laravel/Flutter parity. The repository already contains the main mobile feature
modules. The workspace changes make those modules discoverable without putting
an administration manual on the home screen. No web views or simulated business
data replace the native workflows.

### Delivered — global search

- A single **البحث الشامل** screen (`/search`) reachable from the app bar on every
  tab and from the desktop rail. It calls `GET /api/search` with a 320 ms
  debounce, drops stale responses, and groups subscribers, invoices, meters,
  receipts and squares.
- The server returns only the groups the signed-in user may list
  (`Subscriber`/`Invoice`/`Meter`/`Payment`/`Square` `viewAny`), using the same
  Shield + legacy snake_case + built-in-role rules as the rest of the API.
  Arabic-Indic digits are normalised before matching.
- Tapping a result opens the existing screen (subscriber 360, invoice detail,
  payment editor, the subscriber's meters, or the squares list); no read-only
  duplicate screens were added.

### Delivered in the workspace change

- Task-first Arabic/RTL home, four permission-filtered daily shortcuts, a short
  quick-access list, and a real shared-queue connection/pending/failure summary.
- Four navigation destinations: home, services, sync and account; existing
  adaptive navigation still uses a bottom bar on phones and a rail on desktop.
- Searchable service directory with categories, empty-state recovery, Arabic
  spelling/diacritic normalization and Arabic/English search keywords.
- One catalog of service destinations and permissions, shared by the directory
  and home. Each entry is filtered individually. All required shortcut lookup
  permissions must be present; a view-only invoice grant does not imply list
  access. Laravel remains the authorization boundary.
- Legacy snake_case permission compatibility and collector square access,
  matching the API's built-in role rules.
- Invoice entry points now support water-meter consumption billing only; former
  charge-only invoice engines were removed. Existing `/invoices/create` retains
  its water-invoice form behavior.
- Settings links open the requested permitted tab (`reading`, `invoice`,
  `receipt`, `whatsapp`, `general`). Invalid/unavailable sections safely fall back
  to the first permitted tab, never to an unauthorized editor.

## Source-backed feature destinations

All entries below are native Flutter screens backed by existing repositories.
Action-by-action acceptance against a running Laravel instance remains required.

| Laravel resource/page | Mobile destination |
| --- | --- |
| Squares, subscribers relation | المربعات → subscribers/detail |
| Subscriber and its meters/invoices/payments/statement | المشتركون → subscriber profile |
| Meters | العدادات; تسجيل قراءة for field work |
| Readings, history, bulk invoicing | سجل القراءات; تسجيل قراءة |
| Invoices and generation actions | الفواتير; إصدار فاتورة → type chooser |
| Payments/receipts, collection, printing and sharing | سندات القبض; تحصيل دفعة |
| Tariffs | التعرفة |
| Transactions (read only) | الحركات المالية |
| Filament global search | البحث الشامل |
| Dashboard widgets | لوحة المؤشرات |
| Reports | التقارير |
| ExportDataPage | تصدير Excel |
| ReadingSettings | إعدادات القراءات |
| InvoiceSettings | تصميم الفاتورة |
| ReceiptSettings | تصميم سند القبض |
| WhatsAppSettings | الواتساب |
| General settings resource | الإعدادات العامة |
| Users and roles/Shield | المستخدمون; الأدوار والصلاحيات |
| EditProfilePage | الملف الشخصي / account tab |
| Offline queue (mobile-specific) | المزامنة |
| Cash-session supervision | مراجعة الصناديق; independent view/decision grants |
| Central audit events | سجل التدقيق; filters and before/after details |

## Remaining parity and release gates

Do not call the app “all Laravel functions verified” until these are completed:

- Compare **each** Filament form field, filter, relation action, export, single
  action and bulk action with the Flutter UI and API, using seeded test data.
  Module availability is not proof of action-level equivalence. The current
  source-level matrix lives in `docs/MOBILE_PARITY_AUDIT_AR.md`.
- Action-level gaps **closed against the master plan** (this change): payment
  `notes` field in the shared collect form (S-PAY-02 field 9), payment edit
  action (`/payments/:id/edit`, S-PAY-01), ledger date-range filter (R-11),
  roles bulk delete (S-ROL-01), discard-for-failed sync items (ch 26.2),
  meter form «حفظ وإضافة عداد آخر», and subscription-fee dialog
  «حفظ وإضافة أخرى» — the Filament create-and-create-another chains.
- Tariff bulk restore/force-delete and meter-owner selection already exist in
  this checkout. The deferred-status description was stale. Financial acceptance
  of ownership transfer remains a device/backend check.
- The 2026-10-26 parity change adds subscriber → meter → fee creation, role
  guards and read-only matrix viewing, subscription-invoice setting persistence,
  cash supervision, central audit browsing, actual reading date, complete
  paginated collections and shared sort/page-size/field display controls.
- Consolidate the shared auth queue, field SQLite queue and finance queue.
  The home summary intentionally reports **only `SyncQueueManager`**, not all
  three stores. Network availability does not prove server reachability; the UI
  says “connected to the network,” not “server connected.”
- Test permission changes, role-only fallback, custom/legacy permissions, direct
  links, forbidden writes and account switching against Laravel. UI visibility
  never substitutes for backend checks.
- Exercise reading/photo upload, retry idempotency, invoice calculations,
  receipt allocation, restoration/deletion and reconciliation against real data.
- Verify PDF/thermal printing, camera/scanner, XLSX sharing, WhatsApp pairing and
  the separately deployed WhatsApp bridge on supported Android/Windows devices.
- Run screen-reader, keyboard, RTL, light/dark, 320px-phone, tablet/desktop and
  large-text checks. No SDK/device visual acceptance was possible here.

## Tests

New tests cover catalog completeness/unique destinations, actual router matches,
role/legacy permission navigation, required shortcut permissions, search/filter
behavior, settings query forwarding, invoice engine navigation, RTL/large-text
layouts, and service-request editing/validation/conflicts.

Run with the supported Flutter SDK:

```sh
cd "mobile app"
flutter pub get --enforce-lockfile
flutter analyze --no-fatal-infos --no-fatal-warnings
flutter test test/home test/service_requests
flutter test
```

Flutter/Dart and PHP are available in the 2026-10-26 Windows workspace. Actual
commands and results are recorded in `docs/v2/qa/MOBILE_WEB_PARITY_2026-10-26.md`
at the Laravel root. Automated checks do not replace physical-device acceptance.
