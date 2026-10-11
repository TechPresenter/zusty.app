import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config/app_config.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/creator_completion.dart';
import '../../models/menu.dart';
import '../../models/user.dart';
import '../../providers/account_provider.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/kyc_provider.dart';
import '../../providers/share_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/stat_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_html.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../kyc/widgets/kyc_status_style.dart';
import '../settings/widgets/account_sheets.dart';
import '../settings/widgets/appearance_picker.dart';
import '../settings/widgets/settings_group.dart';
import '../shell/app_shell.dart';
import '../wallet/widgets/wallet_sheets.dart';

/// Screen 20 — Profile (the Menu / More tab).
///
/// The profile hub: who you are and how complete your profile is, the money
/// at a glance, then every secondary destination grouped by what it is for —
/// your role's tools first, then the marketplace, payments and account.
/// Sections an operator builds in the admin panel's menu builder render in
/// the same style, after the compiled ones.
class MenuScreen extends ConsumerWidget {
  const MenuScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    // The verification centre's own reading — finer than `user.kycStatus`,
    // and the only source of the blue tick.
    final kyc = ref.watch(kycSummaryProvider);
    final unread = ref.watch(unreadNotificationsProvider);
    final version = ref.watch(appVersionProvider).valueOrNull;
    // Watched rather than read on tap, so whether the Play listing is live is
    // already known by the time somebody taps "Share Zusty".
    final shareText = ref.watch(shareAppTextProvider);
    final margin = AppBreakpoints.pageMargin(context);

    final isBrand = user?.role.isBrand ?? true;
    final isCreator =
        (user?.role.isCreator ?? false) || (user?.isCreator ?? false);

