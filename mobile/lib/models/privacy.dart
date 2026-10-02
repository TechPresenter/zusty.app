/// Privacy & account management: what `GET /me/privacy` returns, and what the
/// export and deletion requests send.
///
/// The server owns every list a user reads before deciding (what an export
/// contains, what deletion erases and keeps, why it may be blocked), so the
/// screens and the behaviour cannot drift apart.
library;

int _int(Object? value, [int fallback = 0]) =>
    value is int ? value : int.tryParse('${value ?? ''}') ?? fallback;

bool _bool(Object? value) =>
    value == true || value == 1 || value == '1' || value == 'true';

DateTime? _date(Object? value) =>
    value == null ? null : DateTime.tryParse('$value')?.toLocal();

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? value.whereType<Map>().map((m) => m.cast<String, dynamic>()).toList()
    : const [];

/// Everything the privacy screens need, in one response.
class PrivacyOverview {
  const PrivacyOverview({
    required this.verification,
    required this.export,
    required this.deletion,
  });

  factory PrivacyOverview.fromJson(Map<String, dynamic> json) => PrivacyOverview(
        verification: VerificationOptions.fromJson(
          (json['verification'] as Map?)?.cast<String, dynamic>() ?? const {},
        ),
        export: ExportStatus.fromJson(
          (json['export'] as Map?)?.cast<String, dynamic>() ?? const {},
        ),
        deletion: DeletionPreflight.fromJson(
          (json['deletion'] as Map?)?.cast<String, dynamic>() ?? const {},
        ),
      );

  final VerificationOptions verification;
  final ExportStatus export;
  final DeletionPreflight deletion;
}

// ---------------------------------------------------------------------------
// Re-authentication
// ---------------------------------------------------------------------------

/// The ways this account can prove, right now, that it is its owner.
class VerificationOptions {
  const VerificationOptions({required this.password, required this.channels});

  factory VerificationOptions.fromJson(Map<String, dynamic> json) =>
      VerificationOptions(
        password: _bool(json['password']),
        channels: _rows(json['channels']).map(CodeChannel.fromJson).toList(),
      );

  /// The account has a password it can type.
  final bool password;

  /// Where a one-time code can be sent: the account's own email or phone.
  final List<CodeChannel> channels;

  bool get canVerify => password || channels.isNotEmpty;
}

class CodeChannel {
  const CodeChannel({required this.channel, required this.destination});

  factory CodeChannel.fromJson(Map<String, dynamic> json) => CodeChannel(
        channel: '${json['channel'] ?? 'email'}',
        destination: '${json['destination'] ?? ''}',
      );

  /// `email` or `sms`.
  final String channel;

  /// Masked by the server, for example `pr****@gmail.com`.
  final String destination;

  bool get isSms => channel == 'sms';
  String get label => isSms ? 'Text message' : 'Email';
}

/// What a sent code looks like, as the server describes it.
class SentCode {
  const SentCode({
    required this.channel,
    required this.destination,
    required this.length,
    required this.expiresIn,
  });

  factory SentCode.fromJson(Map<String, dynamic> json) => SentCode(
        channel: '${json['channel'] ?? 'email'}',
        destination: '${json['destination'] ?? ''}',
        length: _int(json['length'], 6),
        expiresIn: Duration(seconds: _int(json['expires_in'], 300)),
      );

  final String channel;
  final String destination;
  final int length;
  final Duration expiresIn;
}

/// The proof sent with an export or a deletion: a password, or a code.
class Reauth {
  const Reauth.password(String this.password)
      : code = null,
        channel = null;

  const Reauth.code(String this.code, {required String this.channel})
      : password = null;

  final String? password;
  final String? code;
  final String? channel;

  bool get usesCode => code != null;

  Map<String, dynamic> toJson() => usesCode
      ? {'code': code, 'channel': channel}
      : {'password': password};
}

/// The two actions a code can confirm. A code for one never works for the other.
abstract final class PrivacyPurpose {
  static const export = 'data_export';
  static const delete = 'delete_account';
}

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

/// A titled line of explanation: what an export holds, what deletion erases.
class PrivacyItem {
  const PrivacyItem({required this.title, required this.detail, this.key = ''});

  factory PrivacyItem.fromJson(Map<String, dynamic> json) => PrivacyItem(
        key: '${json['key'] ?? ''}',
        title: '${json['title'] ?? ''}',
        detail: '${json['detail'] ?? ''}',
      );

  final String key;
  final String title;
  final String detail;
}

class ExportStatus {
  const ExportStatus({
    required this.latest,
    required this.canRequest,
    required this.nextAvailableAt,
    required this.cooldownHours,
    required this.linkHours,
    required this.maxDownloads,
    required this.format,
    required this.includes,
  });

  factory ExportStatus.fromJson(Map<String, dynamic> json) => ExportStatus(
        latest: json['latest'] is Map
            ? DataExport.fromJson((json['latest'] as Map).cast<String, dynamic>())
            : null,
        canRequest: json.containsKey('can_request') ? _bool(json['can_request']) : true,
        nextAvailableAt: _date(json['next_available_at']),
        cooldownHours: _int(json['cooldown_hours'], 24),
        linkHours: _int(json['link_hours'], 72),
        maxDownloads: _int(json['max_downloads'], 5),
        format: '${json['format'] ?? 'ZIP archive of JSON files'}',
        includes: _rows(json['includes']).map(PrivacyItem.fromJson).toList(),
      );

