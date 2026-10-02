import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/creator.dart';
import '../../../shared/widgets/platform_icon.dart';
import '../../../shared/widgets/stat_card.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_card.dart';
import 'profile_layout.dart';

/// The Insights tab: reach, engagement and track record, per platform and in
/// total, and — when the platform sync has supplied it — who the audience is.
///
/// Every figure here comes from the profile payload. Nothing is estimated or
/// filled in: a block whose data is missing is left out, and a creator with
/// no linked accounts and no bookings gets an honest empty state.
class ProfileInsightsTab extends StatelessWidget {
  const ProfileInsightsTab({
    super.key,
    required this.profile,
    required this.onOpenAccount,
  });

  final CreatorProfile profile;
  final ValueChanged<SocialAccount> onOpenAccount;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final accounts = profile.metricAccounts;
    final audience = profile.audience;
    final aspects = profile.ratingAspects;
    final overview = _overview(profile);

    final hasAnything =
        accounts.isNotEmpty ||
        audience != null ||
        aspects != null ||
        creator.collabsCount > 0 ||
        creator.ratingCount > 0;

    if (!hasAnything) {
      return ProfileTabBody(
        children: [
          EmptyState(
            title: 'No insights yet',
            message: profile.isOwner
                ? 'Link a social account to show brands your reach and '
                      'engagement.'
                : 'Insights appear once ${firstNameOf(creator.displayName)} '
                      'links a social account or completes a booking.',
            icon: Icons.insights_rounded,
            compact: true,
          ),
        ],
      );
    }

    final totalFollowers = accounts.fold<int>(
      0,
      (sum, account) => sum + account.followers,
    );

