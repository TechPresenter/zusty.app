import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/creator.dart';
import '../../models/creator_fields.dart';
import '../../models/safety.dart';
import '../../providers/core_providers.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/safety_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../safety/block_dialogs.dart';
import '../safety/safety_menu.dart';
import 'widgets/profile_about.dart';
import 'widgets/profile_header.dart';
import 'widgets/profile_insights.dart';
import 'widgets/profile_layout.dart';
import 'widgets/profile_media.dart';
import 'widgets/profile_packages.dart';
import 'widgets/profile_reviews.dart';

/// Screen 09 — Creator Detail.
///
/// A collapsing cover with the avatar across its edge; the identity block
/// (name, handle, reach, Follow and Message, bio, niche tags, linked
/// accounts); then Media, About, Packages, Insights and Reviews as swipeable
/// tabs pinned under the toolbar. Booking is the one sticky action at the
/// bottom, with the starting price beside it.
///
/// On the creator's own profile (`is_owner`) the same page is a preview: the
/// social and booking actions give way to Edit and Share.
class CreatorProfileScreen extends ConsumerWidget {
  const CreatorProfileScreen({super.key, required this.handle});

  final String handle;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(creatorProfileProvider(handle));
    final data = profile.valueOrNull;

    // A refresh that fails keeps the profile already on screen; only a first
    // load with nothing to show falls through to the error state.
    if (data != null) return _ProfileView(profile: data, handle: handle);

    if (profile.hasError) {
      return ZScaffold(
        padded: false,
        appBar: const ZAppBar(title: 'Creator'),
        body: ErrorState(
          error: profile.error!,
          onRetry: () => ref.invalidate(creatorProfileProvider(handle)),
        ),
      );
    }

    return const _ProfileSkeleton();
  }
}

/// The profile's tabs, in display order.
enum _ProfileTab {
  media('Media'),
  about('About'),
  packages('Packages'),
  insights('Insights'),
  reviews('Reviews');

  const _ProfileTab(this.label);

  final String label;
}

class _ProfileView extends ConsumerStatefulWidget {
  const _ProfileView({required this.profile, required this.handle});

  final CreatorProfile profile;
  final String handle;

  @override
  ConsumerState<_ProfileView> createState() => _ProfileViewState();
}

