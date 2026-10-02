import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../models/message.dart';
import '../../providers/auth_provider.dart';
import '../../providers/message_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import '../../shared/widgets/z_text_field.dart';
import 'widgets/conversation_card.dart';

/// Screen 13 — Messages.
///
/// Conversation list with unread badges, online dots, and the booking each
/// thread is scoped to. Search goes to the server; the All / Unread / Bookings
/// pills narrow what is already loaded, so flipping between them is instant.
class MessagesScreen extends ConsumerStatefulWidget {
  const MessagesScreen({super.key});

  @override
  ConsumerState<MessagesScreen> createState() => _MessagesScreenState();
}

/// The inbox views. Client-side: each is a slice of the one list the server
/// returned, not another request.
enum _InboxFilter {
  all('All'),
  unread('Unread'),
  bookings('Bookings');

  const _InboxFilter(this.label);

  final String label;

  bool matches(Conversation c) => switch (this) {
    _InboxFilter.all => true,
    _InboxFilter.unread => c.hasUnread,
    _InboxFilter.bookings => c.isBookingThread,
  };
}

class _MessagesScreenState extends ConsumerState<MessagesScreen> {
  final _controller = TextEditingController();
  _InboxFilter _filter = _InboxFilter.all;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final conversations = ref.watch(conversationsProvider);
    final query = ref.watch(conversationQueryProvider);
    final isBrand = ref.watch(currentUserProvider)?.role.isBrand ?? true;
    final margin = AppBreakpoints.pageMargin(context);

    final unreadTotal =
        conversations.valueOrNull?.fold<int>(
          0,
          (sum, c) => sum + c.unreadCount,
        ) ??
        0;

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Messages'),
      body: Column(
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.xs,
              margin,
              AppSpacing.sm,
            ),
            child: ZSearchField(
              controller: _controller,
              hint: 'Search people or messages',
              showClear: query.isNotEmpty,
              onClear: () {
                _controller.clear();
                ref.read(conversationQueryProvider.notifier).state = '';
              },
              onChanged: (value) =>
                  ref.read(conversationQueryProvider.notifier).state = value,
            ),
          ),
          ZFilterPills(
            padding: EdgeInsets.symmetric(horizontal: margin),
            labels: [
              for (final f in _InboxFilter.values)
                f == _InboxFilter.unread && unreadTotal > 0
                    ? '${f.label} · $unreadTotal'
                    : f.label,
            ],
            selected: _filter.index,
            onSelected: (i) => setState(() => _filter = _InboxFilter.values[i]),
          ),
          const SizedBox(height: AppSpacing.xs),
          Expanded(
            child: ZRefresh(
              onRefresh: () async => ref.refresh(conversationsProvider.future),
              // Rows stagger in on first load; a poll or a refresh does not
              // replay them.
              child: RevealScope(
                child: AsyncView(
                  value: conversations,
                  onRetry: () => ref.invalidate(conversationsProvider),
                  loading: const ConversationSkeleton(),
                  builder: (all) {
                    final items = all.where(_filter.matches).toList();

                    if (items.isEmpty) {
                      return _EmptyInbox(
                        searching: query.isNotEmpty,
                        filter: _filter,
                        isBrand: isBrand,
                        onShowAll: () =>
                            setState(() => _filter = _InboxFilter.all),
                      );
                    }

                    return ListView.separated(
                      padding: EdgeInsets.fromLTRB(
                        margin,
                        AppSpacing.xs,
                        margin,
                        AppSpacing.xl,
                      ),
                      itemCount: items.length,
                      separatorBuilder: (_, _) =>
                          const SizedBox(height: AppSpacing.xs),
                      itemBuilder: (_, i) => Reveal(
                        index: i,
                        revealKey: items[i].id,
                        child: ConversationCard(conversation: items[i]),
                      ),
                    );
                  },
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Says why the list is empty, and offers the one thing that would fill it.
class _EmptyInbox extends StatelessWidget {
  const _EmptyInbox({
    required this.searching,
    required this.filter,
    required this.isBrand,
    required this.onShowAll,
  });

  final bool searching;
  final _InboxFilter filter;
  final bool isBrand;
  final VoidCallback onShowAll;

  @override
  Widget build(BuildContext context) {
    // Inside a scrollable so pull-to-refresh still works on an empty inbox.
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight),
          child: Center(child: _state(context)),
        ),
      ),
    );
  }

  Widget _state(BuildContext context) {
    if (searching) {
      return const EmptyState(
        title: 'No matches',
        message: 'Try a different name or keyword.',
        icon: Icons.search_off_rounded,
      );
    }

    return switch (filter) {
      _InboxFilter.unread => EmptyState(
        title: 'You are all caught up',
        message: 'Every conversation has been read.',
        icon: Icons.mark_chat_read_outlined,
        actionLabel: 'Show all messages',
        onAction: onShowAll,
      ),
      _InboxFilter.bookings => EmptyState(
        title: 'No booking chats yet',
        message:
            'Each booking opens its own thread here, so campaign '
            'details stay in one place.',
        icon: Icons.work_outline_rounded,
        actionLabel: 'Show all messages',
        onAction: onShowAll,
      ),
      _InboxFilter.all => EmptyState(
        title: 'No conversations yet',
        message: isBrand
            ? 'Message a creator from their profile to start a '
                  'conversation.'
            : 'Brands that contact you, and every booking you take on, '
                  'will show up here.',
        icon: Icons.forum_outlined,
        actionLabel: isBrand ? 'Explore creators' : 'Find campaigns',
        onAction: () =>
            context.push(isBrand ? Routes.explore : Routes.creatorCampaigns),
      ),
    };
  }
}
