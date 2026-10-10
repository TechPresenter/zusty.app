import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// The Play upload key. android/key.properties points at the keystore and holds
// its passwords; both are git-ignored and must be backed up somewhere safe —
// see https://flutter.dev/to/reference-keystore.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

// A release build without the upload key would fall back to the debug key and
// only be rejected when it is uploaded to Play. Stop it here instead. Set
// ZUSTE_ALLOW_DEBUG_SIGNED_RELEASE=true to build a debug-signed release on
// purpose, e.g. `flutter run --release` on a checkout without the key.
val releaseRequested = gradle.startParameter.taskNames.any {
    it.contains("Release", ignoreCase = true)
}
val debugSignedReleaseAllowed =
    System.getenv("ZUSTE_ALLOW_DEBUG_SIGNED_RELEASE") == "true"
if (releaseRequested && !keystorePropertiesFile.exists() && !debugSignedReleaseAllowed) {
    throw GradleException(
        "android/key.properties is missing: a release build must be signed " +
            "with the upload key. See mobile/README.md, or set " +
            "ZUSTE_ALLOW_DEBUG_SIGNED_RELEASE=true for a local-only build."
    )
}

android {
    namespace = "com.zuste.zuste"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        // Permanent once the app is on Google Play: keep it.
        applicationId = "com.zuste.zuste"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (keystorePropertiesFile.exists()) {
            create("release") {
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
            }
        }
    }

    buildTypes {
        release {
            // Signed with the upload key when android/key.properties is present.
            // Without it the build stops (above) unless a debug-signed release
            // was explicitly allowed — such a build is NOT accepted by Google
            // Play.
            signingConfig = if (keystorePropertiesFile.exists()) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
        }
    }
}

flutter {
    source = "../.."
}
