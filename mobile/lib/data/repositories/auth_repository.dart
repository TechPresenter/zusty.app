import '../../core/network/api_endpoints.dart';
import '../../core/network/api_failure.dart';
import '../../core/storage/key_value_cache.dart';
import '../../core/storage/secure_store.dart';
import '../../models/privacy.dart';
import '../../models/user.dart';
import 'base_repository.dart';

/// The result of a successful sign-in or registration.
class AuthResult {
  const AuthResult({
    required this.user,
    required this.tokens,
    this.isNewAccount = false,
  });

  final User user;
  final AuthTokens? tokens;
  final bool isNewAccount;
}

/// Authentication and session management (`/auth/*`).
class AuthRepository extends BaseRepository {
  AuthRepository(super.client, this._store, this._cache);

  final SecureStore _store;
  final KeyValueCache _cache;

  bool get hasCompletedOnboarding => _cache.hasCompletedOnboarding;

  Future<void> completeOnboarding() => _cache.setOnboardingComplete();

  Future<AuthResult> login({
    required String identifier,
    required String password,
  }) =>
      _authenticate(() async {
        final response = await client.post(
          ApiEndpoints.login,
          body: {'identifier': identifier, 'password': password},
          skipAuth: true,
        );
        return _parse(response.dataMap);
      });

  /// Email sign-up. Every account is made with an email address; there are
  /// no phone-only accounts.
  Future<AuthResult> register({
    required String name,
    required String email,
    required String password,
    required UserRole role,
    String? referralCode,
    bool acceptTerms = true,
    bool marketingOptIn = false,
  }) =>
      _authenticate(() async {
        final response = await client.post(
          ApiEndpoints.register,
          body: {
            'name': name,
            'email': email,
            'password': password,
            // The server validates `account_type`, not `role`. Unknown keys are
            // dropped silently by the validator, so sending `role` meant every
            // sign-up was created as the default brand.
            'account_type': role.name,
            // Required by the server on every sign-up. Omitting it 422'd
            // every registration.
            'accept_terms': acceptTerms,
            // The unticked "product updates and offers" box. Marketing stays
            // off unless it was ticked.
            'marketing_opt_in': marketingOptIn,
            if (referralCode != null && referralCode.isNotEmpty)
              'referral_code': referralCode,
          },
          skipAuth: true,
        );
        return _parse(response.dataMap, isNew: true);
      });

  /// Exchanges a Firebase ID token (Google / Apple / phone) for Zusty JWTs.
  ///
  /// A first sign-in creates the account, and the server then needs
  /// [acceptTerms] (422 `terms_required` without it). Send it only from
  /// beside the "By continuing, you agree to the Terms and Privacy Policy"
  /// notice. An existing account signs in either way.
  Future<AuthResult> signInWithFirebase(
    String idToken, {
    UserRole? role,
    bool acceptTerms = false,
  }) =>
      _authenticate(() async {
        final response = await client.post(
          ApiEndpoints.firebaseSignIn,
          body: {
            'id_token': idToken,
            if (role != null) 'account_type': role.name,
            if (acceptTerms) 'accept_terms': true,
          },
          skipAuth: true,
        );
        final data = response.dataMap;
        return _parse(data, isNew: data['is_new_account'] == true);
      });

  /// Verifies a one-time code and, for `purpose: login`, returns a session.
  Future<AuthResult> verifyOtp({
    required String destination,
    required String code,
    String purpose = 'login',
  }) =>
      _authenticate(() async {
        final response = await client.post(
          ApiEndpoints.verifyOtp,
          body: {
            'destination': destination,
            'code': code,
            'purpose': purpose,
          },
          skipAuth: true,
        );
        return _parse(response.dataMap);
      });

  /// Starts a guest session so someone can browse without an account.
  ///
  /// Throws when the operator has switched guest access off; the caller should
  /// read `auth.guest.enabled` from the app config and not offer the button.
  Future<AuthResult> signInAsGuest() => _authenticate(() async {
        final response = await client.post(
          ApiEndpoints.guestSignIn,
          skipAuth: true,
        );
        return _parse(response.dataMap);
      });

  /// Turns the current guest session into a real account, in place.
  ///
  /// A sign-up like any other: the terms are accepted, an email address is
  /// given (or comes with the Google sign-in), and marketing stays off unless
  /// [marketingOptIn].
  Future<AuthResult> upgradeGuest({
    required UserRole role,
    String? name,
    String? email,
    String? phone,
    String? password,
    String? idToken,
    String? destination,
    String? code,
    String? referralCode,
    bool acceptTerms = true,
    bool marketingOptIn = false,
  }) =>
      _authenticate(() async {
        final response = await client.post(
          ApiEndpoints.guestUpgrade,
          body: {
            'account_type': role.name,
            'accept_terms': acceptTerms,
            'marketing_opt_in': marketingOptIn,
            if (name != null && name.isNotEmpty) 'name': name,
            if (email != null && email.isNotEmpty) 'email': email,
            if (phone != null && phone.isNotEmpty) 'phone': phone,
            if (password != null && password.isNotEmpty) 'password': password,
            if (idToken != null && idToken.isNotEmpty) 'id_token': idToken,
            if (destination != null && destination.isNotEmpty)
              'destination': destination,
            if (code != null && code.isNotEmpty) 'code': code,
            if (referralCode != null && referralCode.isNotEmpty)
              'referral_code': referralCode,
          },
        );
        return _parse(response.dataMap, isNew: true);
      });

