import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/creator.dart';
import '../../../models/creator_fields.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/discovery_provider.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../shared/widgets/menu_tile.dart';
import '../../../shared/widgets/platform_icon.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_image.dart';
import 'profile_layout.dart';

/// The About tab: the facts a brand checks before booking, how the creator
/// likes to work (read off their real packages and hours), the badges they
/// hold, and the extended profile — skills, experience, credentials, working
/// hours and FAQs.
///
/// The extended profile is its own request ([creatorFieldsProvider]) so the
/// header never waits on it. While it loads this tab shows what it already
/// has plus a short skeleton; if it fails, the extended blocks simply do not
/// appear.
class ProfileAboutTab extends ConsumerWidget {
  const ProfileAboutTab({
    super.key,
    required this.profile,
    required this.onOpenLink,
  });

  final CreatorProfile profile;
  final ValueChanged<String> onOpenLink;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final creator = profile.creator;
    final fieldsValue = ref.watch(creatorFieldsProvider(creator.id));
    final fields = fieldsValue.valueOrNull;
    final taxonomy = ref.watch(taxonomiesProvider);

    final facts = _facts(profile, fields);
    final preferences = _preferences(profile, fields, taxonomy);
    final links = _links(profile, fields);

    final children = <Widget>[
      if (facts.isNotEmpty) ...[
        const ProfileSectionTitle('At a glance', first: true),
        ZCard(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.xs,
          ),
          child: Column(
            children: [
              for (var i = 0; i < facts.length; i++) ...[
                if (i > 0) Divider(height: 1, color: AppColors.outlineVariant),
                _FactRow(fact: facts[i]),
              ],
            ],
          ),
        ),
      ],

