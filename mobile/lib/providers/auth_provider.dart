import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/auth/social_sign_in_service.dart';
import '../features/kyc/kyc_share_cache.dart';
import '../models/user.dart';
import 'core_providers.dart';

/// Where the app is in the sign-in lifecycle. The router listens to this to
/// choose between onboarding, the auth stack, and the main shell.
/// `guest` sits deliberately between unauthenticated and authenticated: a guest
/// holds a real session and may browse and favourite, but the server refuses
/// them anything transactional with `guest_upgrade_required`.
enum AuthStage { unknown, onboarding, unauthenticated, guest, authenticated }

@immutable
class AuthState {
  const AuthState({this.stage = AuthStage.unknown, this.user});

  final AuthStage stage;
  final User? user;

  bool get isAuthenticated => stage == AuthStage.authenticated && user != null;
  bool get isReady => stage != AuthStage.unknown;

  /// Browsing without an account.
  bool get isGuest => stage == AuthStage.guest;

  /// Holds a usable session of either kind. Guards that only need "is there
  /// somebody here" should use this; anything transactional must use
  /// [isAuthenticated], because the server refuses guests.
  bool get hasSession => isAuthenticated || (isGuest && user != null);

  /// Convenience for widgets that branch on marketplace side.
  bool get isCreator => user?.role.isCreator ?? false;
  bool get isBrand => user?.role.isBrand ?? true;

  AuthState copyWith({AuthStage? stage, User? user, bool clearUser = false}) =>
      AuthState(
        stage: stage ?? this.stage,
        user: clearUser ? null : (user ?? this.user),
      );
}