    return Scaffold(
      backgroundColor: AppColors.surface,
      // On a tablet the hub is a centred readable column, header included.
      appBar: const ContentWidthAppBar(child: ShellHeader(title: 'Profile')),
      body: ContentWidthBox(child: ListView(
        padding: EdgeInsets.fromLTRB(
          margin,
          AppSpacing.md,
          margin,
          AppSpacing.xxl,
        ),
        children: [
          // -- Identity ------------------------------------------------------
          if (user != null) _ProfileHeader(user: user, kyc: kyc),

          // -- Profile completion --------------------------------------------
          // Creators only, and only while there is something left to do. A
          // permanent "100% complete" banner is clutter; this disappears once
          // it has served its purpose.
          if (isCreator)
            ProfileCompletionCard(kycStatus: kyc?.status ?? user?.kycStatus),

          // -- Money at a glance ---------------------------------------------
          const SizedBox(height: AppSpacing.md),
          _QuickStats(
            isCreator: isCreator,
            unreadMessages: user?.unreadMessages ?? 0,
          ),

          // -- Role tools ------------------------------------------------------
          if (isBrand)
            SettingsGroup(
              title: 'For your brand',
              children: [
                ZMenuTile(
                  icon: Icons.space_dashboard_outlined,
                  title: 'Dashboard',
                  subtitle: 'Spend, campaigns and approvals',
                  onTap: () => context.push(Routes.dashboard),
                ),
                ZMenuTile(
                  icon: Icons.campaign_outlined,
                  title: 'My campaigns',
                  onTap: () => context.push(Routes.campaigns),
                ),
                ZMenuTile(
                  icon: Icons.compare_arrows_rounded,
                  title: 'Compare creators',
                  onTap: () => context.push(Routes.compare),
                ),
                ZMenuTile(
                  icon: Icons.receipt_long_outlined,
                  title: 'Invoices & billing',
                  onTap: () => context.push(Routes.invoices),
                ),
                ZMenuTile(
                  icon: Icons.business_outlined,
                  title: 'Business profile',
                  onTap: () => context.push(Routes.businessProfile),
                ),
              ],
            ),
          if (isCreator)
            SettingsGroup(
              title: 'For creators',
              children: [
                ZMenuTile(
                  icon: Icons.travel_explore_rounded,
                  title: 'Find campaigns',
                  subtitle: 'Open briefs from brands',
                  onTap: () => context.push(Routes.creatorCampaigns),
                ),
                ZMenuTile(
                  icon: Icons.assignment_outlined,
                  title: 'My applications',
                  onTap: () => context.push(Routes.applications),
                ),
                // What a brand books, what it looks at first, and where it
                // checks the creator's reach.
                ZMenuTile(
                  icon: Icons.inventory_2_outlined,
                  title: 'My packages',
                  subtitle: 'Fixed-price offers brands can pick',
                  onTap: () => context.push(Routes.myPackages),
                ),
                ZMenuTile(
                  icon: Icons.photo_library_outlined,
                  title: 'Portfolio',
                  subtitle: 'Your best work, shown on your profile',
                  onTap: () => context.push(Routes.myPortfolio),
                ),
                ZMenuTile(
                  icon: Icons.alternate_email_rounded,
                  title: 'Social accounts',
                  subtitle: 'Instagram, YouTube and other links',
                  onTap: () => context.push(Routes.socialAccounts),
                ),
                // Creators control which dates brands can book them on.
                ZMenuTile(
                  icon: Icons.event_available_outlined,
                  title: 'My availability',
                  onTap: () => context.push(Routes.availability),
                ),
                // Education, awards, experience, working hours and FAQs — the
                // list-shaped parts of the profile, which the edit-profile
                // form has no room for.
                ZMenuTile(
                  icon: Icons.workspace_premium_outlined,
                  title: 'Profile details',
                  subtitle: 'Experience, education, hours and FAQs',
                  onTap: () => context.push(Routes.creatorFields),
                ),
              ],
            ),

          // -- Marketplace -------------------------------------------------------
          SettingsGroup(
            title: 'Marketplace',
            children: [
              ZMenuTile(
                icon: Icons.calendar_month_outlined,
                title: 'My bookings',
                onTap: () => context.go(Routes.bookings),
              ),
              ZMenuTile(
                icon: Icons.favorite_border_rounded,
                title: 'Saved creators',
                onTap: () => context.push(Routes.saved),
              ),
              ZMenuTile(
                icon: Icons.forum_outlined,
                title: 'Messages',
                badge: user?.unreadMessages ?? 0,
                onTap: () => context.push(Routes.messages),
              ),
              ZMenuTile(
                icon: Icons.notifications_none_rounded,
                title: 'Notifications',
                badge: unread,
                onTap: () => context.push(Routes.notifications),
              ),
              ZMenuTile(
                icon: Icons.category_outlined,
                title: 'Browse categories',
                onTap: () => context.push(Routes.categories),
              ),
            ],
          ),

          // -- Money -------------------------------------------------------------
          SettingsGroup(
            title: 'Payments',
            children: [
              ZMenuTile(
                icon: Icons.account_balance_wallet_outlined,
                title: 'Wallet & transactions',
                onTap: () => context.push(Routes.wallet),
              ),
              ZMenuTile(
                icon: Icons.account_balance_outlined,
                title: 'Payment methods',
                subtitle: 'Bank accounts for payouts',
                onTap: () => BankAccountsSheet.show(context),
              ),
              ZMenuTile(
                icon: Icons.card_giftcard_rounded,
                title: 'Invite & earn',
                subtitle: 'Share your code and earn rewards',
                onTap: () => context.push(Routes.invite),
              ),
            ],
          ),

          // -- Account -------------------------------------------------------------
          SettingsGroup(
            title: 'Account',
            children: [
              ZMenuTile(
                icon: Icons.person_outline_rounded,
                title: 'Edit profile',
                onTap: () => context.push(Routes.editProfile),
              ),
              // Verifying, changing, and adding a second email or number.
              ZMenuTile(
                icon: Icons.alternate_email_rounded,
                title: 'Email & mobile',
                onTap: () => context.push(Routes.contacts),
              ),
              ZMenuTile(
                icon: Icons.verified_user_outlined,
                title: 'Verification',
                value: kyc?.label ?? user?.kycStatus.label,
                onTap: () => context.push(Routes.kyc),
              ),
              ZMenuTile(
                icon: Icons.settings_outlined,
                title: 'Settings & privacy',
                subtitle: 'Notifications, password, sessions',
                onTap: () => context.push(Routes.settings),
              ),
              ZMenuTile(
                icon: Icons.shield_outlined,
                title: 'Privacy & data',
                subtitle: 'Download your data or delete your account',
                onTap: () => context.push(Routes.privacy),
              ),
            ],
          ),

          // -- Appearance -------------------------------------------------------------
          const _AppearanceCard(),

          // -- Support ----------------------------------------------------------------
          SettingsGroup(
            title: 'Support',
            children: [
              ZMenuTile(
                icon: Icons.support_agent_rounded,
                title: 'Help & support',
                onTap: () => context.push(Routes.support),
              ),
              ZMenuTile(
                icon: Icons.help_outline_rounded,
                title: 'FAQs',
                onTap: () => context.push(Routes.faqs),
              ),
              ZMenuTile(
                icon: Icons.ios_share_rounded,
                title: 'Share Zusty',
                showChevron: false,
                onTap: () => SharePlus.instance.share(
                  ShareParams(text: shareText),
                ),
              ),
            ],
          ),

          // -- Admin-managed --------------------------------------------------------
          // Everything above is compiled in because it is behaviour, not
          // content — the theme toggle, the share sheet, sign-out. Everything
          // here comes from the admin panel's menu builder, so an operator can
          // add a destination and have it appear on the next resume with no
          // release. Absent until an admin builds one.
          const _AdminMenuSections(),

          // -- Legal ---------------------------------------------------------------
          SettingsGroup(
            title: 'Legal',
            children: [
              ZMenuTile(
                icon: Icons.description_outlined,
                title: 'Terms of Service',
                onTap: () => context.push(Routes.page('terms')),
              ),
              ZMenuTile(
                icon: Icons.privacy_tip_outlined,
                title: 'Privacy Policy',
                onTap: () => context.push(Routes.page('privacy')),
              ),
              ZMenuTile(
                icon: Icons.info_outline_rounded,
                title: 'About Zusty',
                onTap: () => context.push(Routes.page('about')),
              ),
            ],
          ),

          // -- Sign out --------------------------------------------------------------
          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: 'Log out',
            variant: ZButtonVariant.outlined,
            icon: Icons.logout_rounded,
            onPressed: () => confirmAndLogout(context, ref),
          ),
          const SizedBox(height: AppSpacing.md),
          Center(
            child: Text(
              version == null
                  ? AppConfig.appName
                  : '${AppConfig.appName} · v$version',
              style: AppTypography.bodySmall,
            ),
          ),
        ],
      )),
    );
  }
}

