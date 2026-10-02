import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/campaign.dart';
import '../../../providers/core_providers.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../data/brand_campaign_api.dart';
import 'decline_sheet.dart';

/// What the card is doing right now, so the right button spins and the rest
/// wait.
enum _Pending { none, shortlist, hire, decline, reconsider, message }

/// One creator in a campaign's pipeline, with the actions valid for their
/// stage on the card itself — moving people along is the job of this
/// screen, so those actions are never behind a menu.
///
/// Hiring here marks the application hired and notifies the creator; it
/// does not create a booking (the API has no campaign offer). "Send offer"
/// opens the regular booking request for that creator, which is where the
/// work and escrow are tracked.
class ApplicantCard extends ConsumerStatefulWidget {
  const ApplicantCard({
    super.key,
    required this.campaign,
    required this.recipient,
  });

  final Campaign campaign;
  final CampaignRecipient recipient;

  @override
  ConsumerState<ApplicantCard> createState() => _ApplicantCardState();
}

class _ApplicantCardState extends ConsumerState<ApplicantCard> {
  _Pending _pending = _Pending.none;
  bool _pitchExpanded = false;

  CampaignRecipient get _recipient => widget.recipient;
  String get _name => _recipient.creator.displayName;

  bool get _canSendOffer =>
      _recipient.bookingId == null &&
      (_recipient.status == RecipientStatus.shortlisted ||
          _recipient.status == RecipientStatus.hired);

