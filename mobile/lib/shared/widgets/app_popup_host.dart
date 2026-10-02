import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/theme/app_theme.dart';
import '../../models/app_settings.dart';
import '../../providers/app_config_provider.dart';
import '../../router/routes.dart';
import 'states.dart';
import 'z_button.dart';
import 'z_image.dart';

/// Renders the admin panel's popups over a screen.
///
/// The server does the eligibility work — audience, schedule window, placement
/// filter — and used to have no counterpart at all in the app: every popup an
/// operator built was delivered on every config fetch and dropped. This is that
/// counterpart. It honours what is genuinely a client decision:
///
///   * **placement** — only popups for [placement] (or `all`) are considered;
///   * **trigger** — `immediate`, `delay` (seconds), `scroll` (percent of the
///     wrapped scroll view), `session_count`. `exit_intent` has no mobile
///     equivalent and is skipped rather than guessed at;
///   * **frequency** — `always`, `once`, `once_per_session`, `once_per_day`,
///     persisted through [PopupSeenStore] so `once` survives a relaunch;
///   * **is_dismissible** — a non-dismissible popup has no close affordance and
///     ignores a barrier tap.
///
/// Wrap the screen's body so the scroll trigger can see the scroll view:
/// ```dart
/// body: AppPopupHost(placement: 'home', child: ListView(...))
/// ```
class AppPopupHost extends ConsumerStatefulWidget {
  const AppPopupHost({
    super.key,
    required this.placement,
    required this.child,
  });

  /// One of the panel's placement names: `home`, `search`, `creator`,
  /// `booking`, `wallet`, `checkout`.
  final String placement;

  final Widget child;

  @override
  ConsumerState<AppPopupHost> createState() => _AppPopupHostState();
}

class _AppPopupHostState extends ConsumerState<AppPopupHost> {
  /// Popups this host has already acted on, so a rebuild does not re-fire them.
  final _handled = <int>{};

  /// Scroll-triggered popups still waiting for the user to get far enough.
  final _awaitingScroll = <AppPopup>[];

  AppPopup? _inline;
  AppPopup? _slideIn;
  bool _modalOpen = false;

  /// The popup set this host was last scheduled against.
  String _scheduledFor = '';

  @override
  Widget build(BuildContext context) {
    final popups = ref.watch(popupsForPlacementProvider(widget.placement));
    final signature = popups.map((p) => p.id).join(',');

    if (signature != _scheduledFor) {
      _scheduledFor = signature;
      WidgetsBinding.instance.addPostFrameCallback((_) => _schedule(popups));
    }

    return Stack(
      children: [
        Positioned.fill(
          child: NotificationListener<ScrollNotification>(
            onNotification: _onScroll,
            child: Column(
              children: [
                if (_inline != null)
                  _AnnouncementBar(
                    popup: _inline!,
                    onTap: () => _activate(_inline!),
                    onDismiss: _inline!.isDismissible
                        ? () => setState(() => _inline = null)
                        : null,
                  ),
                Expanded(child: widget.child),
              ],
            ),
          ),
        ),
        if (_slideIn != null)
          Positioned(
            left: AppSpacing.md,
            right: AppSpacing.md,
            bottom: AppSpacing.md,
            child: _SlideInCard(
              popup: _slideIn!,
              onTap: () => _activate(_slideIn!),
              onDismiss: _slideIn!.isDismissible
                  ? () => setState(() => _slideIn = null)
                  : null,
            ),
          ),
      ],
    );
  }

  bool _onScroll(ScrollNotification notification) {
    if (_awaitingScroll.isEmpty) return false;

    final metrics = notification.metrics;
    if (metrics.maxScrollExtent <= 0) return false;

    final percent = (metrics.pixels / metrics.maxScrollExtent * 100).clamp(
      0.0,
      100.0,
    );

    final ready = _awaitingScroll
        .where((popup) => percent >= popup.scrollPercent)
        .toList();

    for (final popup in ready) {
      _awaitingScroll.remove(popup);
      _present(popup);
    }

    return false;
  }