  /// Sends a one-time code to [destination]: an email address for `channel:
  /// 'email'` (the default — sign-in codes go by email), a mobile number for
  /// `'sms'`, offered only when the server lists it.
  ///
  /// Returns how many seconds the server wants before another code is asked
  /// for (`resend_in`), or null when it did not say.
  ///
  /// Not routed through [writeThrough]: swallowing the failure would leave the
  /// user staring at a code entry screen for a code that was never sent.
  Future<int?> sendOtp(
    String destination, {
    String channel = 'email',
    String purpose = 'login',
  }) async {
    final response = await client.post(
      ApiEndpoints.sendOtp,
      body: {
        'destination': destination,
        'channel': channel,
        'purpose': purpose,
      },
      skipAuth: true,
    );
    final resendIn = response.dataMap['resend_in'];
    return resendIn is num ? resendIn.toInt() : null;
  }

  Future<void> forgotPassword(String email) async {
    // The server validates `email`, not `identifier`.
    await client.post(
      ApiEndpoints.forgotPassword,
      body: {'email': email},
      skipAuth: true,
    );
  }

  Future<void> resetPassword({
    required String token,
    required String password,
  }) async {
    await client.post(
      ApiEndpoints.resetPassword,
      body: {
        'token': token,
        'password': password,
        // The rule is `confirmed`, so the server requires the match field.
        'password_confirmation': password,
      },
      skipAuth: true,
    );
  }

  /// Changes the password, or sets the first one.
  ///
  /// An account that has a password confirms with [current]. An account
  /// without one (it signed up with Google, Apple or a phone number) must
  /// send [proof] instead: a code or a fresh Google/Apple sign-in. Either way
  /// the server signs every session out afterwards.
  Future<void> changePassword({
    String current = '',
    required String updated,
    Reauth? proof,
  }) async {
    // A password proof is the current password. It travels as
    // current_password, because its own `password` key would replace the new
    // password in the body.
    final proofFields = <String, dynamic>{...?proof?.toJson()};
    final proofPassword = '${proofFields.remove('password') ?? ''}';
    final currentPassword = current.isNotEmpty ? current : proofPassword;

    await client.post(
      ApiEndpoints.changePassword,
      body: {
        ...proofFields,
        'password': updated,
        'password_confirmation': updated,
        if (currentPassword.isNotEmpty) 'current_password': currentPassword,
      },
    );
  }

  /// Restores the signed-in user on app start. Null when there is no session.
  Future<User?> currentUser() async {
    if (!await _store.hasSession()) return null;

    return readThrough(
      () async {
        final response = await client.get(ApiEndpoints.me);
        return User.fromJson(
          response.maybeMapAt('user') ?? response.dataMap,
        );
      },
      'profile',
      (json) => User.fromJson(asMap(json)),
    );
  }

  Future<void> logout() async {
    try {
      await client.post(ApiEndpoints.logout);
    } catch (_) {
      // A failed server-side logout must not strand the user inside the app.
    }
    await clearLocalSession();
  }

  /// Forgets this device's session without calling the server.
  ///
  /// For after an account deletion, and for a session the server has already
  /// ended. Every token is revoked by then, so a logout request would only
  /// fail. The cached documents, recent searches and city go with the
  /// tokens, so the next person to use the device finds nothing of this
  /// account's. The cache is cleared even when the token store cannot be;
  /// that failure is still reported to the caller.
  Future<void> clearLocalSession() async {
    try {
      await _store.clear();
    } finally {
      await _cache.clearAccountData();
    }
  }

  /// Runs an auth call and persists the returned token pair.
  ///
  /// Deliberately calls [live] directly rather than going through
  /// [writeThrough]. Every other repository may fall back to a bundled fixture
  /// when the host is unreachable — for a list of creators that is a degraded
  /// experience, but for authentication it would mean a failed sign-in handing
  /// back a fabricated session and a fake token, and persisting both. The user
  /// would appear signed in as somebody who does not exist.
  ///
  /// A sign-in that cannot reach the server must fail, visibly. So must one
  /// that reaches it but gets no session back (a code verified without
  /// signing anyone in answers `{verified: true}`): treating that as signed
  /// in would leave the app with a user and no tokens.
  Future<AuthResult> _authenticate(Future<AuthResult> Function() live) async {
    final result = await live();
    final tokens = result.tokens;

    if (tokens == null) {
      throw const UnknownFailure(
        message: 'Sign-in did not return a session. Please try again.',
        code: 'no_session',
      );
    }

    // A new session never inherits the last one's cached responses. That
    // session may have ended without this device hearing about it: the app
    // was closed first, or the keystore could not be read.
    await _cache.clearAll();
    await _store.save(tokens);
    await _cache.setOnboardingComplete();

    return result;
  }

  static AuthResult _parse(Map<String, dynamic> data, {bool isNew = false}) {
    final tokenMap = (data['tokens'] as Map?)?.cast<String, dynamic>() ?? data;

    return AuthResult(
      user: User.fromJson((data['user'] as Map?)?.cast<String, dynamic>() ?? {}),
      tokens: AuthTokens.tryParse(tokenMap),
      isNewAccount: isNew || data['is_new_account'] == true,
    );
  }
}
