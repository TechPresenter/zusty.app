import 'package:flutter/material.dart';

import '../core/utils/json.dart';

/// One entry in an admin-built navigation menu, from `/app/config` → `menus`.
///
/// The admin panel has always had a full menu builder — reorder, nest, target
/// an audience, add a badge, pick one of five link types — and nothing in the
/// app read a single row of it. This is the model that closes that gap: an
/// operator can add a drawer entry, point it at a screen, a CMS page, a
/// category or an external URL, and it appears in installed apps on the next
/// resume with no release.
class MenuEntry {
  const MenuEntry({
    required this.id,
    required this.label,
    this.icon,
    this.linkType = 'none',
    this.linkValue,
    this.badgeText,
    this.badgeColor,
    this.openExternal = false,
    this.children = const [],
  });

  final int id;
  final String label;

  /// A Material icon name such as `account_balance_wallet`.
  ///
  /// Resolved through [iconData] rather than looked up dynamically: Flutter
  /// tree-shakes unreferenced icons out of release builds, so constructing an
  /// IconData from a codepoint the server sent renders an empty box.
  final String? icon;

  /// `screen`, `page`, `category`, `url` or `none`.
  final String linkType;
  final String? linkValue;
  final String? badgeText;

  /// A `#RRGGBB` string from the admin, or null for the theme default.
  final String? badgeColor;
  final bool openExternal;
  final List<MenuEntry> children;

  /// A label-only entry, used as a section heading.
  bool get isLabelOnly => linkType == 'none' || (linkValue ?? '').isEmpty;

  bool get hasChildren => children.isNotEmpty;

  Color? get badgeTint {
    final hex = badgeColor?.replaceFirst('#', '');
    if (hex == null || hex.length != 6) return null;

    final value = int.tryParse(hex, radix: 16);
    return value == null ? null : Color(0xFF000000 | value);
  }

  factory MenuEntry.fromJson(Map<String, dynamic> json) => MenuEntry(
        id: Json.integer(json['id']),
        label: Json.str(json['label']),
        icon: Json.strOrNull(json['icon']),
        linkType: Json.str(json['link_type'], 'none'),
        linkValue: Json.strOrNull(json['link_value']),
        badgeText: Json.strOrNull(json['badge_text']),
        badgeColor: Json.strOrNull(json['badge_color']),
        openExternal: Json.boolean(json['open_external']),
        children: Json.list(json['children'], MenuEntry.fromJson),
      );

  /// Maps the admin's icon name onto a bundled Material glyph.
  ///
  /// Every name the admin panel offers in its icon picker is here. An unknown
  /// name falls back to a neutral chevron rather than throwing, so an operator
  /// typing a name this build does not know gets a plain-looking row instead
  /// of a crash.
  IconData get iconData => switch (icon) {
        'home' => Icons.home_rounded,
        'search' => Icons.search_rounded,
        'explore' => Icons.explore_rounded,
        'calendar_today' => Icons.calendar_today_rounded,
        'event' => Icons.event_rounded,
        'account_balance_wallet' => Icons.account_balance_wallet_rounded,
        'account_balance' => Icons.account_balance_rounded,
        'payments' => Icons.payments_rounded,
        'receipt' => Icons.receipt_long_rounded,
        'notifications' => Icons.notifications_rounded,
        'bookmark' => Icons.bookmark_rounded,
        'favorite' => Icons.favorite_rounded,
        'person' => Icons.person_rounded,
        'person_add' => Icons.person_add_rounded,
        'group' => Icons.group_rounded,
        'campaign' => Icons.campaign_rounded,
        'work' => Icons.work_rounded,
        'store' => Icons.storefront_rounded,
        'dashboard' => Icons.space_dashboard_rounded,
        'analytics' => Icons.insights_rounded,
        'star' => Icons.star_rounded,
        'card_giftcard' => Icons.card_giftcard_rounded,
        'local_offer' => Icons.local_offer_rounded,
        'verified' => Icons.verified_rounded,
        'shield' => Icons.shield_rounded,
        'help' => Icons.help_rounded,
        'support_agent' => Icons.support_agent_rounded,
        'chat' => Icons.chat_bubble_rounded,
        'mail' => Icons.mail_rounded,
        'settings' => Icons.settings_rounded,
        'info' => Icons.info_rounded,
        'article' => Icons.article_rounded,
        'description' => Icons.description_rounded,
        'policy' => Icons.policy_rounded,
        'share' => Icons.share_rounded,
        'link' => Icons.link_rounded,
        'logout' => Icons.logout_rounded,
        _ => Icons.chevron_right_rounded,
      };
}
