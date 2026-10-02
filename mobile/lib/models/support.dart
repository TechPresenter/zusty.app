import '../core/utils/formatters.dart';
import '../core/utils/json.dart';
import 'booking.dart' show StatusTone;

/// A support ticket from `GET /support/tickets`.
class SupportTicket {
  const SupportTicket({
    required this.id,
    required this.reference,
    required this.subject,
    this.category = 'general',
    this.status = 'open',
    this.priority = 'normal',
    this.lastMessage = '',
    this.messagesCount = 0,
    this.bookingId,
    this.messages = const [],
    this.createdAt,
    this.updatedAt,
  });

  final int id;
  final String reference;
  final String subject;

  /// `booking_dispute`, `payment`, `account`, `kyc`, or `general`.
  final String category;
  final String status;
  final String priority;
  final String lastMessage;
  final int messagesCount;
  final int? bookingId;
  final List<TicketMessage> messages;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  factory SupportTicket.fromJson(Map<String, dynamic> json) => SupportTicket(
    id: Json.integer(json['id']),
    reference: Json.str(json['reference'] ?? json['code']),
    subject: Json.str(json['subject'] ?? json['title'], 'Support request'),
    category: Json.str(json['category'], 'general'),
    status: Json.str(json['status'], 'open'),
    priority: Json.str(json['priority'], 'normal'),
    lastMessage: Json.str(json['last_message']),
    messagesCount: Json.integer(json['messages_count']),
    bookingId: Json.intOrNull(json['booking_id']),
    messages: Json.list(json['messages'], TicketMessage.fromJson),
    createdAt: Formatters.parseDate(json['created_at']),
    // The list endpoint sends no `updated_at`, only when the thread last
    // moved — which is what "updated 2h ago" on a ticket card means anyway.
    updatedAt: Formatters.parseDate(
      json['updated_at'] ?? json['last_reply_at'],
    ),
  );

  /// The API's workflow states are named for the support desk
  /// (`pending_agent`, `pending_user`); these say the same thing from the
  /// customer's side of the conversation.
  StatusTone get tone => switch (status) {
    'resolved' || 'closed' => StatusTone.success,
    // The ball is in the user's court — the one state that needs them.
    'pending_user' || 'awaiting_reply' => StatusTone.warning,
    'open' || 'pending' => StatusTone.warning,
    'pending_agent' || 'in_progress' => StatusTone.info,
    _ => StatusTone.neutral,
  };

  String get statusLabel => switch (status) {
    'pending_agent' => 'With our team',
    'pending_user' || 'awaiting_reply' => 'Awaiting your reply',
    'in_progress' => 'In progress',
    '' => 'Open',
    _ => status[0].toUpperCase() + status.substring(1).replaceAll('_', ' '),
  };

  /// A closed ticket refuses replies server-side (409), so the thread offers
  /// a new ticket instead of a composer that can only fail.
  bool get isClosed => status == 'closed';

  String get categoryLabel => switch (category) {
    'booking_dispute' => 'Booking dispute',
    'payment' => 'Payment issue',
    'account' => 'Account',
    'kyc' => 'Verification',
    _ => 'General inquiry',
  };

  String get timeLabel => Formatters.relative(updatedAt ?? createdAt);
}

/// One reply inside a ticket thread.
class TicketMessage {
  const TicketMessage({
    required this.id,
    this.body = '',
    this.authorName = '',
    this.authorAvatar,
    this.isStaff = false,
    this.attachmentUrl,
    this.createdAt,
  });

  final int id;
  final String body;
  final String authorName;
  final String? authorAvatar;

  /// True when the reply came from the Zusty support team.
  final bool isStaff;
  final String? attachmentUrl;
  final DateTime? createdAt;

