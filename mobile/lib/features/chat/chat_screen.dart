import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/booking.dart';
import '../../models/message.dart';
import '../../models/safety.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';
import '../../providers/message_provider.dart';
import '../../providers/safety_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../safety/block_dialogs.dart';
import '../safety/report_sheet.dart';
import 'widgets/chat_image.dart';

/// The chat thread behind a conversation row.
///
/// Text, image, and file messages with read receipts, day dividers, and a
/// system-message rail for booking status changes. A strip under the header
/// carries the thread's context — the booking it belongs to, or the creator's
/// profile — so the next step is one tap away rather than in a menu.
class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({super.key, required this.conversationId});

  final int conversationId;

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> {
  final _composer = TextEditingController();
  final _scroll = ScrollController();

  /// Messages already on screen when the thread opened. They stay still;
  /// only messages that arrive after that slide in.
  Set<int>? _initialIds;

  String _lastText = '';
  DateTime? _lastTypingPing;

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

  /// Tells the other side "typing…" while text is being written — at most
  /// once every few seconds, and never for a cursor move or a cleared field.
  void _onComposerChanged() {
    final text = _composer.text;
    if (text == _lastText) return;
    _lastText = text;
    if (text.trim().isEmpty) return;

    final now = DateTime.now();
    final last = _lastTypingPing;
    if (last != null && now.difference(last) < const Duration(seconds: 4)) {
      return;
    }

    _lastTypingPing = now;
    // A lost ping only means "typing…" shows a moment later on the other side.
    ref
        .read(messageRepositoryProvider)
        .setTyping(widget.conversationId)
        .ignore();
  }

  // The thread is a reversed list — offset 0 is the newest message — so it
  // opens at the latest message without a jump, and new ones land in view.
  bool get _nearBottom => _scroll.hasClients && _scroll.offset < 96;

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scroll.hasClients) return;
      _scroll.animateTo(
        0,
        duration: Motion.of(context, Motion.medium),
        curve: Motion.standard,
      );
    });
  }

  Future<void> _send() async {
    final text = _composer.text.trim();
    if (text.isEmpty) return;

    _composer.clear();
    await ref.read(threadProvider(widget.conversationId).notifier).send(text);
    _scrollToEnd();
  }

  Future<void> _attachImage() async {
    final picker = ImagePicker();
    final file = await picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 80,
    );
    if (file == null) return;

    // No caption: the photo is the message. The app used to send the word
    // "Photo" here, which then rendered as a caption nobody wrote.
    await ref
        .read(threadProvider(widget.conversationId).notifier)
        .send('', type: 'image', mediaUrl: file.path);
    _scrollToEnd();
  }

  Future<void> _toggleMute(Conversation conversation) async {
    try {
      await ref
          .read(messageRepositoryProvider)
          .mute(conversation.id, muted: !conversation.isMuted);
      ref.invalidate(conversationsProvider);
      if (mounted) {
        showZSnack(
          context,
          conversation.isMuted
              ? 'Notifications for this chat are back on.'
              : 'Muted. You will not be notified about new messages here.',
        );
      }
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    }
  }

  /// Reports the other person. A single message is reported from its own
  /// long-press, so the report says which one.
  Future<void> _report(Conversation conversation) async {
    final userId = conversation.otherUserId;
    if (userId == null) {
      showZSnack(
        context,
        'This conversation cannot be reported from the app. Contact support.',
        isError: true,
      );
      return;
    }

    await _closingIfBlocked(
      userId,
      () => showReportSheet(
        context,
        target: ReportTarget.user,
        id: userId,
        name: conversation.otherName,
        blockUserId: userId,
      ),
    );
  }

  Future<void> _reportMessage(Message message, Conversation? conversation) =>
      _closingIfBlocked(
        conversation?.otherUserId,
        () => showReportSheet(
          context,
          target: ReportTarget.message,
          id: message.id,
          name: conversation?.otherName,
          blockUserId: conversation?.otherUserId,
        ),
      );

  /// Runs [flow] (a report sheet, which can end in "Block them too") and,
  /// when it blocked [userId], closes the thread the same way [_block] does.
  Future<void> _closingIfBlocked(
    int? userId,
    Future<Object?> Function() flow,
  ) async {
    final wasBlocked = ref.read(isBlockedProvider(userId));
    await flow();
    if (!mounted || userId == null || wasBlocked) return;
    if (ref.read(isBlockedProvider(userId))) _closeAfterBlock();
  }

  /// Blocks the other person. The thread then leaves the inbox, so the chat
  /// closes with it; the booking it belongs to, if any, still opens it.
  Future<void> _block(Conversation conversation) async {
    final userId = conversation.otherUserId;
    if (userId == null) return;

    final blocked = await confirmBlock(
      context,
      ref,
      userId: userId,
      name: conversation.otherName,
    );

    if (blocked && mounted) _closeAfterBlock();
  }

  /// A block just made here: nobody may post any more, whatever the inbox
  /// row (now gone) said, and the chat closes with the thread.
  void _closeAfterBlock() {
    ref.read(threadClosedProvider(widget.conversationId).notifier).state =
        true;
    if (context.canPop()) context.pop();
  }

  Future<void> _unblock(Conversation conversation) async {
    final userId = conversation.otherUserId;
    if (userId == null) return;

    final unblocked = await confirmUnblock(
      context,
      ref,
      userId: userId,
      name: conversation.otherName,
    );

    if (unblocked) {
      ref.invalidate(threadProvider(widget.conversationId));
    }
  }

  @override
  Widget build(BuildContext context) {
    final thread = ref.watch(threadProvider(widget.conversationId));
    final conversations = ref.watch(conversationsProvider);
    final typing = ref.watch(typingProvider(widget.conversationId));
    final isBrand = ref.watch(currentUserProvider)?.role.isBrand ?? false;

    // Keep the typing bubble in view when it appears — if the reader is at
    // the bottom of the thread, not when they have scrolled back up.
    ref.listen<bool>(typingProvider(widget.conversationId), (previous, next) {
      if (next && !(previous ?? false) && _nearBottom) _scrollToEnd();
    });

    // The inbox row, or the thread's own account of who it is with when the
    // inbox leaves it out: a thread with someone the user blocked, opened
    // from its booking or blocked a moment ago from this screen.
    final conversation =
        conversations.valueOrNull
            ?.where((c) => c.id == widget.conversationId)
            .firstOrNull ??
        ref.watch(threadConversationProvider(widget.conversationId));

    final handle = conversation?.otherHandle;
    final bookingId = conversation?.bookingId;
    final otherUserId = conversation?.otherUserId;

    // A block either way closes the thread: one this user made in this
    // session, one the server reports on the inbox row or on the last poll,
    // or a send it refused as `blocked`.
    final blockedByMe = ref.watch(isBlockedProvider(otherUserId));
    final closedByBlock =
        blockedByMe ||
        !(conversation?.canMessage ?? true) ||
        ref.watch(threadClosedProvider(widget.conversationId));

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        titleWidget: conversation == null
            ? Text('Chat', style: AppTypography.titleMedium)
            : _ChatTitle(conversation: conversation, typing: typing),
        actions: [
          if (bookingId != null)
            IconButton(
              icon: const Icon(Icons.work_outline_rounded, size: 22),
              tooltip: 'View booking',
              onPressed: () => context.push(Routes.booking(bookingId)),
            ),
          if (conversation != null)
            PopupMenuButton<String>(
              icon: const Icon(Icons.more_vert_rounded, size: 22),
              tooltip: 'More options',
              position: PopupMenuPosition.under,
              shape: const RoundedRectangleBorder(
                borderRadius: AppRadius.lgAll,
              ),
              onSelected: (value) => switch (value) {
                'profile' => context.push(Routes.creator(handle!)),
                'booking' => context.push(Routes.booking(bookingId!)),
                'mute' => _toggleMute(conversation),
                'report' => _report(conversation),
                'block' => _block(conversation),
                'unblock' => _unblock(conversation),
                _ => null,
              },
              itemBuilder: (_) => [
                if (handle != null)
                  _menuItem(
                    'profile',
                    Icons.person_outline_rounded,
                    'View profile',
                  ),
                if (bookingId != null)
                  _menuItem(
                    'booking',
                    Icons.work_outline_rounded,
                    'View booking',
                  ),
                _menuItem(
                  'mute',
                  conversation.isMuted
                      ? Icons.notifications_active_outlined
                      : Icons.notifications_off_outlined,
                  conversation.isMuted
                      ? 'Unmute conversation'
                      : 'Mute conversation',
                ),
                _menuItem(
                  'report',
                  Icons.flag_outlined,
                  'Report',
                  destructive: true,
                ),
                if (otherUserId != null)
                  blockedByMe
                      ? _menuItem('unblock', Icons.lock_open_rounded, 'Unblock')
                      : _menuItem(
                          'block',
                          Icons.block_rounded,
                          'Block',
                          destructive: true,
                        ),
              ],
            ),
        ],
      ),
      // The composer lives in the body, not the scaffold's bottom bar: a
      // bottom bar is laid out against the full screen height and sits
      // underneath the keyboard, while the body is resized above it.
      body: Column(
        children: [
          if (conversation != null)
            // No "Book" across a block: the server would refuse the request.
            _ContextStrip(
              conversation: conversation,
              isBrand: isBrand && !closedByBlock,
            ),
          Expanded(
            child: AsyncView(
              value: thread,
              onRetry: () =>
                  ref.invalidate(threadProvider(widget.conversationId)),
              loading: const _ThreadSkeleton(),
              builder: (messages) {
                if (messages.isEmpty && !typing) {
                  // Centred while it fits; scrolls rather than overflowing
                  // when the keyboard leaves only a sliver of thread.
                  return LayoutBuilder(
                    builder: (context, constraints) => SingleChildScrollView(
                      primary: false,
                      keyboardDismissBehavior:
                          ScrollViewKeyboardDismissBehavior.onDrag,
                      child: ConstrainedBox(
                        constraints: BoxConstraints(
                          minHeight: constraints.maxHeight,
                        ),
                        child: EmptyState(
                          title: 'Say hello',
                          message: conversation == null
                              ? 'Start the conversation with a quick '
                                    'introduction.'
                              : 'Introduce yourself to '
                                    '${conversation.otherName.split(' ').first} '
                                    'and share what you have in mind.',
                          icon: Icons.waving_hand_rounded,
                          compact: true,
                        ),
                      ),
                    ),
                  );
                }

                // Remembers which messages have already slid in, so a row
                // rebuilt by a poll or a scroll does not replay its entrance.
                return RevealScope(
                  child: _ThreadList(
                    messages: messages,
                    initialIds: _initialIds ??= {
                      for (final m in messages) m.id,
                    },
                    controller: _scroll,
                    typing: typing,
                    conversation: conversation,
                    onRetry: (message) => ref
                        .read(threadProvider(widget.conversationId).notifier)
                        .retry(message),
                    onReport: (message) =>
                        _reportMessage(message, conversation),
                  ),
                );
              },
            ),
          ),
          if (conversation?.isLocked ?? false)
            const _ClosedNotice()
          else if (closedByBlock)
            _BlockedNotice(
              name: blockedByMe ? conversation?.otherName : null,
              onUnblock: blockedByMe && conversation != null
                  ? () => _unblock(conversation)
                  : null,
            )
          else
            _Composer(
              controller: _composer,
              onSend: _send,
              onAttach: _attachImage,
            ),
        ],
      ),
    );
  }

  static PopupMenuItem<String> _menuItem(
    String value,
    IconData icon,
    String label, {
    bool destructive = false,
  }) {
    final color = destructive ? AppColors.error : AppColors.onSurface;
    return PopupMenuItem(
      value: value,
      height: 48,
      child: Row(
        children: [
          Icon(icon, size: 20, color: color),
          const SizedBox(width: AppSpacing.sm),
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.bodyMedium.copyWith(color: color),
            ),
          ),
        ],
      ),
    );
  }
}