class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    // Kick off session restoration without blocking the first frame.
    Future.microtask(restore);
    return const AuthState();
  }

  /// Reads any stored JWT and re-hydrates the signed-in user.
  Future<void> restore() async {
    final repo = ref.read(authRepositoryProvider);

    if (!repo.hasCompletedOnboarding) {
      state = const AuthState(stage: AuthStage.onboarding);
      return;
    }

    try {
      final user = await repo.currentUser();
      state = user == null
          ? const AuthState(stage: AuthStage.unauthenticated)
          : AuthState(stage: AuthStage.authenticated, user: user);
    } catch (_) {
      state = const AuthState(stage: AuthStage.unauthenticated);
    }
  }

  Future<void> completeOnboarding() async {
    await ref.read(authRepositoryProvider).completeOnboarding();
    state = state.copyWith(stage: AuthStage.unauthenticated);
  }

  Future<void> login({
    required String identifier,
    required String password,
  }) async {
    final result = await ref
        .read(authRepositoryProvider)
        .login(identifier: identifier, password: password);
    state = AuthState(stage: AuthStage.authenticated, user: result.user);
  }

  /// Email sign-up. [marketingOptIn] is the unticked "product updates and
  /// offers" box; the terms are always accepted by the time this is called.
  Future<void> register({
    required String name,
    required String email,
    required String password,
    required UserRole role,
    String? referralCode,
    bool marketingOptIn = false,
  }) async {
    final result = await ref.read(authRepositoryProvider).register(
          name: name,
          email: email,
          password: password,
          role: role,
          referralCode: referralCode,
          marketingOptIn: marketingOptIn,
        );
    state = AuthState(stage: AuthStage.authenticated, user: result.user);
  }

  /// Google (or Apple) sign-in. [acceptTerms] says the person saw the terms
  /// notice above the button, which a first sign-in, creating the account,
  /// needs.
  Future<void> signInWithProvider(
    String idToken, {
    UserRole? role,
    bool acceptTerms = false,
  }) async {
    final result = await ref
        .read(authRepositoryProvider)
        .signInWithFirebase(idToken, role: role, acceptTerms: acceptTerms);
    state = AuthState(stage: AuthStage.authenticated, user: result.user);
  }

  /// Signs in with a one-time code. [destination] is exactly what `sendOtp`
  /// was given — the account's email address for an email code, the number
  /// for an SMS one — and `purpose` must match too: the server keys the
  /// stored code on the pair.
  Future<void> verifyOtp({
    required String destination,
    required String code,
    String purpose = 'login',
  }) async {
    final result = await ref
        .read(authRepositoryProvider)
        .verifyOtp(destination: destination, code: code, purpose: purpose);
    state = AuthState(stage: AuthStage.authenticated, user: result.user);
  }

  /// Starts a guest session. Only offer this when the server says guest access
  /// is enabled — it refuses otherwise.
  Future<void> continueAsGuest() async {
    final result = await ref.read(authRepositoryProvider).signInAsGuest();
    state = AuthState(stage: AuthStage.guest, user: result.user);
  }

  /// Converts the current guest into a real account without losing anything
  /// they did as a guest — the server updates the same row.
  Future<void> upgradeGuest({
    required UserRole role,
    String? name,
    String? email,
    String? phone,
    String? password,
    String? idToken,
    String? destination,
    String? code,
    String? referralCode,
  }) async {
    final result = await ref.read(authRepositoryProvider).upgradeGuest(
          role: role,
          name: name,
          email: email,
          phone: phone,
          password: password,
          idToken: idToken,
          destination: destination,
          code: code,
          referralCode: referralCode,
        );
    state = AuthState(stage: AuthStage.authenticated, user: result.user);
  }

  /// Signs out: tells the server, then clears this device.
  ///
  /// Always ends signed out. Each clearing step runs on its own, so one
  /// failing (an unreadable keystore, say) cannot leave the rest undone or
  /// keep the user inside the app.
  Future<void> logout() async {
    // Before the tokens go: blanking the device's push token needs an
    // authenticated write, and there is no DELETE route for it.
    await _unregisterDevice();

    try {
      try {
        await ref.read(authRepositoryProvider).logout();
      } catch (_) {
        // It already swallows a failed server call; this covers the device.
      }

      await _socialSignOut();

      try {
        await _forgetAccountScopedConfig();
      } catch (_) {
        // Ignored, as above.
      }

      _forgetDocumentCopies();
    } finally {
      state = const AuthState(stage: AuthStage.unauthenticated);
    }
  }

  /// Ends any Firebase or Google session on this device. Sign-in no longer
  /// keeps one, but an older install may still hold one. Never throws.
  Future<void> _socialSignOut({bool revoke = false}) async {
    try {
      await ref.read(socialSignOutProvider)(revoke: revoke);
    } catch (_) {
      // Intentionally ignored — sign-out must always complete.
    }
  }

  /// Sweeps any decrypted verification document a share left on the device,
  /// so the next person to use it finds nothing of this account's. Never
  /// blocks or fails the sign-out.
  void _forgetDocumentCopies() => unawaited(KycShareCache.purge());

  /// Stops the server pushing to this device, and drops the local token.
  ///
  /// Swallows every failure for the same reason [AuthRepository.logout] does: a
  /// device the server keeps targeting is bad, a user trapped inside the app is
  /// worse.
  Future<void> _unregisterDevice() async {
    try {
      final deviceId = ref.read(keyValueCacheProvider).deviceId;
      if (deviceId.isEmpty) return;

      await ref.read(accountRepositoryProvider).unregisterDevice(deviceId);
      await ref.read(pushServiceProvider).deleteToken();
    } catch (_) {
      // Intentionally ignored — sign-out must always complete.
    }
  }

  /// Clears this device after the server has deleted the account.
  ///
  /// The deletion already revoked every session and push registration
  /// server-side, so nothing is sent: the local push token, the Firebase and
  /// Google sessions, the stored session, the caches, the recent searches
  /// and the account-scoped config are all dropped here, and the next person
  /// to open the app on this device starts clean.
  ///
  /// Never throws, and always ends signed out: each step runs on its own, so
  /// one failing cannot keep the others from running. The delete screen and
  /// the session-expiry handler may both call this, even at the same moment;
  /// concurrent calls share one run.
  Future<void> signOutAfterDeletion() => _signingOut ??=
      _signOutAfterDeletion().whenComplete(() => _signingOut = null);

  Future<void>? _signingOut;

  Future<void> _signOutAfterDeletion() async {
    try {
      try {
        await ref.read(pushServiceProvider).deleteToken();
      } catch (_) {
        // Intentionally ignored: the account is gone either way.
      }

      await _socialSignOut(revoke: true);

      try {
        await ref.read(authRepositoryProvider).clearLocalSession();
      } catch (_) {
        // Ignored, as above. The server no longer knows this session.
      }

      try {
        await _forgetAccountScopedConfig();
      } catch (_) {
        // Ignored, as above.
      }

      _forgetDocumentCopies();
    } finally {
      state = const AuthState(stage: AuthStage.unauthenticated);
    }
  }

  /// Drops the parts of `/app/config` that were resolved for the account that
  /// has just left — menus, feature flags and popups are all audience-scoped,
  /// so the next person to sign in on this device must not inherit them.
  ///
  /// The auth block is kept: it decides what the login screen offers, and
  /// blanking it would drop the user back to the compiled email-and-password
  /// fallback instead of the operator's real configuration.
  Future<void> _forgetAccountScopedConfig() async {
    await ref.read(appConfigRepositoryProvider).clearAccountScoped();
    ref.invalidate(appMenusProvider);
  }

  /// Applies a locally-updated user after a profile edit, without a round-trip
  /// through the router.
  void setUser(User user) => state = state.copyWith(user: user);
}

final authProvider =
    NotifierProvider<AuthController, AuthState>(AuthController.new);

/// Signs this device out of Firebase and Google; `revoke` also withdraws the
/// app's Google grant. A provider so tests can see it called without
/// Firebase.
final socialSignOutProvider = Provider<Future<void> Function({bool revoke})>(
  (ref) => SocialSignInService.signOut,
);

/// The signed-in user, or null before sign-in.
final currentUserProvider = Provider<User?>((ref) {
  return ref.watch(authProvider).user;
});
