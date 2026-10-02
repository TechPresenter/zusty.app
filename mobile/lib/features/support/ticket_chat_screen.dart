import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/support.dart';
import '../../models/taxonomy.dart';
import '../../providers/account_provider.dart';
import '../../providers/core_providers.dart';
import '../../providers/taxonomy_provider.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_scaffold.dart';
import 'new_ticket.dart';

/// Screen 27 — Help & Support Chat.
///
/// A ticket thread with the Zusty support team, styled like the creator chat:
/// the user's replies in brand magenta on the right, the team's on white
/// cards on the left with the agent's name. A closed ticket refuses replies,
/// so it offers a new ticket instead of a composer that can only fail.
class TicketChatScreen extends ConsumerStatefulWidget {
  const TicketChatScreen({super.key, required this.ticketId});

  final int ticketId;

  @override
  ConsumerState<TicketChatScreen> createState() => _TicketChatScreenState();
}

class _TicketChatScreenState extends ConsumerState<TicketChatScreen> {
  final _composer = TextEditingController();
  final _scroll = ScrollController();
  bool _sending = false;

  /// Thread length last scrolled to, so a refresh with nothing new does not
  /// yank the user away from what they were reading.
  int _seenMessages = -1;

  @override
  void initState() {
    super.initState();
    _composer.addListener(_onComposerChanged);
  }

