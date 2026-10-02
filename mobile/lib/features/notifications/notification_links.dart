import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/utils/action_link.dart';
import '../../models/notification.dart';
import '../../router/routes.dart';
import '../../shared/widgets/states.dart';

/// Where a notification's link leads in this build, or null when it has
/// none worth following.
///
/// An in-app path is checked against the routes this build registers first
/// (`resolveMenuTarget`), then the older prefix list (`resolveDeepLink`). The
/// latter falls back to the notifications list for anything it does not know,
/// and following a notification back to the list it was tapped on goes
/// nowhere — so that answer counts as "no link".
String? notificationTarget(AppNotification notification) {
  final link = notification.actionUrl;
  if (link == null || link.isEmpty) return null;
  if (isExternalLink(link)) return link;

  final target = Routes.resolveMenuTarget(link) ?? Routes.resolveDeepLink(link);
  return target == Routes.notifications ? null : target;
}

/// Follows a notification's link: an http(s) URL opens in the browser, an
/// in-app path is pushed. Returns false when there was nothing to open, so
/// the caller can show the notification's details instead.
Future<bool> openNotificationTarget(
  BuildContext context,
  AppNotification notification,
) async {
  final target = notificationTarget(notification);
  if (target == null) return false;

  if (isExternalLink(target)) {
    final uri = Uri.tryParse(target);
    final opened =
        uri != null &&
        await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!opened && context.mounted) {
      showZSnack(context, 'That link could not be opened.', isError: true);
    }
    return true;
  }

  if (context.mounted) context.push(target);
  return true;
}

/// The button text for a notification's link, from what it is about.
String notificationActionLabel(AppNotification notification) {
  if (notification.opensExternally) return 'Open link';

  final target = notificationTarget(notification) ?? '';
  if (target.startsWith(Routes.messages)) return 'Open conversation';
  if (target.startsWith(Routes.bookings)) return 'View booking';
  if (target.startsWith(Routes.wallet)) return 'Open wallet';
  if (target.startsWith(Routes.kyc)) return 'View verification';
  if (target.startsWith(Routes.campaigns)) return 'View campaign';
  if (target.startsWith(Routes.creatorCampaigns)) return 'View opportunity';
  if (target.startsWith(Routes.applications)) return 'View application';
  if (target.startsWith(Routes.support)) return 'Open support';
  if (target.startsWith(Routes.creatorBase)) return 'View profile';

  return switch (notification.kind) {
    NotificationKind.booking => 'View booking',
    NotificationKind.payment => 'Open wallet',
    NotificationKind.message => 'Open conversation',
    NotificationKind.review => 'View reviews',
    NotificationKind.kyc => 'View profile',
    _ => 'Open',
  };
}
