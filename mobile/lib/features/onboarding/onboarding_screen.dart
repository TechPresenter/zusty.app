import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../../models/onboarding_slide.dart';
import '../../providers/auth_provider.dart';
import '../../providers/onboarding_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/zuste_logo.dart';
import 'widgets/onboarding_collage.dart';

/// Screens 01–03: the intro shown to first-time users.
///
/// Every slide — headline, supporting line, collage photos, symbol and button
/// label — comes from the admin panel, so the number of panels, the dots and
/// the final "Get Started" all follow the slide count rather than assuming
/// three.
///
/// Only the collage pages horizontally. The wordmark, copy, dots and button
/// stay put and the copy cross-fades, so the eye stays on one place while the
/// photography changes — and the photo area keeps a steady height however
/// long each slide's headline is.
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _controller = PageController();
  int _page = 0;

  /// Slides come from `GET /app/onboarding`, so marketing copy is editable
  /// without a store release.
  List<OnboardingSlide> get _pages =>
      ref.watch(onboardingSlidesProvider).valueOrNull ??
      const [OnboardingSlide.fallback];

  bool get _isLast => _page >= _pages.length - 1;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _finish() async {
    await ref.read(authProvider.notifier).completeOnboarding();
    if (mounted) context.go(Routes.login);
  }

  void _next() {
    if (_isLast) {
      _finish();
    } else {
      _goTo(_page + 1);
    }
  }

  void _goTo(int index) {
    if (index < 0 || index >= _pages.length) return;
    _controller.animateToPage(
      index,
      duration: Motion.of(context, Motion.slow),
      curve: Motion.standard,
    );
  }

  /// A swipe on the copy pages the collage too, so the whole screen feels
  /// swipeable rather than only the photos.
  void _onCopySwipe(DragEndDetails details) {
    final velocity = details.primaryVelocity ?? 0;
    if (velocity < -200) _goTo(_page + 1);
    if (velocity > 200) _goTo(_page - 1);
  }

  @override
  Widget build(BuildContext context) {
    final pages = _pages;
    // The slide list can shrink when the admin's copy replaces the cached one.
    final page = _page.clamp(0, pages.length - 1);
    final slide = pages[page];
    final margin = AppBreakpoints.pageMargin(context);
    final fade = Motion.of(context, Motion.medium);

    final ctaLabel = (slide.ctaLabel?.trim().isNotEmpty ?? false)
        ? slide.ctaLabel!.trim()
        : (_isLast ? 'Get Started' : 'Next');

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(
              maxWidth: AppBreakpoints.maxContentWidth,
            ),
            child: Column(
              children: [
                // -- Skip --------------------------------------------------
                SizedBox(
                  height: 52,
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: Padding(
                      padding: EdgeInsets.only(right: margin - AppSpacing.xs),
                      child: AnimatedOpacity(
                        opacity: _isLast ? 0 : 1,
                        duration: fade,
                        child: IgnorePointer(
                          ignoring: _isLast,
                          child: TextButton(
                            onPressed: _finish,
                            style: TextButton.styleFrom(
                              minimumSize: const Size(
                                AppSpacing.minTouch + 16,
                                AppSpacing.minTouch,
                              ),
                              foregroundColor: AppColors.onSurfaceVariant,
                            ),
                            child: Text(
                              'Skip',
                              style: AppTypography.labelLarge.copyWith(
                                color: AppColors.onSurfaceVariant,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),

                // -- Collage -------------------------------------------------
                Expanded(
                  child: PageView.builder(
                    controller: _controller,
                    itemCount: pages.length,
                    onPageChanged: (index) => setState(() => _page = index),
                    itemBuilder: (_, index) => Padding(
                      padding: EdgeInsets.symmetric(horizontal: margin),
                      child: Semantics(
                        image: true,
                        label: 'Illustration for ${pages[index].title}',
                        child: OnboardingCollage(slide: pages[index]),
                      ),
                    ),
                  ),
                ),

                // -- Brand, copy, progress, action ---------------------------
                GestureDetector(
                  behavior: HitTestBehavior.translucent,
                  onHorizontalDragEnd: _onCopySwipe,
                  child: Padding(
                    padding: EdgeInsets.symmetric(horizontal: margin),
                    child: Column(
                      children: [
                        const SizedBox(height: AppSpacing.lg),
                        const ZusteLogoLockup(height: 26),
                        const SizedBox(height: AppSpacing.md),
                        _SlideCopy(pages: pages, active: page),
                        const SizedBox(height: AppSpacing.lg),
                        _PageDots(count: pages.length, active: page),
                      ],
                    ),
                  ),
                ),
                Padding(
                  padding: EdgeInsets.fromLTRB(
                    margin,
                    AppSpacing.lg,
                    margin,
                    AppSpacing.md,
                  ),
                  child: ZButton(
                    label: ctaLabel,
                    onPressed: _next,
                    trailingIcon: Icons.arrow_forward_rounded,
                    pill: true,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Every slide's headline and supporting line, stacked, with only the active
/// one visible.
///
/// Stacking them sizes the block to the tallest slide, so the collage above
/// keeps one height for the whole carousel instead of jumping as headlines of
/// different lengths come and go.
class _SlideCopy extends StatelessWidget {
  const _SlideCopy({required this.pages, required this.active});

  final List<OnboardingSlide> pages;
  final int active;

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.sizeOf(context).width < 380;
    final duration = Motion.of(context, Motion.medium);

    return Stack(
      alignment: Alignment.topCenter,
      children: [
        for (var i = 0; i < pages.length; i++)
          AnimatedOpacity(
            opacity: i == active ? 1 : 0,
            duration: duration,
            curve: Motion.standard,
            child: AnimatedSlide(
              offset: Offset(0, i == active ? 0 : 0.04),
              duration: duration,
              curve: Motion.emphasized,
              child: IgnorePointer(
                ignoring: i != active,
                child: ExcludeSemantics(
                  excluding: i != active,
                  child: Column(
                    children: [
                      Text(
                        pages[i].title,
                        textAlign: TextAlign.center,
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.displayLarge.copyWith(
                          fontSize: narrow ? 28 : 32,
                          height: 1.15,
                        ),
                      ),
                      if (pages[i].body.isNotEmpty) ...[
                        const SizedBox(height: AppSpacing.sm),
                        Text(
                          pages[i].body,
                          textAlign: TextAlign.center,
                          maxLines: 3,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodyLarge.copyWith(
                            color: AppColors.onSurfaceVariant,
                            height: 1.5,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// The page indicator: one dot per admin slide, the active one stretched into
/// a pill.
class _PageDots extends StatelessWidget {
  const _PageDots({required this.count, required this.active});

  final int count;
  final int active;

  @override
  Widget build(BuildContext context) {
    // A single slide needs no indicator.
    if (count < 2) return const SizedBox(height: 8);

    return Semantics(
      label: 'Slide ${active + 1} of $count',
      excludeSemantics: true,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: List.generate(count, (i) {
          final isActive = i == active;
          return AnimatedContainer(
            duration: Motion.of(context, Motion.medium),
            curve: Motion.standard,
            margin: const EdgeInsets.symmetric(horizontal: 4),
            width: isActive ? 24 : 8,
            height: 8,
            decoration: BoxDecoration(
              color: isActive ? AppColors.primary : AppColors.primaryFixedDim,
              borderRadius: AppRadius.pill,
            ),
          );
        }),
      ),
    );
  }
}
