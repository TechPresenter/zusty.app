import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/support.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';

/// Every FAQ the help centre may show this user, in the admin's order.
///
/// Fetched whole and filtered on the device. The help centre needs the full
/// set at once — the topic tiles count every category, the accordion groups
/// by category, and search spans all of them — and the list is a few dozen
/// short rows, so one request beats a request per chip tap.
///
/// Answers the operator wrote for the other side of the marketplace (a
/// brands-only "How do I book a creator?") are left out for a signed-in user.
final helpCentreFaqsProvider = FutureProvider.autoDispose<List<Faq>>((
  ref,
) async {
  final faqs = await ref.watch(accountRepositoryProvider).faqs();
  final user = ref.watch(currentUserProvider);
  final isCreator = user?.role.isCreator;

  return faqs
      .where((faq) => faq.isFor(isCreator: isCreator))
      .toList(growable: false);
});

/// One FAQ category as the help centre shows it: slug, display name, and how
/// many answers it holds.
@immutable
class FaqTopic {
  const FaqTopic({required this.value, required this.label, this.count = 0});

  final String value;
  final String label;
  final int count;

  /// The glyph for a topic tile. Categories are admin-defined, so an unknown
  /// one gets a neutral question mark rather than a wrong picture.
  IconData get icon => switch (value) {
    'getting_started' => Icons.rocket_launch_rounded,
    'general' => Icons.lightbulb_outline_rounded,
    'booking' || 'bookings' => Icons.calendar_month_rounded,
    'payment' || 'payments' => Icons.payments_rounded,
    'wallet' || 'payouts' || 'payout' => Icons.account_balance_wallet_rounded,
    'creator' || 'creators' => Icons.auto_awesome_rounded,
    'brand' || 'brands' => Icons.storefront_rounded,
    'account' => Icons.manage_accounts_rounded,
    'security' => Icons.shield_rounded,
    'verification' || 'kyc' => Icons.verified_user_rounded,
    'disputes' || 'dispute' => Icons.gavel_rounded,
    'campaigns' || 'campaign' => Icons.campaign_rounded,
    _ => Icons.help_outline_rounded,
  };

  /// The categories present in [faqs], in the order they first appear —
  /// which is the admin's sort order.
  static List<FaqTopic> from(List<Faq> faqs) {
    final counts = <String, int>{};
    final labels = <String, String>{};

    for (final faq in faqs) {
      counts[faq.category] = (counts[faq.category] ?? 0) + 1;
      labels.putIfAbsent(faq.category, () => faq.categoryName);
    }

    return [
      for (final entry in counts.entries)
        FaqTopic(
          value: entry.key,
          label: labels[entry.key]!,
          count: entry.value,
        ),
    ];
  }
}

/// How the next FAQs screen should open: on a topic, on one question, or with
/// the search box focused.
///
/// The router builds the FAQs screen with no arguments, so the help centre
/// leaves its intent here and the FAQs screen takes it (and clears it) as it
/// opens. Deliberately not auto-disposed: nothing listens between the write
/// and the push, and an auto-disposed provider would forget the intent in
/// that gap.
@immutable
class FaqLaunch {
  const FaqLaunch({this.category, this.faqId, this.focusSearch = false});

  final String? category;
  final int? faqId;
  final bool focusSearch;
}

final faqLaunchProvider = StateProvider<FaqLaunch?>((_) => null);

/// FAQ answers are written in the admin panel's rich-text editor, so they can
/// arrive as HTML. The help centre shows them as plain paragraphs: tags
/// become line breaks or disappear, and the common entities are decoded.
String faqPlainText(String raw) {
  if (!raw.contains('<') && !raw.contains('&')) return raw.trim();

  var text = raw
      .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
      .replaceAll(RegExp(r'</(p|div|li|h[1-6])>', caseSensitive: false), '\n')
      .replaceAll(RegExp(r'<li[^>]*>', caseSensitive: false), '• ')
      .replaceAll(RegExp(r'<[^>]+>'), '');

  const entities = {
    '&nbsp;': ' ',
    '&quot;': '"',
    '&#39;': "'",
    '&apos;': "'",
    '&lt;': '<',
    '&gt;': '>',
    '&rsquo;': '’',
    '&lsquo;': '‘',
    '&ldquo;': '“',
    '&rdquo;': '”',
    '&ndash;': '–',
    '&mdash;': '—',
    '&hellip;': '…',
    '&#8377;': '₹',
    // Last, so an escaped entity ("&amp;lt;") decodes once, not twice.
    '&amp;': '&',
  };
  entities.forEach((entity, char) => text = text.replaceAll(entity, char));

  return text.replaceAll(RegExp(r'\n{3,}'), '\n\n').trim();
}