/// The messages, newest at the bottom, with day dividers and sender grouping.
class _ThreadList extends StatelessWidget {
  const _ThreadList({
    required this.messages,
    required this.initialIds,
    required this.controller,
    required this.typing,
    required this.conversation,
    required this.onRetry,
    this.onReport,
  });

  final List<Message> messages;
  final Set<int> initialIds;
  final ScrollController controller;
  final bool typing;
  final Conversation? conversation;
  final ValueChanged<Message> onRetry;

  /// Reports one message the other person sent, from its long-press menu.
  final ValueChanged<Message>? onReport;

  /// Consecutive messages from one side within a few minutes read as one
  /// burst: tighter spacing, one avatar, one timestamp.
  static bool _sameBurst(Message a, Message b) {
    if (a.isSystem || b.isSystem) return false;
    if (a.isMine != b.isMine) return false;
    if (!a.isMine && a.senderId != b.senderId) return false;
    final at = a.createdAt;
    final bt = b.createdAt;
    if (at == null || bt == null) return true;
    if (!_sameDay(at, bt)) return false;
    return bt.difference(at).inMinutes.abs() < 5;
  }

  static bool _sameDay(DateTime? a, DateTime? b) {
    if (a == null || b == null) return false;
    return a.year == b.year && a.month == b.month && a.day == b.day;
  }

