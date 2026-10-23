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

See [RELEASE_NOTES.md](RELEASE_NOTES.md) for local-network setup and limitations.
The GitHub Actions workflow builds Android and Windows and publishes a testing
prerelease only after both builds and Android's analyzer/test gates succeed.
It runs on the Arena working branch or manually, not on arbitrary pull requests.
Production signing is deliberately not implied: configure a persistent private
keystore through GitHub Actions secrets before shipping a production build.
Never commit signing keys or backend credentials.

## Remaining acceptance work

- Run the master plan's full device/backend acceptance checklist.
- Consolidate/audit the two offline queue implementations; the shared auth queue
  still uses SharedPreferences, not the field module's SQLite store.
- Exercise login, permission enforcement, account switching, read/payment retries,
  printing, camera and Windows platform support against a test backend.
- Resolve remaining analyzer warnings and replace debug signing for production.
