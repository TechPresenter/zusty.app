import 'package:flutter/material.dart';

import '../core/theme/app_colors.dart';
import '../core/utils/action_link.dart';
import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// The event families the notification service emits
/// (see `Zuste_Workflow.md` § 11).
enum NotificationKind {
  booking,
  payment,
  message,
  campaign,
  system,
  review,
  kyc,
  promo;

  /// The kind for a row, preferring the server's own `category` and falling
  /// back to guessing from the `type` string for payloads that lack one.
  static NotificationKind resolve({String? category, String? type}) =>
      switch (category?.toLowerCase()) {
        // Campaign events are filed under booking, but still read better
        // with the campaign glyph.
        'booking' =>
          fromString(type) == NotificationKind.campaign
              ? NotificationKind.campaign
              : NotificationKind.booking,
        'payment' || 'wallet' => NotificationKind.payment,
        'message' => NotificationKind.message,
        'review' => NotificationKind.review,
        'promo' => NotificationKind.promo,
        // Account events are verification, sign-in and profile changes.
        'account' => NotificationKind.kyc,
        'system' => NotificationKind.system,
        _ => fromString(type),
      };

  static NotificationKind fromString(String? value) {
    final text = (value ?? '').toLowerCase();
    if (text.contains('booking') || text.contains('campaign_request')) {
      return NotificationKind.booking;
    }
    if (text.contains('payment') ||
        text.contains('payout') ||
        text.contains('escrow') ||
        text.contains('wallet') ||
        text.contains('withdraw')) {
      return NotificationKind.payment;
    }
    if (text.contains('message') || text.contains('chat')) {
      return NotificationKind.message;
    }
    if (text.contains('review') || text.contains('rating')) {
      return NotificationKind.review;
    }
    if (text.contains('kyc') || text.contains('verification')) {
      return NotificationKind.kyc;
    }
    if (text.contains('campaign')) return NotificationKind.campaign;
    if (text.contains('promo') || text.contains('offer')) {
      return NotificationKind.promo;
    }
    return NotificationKind.system;
  }

  /// How the kind reads on a chip or a heading.
  String get label => switch (this) {
    NotificationKind.booking => 'Booking',
    NotificationKind.payment => 'Payment',
    NotificationKind.message => 'Message',
    NotificationKind.campaign => 'Campaign',
    NotificationKind.review => 'Review',
    NotificationKind.kyc => 'Account',
    NotificationKind.promo => 'Offer',
    NotificationKind.system => 'Update',
  };

  IconData get icon => switch (this) {
    NotificationKind.booking => Icons.calendar_month_rounded,
    NotificationKind.payment => Icons.payments_rounded,
    NotificationKind.message => Icons.chat_bubble_rounded,
    NotificationKind.campaign => Icons.campaign_rounded,
    NotificationKind.review => Icons.star_rounded,
    NotificationKind.kyc => Icons.verified_user_rounded,
    NotificationKind.promo => Icons.local_offer_rounded,
    NotificationKind.system => Icons.notifications_rounded,
  };

  Color get color => switch (this) {
    NotificationKind.booking => AppColors.secondary,
    NotificationKind.payment => AppColors.tertiary,
    NotificationKind.message => AppColors.primary,
    NotificationKind.campaign => AppColors.secondary,
    NotificationKind.review => AppColors.star,
    NotificationKind.kyc => AppColors.tertiary,
    NotificationKind.promo => AppColors.primary,
    NotificationKind.system => AppColors.onSurfaceVariant,
  };

  Color get containerColor => switch (this) {
    NotificationKind.booking ||
    NotificationKind.campaign => AppColors.secondaryFixed,
    NotificationKind.payment ||
    NotificationKind.kyc => AppColors.successContainer,
    NotificationKind.review => AppColors.warningContainer,
    _ => AppColors.primaryFixed,
  };
}

/// A row from `GET /notifications`.
class AppNotification {
  const AppNotification({
    required this.id,
    required this.title,
    required this.kind,
    this.body = '',
    this.type = '',
    this.category,
    this.imageUrl,
    this.isRead = false,
    this.actionUrl,
    this.referenceType,
    this.referenceId,
    this.createdAt,
  });

  final int id;
  final String title;
  final NotificationKind kind;
  final String body;

  /// Raw backend type string, e.g. `booking_accepted`.
  final String type;

  /// The server's filing bucket: `booking`, `payment`, `message`, `review`,
  /// `system`, `promo`, `account` or `wallet`. Campaign events are filed
  /// under `booking`. Null on payloads that predate the column.
  final String? category;
  final String? imageUrl;
  final bool isRead;

  /// Deep link the backend suggests, e.g. `/bookings/42`.
  final String? actionUrl;
  final String? referenceType;
  final int? referenceId;
  final DateTime? createdAt;

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    final type = Json.str(json['type']);
    final category = Json.strOrNull(json['category']);
    return AppNotification(
      id: Json.integer(json['id']),
      title: Json.str(json['title'], 'Notification'),
      kind: NotificationKind.resolve(category: category, type: type),
      body: Json.str(json['body'] ?? json['message']),
      type: type,
      category: category,
      imageUrl: Json.strOrNull(json['image_url'] ?? json['icon_url']),
      isRead: Json.boolean(json['is_read'] ?? json['read_at'] != null),
      actionUrl:
          Json.strOrNull(json['action_url'] ?? json['deep_link']) ??
          actionLinkFor(json['action_type'], json['action_value']),
      referenceType: Json.strOrNull(json['reference_type']),
      referenceId: Json.intOrNull(json['reference_id']),
      createdAt: Formatters.parseDate(json['created_at']),
    );
  }

  String get timeLabel => Formatters.relative(createdAt);

  /// True when [actionUrl] points outside the app (an http(s) link).
  bool get opensExternally => isExternalLink(actionUrl);

  AppNotification copyWith({bool? isRead}) => AppNotification(
    id: id,
    title: title,
    kind: kind,
    body: body,
    type: type,
    category: category,
    imageUrl: imageUrl,
    isRead: isRead ?? this.isRead,
    actionUrl: actionUrl,
    referenceType: referenceType,
    referenceId: referenceId,
    createdAt: createdAt,
  );
}
