import 'package:zuste/data/repositories/base_repository.dart';

import 'mock_data.dart';

/// Installs the test fixtures behind [BaseRepository.fixtures].
///
/// Nothing in `lib/` holds fixture data any more — repositories only name the
/// payload they want, and this resolver decides what that name means. Call it
/// from `setUp` alongside `BaseRepository.demoMode = true`; a build that never
/// calls it (every release build) has no fixture data compiled in at all.
void registerTestFixtures() {
  BaseRepository.fixtures = resolveTestFixture;
}

/// Drops the resolver again, restoring production behaviour.
void clearTestFixtures() {
  BaseRepository.fixtures = null;
}

/// Maps a repository fixture key to the matching [MockData] payload.
///
/// Keys are either a bare name (`wallet`) or a name with the one argument the
/// endpoint is scoped by (`booking.detail:4`). Unknown keys throw rather than
/// returning null: a null would surface as an empty screen and read like a
/// passing test.
dynamic resolveTestFixture(String key) {
  final separator = key.indexOf(':');
  final name = separator == -1 ? key : key.substring(0, separator);
  final argument = separator == -1 ? '' : key.substring(separator + 1);

  return switch (name) {
    // Writes that have nothing to echo back — the caller builds its own
    // optimistic result, so the fixture is just an acknowledgement.
    'noop' => const <String, dynamic>{},

    // Account
    'profile' => MockData.currentUser,
    'notifications' => MockData.notifications,
    'referrals' => MockData.referrals,

    // Discovery
    'home' => MockData.home,
    'explore' => MockData.explore,
    'categories' => MockData.categories,
    'creators' => MockData.creators,
    'creator.detail' => MockData.creatorProfile(argument),
    'creator.portfolio' => MockData.portfolio(argument),
    'creator.reviews' => MockData.reviews,
    'creator.availability' => MockData.availability(argument),
    'search.suggestions' => _searchSuggestions(argument),

    // Bookings and campaigns
    'bookings' => MockData.bookings,
    'booking.detail' => MockData.bookingDetail(_id(key, argument)),
    'booking.coupons' => MockData.couponOffers,
    'campaigns' => MockData.campaigns,
    'campaign.detail' => MockData.campaignDetail(_id(key, argument)),

    // Messaging
    'conversations' => MockData.conversations,
    'messages' => MockData.messages(_id(key, argument)),

    // Money
    'wallet' => MockData.wallet,
    'wallet.transactions' => MockData.transactions,
    'payments' => MockData.payments,
    'invoices' => MockData.invoices,
    'payment.methods' => MockData.paymentMethods,
    'withdrawals' => MockData.withdrawals,
    'bank.accounts' => MockData.bankAccounts,

    // AI drafts and matching
    'ai.bio' => MockData.aiBio,
    'ai.brief' => MockData.aiBrief,
    'ai.match' => MockData.aiMatches,

    // Support and CMS
    'tickets' => MockData.tickets,
    'ticket.detail' => MockData.ticketDetail(_id(key, argument)),
    'faqs' => MockData.faqs,
    'page' => MockData.page(argument),

    // Verification. KYC is a legal record, so the fixture is a blank draft —
    // an invented "approved" would be worse than no answer at all.
    'kyc.overview' => const <String, dynamic>{},
    'kyc.requirements' => const <Map<String, dynamic>>[],

    _ => throw StateError(
        'No test fixture is registered for "$key". Add it to '
        'resolveTestFixture in test/support/fixtures.dart, or fix the key the '
        'repository passes to readThrough/writeThrough.',
      ),
  };
}

/// Type-ahead results, narrowed the way the live endpoint narrows them: an
/// empty query returns the trending terms, anything else matches creator names
/// first and then the trending list.
List<String> _searchSuggestions(String query) {
  if (query.isEmpty) return MockData.searchSuggestions;

  final lower = query.toLowerCase();
  return [
    ...MockData.creators
        .map((c) => c['display_name'] as String)
        .where((name) => name.toLowerCase().contains(lower)),
    ...MockData.searchSuggestions
        .where((s) => s.toLowerCase().contains(lower)),
  ].take(6).toList();
}

int _id(String key, String argument) =>
    int.tryParse(argument) ??
    (throw StateError('Fixture key "$key" needs a numeric id.'));