      // -- How they work -------------------------------------------------
      // Derived from what the creator actually sells and when they work,
      // never typed in separately, so it cannot drift from the packages.
      if (preferences.isNotEmpty) ...[
        ProfileSectionTitle(
          'Collaboration preferences',
          subtitle:
              'From ${firstNameOf(creator.displayName)}\'s packages '
              'and working hours',
          first: facts.isEmpty,
        ),
        ZCard(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.xs,
          ),
          child: Column(
            children: [
              for (var i = 0; i < preferences.length; i++) ...[
                if (i > 0) Divider(height: 1, color: AppColors.outlineVariant),
                preferences[i],
              ],
            ],
          ),
        ),
      ],

      // -- Verification badges -------------------------------------------
      // Label, icon and colour all arrive with each badge, so adding a
      // twelfth badge in the admin panel needs no app release.
      if (profile.badges.isNotEmpty) ...[
        const ProfileSectionTitle('Verified by Zusty'),
        Wrap(
          spacing: AppSpacing.xs,
          runSpacing: AppSpacing.xs,
          children: profile.badges
              .map((badge) => ProfileBadgeChip(badge: badge))
              .toList(),
        ),
      ],

      // -- Extended profile ----------------------------------------------
      // Skills, education, work history, working hours and FAQs. Each block
      // renders only if the creator filled it in, so a sparse profile shows
      // no empty headings.
      if (fields != null && !fields.isEmpty)
        ...profileExtendedSections(
          fields,
          // Shown once, in "At a glance", rather than twice.
          skipSections: profile.languages.isNotEmpty
              ? const {'languages'}
              : const {},
        ),
      if (fieldsValue.isLoading && fields == null) ...[
        const SizedBox(height: AppSpacing.lg),
        const ZShimmer(height: 96, borderRadius: AppRadius.xlAll),
      ],

      if (links.isNotEmpty) ...[
        const ProfileSectionTitle('Media & documents'),
        ZCard(
          padding: EdgeInsets.zero,
          clip: true,
          child: Column(
            children: [
              for (final link in links)
                ZMenuTile(
                  icon: link.icon,
                  title: link.title,
                  subtitle: link.subtitle,
                  trailing: Icon(
                    Icons.open_in_new_rounded,
                    size: 18,
                    color: AppColors.onSurfaceVariant,
                  ),
                  onTap: () => onOpenLink(link.url),
                ),
            ],
          ),
        ),
      ],
    ];

    // "At a glance" always has at least availability, so this tab is never
    // blank; a sparse profile gets a nudge under it instead of an empty page.
    final sparse =
        preferences.isEmpty &&
        profile.badges.isEmpty &&
        links.isEmpty &&
        (fields == null || fields.isEmpty) &&
        !fieldsValue.isLoading;

    return ProfileTabBody(
      children: [
        ...children,
        if (sparse)
          EmptyState(
            title: profile.isOwner
                ? 'Tell brands how you work'
                : 'More details coming soon',
            message: profile.isOwner
                ? 'Add your skills, experience and working hours so brands '
                      'can book you with confidence.'
                : '${firstNameOf(creator.displayName)} has not added '
                      'skills, experience or working hours yet.',
            icon: Icons.person_outline_rounded,
            compact: true,
          ),
      ],
    );
  }

  static List<_Fact> _facts(CreatorProfile profile, CreatorFields? fields) {
    final creator = profile.creator;

    final languages = profile.languages.isNotEmpty
        ? profile.languages
        : (fields?.terms['languages'] ?? const <ProfileTerm>[])
              .map((term) => term.label)
              .toList();

    final experience = fields?.details.experienceLabel;
    final response = profile.responseTimeLabel;
    final joined = profile.joinedAt;

    return [
      if (creator.location.isNotEmpty)
        _Fact(Icons.location_on_outlined, 'Based in', creator.location),
      if (languages.isNotEmpty)
        _Fact(Icons.translate_rounded, 'Languages', languages.join(', ')),
      if (experience != null)
        _Fact(Icons.workspace_premium_outlined, 'Experience', experience),
      if (response != null)
        _Fact(
          Icons.bolt_rounded,
          'Responds',
          response.replaceFirst('Replies ', ''),
        ),
      _Fact(
        Icons.event_available_outlined,
        'Availability',
        creator.availability.label,
      ),
      if (joined != null)
        _Fact(
          Icons.calendar_month_outlined,
          'On Zusty since',
          Formatters.monthYear(joined),
        ),
    ];
  }

  static List<Widget> _preferences(
    CreatorProfile profile,
    CreatorFields? fields,
    TaxonomyBundle taxonomy,
  ) {
    final packages = profile.packages;
    final rows = <Widget>[];

    if (packages.isNotEmpty) {
      final deliverables = <String>{
        for (final p in packages)
          if (p.deliverableType.isNotEmpty)
            taxonomy.label(Tax.deliverableType, p.deliverableType),
      };

      if (deliverables.isNotEmpty) {
        rows.add(
          _FactRow(
            fact: _Fact(
              Icons.inventory_2_outlined,
              'Deliverables',
              deliverables.join(', '),
            ),
          ),
        );
      }

      final platforms = <String>{
        for (final p in packages)
          if (p.platform.isNotEmpty) p.platform,
      };

      if (platforms.isNotEmpty) {
        rows.add(_PlatformsRow(platforms: platforms.toList()));
      }

      final turnaround =
          packages.map((p) => p.turnaroundDays).where((d) => d > 0).toList()
            ..sort();

      if (turnaround.isNotEmpty) {
        final low = turnaround.first;
        final high = turnaround.last;
        rows.add(
          _FactRow(
            fact: _Fact(
              Icons.schedule_rounded,
              'Turnaround',
              low == high ? Formatters.duration(low) : '$low–$high days',
            ),
          ),
        );
      }

      final revisions = packages.map((p) => p.revisions).toList()..sort();
      final most = revisions.last;

      if (most > 0) {
        final least = revisions.first;
        rows.add(
          _FactRow(
            fact: _Fact(
              Icons.refresh_rounded,
              'Revisions',
              least == most
                  ? '$most ${most == 1 ? 'round' : 'rounds'} included'
                  : '$least–$most rounds included',
            ),
          ),
        );
      }
    }

    final week = fields?.workingHours;

    if (week != null && week.hasAny) {
      final working = week.days.where((d) => d.isSet && d.isWorking).toList();

      if (working.isNotEmpty) {
        rows.add(
          _FactRow(
            fact: _Fact(
              Icons.work_history_outlined,
              'Works on',
              working
                  .map((d) => d.shortLabel.isNotEmpty ? d.shortLabel : d.label)
                  .join(', '),
            ),
          ),
        );
      }
    }

    return rows;
  }

  static List<_Link> _links(CreatorProfile profile, CreatorFields? fields) {
    final details = fields?.details;

    final mediaKit = profile.mediaKitUrl ?? details?.mediaKitUrl;
    final intro = profile.introVideoUrl ?? details?.introVideoUrl;
    final resume = details?.resumeUrl;

    return [
      if (intro != null)
        _Link(
          Icons.play_circle_outline_rounded,
          'Intro video',
          'A short hello from the creator',
          intro,
        ),
      if (mediaKit != null)
        _Link(
          Icons.description_outlined,
          'Media kit',
          'Rates, audience and past work',
          mediaKit,
        ),
      if (resume != null) _Link(Icons.badge_outlined, 'Resume', null, resume),
    ];
  }
}