  void _schedule(List<AppPopup> popups) {
    if (!mounted) return;

    final seen = ref.read(popupSeenStoreProvider);

    for (final popup in popups) {
      if (!_handled.add(popup.id)) continue;
      if (seen.hasShown(popup)) continue;

      switch (popup.trigger) {
        case 'delay':
          Future.delayed(popup.delay, () {
            if (mounted) _present(popup);
          });

        case 'scroll':
          _awaitingScroll.add(popup);

        case 'session_count':
          if (seen.sessionNumber >= popup.triggerValue) _present(popup);

        // 'exit_intent' has no mobile equivalent — a phone has no cursor
        // leaving the viewport, and firing it on a back gesture would show a
        // modal on the way out of the app. Skipped rather than approximated.
        case 'exit_intent':
          break;

        // 'immediate', and anything a newer panel invents.
        default:
          _present(popup);
      }
    }
  }

  void _present(AppPopup popup) {
    if (!mounted) return;

    // The frequency record is written when the popup is actually shown, not
    // when it was scheduled — a delayed popup the user never waited for has
    // not been seen.
    ref.read(popupSeenStoreProvider).markShown(popup);

    if (popup.isInline) {
      if (_inline == null) setState(() => _inline = popup);
      return;
    }

    if (popup.isSlideIn) {
      if (_slideIn == null) setState(() => _slideIn = popup);
      return;
    }

    if (_modalOpen) return;
    _modalOpen = true;

    showDialog<void>(
      context: context,
      barrierDismissible: popup.isDismissible,
      builder: (_) => _PopupDialog(
        popup: popup,
        onCta: () => _activate(popup),
      ),
    ).whenComplete(() => _modalOpen = false);
  }

  /// Runs a popup's call to action, then closes it.
  Future<void> _activate(AppPopup popup) async {
    final navigator = Navigator.of(context);

    if (_modalOpen && navigator.canPop()) navigator.pop();
    setState(() {
      if (_inline?.id == popup.id) _inline = null;
      if (_slideIn?.id == popup.id) _slideIn = null;
    });

    switch (popup.ctaAction) {
      case 'url':
        final uri = Uri.tryParse(popup.ctaValue ?? '');

        // Only http(s). A popup is admin-authored content, and handing an
        // arbitrary scheme to the platform launcher turns it into a way to
        // fire intents the app never intended.
        if (uri == null || (uri.scheme != 'http' && uri.scheme != 'https')) {
          _unavailable();
          return;
        }

        final launched =
            await launchUrl(uri, mode: LaunchMode.externalApplication);
        if (!launched) _unavailable();

      case 'screen':
        final target = Routes.resolveMenuTarget(popup.ctaValue);
        if (target == null) {
          _unavailable();
          return;
        }
        if (mounted) context.push(target);

      // 'dismiss' and 'none' — closing was the whole action.
      default:
        break;
    }
  }

  /// An installed app can be older than the panel that authored the popup.
  void _unavailable() {
    if (!mounted) return;

    showZSnack(
      context,
      'That link needs a newer version of the app.',
      isError: true,
    );
  }
}

/// A full-width strip pinned above the screen's content.
class _AnnouncementBar extends StatelessWidget {
  const _AnnouncementBar({
    required this.popup,
    required this.onTap,
    this.onDismiss,
  });

  final AppPopup popup;
  final VoidCallback onTap;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    final background = popup.background ?? AppColors.primaryFixed;
    final foreground =
        popup.textColor ?? _readableOn(background, AppColors.onPrimaryFixedVariant);

