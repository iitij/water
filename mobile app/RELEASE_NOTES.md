# Water — testing prerelease

Testing builds of the integrated Arabic Water app. Android APKs are attached to
`mobile-test-*` releases; Windows ZIPs are attached to `windows-test-*` releases.

## What's new in this prerelease

- A full interface redesign: new design system, light/dark themes and a calmer,
  higher-contrast Arabic layout.
- Five-tab navigation (Overview, Field, Billing, Subscribers, More) replacing the
  single long home page, plus redesigned dashboard, login, splash and sync
  screens.
- Richer dashboard widgets: KPI cards with sparklines, collection health ring,
  revenue chart summary and actionable overdue-invoice cards.

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
The server is saved on first launch. Changing servers requires clearing app data
after synchronizing pending work, preventing credentials/queues crossing servers.

Extract the whole Windows ZIP and run `water_project.exe`; keep the DLLs and data
folder alongside it. Windows camera, Bluetooth, printing and platform plugins
need separate hardware testing. The APK build does not verify those operations.

Automated analyzer errors and Flutter tests gate Android publication, but legacy lint
warnings are not yet blocking. Full master-plan acceptance, backend integration,
physical-device testing, secure offline persistence and production signing remain
release-readiness work. No production-readiness claim is made by this prerelease.