  @override
  Widget build(BuildContext context) {
    final count = messages.length;

    return ListView.builder(
      controller: controller,
      reverse: true,
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: EdgeInsets.fromLTRB(
        AppBreakpoints.pageMargin(context),
        AppSpacing.md,
        AppBreakpoints.pageMargin(context),
        AppSpacing.sm,
      ),
      // Slot 0 (the bottom, in a reversed list) is the typing bubble, always
      // present so it can grow in and shrink away rather than pop.
      itemCount: count + 1,
      // In a reversed list every new message shifts the rest up a slot.
      // Rows are keyed by message id and found again by it, so each keeps
      // its own element (and its finished entrance) instead of inheriting
      // the one that used to sit at its index.
      findChildIndexCallback: (key) {
        if (key is! ValueKey<int>) return null;
        final i = messages.indexWhere((m) => m.id == key.value);
        return i < 0 ? null : count - i;
      },
      itemBuilder: (_, slot) {
        if (slot == 0) {
          return KeyedSubtree(
            key: const ValueKey('typing'),
            child: _TypingFooter(visible: typing),
          );
        }

        final i = count - slot;
        final message = messages[i];
        final previous = i == 0 ? null : messages[i - 1];
        final next = i == count - 1 ? null : messages[i + 1];

        final showDay =
            previous == null ||
            !_sameDay(previous.createdAt, message.createdAt);
        final first = showDay || !_sameBurst(previous, message);
        final last = next == null || !_sameBurst(message, next);

        // Only what the other person sent, once the server has it.
        final report = onReport;
        final reportable =
            report != null && !message.isMine && !message.isSystem && message.id > 0;

        Widget bubble = MessageBubble(
          message: message,
          isFirstInGroup: first,
          isLastInGroup: last,
          fallbackName: conversation?.otherName,
          fallbackAvatar: conversation?.otherAvatar,
          onRetry: message.failed ? () => onRetry(message) : null,
          onReport: reportable ? () => report(message) : null,
        );

        // A new message slides in from its sender's side, once. A message
        // sent from here slides in as its pending copy; the server's
        // confirmed copy that replaces it arrives under a new id and must
        // not slide in a second time, so a confirmed message of one's own
        // is drawn at rest.
        final settled =
            initialIds.contains(message.id) ||
            (message.isMine && !message.isSending && !message.failed);
        if (!settled) {
          bubble = Reveal(
            revealKey: message.id,
            offset: Offset(message.isMine ? 18 : -18, 10),
            child: bubble,
          );
        }

        return KeyedSubtree(
          key: ValueKey<int>(message.id),
          child: Column(
            children: [
              if (showDay && message.createdAt != null)
                _DayDivider(date: message.createdAt!),
              bubble,
            ],
          ),
        );
      },
    );
  }
}

