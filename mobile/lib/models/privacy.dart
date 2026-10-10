/// Privacy & account management: what `GET /me/privacy` returns, and what the
/// export and deletion requests send.
///
/// The server owns every list a user reads before deciding (what an export
/// contains, what deletion erases and keeps, why it may be blocked), so the
/// screens and the behaviour cannot drift apart.
library;

int _int(Object? value, [int fallback = 0]) =>
    value is int ? value : int.tryParse('${value ?? ''}') ?? fallback;

double _double(Object? value) =>
    value is num ? value.toDouble() : double.tryParse('${value ?? ''}') ?? 0;

bool _bool(Object? value) =>
    value == true || value == 1 || value == '1' || value == 'true';

DateTime? _date(Object? value) =>
    value == null ? null : DateTime.tryParse('$value')?.toLocal();

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? value.whereType<Map>().map((m) => m.cast<String, dynamic>()).toList()
    : const [];

List<String> _strings(Object? value) => value is List
    ? [
        for (final item in value)
          if (item != null && '$item'.isNotEmpty) '$item',
      ]
    : const [];

Map<String, dynamic>? _map(Object? value) =>
    value is Map ? value.cast<String, dynamic>() : null;

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
  const VerificationOptions({
    required this.password,
    required this.channels,
    bool? hasPassword,
    this.unavailable = const [],
    this.providers = const [],
  }) : hasPassword = hasPassword ?? password;

  factory VerificationOptions.fromJson(Map<String, dynamic> json) =>
      VerificationOptions(
        password: _bool(json['password']),
        hasPassword: json.containsKey('has_password') ? _bool(json['has_password']) : null,
        channels: _rows(json['channels']).map(CodeChannel.fromJson).toList(),
        unavailable: _rows(json['unavailable']).map(UnavailableChannel.fromJson).toList(),
        providers: _strings(json['providers']),
      );

  /// The account has a password it can confirm with. False for a password
  /// that was set without proof, which the server no longer accepts.
  final bool password;

  /// The account has a password at all, proven or not. Decides whether the
  /// app offers "Change password" or "Set a password".
  final bool hasPassword;

  /// Where a one-time code can be sent: the account's own email or phone.
  final List<CodeChannel> channels;

  /// Contact points the account has that cannot take a code right now, and
  /// why: text messages are switched off, or the address changed recently.
  final List<UnavailableChannel> unavailable;

  /// Sign-in providers that can confirm it is the owner with a fresh
  /// sign-in: `google.com`, `apple.com` or `phone`.
  final List<String> providers;

  /// The providers this app can confirm with: Google and Apple. A phone
  /// sign-in needs a Firebase phone flow the app does not have yet.
  List<String> get appProviders => [
        for (final provider in providers)
          if (provider == 'google.com' || provider == 'apple.com') provider,
      ];

  bool get canVerify => password || channels.isNotEmpty || appProviders.isNotEmpty;
}

/// A contact point that cannot take a code right now.
class UnavailableChannel {
  const UnavailableChannel({
    required this.channel,
    required this.destination,
    required this.reason,
    this.availableAt,
  });

  factory UnavailableChannel.fromJson(Map<String, dynamic> json) => UnavailableChannel(
        channel: '${json['channel'] ?? 'email'}',
        destination: '${json['destination'] ?? ''}',
        reason: '${json['reason'] ?? ''}',
        availableAt: _date(json['available_at']),
      );

  /// `email` or `sms`.
  final String channel;

  /// Masked by the server.
  final String destination;

  /// `sms_unavailable` (text messages are switched off) or
  /// `contact_recently_changed` (held for a few days after a change).
  final String reason;

  /// When a recently changed address can take codes again.
  final DateTime? availableAt;

  bool get isSms => channel == 'sms';
  bool get recentlyChanged => reason == 'contact_recently_changed';
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
    this.resendIn = const Duration(seconds: 30),
  });

  factory SentCode.fromJson(Map<String, dynamic> json) => SentCode(
        channel: '${json['channel'] ?? 'email'}',
        destination: '${json['destination'] ?? ''}',
        length: _int(json['length'], 6),
        expiresIn: Duration(seconds: _int(json['expires_in'], 300)),
        resendIn: Duration(seconds: _int(json['resend_in'], 30)),
      );

  final String channel;
  final String destination;
  final int length;
  final Duration expiresIn;

  /// How long the server makes the next send wait.
  final Duration resendIn;
}

/// The proof sent with a privacy action: a password, a one-time code, or
/// the ID token of a fresh Google or Apple sign-in.
class Reauth {
  const Reauth.password(String this.password)
      : code = null,
        channel = null,
        idToken = null;

  const Reauth.code(String this.code, {required String this.channel})
      : password = null,
        idToken = null;

  const Reauth.firebase(String this.idToken)
      : password = null,
        code = null,
        channel = null;

  final String? password;
  final String? code;
  final String? channel;

  /// A Firebase ID token from a sign-in completed moments ago.
  final String? idToken;

  bool get usesCode => code != null;
  bool get usesFirebase => idToken != null;

  Map<String, dynamic> toJson() => usesCode
      ? {'code': code, 'channel': channel}
      : usesFirebase
          ? {'id_token': idToken}
          : {'password': password};
}

/// What a code confirms. A code for one action never works for another.
abstract final class PrivacyPurpose {
  static const export = 'data_export';
  static const delete = 'delete_account';

  /// Adding a first password to an account that signed up without one.
  static const setPassword = 'set_password';

  /// Changing the account's primary email or mobile number.
  static const contactChange = 'confirm_contact_change';
}

