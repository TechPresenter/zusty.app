import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_response.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/creator.dart';
import '../../../models/review.dart';
import '../../../providers/core_providers.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_dialogs.dart';
import 'profile_layout.dart';

/// The Reviews tab: the score and its star histogram, the latest reviews the
/// profile carries, and — when there are more — every review, paged from
/// `GET /creators/{id}/reviews` in a sheet.
class ProfileReviewsTab extends StatelessWidget {
  const ProfileReviewsTab({super.key, required this.profile});

  final CreatorProfile profile;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final reviews = profile.reviews;
    final total = profile.totalReviews > 0
        ? profile.totalReviews
        : creator.ratingCount;

    if (reviews.isEmpty) {
      return ProfileTabBody(
        children: [
          EmptyState(
            title: 'No reviews yet',
            message:
                'Brands review ${firstNameOf(creator.displayName)} '
                'after a completed booking. Reviews will appear here.',
            icon: Icons.rate_review_outlined,
            compact: true,
          ),
        ],
      );
    }

    return ProfileTabBody(
      children: [
        ProfileRatingSummary(profile: profile),
        ProfileSectionTitle(
          'Latest reviews',
          actionLabel: total > reviews.length ? 'See all $total' : null,
          onAction: total > reviews.length
              ? () => showAllReviews(context, profile: profile)
              : null,
        ),
        for (final review in reviews)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: ProfileReviewCard(review: review),
          ),
        if (total > reviews.length) ...[
          const SizedBox(height: AppSpacing.xs),
          ZButton.outlined(
            label: 'See all $total reviews',
            size: ZButtonSize.medium,
            onPressed: () => showAllReviews(context, profile: profile),
          ),
        ],
      ],
    );
  }
}

/// Average score plus the 5→1 star histogram.
class ProfileRatingSummary extends StatelessWidget {
  const ProfileRatingSummary({super.key, required this.profile});

  final CreatorProfile profile;