    return ProfileTabBody(
      children: [
        const ProfileSectionTitle('Overview', first: true),
        _StatGrid(cards: overview),

        // -- Followers by platform ---------------------------------
        if (accounts.length > 1 && totalFollowers > 0) ...[
          ProfileSectionTitle(
            'Followers by platform',
            subtitle:
                '${Formatters.compact(totalFollowers)} across '
                '${accounts.length} platforms',
          ),
          ZCard(
            child: Column(
              children: [
                for (var i = 0; i < accounts.length; i++) ...[
                  if (i > 0) const SizedBox(height: AppSpacing.sm),
                  ZProgressBar(
                    label: accounts[i].platformLabel,
                    fraction: accounts[i].followers / totalFollowers,
                    valueLabel: accounts[i].followersLabel,
                    color: _barTint(accounts[i]),
                  ),
                ],
              ],
            ),
          ),
        ],

        // -- Engagement per account --------------------------------
        if (accounts.isNotEmpty) ...[
          const ProfileSectionTitle(
            'Engagement by account',
            subtitle: 'As reported by each platform at its last sync',
          ),
          for (final account in accounts)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: _AccountCard(
                account: account,
                onTap: account.profileUrl == null
                    ? null
                    : () => onOpenAccount(account),
              ),
            ),
        ],

        // -- Audience ------------------------------------------------
        if (audience != null) ...[
          ProfileSectionTitle(
            'Audience',
            subtitle: audience.accounts > 1
                ? 'Across ${audience.accounts} linked accounts, weighted '
                      'by followers'
                : 'From the linked account\'s platform data',
          ),
          _AudienceCard(audience: audience),
        ],

        // -- How brands rated the work --------------------------------
        if (aspects != null) ...[
          ProfileSectionTitle(
            'Brand ratings',
            subtitle: aspects.count == 1
                ? 'From 1 published review'
                : 'From ${aspects.count} published reviews',
          ),
          ZCard(
            child: Column(
              children: [
                for (final (label, value) in [
                  ('Quality', aspects.quality),
                  ('Communication', aspects.communication),
                  ('Timeliness', aspects.timeliness),
                ])
                  if (value != null)
                    Padding(
                      padding: const EdgeInsets.symmetric(
                        vertical: AppSpacing.base,
                      ),
                      child: _AspectRow(label: label, value: value),
                    ),
              ],
            ),
          ),
        ],
      ],
    );
  }

  /// The headline tiles, each only when its figure is real and meaningful.
  static List<StatCard> _overview(CreatorProfile profile) {
    final creator = profile.creator;
    final accounts = profile.metricAccounts;
    final engagement = profile.averageEngagement;
    final completion = profile.completionRate;
    final response = profile.responseTimeShort;

    return [
      if (creator.followers > 0)
        StatCard(
          label: 'Total reach',
          value: creator.followersLabel,
          icon: Icons.groups_rounded,
          caption: accounts.isEmpty
              ? null
              : accounts.length == 1
              ? 'On ${accounts.first.platformLabel}'
              : 'Across ${accounts.length} platforms',
        ),
      if (engagement > 0)
        StatCard(
          label: 'Avg. engagement',
          value: '${engagement.toStringAsFixed(1)}%',
          icon: Icons.favorite_rounded,
          tint: AppColors.secondary,
          caption: accounts.length > 1 ? 'Weighted by followers' : null,
        ),
      StatCard(
        label: 'Collabs completed',
        value: Formatters.number(creator.collabsCount),
        icon: Icons.handshake_outlined,
        tint: AppColors.success,
      ),
      // A completion rate over zero bookings is a default, not a record.
      if (completion != null && creator.collabsCount > 0)
        StatCard(
          label: 'Completion rate',
          value: '${completion.toStringAsFixed(completion % 1 == 0 ? 0 : 1)}%',
          icon: Icons.task_alt_rounded,
          tint: AppColors.success,
        ),
      if (response != null)
        StatCard(
          label: 'Typical reply',
          value: response,
          icon: Icons.bolt_rounded,
          tint: AppColors.info,
        ),
      if (creator.ratingCount > 0)
        StatCard(
          label: 'Rating',
          value: Formatters.rating(creator.rating),
          icon: Icons.star_rounded,
          tint: AppColors.star,
          caption: creator.ratingCount == 1
              ? '1 review'
              : '${creator.ratingCount} reviews',
        ),
      if (profile.platformFollowers > 0)
        StatCard(
          label: 'Followers on Zusty',
          value: Formatters.compact(profile.platformFollowers),
          icon: Icons.person_add_alt_rounded,
        ),
    ];
  }

  /// The network's own colour, unless it is too pale to read as a bar.
  static Color _barTint(SocialAccount account) {
    final tint = PlatformIcons.parseHex(account.color);
    if (tint == null || tint.computeLuminance() > 0.6) return AppColors.primary;
    return tint;
  }
}

/// Stat tiles two-up, each pair sharing a height so captions never leave
/// ragged edges.
class _StatGrid extends StatelessWidget {
  const _StatGrid({required this.cards});