class _ChatTitle extends StatelessWidget {
  const _ChatTitle({required this.conversation, this.typing = false});

  final Conversation conversation;

  /// The other person is typing right now.
  final bool typing;

  @override
  Widget build(BuildContext context) {
    final handle = conversation.otherHandle;
    final offlineLine = conversation.otherHeadline?.isNotEmpty == true
        ? conversation.otherHeadline!
        : 'Offline';

    final title = Row(
      children: [
        ZAvatar(
          imageUrl: conversation.otherAvatar,
          name: conversation.otherName,
          size: 40,
          isOnline: conversation.isOnline,
        ),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Row(
                children: [
                  Flexible(
                    child: Text(
                      conversation.otherName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.titleSmall.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  if (conversation.isVerified) ...[
                    const SizedBox(width: 4),
                    const VerifiedBadge(size: 14),
                  ],
                  if (conversation.isMuted) ...[
                    const SizedBox(width: 4),
                    Icon(
                      Icons.notifications_off_outlined,
                      size: 14,
                      color: AppColors.onSurfaceVariant,
                      semanticLabel: 'Muted',
                    ),
                  ],
                ],
              ),
              // "typing…", "Online" or the headline, cross-faded as it changes.
              AnimatedSwitcher(
                duration: Motion.of(context, Motion.medium),
                switchInCurve: Motion.standard,
                switchOutCurve: Motion.exit,
                layoutBuilder: (current, previous) => Stack(
                  alignment: Alignment.centerLeft,
                  children: [...previous, ?current],
                ),
                child: Text(
                  typing
                      ? 'typing…'
                      : (conversation.isOnline ? 'Online' : offlineLine),
                  key: ValueKey(typing ? 'typing' : conversation.isOnline),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.labelSmall.copyWith(
                    color: typing
                        ? AppColors.primary
                        : (conversation.isOnline
                              ? AppColors.online
                              : AppColors.onSurfaceVariant),
                    fontWeight: typing || conversation.isOnline
                        ? FontWeight.w600
                        : FontWeight.w400,
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );

    if (handle == null) return title;

    // The whole title opens the creator's profile, not just the avatar.
    return Semantics(
      button: true,
      label: 'View ${conversation.otherName}\'s profile',
      child: InkWell(
        onTap: () => context.push(Routes.creator(handle)),
        borderRadius: AppRadius.mdAll,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: AppSpacing.minTouch),
          child: title,
        ),
      ),
    );
  }
}

/// What this thread is about, with the next step beside it.
///
/// A booking thread shows the booking and its status with "View booking". A
/// direct thread with a creator gives a brand "Book" — the conversion this
/// conversation usually exists for — and anyone "Profile". A thread with
/// neither (a creator talking to a brand outside a booking) shows nothing.
class _ContextStrip extends StatelessWidget {
  const _ContextStrip({required this.conversation, required this.isBrand});

  final Conversation conversation;
  final bool isBrand;

  @override
  Widget build(BuildContext context) {
    final bookingId = conversation.bookingId;
    final handle = conversation.otherHandle;

    if (bookingId == null && handle == null) return const SizedBox.shrink();

    final margin = AppBreakpoints.pageMargin(context);
    final status = conversation.bookingStatus == null
        ? null
        : BookingStatus.fromString(conversation.bookingStatus);

    final Widget lead;
    final List<Widget> actions;

    if (bookingId != null) {
      lead = _StripLabel(
        icon: Icons.work_outline_rounded,
        title: conversation.bookingLabel ?? 'Booking',
        trailing: status == null
            ? null
            : StatusChip(label: status.label, tone: status.tone, dense: true),
      );
      actions = [
        ZButton.tonal(
          label: 'View booking',
          size: ZButtonSize.small,
          expand: false,
          onPressed: () => context.push(Routes.booking(bookingId)),
        ),
      ];
    } else {
      lead = _StripLabel(
        icon: Icons.auto_awesome_outlined,
        title: conversation.otherHeadline?.isNotEmpty == true
            ? conversation.otherHeadline!
            : 'Creator on Zusty',
      );
      actions = [
        ZButton(
          label: 'Profile',
          variant: ZButtonVariant.outlined,
          size: ZButtonSize.small,
          expand: false,
          onPressed: () => context.push(Routes.creator(handle!)),
        ),
        if (isBrand) ...[
          const SizedBox(width: AppSpacing.xs),
          ZButton(
            label: 'Book',
            size: ZButtonSize.small,
            expand: false,
            onPressed: () => context.push(Routes.newBooking(handle!)),
          ),
        ],
      ];
    }

    return Container(
      padding: EdgeInsets.fromLTRB(
        margin,
        AppSpacing.xs,
        margin,
        AppSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        border: Border(bottom: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: Row(
        children: [
          Expanded(child: lead),
          const SizedBox(width: AppSpacing.xs),
          ...actions,
        ],
      ),
    );
  }
}

class _StripLabel extends StatelessWidget {
  const _StripLabel({required this.icon, required this.title, this.trailing});

  final IconData icon;
  final String title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerLow,
            borderRadius: AppRadius.mdAll,
          ),
          child: Icon(icon, size: 16, color: AppColors.primary),
        ),
        const SizedBox(width: AppSpacing.xs),
        Flexible(
          child: Text(
            title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.labelMedium.copyWith(
              color: AppColors.onSurface,
            ),
          ),
        ),
        if (trailing != null) ...[
          const SizedBox(width: 6),
          Flexible(child: trailing!),
        ],
      ],
    );
  }
}

/// The other person's typing bubble at the end of the thread: grows in under
/// the last message and shrinks away when they stop, styled like an incoming
/// message.
class _TypingFooter extends StatelessWidget {
  const _TypingFooter({required this.visible});

