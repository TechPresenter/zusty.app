import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../providers/account_provider.dart';
import '../../providers/auth_provider.dart';
import '../../providers/location_provider.dart';
import '../../providers/theme_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/zuste_logo.dart';
import '../location/city_selector_sheet.dart';

/// The persistent bottom-navigation shell.
///
/// Five slots matching the Stitch designs: Home, Search, a raised centre action
/// that starts a new campaign, Bookings, and Profile.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.navigationShell});

  final StatefulNavigationShell navigationShell;

  static const _tabs = <_NavItem>[
    _NavItem(
      icon: Icons.home_outlined,
      activeIcon: Icons.home_rounded,
      label: 'Home',
    ),
    _NavItem(
      icon: Icons.search_rounded,
      activeIcon: Icons.search_rounded,
      label: 'Search',
    ),
    _NavItem(
      icon: Icons.add_rounded,
      activeIcon: Icons.add_rounded,
      label: 'Create',
      isAction: true,
    ),
    _NavItem(
      icon: Icons.calendar_today_outlined,
      activeIcon: Icons.calendar_month_rounded,
      label: 'Bookings',
    ),
    _NavItem(
      icon: Icons.person_outline_rounded,
      activeIcon: Icons.person_rounded,
      label: 'Profile',
    ),
  ];

  /// Maps a tab index onto the branch index, skipping the centre action which
  /// is not a branch of its own.
  static int _branchFor(int tabIndex) => tabIndex > 2 ? tabIndex - 1 : tabIndex;

  static int _tabFor(int branchIndex) =>
      branchIndex >= 2 ? branchIndex + 1 : branchIndex;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final currentTab = _tabFor(navigationShell.currentIndex);
    final unread = ref.watch(unreadNotificationsProvider);

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: navigationShell,
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: AppColors.surfaceContainerLowest,
          boxShadow: AppShadows.bottomBar,
          borderRadius: const BorderRadius.vertical(
            top: Radius.circular(AppRadius.xl),
          ),
        ),
        child: SafeArea(
          top: false,
          child: SizedBox(
            height: 68,
            child: Row(
              children: [
                for (var i = 0; i < _tabs.length; i++)
                  Expanded(
                    child: _tabs[i].isAction
                        ? _CentreAction(
                            onTap: () => showCreateSheet(context, ref),
                          )
                        : _NavButton(
                            item: _tabs[i],
                            selected: currentTab == i,
                            badge: _tabs[i].label == 'Profile' ? unread : 0,
                            onTap: () => _select(i),
                          ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  void _select(int tabIndex) {
    final branch = _branchFor(tabIndex);
    navigationShell.goBranch(
      branch,
      // Tapping the active tab pops that branch back to its root.
      initialLocation: branch == navigationShell.currentIndex,
    );
  }
}

class _NavItem {
  const _NavItem({
    required this.icon,
    required this.activeIcon,
    required this.label,
    this.isAction = false,
  });

  final IconData icon;
  final IconData activeIcon;
  final String label;
  final bool isAction;
}

class _NavButton extends StatelessWidget {
  const _NavButton({
    required this.item,
    required this.selected,
    required this.onTap,
    this.badge = 0,
  });

  final _NavItem item;
  final bool selected;
  final VoidCallback onTap;
  final int badge;

  @override
  Widget build(BuildContext context) {
    final color = selected ? AppColors.primary : AppColors.onSurfaceVariant;
    final medium = Motion.of(context, Motion.medium);
    final fast = Motion.of(context, Motion.fast);

    return Semantics(
      selected: selected,
      button: true,
      label: badge > 0 ? '${item.label}, $badge unread' : item.label,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        child: Pressable(
          pressedScale: 0.92,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Stack(
                clipBehavior: Clip.none,
                alignment: Alignment.center,
                children: [
                  // A soft pill behind the active tab's icon; it widens as
                  // the tab becomes active.
                  AnimatedContainer(
                    duration: medium,
                    curve: Motion.emphasized,
                    width: selected ? 52 : 32,
                    height: 30,
                    decoration: BoxDecoration(
                      color: selected
                          ? AppColors.primaryFixed
                          : Colors.transparent,
                      borderRadius: AppRadius.pill,
                    ),
                  ),
                  // Outline to filled as a cross-fade, with a slight lift
                  // when the tab becomes active.
                  AnimatedScale(
                    scale: selected ? 1.06 : 1.0,
                    duration: medium,
                    curve: Motion.standard,
                    child: AnimatedSwitcher(
                      duration: fast,
                      switchInCurve: Motion.standard,
                      switchOutCurve: Motion.exit,
                      transitionBuilder: (child, animation) => FadeTransition(
                        opacity: animation,
                        child: ScaleTransition(
                          scale: Tween(
                            begin: 0.85,
                            end: 1.0,
                          ).animate(animation),
                          child: child,
                        ),
                      ),
                      child: TweenAnimationBuilder<Color?>(
                        key: ValueKey(selected),
                        tween: ColorTween(end: color),
                        duration: fast,
                        builder: (context, tint, _) => Icon(
                          selected ? item.activeIcon : item.icon,
                          size: 24,
                          color: tint,
                        ),
                      ),
                    ),
                  ),
                  Positioned(
                    right: -6,
                    top: -4,
                    // The unread badge pops in, and pops again on a new count.
                    child: AnimatedSwitcher(
                      duration: medium,
                      switchInCurve: Motion.emphasized,
                      switchOutCurve: Motion.exit,
                      transitionBuilder: (child, animation) => ScaleTransition(
                        scale: animation,
                        child: FadeTransition(opacity: animation, child: child),
                      ),
                      child: badge <= 0
                          ? const SizedBox.shrink(key: ValueKey('no-badge'))
                          : Container(
                              key: ValueKey(badge > 9 ? 10 : badge),
                              padding: const EdgeInsets.symmetric(
                                horizontal: 4,
                                vertical: 1,
                              ),
                              constraints: const BoxConstraints(minWidth: 16),
                              decoration: BoxDecoration(
                                color: AppColors.primary,
                                borderRadius: AppRadius.pill,
                                border: Border.all(
                                  color: AppColors.surface,
                                  width: 1.5,
                                ),
                              ),
                              child: Text(
                                badge > 9 ? '9+' : '$badge',
                                textAlign: TextAlign.center,
                                style: AppTypography.labelSmall.copyWith(
                                  color: AppColors.onPrimary,
                                  fontSize: 9,
                                ),
                              ),
                            ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              AnimatedDefaultTextStyle(
                duration: fast,
                curve: Motion.standard,
                style: AppTypography.labelSmall.copyWith(
                  color: color,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                ),
                child: Text(item.label),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The raised pink circle in the middle of the bar.
class _CentreAction extends StatelessWidget {
  const _CentreAction({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    // Raised above the bar on a white ring, so it reads as the one primary
    // action rather than a fifth tab.
    return Center(
      child: Transform.translate(
        offset: const Offset(0, -14),
        child: Pressable(
          pressedScale: 0.92,
          child: Container(
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: AppColors.surfaceContainerLowest,
            ),
            child: DecoratedBox(
              decoration: const BoxDecoration(
                shape: BoxShape.circle,
                boxShadow: AppShadows.fab,
              ),
              child: Material(
                shape: const CircleBorder(),
                clipBehavior: Clip.antiAlias,
                child: Ink(
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [AppColors.primaryContainer, AppColors.primary],
                    ),
                  ),
                  child: InkWell(
                    onTap: onTap,
                    child: SizedBox(
                      width: 54,
                      height: 54,
                      child: Icon(
                        Icons.add_rounded,
                        color: AppColors.onPrimary,
                        size: 30,
                        semanticLabel: 'Create',
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The header on every primary shell screen.
///
/// Left: the full Zusty logo lockup. Right: the city selector, the theme
/// toggle, then whatever the screen supplies (notifications, avatar).
///
/// The logo replaces the screen title on the shell tabs — the bottom bar
/// already says which tab you are on, so the masthead is better spent on brand.
class ShellHeader extends ConsumerWidget implements PreferredSizeWidget {
  const ShellHeader({
    super.key,
    required this.title,
    this.actions,
    this.avatarUrl,
    this.onAvatarTap,
    this.showLocation = true,
    this.showThemeToggle = true,
  });

  final String title;
  final List<Widget>? actions;
  final String? avatarUrl;
  final VoidCallback? onAvatarTap;

  /// Hidden on screens where a city has no meaning (e.g. Bookings).
  final bool showLocation;
  final bool showThemeToggle;

  @override
  Size get preferredSize => const Size.fromHeight(60);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Below this the logo + city + toggle + actions stop fitting comfortably,
    // so the city chip collapses to an icon.
    final compact = MediaQuery.sizeOf(context).width < 400;

    return AppBar(
      backgroundColor: AppColors.surface,
      toolbarHeight: 60,
      titleSpacing: AppBreakpoints.pageMargin(context),
      // The masthead yields space to the actions rather than overflowing, so a
      // long city name or a large text scale can never break the header.
      title: FittedBox(
        fit: BoxFit.scaleDown,
        alignment: Alignment.centerLeft,
        child: ZusteLogoLockup(height: 32),
      ),
      actions: [
        if (showLocation) CityChip(compact: compact),
        if (showThemeToggle) const ThemeToggleButton(),
        ...?actions,
        SizedBox(width: AppBreakpoints.pageMargin(context) - 8),
      ],
    );
  }
}

/// The city selector in the header, to the left of the notification icon.
class CityChip extends ConsumerWidget {
  const CityChip({super.key, this.compact = false});

  /// Shows only the pin icon, for narrow phones.
  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final location = ref.watch(locationProvider);

    if (compact) {
      return IconButton(
        tooltip: location.hasCity ? location.label : 'Select city',
        onPressed: () => CitySelectorSheet.show(context),
        icon: Icon(
          location.hasCity
              ? Icons.location_on_rounded
              : Icons.add_location_alt_outlined,
          size: 21,
          color: location.hasCity
              ? AppColors.primary
              : AppColors.onSurfaceVariant,
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: Material(
        color: AppColors.surfaceContainerLow,
        shape: StadiumBorder(side: BorderSide(color: AppColors.outlineVariant)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => CitySelectorSheet.show(context),
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: AppSpacing.xs,
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (location.isDetecting)
                  SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(
                      strokeWidth: 1.8,
                      valueColor: AlwaysStoppedAnimation(AppColors.primary),
                    ),
                  )
                else
                  Icon(
                    location.source == LocationSource.detected
                        ? Icons.my_location_rounded
                        : Icons.location_on_rounded,
                    size: 15,
                    color: location.hasCity
                        ? AppColors.primary
                        : AppColors.onSurfaceVariant,
                  ),
                const SizedBox(width: 4),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 84),
                  child: Text(
                    location.label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.labelMedium.copyWith(
                      color: AppColors.onSurface,
                    ),
                  ),
                ),
                Icon(
                  Icons.keyboard_arrow_down_rounded,
                  size: 16,
                  color: AppColors.onSurfaceVariant,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Sun/moon switch for the light and dark palettes.
class ThemeToggleButton extends ConsumerWidget {
  const ThemeToggleButton({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final brightness = ref.watch(effectiveBrightnessProvider);
    final isDark = brightness == Brightness.dark;

    return IconButton(
      tooltip: isDark ? 'Switch to light mode' : 'Switch to dark mode',
      onPressed: () => ref.read(themeModeProvider.notifier).toggle(brightness),
      icon: AnimatedSwitcher(
        duration: const Duration(milliseconds: 220),
        transitionBuilder: (child, animation) => RotationTransition(
          turns: Tween(begin: 0.6, end: 1.0).animate(animation),
          child: FadeTransition(opacity: animation, child: child),
        ),
        child: Icon(
          isDark ? Icons.light_mode_rounded : Icons.dark_mode_outlined,
          key: ValueKey(isDark),
          size: 21,
          color: AppColors.onSurfaceVariant,
        ),
      ),
    );
  }
}

/// The "+" in the bottom bar: what this person can start, by role.
///
/// A brand creates campaigns and books creators; a creator finds campaigns and
/// keeps their profile bookable. Each entry is a real route, so nothing here
/// is a placeholder.
Future<void> showCreateSheet(BuildContext context, WidgetRef ref) {
  final isCreator = ref.read(authProvider).isCreator;

  final actions = isCreator
      ? <_CreateAction>[
          const _CreateAction(
            icon: Icons.campaign_rounded,
            title: 'Find campaigns',
            subtitle: 'Browse open briefs from brands and apply',
            route: Routes.creatorCampaigns,
          ),
          const _CreateAction(
            icon: Icons.event_available_rounded,
            title: 'Update availability',
            subtitle: 'Mark the days you can take on work',
            route: Routes.availability,
          ),
          const _CreateAction(
            icon: Icons.auto_awesome_rounded,
            title: 'Polish your profile',
            subtitle: 'Skills, experience and the details brands look for',
            route: Routes.creatorFields,
          ),
        ]
      : <_CreateAction>[
          const _CreateAction(
            icon: Icons.campaign_rounded,
            title: 'New campaign',
            subtitle: 'Post a brief and let creators apply',
            route: Routes.newCampaign,
          ),
          const _CreateAction(
            icon: Icons.person_search_rounded,
            title: 'Book a creator',
            subtitle: 'Browse creators and send a booking request',
            route: Routes.explore,
          ),
          const _CreateAction(
            icon: Icons.auto_awesome_rounded,
            title: 'Match with AI',
            subtitle: 'Describe what you need and get a shortlist',
            route: Routes.aiMatch,
          ),
        ];

  return showModalBottomSheet<void>(
    context: context,
    useRootNavigator: true,
    builder: (sheetContext) => SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.marginMobile,
          0,
          AppSpacing.marginMobile,
          AppSpacing.md,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Create', style: AppTypography.titleMedium),
            const SizedBox(height: 2),
            Text(
              isCreator
                  ? 'Find your next collaboration.'
                  : 'Start a collaboration with creators.',
              style: AppTypography.bodySmall,
            ),
            const SizedBox(height: AppSpacing.md),
            for (final action in actions) ...[
              _CreateTile(
                action: action,
                onTap: () {
                  Navigator.of(sheetContext).pop();
                  context.push(action.route);
                },
              ),
              const SizedBox(height: AppSpacing.xs),
            ],
          ],
        ),
      ),
    ),
  );
}

class _CreateAction {
  const _CreateAction({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.route,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final String route;
}

class _CreateTile extends StatelessWidget {
  const _CreateTile({required this.action, required this.onTap});

  final _CreateAction action;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surfaceContainerLowest,
      shape: RoundedRectangleBorder(
        borderRadius: AppRadius.lgAll,
        side: BorderSide(color: AppColors.outlineVariant),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: AppColors.primaryFixed,
                  borderRadius: AppRadius.mdAll,
                ),
                child: Icon(action.icon, color: AppColors.primary),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(action.title, style: AppTypography.titleSmall),
                    const SizedBox(height: 2),
                    Text(action.subtitle, style: AppTypography.bodySmall),
                  ],
                ),
              ),
              Icon(Icons.chevron_right_rounded, color: AppColors.outline),
            ],
          ),
        ),
      ),
    );
  }
}