  Future<void> _move(
    RecipientStatus status,
    _Pending pending, {
    String? reason,
    required String done,
    String? actionLabel,
    VoidCallback? onAction,
  }) async {
    setState(() => _pending = pending);
    try {
      await ref.read(brandCampaignActionsProvider).decide(
            widget.campaign.id,
            _recipient.id,
            status,
            reason: reason,
          );
      if (mounted) {
        showZSnack(context, done, actionLabel: actionLabel, onAction: onAction);
      }
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _pending = _Pending.none);
    }
  }

  void _shortlist({bool reconsider = false}) => _move(
        RecipientStatus.shortlisted,
        reconsider ? _Pending.reconsider : _Pending.shortlist,
        done: reconsider
            ? '$_name is back on the shortlist.'
            : '$_name added to your shortlist.',
      );

  Future<void> _hire() async {
    final quote = _recipient.quotedAmount;
    final confirmed = await showZConfirm(
      context,
      title: 'Hire $_name?',
      message: '${quote == null ? '' : 'They quoted ${Formatters.currency(quote)}. '}'
          'They will be told straight away. Then send them an offer, so the '
          'work and payment run through a booking.',
      confirmLabel: 'Hire creator',
      icon: Icons.handshake_rounded,
    );
    if (!confirmed || !mounted) return;

    // Captured now: the card may rebuild into the "hired" group by the time
    // the snack's action is tapped.
    final router = GoRouter.of(context);
    final handle = _recipient.creator.handle;

    await _move(
      RecipientStatus.hired,
      _Pending.hire,
      done: '$_name is hired. Send an offer to start the booking.',
      actionLabel: 'Send offer',
      onAction: () => router.push(Routes.newBooking(handle)),
    );
  }

  Future<void> _decline() async {
    final isInvitation = _recipient.status == RecipientStatus.invited;
    final decision = await showDeclineSheet(
      context,
      name: _name,
      isInvitation: isInvitation,
    );
    if (decision == null || !mounted) return;

    await _move(
      RecipientStatus.declined,
      _Pending.decline,
      reason: decision.reason,
      done: isInvitation
          ? 'Invitation to $_name withdrawn.'
          : '$_name declined.',
    );
  }

  Future<void> _message() async {
    setState(() => _pending = _Pending.message);
    try {
      final id = await ref
          .read(messageRepositoryProvider)
          .start(creatorId: _recipient.creator.id);
      if (!mounted) return;
      if (id <= 0) {
        showZSnack(context, 'Could not open the chat. Please try again.',
            isError: true);
        return;
      }
      context.push(Routes.chat(id));
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } catch (_) {
      if (mounted) {
        showZSnack(context, 'Could not open the chat. Please try again.',
            isError: true);
      }
    } finally {
      if (mounted) setState(() => _pending = _Pending.none);
    }
  }

  void _openProfile() =>
      context.push(Routes.creator(_recipient.creator.handle));

  void _sendOffer() =>
      context.push(Routes.newBooking(_recipient.creator.handle));

  @override
  Widget build(BuildContext context) {
    final creator = _recipient.creator;
    final facts = [
      '${creator.followersLabel} followers',
      if (creator.engagementRate > 0)
        '${creator.engagementRate.toStringAsFixed(1)}% eng.',
      if (creator.city != null && creator.city!.isNotEmpty) creator.city!,
    ];

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // -- Who -----------------------------------------------------------
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ZAvatar(
                imageUrl: creator.avatarUrl,
                name: creator.displayName,
                size: 52,
                isVerified: creator.isVerified,
                onTap: _openProfile,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Semantics(
                  button: true,
                  label: 'View ${creator.displayName}\'s profile',
                  child: GestureDetector(
                    onTap: _openProfile,
                    behavior: HitTestBehavior.opaque,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          creator.displayName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.titleSmall
                              .copyWith(fontWeight: FontWeight.w700),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          [
                            creator.atHandle,
                            if (creator.category != null &&
                                creator.category!.isNotEmpty)
                              creator.category!,
                          ].join(' · '),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodySmall,
                        ),
                        const SizedBox(height: 4),
                        Row(
                          children: [
                            if (creator.rating > 0) ...[
                              RatingStars(rating: creator.rating, size: 12),
                              const SizedBox(width: AppSpacing.xs),
                            ],
                            Flexible(
                              child: Text(
                                facts.join(' · '),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: AppTypography.labelSmall.copyWith(
                                  color: AppColors.onSurfaceVariant,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              _MoreMenu(
                name: creator.displayName,
                status: _recipient.status,
                canSendOffer: _canSendOffer,
                bookingId: _recipient.bookingId,
                onProfile: _openProfile,
                onMessage: _message,
                onSendOffer: _sendOffer,
                onShortlist: () => _shortlist(),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),

          // -- What they offered ------------------------------------------
          _OfferPanel(
            recipient: _recipient,
            expanded: _pitchExpanded,
            onToggle: () => setState(() => _pitchExpanded = !_pitchExpanded),
          ),
          const SizedBox(height: AppSpacing.sm),

          // -- What to do -------------------------------------------------
          _StageActions(
            status: _recipient.status,
            name: creator.displayName,
            pending: _pending,
            hasBooking: _recipient.bookingId != null,
            onShortlist: () => _shortlist(),
            onHire: _hire,
            onDecline: _decline,
            onReconsider: () => _shortlist(reconsider: true),
            onMessage: _message,
            onSendOffer: _sendOffer,
            onViewBooking: _recipient.bookingId == null
                ? null
                : () => context.push(Routes.booking(_recipient.bookingId!)),
          ),
        ],
      ),
    );
  }
}

/// The quote, and the pitch beneath it, on a blush panel.
class _OfferPanel extends StatelessWidget {
  const _OfferPanel({
    required this.recipient,
    required this.expanded,
    required this.onToggle,
  });

  final CampaignRecipient recipient;
  final bool expanded;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final pitch = recipient.pitch?.trim();
    final hasPitch = pitch != null && pitch.isNotEmpty;
    final isLong = hasPitch && pitch.length > 140;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: AppRadius.mdAll,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  recipient.status == RecipientStatus.invited
                      ? 'Invitation sent'
                      : 'Quoted rate',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.labelMedium.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Text(
                recipient.quotedAmount == null
                    ? (recipient.status == RecipientStatus.invited
                        ? 'Not applied yet'
                        : 'No rate given')
                    : recipient.quoteLabel,
                maxLines: 1,
                style: recipient.quotedAmount == null
                    ? AppTypography.labelMedium
                        .copyWith(color: AppColors.onSurfaceVariant)
                    : AppTypography.titleSmall.copyWith(
                        color: AppColors.primaryDark,
                        fontWeight: FontWeight.w700,
                      ),
              ),
            ],
          ),
          if (hasPitch) ...[
            const SizedBox(height: AppSpacing.xs),
            Text(
              '“$pitch”',
              maxLines: expanded ? null : 3,
              overflow: expanded ? null : TextOverflow.ellipsis,
              style: AppTypography.bodySmall.copyWith(
                color: AppColors.onSurface,
                height: 1.5,
              ),
            ),
            if (isLong)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton(
                  onPressed: onToggle,
                  style: TextButton.styleFrom(
                    padding: EdgeInsets.zero,
                    minimumSize: const Size(AppSpacing.minTouch, 36),
                    tapTargetSize: MaterialTapTargetSize.padded,
                  ),
                  child: Text(expanded ? 'Show less' : 'Read full pitch'),
                ),
              ),
          ],
          // The work they linked when applying, opened outside the app.
          if (recipient.portfolioLinks.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.xs),
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                for (final link in recipient.portfolioLinks)
                  ActionChip(
                    avatar: Icon(
                      Icons.link_rounded,
                      size: 16,
                      color: AppColors.primary,
                    ),
                    label: Text(
                      Uri.tryParse(link)?.host.replaceFirst('www.', '') ?? link,
                      overflow: TextOverflow.ellipsis,
                    ),
                    tooltip: link,
                    onPressed: () => launchUrl(
                      Uri.parse(link),
                      mode: LaunchMode.externalApplication,
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// Stage-appropriate actions. A hired creator moves on to an offer or their
/// booking instead.
class _StageActions extends StatelessWidget {
  const _StageActions({
    required this.status,
    required this.name,
    required this.pending,
    required this.hasBooking,
    required this.onShortlist,
    required this.onHire,
    required this.onDecline,
    required this.onReconsider,
    required this.onMessage,
    required this.onSendOffer,
    this.onViewBooking,
  });

  final RecipientStatus status;
  final String name;
  final _Pending pending;
  final bool hasBooking;
  final VoidCallback onShortlist;
  final VoidCallback onHire;
  final VoidCallback onDecline;
  final VoidCallback onReconsider;
  final VoidCallback onMessage;
  final VoidCallback onSendOffer;
  final VoidCallback? onViewBooking;

  @override
  Widget build(BuildContext context) {
    final busy = pending != _Pending.none;
    VoidCallback? guard(VoidCallback action) => busy ? null : action;

    Widget messageIcon() => _MessageIconButton(
          name: name,
          loading: pending == _Pending.message,
          onPressed: guard(onMessage),
        );

    switch (status) {
      case RecipientStatus.applied:
        return Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Decline',
                size: ZButtonSize.small,
                isLoading: pending == _Pending.decline,
                onPressed: guard(onDecline),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: ZButton(
                label: 'Shortlist',
                size: ZButtonSize.small,
                isLoading: pending == _Pending.shortlist,
                onPressed: guard(onShortlist),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            messageIcon(),
          ],
        );

      case RecipientStatus.shortlisted:
        return Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Decline',
                size: ZButtonSize.small,
                isLoading: pending == _Pending.decline,
                onPressed: guard(onDecline),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: ZButton(
                label: 'Hire',
                size: ZButtonSize.small,
                isLoading: pending == _Pending.hire,
                onPressed: guard(onHire),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            messageIcon(),
          ],
        );

      case RecipientStatus.invited:
        return Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Withdraw',
                size: ZButtonSize.small,
                isLoading: pending == _Pending.decline,
                onPressed: guard(onDecline),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: ZButton.tonal(
                label: 'Message',
                icon: Icons.chat_bubble_outline_rounded,
                size: ZButtonSize.small,
                isLoading: pending == _Pending.message,
                onPressed: guard(onMessage),
              ),
            ),
          ],
        );

      case RecipientStatus.hired:
        return Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Message',
                icon: Icons.chat_bubble_outline_rounded,
                size: ZButtonSize.small,
                isLoading: pending == _Pending.message,
                onPressed: guard(onMessage),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: hasBooking
                  ? ZButton(
                      label: 'View booking',
                      icon: Icons.receipt_long_rounded,
                      size: ZButtonSize.small,
                      onPressed: busy ? null : onViewBooking,
                    )
                  : ZButton(
                      label: 'Send offer',
                      icon: Icons.send_rounded,
                      size: ZButtonSize.small,
                      onPressed: guard(onSendOffer),
                    ),
            ),
          ],
        );

      case RecipientStatus.declined:
        return Row(
          children: [
            Expanded(
              child: ZButton.outlined(
                label: 'Reconsider',
                icon: Icons.undo_rounded,
                size: ZButtonSize.small,
                isLoading: pending == _Pending.reconsider,
                onPressed: guard(onReconsider),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            messageIcon(),
          ],
        );
    }
  }
}