/// Who is signed in: photo, name, verification, role, and the way to edit it.
///
/// A soft blush band sits behind the avatar — the one gradient on the screen,
/// so the header reads as the top of a profile rather than another list card.
class _ProfileHeader extends StatelessWidget {
  const _ProfileHeader({required this.user, this.kyc});

  final User user;

  /// Null until the verification overview has loaded.
  final KycSummary? kyc;

  static const _band = 76.0;
  static const _avatar = 80.0;

  @override
  Widget build(BuildContext context) {
    final kycStatus = kyc?.status ?? user.kycStatus;
    final kycApproved = kycStatus == KycStatus.approved;
    // The tick beside the name is the blue tick, which the team grants by
    // hand after approval — so approval alone no longer draws it.
    final hasBlueTick = kyc?.hasBlueTick ?? false;
    final contact = user.email?.isNotEmpty == true
        ? user.email!
        : user.fullPhone;

    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            height: _band + _avatar / 2 + AppSpacing.xs,
            child: Stack(
              children: [
                Container(
                  height: _band,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [
                        AppColors.primaryFixed,
                        AppColors.secondaryFixed,
                      ],
                    ),
                  ),
                ),
                Positioned(
                  left: AppSpacing.md,
                  top: _band - _avatar / 2,
                  child: ZAvatar(
                    imageUrl: user.avatarUrl,
                    name: user.displayName,
                    size: _avatar,
                    ringWidth: 3,
                    ringColor: AppColors.surfaceContainerLowest,
                  ),
                ),
                Positioned(
                  right: AppSpacing.md,
                  top: _band + AppSpacing.sm,
                  child: ZButton.tonal(
                    label: 'Edit profile',
                    icon: Icons.edit_outlined,
                    size: ZButtonSize.small,
                    expand: false,
                    onPressed: () => context.push(Routes.editProfile),
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.md,
              AppSpacing.xs,
              AppSpacing.md,
              AppSpacing.md,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        user.displayName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.headlineSmall,
                      ),
                    ),
                    if (hasBlueTick) ...[
                      const SizedBox(width: 6),
                      const VerifiedBadge(size: 18),
                    ],
                  ],
                ),
                if (contact.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    contact,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodyMedium.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ],
                const SizedBox(height: AppSpacing.sm),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    StatusChip(
                      label: user.role.label,
                      tone: StatusTone.neutral,
                      icon: user.role.isCreator
                          ? Icons.auto_awesome_rounded
                          : Icons.business_center_rounded,
                      dense: true,
                    ),
                    StatusChip(
                      label: kyc?.label ?? user.kycStatus.label,
                      tone: kycStatus.tone,
                      icon: kycApproved
                          ? Icons.verified_user_rounded
                          : Icons.shield_outlined,
                      dense: true,
                    ),
                    if (user.joinedAt != null)
                      Text(
                        'Joined ${Formatters.monthYear(user.joinedAt)}',
                        style: AppTypography.bodySmall,
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

/// The wallet in three figures, tapping through to it. Brands see what is
/// spendable and what is on hold; creators see what they can
/// withdraw, what is still clearing, and what they have earned in total.
class _QuickStats extends ConsumerWidget {
  const _QuickStats({required this.isCreator, required this.unreadMessages});

  final bool isCreator;
  final int unreadMessages;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final wallet = ref.watch(walletProvider);

    return wallet.when(
      loading: () => const ZShimmer(height: 84, borderRadius: AppRadius.xlAll),
      // A wallet that will not load should not take the profile down with
      // it; the Wallet row below still leads to the full error and a retry.
      error: (_, _) => const SizedBox.shrink(),
      data: (data) => ZCard(
        onTap: () => context.push(Routes.wallet),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.xs,
          vertical: AppSpacing.md,
        ),
        child: StatStrip(
          items: isCreator
              ? [
                  (data.balanceLabel, 'Available'),
                  (data.pendingLabel, 'Pending'),
                  (data.lifetimeLabel, 'Earned'),
                ]
              : [
                  (data.balanceLabel, 'Wallet'),
                  (data.escrowLabel, 'On hold'),
                  ('$unreadMessages', 'Unread chats'),
                ],
        ),
      ),
    );
  }
}

