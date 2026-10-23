# Water Flutter application

The single Flutter project lives here; Laravel stays at the repository root.
Android and Windows runners, Dart source, tests and bundled fonts all live inside
`mobile app/`. Historical handoff/plan paths are relative to this folder now.

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
The GitHub Actions workflow builds Android and Windows and publishes a testing
prereleases per platform, directly to Releases (without Actions artifact storage).
Android publication requires its build and analyzer/test gates to succeed.
Windows publication requires its own build to succeed.
It runs on the Arena working branch or manually, not on arbitrary pull requests.
Production signing is deliberately not implied: configure a persistent private
keystore through GitHub Actions secrets before shipping a production build.
Never commit signing keys or backend credentials.

## Workspace and feature coverage

The Arabic home is task-first; **الخدمات** opens the searchable, permission-aware
service directory. See [FEATURE_COVERAGE.md](FEATURE_COVERAGE.md) for the Laravel
module inventory, delivered UX/workflow changes, and remaining parity checks.

## Remaining acceptance work

- Run the master plan's full device/backend acceptance checklist.
- Consolidate/audit the two offline queue implementations; the shared auth queue
  still uses SharedPreferences, not the field module's SQLite store.
- Exercise login, permission enforcement, account switching, read/payment retries,
  printing, camera and Windows platform support against a test backend.
- Resolve remaining analyzer warnings and replace debug signing for production.