  factory TicketMessage.fromJson(Map<String, dynamic> json) => TicketMessage(
    id: Json.integer(json['id']),
    body: Json.str(json['body'] ?? json['message']),
    authorName: Json.str(
      json['author_name'] ?? json['sender_name'] ?? json['name'],
    ),
    authorAvatar: Json.strOrNull(json['author_avatar'] ?? json['avatar_url']),
    // The live thread says who wrote each reply with `sender_type`
    // (`user` / `agent`), not a staff flag. Reading only `is_staff` put
    // every agent reply on the user's side of the conversation.
    isStaff: json.containsKey('is_staff') || json.containsKey('is_admin')
        ? Json.boolean(json['is_staff'] ?? json['is_admin'])
        : _staffSenders.contains(Json.str(json['sender_type'])),
    attachmentUrl: Json.strOrNull(json['attachment_url']),
    createdAt: Formatters.parseDate(json['created_at']),
  );

  static const _staffSenders = {'agent', 'admin', 'staff', 'system'};

  String get timeLabel => Formatters.time(createdAt);
}

/// A help-centre entry from `GET /faqs`.
class Faq {
  const Faq({
    required this.id,
    required this.question,
    required this.answer,
    this.category = 'general',
    this.categoryLabel = '',
    this.audience = 'all',
    this.helpfulYes = 0,
    this.helpfulNo = 0,
  });

  final int id;
  final String question;
  final String answer;
  final String category;

  /// The category's display name from the admin panel ("For creators").
  /// Empty from older servers; use [categoryName], which falls back.
  final String categoryLabel;

  /// `all`, `brands` or `creators` — who the operator wrote the answer for.
  final String audience;
  final int helpfulYes;
  final int helpfulNo;

  factory Faq.fromJson(Map<String, dynamic> json) => Faq(
    id: Json.integer(json['id']),
    question: Json.str(json['question'] ?? json['title']),
    answer: Json.str(json['answer'] ?? json['content']),
    category: Json.str(json['category'], 'general'),
    categoryLabel: Json.str(json['category_label']),
    audience: Json.str(json['audience'], 'all'),
    helpfulYes: Json.integer(json['helpful_yes']),
    helpfulNo: Json.integer(json['helpful_no']),
  );

  /// [categoryLabel], or the slug made readable ("getting_started" →
  /// "Getting started") when the server did not send one.
  String get categoryName {
    if (categoryLabel.isNotEmpty) return categoryLabel;
    if (category.isEmpty) return 'General';
    final words = category.replaceAll(RegExp('[_-]+'), ' ').trim();
    if (words.isEmpty) return 'General';
    return words[0].toUpperCase() + words.substring(1);
  }

  /// Whether the answer was written for this side of the marketplace. A
  /// guest, or a server that sends no audience, sees everything.
  bool isFor({required bool? isCreator}) => switch (audience) {
    'brands' => isCreator != true,
    'creators' => isCreator != false,
    _ => true,
  };
}

/// A CMS page from `GET /pages/{slug}` (Terms, Privacy, About …).
class CmsPage {
  const CmsPage({
    required this.slug,
    required this.title,
    this.subtitle,
    this.content = '',
    this.heroImage,
    this.updatedAt,
  });

  final String slug;
  final String title;
  final String? subtitle;
  final String content;
  final String? heroImage;
  final DateTime? updatedAt;

  factory CmsPage.fromJson(Map<String, dynamic> json) => CmsPage(
    slug: Json.str(json['slug']),
    title: Json.str(json['title']),
    subtitle: Json.strOrNull(json['subtitle']),
    content: Json.str(json['content']),
    heroImage: Json.strOrNull(json['hero_image']),
    updatedAt: Formatters.parseDate(json['updated_at']),
  );
}

/// The referral summary behind the Invite & Earn screen (`GET /me/referrals`).
class ReferralSummary {
  const ReferralSummary({
    this.code = '',
    this.shareUrl = '',
    this.shareMessage = '',
    this.totalInvited = 0,
    this.totalJoined = 0,
    this.rewardedCount = 0,
    this.pendingCount = 0,
    this.totalEarned = 0,
    this.rewardPerReferral = 0,
    this.friendReward = 0,
    this.enabled = true,
    this.referrals = const [],
  });

  final String code;
  final String shareUrl;

  /// The invite text the operator configured, amounts already filled in.
  /// Empty from older servers; the screen then writes its own.
  final String shareMessage;
  final int totalInvited;
  final int totalJoined;