  @override
  Widget build(BuildContext context) {
    final total = profile.totalReviews;
    final count = total > 0 ? total : profile.creator.ratingCount;

    return ZCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                Formatters.rating(profile.creator.rating),
                style: AppTypography.displayLarge.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              RatingStars(
                rating: profile.creator.rating,
                starCount: 5,
                showValue: false,
                size: 14,
              ),
              const SizedBox(height: AppSpacing.base),
              Text(
                count == 1 ? '1 review' : '$count reviews',
                style: AppTypography.bodySmall,
              ),
            ],
          ),
          const SizedBox(width: AppSpacing.lg),
          Expanded(
            child: Column(
              children: [
                for (var star = 5; star >= 1; star--)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 3),
                    child: Semantics(
                      label:
                          '$star stars: '
                          '${profile.ratingBreakdown[star] ?? 0} reviews',
                      excludeSemantics: true,
                      child: Row(
                        children: [
                          SizedBox(
                            width: 12,
                            child: Text(
                              '$star',
                              style: AppTypography.labelMedium,
                            ),
                          ),
                          Icon(
                            Icons.star_rounded,
                            size: 12,
                            color: AppColors.star,
                          ),
                          const SizedBox(width: AppSpacing.xs),
                          Expanded(
                            child: ClipRRect(
                              borderRadius: AppRadius.pill,
                              child: LinearProgressIndicator(
                                value: total == 0
                                    ? 0
                                    : (profile.ratingBreakdown[star] ?? 0) /
                                          total,
                                minHeight: 6,
                                backgroundColor:
                                    AppColors.surfaceContainerHighest,
                                valueColor: AlwaysStoppedAnimation(
                                  AppColors.star,
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: AppSpacing.xs),
                          SizedBox(
                            width: 24,
                            child: Text(
                              '${profile.ratingBreakdown[star] ?? 0}',
                              textAlign: TextAlign.right,
                              style: AppTypography.labelSmall,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class ProfileReviewCard extends StatelessWidget {
  const ProfileReviewCard({super.key, required this.review});

  final Review review;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              ZAvatar(
                imageUrl: review.reviewerAvatar,
                name: review.reviewerName,
                size: 40,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      review.reviewerName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelLarge,
                    ),
                    if (review.dateLabel.isNotEmpty)
                      Text(review.dateLabel, style: AppTypography.bodySmall),
                  ],
                ),
              ),
              RatingStars(rating: review.rating, size: 13),
            ],
          ),
          if (review.title.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(review.title, style: AppTypography.titleSmall),
          ],
          if (review.comment.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.base),
            Text(
              review.comment,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
                height: 1.45,
              ),
            ),
          ],
          if (review.hasReply) ...[
            const SizedBox(height: AppSpacing.sm),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(AppSpacing.sm),
              decoration: BoxDecoration(
                color: AppColors.surfaceContainerLow,
                borderRadius: AppRadius.mdAll,
                border: Border(
                  left: BorderSide(color: AppColors.primary, width: 3),
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        Icons.reply_rounded,
                        size: 14,
                        color: AppColors.primary,
                      ),
                      const SizedBox(width: 4),
                      Text(
                        'Creator replied',
                        style: AppTypography.labelMedium.copyWith(
                          color: AppColors.primary,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(review.reply!, style: AppTypography.bodyMedium),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Every published review for the creator, paged in a tall sheet.
Future<void> showAllReviews(
  BuildContext context, {
  required CreatorProfile profile,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    sheetAnimationStyle: zSheetMotion(context),
    builder: (_) => DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.9,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      builder: (context, controller) =>
          _AllReviews(profile: profile, controller: controller),
    ),
  );
}

class _AllReviews extends ConsumerStatefulWidget {
  const _AllReviews({required this.profile, required this.controller});

  final CreatorProfile profile;
  final ScrollController controller;

  @override
  ConsumerState<_AllReviews> createState() => _AllReviewsState();
}

class _AllReviewsState extends ConsumerState<_AllReviews> {
  Paginated<Review>? _page;
  Object? _error;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_maybeLoadMore);
    _load(1);
  }

  @override
  void dispose() {
    widget.controller.removeListener(_maybeLoadMore);
    super.dispose();
  }

  /// Fetches the next page as the reader nears the end, one page at a time.
  void _maybeLoadMore() {
    final page = _page;
    if (page == null || !page.hasMore || _loading) return;

    final position = widget.controller.position;
    if (position.pixels >= position.maxScrollExtent - 400) {
      _load(page.nextPage);
    }
  }

  Future<void> _load(int pageNumber) async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final next = await ref
          .read(creatorRepositoryProvider)
          .reviews(widget.profile.creator.id, page: pageNumber);

      if (!mounted) return;
      setState(() {
        _page = pageNumber == 1 || _page == null ? next : _page!.merge(next);
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = error);
    } finally {
      if (mounted) {
        setState(() => _loading = false);

        // A short page may not fill the sheet, and then nothing scrolls to
        // ask for the next one.
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && widget.controller.hasClients) _maybeLoadMore();
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    final margin = AppBreakpoints.pageMargin(context);

    return Column(
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, AppSpacing.xs, 0),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  'Reviews for ${widget.profile.creator.displayName}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleMedium,
                ),
              ),
              IconButton(
                onPressed: () => Navigator.of(context).maybePop(),
                icon: const Icon(Icons.close_rounded),
                tooltip: 'Close',
              ),
            ],
          ),
        ),
        Expanded(
          child: page == null
              ? (_error != null
                    ? ErrorState(error: _error!, onRetry: () => _load(1))
                    : ListSkeleton(
                        count: 4,
                        height: 120,
                        padding: EdgeInsets.symmetric(
                          horizontal: margin,
                          vertical: AppSpacing.md,
                        ),
                      ))
              : ListView.separated(
                  controller: widget.controller,
                  padding: EdgeInsets.fromLTRB(
                    margin,
                    AppSpacing.sm,
                    margin,
                    AppSpacing.xl,
                  ),
                  itemCount: page.items.length + 1,
                  separatorBuilder: (_, _) =>
                      const SizedBox(height: AppSpacing.sm),
                  itemBuilder: (context, i) {
                    if (i == page.items.length) {
                      return _Footer(
                        loading: _loading,
                        hasMore: page.hasMore,
                        failed: _error != null,
                        onRetry: () => _load(page.nextPage),
                      );
                    }

                    return ProfileReviewCard(review: page.items[i]);
                  },
                ),
        ),
      ],
    );
  }
}

class _Footer extends StatelessWidget {
  const _Footer({
    required this.loading,
    required this.hasMore,
    required this.failed,
    required this.onRetry,
  });

  final bool loading;
  final bool hasMore;
  final bool failed;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Padding(
        padding: EdgeInsets.all(AppSpacing.md),
        child: Center(child: BrandLoader(size: 28)),
      );
    }

    if (failed && hasMore) {
      return Center(
        child: ZButton.outlined(
          label: 'Load more',
          size: ZButtonSize.small,
          expand: false,
          onPressed: onRetry,
        ),
      );
    }

    if (!hasMore) {
      return Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Text(
          'That\'s every review',
          textAlign: TextAlign.center,
          style: AppTypography.bodySmall,
        ),
      );
    }

    return const SizedBox(height: AppSpacing.md);
  }
}