  @override
  void dispose() {
    _composer.removeListener(_onComposerChanged);
    _composer.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _onComposerChanged() => setState(() {});

  Future<void> _send() async {
    final text = _composer.text.trim();
    if (text.isEmpty || _sending) return;

    setState(() => _sending = true);
    try {
      await ref
          .read(accountRepositoryProvider)
          .replyToTicket(widget.ticketId, text);
      _composer.clear();
      ref.invalidate(ticketProvider(widget.ticketId));
      ref.invalidate(ticketsProvider);
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  /// Lands on the newest message when the thread opens or grows.
  void _followThread(int count) {
    if (count == _seenMessages) return;
    _seenMessages = count;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scroll.hasClients) return;
      final end = _scroll.position.maxScrollExtent;
      if (Motion.reduced(context)) {
        _scroll.jumpTo(end);
      } else {
        _scroll.animateTo(end, duration: Motion.slow, curve: Motion.standard);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    // `/support/tickets/new` resolves through the `tickets/:id` route with
    // no numeric id. Rather than an empty thread for ticket 0, that path
    // shows the new-ticket form.
    if (widget.ticketId <= 0) return const NewTicketScreen();

    final ticket = ref.watch(ticketProvider(widget.ticketId));
    final data = ticket.valueOrNull;

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        titleWidget: Row(
          children: [
            const _AgentMark(size: 36),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    'Zusty Support',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.titleSmall,
                  ),
                  Text(
                    data == null || data.reference.isEmpty
                        ? 'Help & support'
                        : data.reference,
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
        actions: [
          if (data != null)
            Padding(
              padding: const EdgeInsets.only(right: AppSpacing.xs),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 150),
                  child: StatusChip(
                    label: data.statusLabel,
                    tone: data.tone,
                    dense: true,
                  ),
                ),
              ),
            ),
        ],
      ),
      bottomBar: data == null
          ? null
          : data.isClosed
          ? const _ClosedBar()
          : _Composer(controller: _composer, sending: _sending, onSend: _send),
      body: AsyncView(
        value: ticket,
        onRetry: () => ref.invalidate(ticketProvider(widget.ticketId)),
        loading: const ListSkeleton(
          count: 4,
          height: 72,
          padding: EdgeInsets.all(AppSpacing.md),
        ),
        builder: (data) {
          _followThread(data.messages.length);

          return ZRefresh(
            onRefresh: () async {
              ref.invalidate(ticketProvider(widget.ticketId));
              try {
                await ref.read(ticketProvider(widget.ticketId).future);
              } catch (_) {
                // The thread keeps showing what it had.
              }
            },
            child: RevealScope(
              child: ListView(
                controller: _scroll,
                physics: const AlwaysScrollableScrollPhysics(),
                padding: EdgeInsets.symmetric(
                  horizontal: AppBreakpoints.pageMargin(context),
                  vertical: AppSpacing.md,
                ),
                children: [
                  _TicketSummary(ticket: data),
                  const SizedBox(height: AppSpacing.lg),
                  for (var i = 0; i < data.messages.length; i++) ...[
                    if (_startsNewDay(data.messages, i))
                      _DayDivider(date: data.messages[i].createdAt),
                    Reveal(
                      revealKey: data.messages[i].id,
                      index: i,
                      offset: Offset(data.messages[i].isStaff ? -14 : 14, 8),
                      child: _TicketBubble(message: data.messages[i]),
                    ),
                  ],
                  if (data.messages.isEmpty)
                    const EmptyState(
                      title: 'No replies yet',
                      message: 'Our team usually responds within a few hours.',
                      icon: Icons.support_agent_rounded,
                      compact: true,
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  static bool _startsNewDay(List<TicketMessage> messages, int index) {
    final current = messages[index].createdAt;
    if (current == null) return false;
    if (index == 0) return true;
    final previous = messages[index - 1].createdAt;
    return previous == null ||
        previous.year != current.year ||
        previous.month != current.month ||
        previous.day != current.day;
  }
}

/// What the ticket is about, pinned at the top of the thread.
class _TicketSummary extends ConsumerWidget {
  const _TicketSummary({required this.ticket});

  final SupportTicket ticket;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final category =
        ref.watch(termMapProvider(Tax.ticketCategory))[ticket.category] ??
        ticket.categoryLabel;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.lgAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(
              Icons.confirmation_number_outlined,
              size: 18,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(ticket.subject, style: AppTypography.titleSmall),
                const SizedBox(height: 2),
                Text(
                  '$category · opened ${Formatters.relative(ticket.createdAt)}',
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _DayDivider extends StatelessWidget {
  const _DayDivider({required this.date});

  final DateTime? date;

  @override
  Widget build(BuildContext context) {
    if (date == null) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Center(
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm,
            vertical: 4,
          ),
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerHigh,
            borderRadius: AppRadius.pill,
          ),
          child: Text(
            Formatters.dayLabel(date!),
            style: AppTypography.labelSmall.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ),
      ),
    );
  }
}

class _TicketBubble extends StatelessWidget {
  const _TicketBubble({required this.message});

  final TicketMessage message;

  @override
  Widget build(BuildContext context) {
    final staff = message.isStaff;
    final foreground = staff ? AppColors.onSurface : AppColors.onPrimary;

    final radius = BorderRadius.only(
      topLeft: const Radius.circular(AppRadius.lg),
      topRight: const Radius.circular(AppRadius.lg),
      bottomLeft: Radius.circular(staff ? 4 : AppRadius.lg),
      bottomRight: Radius.circular(staff ? AppRadius.lg : 4),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Row(
        mainAxisAlignment: staff
            ? MainAxisAlignment.start
            : MainAxisAlignment.end,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          if (staff) ...[
            const _AgentMark(size: 28),
            const SizedBox(width: AppSpacing.xs),
          ],
          Flexible(
            child: Container(
              constraints: const BoxConstraints(maxWidth: 320),
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.sm,
                AppSpacing.xs + 2,
                AppSpacing.sm,
                AppSpacing.xs,
              ),
              decoration: BoxDecoration(
                color: staff
                    ? AppColors.surfaceContainerLowest
                    : AppColors.primary,
                borderRadius: radius,
                border: staff
                    ? Border.all(color: AppColors.outlineVariant)
                    : null,
                boxShadow: staff ? AppShadows.subtle : null,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (staff) ...[
                    Text(
                      message.authorName.isEmpty
                          ? 'Zusty Support'
                          : message.authorName,
                      style: AppTypography.labelMedium.copyWith(
                        color: AppColors.primary,
                      ),
                    ),
                    const SizedBox(height: 2),
                  ],
                  Text(
                    message.body,
                    style: AppTypography.bodyMedium.copyWith(
                      color: foreground,
                      height: 1.45,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Align(
                    alignment: Alignment.centerRight,
                    child: Text(
                      message.timeLabel,
                      style: AppTypography.labelSmall.copyWith(
                        color: foreground.withValues(alpha: 0.72),
                        fontSize: 10,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The support team's avatar: a magenta disc with the agent glyph.
class _AgentMark extends StatelessWidget {
  const _AgentMark({required this.size});

  final double size;

  @override
  Widget build(BuildContext context) {
    return ExcludeSemantics(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [AppColors.primary, AppColors.primaryContainer],
          ),
        ),
        child: Icon(
          Icons.support_agent_rounded,
          size: size * 0.55,
          color: AppColors.onPrimary,
        ),
      ),
    );
  }
}

class _Composer extends StatelessWidget {
  const _Composer({
    required this.controller,
    required this.sending,
    required this.onSend,
  });

  final TextEditingController controller;
  final bool sending;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    final canSend = controller.text.trim().isNotEmpty && !sending;
    final pill = OutlineInputBorder(
      borderRadius: AppRadius.pill,
      borderSide: BorderSide(color: AppColors.outlineVariant),
    );

    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Expanded(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 132),
            child: TextField(
              controller: controller,
              minLines: 1,
              maxLines: 5,
              maxLength: 5000,
              textCapitalization: TextCapitalization.sentences,
              style: AppTypography.bodyMedium,
              cursorColor: AppColors.primary,
              decoration: InputDecoration(
                hintText: 'Reply to support...',
                isDense: true,
                counterText: '',
                filled: true,
                fillColor: AppColors.surfaceContainerLow,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.md,
                  vertical: AppSpacing.sm,
                ),
                border: pill,
                enabledBorder: pill,
                focusedBorder: pill.copyWith(
                  borderSide: BorderSide(color: AppColors.primary, width: 1.4),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Semantics(
          button: true,
          enabled: canSend,
          label: 'Send reply',
          child: Material(
            color: canSend
                ? AppColors.primary
                : AppColors.surfaceContainerHighest,
            shape: const CircleBorder(),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: canSend ? onSend : null,
              child: SizedBox(
                width: 46,
                height: 46,
                child: sending
                    ? Padding(
                        padding: const EdgeInsets.all(13),
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          valueColor: AlwaysStoppedAnimation(
                            AppColors.onPrimary,
                          ),
                        ),
                      )
                    : Icon(
                        Icons.send_rounded,
                        size: 20,
                        color: canSend
                            ? AppColors.onPrimary
                            : AppColors.outline,
                      ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Replaces the composer on a closed ticket.
class _ClosedBar extends StatelessWidget {
  const _ClosedBar();

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.lock_outline_rounded,
              size: 16,
              color: AppColors.onSurfaceVariant,
            ),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                'This ticket is closed.',
                style: AppTypography.bodySmall,
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        ZButton.tonal(
          label: 'Open a new ticket',
          icon: Icons.add_rounded,
          onPressed: () => NewTicketSheet.show(context),
        ),
      ],
    );
  }
}