/// Profile completion, and what to do next.
///
/// Every label and percentage here is the operator's own wording from the
/// server. The card composes no explanation of its own, so retuning a
/// threshold in the admin panel changes what a creator reads with no release.
///
/// Every section it lists opens the screen where that section is finished,
/// and a section no screen in this app can finish is not listed at all: a
/// row that leads nowhere is the dead end this card used to be, when the
/// mobile-number rules sat on it as mandatory while SMS was off. It does not
/// claim a feature is locked either, because nothing on the platform
/// withholds one by completion.
///
/// Renders nothing at all when nothing listed is left to do — a card that
/// says "you are done" is clutter on every visit.
class ProfileCompletionCard extends ConsumerWidget {
  const ProfileCompletionCard({super.key, this.kycStatus});

  /// Where the creator's verification stands, when known. A KYC gap opens
  /// the verification screen only while there is something to do there
  /// ([KycStatus.needsAction]): under review the forms are locked, and once
  /// approved the badge follows on the server.
  final KycStatus? kycStatus;

  /// What the app can finish, by `section.rule_key`, for the rules the
  /// platform ships with. Null marks a rule no screen here can meet: the
  /// creator's basics, bio and categories have no editor in the app, a cover
  /// image or gallery upload has no picker, and a verified account, a
  /// featured piece or the blue tick are the team's to give.
  static const _ruleRoutes = <String, String?>{
    'basic.display_name': null,
    'basic.handle': null,
    'basic.headline': null,
    'basic.city': null,
    'basic.state': null,
    'basic.languages': null,
    'basic.gender': null,
    'basic.date_of_birth': null,
    'contact.email': Routes.contacts,
    'contact.email_verified': Routes.contacts,
    'contact.phone': Routes.contacts,
    'contact.phone_verified': Routes.contacts,
    'social.linked_account': Routes.socialAccounts,
    'social.primary_account': null,
    'social.verified_account': null,
    'portfolio.work_samples': Routes.myPortfolio,
    'portfolio.featured_sample': null,
    'kyc.kyc_submitted': Routes.kyc,
    'kyc.kyc_approved': Routes.kyc,
    'bio.about': null,
    'bio.about_detailed': null,
    'gallery.profile_photo': Routes.editProfile,
    'gallery.cover_image': null,
    'gallery.gallery_media': null,
    'pricing.price_min': Routes.myPackages,
    'pricing.price_max': Routes.myPackages,
    'pricing.package': Routes.myPackages,
    'categories.primary_category': null,
    'categories.sub_categories': null,
    // The email badge follows a confirmed address (see [_earnedVia]), the
    // identity badge an approved KYC.
    'verification.email_badge': Routes.contacts,
    'verification.mobile_badge': Routes.contacts,
    'verification.identity_badge': Routes.kyc,
    'verification.creator_badge': null,
  };

