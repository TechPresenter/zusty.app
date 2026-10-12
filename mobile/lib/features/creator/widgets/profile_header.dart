import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/creator.dart';
import '../../../router/routes.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/creator_card.dart';
import '../../../shared/widgets/platform_icon.dart';
import '../../../shared/widgets/stat_card.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_feedback.dart';
import '../../../shared/widgets/z_image.dart';
import 'profile_layout.dart';

/// The collapsing cover: full-bleed photo under the status bar, back / share /
/// favourite floating over it, and the avatar sitting across its bottom edge.
///
/// Collapses to a plain toolbar carrying the creator's name, so the way back
/// and the share and save actions stay in reach however far down the page the
/// user has read.
class ProfileCoverAppBar extends StatelessWidget {
  const ProfileCoverAppBar({
    super.key,
    required this.profile,
    required this.collapsed,
    required this.onShare,
    this.overflow,
    this.blocked = false,
    this.coverSpansWindow = true,
  });

  final CreatorProfile profile;

  /// True once the cover has scrolled away; switches the status bar to dark
  /// icons and fades the name into the toolbar.
  final bool collapsed;

  /// Whether the cover reaches both edges of the screen, as on a phone
  /// ([ProfileLayout.coverSpansWindow]). When it does not, the cover starts
  /// below the status bar, the status bar keeps the page colour, and its
  /// icons are dark whether or not the cover has scrolled away.
  final bool coverSpansWindow;
  final VoidCallback onShare;

  /// The ⋮ menu after the other actions (report and block a visitor's view).
  final Widget? overflow;

  /// The viewer blocked this creator: saving them is not offered.
  final bool blocked;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final margin = AppBreakpoints.pageMargin(context);
    final statusBar = MediaQuery.paddingOf(context).top;

    return SliverAppBar(
      pinned: true,
      expandedHeight: ProfileLayout.expandedHeight,
      backgroundColor: AppColors.surface,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      automaticallyImplyLeading: false,
      // Light icons over the photo; the theme's own once the toolbar is a
      // plain surface again, or whenever the status bar is over the page
      // colour rather than the photo (a cover narrower than the screen).
      systemOverlayStyle: (collapsed || !coverSpansWindow) && !dark
          ? SystemUiOverlayStyle.dark
          : SystemUiOverlayStyle.light,
      leadingWidth: margin + AppSpacing.minTouch + AppSpacing.xs,
      leading: Padding(
        padding: EdgeInsets.only(left: margin),
        child: Center(
          child: CoverButton(
            icon: Icons.arrow_back_ios_new_rounded,
            iconSize: 18,
            tooltip: 'Back',
            onPressed: () =>
                context.canPop() ? context.pop() : context.go(Routes.home),
          ),
        ),
      ),
      titleSpacing: 0,
      title: AnimatedOpacity(
        opacity: collapsed ? 1 : 0,
        duration: Motion.of(context, Motion.fast),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(
              child: Text(
                creator.displayName,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.titleMedium,
              ),
            ),
            if (creator.isVerified) ...[
              const SizedBox(width: AppSpacing.base),
              const VerifiedBadge(size: 16),
            ],
          ],
        ),
      ),
      actions: [
        CoverButton(
          icon: Icons.ios_share_rounded,
          tooltip: 'Share profile',
          onPressed: onShare,
        ),
        // The shared heart: optimistic, rolls back and explains itself if
        // the server refuses, and flips every card showing this creator.
        if (!blocked) ...[
          const SizedBox(width: AppSpacing.xs),
          CreatorFavoriteButton(creator: creator, size: AppSpacing.minTouch),
        ],
        if (overflow != null) ...[
          const SizedBox(width: AppSpacing.xs),
          overflow!,
        ],
        SizedBox(width: margin),
      ],
      // One shape either way, so a window that changes class (a foldable,
      // a tablet turned) keeps the cover rather than rebuilding it.
      flexibleSpace: Stack(
        fit: StackFit.expand,
        children: [
          FlexibleSpaceBar(
            collapseMode: CollapseMode.parallax,
            background: _CoverBackdrop(
              profile: profile,
              top: ProfileLayout.coverTop(
                context,
                spansWindow: coverSpansWindow,
              ),
            ),
          ),
          // Over a capped cover the status bar's strip stays the page
          // colour across the column too, so its dark icons never land on
          // the photo as the cover slides up beneath it.
          if (!coverSpansWindow)
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              height: statusBar,
              child: ColoredBox(color: AppColors.surface),
            ),
        ],
      ),
    );
  }
}

