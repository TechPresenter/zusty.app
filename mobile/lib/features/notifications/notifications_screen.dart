import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/notification.dart';
import '../../providers/account_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import 'notification_links.dart';

/// Screen 17 — Notifications.
///
/// Filtered by the server's own categories, grouped by day, swipe to delete
/// (with a moment to undo), and a tap goes straight to whatever the
/// notification is about. Long-press opens the full text.
class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key});

  @override
  ConsumerState<NotificationsScreen> createState() =>
      _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  /// Swiped away and waiting out the undo window. Hidden at once — a
  /// dismissed row must leave the tree in the same frame — and deleted on the
  /// server only when the window closes.
  final Map<int, Timer> _pendingDeletes = {};

  late final NotificationActionsController _actions;

  static const _undoWindow = Duration(seconds: 4);

  @override
  void initState() {
    super.initState();
    // Held rather than read on demand: [dispose] flushes pending deletes,
    // and a widget's ref is off-limits by then.
    _actions = ref.read(notificationActionsProvider);
  }

  @override
  void dispose() {
    // Leaving the screen closes every undo window early: what was swiped
    // away is deleted, not forgotten.
    // (A timer that has already fired has its delete in flight.)
    for (final entry in _pendingDeletes.entries) {
      if (!entry.value.isActive) continue;
      entry.value.cancel();
      _actions.delete(entry.key).ignore();
    }
    super.dispose();
  }

  void _dismiss(AppNotification notification) {
    final id = notification.id;
    setState(() {
      _pendingDeletes[id] = Timer(_undoWindow, () async {
        try {
          // Deleted: the id stays in the map (its timer spent) so the row
          // cannot flicker back while the list reloads around it.
          await _actions.delete(id);
        } catch (error) {
          // Not deleted after all: put it back and say why.
          _pendingDeletes.remove(id);
          if (!mounted) return;
          setState(() {});
          showZSnack(
            context,
            error is ApiFailure ? error.message : 'Could not delete that.',
            isError: true,
          );
        }
      });
    });

    showZSnack(
      context,
      'Notification deleted',
      actionLabel: 'Undo',
      onAction: () {
        _pendingDeletes.remove(id)?.cancel();
        if (mounted) setState(() {});
      },
    );
  }

  Future<void> _markAllRead() async {
    try {
      await _actions.markAllRead();
      if (mounted) showZSnack(context, 'You are all caught up.');
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    }
  }

  Future<void> _open(AppNotification notification) async {
    ref.read(openedNotificationProvider.notifier).state = notification;

    if (!notification.isRead) {
      // A failed read receipt only means the dot stays until next time.
      _actions.markRead(notification.id).ignore();
    }

    final followed = await openNotificationTarget(context, notification);
    if (!followed && mounted) {
      context.push(Routes.notification(notification.id));
    }
  }

  void _details(AppNotification notification) {
    ref.read(openedNotificationProvider.notifier).state = notification;
    context.push(Routes.notification(notification.id));
  }

  @override
  Widget build(BuildContext context) {
    final filter = ref.watch(notificationFilterProvider);
    final notifications = ref.watch(filteredNotificationsProvider(filter));
    final unread = ref.watch(unreadNotificationsProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        title: 'Notifications',
        actions: [
          if (unread > 0)
            TextButton(
              onPressed: _markAllRead,
              style: TextButton.styleFrom(
                minimumSize: const Size(0, AppSpacing.minTouch),
              ),
              child: Text(
                'Mark all read',
                style: AppTypography.labelLarge.copyWith(
                  color: AppColors.primary,
                ),
              ),
            ),
          IconButton(
            icon: const Icon(Icons.tune_rounded, size: 22),
            tooltip: 'Notification settings',
            onPressed: () => context.push(Routes.settings),
          ),
        ],
      ),
      body: Column(
        children: [
          const SizedBox(height: AppSpacing.xs),
          ZFilterPills(
            padding: EdgeInsets.symmetric(horizontal: margin),
            labels: [
              for (final f in NotificationFilter.values)
                f == NotificationFilter.all && unread > 0
                    ? '${f.label} · $unread'
                    : f.label,
            ],
            selected: filter.index,
            onSelected: (i) =>
                ref.read(notificationFilterProvider.notifier).state =
                    NotificationFilter.values[i],
          ),
          const SizedBox(height: AppSpacing.xs),
          Expanded(
            child: ZRefresh(
              onRefresh: () {
                ref.invalidate(notificationsProvider);
                return ref.refresh(
                  filteredNotificationsProvider(filter).future,
                );
              },
              child: RevealScope(
                child: AsyncView(
                  value: notifications,
                  onRetry: () =>
                      ref.invalidate(filteredNotificationsProvider(filter)),
                  loading: Padding(
                    padding: EdgeInsets.symmetric(horizontal: margin),
                    child: const ListSkeleton(count: 6, height: 92),
                  ),
                  builder: (all) {
                    final items = all
                        .where((n) => !_pendingDeletes.containsKey(n.id))
                        .toList();

                    if (items.isEmpty) {
                      return _EmptyNotifications(
                        filter: filter,
                        onShowAll: () =>
                            ref
                                    .read(notificationFilterProvider.notifier)
                                    .state =
                                NotificationFilter.all,
                      );
                    }

                    final rows = _rows(items);

                    return ListView.builder(
                      padding: EdgeInsets.fromLTRB(
                        margin,
                        0,
                        margin,
                        AppSpacing.xl,
                      ),
                      itemCount: rows.length,
                      itemBuilder: (_, i) {
                        final row = rows[i];
                        if (row is String) return _DayHeader(label: row);

                        final notification = row as AppNotification;
                        return Reveal(
                          index: i,
                          revealKey: notification.id,
                          child: Padding(
                            padding: const EdgeInsets.only(
                              bottom: AppSpacing.xs,
                            ),
                            child: NotificationCard(
                              notification: notification,
                              onTap: () => _open(notification),
                              onLongPress: () => _details(notification),
                              onDismissed: () => _dismiss(notification),
                            ),
                          ),
                        );
                      },
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

  /// Interleaves Today / Yesterday / date headings with the rows under them.
  static List<Object> _rows(List<AppNotification> items) {
    final rows = <Object>[];
    String? current;
    for (final item in items) {
      final key = item.createdAt == null
          ? 'Earlier'
          : Formatters.dayLabel(item.createdAt!);
      if (key != current) {
        rows.add(key);
        current = key;
      }
      rows.add(item);
    }
    return rows;
  }
}

class _DayHeader extends StatelessWidget {
  const _DayHeader({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.base,
        AppSpacing.md,
        AppSpacing.base,
        AppSpacing.xs,
      ),
      child: Semantics(
        header: true,
        child: Text(
          label,
          style: AppTypography.labelMedium.copyWith(
            color: AppColors.onSurfaceVariant,
          ),
        ),
      ),
    );
  }
}

/// Why this view is empty, with a way back to everything.
class _EmptyNotifications extends StatelessWidget {
  const _EmptyNotifications({required this.filter, required this.onShowAll});

  final NotificationFilter filter;
  final VoidCallback onShowAll;

  @override
  Widget build(BuildContext context) {
    final (title, message, icon) = switch (filter) {
      NotificationFilter.all => (
        'You are all caught up',
        'Booking updates, payments, and messages will appear here.',
        Icons.notifications_none_rounded,
      ),
      NotificationFilter.bookings => (
        'No booking updates',
        'Requests, acceptances, deliveries and campaign news land here.',
        Icons.calendar_month_outlined,
      ),
      NotificationFilter.payments => (
        'No payment updates',
        'Payments, refunds, payouts and wallet activity land here.',
        Icons.payments_outlined,
      ),
      NotificationFilter.messages => (
        'No message alerts',
        'When someone writes to you, you will see it here too.',
        Icons.chat_bubble_outline_rounded,
      ),
      NotificationFilter.reviews => (
        'No reviews yet',
        'Reviews left after a completed booking appear here.',
        Icons.star_outline_rounded,
      ),
      NotificationFilter.account => (
        'No account notices',
        'Verification, sign-in and security notices appear here.',
        Icons.verified_user_outlined,
      ),
      NotificationFilter.updates => (
        'No updates from Zusty',
        'Product news and offers appear here.',
        Icons.campaign_outlined,
      ),
    };

    // Inside a scrollable so pull-to-refresh still works when it is empty.
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight),
          child: Center(
            child: EmptyState(
              title: title,
              message: message,
              icon: icon,
              actionLabel: filter == NotificationFilter.all ? null : 'Show all',
              onAction: filter == NotificationFilter.all ? null : onShowAll,
            ),
          ),
        ),
      ),
    );
  }
}

/// A notification row.
///
/// Unread rows are lifted — white, shadowed, a magenta dot and a bold title;
/// read ones sit flat against the page, so the eye goes to what is new. When
/// the notification leads somewhere, the row says where.
class NotificationCard extends ConsumerWidget {
  const NotificationCard({
    super.key,
    required this.notification,
    this.onTap,
    this.onLongPress,
    this.onDismissed,
  });

  final AppNotification notification;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;

  /// Swipe-to-delete. Without it the row cannot be swiped.
  final VoidCallback? onDismissed;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final n = notification;
    final unread = !n.isRead;
    final hasLink = notificationTarget(n) != null;

    final card = GestureDetector(
      onLongPress: onLongPress,
      child: ZCard(
        padding: const EdgeInsets.all(AppSpacing.sm),
        color: unread ? null : AppColors.surface,
        border: Border.all(
          color: unread
              ? AppColors.primary.withValues(alpha: 0.22)
              : AppColors.outlineVariant,
        ),
        shadow: unread,
        onTap:
            onTap ??
            () {
              ref.read(openedNotificationProvider.notifier).state = n;
              context.push(Routes.notification(n.id));
            },
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _KindTile(notification: n, size: 44),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          n.title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.titleSmall.copyWith(
                            fontWeight: unread
                                ? FontWeight.w700
                                : FontWeight.w600,
                            color: unread
                                ? AppColors.onSurface
                                : AppColors.onSurface.withValues(alpha: 0.85),
                          ),
                        ),
                      ),
                      const SizedBox(width: AppSpacing.xs),
                      Text(
                        n.timeLabel,
                        style: AppTypography.labelSmall.copyWith(
                          color: unread
                              ? AppColors.primary
                              : AppColors.onSurfaceVariant,
                          fontWeight: unread
                              ? FontWeight.w600
                              : FontWeight.w400,
                        ),
                      ),
                      if (unread)
                        Container(
                          width: 8,
                          height: 8,
                          margin: const EdgeInsets.only(left: 6, top: 4),
                          decoration: BoxDecoration(
                            color: AppColors.primary,
                            shape: BoxShape.circle,
                          ),
                        ),
                    ],
                  ),
                  if (n.body.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      n.body,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodyMedium.copyWith(
                        color: AppColors.onSurfaceVariant,
                        height: 1.4,
                      ),
                    ),
                  ],
                  if (hasLink) ...[
                    const SizedBox(height: AppSpacing.xs),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Flexible(
                          child: Text(
                            notificationActionLabel(n),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.labelMedium.copyWith(
                              color: AppColors.primary,
                            ),
                          ),
                        ),
                        const SizedBox(width: 2),
                        Icon(
                          n.opensExternally
                              ? Icons.open_in_new_rounded
                              : Icons.arrow_forward_rounded,
                          size: 14,
                          color: AppColors.primary,
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );

    if (onDismissed == null) return card;

    return Dismissible(
      key: ValueKey('notification-${n.id}'),
      direction: DismissDirection.endToStart,
      onDismissed: (_) => onDismissed!(),
      background: Container(
        alignment: Alignment.centerRight,
        padding: const EdgeInsets.only(right: AppSpacing.lg),
        decoration: BoxDecoration(
          color: AppColors.errorContainer,
          borderRadius: AppRadius.xlAll,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Delete',
              style: AppTypography.labelLarge.copyWith(
                color: AppColors.onErrorContainer,
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Icon(
              Icons.delete_outline_rounded,
              color: AppColors.onErrorContainer,
            ),
          ],
        ),
      ),
      child: card,
    );
  }
}

