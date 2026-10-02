import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// One signed-in device, from `GET /auth/sessions`.
///
/// A session is a live refresh token. Revoking one signs that device out the
/// next time its access token expires — it cannot be undone from here.
class AccountSession {
  const AccountSession({
    required this.id,
    this.deviceName = '',
    this.platform = 'unknown',
    this.ipAddress,
    this.createdAt,
    this.lastUsedAt,
    this.expiresAt,
  });

  final int id;
  final String deviceName;

  /// `android`, `ios`, `web` or `unknown`.
  final String platform;
  final String? ipAddress;
  final DateTime? createdAt;
  final DateTime? lastUsedAt;
  final DateTime? expiresAt;

  factory AccountSession.fromJson(Map<String, dynamic> json) => AccountSession(
    id: Json.integer(json['id']),
    deviceName: Json.str(json['device_name']),
    platform: Json.str(json['platform'], 'unknown'),
    ipAddress: Json.strOrNull(json['ip_address']),
    createdAt: Formatters.parseDate(json['created_at']),
    lastUsedAt: Formatters.parseDate(json['last_used_at']),
    expiresAt: Formatters.parseDate(json['expires_at']),
  );

  /// The device's own name when the app sent one, otherwise the platform.
  String get title {
    if (deviceName.trim().isNotEmpty) return deviceName.trim();
    return switch (platform) {
      'android' => 'Android device',
      'ios' => 'iPhone or iPad',
      'web' => 'Web browser',
      _ => 'Unknown device',
    };
  }

  /// The most recent sign of life: last refresh, else when it signed in.
  DateTime? get lastActive => lastUsedAt ?? createdAt;
}

/// Whether a creator's profile shows in the public directory, from
/// `GET /me/creator/visibility`.
///
/// Two separate things decide it: the creator's own switch ([isPublic]) and
/// moderation ([status]). The switch never sends a profile back through
/// review, so [blockers] — the server's own wording — is what explains why a
/// switched-on profile is still not live.
class CreatorVisibility {
  const CreatorVisibility({
    required this.isPublic,
    required this.isLive,
    this.canGoLive = true,
    this.status = '',
    this.handle,
    this.shareUrl,
    this.blockers = const [],
  });

  final bool isPublic;
  final bool isLive;
  final bool canGoLive;
  final String status;
  final String? handle;
  final String? shareUrl;
  final List<String> blockers;

  factory CreatorVisibility.fromJson(Map<String, dynamic> json) {
    final data = json['visibility'] is Map
        ? Json.map(json['visibility'])
        : json;
    return CreatorVisibility(
      isPublic: Json.boolean(data['is_public']),
      isLive: Json.boolean(data['is_live']),
      canGoLive: Json.boolean(data['can_go_live'], true),
      status: Json.str(data['status']),
      handle: Json.strOrNull(data['handle']),
      shareUrl: Json.strOrNull(data['share_url']),
      blockers: [
        for (final item in (data['blockers'] as List? ?? const []))
          if (item != null && '$item'.trim().isNotEmpty) '$item',
      ],
    );
  }
}