class _CoverBackdrop extends StatelessWidget {
  const _CoverBackdrop({required this.profile, this.top = 0});

  final CreatorProfile profile;

  /// Where the photo starts ([ProfileLayout.coverTop]).
  final double top;

  /// The cover photo, else the creator's own best work — a real image of
  /// theirs reads far better than an empty band of colour.
  String? get _coverUrl {
    final cover = profile.coverUrl;
    if (cover != null && cover.isNotEmpty) return cover;

    for (final item in profile.recentPortfolio) {
      final url = item.mediaUrl ?? item.displayUrl;
      if (url != null && url.isNotEmpty) return url;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final url = _coverUrl;

    return Stack(
      fit: StackFit.expand,
      children: [
        Positioned(
          top: top,
          left: 0,
          right: 0,
          bottom: ProfileLayout.avatarOverhang,
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (url == null)
                DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [AppColors.secondary, AppColors.primaryDark],
                    ),
                  ),
                )
              else
                ZImage(url: url, fallbackIcon: Icons.photo_camera_back_rounded),
              // Keeps the status bar and the floating buttons legible over a
              // bright photo, without dimming the picture itself.
              DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.center,
                    colors: [
                      Colors.black.withValues(alpha: 0.40),
                      Colors.transparent,
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 0,
          child: Center(
            child: Semantics(
              image: true,
              label: creator.isVerified
                  ? '${creator.displayName}, verified creator'
                  : creator.displayName,
              excludeSemantics: true,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  boxShadow: AppShadows.raised,
                ),
                // The surface-coloured ring cuts the avatar out of the cover.
                // The tick sits on the avatar; when the creator is not
                // verified the slot shows whether they are online instead.
                child: ZAvatar(
                  imageUrl: creator.avatarUrl,
                  name: creator.displayName,
                  size: ProfileLayout.avatarSize,
                  isVerified: creator.isVerified,
                  isOnline: !creator.isVerified && profile.isOnline,
                  ringColor: AppColors.surface,
                  ringWidth: 4,
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Everything between the cover and the tabs: who the creator is, the
/// numbers, the two social actions, niche tags, bio and linked accounts.
class ProfileIdentity extends StatelessWidget {
  const ProfileIdentity({
    super.key,
    required this.profile,
    required this.messaging,
    required this.onMessage,
    required this.onSocialTap,
    required this.onCategoryTap,
    this.blocked = false,
    this.onUnblock,
  });

  final CreatorProfile profile;

  /// A conversation is being opened; the Message button shows a spinner.
  final bool messaging;
  final VoidCallback onMessage;
  final ValueChanged<SocialAccount> onSocialTap;
  final ValueChanged<String> onCategoryTap;

  /// The viewer blocked this creator: Follow and Message give way to a
  /// notice that offers [onUnblock].
  final bool blocked;
  final VoidCallback? onUnblock;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final margin = AppBreakpoints.pageMargin(context);

    final subline = [
      creator.atHandle,
      if (creator.category != null && creator.category!.isNotEmpty)
        creator.category!,
    ].join('  ·  ');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: margin),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: AppSpacing.sm),
              Text(
                creator.displayName,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.headlineMedium.copyWith(
                  fontWeight: FontWeight.w800,
                  height: 1.2,
                ),
              ),
              const SizedBox(height: AppSpacing.base),
              Text(
                subline,
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onSurfaceVariant,
                ),
              ),
              if (creator.headline.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.xs),
                Text(
                  creator.headline,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodyMedium.copyWith(
                    color: AppColors.onSurface,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
              const SizedBox(height: AppSpacing.sm),
              _MetaRow(profile: profile),
              const SizedBox(height: AppSpacing.sm),
              _StatusRow(profile: profile),
              const SizedBox(height: AppSpacing.md),
              ZCard(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.md),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    StatStrip(items: _stats(profile)),
                    // The creator types these in; nothing fetches them from
                    // the networks, so the figure says whose it is.
                    if (profile.creator.followers > 0) ...[
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        'Follower figures are self-reported',
                        textAlign: TextAlign.center,
                        style: AppTypography.labelSmall.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              if (profile.isOwner)
                ZInfoBanner(
                  title: 'Previewing your profile',
                  message:
                      'This is how brands see you. Follow, Message and '
                      'Book are hidden on your own profile.',
                  icon: Icons.visibility_outlined,
                  actionLabel: 'Edit profile',
                  onAction: () => context.push(Routes.editProfile),
                )
              else if (blocked)
                ZInfoBanner(
                  title: 'You blocked ${creator.displayName}',
                  message:
                      'You can\'t message or book each other, and they don\'t '
                      'appear in your search.',
                  icon: Icons.block_rounded,
                  tone: StatusTone.warning,
                  actionLabel: onUnblock == null ? null : 'Unblock',
                  onAction: onUnblock,
                )
              else
                // Both pinned to the same height so the pair reads as one row.
                SizedBox(
                  height: 46,
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Expanded(
                        child: CreatorFollowButton(
                          creator: creator,
                          large: true,
                        ),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        // Small size for its tighter padding; the row's
                        // fixed height still makes it the Follow pill's twin.
                        child: ZButton.outlined(
                          label: 'Message',
                          icon: Icons.chat_bubble_outline_rounded,
                          size: ZButtonSize.small,
                          isLoading: messaging,
                          onPressed: onMessage,
                        ),
                      ),
                    ],
                  ),
                ),
              if (profile.bio.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                _ExpandableBio(text: profile.bio),
              ],
              if (profile.categories.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                Wrap(
                  alignment: WrapAlignment.center,
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  children: [
                    for (final category in profile.categories)
                      ZChip(
                        label: category.name,
                        color: AppColors.secondary,
                        onTap: () => onCategoryTap(category.slug),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
        if (profile.socialAccounts.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.md),
          SizedBox(
            height: 60,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: EdgeInsets.symmetric(horizontal: margin),
              itemCount: profile.socialAccounts.length,
              separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.xs),
              itemBuilder: (_, i) {
                final account = profile.socialAccounts[i];
                return SocialLinkPill(
                  account: account,
                  onTap: () => onSocialTap(account),
                );
              },
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
      ],
    );
  }

  /// Followers / Following / Posts, like the social profile a brand already
  /// knows how to read.
  ///
  /// Followers is the creator's combined reach across linked networks — the
  /// figure brands book on. Following is their primary account's, the only
  /// place that number means anything; when there is none the slot shows
  /// completed collaborations instead of a zero. Posts counts the portfolio
  /// shown in the Media tab below.
  static List<(String, String)> _stats(CreatorProfile profile) {
    final creator = profile.creator;
    final primary = profile.primaryAccount;
    final posts = creator.postsCount > 0
        ? creator.postsCount
        : profile.portfolioTotal;

    return [
      (creator.followersLabel, 'Followers'),
      if (primary != null && primary.following > 0)
        (primary.followingLabel, 'Following')
      else
        (Formatters.compact(creator.collabsCount), 'Collabs'),
      (Formatters.compact(posts), 'Posts'),
    ];
  }
}

/// Location and rating on one line, each with its glyph.
class _MetaRow extends StatelessWidget {
  const _MetaRow({required this.profile});

  final CreatorProfile profile;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final muted = AppTypography.bodySmall.copyWith(
      color: AppColors.onSurfaceVariant,
    );

    return Wrap(
      alignment: WrapAlignment.center,
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: AppSpacing.md,
      runSpacing: AppSpacing.base,
      children: [
        if (creator.location.isNotEmpty)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.location_on_outlined,
                size: 15,
                color: AppColors.onSurfaceVariant,
              ),
              const SizedBox(width: 3),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 220),
                child: Text(
                  creator.location,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: muted,
                ),
              ),
            ],
          ),
        Semantics(
          label: creator.ratingCount > 0
              ? 'Rated ${Formatters.rating(creator.rating)} from '
                    '${Formatters.count(creator.ratingCount, 'review')}'
              : 'No reviews yet',
          excludeSemantics: true,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.star_rounded, size: 16, color: AppColors.star),
              const SizedBox(width: 3),
              Text(
                creator.ratingCount > 0
                    ? '${Formatters.rating(creator.rating)} '
                          '(${creator.ratingCount})'
                    : 'New',
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.onSurface,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Availability, reply time and presence as soft status chips.
class _StatusRow extends StatelessWidget {
  const _StatusRow({required this.profile});

  final CreatorProfile profile;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final response = profile.responseTimeLabel;

    return Wrap(
      alignment: WrapAlignment.center,
      spacing: AppSpacing.xs,
      runSpacing: AppSpacing.xs,
      children: [
        StatusChip(
          label: creator.availability.label,
          tone: switch (creator.availability) {
            Availability.available => StatusTone.success,
            Availability.busy => StatusTone.warning,
            Availability.unavailable => StatusTone.error,
          },
          icon: Icons.circle,
          dense: true,
        ),
        if (response != null)
          StatusChip(
            label: response,
            tone: StatusTone.info,
            icon: Icons.bolt_rounded,
            dense: true,
          ),
        // The avatar carries the verified tick for verified creators, so
        // presence moves here for them rather than being lost.
        if (profile.isOnline && creator.isVerified)
          const StatusChip(
            label: 'Online now',
            tone: StatusTone.success,
            icon: Icons.wifi_tethering_rounded,
            dense: true,
          ),
      ],
    );
  }
}

/// The creator's own words, clamped to three lines with an inline toggle so a
/// long bio does not push the actions and tabs off the first screen.
class _ExpandableBio extends StatefulWidget {
  const _ExpandableBio({required this.text});

  final String text;

  @override
  State<_ExpandableBio> createState() => _ExpandableBioState();
}

class _ExpandableBioState extends State<_ExpandableBio> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final style = AppTypography.bodyMedium.copyWith(
      color: AppColors.onSurfaceVariant,
      height: 1.5,
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        // Only offer "more" when the text really is longer than three lines.
        final painter = TextPainter(
          text: TextSpan(text: widget.text, style: style),
          maxLines: 3,
          textAlign: TextAlign.center,
          textDirection: Directionality.of(context),
          textScaler: MediaQuery.textScalerOf(context),
        )..layout(maxWidth: constraints.maxWidth);
        final overflows = painter.didExceedMaxLines;
        painter.dispose();

        return Column(
          children: [
            AnimatedSize(
              duration: Motion.of(context, Motion.medium),
              curve: Motion.standard,
              alignment: Alignment.topCenter,
              child: Text(
                widget.text,
                textAlign: TextAlign.center,
                maxLines: _expanded ? null : 3,
                overflow: _expanded
                    ? TextOverflow.visible
                    : TextOverflow.ellipsis,
                style: style,
              ),
            ),
            if (overflows)
              TextButton(
                onPressed: () => setState(() => _expanded = !_expanded),
                style: TextButton.styleFrom(
                  minimumSize: const Size(
                    AppSpacing.minTouch,
                    AppSpacing.minTouch,
                  ),
                ),
                child: Text(
                  _expanded ? 'Show less' : 'Read more',
                  style: AppTypography.labelLarge.copyWith(
                    color: AppColors.primary,
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

/// A linked account as a tappable pill: the network's glyph, its follower
/// count and the handle. Opens the account itself, outside the app.
class SocialLinkPill extends StatelessWidget {
  const SocialLinkPill({super.key, required this.account, required this.onTap});

  final SocialAccount account;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final handle = account.displayHandle;
    final headline = account.hasMetrics
        ? account.followersLabel
        : account.platformLabel;

    return Semantics(
      button: true,
      label: [
        account.platformLabel,
        if (handle.isNotEmpty) handle,
        if (account.hasMetrics) '${account.followersLabel} followers',
        'opens outside the app',
      ].join(', '),
      excludeSemantics: true,
      child: Pressable(
        pressedScale: 0.96,
        child: Material(
          color: AppColors.surfaceContainerLowest,
          shape: StadiumBorder(
            side: BorderSide(color: AppColors.outlineVariant),
          ),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.xs,
                AppSpacing.xs,
                AppSpacing.md,
                AppSpacing.xs,
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  PlatformIcon(
                    platform: account.platform,
                    iconName: account.icon,
                    color: account.color,
                    size: 36,
                  ),
                  const SizedBox(width: AppSpacing.xs),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 132),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Flexible(
                              child: Text(
                                headline,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: AppTypography.labelLarge.copyWith(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            if (account.isVerified) ...[
                              const SizedBox(width: 3),
                              const VerifiedBadge(size: 12),
                            ],
                          ],
                        ),
                        if (handle.isNotEmpty)
                          Text(
                            handle,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.labelSmall.copyWith(
                              color: AppColors.onSurfaceVariant,
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
