import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// What can be reported to the moderation queue (`reports.reportable_type`).
///
/// [noun] is how the report sheet names the thing in its copy ("Report this
/// message"), so one sheet reads right wherever it is opened from.
enum ReportTarget {
  user('user', 'account'),
  creator('creator', 'profile'),
  message('message', 'message'),
  review('review', 'review'),
  booking('booking', 'booking'),
  portfolio('portfolio', 'post'),
  campaign('campaign', 'brief');

  const ReportTarget(this.apiValue, this.noun);

  /// The `reportable_type` the server expects.
  final String apiValue;
  final String noun;
}

/// A reason the server accepts for a report, with how it reads to a person.
class ReportReason {
  const ReportReason(this.code, this.label);

  /// `reports.reason`, as `POST /reports` validates it.
  final String code;
  final String label;

  /// In the order the sheet lists them: the everyday problems first, the
  /// serious harms together, "something else" last.
  static const all = <ReportReason>[
    ReportReason('spam', 'Spam or misleading'),
    ReportReason('fraud', 'Scam or fraud'),
    ReportReason('payment_issue', 'Asking to pay outside Zusty'),
    ReportReason('abuse', 'Harassment or bullying'),
    ReportReason('hate_speech', 'Hate speech'),
    ReportReason('sexual_content', 'Nudity or sexual content'),
    ReportReason('violence', 'Violence or threats'),
    ReportReason('self_harm', 'Self-harm or suicide'),
    ReportReason('child_safety', 'A child may be at risk'),
    ReportReason('impersonation', 'Pretending to be someone else'),
    ReportReason('copyright', 'Uses someone\'s work without permission'),
    ReportReason('other', 'Something else'),
  ];
}

/// An account the signed-in user blocked, from `GET /me/blocks`.
class BlockedUser {
  const BlockedUser({
    required this.userId,
    required this.name,
    this.avatarUrl,
    this.accountType = '',
    this.handle,
    this.blockedAt,
  });

  final int userId;

  /// The name they go by on Zusty: a creator's display name, a brand's
  /// company name, or the account name.
  final String name;
  final String? avatarUrl;

  /// `brand` or `creator`.
  final String accountType;

  /// A creator's public handle; null for a brand.
  final String? handle;
  final DateTime? blockedAt;

  factory BlockedUser.fromJson(Map<String, dynamic> json) => BlockedUser(
    userId: Json.integer(json['user_id']),
    name: Json.str(json['name'], 'Zusty user'),
    avatarUrl: Json.strOrNull(json['avatar_url']),
    accountType: Json.str(json['account_type']),
    handle: Json.strOrNull(json['handle']),
    blockedAt: Formatters.parseDate(json['blocked_at']),
  );

  bool get isCreator => accountType == 'creator';

  /// "Blocked on 03 Oct 2026".
  String get blockedLabel =>
      blockedAt == null ? 'Blocked' : 'Blocked on ${Formatters.date(blockedAt)}';
}
