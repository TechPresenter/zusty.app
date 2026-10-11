# Zusty mobile app

The Flutter app for brands and creators (Android, iOS and web). It talks to the
PHP API in `../backend`; the API origin is fixed at build time.

## Build-time settings

| Define                  | Debug / profile default | Release default      |
| ----------------------- | ----------------------- | -------------------- |
| `ZUSTE_API_ORIGIN`      | `http://localhost:8000` | `https://zusty.app`  |
| `ZUSTE_DEEP_LINK_HOST`  | `zusty.app`             | `zusty.app`          |
| `ZUSTE_VERBOSE_NETWORK` | `true`                  | `false`              |

A release build with no defines therefore talks to production. A release
Android or iOS build whose `ZUSTE_API_ORIGIN` is not `https://` refuses to
start (`main.dart`), because the phone would block every cleartext request.
The web build is exempt, so a local web QA build still works, but it now
needs the local origin passed explicitly:

```sh
flutter build web --release --no-wasm-dry-run \
  --dart-define=ZUSTE_API_ORIGIN=http://localhost:8000
```

## Release build (Google Play)

Release builds are signed with the upload key in `android/upload-keystore.jks`.
`android/key.properties` holds its alias and passwords. Both files are
git-ignored. **Keep a copy of both somewhere safe**: if they are lost, Google
has to reset the upload key before you can ship another update. Without
`key.properties` a release build stops with an error rather than falling back
to the debug key, which Play would reject. To build a debug-signed release on
purpose (for example `flutter run --release` on a checkout without the key),
set `ZUSTE_ALLOW_DEBUG_SIGNED_RELEASE=true`.

1. Raise `version:` in `pubspec.yaml`. The number after `+` is the Android
   versionCode, and every upload to Play needs a higher one.
2. Build the bundle for Play, and an APK if you need one for direct installs.
   The defines repeat the release defaults so the command says what it ships:

   ```sh
   flutter build appbundle --release \
     --obfuscate --split-debug-info=build/symbols \
     --dart-define=ZUSTE_API_ORIGIN=https://zusty.app \
     --dart-define=ZUSTE_DEEP_LINK_HOST=zusty.app \
     --dart-define=ZUSTE_VERBOSE_NETWORK=false

   flutter build apk --release   # same flags
   ```

   Outputs: `build/app/outputs/bundle/release/app-release.aab` and
   `build/app/outputs/flutter-apk/app-release.apk`. Gradle 8.14 does not run
   on Java 25, so point Gradle at a JDK from 17 to 24 if it fails to start.
3. Copy `build/symbols` somewhere safe under the version's name (for example
   `release/symbols-1.0.1+2`) for every version you ship: obfuscated Dart
   stack traces (in Crashlytics or a bug report) can only be read with the
   symbols of that exact build (`flutter symbolize`). Upload them to
   Crashlytics with `firebase crashlytics:symbols:upload --app=<android app id>
   build/symbols`.
4. Upload the `.aab` to Play Console. New apps must ship bundles; the APK is
   only for sideloading and testing. In App bundle explorer → Downloads, check
   that the R8 `mapping.txt` and `native-debug-symbols.zip` are attached to the
   version, and upload them from `build/app/outputs/` (`mapping/release/` and
   `native-debug-symbols/release/`) if not.
5. Check the merged manifest
   (`build/app/intermediates/merged_manifests/release/processReleaseManifest/AndroidManifest.xml`)
   contains `android:dataExtractionRules` and no `AD_ID`, `USE_BIOMETRIC` or
   `USE_FINGERPRINT` permission.

Upload key fingerprints, which Firebase needs for Google sign-in:

- SHA-1 `8F:7F:34:A7:E4:6A:8F:86:41:04:00:33:DA:BE:E4:75:CE:7F:65:F2`
- SHA-256 `0C:E8:4E:C6:DC:45:82:82:91:11:B9:D0:FD:C0:61:65:3F:59:C7:DA:29:BB:0B:7A:2F:19:F7:DB:B5:99:63:F9`

After the first upload, also register the Play app signing key's fingerprints
(Play Console → Test and release → App integrity). Users who install from Play
get builds signed with that key, not the upload key.

## What the release build depends on from the server

- **Sign-in with Google** appears only when `/app/config` serves complete
  Firebase options and, on Android, a Google `server_client_id` (the Web
  client ID). Until then the button is hidden.
- **Push** starts once those Firebase options are served and the server has
  the Firebase service account. Notifications use the `zuste_default` channel
  the app creates, and a tap opens what the notification is about.
- **Payments** are offered only through gateways this build can complete:
  Cashfree (hosted checkout) and the Zusty wallet. Razorpay and Stripe need
  native SDKs the app does not ship, so they are hidden even when enabled.
- **Crash reports** go to Firebase Crashlytics from release builds only.

## Large screens

The layouts are designed for phones. On tablets they are centred rather than
stretched, and on phones (under 600dp wide) nothing changes: every rule below
is skipped for a compact window.

- **Size classes** (`lib/core/layout/breakpoints.dart`): compact under 600dp,
  medium 600 to 839, expanded 840 and up. `ContentWidth` holds the caps:
  720 (`readable`) for forms, details, chat, settings and single-column
  lists; 1040 (`wide`) for grids and two-column lists.
- **Pages**: `ZScaffold` centres its app bar, body and bottom bar in one
  column `maxContentWidth` wide (readable by default; pass
  `ContentWidth.wide` for a grid page), with the page background either
  side. Screens with their own `Scaffold` wrap the body in `ContentWidthBox`
  and the app bar in `ContentWidthAppBar`
  (`lib/shared/widgets/adaptive_layout.dart`). The sign-in screens and
  onboarding keep their 560dp column. Page margins are 24dp on tablets.
- **Navigation**: the bottom bar keeps its buttons in a 560dp group on a
  medium window and becomes a `NavigationRail` (with the Create button on
  top) on an expanded one. The rail scrolls in a very short window, and the
  keyboard is left to the tab page's own `Scaffold`, so it never squeezes
  the rail.
- **Lists and grids**: `CreatorGridDelegate` adds columns on a tablet
  (cards at least 200dp) instead of enlarging cards. `AdaptiveCardList` and
  `SliverAdaptiveCardList` put cards two to a row once a list is 700dp wide
  (briefs, search results, saved creators). On a grid page, search fields
  keep the readable width and the home banners show two at a time (from a
  760dp column), so their photos keep roughly a phone's crop.
- **Sheets and dialogs** are capped at 640dp (the theme).
- **Orientation**: phones stay portrait. A device whose shorter side is
  600dp or more may also rotate (`OrientationPolicy`). `main` applies it
  before the view may have been measured, so `OrientationPolicyHost` at the
  root applies it again once the app is up and on every window change (a
  foldable opening or closing). In landscape the launch splash shows the
  whole artwork instead of cropping it.
- **Tests**: `test/large_screen_layout_test.dart` renders the main screens at
  390x844, 599x900, 600x960, 800x1280, 1280x800 and 960x600. It checks for
  overflow, that each column on a tablet is centred and fills its cap, and
  that phones keep the phone rules (the 599/600 line is tested directly).
  It also opens the keyboard in landscape, runs real tab pages inside the
  shell, and checks the orientation policy at startup.