  final bool visible;

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: Motion.of(context, Motion.medium),
      switchInCurve: Motion.emphasized,
      switchOutCurve: Motion.exit,
      transitionBuilder: (child, animation) => SizeTransition(
        sizeFactor: animation,
        axisAlignment: -1,
        child: FadeTransition(opacity: animation, child: child),
      ),
      child: !visible
          ? const SizedBox.shrink(key: ValueKey('idle'))
          : Align(
              key: const ValueKey('typing'),
              alignment: Alignment.centerLeft,
              child: Padding(
                // Lines up with incoming bubbles, which sit beside a 28px
                // avatar and an 8px gap.
                padding: const EdgeInsets.only(
                  left: MessageBubble.avatarGutter,
                  top: AppSpacing.base,
                  bottom: AppSpacing.xs,
                ),
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.sm + 2,
                    vertical: AppSpacing.sm + 2,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLowest,
                    border: Border.all(color: AppColors.outlineVariant),
                    borderRadius: const BorderRadius.only(
                      topLeft: Radius.circular(AppRadius.xl),
                      topRight: Radius.circular(AppRadius.xl),
                      bottomLeft: Radius.circular(4),
                      bottomRight: Radius.circular(AppRadius.xl),
                    ),
                  ),
                  child: const TypingIndicator(),
                ),
              ),
            ),
    );
  }
}

/// A single chat bubble.
///
/// Outgoing messages sit on the soft magenta tint with deep plum text — the
/// brand colour without a wall of saturated pink down one side of the screen.
/// Incoming messages are white cards with a hairline border, like everything
/// else in the app. Long-press copies the text; on a message the other person
/// sent, with [onReport], it offers Copy and Report instead.
class MessageBubble extends StatelessWidget {
  const MessageBubble({
    super.key,
    required this.message,
    this.onRetry,
    this.onReport,
    this.isFirstInGroup = true,
    this.isLastInGroup = true,
    this.fallbackName,
    this.fallbackAvatar,
  });

  final Message message;
  final VoidCallback? onRetry;
  final VoidCallback? onReport;

  /// Position within a burst of messages from one side; drives spacing,
  /// the tail corner, and whether the avatar and timestamp show.
  final bool isFirstInGroup;
  final bool isLastInGroup;

  /// The thread's other participant. The messages endpoint carries no sender
  /// name or photo, so an incoming bubble borrows them from the conversation.
  final String? fallbackName;
  final String? fallbackAvatar;

  /// Avatar width plus its gap: where incoming content starts.
  static const double avatarGutter = 28 + AppSpacing.xs;

  /// The caption the app used to attach to every photo. It was never typed by
  /// anyone, so it is not shown.
  static const _legacyPhotoCaption = 'Photo';

