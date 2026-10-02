import 'dart:io' show Platform;

import 'package:firebase_auth/firebase_auth.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';

import '../../models/auth_config.dart';

/// Raised when a social sign-in cannot proceed, with a message meant for the
/// user rather than the console.
class SocialSignInException implements Exception {
  const SocialSignInException(this.message, {this.cancelled = false});

  final String message;

  /// The person backed out of the provider's own sheet. Callers should stay
  /// quiet rather than showing an error for something the user chose.
  final bool cancelled;

  @override
  String toString() => message;
}

/// Turns a tap on "Continue with Google" into a Firebase ID token the backend
/// will accept at `POST /auth/firebase`.
///
/// Every credential comes from [AuthConfig] — nothing is compiled in. The
/// client IDs, the Apple service ID and redirect URI, and the Firebase options
/// are all what the admin panel is currently serving, so an operator can point
/// the app at a different Google project without a release.
///
/// What an operator **cannot** change from the panel, and why:
///
///   * `google-services.json` / `GoogleService-Info.plist` — read by the native
///     SDK before Dart runs. This app ships neither, which is precisely why
///     passing [FirebaseOptions] explicitly works.
///   * Android signing SHA-1/SHA-256 — belongs to the keystore that signs the
///     APK and must be registered in the Google console.
///   * The iOS `com.apple.developer.applesignin` entitlement and the reversed
///     client ID URL scheme in `Info.plist`.
///
/// Those are recorded in the panel for reference and flagged there as
/// build-time, so nobody changes one and expects an installed app to follow.
class SocialSignInService {
  const SocialSignInService();

  static bool _firebaseReady = false;
  static bool _googleInitialised = false;
  static String _googleInitialisedFor = '';

  /// Start Firebase from server-supplied options.
  ///
  /// Safe to call more than once and safe to call with an unusable config — it
  /// simply reports that Firebase is unavailable, and the app carries on with
  /// email/password and OTP, which need no Firebase at all.
  static Future<bool> ensureFirebase(FirebaseConfig config) async {
    if (_firebaseReady) return true;

    if (!config.isUsable) {
      debugPrint(
        '[Zusty] Firebase is not configured on the server '
        '(project/api key/app id incomplete); social sign-in is unavailable.',
      );
      return false;
    }

    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp(
          options: FirebaseOptions(
            apiKey: config.apiKey,
            appId: config.appId,
            messagingSenderId: config.messagingSenderId,
            projectId: config.projectId,
            authDomain: config.authDomain.isEmpty ? null : config.authDomain,
            storageBucket:
                config.storageBucket.isEmpty ? null : config.storageBucket,
          ),
        );
      }

