import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// Which side of the marketplace the signed-in account sits on.
enum UserRole {
  brand,
  creator,
  admin;

  static UserRole fromString(String? value) => switch (value?.toLowerCase()) {
        'creator' || 'influencer' => UserRole.creator,
        'admin' || 'moderator' || 'support' || 'finance' => UserRole.admin,
        _ => UserRole.brand,
      };

  String get label => switch (this) {
        UserRole.brand => 'Brand',
        UserRole.creator => 'Creator',
        UserRole.admin => 'Admin',
      };

  bool get isBrand => this == UserRole.brand;
  bool get isCreator => this == UserRole.creator;
}

/// KYC lifecycle, as the server reports it on `users.kyc_status` and on the
/// verification profile (`kyc_profiles.status`).
///
/// Eight states rather than the four this used to collapse into: a profile
/// waiting in the queue and one a reviewer has open read differently to the
/// person waiting, and a reviewer asking for one more document is not a
/// rejection. Folding `info_required`, `suspended` and `expired` into
/// "not submitted" told a user who had done everything that they had done
/// nothing. The legacy column values (`none`, `pending`, `verified`) map onto
/// the state they mean.
///
/// Approval is NOT the blue tick — that is granted separately by a person,
/// after approval, and travels as its own flag.
enum KycStatus {
  notSubmitted,
  pending,
  underReview,
  actionNeeded,
  approved,
  rejected,
  suspended,
  expired;

  static KycStatus fromString(String? value) =>
      switch (value?.trim().toLowerCase()) {
        'submitted' || 'pending' => KycStatus.pending,
        'under_review' || 'in_review' => KycStatus.underReview,
        'info_required' || 'action_needed' => KycStatus.actionNeeded,
        'approved' || 'verified' => KycStatus.approved,
        'rejected' || 'declined' => KycStatus.rejected,
        'suspended' => KycStatus.suspended,
        'expired' => KycStatus.expired,
        // none, draft, not_submitted, or nothing at all.
        _ => KycStatus.notSubmitted,
      };

  String get label => switch (this) {
        KycStatus.notSubmitted => 'Not submitted',
        KycStatus.pending => 'Pending',
        KycStatus.underReview => 'Under review',
        KycStatus.actionNeeded => 'Action needed',
        KycStatus.approved => 'Approved',
        KycStatus.rejected => 'Rejected',
        KycStatus.suspended => 'Suspended',
        KycStatus.expired => 'Expired',
      };

  bool get isApproved => this == KycStatus.approved;

  /// With the review team — the forms are locked until they decide.
  bool get isInReview =>
      this == KycStatus.pending || this == KycStatus.underReview;

  /// The ball is in the user's court: start, fix, or renew.
  bool get needsAction => switch (this) {
        KycStatus.notSubmitted ||
        KycStatus.actionNeeded ||
        KycStatus.rejected ||
        KycStatus.expired =>
          true,
        _ => false,
      };
}

/// The signed-in account, from `GET /auth/me` and `GET /me`.
class User {
  const User({
    required this.id,
    required this.name,
    required this.role,
    this.email,
    this.phone,
    this.phoneCountryCode = '+91',
    this.avatarUrl,
    this.companyName,
    this.website,
    this.gstNumber,
    this.billingAddress,
    this.industry,
    this.city,
    this.country,
    this.bio,
    this.creatorId,
    this.isCreator = false,
    this.emailVerified = false,
    this.phoneVerified = false,
    this.kycStatus = KycStatus.notSubmitted,
    this.identityLocked = false,
    this.referralCode,
    this.notificationPrefs = const NotificationPrefs(),
    this.walletBalance = 0,
    this.unreadNotifications = 0,
    this.unreadMessages = 0,
    this.joinedAt,
  });

  final int id;
  final String name;
  final UserRole role;
  final String? email;
  final String? phone;
  final String phoneCountryCode;
  final String? avatarUrl;
  final String? companyName;

  /// Brand-account business details (`Zuste_Workflow.md` § 2).
  final String? website;
  final String? gstNumber;
  final String? billingAddress;
  final String? industry;

  final String? city;
  final String? country;
  final String? bio;

  /// Set when this account also owns a creator profile.
  final int? creatorId;
  final bool isCreator;

  final bool emailVerified;
  final bool phoneVerified;
  final KycStatus kycStatus;

  /// `identity_locked` from `GET /me`: the account shows the verified badge,
  /// so its public name (name, company name) cannot be changed in the app.
  /// Absent from older servers and from `/auth/me`, which read as unlocked.
  final bool identityLocked;
  final String? referralCode;
  final NotificationPrefs notificationPrefs;
  final double walletBalance;
  final int unreadNotifications;
  final int unreadMessages;
  final DateTime? joinedAt;

