# Flutter Foundation / Dev 1 Handoff

## Scope delivered

This branch adds the first Flutter application layer alongside the existing Laravel project:

- `lib/main.dart`, `lib/bootstrap.dart`, and `lib/app.dart` wire startup, GetIt, Bloc, GoRouter, Arabic localization/RTL, Tajawal, and light/dark themes.
- `lib/core/` contains environment/API configuration, Dio and auth/error/offline-cache interceptors, secure session storage, permission helpers, shared fields/buttons/status/empty/loading/dialog widgets, RTL-safe numeric/search helpers, responsive breakpoints/scaffolds, and route names.
- SCR-01 login, SCR-02 profile edit, a home shell, and SCR-28 sync monitor are implemented as the shared starting screens.
- `lib/features/offline_sync/` exposes a repository interface, UUID/idempotency-aware read/payment queue manager, network-triggered retry, account ownership checks, and a monitor UI.
- The API routes/controllers were expanded under `routes/api.php` and `app/Http/Controllers/Api/`; payments and readings accept idempotency UUIDs.

The current queue repository is deliberately a **temporary SharedPreferences adapter** behind `SyncQueueRepository`. Drift/`drift_flutter` and code-generation tooling are included in `pubspec.yaml`, but the shared generated database/schema has not yet been added. Replace the temporary adapter with the team's shared Drift/SQLite database before using this for production field operations or sensitive data. Keep the queue API and account-owner checks intact when replacing persistence.

## Run/configure

Flutter/Dart are not installed in the current workspace, so dependency resolution, analyzer, emulator builds, and Flutter tests have **not** been run here. When an SDK is available:

```sh
flutter pub get
flutter analyze
flutter test
```

API base URL is provided at build time:

```sh
flutter run --dart-define=API_BASE_URL=https://your-host.example/api
```

The default is `http://10.0.2.2:8000/api` for an Android emulator. Set the LAN/HTTPS URL for a physical device (and configure Android's development cleartext policy if using HTTP). This repository still needs the platform runner folders (Android/iOS/web) generated or merged from the project's Flutter template before a device build.

Backend requirements: apply the migrations `2026_10_23_000001_add_offline_sync_fields_to_readings_table.php` and `2026_10_23_000002_add_sync_uuid_to_payments_table.php`; then run the normal Laravel setup and verify with the project's PHP/composer toolchain. The local WhatsApp bridge directory was absent during implementation; bridge-control routes cannot operate until that service exists.

## API contracts for feature teams

All paths below are relative to `/api` and except login are protected by `auth:sanctum`. Responses and validation should be confirmed against the deployed Laravel version before wiring more screens.

| Flow | Contract |
|---|---|
| Login | `POST /login` JSON `{ "email": "…", "password": "…" }` → `{ access_token, token_type, user: { id, name, email, avatar, roles: [], permissions: [] } }` |
| Session | `GET /me` (legacy `GET /user`) → `{ user: … }`; `POST /logout` revokes the current Sanctum token. |
| Profile | `POST` or `PUT /profile`; multipart or JSON fields `name`, `email`, optional `password`, `password_confirmation`, `avatar` (image, max 5 MB) → `{ message, user }`. |
| Offline reading | `POST /readings/sync-batch` with `{ readings: [{ meter_id, reading_date, current_reading, sync_uuid, … }] }`; `local_image_path` is device-only and the adapter sends an existing image as `readings[0][image]`. Response has `results[]` with `success`, `duplicate`, reading/invoice fields or `message`. |
| Offline payment | `POST /invoices/pay` JSON `{ invoice_id, amount, payment_method: "cash"|"bank_transfer"|"check", payment_date?, reference_number?, reason?, notes?, sync_uuid }` → `{ message, payment, invoice_status, receipt_data }`. `sync_uuid` is a UUID v4 generated once and reused unchanged on every retry. |
| Permission payload | Use the server-returned `roles` and Spatie permission `permissions` names. UI guards are presentation only; Laravel remains the authorization source of truth. |

`SyncQueueManager.enqueueReading` and `.enqueuePayment` expect plain-JSON payloads (for photos use `local_image_path`) and require a ready, authenticated session. Each queued operation is bound to the creating user ID; a different account cannot submit it. Feature modules should depend on `SyncQueueManager`/`SyncQueueRepository`, not the SharedPreferences implementation.

## Ownership and integration notes

- The requested sub-agent tools were not available in this session; integration decisions and contracts are recorded here for the other engineers.
- The updated `main` base also contains a separate `mobile/` Flutter package from the SCR feature work. This foundation remains at the repository root per the initial plan; before product rollout, decide whether to consolidate the root app/core into `mobile/` or keep both packages intentionally.
- Dev 1 owns `lib/core/`, `lib/main.dart`, `lib/app.dart`, `lib/bootstrap.dart`, `lib/core/router/`, the auth session/login/profile foundation, and the shared sync monitor contract.
- Feature teams should add their APIs/repositories/screens within their own feature directories, inject Dio/permissions/queue through GetIt, and must not create a second router, network client, or app root.
- Use `AppNumericField` for amounts, phone numbers, IDs, and compound square/path input. It fixes direction to LTR and normalizes Arabic/Persian digits. Use `AppTextField` with explicit `TextDirection.ltr` for email/password/phone where appropriate.
- Keep API endpoint paths slash-free in `ApiEndpoints` because Dio's base URL already includes `/api/`.
- `OfflineCacheInterceptor` is opt-in per GET (`Options(extra: {'offlineCache': true})`); it is an in-memory stale read cache, not a replacement for the shared local database.

## Verification performed / not performed

- Dart source was parsed with tree-sitter: **46 Dart files, no syntax nodes reported as errors** at the time of the check. This is not a substitute for `dart analyze` or Flutter compilation.
- Targeted PHP source was parsed with tree-sitter: **24 API/migration/service/model files, no syntax nodes reported as errors** at the time of the check. A static route scan matched **152 explicit/resource route actions** to controller methods with no missing targets. These checks do not validate Laravel runtime behavior or SQL/migration compatibility.
- Two Flutter test files cover Arabic/Persian digit/decimal conversion and compound square/path search. They are present but were not executed.
- `pubspec.yaml` and `analysis_options.yaml` parse as valid YAML, and `git diff --check` reports no whitespace errors.
- `flutter pub get`, `flutter analyze`, `flutter test`, PHP/Composer tests, `artisan route:list`, and DB migrations were not run because the corresponding toolchains/dependencies are absent.