  @override
  Widget build(BuildContext context) {
    if (message.isSystem) return _SystemMessage(message: message);

    final mine = message.isMine;
    final background = mine
        ? AppColors.primaryFixed
        : AppColors.surfaceContainerLowest;
    final foreground = mine ? AppColors.onPrimaryFixed : AppColors.onSurface;
    final isImage = message.type == MessageType.image;
    final showMeta = isLastInGroup || message.failed || message.isSending;

    const big = Radius.circular(AppRadius.xl);
    const joined = Radius.circular(6);
    const tail = Radius.circular(4);

    final radius = mine
        ? BorderRadius.only(
            topLeft: big,
            bottomLeft: big,
            topRight: isFirstInGroup ? big : joined,
            bottomRight: isLastInGroup ? tail : joined,
          )
        : BorderRadius.only(
            topRight: big,
            bottomRight: big,
            topLeft: isFirstInGroup ? big : joined,
            bottomLeft: isLastInGroup ? tail : joined,
          );

    return Padding(
      padding: EdgeInsets.only(bottom: isLastInGroup ? AppSpacing.sm : 2),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final maxBubble = (constraints.maxWidth * 0.78).clamp(0.0, 360.0);
          final imageWidth = (maxBubble - 8).clamp(120.0, 240.0);

          final canCopy = message.body.isNotEmpty && !isImage;

          final bubble = GestureDetector(
            onLongPress: onReport != null
                ? () => _actions(context, canCopy: canCopy)
                : (canCopy ? () => _copy(context) : null),
            child: Container(
              constraints: BoxConstraints(maxWidth: maxBubble),
              padding: isImage
                  ? const EdgeInsets.all(4)
                  : const EdgeInsets.fromLTRB(
                      AppSpacing.sm + 2,
                      AppSpacing.xs + 1,
                      AppSpacing.sm + 2,
                      AppSpacing.xs,
                    ),
              decoration: BoxDecoration(
                color: background,
                borderRadius: radius,
                border: mine
                    ? null
                    : Border.all(color: AppColors.outlineVariant),
              ),
              // As wide as the wider of text and time, with the text
              // reading from the left and the time tucked to the right, the
              // way every messenger lays a bubble out. End-aligning the whole
              // column pushed a short "hi" to the right edge.
              child: IntrinsicWidth(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _content(foreground, imageWidth),
                    if (showMeta)
                      Align(
                        alignment: Alignment.centerRight,
                        child: Padding(
                          padding: EdgeInsets.only(
                            top: 3,
                            right: isImage ? 6 : 0,
                            bottom: isImage ? 2 : 0,
                          ),
                          child: _Meta(
                            message: message,
                            foreground: foreground,
                            onRetry: onRetry,
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          );

          return Row(
            mainAxisAlignment: mine
                ? MainAxisAlignment.end
                : MainAxisAlignment.start,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              if (!mine)
                SizedBox(
                  width: avatarGutter,
                  child: isLastInGroup
                      ? Align(
                          alignment: Alignment.bottomLeft,
                          child: ZAvatar(
                            imageUrl: message.senderAvatar ?? fallbackAvatar,
                            name: message.senderName.isNotEmpty
                                ? message.senderName
                                : fallbackName,
                            size: 28,
                          ),
                        )
                      : null,
                ),
              Flexible(child: bubble),
            ],
          );
        },
      ),
    );
  }

  void _copy(BuildContext context) {
    Clipboard.setData(ClipboardData(text: message.body));
    HapticFeedback.selectionClick();
    showZSnack(context, 'Message copied');
  }

  /// What a long-press on someone else's message offers: copy its text, and
  /// report it.
  Future<void> _actions(BuildContext context, {required bool canCopy}) async {
    HapticFeedback.selectionClick();

    final choice = await showModalBottomSheet<String>(
      context: context,
      useSafeArea: true,
      sheetAnimationStyle: zSheetMotion(context),
      builder: (context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (canCopy)
              ListTile(
                leading: const Icon(Icons.copy_rounded),
                title: const Text('Copy text'),
                onTap: () => Navigator.of(context).pop('copy'),
              ),
            ListTile(
              leading: Icon(Icons.flag_outlined, color: AppColors.error),
              title: Text(
                'Report message',
                style: TextStyle(color: AppColors.error),
              ),
              onTap: () => Navigator.of(context).pop('report'),
            ),
          ],
        ),
      ),
    );

    if (!context.mounted) return;

    switch (choice) {
      case 'copy':
        _copy(context);
      case 'report':
        onReport?.call();
    }
  }