  /// Rules met by finishing a rule in another section, by `section.rule_key`:
  /// that section, and the rules in it the screen can still help with.
  ///
  /// Email & mobile can earn the email badge only while the contact section
  /// still lists the address or its confirmation as missing. Once the address
  /// is confirmed the badge follows on the server and there is nothing left
  /// to do on that screen, so the rule opens nothing rather than a screen
  /// that already says "confirmed".
  static const _earnedVia = <String, (String, List<String>)>{
    'verification.email_badge': ('contact', ['email', 'email_verified']),
    'verification.mobile_badge': ('contact', ['phone', 'phone_verified']),
  };

  /// The screen for a rule this build does not know (one an operator added
  /// later), by its section. Sections with no editor in the app are absent.
  static const _sectionRoutes = <String, String>{
    'contact': Routes.contacts,
    'social': Routes.socialAccounts,
    'portfolio': Routes.myPortfolio,
    'kyc': Routes.kyc,
    'pricing': Routes.myPackages,
    'verification': Routes.kyc,
  };

  /// Where in the app the creator can finish [section]: the screen for its
  /// first unmet rule that one can meet, or null when none can. [all] is
  /// every section of the breakdown, which decides the rules in [_earnedVia].
  /// [kyc] is the creator's verification status, when known: the KYC screen
  /// is offered only while it [KycStatus.needsAction].
  static String? routeFor(
    CompletionSection section, {
    List<CompletionSection> all = const [],
    KycStatus? kyc,
  }) {
    bool usable(String? route) =>
        route != null &&
        (route != Routes.kyc || kyc == null || kyc.needsAction);

    final fallback = _sectionRoutes[section.section];

    if (section.missingKeys.isEmpty) return usable(fallback) ? fallback : null;

    for (final rule in section.missingKeys) {
      final key = '${section.section}.$rule';
      final route = _ruleRoutes.containsKey(key) ? _ruleRoutes[key] : fallback;
      if (!usable(route)) continue;

      final via = _earnedVia[key];
      if (via != null && !_stillMissing(all, via.$1, via.$2)) continue;

      return route;
    }

    return null;
  }

  /// Does [all] list one of [rules] in [section] as missing?
  static bool _stillMissing(
    List<CompletionSection> all,
    String section,
    List<String> rules,
  ) => all.any(
    (other) =>
        other.section == section && other.missingKeys.any(rules.contains),
  );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final completion = ref.watch(creatorCompletionProvider).valueOrNull;

    if (completion == null) return const SizedBox.shrink();

    final gaps = [
      for (final section in completion.incomplete)
        if (routeFor(section, all: completion.sections, kyc: kycStatus)
            case final route?)
          (section: section, route: route),
    ];

