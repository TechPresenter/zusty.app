import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/support.dart';
import '../../providers/account_provider.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/stat_card.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';

/// Screen 26 — Invite & Earn.
///
/// The share card (code, copy, link) first, because sharing is the one thing
/// this screen is for; then what the user has earned, how the programme
/// works, and everyone they have invited with where each one stands.
///
/// Every amount comes from `GET /me/referrals`, which reads the operator's
/// referral settings — nothing here hard-codes a reward.
class InviteEarnScreen extends ConsumerWidget {
  const InviteEarnScreen({super.key});

  /// The operator's invite text when configured, else one built from the
  /// same figures.
  static String _shareText(ReferralSummary data) {
    if (data.shareMessage.isNotEmpty) return data.shareMessage;
    return 'Join me on Zusty — book verified creators or get booked by '
        'brands. Use my code ${data.code}: ${data.shareUrl}';
  }

  static Future<void> _share(ReferralSummary data) =>
      SharePlus.instance.share(ShareParams(text: _shareText(data)));

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final referrals = ref.watch(referralsProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Invite & Earn'),
      bottomBar: referrals.maybeWhen(
        data: (data) => data.code.isEmpty
            ? null
            : ZButton(
                label: 'Share your invite link',
                icon: Icons.ios_share_rounded,
                onPressed: () => _share(data),
              ),
        orElse: () => null,
      ),
      body: AsyncView(
        value: referrals,
        onRetry: () => ref.invalidate(referralsProvider),
        loading: const ListSkeleton(
          count: 3,
          height: 120,
          padding: EdgeInsets.all(AppSpacing.md),
        ),
        builder: (data) => ZRefresh(
          onRefresh: () async {
            ref.invalidate(referralsProvider);
            try {
              await ref.read(referralsProvider.future);
            } catch (_) {
              // The screen shows its own error state.
            }
          },
          child: ListView(
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.md,
              margin,
              AppSpacing.xl,
            ),
            children: [
              Reveal(child: _ShareCard(summary: data)),
              if (!data.enabled) ...[
                const SizedBox(height: AppSpacing.sm),
                const ZInfoBanner(
                  tone: StatusTone.warning,
                  message:
                      'The referral programme is paused right now, so '
                      'new invites will not earn rewards.',
                ),
              ],
              const SizedBox(height: AppSpacing.md),
              Reveal(
                index: 1,
                child: ZCard(
                  padding: const EdgeInsets.symmetric(
                    vertical: AppSpacing.md,
                    horizontal: AppSpacing.xs,
                  ),
                  child: StatStrip(
                    items: [
                      ('${data.totalJoined}', 'Joined'),
                      ('${data.rewardedCount}', 'Rewarded'),
                      (Formatters.currency(data.totalEarned), 'Earned'),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
              const SectionHeader(title: 'How it works'),
              const SizedBox(height: AppSpacing.sm),
              Reveal(index: 2, child: _Steps(summary: data)),
              const SizedBox(height: AppSpacing.lg),
              SectionHeader(
                title: data.referrals.isEmpty
                    ? 'Your invites'
                    : 'Your invites (${data.referrals.length})',
                subtitle: data.pendingCount > 0
                    ? '${data.pendingCount} waiting on a first booking'
                    : null,
              ),
              const SizedBox(height: AppSpacing.sm),
              if (data.referrals.isEmpty)
                const ZCard(
                  child: EmptyState(
                    compact: true,
                    icon: Icons.group_add_rounded,
                    title: 'No invites yet',
                    message:
                        'Share your code — friends who join with it '
                        'will appear here, with their reward status.',
                  ),
                )
              else
                _InviteList(entries: data.referrals),
            ],
          ),
        ),
      ),
    );
  }
}

/// The hero: what a referral is worth, the code with a copy button, and the
/// link.
class _ShareCard extends StatelessWidget {
  const _ShareCard({required this.summary});

  final ReferralSummary summary;

  Future<void> _copy(BuildContext context, String text, String what) async {
    await Clipboard.setData(ClipboardData(text: text));
    HapticFeedback.selectionClick();
    if (context.mounted) showZSnack(context, '$what copied.');
  }

  @override
  Widget build(BuildContext context) {
    final onCard = AppColors.onSecondary;
    final reward = Formatters.currency(summary.rewardPerReferral);

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        borderRadius: AppRadius.xxlAll,
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.secondary, AppColors.secondaryContainer],
        ),
        boxShadow: AppShadows.raised,
      ),
      child: Stack(
        children: [
          // Two soft discs for depth; decoration only.
          Positioned(
            top: -40,
            right: -30,
            child: _Disc(size: 150, color: onCard.withValues(alpha: 0.08)),
          ),
          Positioned(
            bottom: -50,
            left: -20,
            child: _Disc(
              size: 120,
              color: AppColors.primaryContainer.withValues(alpha: 0.35),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        color: onCard.withValues(alpha: 0.16),
                        borderRadius: AppRadius.mdAll,
                      ),
                      child: Icon(
                        Icons.card_giftcard_rounded,
                        size: 22,
                        color: onCard,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Flexible(
                      child: Text(
                        'INVITE & EARN',
                        style: AppTypography.labelMedium.copyWith(
                          color: onCard.withValues(alpha: 0.85),
                          letterSpacing: 1.4,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                Text(
                  'Earn $reward for every friend who joins',
                  style: AppTypography.headlineMedium.copyWith(
                    color: onCard,
                    height: 1.2,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                Text(
                  summary.friendReward > 0
                      ? 'They get ${Formatters.currency(summary.friendReward)} '
                            'too. You are credited once their first booking '
                            'completes.'
                      : 'They get a welcome bonus on their first booking, and '
                            'you get credited once it completes.',
                  style: AppTypography.bodyMedium.copyWith(
                    color: onCard.withValues(alpha: 0.88),
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),

                // -- The code ------------------------------------------------
                Container(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.md,
                    AppSpacing.xs,
                    AppSpacing.xs,
                    AppSpacing.xs,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLowest,
                    borderRadius: AppRadius.lgAll,
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Your referral code',
                              style: AppTypography.labelSmall.copyWith(
                                color: AppColors.onSurfaceVariant,
                              ),
                            ),
                            const SizedBox(height: 2),
                            FittedBox(
                              fit: BoxFit.scaleDown,
                              alignment: Alignment.centerLeft,
                              child: Text(
                                summary.code.isEmpty ? '—' : summary.code,
                                style: AppTypography.headlineSmall.copyWith(
                                  color: AppColors.primary,
                                  letterSpacing: 2.4,
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      if (summary.code.isNotEmpty)
                        ZButton.tonal(
                          label: 'Copy',
                          icon: Icons.copy_rounded,
                          size: ZButtonSize.small,
                          expand: false,
                          onPressed: () =>
                              _copy(context, summary.code, 'Referral code'),
                        ),
                    ],
                  ),
                ),

                // -- The link ------------------------------------------------
                if (summary.shareUrl.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.xs),
                  Material(
                    color: Colors.transparent,
                    child: InkWell(
                      onTap: () =>
                          _copy(context, summary.shareUrl, 'Invite link'),
                      borderRadius: AppRadius.mdAll,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(
                          minHeight: AppSpacing.minTouch,
                        ),
                        child: Row(
                          children: [
                            Icon(
                              Icons.link_rounded,
                              size: 18,
                              color: onCard.withValues(alpha: 0.85),
                            ),
                            const SizedBox(width: AppSpacing.xs),
                            Expanded(
                              child: Text(
                                summary.shareUrl,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: AppTypography.bodySmall.copyWith(
                                  color: onCard.withValues(alpha: 0.9),
                                ),
                              ),
                            ),
                            const SizedBox(width: AppSpacing.xs),
                            Text(
                              'Copy link',
                              style: AppTypography.labelMedium.copyWith(
                                color: onCard,
                              ),
                            ),
                          ],
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
    );
  }
}

class _Disc extends StatelessWidget {
  const _Disc({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(shape: BoxShape.circle, color: color),
  );
}

/// Three numbered steps joined by a line.
class _Steps extends StatelessWidget {
  const _Steps({required this.summary});

  final ReferralSummary summary;

  @override
  Widget build(BuildContext context) {
    final reward = Formatters.currency(summary.rewardPerReferral);

    final steps = <(IconData, String, String)>[
      (
        Icons.ios_share_rounded,
        'Share your code',
        'Send your invite link to brands or creators you know.',
      ),
      (
        Icons.person_add_alt_1_rounded,
        'They sign up',
        'Your friend creates a Zusty account using your code.',
      ),
      (
        Icons.account_balance_wallet_rounded,
        'You both earn',
        'Once their first booking completes, $reward is credited to your '
            'wallet.',
      ),
    ];

    return ZCard(
      child: Column(
        children: [
          for (var i = 0; i < steps.length; i++)
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SizedBox(
                    width: 40,
                    child: Column(
                      children: [
                        Container(
                          width: 40,
                          height: 40,
                          decoration: BoxDecoration(
                            color: AppColors.primaryFixed,
                            shape: BoxShape.circle,
                          ),
                          child: Icon(
                            steps[i].$1,
                            size: 19,
                            color: AppColors.primary,
                          ),
                        ),
                        if (i != steps.length - 1)
                          Expanded(
                            child: Container(
                              width: 2,
                              margin: const EdgeInsets.symmetric(vertical: 4),
                              decoration: BoxDecoration(
                                color: AppColors.outlineVariant,
                                borderRadius: AppRadius.pill,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(
                        top: 2,
                        bottom: i == steps.length - 1 ? 0 : AppSpacing.md,
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${i + 1}. ${steps[i].$2}',
                            style: AppTypography.titleSmall,
                          ),
                          const SizedBox(height: 2),
                          Text(steps[i].$3, style: AppTypography.bodySmall),
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

/// Everyone invited, newest first, on one grouped card.
class _InviteList extends StatelessWidget {
  const _InviteList({required this.entries});

  final List<ReferralEntry> entries;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          for (var i = 0; i < entries.length; i++) ...[
            if (i > 0)
              Divider(
                height: 1,
                indent: AppSpacing.md + 40 + AppSpacing.sm,
                color: AppColors.outlineVariant,
              ),
            _ReferralRow(entry: entries[i]),
          ],
        ],
      ),
    );
  }
}

class _ReferralRow extends StatelessWidget {
  const _ReferralRow({required this.entry});

  final ReferralEntry entry;

  @override
  Widget build(BuildContext context) {
    final when = entry.isRewarded && entry.rewardedAt != null
        ? 'Rewarded ${Formatters.relative(entry.rewardedAt)}'
        : 'Joined ${Formatters.relative(entry.joinedAt)}';

    return Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      child: Row(
        children: [
          ZAvatar(imageUrl: entry.avatarUrl, name: entry.name, size: 40),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  entry.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                Text(
                  when,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(
                label: entry.statusLabel,
                tone: entry.tone,
                dense: true,
              ),
              if (entry.reward > 0 && entry.isRewarded) ...[
                const SizedBox(height: 4),
                Text(
                  '+ ${Formatters.currency(entry.reward)}',
                  style: AppTypography.labelMedium.copyWith(
                    color: AppColors.success,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