    return Material(
      color: background,
      child: InkWell(
        onTap: popup.ctaAction == 'none' ? null : onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.xs + 2,
          ),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (popup.title.isNotEmpty)
                      Text(
                        popup.title,
                        style: AppTypography.labelMedium
                            .copyWith(color: foreground),
                      ),
                    if (popup.plainContent.isNotEmpty)
                      Text(
                        popup.plainContent,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.labelSmall
                            .copyWith(color: foreground),
                      ),
                  ],
                ),
              ),
              if (popup.ctaLabel != null) ...[
                const SizedBox(width: AppSpacing.xs),
                Text(
                  popup.ctaLabel!,
                  style: AppTypography.labelMedium.copyWith(
                    color: foreground,
                    decoration: TextDecoration.underline,
                    decorationColor: foreground,
                  ),
                ),
              ],
              if (onDismiss != null)
                IconButton(
                  onPressed: onDismiss,
                  visualDensity: VisualDensity.compact,
                  tooltip: 'Dismiss',
                  icon: Icon(Icons.close_rounded, size: 18, color: foreground),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A card that slides up over the bottom of the screen.
class _SlideInCard extends StatelessWidget {
  const _SlideInCard({
    required this.popup,
    required this.onTap,
    this.onDismiss,
  });

  final AppPopup popup;
  final VoidCallback onTap;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    final background = popup.background ?? AppColors.surfaceContainerHighest;
    final foreground =
        popup.textColor ?? _readableOn(background, AppColors.onSurface);

    return Material(
      color: background,
      borderRadius: AppRadius.lgAll,
      elevation: 6,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: popup.ctaAction == 'none' ? null : onTap,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.sm),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (popup.title.isNotEmpty)
                      Text(
                        popup.title,
                        style: AppTypography.labelMedium
                            .copyWith(color: foreground),
                      ),
                    if (popup.plainContent.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        popup.plainContent,
                        maxLines: 3,
                        style: AppTypography.labelSmall
                            .copyWith(color: foreground),
                      ),
                    ],
                    if (popup.ctaLabel != null) ...[
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        popup.ctaLabel!,
                        style: AppTypography.labelMedium.copyWith(
                          color: foreground,
                          decoration: TextDecoration.underline,
                          decorationColor: foreground,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              if (onDismiss != null)
                IconButton(
                  onPressed: onDismiss,
                  visualDensity: VisualDensity.compact,
                  tooltip: 'Dismiss',
                  icon: Icon(Icons.close_rounded, size: 18, color: foreground),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A modal or full-screen popup.
class _PopupDialog extends StatelessWidget {
  const _PopupDialog({required this.popup, required this.onCta});

  final AppPopup popup;
  final VoidCallback onCta;

  @override
  Widget build(BuildContext context) {
    final background = popup.background ?? AppColors.surface;
    final foreground =
        popup.textColor ?? _readableOn(background, AppColors.onSurface);

    return PopScope(
      canPop: popup.isDismissible,
      child: Dialog(
        backgroundColor: background,
        shape: RoundedRectangleBorder(borderRadius: AppRadius.xlAll),
        clipBehavior: Clip.antiAlias,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (popup.imageUrl != null)
              SizedBox(
                height: 148,
                width: double.infinity,
                child: ZImage(
                  url: popup.imageUrl,
                  fallbackIcon: Icons.campaign_rounded,
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (popup.title.isNotEmpty)
                    Text(
                      popup.title,
                      style:
                          AppTypography.titleMedium.copyWith(color: foreground),
                    ),
                  if (popup.plainContent.isNotEmpty) ...[
                    const SizedBox(height: AppSpacing.xs),
                    Text(
                      popup.plainContent,
                      style: AppTypography.bodyMedium
                          .copyWith(color: foreground),
                    ),
                  ],
                  const SizedBox(height: AppSpacing.lg),
                  if (popup.ctaLabel != null)
                    ZButton(label: popup.ctaLabel!, onPressed: onCta),
                  if (popup.isDismissible) ...[
                    const SizedBox(height: AppSpacing.xs),
                    // In the popup's own text colour: the theme's magenta
                    // text button disappears on an operator's coloured card.
                    SizedBox(
                      width: double.infinity,
                      child: TextButton(
                        onPressed: () => Navigator.of(context).pop(),
                        style: TextButton.styleFrom(
                          foregroundColor: foreground,
                          minimumSize:
                              const Size.fromHeight(AppSpacing.minTouch),
                        ),
                        child: Text(
                          'Not now',
                          style: AppTypography.button.copyWith(
                            color: foreground.withValues(alpha: 0.85),
                          ),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Text colour for an operator-chosen background that set none of its own:
/// white on a dark card, [fallback] on a light one.
Color _readableOn(Color background, Color fallback) =>
    background.computeLuminance() < 0.4 ? Colors.white : fallback;
