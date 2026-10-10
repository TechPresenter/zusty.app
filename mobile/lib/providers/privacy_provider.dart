import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/auth/social_sign_in_service.dart';
import '../data/repositories/privacy_repository.dart';
import '../models/privacy.dart';
import 'auth_provider.dart';
import 'core_providers.dart';

/// Privacy & account management: "Download my data" and "Delete my account".
final privacyRepositoryProvider = Provider<PrivacyRepository>((ref) {
  return PrivacyRepository(ref.watch(apiClientProvider));
});

/// Verification options, the latest export and the deletion preflight.
///
/// Auto-disposed: blockers and the export's state change outside the app
/// (a booking completes, a link expires), so each visit asks afresh.
final privacyOverviewProvider = FutureProvider.autoDispose<PrivacyOverview>((ref) {
  return ref.watch(privacyRepositoryProvider).overview();
});

/// Set just before signing out after a deletion, so the router, which is
/// rebuilt on every sign-in change, opens on the goodbye screen instead of
/// the sign-in form. Cleared when the user leaves that screen.
///
/// Memory only, on purpose: a reload, a restored browser tab or a cold start
/// forgets it. An old `/account-deleted` address then falls through to the
/// normal gates (onboarding or sign-in) instead of telling someone who never
/// deleted anything that their account is gone.
final accountDeletedProvider = StateProvider<bool>((ref) => false);

/// Where the server sent its confirmation of the deletion (masked), for the
/// goodbye screen. Null before any deletion, and when the account had no
/// email address or text-message number to send one to. Memory only, like
/// [accountDeletedProvider], and cleared with it when the goodbye is left.
final accountDeletedNoticeProvider = StateProvider<SecurityNotice?>((ref) => null);

/// True while a deletion request is on its way to the server.
///
/// The delete route refuses to be left meanwhile (browser Back, a link, a
/// programmatic `go`), so the screen that hands the device over once the
/// server answers is not torn down mid-request.
final deletionInFlightProvider = StateProvider<bool>((ref) => false);

Future<void>? _finishingDeletion;

/// Hands this device over once the server has erased the account.
///
/// Raises [accountDeletedProvider] first, so the router that the sign-out
/// rebuilds opens on the goodbye screen, then signs out of Firebase and
/// withdraws the app's Google grant, then clears the device through
/// [AuthController.signOutAfterDeletion].
///
/// The delete screen and the session-expiry handler (a 401 `account_deleted`
/// met by any request) may both call this, even at the same moment, so
/// concurrent calls share one run. It never throws: if clearing the device
/// fails, [AuthController.restore] still ends signed out, because the server
/// no longer knows this session.
///
/// Pass notifiers read before any await (`ref.read(provider.notifier)`): the
/// screen that started the deletion may be gone by the time it finishes.
Future<void> finishAccountDeletion(
  StateController<bool> flag,
  AuthController auth,
) =>
    _finishingDeletion ??= _finishAccountDeletion(flag, auth)
        .whenComplete(() => _finishingDeletion = null);

Future<void> _finishAccountDeletion(
  StateController<bool> flag,
  AuthController auth,
) async {
  flag.state = true;

  // It swallows its own errors; the guard keeps it that way, because a
  // sign-out Google refuses must never keep this device signed in.
  try {
    await SocialSignInService.signOut(revoke: true);
  } catch (_) {
    // Ignored, as above.
  }

  try {
    await auth.signOutAfterDeletion();
  } catch (_) {
    try {
      await auth.restore();
    } catch (_) {
      // Nothing left to try. The server already ended the session, so the
      // next request this device makes is refused and signs it out.
    }
  }
}