class _Fact {
  const _Fact(this.icon, this.label, this.value);

  final IconData icon;
  final String label;
  final String value;
}

class _Link {
  const _Link(this.icon, this.title, this.subtitle, this.url);

  final IconData icon;
  final String title;
  final String? subtitle;
  final String url;
}

/// An icon, a label on the left and the value on the right, wrapping rather
/// than overflowing when the value is long.
class _FactRow extends StatelessWidget {
  const _FactRow({required this.fact});

  final _Fact fact;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(fact.icon, size: 18, color: AppColors.primary),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            flex: 2,
            child: Text(
              fact.label,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            flex: 3,
            child: Text(
              fact.value,
              textAlign: TextAlign.right,
              style: AppTypography.bodyMedium.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _PlatformsRow extends ConsumerWidget {
  const _PlatformsRow({required this.platforms});

  final List<String> platforms;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final taxonomy = ref.watch(taxonomiesProvider);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.devices_rounded, size: 18, color: AppColors.primary),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            flex: 2,
            child: Text(
              'Platforms',
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            flex: 3,
            child: Wrap(
              alignment: WrapAlignment.end,
              spacing: AppSpacing.base,
              runSpacing: AppSpacing.base,
              children: [
                for (final platform in platforms)
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      PlatformIcon(platform: platform, size: 20),
                      const SizedBox(width: 4),
                      // Flexible so a long network name ellipsizes inside
                      // a narrow column instead of overflowing it.
                      Flexible(
                        child: Text(
                          taxonomy.label(Tax.socialPlatform, platform),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodyMedium.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The extended-profile blocks, in the order a brand reads them.
///
/// Returns a flat list of widgets rather than one container so each block
/// sits in the tab's own list and inherits its scrolling and padding. Every
/// heading is guarded by its own content check — a creator who has filled in
/// nothing but skills sees exactly one heading.
List<Widget> profileExtendedSections(
  CreatorFields fields, {
  Set<String> skipSections = const {},
}) {
  final widgets = <Widget>[];

  void heading(String title) => widgets.add(ProfileSectionTitle(title));

  // -- Skills, interests, languages, service areas ------------------------
  // Section keys and their labels come from the server; this only decides the
  // order it prefers to show them in, and renders anything else afterwards so
  // a section added later still appears.
  const preferred = <String, String>{
    'professions': 'Profession',
    'skills': 'Skills',
    'languages': 'Languages',
    'interests': 'Interests',
    'service_areas': 'Service areas',
    'service_cities': 'Serves in',
  };

  final ordered = <String>[
    ...preferred.keys.where(fields.terms.containsKey),
    ...fields.terms.keys.where((key) => !preferred.containsKey(key)),
  ];

  for (final section in ordered) {
    if (skipSections.contains(section)) continue;

    final terms = fields.terms[section] ?? const <ProfileTerm>[];
    if (terms.isEmpty) continue;

    heading(preferred[section] ?? titleise(section));
    widgets.add(
      Wrap(
        spacing: AppSpacing.xs,
        runSpacing: AppSpacing.xs,
        children: terms.map((term) => ProfileTermChip(term: term)).toList(),
      ),
    );
  }

  // -- Experience and previous collaborations -----------------------------
  // Brand collaborations live here too, and the ones backed by a completed
  // booking on Zusty carry a tick — this is the creator's campaign history.
  if (fields.experience.isNotEmpty) {
    heading('Experience & collaborations');
    widgets.addAll(
      fields.experience.map(
        (entry) => Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: ProfileExperienceCard(entry: entry),
        ),
      ),
    );
  }

  // -- Education, certifications, awards ----------------------------------
  // Grouped by the kind's own label, so a new credential kind added in the
  // admin panel gets its own heading with no change here.
  fields.credentialsByKind.forEach((kindLabel, items) {
    heading(kindLabel.isEmpty ? 'Credentials' : kindLabel);
    widgets.addAll(
      items.map(
        (item) => Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: ProfileCredentialCard(credential: item),
        ),
      ),
    );
  });

  // -- Working hours ------------------------------------------------------
  if (fields.workingHours.hasAny) {
    final tz = fields.workingHours.timezone;
    widgets.add(
      ProfileSectionTitle(
        'Working hours',
        subtitle: tz != null && tz.isNotEmpty ? 'Times shown in $tz' : null,
      ),
    );
    widgets.add(ProfileWorkingWeekCard(week: fields.workingHours));
  }

  // -- FAQs ---------------------------------------------------------------
  if (fields.faqs.isNotEmpty) {
    heading('Frequently asked');
    widgets.add(
      ZCard(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
        child: Column(
          children: [
            for (var i = 0; i < fields.faqs.length; i++) ...[
              if (i > 0) Divider(height: 1, color: AppColors.outlineVariant),
              ProfileFaqTile(faq: fields.faqs[i]),
            ],
          ],
        ),
      ),
    );
  }

  return widgets;
}

class ProfileTermChip extends StatelessWidget {
  const ProfileTermChip({super.key, required this.term});

  final ProfileTerm term;

  @override
  Widget build(BuildContext context) {
    final tint = profileTint(term.color, AppColors.secondary);

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: 6,
      ),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: term.isPrimary ? 0.16 : 0.08),
        borderRadius: AppRadius.pill,
        border: Border.all(
          color: tint.withValues(alpha: term.isPrimary ? 0.45 : 0.20),
        ),
      ),
      child: Text(
        term.label,
        style: AppTypography.labelMedium.copyWith(
          color: tint,
          fontWeight: term.isPrimary ? FontWeight.w700 : FontWeight.w500,
        ),
      ),
    );
  }
}

class ProfileExperienceCard extends StatelessWidget {
  const ProfileExperienceCard({super.key, required this.entry});

  final ExperienceEntry entry;

  @override
  Widget build(BuildContext context) {
    final tint = profileTint(entry.kindColor, AppColors.secondary);

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // The organisation's logo when there is one; otherwise a tinted
          // tile in the entry kind's colour.
          if (entry.organisationLogoUrl != null)
            ZImage(
              url: entry.organisationLogoUrl,
              width: 40,
              height: 40,
              borderRadius: AppRadius.mdAll,
              fallbackIcon: Icons.business_rounded,
            )
          else
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: tint.withValues(alpha: 0.12),
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(Icons.business_center_rounded, size: 20, color: tint),
            ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        entry.role?.isNotEmpty == true
                            ? '${entry.role} · ${entry.organisation}'
                            : entry.organisation,
                        style: AppTypography.labelLarge,
                      ),
                    ),
                    // The distinction a brand actually cares about: a
                    // collaboration backed by a completed booking here, versus
                    // one just claimed.
                    if (entry.isPlatformVerified) ...[
                      const SizedBox(width: AppSpacing.xs),
                      const Tooltip(
                        message: 'Completed through Zusty',
                        child: VerifiedBadge(size: 16),
                      ),
                    ],
                  ],
                ),
                if (entry.period.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    entry.location?.isNotEmpty == true
                        ? '${entry.period} · ${entry.location}'
                        : entry.period,
                    style: AppTypography.bodySmall,
                  ),
                ],
                if (entry.description.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    entry.description,
                    style: AppTypography.bodyMedium.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class ProfileCredentialCard extends StatelessWidget {
  const ProfileCredentialCard({super.key, required this.credential});

  final Credential credential;

  @override
  Widget build(BuildContext context) {
    final tint = profileTint(credential.kindColor);

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: tint.withValues(alpha: 0.12),
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(Icons.workspace_premium_rounded, size: 20, color: tint),
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
                        credential.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.labelLarge,
                      ),
                    ),
                    if (credential.isVerified) ...[
                      const SizedBox(width: 4),
                      const VerifiedBadge(size: 14),
                    ],
                  ],
                ),
                if (credential.subtitle.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(credential.subtitle, style: AppTypography.bodySmall),
                ],
                if (credential.period.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    credential.isExpired
                        ? '${credential.period} · Expired'
                        : credential.period,
                    style: AppTypography.bodySmall.copyWith(
                      color: credential.isExpired
                          ? AppColors.error
                          : AppColors.onSurfaceVariant,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class ProfileWorkingWeekCard extends StatelessWidget {
  const ProfileWorkingWeekCard({super.key, required this.week});

  final WorkingWeek week;

  @override
  Widget build(BuildContext context) {
    // Only the days the creator actually set. An unset day is unknown, which
    // is a different thing from a day they marked as not working, and showing
    // "Not set" for four days reads as a broken screen.
    final days = week.days.where((day) => day.isSet).toList();

    return ZCard(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      child: Column(
        children: days
            .map(
              (day) => Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.base),
                child: Row(
                  children: [
                    SizedBox(
                      width: 52,
                      child: Text(
                        day.shortLabel.isNotEmpty ? day.shortLabel : day.label,
                        style: AppTypography.labelLarge,
                      ),
                    ),
                    Expanded(
                      child: Text(
                        day.summary,
                        textAlign: TextAlign.right,
                        style: AppTypography.bodyMedium.copyWith(
                          color: day.isWorking
                              ? AppColors.onSurface
                              : AppColors.onSurfaceVariant,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            )
            .toList(),
      ),
    );
  }
}

class ProfileFaqTile extends StatelessWidget {
  const ProfileFaqTile({super.key, required this.faq});

  final CreatorFaq faq;

  @override
  Widget build(BuildContext context) {
    return Theme(
      // The default divider on an ExpansionTile draws a hard line across the
      // card in both themes; the card already provides the separation.
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        tilePadding: EdgeInsets.zero,
        childrenPadding: const EdgeInsets.only(bottom: AppSpacing.sm),
        iconColor: AppColors.primary,
        collapsedIconColor: AppColors.onSurfaceVariant,
        title: Text(faq.question, style: AppTypography.labelLarge),
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: Text(
              faq.answer,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One verification badge, drawn entirely from what the server sent.
///
/// The catalogue is an admin-managed taxonomy, so this widget declares no badge
/// names of its own — only how to draw whatever arrives.
class ProfileBadgeChip extends StatelessWidget {
  const ProfileBadgeChip({super.key, required this.badge});

  final VerificationBadge badge;

  static const _glyphs = <String, IconData>{
    'mail': Icons.mail_rounded,
    'smartphone': Icons.smartphone_rounded,
    'shield': Icons.shield_rounded,
    'check-circle': Icons.check_circle_rounded,
    'verified': Icons.verified_rounded,
    'star': Icons.star_rounded,
    'megaphone': Icons.campaign_rounded,
    'briefcase': Icons.business_center_rounded,
    'link': Icons.link_rounded,
    'trending-up': Icons.trending_up_rounded,
    'award': Icons.workspace_premium_rounded,
  };

  @override
  Widget build(BuildContext context) {
    final tint = profileTint(badge.color);

    return Tooltip(
      message: badge.description.isEmpty ? badge.label : badge.description,
      child: Container(
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.sm,
          vertical: AppSpacing.xs,
        ),
        decoration: BoxDecoration(
          color: tint.withValues(alpha: 0.10),
          borderRadius: AppRadius.pill,
          border: Border.all(color: tint.withValues(alpha: 0.28)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              _glyphs[badge.icon] ?? Icons.workspace_premium_rounded,
              size: 15,
              color: tint,
            ),
            const SizedBox(width: 6),
            Text(
              badge.label,
              style: AppTypography.labelMedium.copyWith(color: tint),
            ),
          ],
        ),
      ),
    );
  }
}