/// Where the server sent a security notice: masked, by email or text.
class SecurityNotice {
  const SecurityNotice({required this.channel, required this.destination});

  factory SecurityNotice.fromJson(Map<String, dynamic> json) => SecurityNotice(
        channel: '${json['channel'] ?? 'email'}',
        destination: '${json['destination'] ?? ''}',
      );

  /// Null unless [value] is a notice with somewhere to show.
  static SecurityNotice? tryParse(Object? value) {
    final json = _map(value);
    if (json == null) return null;
    final notice = SecurityNotice.fromJson(json);
    return notice.destination.isEmpty ? null : notice;
  }

  /// `email` or `sms`.
  final String channel;

  /// For example `pr****@gmail.com`.
  final String destination;

  bool get isSms => channel == 'sms';
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
    this.securityNotice,
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
        securityNotice: SecurityNotice.tryParse(json['security_notice']),
      );

  final int id;

  /// `processing`, `ready`, `failed` or `expired`.
  final String status;
  final int? fileSize;
  final int downloadCount;
  final int maxDownloads;
  final int downloadsLeft;

  /// A copy exists and a download link can be issued for it.
  final bool canDownload;

  /// Only on the response that minted it (a new export, or a link issued
  /// after confirming it is the owner). The overview never carries one.
  final String? downloadUrl;
  final DateTime? createdAt;
  final DateTime? expiresAt;
  final DateTime? lastDownloadedAt;

  /// Where the server told the owner about this export. Only on the
  /// response that created it, and null when there was nowhere to send it.
  final SecurityNotice? securityNotice;

  /// Ready, not expired and with downloads left. A link may still have to
  /// be asked for: see [downloadUrl].
  bool get isReady => status == 'ready' && canDownload;
  bool get isFailed => status == 'failed';

  /// This copy as [server] now reports it, keeping the link and notice only
  /// this device was handed. The counters, state and expiry are the server's,
  /// so a copy used up or expired elsewhere stops offering a download here.
  DataExport mergedWith(DataExport server) => DataExport(
        id: id,
        status: server.status,
        fileSize: server.fileSize ?? fileSize,
        downloadCount: server.downloadCount,
        maxDownloads: server.maxDownloads,
        downloadsLeft: server.downloadsLeft,
        canDownload: server.canDownload,
        downloadUrl: server.isReady ? server.downloadUrl ?? downloadUrl : null,
        createdAt: server.createdAt ?? createdAt,
        expiresAt: server.expiresAt ?? expiresAt,
        lastDownloadedAt: server.lastDownloadedAt ?? lastDownloadedAt,
        securityNotice: server.securityNotice ?? securityNotice,
      );

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
    this.forfeit,
  });

  factory DeletionPreflight.fromJson(Map<String, dynamic> json) {
    final counts = (json['retained_counts'] as Map?)?.cast<String, dynamic>() ?? const {};
    final forfeit = _map(json['forfeit']);

    return DeletionPreflight(
      canDelete: json.containsKey('can_delete') ? _bool(json['can_delete']) : true,
      blockers: _rows(json['blockers']).map(DeletionBlocker.fromJson).toList(),
      confirmPhrase: '${json['confirm_phrase'] ?? 'DELETE'}',
      reasons: _rows(json['reasons']).map(DeletionReason.fromJson).toList(),
      erased: _rows(json['erased']).map(PrivacyItem.fromJson).toList(),
      retained: _rows(json['retained']).map(PrivacyItem.fromJson).toList(),
      retainedCounts: counts.map((key, value) => MapEntry(key, _int(value))),
      forfeit: forfeit == null ? null : DeletionForfeit.fromJson(forfeit),
    );
  }

  /// No hard blockers. A [forfeit] may still need the user's explicit
  /// agreement before the server accepts the deletion.
  final bool canDelete;
  final List<DeletionBlocker> blockers;

  /// A small wallet balance that can't be paid out (below the minimum
  /// withdrawal, or without KYC), which the user may give up to delete now.
  final DeletionForfeit? forfeit;

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

  /// Where to go to settle it: `bookings`, `wallet`, `kyc` (verify identity
  /// so the balance can be withdrawn) or `support`.
  final String action;
}

/// A wallet balance the user may give up to delete their account, because
/// it can't be paid out.
class DeletionForfeit {
  const DeletionForfeit({
    required this.amount,
    required this.currency,
    required this.label,
    required this.reason,
    required this.message,
  });

  factory DeletionForfeit.fromJson(Map<String, dynamic> json) => DeletionForfeit(
        amount: _double(json['amount']),
        currency: '${json['currency'] ?? 'INR'}',
        label: '${json['label'] ?? ''}',
        reason: '${json['reason'] ?? ''}',
        message: '${json['message'] ?? ''}',
      );

  /// Sent back as `forfeit_balance`: the server deletes only when it still
  /// matches the balance.
  final double amount;
  final String currency;

  /// Formatted by the server, for example `₹250.00`.
  final String label;

  /// `below_minimum`, `kyc_required` or `contact_unverified`.
  final String reason;
  final String message;
}

/// What the server reports after erasing the account.
class DeletionResult {
  const DeletionResult({this.notice, this.retained = const {}});

  factory DeletionResult.fromJson(Map<String, dynamic> json) {
    final retained = _map(json['retained']) ?? const {};

    return DeletionResult(
      notice: SecurityNotice.tryParse(json['notice']),
      retained: retained.map((key, value) => MapEntry(key, _int(value))),
    );
  }

  /// Where the confirmation went, or null when the account had no email
  /// address or text-message number to send one to.
  final SecurityNotice? notice;

  /// The records kept for the legal period, by kind.
  final Map<String, int> retained;
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