  final List<StatCard> cards;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < cards.length; i += 2) ...[
          if (i > 0) const SizedBox(height: AppSpacing.sm),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: cards[i]),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: i + 1 < cards.length
                      ? cards[i + 1]
                      : const SizedBox.shrink(),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({required this.account, required this.onTap});

  final SocialAccount account;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final synced = account.lastSyncedAt;

    return ZCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              PlatformIcon(
                platform: account.platform,
                iconName: account.icon,
                color: account.color,
                size: 40,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            account.platformLabel,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.labelLarge,
                          ),
                        ),
                        if (account.isPrimary) ...[
                          const SizedBox(width: AppSpacing.xs),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 1,
                            ),
                            decoration: BoxDecoration(
                              color: AppColors.primaryFixed,
                              borderRadius: AppRadius.pill,
                            ),
                            child: Text(
                              'Primary',
                              style: AppTypography.labelSmall.copyWith(
                                color: AppColors.primaryDark,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ],
                        if (account.isVerified) ...[
                          const SizedBox(width: 4),
                          const VerifiedBadge(size: 14),
                        ],
                      ],
                    ),
                    Text(
                      account.displayHandle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodySmall,
                    ),
                  ],
                ),
              ),
              if (onTap != null)
                Icon(
                  Icons.open_in_new_rounded,
                  size: 18,
                  color: AppColors.onSurfaceVariant,
                ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          StatStrip(
            dense: true,
            items: [
              (account.followersLabel, 'Followers'),
              (
                account.engagementRate > 0
                    ? '${account.engagementRate.toStringAsFixed(1)}%'
                    : '—',
                'Engagement',
              ),
              (
                account.avgViews > 0
                    ? Formatters.compact(account.avgViews)
                    : '—',
                'Avg views',
              ),
            ],
          ),
          if (account.avgLikes > 0 || account.avgComments > 0) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(
              '${[if (account.avgLikes > 0) '${Formatters.compact(account.avgLikes)} likes', if (account.avgComments > 0) '${Formatters.compact(account.avgComments)} comments'].join(' · ')} per post on average',
              style: AppTypography.bodySmall,
            ),
          ],
          if (synced != null) ...[
            const SizedBox(height: AppSpacing.base),
            Text(
              'Updated ${Formatters.relative(synced)}',
              style: AppTypography.labelSmall.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _AudienceCard extends StatelessWidget {
  const _AudienceCard({required this.audience});

  final AudienceSummary audience;

  @override
  Widget build(BuildContext context) {
    // Named places first, largest first; the catch-all buckets ("Other
    // India", "Overseas") after them, so the list reads as "top cities".
    final locations = audience.locations.entries.toList()
      ..sort((a, b) {
        final aOther = _isCatchAll(a.key);
        final bOther = _isCatchAll(b.key);
        if (aOther != bOther) return aOther ? 1 : -1;
        return b.value.compareTo(a.value);
      });

    final groups = <(String, List<MapEntry<String, double>>)>[
      if (audience.gender.isNotEmpty)
        ('Gender', audience.gender.entries.toList()),
      if (audience.age.isNotEmpty) ('Age', audience.age.entries.toList()),
      if (locations.isNotEmpty) ('Top locations', locations),
    ];

    return ZCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var g = 0; g < groups.length; g++) ...[
            if (g > 0) ...[
              const SizedBox(height: AppSpacing.md),
              Divider(height: 1, color: AppColors.outlineVariant),
              const SizedBox(height: AppSpacing.md),
            ],
            Text(groups[g].$1, style: AppTypography.titleSmall),
            const SizedBox(height: AppSpacing.sm),
            for (final entry in groups[g].$2)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ZProgressBar(
                  label: titleise(entry.key),
                  fraction: entry.value / 100,
                  valueLabel: '${_percent(entry.value)}%',
                ),
              ),
          ],
        ],
      ),
    );
  }

  static bool _isCatchAll(String bucket) {
    final lower = bucket.toLowerCase();
    return lower.startsWith('other') || lower == 'overseas' || lower == 'rest';
  }

  static String _percent(double value) =>
      value % 1 == 0 ? value.toStringAsFixed(0) : value.toStringAsFixed(1);
}

class _AspectRow extends StatelessWidget {
  const _AspectRow({required this.label, required this.value});

  final String label;
  final double value;

  @override
  Widget build(BuildContext context) {
    // Not ZProgressBar: its fixed 72dp label column clips "Communication".
    return Semantics(
      label: '$label ${Formatters.rating(value)} out of 5',
      excludeSemantics: true,
      child: Row(
        children: [
          Expanded(
            flex: 2,
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.bodySmall.copyWith(
                color: AppColors.onSurface,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            flex: 3,
            child: ClipRRect(
              borderRadius: AppRadius.pill,
              child: LinearProgressIndicator(
                value: (value / 5).clamp(0.0, 1.0),
                minHeight: 8,
                color: AppColors.star,
                backgroundColor: AppColors.surfaceContainerHigh,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Icon(Icons.star_rounded, size: 14, color: AppColors.star),
          const SizedBox(width: 2),
          Text(Formatters.rating(value), style: AppTypography.labelMedium),
        ],
      ),
    );
  }
}
