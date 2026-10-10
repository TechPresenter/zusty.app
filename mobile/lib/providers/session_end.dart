import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../features/kyc/kyc_share_cache.dart';
import 'auth_provider.dart';
import 'core_providers.dart';

/// Clears this device after the server has ended its session, then sends the
/// app back to sign-in.
///
/// For a session the server ended on its own: the refresh token was revoked
/// (a password change, "sign out of all devices", an operator) or has run
/// out. Nothing is sent to the server, because no token works any more. What
/// is cleared, each step on its own so one failure cannot skip the rest: the
/// push token, the stored session with every cached document, the recent
/// searches and the city, the config resolved for this account, any
/// decrypted verification document a share left behind, and the Firebase and
/// Google sessions. Then [AuthController.restore] finds no session and lands
/// on sign-in.
///
/// Concurrent calls, such as several requests failing with 401 at once,
/// share one run. Every step is safe to repeat.
final sessionEndProvider = Provider<Future<void> Function()>((ref) {
  Future<void>? running;

  Future<void> run() async {
    try {
      await ref.read(pushServiceProvider).deleteToken();
    } catch (_) {
      // Firebase may never have started on this device. Nothing to drop.
    }

    try {
      await ref.read(authRepositoryProvider).clearLocalSession();
    } catch (_) {
      // Best effort: the remaining steps still run.
    }

    try {
      await ref.read(appConfigRepositoryProvider).clearAccountScoped();
      ref.invalidate(appMenusProvider);
    } catch (_) {
      // Best effort, as above.
    }

    // Never throws, and must not hold up the return to sign-in.
    unawaited(KycShareCache.purge());

    try {
      await ref.read(socialSignOutProvider)();
    } catch (_) {
      // It swallows its own errors; this keeps a fake from breaking the run.
    }

    try {
      await ref.read(authProvider.notifier).restore();
    } catch (_) {
      // restore() handles its own failures; this only guards the hand-off.
    }
  }

  return () => running ??= run().whenComplete(() => running = null);
});
