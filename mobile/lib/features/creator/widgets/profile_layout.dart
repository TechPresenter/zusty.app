import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_card.dart';

/// Layout pieces shared by the creator profile's header and its tabs.
///
/// The profile is one scroll view: a collapsing cover, the identity block,
/// a tab bar pinned under the toolbar, and the selected tab's body. Everything
/// here exists to keep those parts agreeing on sizes — the tab bar's height,
/// the pinned extent a tab body has to fill below — in one place.
abstract final class ProfileLayout {
  /// Height of the cover photo below the status bar.
  static const double coverHeight = 184;

  static const double avatarSize = 104;

  /// How far the avatar hangs below the cover. Half the avatar, so its centre
  /// sits on the cover's bottom edge.
  static const double avatarOverhang = avatarSize / 2;

  /// The SliverAppBar's expanded height (it adds the status bar itself).
  static const double expandedHeight = coverHeight + avatarOverhang;

  static const double tabBarHeight = 48;

  /// What stays pinned at the top once the header has collapsed: status bar,
  /// toolbar and tab bar. A tab body is at least the viewport minus this, so
  /// switching to a short tab never yanks the pinned tabs back down.
  static double pinnedExtent(BuildContext context) =>
      MediaQuery.paddingOf(context).top + kToolbarHeight + tabBarHeight;

  /// Whether a cover [columnWidth] wide spans the window edge to edge: on a
  /// phone, and on a tablet no wider than the profile's readable column.
  ///
  /// When it does not (a 10-inch tablet, any tablet in landscape) the status
  /// bar's clock and battery sit over the page background either side of
  /// the cover, not over the photo, so light icons chosen for the photo all
  /// but vanish. Such a cover starts below the status bar instead
  /// ([coverTop]), the status bar keeps the page colour across the whole
  /// width, and its icons are drawn dark.
  static bool coverSpansWindow(BuildContext context, double columnWidth) =>
      Breakpoints.reaches(columnWidth, MediaQuery.sizeOf(context).width);

  /// Where the cover photo starts, from the top of the header: at the very
  /// top, under the status bar, on a cover that spans the window; below the
  /// status bar on one that does not ([coverSpansWindow]). Either way it is
  /// [coverHeight] tall below the status bar.
  static double coverTop(BuildContext context, {required bool spansWindow}) =>
      spansWindow ? 0 : MediaQuery.paddingOf(context).top;
}

/// A 44dp round button that reads on any photo: a near-white disc with a dark
/// glyph. Used over the cover for back and share, matching the favourite
/// button beside them.
class CoverButton extends StatelessWidget {
  const CoverButton({
    super.key,
    required this.icon,
    required this.tooltip,
    required this.onPressed,
    this.iconSize = 20,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;
  final double iconSize;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Semantics(
        button: true,
        label: tooltip,
        excludeSemantics: true,
        child: Pressable(
          enabled: onPressed != null,
          pressedScale: 0.9,
          child: Material(
            color: AppColors.surfaceContainerLowest.withValues(alpha: 0.92),
            shape: const CircleBorder(),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: onPressed,
              child: SizedBox(
                width: AppSpacing.minTouch,
                height: AppSpacing.minTouch,
                child: Icon(icon, size: iconSize, color: AppColors.onSurface),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Pins the profile's [TabBar] under the collapsed toolbar.
///
/// Paints its own surface so content scrolling underneath never shows through,
/// and lifts with a soft shadow only once something is actually beneath it.
class ProfileTabBarDelegate extends SliverPersistentHeaderDelegate {
  ProfileTabBarDelegate(this.tabBar);

  final TabBar tabBar;

  @override
  double get minExtent => ProfileLayout.tabBarHeight;

  @override
  double get maxExtent => ProfileLayout.tabBarHeight;

  @override
  Widget build(
    BuildContext context,
    double shrinkOffset,
    bool overlapsContent,
  ) {
    return AnimatedContainer(
      duration: Motion.of(context, Motion.fast),
      decoration: BoxDecoration(
        color: AppColors.surface,
        boxShadow: overlapsContent || shrinkOffset > 0
            ? AppShadows.appBar
            : null,
      ),
      child: Material(type: MaterialType.transparency, child: tabBar),
    );
  }

  @override
  bool shouldRebuild(ProfileTabBarDelegate oldDelegate) =>
      oldDelegate.tabBar != tabBar;
}

/// One tab's content under the pinned tab bar.
///
/// The profile is a single scroll view — cover, identity, pinned tabs, then
/// the selected tab's body — so a tab is a plain column rather than its own
/// scrollable. That keeps the toolbar and tab bar pinned however far the user
/// scrolls, and lets one pull-to-refresh cover the whole page.
class ProfileTabBody extends StatelessWidget {
  const ProfileTabBody({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return Padding(
      padding: EdgeInsets.fromLTRB(
        margin,
        AppSpacing.lg,
        margin,
        AppSpacing.xl,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }
}

/// A heading inside a profile tab: the shared [SectionHeader] look with the
/// spacing the tabs use between groups.
class ProfileSectionTitle extends StatelessWidget {
  const ProfileSectionTitle(
    this.title, {
    super.key,
    this.subtitle,
    this.actionLabel,
    this.onAction,
    this.first = false,
  });

  final String title;
  final String? subtitle;
  final String? actionLabel;
  final VoidCallback? onAction;

  /// The opening section of a tab, which needs no gap above it.
  final bool first;

  @override
  Widget build(BuildContext context) {
    return SectionHeader(
      title: title,
      subtitle: subtitle,
      actionLabel: actionLabel,
      onAction: onAction,
      padding: EdgeInsets.only(
        top: first ? 0 : AppSpacing.lg,
        bottom: AppSpacing.sm,
      ),
    );
  }
}

/// Parses a `#RRGGBB` the server sent, falling back to [fallback] (or the
/// app's primary) so an unstyled term still renders in-theme.
Color profileTint(String? hex, [Color? fallback]) {
  final value = hex?.replaceFirst('#', '');

  if (value == null || (value.length != 6 && value.length != 8)) {
    return fallback ?? AppColors.primary;
  }

  final parsed = int.tryParse(value, radix: 16);
  if (parsed == null) return fallback ?? AppColors.primary;

  return Color(value.length == 6 ? 0xFF000000 | parsed : parsed);
}

/// `service_cities` -> `Service cities`, for a key the server sends that this
/// app has no preferred label for.
String titleise(String key) {
  final words = key.replaceAll('_', ' ').trim();
  if (words.isEmpty) return key;

  return words[0].toUpperCase() + words.substring(1);
}

/// The first word of a display name, for copy like "Aanya's packages".
String firstNameOf(String displayName) {
  final trimmed = displayName.trim();
  if (trimmed.isEmpty) return 'This creator';

  return trimmed.split(RegExp(r'\s+')).first;
}

/// Opens [url] in the platform's browser or app, telling the user when it
/// cannot. Returns whether anything opened, so a caller can count the click
/// only when it happened.
Future<bool> openExternalLink(BuildContext context, String? url) async {
  final uri = url == null ? null : Uri.tryParse(url.trim());

  if (uri == null || !uri.hasScheme) {
    showZSnack(context, 'This link is not available.', isError: true);
    return false;
  }

  var opened = false;

  try {
    opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {
    opened = false;
  }

  if (!opened && context.mounted) {
    showZSnack(context, 'Could not open that link.', isError: true);
  }

  return opened;
}