  Widget _content(Color foreground, double imageWidth) {
    switch (message.type) {
      case MessageType.image:
        final caption = message.body.trim();
        final showCaption =
            caption.isNotEmpty && caption != _legacyPhotoCaption;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ChatImage(message: message, width: imageWidth),
            if (showCaption)
              SizedBox(
                width: imageWidth,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(6, 6, 6, 0),
                  child: Text(
                    caption,
                    style: AppTypography.bodyMedium.copyWith(
                      color: foreground,
                      height: 1.4,
                    ),
                  ),
                ),
              ),
          ],
        );

      case MessageType.file:
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: foreground.withValues(alpha: 0.08),
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(
                Icons.description_outlined,
                size: 20,
                color: foreground,
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Flexible(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    message.fileName ?? 'Attachment',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.labelMedium.copyWith(
                      color: foreground,
                    ),
                  ),
                  if (message.fileSizeLabel.isNotEmpty)
                    Text(
                      message.fileSizeLabel,
                      style: AppTypography.labelSmall.copyWith(
                        color: foreground.withValues(alpha: 0.7),
                      ),
                    ),
                ],
              ),
            ),
          ],
        );

      case MessageType.voice:
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.play_arrow_rounded, size: 22, color: foreground),
            const SizedBox(width: 6),
            Container(
              width: 110,
              height: 3,
              decoration: BoxDecoration(
                color: foreground.withValues(alpha: 0.3),
                borderRadius: AppRadius.pill,
              ),
            ),
            const SizedBox(width: 6),
            Text(
              '${message.durationSecs}s',
              style: AppTypography.labelSmall.copyWith(color: foreground),
            ),
          ],
        );

      case MessageType.text:
      case MessageType.system:
        return Align(
          alignment: Alignment.centerLeft,
          widthFactor: 1,
          child: Text(
            message.body,
            style: AppTypography.bodyMedium.copyWith(
              color: foreground,
              height: 1.4,
            ),
          ),
        );
    }
  }
}

/// Time and delivery state under a bubble: a clock while sending, one tick
/// when delivered, two magenta ticks once read, or a retry prompt.
class _Meta extends StatelessWidget {
  const _Meta({required this.message, required this.foreground, this.onRetry});

  final Message message;
  final Color foreground;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final muted = foreground.withValues(alpha: 0.62);

    if (message.failed) {
      return Semantics(
        button: true,
        label: 'Not sent. Tap to retry',
        child: InkWell(
          onTap: onRetry,
          borderRadius: AppRadius.smAll,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.error_outline_rounded,
                  size: 14,
                  color: AppColors.error,
                ),
                const SizedBox(width: 4),
                Text(
                  'Not sent · Tap to retry',
                  style: AppTypography.labelSmall.copyWith(
                    color: AppColors.error,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    final IconData? tick = !message.isMine
        ? null
        : message.isSending
        ? Icons.schedule_rounded
        : (message.isRead ? Icons.done_all_rounded : Icons.done_rounded);

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          message.isSending ? 'Sending…' : message.timeLabel,
          style: AppTypography.labelSmall.copyWith(color: muted),
        ),
        if (tick != null) ...[
          const SizedBox(width: 4),
          Icon(
            tick,
            size: 14,
            color: message.isRead ? AppColors.primary : muted,
            semanticLabel: message.isSending
                ? 'Sending'
                : (message.isRead ? 'Read' : 'Delivered'),
          ),
        ],
      ],
    );
  }
}

/// A centred note for booking status changes posted into the thread.
class _SystemMessage extends StatelessWidget {
  const _SystemMessage({required this.message});

  final Message message;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: Center(
        child: Container(
          constraints: const BoxConstraints(maxWidth: 320),
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm,
            vertical: AppSpacing.xs,
          ),
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerLow,
            borderRadius: AppRadius.lgAll,
            border: Border.all(color: AppColors.outlineVariant),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.info_outline_rounded,
                size: 14,
                color: AppColors.onSurfaceVariant,
              ),
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  message.body,
                  textAlign: TextAlign.center,
                  style: AppTypography.labelSmall.copyWith(
                    color: AppColors.onSurfaceVariant,
                    height: 1.35,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// `Today`, `Yesterday`, a weekday, or a date — a pill between days.
class _DayDivider extends StatelessWidget {
  const _DayDivider({required this.date});

  final DateTime date;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.xs, bottom: AppSpacing.sm),
      child: Center(
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.sm,
            vertical: 4,
          ),
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerLowest,
            borderRadius: AppRadius.pill,
            border: Border.all(color: AppColors.outlineVariant),
          ),
          child: Text(
            Formatters.dayLabel(date),
            style: AppTypography.labelSmall.copyWith(
              color: AppColors.onSurfaceVariant,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ),
    );
  }
}