    if (gaps.isEmpty) return const SizedBox.shrink();

    // Below the operator's required percentage the profile is not merely
    // improvable, it is failing a rule — worth saying in the error colour
    // rather than the same neutral prompt as a nice-to-have. "Needed to go
    // live" is said only of a profile that is not live yet.
    final short = !completion.meetsRequirement;
    final tint = short ? AppColors.error : AppColors.primary;

    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.md),
      child: ZCard(
        onTap: () => context.push(gaps.first.route),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Profile ${completion.overall}% complete',
                        style: AppTypography.titleMedium,
                      ),
                      if (short && completion.status != 'approved')
                        Text(
                          '${completion.requiredPercent}% needed to go live',
                          style: AppTypography.labelMedium.copyWith(
                            color: tint,
                          ),
                        ),
                    ],
                  ),
                ),
                Icon(
                  Icons.chevron_right_rounded,
                  size: 22,
                  color: AppColors.outline,
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            ClipRRect(
              borderRadius: AppRadius.pill,
              child: LinearProgressIndicator(
                value: completion.overall / 100,
                minHeight: 8,
                backgroundColor: AppColors.surfaceContainerHigh,
                valueColor: AlwaysStoppedAnimation<Color>(tint),
              ),
            ),

            // The two sections worth the most, so the prompt is specific
            // rather than "complete your profile", each opening its screen.
            const SizedBox(height: AppSpacing.xs),
            for (final gap in gaps.take(2))
              Semantics(
                button: true,
                label: 'Finish ${gap.section.label}, ${gap.section.percent}%',
                excludeSemantics: true,
                child: InkWell(
                  onTap: () => context.push(gap.route),
                  borderRadius: AppRadius.mdAll,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(
                      minHeight: AppSpacing.minTouch,
                    ),
                    child: Row(
                      children: [
                        Icon(
                          gap.section.blocksProfile
                              ? Icons.error_outline_rounded
                              : Icons.radio_button_unchecked_rounded,
                          size: 16,
                          color: gap.section.blocksProfile
                              ? AppColors.error
                              : AppColors.onSurfaceVariant,
                        ),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            '${gap.section.label} · ${gap.section.percent}%',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.bodySmall,
                          ),
                        ),
                        Icon(
                          Icons.chevron_right_rounded,
                          size: 18,
                          color: AppColors.outline,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// The admin panel's menu builder, rendered.
///
/// The builder — reorder, nest, target an audience, badge, five link types —
/// had existed since the panel was written and nothing read a row of it. Flat
/// entries become one "More" section; an entry with children becomes a section
/// of its own titled with the parent's label, which is what nesting in the
/// builder is for.
class _AdminMenuSections extends ConsumerWidget {
  const _AdminMenuSections();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final entries = ref.watch(appDrawerMenuProvider);

    if (entries.isEmpty) return const SizedBox.shrink();

    final flat = entries.where((e) => !e.hasChildren).toList();
    final grouped = entries.where((e) => e.hasChildren).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (flat.isNotEmpty)
          SettingsGroup(
            title: 'More',
            children: [for (final entry in flat) _adminItem(context, entry)],
          ),
        for (final parent in grouped)
          SettingsGroup(
            title: parent.label,
            children: [
              for (final child in parent.children) _adminItem(context, child),
            ],
          ),
      ],
    );
  }

  Widget _adminItem(BuildContext context, MenuEntry entry) {
    final badge = entry.badgeText;

    return ZMenuTile(
      icon: entry.iconData,
      title: entry.label,
      onTap: entry.isLabelOnly ? null : () => _open(context, entry),
      trailing: badge == null || badge.isEmpty
          ? null
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                _AdminBadge(text: badge, tint: entry.badgeTint),
                if (!entry.isLabelOnly)
                  Padding(
                    padding: const EdgeInsets.only(left: AppSpacing.xs),
                    child: Icon(
                      Icons.chevron_right_rounded,
                      size: 22,
                      color: AppColors.outline,
                    ),
                  ),
              ],
            ),
    );
  }

  Future<void> _open(BuildContext context, MenuEntry entry) async {
    if (entry.isLabelOnly) return;

    final value = entry.linkValue ?? '';

    switch (entry.linkType) {
      case 'url':
        final uri = Uri.tryParse(value);

        // Only http(s). A menu row is admin-authored content, and letting it
        // hand an arbitrary scheme to the platform launcher turns the drawer
        // into a way to fire intents the app never intended.
        if (uri == null || (uri.scheme != 'http' && uri.scheme != 'https')) {
          _unavailable(context);
          return;
        }

        final launched = await launchUrl(
          uri,
          mode: LaunchMode.externalApplication,
        );

        if (!launched && context.mounted) _unavailable(context);

      case 'page':
        if (context.mounted) context.push(Routes.page(value));

      case 'category':
        if (context.mounted) context.push(Routes.categories);

      // 'screen', and anything a newer admin panel invents.
      default:
        final target = Routes.resolveMenuTarget(value);

        if (target == null) {
          _unavailable(context);
          return;
        }

        if (context.mounted) context.push(target);
    }
  }

  /// An installed app can be older than the panel that configured the menu.
  /// Saying so beats navigating somewhere wrong, and beats a crash.
  void _unavailable(BuildContext context) {
    if (!context.mounted) return;

    showZSnack(
      context,
      'That link needs a newer version of the app.',
      isError: true,
    );
  }
}

