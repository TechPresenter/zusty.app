import 'package:intl/intl.dart';

import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// A chat thread from `GET /conversations`.
class Conversation {
  const Conversation({
    required this.id,
    required this.otherName,
    this.otherAvatar,
    this.otherUserId,
    this.otherHandle,
    this.otherHeadline,
    this.lastMessage = '',
    this.lastMessageAt,
    this.timeAgo = '',
    this.unreadCount = 0,
    this.isOnline = false,
    this.isMuted = false,
    this.isLocked = false,
    this.bookingId,
    this.bookingCode,
    this.bookingTitle,
    this.bookingStatus,
    this.isVerified = false,
    this.canMessage = true,
    this.blockedByMe = false,
  });

  final int id;
  final String otherName;
  final String? otherAvatar;
  final int? otherUserId;

  /// The other party's public creator handle. Null when they are a brand,
  /// which has no public profile to open.
  final String? otherHandle;

  /// The creator's one-line headline, shown under the name in the chat
  /// header while they are offline.
  final String? otherHeadline;
  final String lastMessage;
  final DateTime? lastMessageAt;

  /// The server's own "3h ago", used when a thread has no message yet and so
  /// no [lastMessageAt] to format.
  final String timeAgo;
  final int unreadCount;
  final bool isOnline;
  final bool isMuted;

  /// Closed by the platform (a finished or disputed booking): readable, but
  /// the server refuses new messages.
  final bool isLocked;

  /// Set when the thread is scoped to a booking rather than a general inquiry.
  final int? bookingId;
  final String? bookingCode;
  final String? bookingTitle;

  /// Raw booking status (`countered`, `in_progress`…) for the context chip.
  final String? bookingStatus;
  final bool isVerified;

  /// False when the other person has blocked the user: the thread stays
  /// readable, but the server refuses new messages. A thread with someone
  /// the user blocked does not reach the inbox at all.
  final bool canMessage;

  /// The user blocked the other person. Only the thread itself says so
  /// (`GET /conversations/{id}/messages`): the inbox leaves such a thread out.
  final bool blockedByMe;

  factory Conversation.fromJson(Map<String, dynamic> json) {
    final other = Json.map(json['other_user'] ?? json['participant']);
    return Conversation(
      id: Json.integer(json['id']),
      otherName: Json.str(
        other['name'] ?? json['other_name'] ?? json['title'],
        'Conversation',
      ),
      otherAvatar: Json.strOrNull(other['avatar_url'] ?? json['other_avatar']),
      // The inbox names it `user_id`; `id` is what older payloads used.
      otherUserId: Json.intOrNull(
        other['user_id'] ?? other['id'] ?? json['other_user_id'],
      ),
      otherHandle: Json.strOrNull(other['handle'] ?? json['other_handle']),
      otherHeadline: Json.strOrNull(other['headline']),
      lastMessage: Json.str(json['last_message_text'] ?? json['last_message']),
      lastMessageAt: Formatters.parseDate(json['last_message_at']),
      timeAgo: Json.str(json['time_ago']),
      unreadCount: Json.integer(json['unread_count']),
      isOnline: Json.boolean(other['is_online'] ?? json['is_online']),
      isMuted: Json.boolean(json['is_muted']),
      isLocked: Json.boolean(json['is_locked']),
      bookingId: Json.intOrNull(json['booking_id']),
      bookingCode: Json.strOrNull(json['booking_code']),
      bookingTitle: Json.strOrNull(json['booking_title']),
      bookingStatus: Json.strOrNull(json['booking_status']),
      isVerified: Json.boolean(other['is_verified'] ?? json['is_verified']),
      // Absent from servers that predate blocking, which never refuse.
      canMessage: json['can_message'] != false,
      blockedByMe: Json.boolean(other['is_blocked']),
    );
  }

  bool get hasUnread => unreadCount > 0;

  /// Scoped to a booking, so the thread can offer "View booking".
  bool get isBookingThread => bookingId != null;

  /// What the booking chip reads: the title when the server sends one,
  /// otherwise the booking code.
  String? get bookingLabel => bookingTitle ?? bookingCode;

  String get initials => Formatters.initials(otherName);

  /// `4:30 PM` today, `Yesterday`, a weekday within the week, then `Aug 1`.
  String get timeLabel {
    final at = lastMessageAt;
    if (at == null) return timeAgo;

    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final days = today.difference(DateTime(at.year, at.month, at.day)).inDays;

    if (days <= 0) return Formatters.time(at);
    if (days == 1) return 'Yesterday';
    if (days < 7) return DateFormat('EEE').format(at);
    return Formatters.shortDate(at);
  }