class _MessageIconButton extends StatelessWidget {
  const _MessageIconButton({
    required this.name,
    required this.loading,
    required this.onPressed,
  });

  final String name;
  final bool loading;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return SizedBox(
        width: AppSpacing.minTouch,
        height: AppSpacing.minTouch,
        child: Center(
          child: SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              color: AppColors.primary,
            ),
          ),
        ),
      );
    }

    // The tooltip doubles as the button's accessible name.
    return ZIconButton(
      icon: Icons.chat_bubble_outline_rounded,
      size: AppSpacing.minTouch,
      tooltip: 'Message $name',
      onPressed: onPressed,
    );
  }
}

/// Everything else you can do with an applicant, whatever their stage.
class _MoreMenu extends StatelessWidget {
  const _MoreMenu({
    required this.name,
    required this.status,
    required this.canSendOffer,
    required this.bookingId,
    required this.onProfile,
    required this.onMessage,
    required this.onSendOffer,
    required this.onShortlist,
  });

  final String name;
  final RecipientStatus status;
  final bool canSendOffer;
  final int? bookingId;
  final VoidCallback onProfile;
  final VoidCallback onMessage;
  final VoidCallback onSendOffer;
  final VoidCallback onShortlist;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      tooltip: 'More for $name',
      icon: Icon(Icons.more_vert_rounded, color: AppColors.onSurfaceVariant),
      shape: RoundedRectangleBorder(borderRadius: AppRadius.lgAll),
      onSelected: (value) {
        switch (value) {
          case 'profile':
            onProfile();
          case 'message':
            onMessage();
          case 'offer':
            onSendOffer();
          case 'booking':
            context.push(Routes.booking(bookingId!));
          case 'shortlist':
            onShortlist();
        }
      },
      itemBuilder: (_) => [
        _item('profile', Icons.person_outline_rounded, 'View profile'),
        _item('message', Icons.chat_bubble_outline_rounded, 'Message'),
        if (canSendOffer) _item('offer', Icons.send_rounded, 'Send offer'),
        if (bookingId != null)
          _item('booking', Icons.receipt_long_rounded, 'View booking'),
        // An invitee has not applied yet, but a brand may still want them
        // on the shortlist while they decide.
        if (status == RecipientStatus.invited)
          _item('shortlist', Icons.star_outline_rounded, 'Shortlist'),
      ],
    );
  }

  static PopupMenuItem<String> _item(String value, IconData icon, String label) =>
      PopupMenuItem(
        value: value,
        child: Row(
          children: [
            Icon(icon, size: 20, color: AppColors.onSurfaceVariant),
            const SizedBox(width: AppSpacing.sm),
            Text(label, style: AppTypography.bodyMedium),
          ],
        ),
      );
}

/// A pipeline group's heading: the stage, how many, and what it means.
class PipelineGroupHeader extends StatelessWidget {
  const PipelineGroupHeader({
    super.key,
    required this.status,
    required this.count,
  });

  final RecipientStatus status;
  final int count;

  String get _hint => switch (status) {
        RecipientStatus.applied => 'Waiting for your decision',
        RecipientStatus.shortlisted => 'Your favourites so far',
        RecipientStatus.hired => 'Send each an offer to start a booking',
        RecipientStatus.invited => 'Invited, not applied yet',
        RecipientStatus.declined => 'Not going ahead',
      };

  @override
  Widget build(BuildContext context) {
    final (_, ink) = StatusChip.colorsFor(status.tone);

    return Semantics(
      header: true,
      child: Padding(
        padding: const EdgeInsets.only(
          top: AppSpacing.md,
          bottom: AppSpacing.xs,
        ),
        child: Row(
          children: [
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(color: ink, shape: BoxShape.circle),
            ),
            const SizedBox(width: AppSpacing.xs),
            Text(
              '${status.label} · $count',
              style: AppTypography.titleSmall,
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: Text(
                _hint,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.bodySmall,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