  factory User.fromJson(Map<String, dynamic> json) => User(
        id: Json.integer(json['id']),
        name: Json.str(json['name'], 'Zusty user'),
        // The API names this `account_type`; `role` is what the app writes
        // when it caches a session. Reading only `role` parsed every signed-in
        // creator as a brand, so no creator ever saw a creator screen.
        role: UserRole.fromString(
          Json.strOrNull(json['role'] ?? json['account_type']),
        ),
        email: Json.strOrNull(json['email']),
        phone: Json.strOrNull(json['phone']),
        phoneCountryCode: Json.str(json['phone_country_code'], '+91'),
        avatarUrl: Json.strOrNull(json['avatar_url']),
        companyName: Json.strOrNull(json['company_name']),
        website: Json.strOrNull(json['website']),
        gstNumber: Json.strOrNull(json['gst_number']),
        billingAddress: Json.strOrNull(json['billing_address']),
        industry: Json.strOrNull(json['industry']),
        city: Json.strOrNull(json['city']),
        country: Json.strOrNull(json['country']),
        bio: Json.strOrNull(json['bio']),
        creatorId: Json.intOrNull(json['creator_id']),
        isCreator: Json.boolean(json['is_creator']),
        emailVerified: Json.boolean(json['email_verified']),
        phoneVerified: Json.boolean(json['phone_verified']),
        kycStatus: KycStatus.fromString(Json.strOrNull(json['kyc_status'])),
        identityLocked: Json.boolean(json['identity_locked']),
        referralCode: Json.strOrNull(json['referral_code']),
        notificationPrefs:
            NotificationPrefs.fromJson(Json.map(json['notification_prefs'])),
        walletBalance: Json.decimal(json['wallet_balance']),
        unreadNotifications: Json.integer(json['unread_notifications']),
        unreadMessages: Json.integer(json['unread_messages']),
        joinedAt: Formatters.parseDate(json['created_at'] ?? json['joined_at']),
      );

  /// Display name preferring the business name for brand accounts.
  String get displayName =>
      (companyName != null && companyName!.isNotEmpty && role.isBrand)
          ? companyName!
          : name;

  String get initials => Formatters.initials(displayName);

  String get fullPhone => phone == null ? '' : '$phoneCountryCode$phone';

  String get location =>
      [city, country].where((e) => e != null && e.isNotEmpty).join(', ');

  bool get isVerified => emailVerified || phoneVerified;

  User copyWith({
    String? name,
    String? email,
    String? phone,
    String? avatarUrl,
    String? companyName,
    String? city,
    String? country,
    String? bio,
    KycStatus? kycStatus,
    NotificationPrefs? notificationPrefs,
    double? walletBalance,
    int? unreadNotifications,
    int? unreadMessages,
  }) =>
      User(
        id: id,
        name: name ?? this.name,
        role: role,
        email: email ?? this.email,
        phone: phone ?? this.phone,
        phoneCountryCode: phoneCountryCode,
        avatarUrl: avatarUrl ?? this.avatarUrl,
        companyName: companyName ?? this.companyName,
        website: website,
        gstNumber: gstNumber,
        billingAddress: billingAddress,
        industry: industry,
        city: city ?? this.city,
        country: country ?? this.country,
        bio: bio ?? this.bio,
        creatorId: creatorId,
        isCreator: isCreator,
        emailVerified: emailVerified,
        phoneVerified: phoneVerified,
        kycStatus: kycStatus ?? this.kycStatus,
        identityLocked: identityLocked,
        referralCode: referralCode,
        notificationPrefs: notificationPrefs ?? this.notificationPrefs,
        walletBalance: walletBalance ?? this.walletBalance,
        unreadNotifications: unreadNotifications ?? this.unreadNotifications,
        unreadMessages: unreadMessages ?? this.unreadMessages,
        joinedAt: joinedAt,
      );
}

/// Per-channel notification opt-ins (`PUT /me/notification-preferences`).
class NotificationPrefs {
  const NotificationPrefs({
    this.push = true,
    this.email = true,
    this.sms = false,
    this.marketing = true,
  });

  final bool push;
  final bool email;
  final bool sms;
  final bool marketing;

  factory NotificationPrefs.fromJson(Map<String, dynamic> json) =>
      NotificationPrefs(
        push: Json.boolean(json['push'], true),
        email: Json.boolean(json['email'], true),
        sms: Json.boolean(json['sms']),
        marketing: Json.boolean(json['marketing'], true),
      );

  Map<String, dynamic> toJson() => {
        'push': push,
        'email': email,
        'sms': sms,
        'marketing': marketing,
      };

  NotificationPrefs copyWith({
    bool? push,
    bool? email,
    bool? sms,
    bool? marketing,
  }) =>
      NotificationPrefs(
        push: push ?? this.push,
        email: email ?? this.email,
        sms: sms ?? this.sms,
        marketing: marketing ?? this.marketing,
      );
}