  Conversation copyWith({int? unreadCount, bool? isMuted}) => Conversation(
    id: id,
    otherName: otherName,
    otherAvatar: otherAvatar,
    otherUserId: otherUserId,
    otherHandle: otherHandle,
    otherHeadline: otherHeadline,
    lastMessage: lastMessage,
    lastMessageAt: lastMessageAt,
    timeAgo: timeAgo,
    unreadCount: unreadCount ?? this.unreadCount,
    isOnline: isOnline,
    isMuted: isMuted ?? this.isMuted,
    isLocked: isLocked,
    bookingId: bookingId,
    bookingCode: bookingCode,
    bookingTitle: bookingTitle,
    bookingStatus: bookingStatus,
    isVerified: isVerified,
    canMessage: canMessage,
    blockedByMe: blockedByMe,
  );
}

/// What kind of payload a message carries.
enum MessageType {
  text,
  image,
  file,
  voice,
  system;

  static MessageType fromString(String? value) =>
      switch (value?.toLowerCase()) {
        'image' => MessageType.image,
        'file' || 'pdf' || 'document' => MessageType.file,
        'voice' || 'audio' => MessageType.voice,
        'system' => MessageType.system,
        _ => MessageType.text,
      };
}

/// A single chat message from `GET /conversations/{id}/messages`.
class Message {
  const Message({
    required this.id,
    required this.type,
    this.uuid,
    this.senderId,
    this.senderName = '',
    this.senderAvatar,
    this.body = '',
    this.mediaUrl,
    this.thumbnailUrl,
    this.mediaWidth,
    this.mediaHeight,
    this.fileName,
    this.fileSize,
    this.durationSecs = 0,
    this.isMine = false,
    this.isRead = false,
    this.createdAt,
    this.isSending = false,
    this.failed = false,
  });

  final int id;
  final MessageType type;
  final String? uuid;
  final int? senderId;
  final String senderName;
  final String? senderAvatar;
  final String body;
  final String? mediaUrl;

  /// A smaller rendition of an image, when the server made one.
  final String? thumbnailUrl;

  /// Pixel size of an uploaded image, so the bubble can hold its shape
  /// before the picture arrives instead of jumping when it does.
  final int? mediaWidth;
  final int? mediaHeight;
  final String? fileName;
  final int? fileSize;
  final int durationSecs;
  final bool isMine;
  final bool isRead;
  final DateTime? createdAt;

  /// Local-only flags for optimistic sends.
  final bool isSending;
  final bool failed;

  factory Message.fromJson(Map<String, dynamic> json, {int? viewerId}) {
    final senderId = Json.intOrNull(json['sender_id']);
    // A booking status change is posted by the platform, not a person. The
    // server flags it with `is_system` as well as the type, so honour either.
    final isSystem = Json.boolean(json['is_system']);
    return Message(
      id: Json.integer(json['id']),
      type: isSystem
          ? MessageType.system
          : MessageType.fromString(Json.strOrNull(json['type'])),
      uuid: Json.strOrNull(json['uuid']),
      senderId: senderId,
      senderName: Json.str(json['sender_name']),
      senderAvatar: Json.strOrNull(json['sender_avatar']),
      body: Json.str(json['body']),
      mediaUrl: Json.strOrNull(json['media_url']),
      thumbnailUrl: Json.strOrNull(json['thumbnail_url']),
      mediaWidth: Json.intOrNull(json['media_width']),
      mediaHeight: Json.intOrNull(json['media_height']),
      fileName: Json.strOrNull(json['file_name']),
      fileSize: Json.intOrNull(json['file_size']),
      durationSecs: Json.integer(json['duration_secs']),
      isMine: json['is_mine'] != null
          ? Json.boolean(json['is_mine'])
          : (viewerId != null && senderId == viewerId),
      isRead: Json.boolean(json['is_read'] ?? json['read_at'] != null),
      createdAt: Formatters.parseDate(json['created_at']),
    );
  }

  bool get isSystem => type == MessageType.system;

  String get timeLabel => Formatters.time(createdAt);

  /// Width ÷ height of an image, clamped so a panorama or a very tall
  /// screenshot still makes a sensible bubble. Null when the size is unknown.
  double? get aspectRatio {
    final w = mediaWidth;
    final h = mediaHeight;
    if (w == null || h == null || w <= 0 || h <= 0) return null;
    return (w / h).clamp(0.6, 1.8);
  }

  /// `1.4 MB`
  String get fileSizeLabel {
    final bytes = fileSize;
    if (bytes == null || bytes <= 0) return '';
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(0)} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }

  Message copyWith({int? id, bool? isSending, bool? failed, bool? isRead}) =>
      Message(
        id: id ?? this.id,
        type: type,
        uuid: uuid,
        senderId: senderId,
        senderName: senderName,
        senderAvatar: senderAvatar,
        body: body,
        mediaUrl: mediaUrl,
        thumbnailUrl: thumbnailUrl,
        mediaWidth: mediaWidth,
        mediaHeight: mediaHeight,
        fileName: fileName,
        fileSize: fileSize,
        durationSecs: durationSecs,
        isMine: isMine,
        isRead: isRead ?? this.isRead,
        createdAt: createdAt,
        isSending: isSending ?? this.isSending,
        failed: failed ?? this.failed,
      );
}
