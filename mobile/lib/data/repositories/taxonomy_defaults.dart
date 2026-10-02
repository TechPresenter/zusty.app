import '../../models/taxonomy.dart';

/// First-launch fallback for the admin-managed option lists.
///
/// These exist for exactly one situation: a fresh install that has never
/// reached the server. Without them the very first frame would render empty
/// dropdowns. The moment a real bundle arrives it is cached and used instead,
/// and it wins on every subsequent launch.
///
/// Deliberately minimal — only the lists a user can hit before the first
/// network round-trip completes. Everything else comes from the API. Keep the
/// values in step with `database/seeds/007_taxonomies.sql`; the labels here
/// will simply be overwritten by whatever the admin has set.
abstract final class TaxonomyDefaults {
  static final bundle = TaxonomyBundle(
    version: 'bundled',
    taxonomies: {
      Tax.creatorSort: _terms({
        'relevance': 'Relevance',
        'rating': 'Rating',
        'popular': 'Popularity',
        'price_low': 'Price: low to high',
        'price_high': 'Price: high to low',
        'newest': 'Newest',
      }, defaultValue: 'relevance'),

      Tax.platform: _terms({
        'instagram': 'Instagram',
        'youtube': 'YouTube',
        'facebook': 'Facebook',
        'twitter': 'X (Twitter)',
        'linkedin': 'LinkedIn',
      }, defaultValue: 'instagram'),

      Tax.portfolioKind: _terms({
        'post': 'Posts',
        'reel': 'Reels',
        'photo': 'Photos',
        'video': 'Videos',
      }, defaultValue: 'post'),

      Tax.bookingStatus: _statusTerms(),
    },
  );

  /// Builds terms from a `value → label` map, preserving insertion order.
  static List<TaxonomyTerm> _terms(
    Map<String, String> entries, {
    String? defaultValue,
  }) =>
      [
        for (final entry in entries.entries)
          TaxonomyTerm(
            value: entry.key,
            label: entry.value,
            isDefault: entry.key == defaultValue,
          ),
      ];

  /// Booking statuses carry a tone so a status chip still colours correctly
  /// before the first fetch.
  static List<TaxonomyTerm> _statusTerms() => const [
        TaxonomyTerm(value: 'pending', label: 'Pending', metadata: {'tone': 'warning'}),
        TaxonomyTerm(value: 'countered', label: 'Counter offer', metadata: {'tone': 'warning'}),
        TaxonomyTerm(value: 'accepted', label: 'Accepted', metadata: {'tone': 'info'}),
        TaxonomyTerm(value: 'awaiting_payment', label: 'Awaiting payment', metadata: {'tone': 'warning'}),
        TaxonomyTerm(value: 'confirmed', label: 'Confirmed', metadata: {'tone': 'info'}),
        TaxonomyTerm(value: 'in_progress', label: 'In progress', metadata: {'tone': 'info'}),
        TaxonomyTerm(value: 'delivered', label: 'Delivered', metadata: {'tone': 'info'}),
        TaxonomyTerm(value: 'completed', label: 'Completed', metadata: {'tone': 'success'}),
        TaxonomyTerm(value: 'rejected', label: 'Declined', metadata: {'tone': 'error'}),
        TaxonomyTerm(value: 'cancelled', label: 'Cancelled', metadata: {'tone': 'error'}),
        TaxonomyTerm(value: 'expired', label: 'Expired', metadata: {'tone': 'error'}),
        TaxonomyTerm(value: 'disputed', label: 'Disputed', metadata: {'tone': 'error'}),
        TaxonomyTerm(value: 'refunded', label: 'Refunded', metadata: {'tone': 'error'}),
      ];
}