/// Bubble-shaped placeholders while the thread loads.
class _ThreadSkeleton extends StatelessWidget {
  const _ThreadSkeleton();

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);
    const shapes = [
      (false, 200.0),
      (true, 160.0),
      (false, 240.0),
      (true, 120.0),
    ];

    // Clipped rather than overflowing when the keyboard leaves only a sliver
    // of thread (a phone, or a tablet in landscape).
    return SingleChildScrollView(
      primary: false,
      physics: const NeverScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, 0),
      child: Column(
        children: [
          for (final (mine, width) in shapes)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Align(
                alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
                child: ZShimmer(
                  width: width,
                  height: 44,
                  borderRadius: AppRadius.xlAll,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Shown in place of the composer when the platform has closed the thread.
class _ClosedNotice extends StatelessWidget {
  const _ClosedNotice();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        border: Border(top: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            children: [
              Icon(
                Icons.lock_outline_rounded,
                size: 18,
                color: AppColors.onSurfaceVariant,
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: Text(
                  'This conversation is closed. You can still read it, '
                  'but new messages are off.',
                  style: AppTypography.bodySmall,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Shown in place of the composer when a block stands between the two
/// people in the thread. Someone who made the block ([name] set) is offered
/// to undo it; the other side is told only that messages are off.
class _BlockedNotice extends StatelessWidget {
  const _BlockedNotice({this.name, this.onUnblock});

  final String? name;
  final VoidCallback? onUnblock;

  @override
  Widget build(BuildContext context) {
    final name = this.name;

    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        border: Border(top: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.md,
            AppSpacing.sm,
            AppSpacing.sm,
            AppSpacing.sm,
          ),
          child: Row(
            children: [
              Icon(
                Icons.block_rounded,
                size: 18,
                color: AppColors.onSurfaceVariant,
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: Text(
                  name == null
                      ? 'You can\'t send messages in this conversation. You '
                            'can still read it.'
                      : 'You blocked $name. Unblock them to send messages.',
                  style: AppTypography.bodySmall,
                ),
              ),
              if (onUnblock != null)
                TextButton(onPressed: onUnblock, child: const Text('Unblock')),
            ],
          ),
        ),
      ),
    );
  }
}

/// The message composer pinned above the keyboard.
class _Composer extends StatefulWidget {
  const _Composer({
    required this.controller,
    required this.onSend,
    required this.onAttach,
  });

  final TextEditingController controller;
  final VoidCallback onSend;
  final VoidCallback onAttach;

  @override
  State<_Composer> createState() => _ComposerState();
}

class _ComposerState extends State<_Composer> {
  bool get _canSend => widget.controller.text.trim().isNotEmpty;

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_onChanged);
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onChanged);
    super.dispose();
  }

  void _onChanged() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final canSend = _canSend;
    final duration = Motion.of(context, Motion.fast);

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        border: Border(top: BorderSide(color: AppColors.outlineVariant)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(
            AppSpacing.xs,
            AppSpacing.xs,
            AppBreakpoints.pageMargin(context) - 4,
            AppSpacing.xs,
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              IconButton(
                icon: const Icon(Icons.add_photo_alternate_outlined, size: 24),
                color: AppColors.primary,
                tooltip: 'Attach a photo',
                style: IconButton.styleFrom(
                  minimumSize: const Size.square(AppSpacing.minTouch),
                ),
                onPressed: widget.onAttach,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxHeight: 132),
                  child: TextField(
                    controller: widget.controller,
                    minLines: 1,
                    maxLines: 5,
                    textCapitalization: TextCapitalization.sentences,
                    keyboardType: TextInputType.multiline,
                    style: AppTypography.bodyMedium,
                    cursorColor: AppColors.primary,
                    decoration: InputDecoration(
                      hintText: 'Write a message…',
                      isDense: true,
                      filled: true,
                      fillColor: AppColors.surfaceContainerLow,
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: AppSpacing.md,
                        vertical: AppSpacing.sm,
                      ),
                      border: OutlineInputBorder(
                        borderRadius: const BorderRadius.all(
                          Radius.circular(22),
                        ),
                        borderSide: BorderSide(color: AppColors.outlineVariant),
                      ),
                      enabledBorder: OutlineInputBorder(
                        borderRadius: const BorderRadius.all(
                          Radius.circular(22),
                        ),
                        borderSide: BorderSide(color: AppColors.outlineVariant),
                      ),
                      focusedBorder: OutlineInputBorder(
                        borderRadius: const BorderRadius.all(
                          Radius.circular(22),
                        ),
                        borderSide: BorderSide(
                          color: AppColors.primary,
                          width: 1.4,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Semantics(
                button: true,
                enabled: canSend,
                label: 'Send message',
                child: AnimatedContainer(
                  duration: duration,
                  curve: Motion.standard,
                  width: AppSpacing.minTouch,
                  height: AppSpacing.minTouch,
                  decoration: BoxDecoration(
                    color: canSend
                        ? AppColors.primary
                        : AppColors.surfaceContainerHighest,
                    shape: BoxShape.circle,
                    boxShadow: canSend ? AppShadows.subtle : null,
                  ),
                  child: Material(
                    type: MaterialType.transparency,
                    shape: const CircleBorder(),
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      onTap: canSend ? widget.onSend : null,
                      child: Icon(
                        Icons.arrow_upward_rounded,
                        size: 22,
                        color: canSend
                            ? AppColors.onPrimary
                            : AppColors.outline,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