      _firebaseReady = true;
      return true;
    } catch (e) {
      // A bad option set must not stop the app booting — the sign-in methods
      // that do not need Firebase still have to work.
      debugPrint('[Zusty] Firebase failed to start: $e');
      return false;
    }
  }

  /// Sign in with Google and return a **Firebase** ID token.
  ///
  /// The backend verifies Firebase tokens, not Google ones, so the Google
  /// credential is exchanged through Firebase first.
  Future<String> google(AuthConfig config) async {
    final method = config.method('google');

    if (method == null || !method.enabled) {
      throw const SocialSignInException('Google sign-in is currently unavailable.');
    }

    if (!await ensureFirebase(config.firebase)) {
      throw const SocialSignInException(
        'Google sign-in is not available in this build yet.',
      );
    }

    final clientId = method.string('client_id');
    final serverClientId = method.string('server_client_id');

    if (clientId.isEmpty && serverClientId.isEmpty) {
      throw const SocialSignInException(
        'Google sign-in has not been configured yet.',
      );
    }

    try {
      // initialize() may be called exactly once per process, and the client id
      // could change if an operator repoints the app, so the id it ran with is
      // remembered and a change is reported rather than silently ignored.
      if (!_googleInitialised) {
        await GoogleSignIn.instance.initialize(
          clientId: clientId.isEmpty ? null : clientId,
          serverClientId: serverClientId.isEmpty ? null : serverClientId,
        );
        _googleInitialised = true;
        _googleInitialisedFor = clientId;
      } else if (_googleInitialisedFor != clientId && clientId.isNotEmpty) {
        debugPrint(
          '[Zusty] The Google client ID changed after sign-in was initialised; '
          'restart the app for it to take effect.',
        );
      }

      final account = await GoogleSignIn.instance.authenticate(
        scopeHint: method.strings('scopes'),
      );

      final googleIdToken = account.authentication.idToken;

      if (googleIdToken == null || googleIdToken.isEmpty) {
        throw const SocialSignInException(
          'Google did not return a usable token. Please try again.',
        );
      }

      return _exchangeWithFirebase(
        GoogleAuthProvider.credential(idToken: googleIdToken),
      );
    } on GoogleSignInException catch (e) {
      if (e.code == GoogleSignInExceptionCode.canceled) {
        throw const SocialSignInException('Sign-in cancelled.', cancelled: true);
      }
      throw SocialSignInException(_readable(e.description ?? e.code.name));
    } on FirebaseAuthException catch (e) {
      throw SocialSignInException(_readable(e.message ?? e.code));
    }
  }

  /// Sign in with Apple and return a Firebase ID token.
  ///
  /// `platforms` in the config keeps this off Android, where the plugin has no
  /// native implementation and the web fallback needs the Service ID.
  Future<String> apple(AuthConfig config) async {
    final method = config.method('apple');

    if (method == null || !method.enabled) {
      throw const SocialSignInException(
        'Sign in with Apple is currently unavailable.',
      );
    }

    if (!await ensureFirebase(config.firebase)) {
      throw const SocialSignInException(
        'Sign in with Apple is not available in this build yet.',
      );
    }

    final serviceId = method.string('service_id');
    final redirectUri = method.string('redirect_uri');

    // On anything that is not iOS/macOS the plugin needs the web flow, which
    // cannot start without both of these.
    final needsWebFlow = kIsWeb || !(Platform.isIOS || Platform.isMacOS);

    if (needsWebFlow && (serviceId.isEmpty || redirectUri.isEmpty)) {
      throw const SocialSignInException(
        'Sign in with Apple has not been configured for this platform yet.',
      );
    }

    try {
      final credential = await SignInWithApple.getAppleIDCredential(
        scopes: [
          AppleIDAuthorizationScopes.email,
          AppleIDAuthorizationScopes.fullName,
        ],
        webAuthenticationOptions: needsWebFlow
            ? WebAuthenticationOptions(
                clientId: serviceId,
                redirectUri: Uri.parse(redirectUri),
              )
            : null,
      );

      final appleIdToken = credential.identityToken;

      if (appleIdToken == null || appleIdToken.isEmpty) {
        throw const SocialSignInException(
          'Apple did not return a usable token. Please try again.',
        );
      }

      return _exchangeWithFirebase(
        OAuthProvider('apple.com').credential(
          idToken: appleIdToken,
          accessToken: credential.authorizationCode,
        ),
      );
    } on SignInWithAppleAuthorizationException catch (e) {
      if (e.code == AuthorizationErrorCode.canceled) {
        throw const SocialSignInException('Sign-in cancelled.', cancelled: true);
      }
      throw SocialSignInException(_readable(e.message));
    } on FirebaseAuthException catch (e) {
      throw SocialSignInException(_readable(e.message ?? e.code));
    }
  }

  /// Hand a provider credential to Firebase and read back its ID token, which
  /// is what `POST /auth/firebase` verifies.
  Future<String> _exchangeWithFirebase(AuthCredential credential) async {
    final result = await FirebaseAuth.instance.signInWithCredential(credential);
    final token = await result.user?.getIdToken();

    if (token == null || token.isEmpty) {
      throw const SocialSignInException(
        'Could not complete sign-in. Please try again.',
      );
    }

    return token;
  }

  static String _readable(String raw) =>
      raw.trim().isEmpty ? 'Sign-in failed. Please try again.' : raw.trim();
}
