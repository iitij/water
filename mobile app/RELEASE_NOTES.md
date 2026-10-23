# Water — testing prerelease

Testing builds of the integrated Arabic Water app. Android APKs are attached to
`mobile-test-*` releases; Windows ZIPs are attached to `windows-test-*` releases.

**Testing only — not a production release.** Android uses the generated debug
signing key, so a later build may require uninstalling the previous APK. Sync
pending operations before uninstalling; uninstalling deletes local app data.
Do not use this build for live financial collection until device/backend QA passes.

## Connect to a local Laravel server

1. On the PC, install/configure Laravel dependencies and database, apply migrations,
   then run `php artisan serve --host=0.0.0.0 --port=8000`.
2. Allow port 8000 through the PC firewall on your trusted private network only.
3. First launch prompts for the API URL:
   - Windows on the backend PC: `http://localhost:8000/api`.
   - Physical Android phone: `http://<PC-LAN-IP>:8000/api`; use the same Wi-Fi.
   - Android emulator: `http://10.0.2.2:8000/api`.
4. Sign in using an existing backend account. There are no bundled credentials.

`localhost` on a phone refers to the phone, not your PC. HTTP is enabled for
local testing and is not encrypted. Use HTTPS before production.

The server is saved on first launch. It can be changed later from the login screen
(server button) or **الملف الشخصي ← الاتصال بالخادم** without clearing app data:
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
