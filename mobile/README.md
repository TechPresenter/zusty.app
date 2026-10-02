# Zusty mobile app

The Flutter app for brands and creators (Android, iOS and web). It talks to the
PHP API in `../backend`; the API origin is fixed at build time.

## Build-time settings

| Define                  | Default                 | Release value        |
| ----------------------- | ----------------------- | -------------------- |
| `ZUSTE_API_ORIGIN`      | `http://localhost:8000` | `https://zusty.app`  |
| `ZUSTE_DEEP_LINK_HOST`  | `zuste.app`             | `zusty.app`          |
| `ZUSTE_VERBOSE_NETWORK` | `true` (debug only)     | `false`              |

## Release build (Google Play)

Release builds are signed with the upload key in `android/upload-keystore.jks`.
`android/key.properties` holds its alias and passwords. Both files are
git-ignored. **Keep a copy of both somewhere safe**: if they are lost, Google
has to reset the upload key before you can ship another update. Without
`key.properties` the build falls back to the debug key, and Play rejects that
build.

1. Raise `version:` in `pubspec.yaml`. The number after `+` is the Android
   versionCode, and every upload to Play needs a higher one.
2. Build the bundle for Play, and an APK if you need one for direct installs:

   ```sh
   flutter build appbundle --release \
     --dart-define=ZUSTE_API_ORIGIN=https://zusty.app \
     --dart-define=ZUSTE_DEEP_LINK_HOST=zusty.app \
     --dart-define=ZUSTE_VERBOSE_NETWORK=false

   flutter build apk --release   # same three --dart-define flags
   ```

   Outputs: `build/app/outputs/bundle/release/app-release.aab` and
   `build/app/outputs/flutter-apk/app-release.apk`. Gradle 8.14 does not run
   on Java 25, so point Gradle at a JDK from 17 to 24 if it fails to start.
3. Upload the `.aab` to Play Console. New apps must ship bundles; the APK is
   only for sideloading and testing.

Upload key fingerprints, which Firebase needs for Google sign-in:

- SHA-1 `8F:7F:34:A7:E4:6A:8F:86:41:04:00:33:DA:BE:E4:75:CE:7F:65:F2`
- SHA-256 `0C:E8:4E:C6:DC:45:82:82:91:11:B9:D0:FD:C0:61:65:3F:59:C7:DA:29:BB:0B:7A:2F:19:F7:DB:B5:99:63:F9`

After the first upload, also register the Play app signing key's fingerprints
(Play Console → Test and release → App integrity). Users who install from Play
get builds signed with that key, not the upload key.
