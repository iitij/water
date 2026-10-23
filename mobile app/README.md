# Water Flutter application

The single Flutter project lives here; Laravel stays at the repository root.
Android and Windows runners, Dart source, tests and bundled fonts all live inside
`mobile app/`. Historical handoff/plan paths are relative to this folder now.

## Interface & design system

The Flutter client now ships a complete in-house design system:

- **Five-tab navigation** — Overview, Field, Billing, Subscribers and More — with
  a floating pill bar on phones and a branded side rail on wide screens.
- **A reusable widget kit** (`lib/core/ui`) for cards, gradient heroes, KPI
  cards, module tiles, pills, avatars, progress rings, sparklines and notices.
- **A catalog-driven module map** (`lib/core/navigation/module_catalog.dart`) so
  new features appear automatically in the correct hub with permission-aware
  visibility.
- **Light / dark / system themes** persisted across launches, with a one-tap
  toggle in the header.

Read [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) for the palette, typography, component
inventory and navigation map.

## Development

Install Flutter **3.44.0** (Dart 3.12), Java 17 and the Android SDK. For Windows,
use Windows with Visual Studio's Desktop development with C++ workload.

```sh
cd "mobile app"
flutter pub get --enforce-lockfile
flutter analyze --no-fatal-infos --no-fatal-warnings
flutter test
flutter run
# Optional first-launch suggested server:
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api
flutter build apk --release
flutter build windows --release
```

The Flutter tool regenerates the ignored Gradle wrapper launchers/JAR on build.
The committed Gradle configuration targets AGP 9 and Flutter 3.44.

## App icons

The launcher/taskbar icons for Android, Windows and Linux are generated from the
SVG sources in [`assets/icons/`](assets/icons/README.md):

```sh
cd tool && npm install && node generate_icons.mjs
```

That writes the Android mipmaps and adaptive-icon XML, the Windows
`windows/runner/resources/app_icon.ico` and the Linux hicolor set. Do not edit the
generated PNG/ICO files by hand; change the SVGs and re-run.

See [RELEASE_NOTES.md](RELEASE_NOTES.md) for local-network setup and limitations.
The GitHub Actions workflow builds Android and Windows and publishes testing
prereleases per platform, directly to Releases (without Actions artifact storage).
Analyzer and tests run on both jobs; publication is skipped for pull requests.
It runs on `main`/`master`/`develop`, any `arena/**` branch, and manually.
Production signing is deliberately not implied: configure a persistent private
keystore through GitHub Actions secrets before shipping a production build.
Never commit signing keys or backend credentials.

## Workspace and feature coverage

The Arabic home is task-first; **الخدمات** opens the searchable, permission-aware
service directory. See [FEATURE_COVERAGE.md](FEATURE_COVERAGE.md) for the Laravel
module inventory, delivered UX/workflow changes, and remaining parity checks.

See [../docs/MOBILE_PHASE1_ARCHITECTURE.md](../docs/MOBILE_PHASE1_ARCHITECTURE.md)
for the unified offline-queue design, account isolation rules and server-switch guard.

## Remaining acceptance work

- Run the master plan's full device/backend acceptance checklist.
- Exercise login, permission enforcement, account switching, read/payment retries,
  printing, camera and Windows platform support against a test backend.
- Physical Bluetooth 58/80 mm printing, barcode camera, and the Node WhatsApp bridge.
- Resolve remaining analyzer warnings (mostly legacy deprecations) and replace
  debug signing with a production keystore + Play App Signing.
- Enforce HTTPS in release builds (`usesCleartextTraffic` is still enabled for LAN QA).