class _ProfileViewState extends ConsumerState<_ProfileView>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(
    length: _ProfileTab.values.length,
    vsync: this,
  );

  /// The page's one scroll position: cover, identity, pinned tabs, body.
  final ScrollController _scroll = ScrollController();

  /// Located after layout to find where the tab bar pins, and where its
  /// bottom edge is on screen.
  final GlobalKey _tabHeaderKey = GlobalKey();
  final GlobalKey _tabBarKey = GlobalKey();

  /// The tab whose body is showing. Follows [_tabs], whether it moved by a
  /// tap on the bar or a swipe across the content.
  _ProfileTab _shown = _ProfileTab.media;

  /// Whether a horizontal drag started below the pinned tab bar; only drags
  /// over the tab body switch tabs, so swiping the social links row or the
  /// bio never does.
  bool _dragOnBody = false;

  bool _collapsed = false;
  bool _messaging = false;

  CreatorProfile get _profile => widget.profile;
  Creator get _creator => widget.profile.creator;

  /// The header counts as collapsed a little before the toolbar fully
  /// settles, so the name has faded in by the time the cover is gone.
  static const double _collapseAt =
      ProfileLayout.expandedHeight - kToolbarHeight - 16;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _tabs.addListener(_onTab);
  }

  @override
  void dispose() {
    _scroll
      ..removeListener(_onScroll)
      ..dispose();
    _tabs
      ..removeListener(_onTab)
      ..dispose();
    super.dispose();
  }

  /// Rebuilds only when the collapsed state flips, not on every pixel.
  void _onScroll() {
    final collapsed = _scroll.hasClients && _scroll.offset > _collapseAt;
    if (collapsed != _collapsed) setState(() => _collapsed = collapsed);
  }

  /// Swaps the body, and — if the reader was already below the header —
  /// brings the new tab in at its top, right under the pinned bar, instead of
  /// somewhere in its middle.
  void _onTab() {
    final next = _ProfileTab.values[_tabs.index];
    if (next == _shown) return;

    final pinAt = _tabPinOffset();
    setState(() => _shown = next);

    if (pinAt != null && _scroll.hasClients && _scroll.offset > pinAt) {
      _scroll.jumpTo(pinAt);
    }
  }

  /// The scroll offset at which the tab bar meets the collapsed toolbar.
  double? _tabPinOffset() {
    final sliver = _tabHeaderKey.currentContext?.findRenderObject();
    if (sliver is! RenderSliver || sliver.geometry == null) return null;

    final toolbar = MediaQuery.paddingOf(context).top + kToolbarHeight;
    return math.max(0, sliver.constraints.precedingScrollExtent - toolbar);
  }

  /// The global y of the tab bar's bottom edge, for telling a swipe on the
  /// body from one on the header.
  double _tabBarBottom() {
    final box = _tabBarKey.currentContext?.findRenderObject();
    if (box is! RenderBox || !box.hasSize) return double.infinity;

    return box.localToGlobal(Offset(0, box.size.height)).dy;
  }

  void _onSwipeEnd(DragEndDetails details) {
    final velocity = details.primaryVelocity ?? 0;
    if (!_dragOnBody || velocity.abs() < 350) return;

    final target = _tabs.index + (velocity < 0 ? 1 : -1);
    if (target < 0 || target >= _tabs.length) return;

    _tabs.animateTo(target);
  }

  Future<void> _refresh() async {
    try {
      ref
        ..invalidate(creatorProfileProvider(widget.handle))
        ..invalidate(creatorFieldsProvider(_creator.id));
      await ref.read(creatorProfileProvider(widget.handle).future);
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } catch (_) {
      if (mounted) {
        showZSnack(
          context,
          'Could not refresh. Please try again.',
          isError: true,
        );
      }
    }
  }

  // -- Actions ---------------------------------------------------------------

  /// Opens (or reuses) the conversation with this creator, then the thread.
  ///
  /// This used to push `Routes.chat(creator.id)` — a creator id where a
  /// conversation id belongs, so the chat screen asked for somebody else's
  /// thread (or none) and got a 403. The server owns the thread id: ask it.
  Future<void> _message() async {
    if (_messaging) return;
    setState(() => _messaging = true);

    try {
      final id = await ref
          .read(messageRepositoryProvider)
          .start(creatorId: _creator.id);

      if (!mounted) return;

      if (id <= 0) {
        showZSnack(
          context,
          'Could not open the chat. Please try again.',
          isError: true,
        );
        return;
      }

      context.push(Routes.chat(id));
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } catch (_) {
      if (mounted) {
        showZSnack(
          context,
          'Could not open the chat. Please try again.',
          isError: true,
        );
      }
    } finally {
      if (mounted) setState(() => _messaging = false);
    }
  }

  /// Starts a booking from [package], or a custom request when it is null.
  void _bookPackage(CreatorPackage? package) {
    final base = Routes.newBooking(_creator.handle);
    context.push(package == null ? base : '$base?package=${package.id}');
  }

  /// The sticky CTA: with packages, ask which one first; without, go straight
  /// to the request form.
  Future<void> _book() async {
    if (_profile.packages.isEmpty) {
      _bookPackage(null);
      return;
    }

    final choice = await showPackageChooser(context, profile: _profile);
    if (!mounted || choice == null) return;

    _bookPackage(choice is CreatorPackage ? choice : null);
  }

  /// Opens a linked account outside the app and, if it opened, counts the
  /// click for the creator's analytics.
  Future<void> _openSocial(SocialAccount account) async {
    final opened = await openExternalLink(context, account.profileUrl);

    // Fire-and-forget: a counter must never get in the way of the click. The
    // event names the account row, which is why it needs the id.
    if (opened && account.id > 0) {
      unawaited(
        ref
            .read(creatorRepositoryProvider)
            .recordEngagement(
              _creator.id,
              eventType: 'social_click',
              channel: account.platform,
              targetId: account.id,
            ),
      );
    }
  }

  void _openCategory(String slug) =>
      context.push('${Routes.searchResults}?category=$slug');

  /// Lifts the viewer's block on this creator; the profile reloads with
  /// Message and Book back.
  Future<void> _unblock() => confirmUnblock(
    context,
    ref,
    userId: _creator.userId,
    name: _creator.displayName,
  );

  bool get _canBook =>
      !_profile.isOwner && _creator.availability != Availability.unavailable;

  // -- Build -----------------------------------------------------------------

  Widget _body(_ProfileTab tab, {required bool blocked}) {
    final profile = _profile;

    return switch (tab) {
      _ProfileTab.media => ProfileMediaTab(profile: profile),
      _ProfileTab.about => ProfileAboutTab(
        profile: profile,
        onOpenLink: (url) => openExternalLink(context, url),
      ),
      _ProfileTab.packages => ProfilePackagesTab(
        profile: profile,
        onBook: _canBook && !blocked ? _bookPackage : null,
      ),
      _ProfileTab.insights => ProfileInsightsTab(
        profile: profile,
        onOpenAccount: _openSocial,
      ),
      _ProfileTab.reviews => ProfileReviewsTab(profile: profile),
    };
  }

  @override
  Widget build(BuildContext context) {
    final profile = _profile;
    final top = MediaQuery.paddingOf(context).top;

    // The server's flag, or a block made on another screen this session
    // before the profile has been fetched again.
    final blocked =
        !profile.isOwner &&
        (profile.isBlocked ||
            ref.watch(isBlockedProvider(profile.creator.userId)));

    final tabBar = TabBar(
      key: _tabBarKey,
      controller: _tabs,
      isScrollable: true,
      tabAlignment: TabAlignment.start,
      padding: EdgeInsets.symmetric(
        horizontal: AppBreakpoints.pageMargin(context) - AppSpacing.sm,
      ),
      labelPadding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
      tabs: [
        for (final tab in _ProfileTab.values)
          Tab(
            height: ProfileLayout.tabBarHeight,
            text: tab == _ProfileTab.reviews && profile.creator.ratingCount > 0
                ? '${tab.label} (${profile.creator.ratingCount})'
                : tab.label,
          ),
      ],
    );

    return Scaffold(
      backgroundColor: AppColors.surface,
      bottomNavigationBar: _ActionBar(
        profile: profile,
        onBook: _book,
        onShare: () => _shareProfile(context, ref, profile),
        blocked: blocked,
        onUnblock: _unblock,
      ),
      body: LayoutBuilder(
        builder: (context, constraints) {
          // A short tab still fills the screen below the pinned bars, so
          // switching to it never drags the tab bar back down the page.
          final minBody = math.max(
            0.0,
            constraints.maxHeight - ProfileLayout.pinnedExtent(context),
          );

          return ZRefresh(
            onRefresh: _refresh,
            edgeOffset: top + kToolbarHeight,
            child: CustomScrollView(
              controller: _scroll,
              slivers: [
                ProfileCoverAppBar(
                  profile: profile,
                  collapsed: _collapsed,
                  // The URL and the wording come from the server, which
                  // builds them from the creator's handle and the configured
                  // public path.
                  onShare: () => _shareProfile(context, ref, profile),
                  blocked: blocked,
                  // Report and block, for everyone but the creator.
                  overflow: profile.isOwner
                      ? null
                      : SafetyMenuButton(
                          target: ReportTarget.creator,
                          targetId: profile.creator.id,
                          name: profile.creator.displayName,
                          userId: profile.creator.userId > 0
                              ? profile.creator.userId
                              : null,
                          blocked: blocked,
                          onCover: true,
                        ),
                ),
                SliverToBoxAdapter(
                  child: ProfileIdentity(
                    profile: profile,
                    messaging: _messaging,
                    onMessage: _message,
                    onSocialTap: _openSocial,
                    onCategoryTap: _openCategory,
                    blocked: blocked,
                    onUnblock: _unblock,
                  ),
                ),
                SliverPersistentHeader(
                  key: _tabHeaderKey,
                  pinned: true,
                  delegate: ProfileTabBarDelegate(tabBar),
                ),
                SliverToBoxAdapter(
                  child: GestureDetector(
                    behavior: HitTestBehavior.translucent,
                    onHorizontalDragStart: (details) => _dragOnBody =
                        details.globalPosition.dy > _tabBarBottom(),
                    onHorizontalDragEnd: _onSwipeEnd,
                    child: ConstrainedBox(
                      constraints: BoxConstraints(minHeight: minBody),
                      child: AnimatedSwitcher(
                        duration: Motion.of(context, Motion.medium),
                        switchInCurve: Motion.standard,
                        switchOutCurve: Motion.exit,
                        layoutBuilder: (current, previous) => Stack(
                          alignment: Alignment.topCenter,
                          children: [...previous, ?current],
                        ),
                        child: KeyedSubtree(
                          key: ValueKey(_shown),
                          child: _body(_shown, blocked: blocked),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

/// Open the share sheet with the server's URL and wording, then count it.
///
/// The share payload is fetched on demand rather than eagerly with the profile
/// — most visitors never tap share, and the QR it carries is not free. If the
/// fetch fails the sheet still opens, because failing to share is a worse
/// outcome than sharing a slightly plainer message.
///
/// This used to compose the link here as "https://zuste.com/creator/{handle}"
/// — the wrong domain (one 'e') and the wrong segment ("creator", not
/// "creators"), so every link ever shared from the app was a 404. Deriving it
/// server-side means there is one definition of a profile URL rather than two
/// that can drift.
Future<void> _shareProfile(
  BuildContext context,
  WidgetRef ref,
  CreatorProfile profile,
) async {
  final creator = profile.creator;

  CreatorShare? payload;

  try {
    payload = await ref.read(creatorShareProvider(creator.id).future);
  } catch (_) {
    payload = null;
  }

  if (!context.mounted) return;

  // No locally-composed URL in the fallback, on purpose. Guessing the public
  // address here is what produced a dead link in every share the app has ever
  // sent; a message without a link is worse than one with a link, but far
  // better than one with a link that 404s.
  final text = payload?.text ?? 'Check out ${creator.displayName} on Zusty';
  final url = payload?.url;

  await SharePlus.instance.share(
    ShareParams(text: url == null ? text : '$text — $url'),
  );

  // Counted after the sheet is dismissed, and deliberately not awaited on the
  // UI path: the server ignores a creator sharing their own profile, so this
  // does not need to know who is looking.
  unawaited(
    ref
        .read(creatorRepositoryProvider)
        .recordEngagement(creator.id, eventType: 'profile_share'),
  );
}

/// The sticky bottom bar: the starting price and Book now for a visitor;
/// Share and Edit for the creator previewing their own page.
class _ActionBar extends StatelessWidget {
  const _ActionBar({
    required this.profile,
    required this.onBook,
    required this.onShare,
    this.blocked = false,
    this.onUnblock,
  });

  final CreatorProfile profile;
  final VoidCallback onBook;
  final VoidCallback onShare;

  /// The viewer blocked this creator: booking gives way to Unblock.
  final bool blocked;
  final VoidCallback? onUnblock;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final unavailable = creator.availability == Availability.unavailable;
    final starting = profile.startingPackage;

    // The cheapest package is the honest "from" price; without packages the
    // creator's own minimum rate, if they set one.
    final fromPrice =
        starting?.priceFormatted ??
        (creator.priceMin > 0 ? Formatters.currency(creator.priceMin) : null);

    final Widget content;

    if (profile.isOwner) {
      content = Row(
        children: [
          Expanded(
            child: ZButton.outlined(
              label: 'Share',
              icon: Icons.ios_share_rounded,
              onPressed: onShare,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: ZButton(
              label: 'Edit profile',
              icon: Icons.edit_outlined,
              onPressed: () => context.push(Routes.editProfile),
            ),
          ),
        ],
      );
    } else if (blocked) {
      content = ZButton.outlined(
        label: 'Unblock to book',
        icon: Icons.lock_open_rounded,
        onPressed: onUnblock,
      );
    } else {
      content = Row(
        children: [
          if (fromPrice != null && !unavailable) ...[
            Flexible(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Starting at',
                    maxLines: 1,
                    style: AppTypography.bodySmall,
                  ),
                  Text(
                    fromPrice,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.titleMedium.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: AppSpacing.md),
          ],
          Expanded(
            flex: 2,
            child: ZButton(
              label: unavailable ? 'Bookings paused' : 'Book now',
              icon: unavailable ? null : Icons.event_available_rounded,
              onPressed: unavailable ? null : onBook,
            ),
          ),
        ],
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        boxShadow: AppShadows.bottomBar,
      ),
      child: SafeArea(
        top: false,
        child: Align(
          heightFactor: 1,
          child: ConstrainedBox(
            constraints: const BoxConstraints(
              maxWidth: AppBreakpoints.maxContentWidth,
            ),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.marginMobile,
                AppSpacing.sm,
                AppSpacing.marginMobile,
                AppSpacing.sm,
              ),
              child: content,
            ),
          ),
        ),
      ),
    );
  }
}

/// The page's shape in shimmer — cover, avatar, name, stats, actions, tabs
/// and a grid — so the profile lands without anything jumping.
class _ProfileSkeleton extends StatelessWidget {
  const _ProfileSkeleton();

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);
    final top = MediaQuery.paddingOf(context).top;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Stack(
        children: [
          ListView(
            padding: EdgeInsets.zero,
            physics: const NeverScrollableScrollPhysics(),
            children: [
              SizedBox(
                height: top + ProfileLayout.expandedHeight,
                child: Stack(
                  children: [
                    Positioned(
                      top: 0,
                      left: 0,
                      right: 0,
                      bottom: ProfileLayout.avatarOverhang,
                      child: const ZShimmer(),
                    ),
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: Center(
                        child: Container(
                          padding: const EdgeInsets.all(4),
                          decoration: BoxDecoration(
                            color: AppColors.surface,
                            shape: BoxShape.circle,
                          ),
                          child: const ZShimmer(
                            width: ProfileLayout.avatarSize - 8,
                            height: ProfileLayout.avatarSize - 8,
                            borderRadius: AppRadius.pill,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: margin),
                child: const Column(
                  children: [
                    SizedBox(height: AppSpacing.md),
                    ZShimmer(
                      width: 180,
                      height: 26,
                      borderRadius: AppRadius.baseAll,
                    ),
                    SizedBox(height: AppSpacing.xs),
                    ZShimmer(
                      width: 140,
                      height: 14,
                      borderRadius: AppRadius.baseAll,
                    ),
                    SizedBox(height: AppSpacing.md),
                    ZShimmer(height: 76, borderRadius: AppRadius.xlAll),
                    SizedBox(height: AppSpacing.md),
                    ZShimmer(height: 46, borderRadius: AppRadius.pill),
                    SizedBox(height: AppSpacing.lg),
                    ZShimmer(height: 14, borderRadius: AppRadius.baseAll),
                    SizedBox(height: AppSpacing.xs),
                    ZShimmer(height: 14, borderRadius: AppRadius.baseAll),
                    SizedBox(height: AppSpacing.lg),
                    ListSkeleton(count: 2, height: 96),
                  ],
                ),
              ),
            ],
          ),
          // The way out is there before the content is.
          Positioned(
            top: top + (kToolbarHeight - AppSpacing.minTouch) / 2,
            left: margin,
            child: CoverButton(
              icon: Icons.arrow_back_ios_new_rounded,
              iconSize: 18,
              tooltip: 'Back',
              onPressed: () =>
                  context.canPop() ? context.pop() : context.go(Routes.home),
            ),
          ),
        ],
      ),
    );
  }
}