/// An admin-set badge ("New"), in the admin's colour when one was chosen.
class _AdminBadge extends StatelessWidget {
  const _AdminBadge({required this.text, this.tint});

  final String text;
  final Color? tint;

  @override
  Widget build(BuildContext context) {
    final background = tint ?? AppColors.primary;
    // Readable on whatever colour the admin picked.
    final foreground =
        ThemeData.estimateBrightnessForColor(background) == Brightness.dark
        ? AppColors.onPrimary
        : AppColors.onSurface;

    return Container(
      constraints: const BoxConstraints(maxWidth: 96),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: background,
        borderRadius: AppRadius.pill,
      ),
      child: Text(
        text,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: AppTypography.labelSmall.copyWith(
          color: foreground,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

/// Appearance controls: an explicit Light / Dark / System choice.
class _AppearanceCard extends StatelessWidget {
  const _AppearanceCard();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(
              left: AppSpacing.xs,
              bottom: AppSpacing.xs,
            ),
            child: Text(
              'Appearance',
              style: AppTypography.labelLarge.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ),
          const ZCard(
            shadow: false,
            padding: EdgeInsets.all(AppSpacing.sm),
            child: AppearancePicker(),
          ),
        ],
      ),
    );
  }
}

/// A CMS page rendered from `GET /pages/{slug}` — Terms, Privacy, About.
class CmsPageScreen extends ConsumerWidget {
  const CmsPageScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final page = ref.watch(cmsPageProvider(slug));
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      appBar: ZAppBar(
        title: page.maybeWhen(
          data: (data) => data.title,
          orElse: () => AppConfig.appName,
        ),
      ),
      body: AsyncView(
        value: page,
        onRetry: () => ref.invalidate(cmsPageProvider(slug)),
        loading: Padding(
          padding: EdgeInsets.all(margin),
          child: const ListSkeleton(count: 4, height: 96),
        ),
        builder: (data) => ListView(
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.md,
            margin,
            AppSpacing.xxl,
          ),
          children: [
            Text(data.title, style: AppTypography.headlineMedium),
            if (data.updatedAt != null) ...[
              const SizedBox(height: AppSpacing.base),
              Text(
                'Last updated ${Formatters.date(data.updatedAt)}',
                style: AppTypography.bodySmall,
              ),
            ],
            const SizedBox(height: AppSpacing.md),
            // The admin panel's editor saves HTML. Rendered, not printed:
            // showing it as text put every <h2> and <p> on the screen.
            ZCard(
              shadow: false,
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: ZHtml(
                data.content,
                style: AppTypography.bodyLarge.copyWith(
                  color: AppColors.onSurface,
                  height: 1.6,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