  final DataExport? latest;
  final bool canRequest;
  final DateTime? nextAvailableAt;
  final int cooldownHours;
  final int linkHours;
  final int maxDownloads;
  final String format;
  final List<PrivacyItem> includes;
}

class DataExport {
  const DataExport({
    required this.id,
    required this.status,
    required this.fileSize,
    required this.downloadCount,
    required this.maxDownloads,
    required this.downloadsLeft,
    required this.canDownload,
    required this.downloadUrl,
    required this.createdAt,
    required this.expiresAt,
    required this.lastDownloadedAt,
  });

  factory DataExport.fromJson(Map<String, dynamic> json) => DataExport(
        id: _int(json['id']),
        status: '${json['status'] ?? 'processing'}',
        fileSize: json['file_size'] == null ? null : _int(json['file_size']),
        downloadCount: _int(json['download_count']),
        maxDownloads: _int(json['max_downloads'], 5),
        downloadsLeft: _int(json['downloads_left']),
        canDownload: _bool(json['can_download']),
        downloadUrl: (json['download_url'] as String?)?.trim().isEmpty ?? true
            ? null
            : json['download_url'] as String,
        createdAt: _date(json['created_at']),
        expiresAt: _date(json['expires_at']),
        lastDownloadedAt: _date(json['last_downloaded_at']),
      );

  final int id;

  /// `processing`, `ready`, `failed` or `expired`.
  final String status;
  final int? fileSize;
  final int downloadCount;
  final int maxDownloads;
  final int downloadsLeft;
  final bool canDownload;
  final String? downloadUrl;
  final DateTime? createdAt;
  final DateTime? expiresAt;
  final DateTime? lastDownloadedAt;

  bool get isReady => status == 'ready' && canDownload && downloadUrl != null;
  bool get isFailed => status == 'failed';

  /// "84 KB", "1.2 MB".
  String get sizeLabel {
    final bytes = fileSize;
    if (bytes == null) return '';
    if (bytes >= 1024 * 1024) return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
    return '${(bytes / 1024).ceil()} KB';
  }
}

// ---------------------------------------------------------------------------
// Deletion
// ---------------------------------------------------------------------------

class DeletionPreflight {
  const DeletionPreflight({
    required this.canDelete,
    required this.blockers,
    required this.confirmPhrase,
    required this.reasons,
    required this.erased,
    required this.retained,
    required this.retainedCounts,
  });

  factory DeletionPreflight.fromJson(Map<String, dynamic> json) {
    final counts = (json['retained_counts'] as Map?)?.cast<String, dynamic>() ?? const {};

    return DeletionPreflight(
      canDelete: json.containsKey('can_delete') ? _bool(json['can_delete']) : true,
      blockers: _rows(json['blockers']).map(DeletionBlocker.fromJson).toList(),
      confirmPhrase: '${json['confirm_phrase'] ?? 'DELETE'}',
      reasons: _rows(json['reasons']).map(DeletionReason.fromJson).toList(),
      erased: _rows(json['erased']).map(PrivacyItem.fromJson).toList(),
      retained: _rows(json['retained']).map(PrivacyItem.fromJson).toList(),
      retainedCounts: counts.map((key, value) => MapEntry(key, _int(value))),
    );
  }

  final bool canDelete;
  final List<DeletionBlocker> blockers;

  /// What the user types to confirm, for example `DELETE`.
  final String confirmPhrase;
  final List<DeletionReason> reasons;
  final List<PrivacyItem> erased;
  final List<PrivacyItem> retained;
  final Map<String, int> retainedCounts;

  /// "3 bookings, 2 invoices and 4 payments", or empty when nothing is kept.
  String get retainedSummary {
    const nouns = {
      'bookings': 'booking',
      'invoices': 'invoice',
      'payments': 'payment',
      'messages': 'message',
    };

    final parts = <String>[
      for (final entry in nouns.entries)
        if ((retainedCounts[entry.key] ?? 0) > 0)
          '${retainedCounts[entry.key]} ${entry.value}${retainedCounts[entry.key] == 1 ? '' : 's'}',
    ];

    if (parts.length <= 1) return parts.join();
    return '${parts.sublist(0, parts.length - 1).join(', ')} and ${parts.last}';
  }
}

class DeletionBlocker {
  const DeletionBlocker({
    required this.code,
    required this.title,
    required this.message,
    required this.action,
  });

  factory DeletionBlocker.fromJson(Map<String, dynamic> json) => DeletionBlocker(
        code: '${json['code'] ?? ''}',
        title: '${json['title'] ?? ''}',
        message: '${json['message'] ?? ''}',
        action: '${json['action'] ?? ''}',
      );

  final String code;
  final String title;
  final String message;

  /// Where to go to settle it: `bookings`, `wallet` or `support`.
  final String action;
}

class DeletionReason {
  const DeletionReason({required this.code, required this.label});

  factory DeletionReason.fromJson(Map<String, dynamic> json) => DeletionReason(
        code: '${json['code'] ?? ''}',
        label: '${json['label'] ?? ''}',
      );

  final String code;
  final String label;
}