/// The notification's picture when it has one, otherwise its kind's glyph on
/// a tinted tile.
class _KindTile extends StatelessWidget {
  const _KindTile({required this.notification, required this.size});

  final AppNotification notification;
  final double size;

  @override
  Widget build(BuildContext context) {
    final kind = notification.kind;
    final image = notification.imageUrl;

    if (image != null && image.isNotEmpty) {
      return ZImage(
        url: image,
        width: size,
        height: size,
        borderRadius: AppRadius.mdAll,
        fallbackIcon: kind.icon,
      );
    }

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: notification.isRead
            ? AppColors.surfaceContainerHigh
            : kind.containerColor,
        borderRadius: AppRadius.mdAll,
      ),
      child: Icon(
        kind.icon,
        size: size * 0.46,
        color: notification.isRead ? AppColors.onSurfaceVariant : kind.color,
      ),
    );
  }
}

/// Screen 24 — Notification Details.
///
/// The full text of one notification, with its link as a pinned button.
class NotificationDetailsScreen extends ConsumerStatefulWidget {
  const NotificationDetailsScreen({super.key, required this.notificationId});

  final int notificationId;

  @override
  ConsumerState<NotificationDetailsScreen> createState() =>
      _NotificationDetailsScreenState();
}

class _NotificationDetailsScreenState
    extends ConsumerState<NotificationDetailsScreen> {
  bool _markedRead = false;
  bool _deleting = false;

  /// Opened straight from a push, the row is still unread; reading it here
  /// is reading it.
  void _markReadOnce(AppNotification notification) {
    if (_markedRead || notification.isRead) return;
    _markedRead = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(notificationActionsProvider).markRead(notification.id).ignore();
    });
  }

  Future<void> _delete(AppNotification notification) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Delete this notification?',
      message: 'It will be removed from your notifications on every device.',
      confirmLabel: 'Delete',
      cancelLabel: 'Keep it',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!confirmed || !mounted) return;

    setState(() => _deleting = true);
    try {
      await ref.read(notificationActionsProvider).delete(notification.id);
      if (!mounted) return;
      showZSnack(context, 'Notification deleted');
      context.pop();
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _deleting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final notifications = ref.watch(notificationsProvider);
    final opened = ref.watch(openedNotificationProvider);
    final margin = AppBreakpoints.pageMargin(context);

    final fromList = notifications.valueOrNull?.items
        .where((n) => n.id == widget.notificationId)
        .firstOrNull;
    final remembered = opened?.id == widget.notificationId ? opened : null;
    final notification = fromList ?? remembered;

    if (notification == null) {
      return ZScaffold(
        padded: false,
        constrain: false,
        appBar: const ZAppBar(title: 'Notification'),
        body: notifications.isLoading
            ? Padding(
                padding: EdgeInsets.all(margin),
                child: const ListSkeleton(count: 2, height: 140),
              )
            : notifications.hasError
            ? ErrorState(
                error: notifications.error!,
                onRetry: () => ref.invalidate(notificationsProvider),
              )
            : EmptyState(
                title: 'Notification not found',
                message: 'It may have been deleted.',
                icon: Icons.notifications_off_rounded,
                actionLabel: 'Back to notifications',
                onAction: () => context.canPop()
                    ? context.pop()
                    : context.go(Routes.notifications),
              ),
      );
    }

    _markReadOnce(notification);

    final kind = notification.kind;
    final hasLink = notificationTarget(notification) != null;

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        title: 'Notification',
        actions: [
          IconButton(
            icon: const Icon(Icons.delete_outline_rounded, size: 22),
            tooltip: 'Delete notification',
            onPressed: _deleting ? null : () => _delete(notification),
          ),
        ],
      ),
      bottomBar: hasLink
          ? ZButton(
              label: notificationActionLabel(notification),
              trailingIcon: notification.opensExternally
                  ? Icons.open_in_new_rounded
                  : Icons.arrow_forward_rounded,
              onPressed: () => openNotificationTarget(context, notification),
            )
          : null,
      body: ListView(
        padding: EdgeInsets.fromLTRB(
          margin,
          AppSpacing.md,
          margin,
          AppSpacing.xl,
        ),
        children: [
          ZCard(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _KindTile(
                      notification: notification.copyWith(isRead: false),
                      size: 56,
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          StatusChip(
                            label: kind.label,
                            tone: StatusTone.neutral,
                            icon: kind.icon,
                            dense: true,
                          ),
                          const SizedBox(height: 6),
                          Text(
                            notification.createdAt == null
                                ? notification.timeLabel
                                : '${Formatters.dateTime(notification.createdAt)}'
                                      ' · ${notification.timeLabel}',
                            style: AppTypography.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                Text(notification.title, style: AppTypography.headlineSmall),
                if (notification.body.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    notification.body,
                    style: AppTypography.bodyLarge.copyWith(
                      color: AppColors.onSurfaceVariant,
                      height: 1.55,
                    ),
                  ),
                ],
              ],
            ),
          ),
          if (!hasLink) ...[
            const SizedBox(height: AppSpacing.md),
            Text(
              'Nothing to open for this one. It is here for your records.',
              textAlign: TextAlign.center,
              style: AppTypography.bodySmall,
            ),
          ],
        ],
      ),
    );
  }
}