  /// Invites that have paid out, and invites still waiting to qualify.
  final int rewardedCount;
  final int pendingCount;
  final double totalEarned;

  /// What the inviter earns per qualified friend.
  final double rewardPerReferral;

  /// The welcome bonus the invited friend gets. Zero when not configured.
  final double friendReward;

  /// False when the operator has switched the referral programme off.
  final bool enabled;
  final List<ReferralEntry> referrals;

  /// Accepts both shapes the endpoint has had.
  ///
  /// The live API nests the figures under `summary` and the people under
  /// `invitees`, with `total_invites` / `referrer_reward` names. This used to
  /// read only the older flat shape, so on a real account the code, link and
  /// every total came back blank and the reward fell to a made-up ₹500.
  factory ReferralSummary.fromJson(Map<String, dynamic> json) {
    final summary = json['summary'] is Map ? Json.map(json['summary']) : json;
    final people = json['invitees'] ?? json['referrals'];
    final entries = Json.list(people, ReferralEntry.fromJson);

    return ReferralSummary(
      code: Json.str(summary['code'] ?? summary['referral_code']),
      shareUrl: Json.str(summary['share_url'] ?? summary['link']),
      shareMessage: Json.str(summary['share_message']),
      totalInvited: Json.integer(
        summary['total_invites'] ?? summary['total_invited'],
      ),
      // Everyone the API lists signed up with the code, so "joined" is the
      // invite total unless an older server reports it separately.
      totalJoined: Json.integer(
        summary['total_joined'] ?? summary['total_invites'],
      ),
      rewardedCount: summary.containsKey('rewarded_invites')
          ? Json.integer(summary['rewarded_invites'])
          : entries.where((e) => e.isRewarded).length,
      pendingCount: summary.containsKey('pending_invites')
          ? Json.integer(summary['pending_invites'])
          : entries.where((e) => e.status == 'pending').length,
      totalEarned: Json.decimal(summary['total_earned']),
      rewardPerReferral: Json.decimal(
        summary['referrer_reward'] ?? summary['reward_per_referral'],
        500,
      ),
      friendReward: Json.decimal(summary['referred_reward']),
      enabled: Json.boolean(summary['enabled'], true),
      referrals: entries,
    );
  }
}

/// One invited friend and where they are in the funnel.
class ReferralEntry {
  const ReferralEntry({
    required this.name,
    this.avatarUrl,
    this.status = 'pending',
    this.reward = 0,
    this.joinedAt,
    this.rewardedAt,
  });

  final String name;
  final String? avatarUrl;

  /// `pending` (signed up), `qualified` (first booking done), `rewarded`
  /// (paid out) or `void` (did not qualify).
  final String status;
  final double reward;
  final DateTime? joinedAt;
  final DateTime? rewardedAt;

  factory ReferralEntry.fromJson(Map<String, dynamic> json) => ReferralEntry(
    name: Json.str(json['name'], 'Invited friend'),
    avatarUrl: Json.strOrNull(json['avatar_url']),
    status: Json.str(json['status'], 'pending'),
    // The live invitee rows carry the amount as `referrer_reward`, the
    // reward this friend is worth to the inviter.
    reward: Json.decimal(json['reward'] ?? json['referrer_reward']),
    joinedAt: Formatters.parseDate(json['joined_at'] ?? json['created_at']),
    rewardedAt: Formatters.parseDate(json['rewarded_at']),
  );

  String get initials => Formatters.initials(name);

  bool get isRewarded => status == 'rewarded' || status == 'completed';

  StatusTone get tone => switch (status) {
    'rewarded' || 'completed' => StatusTone.success,
    'qualified' || 'joined' || 'signed_up' => StatusTone.info,
    'void' => StatusTone.neutral,
    _ => StatusTone.warning,
  };

  String get statusLabel => switch (status) {
    'pending' => 'Signed up',
    'qualified' => 'Qualified',
    'void' => 'Not eligible',
    '' => 'Pending',
    _ => status[0].toUpperCase() + status.substring(1).replaceAll('_', ' '),
  };
}
