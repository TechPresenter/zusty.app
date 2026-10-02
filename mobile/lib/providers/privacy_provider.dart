import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/repositories/privacy_repository.dart';
import '../models/privacy.dart';
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
final accountDeletedProvider = StateProvider<bool>((ref) => false);
