import '../core/utils/formatters.dart';
import '../core/utils/json.dart';
import 'creator_fields.dart' show ProfileTerm;

/// The signed-in account's contact points (`GET /me/contacts`).
///
/// The server sends its own policy alongside the data — whether OTP is on,
/// whether changes are locked, how many alternates are allowed, how long a
/// code lasts. The screen reads those rather than deciding for itself, so an
/// operator turning OTP off or locking mobile changes takes effect with no
/// app release.
class ContactBook {
  const ContactBook({
    this.email = const ContactPoint(channel: 'email'),
    this.phone = const ContactPoint(channel: 'phone'),
    this.alternates = const [],
    this.requests = const [],
    this.policy = const ContactPolicy(),
    this.labels = const [],
    this.reasons = const [],
  });

  final ContactPoint email;
  final ContactPoint phone;

  /// Additional addresses the account has added.
  final List<ContactPoint> alternates;

  /// Changes waiting on a code.
  final List<ContactChangeRequest> requests;

  final ContactPolicy policy;

  /// `contact_label` taxonomy — "Personal", "Work", "Billing".
  final List<ProfileTerm> labels;

  /// `contact_change_reason` taxonomy, offered when starting a change.
  final List<ProfileTerm> reasons;

  factory ContactBook.fromJson(Map<String, dynamic> json) {
    final primary = Json.map(json['primary']);

    return ContactBook(
      email: ContactPoint.fromJson(Json.map(primary['email']), 'email'),
      phone: ContactPoint.fromJson(Json.map(primary['phone']), 'phone'),
      alternates: Json.list(
        json['alternates'],
        (row) => ContactPoint.fromJson(row, Json.str(row['channel'])),
      ),
      requests: Json.list(json['requests'], ContactChangeRequest.fromJson),
      policy: ContactPolicy.fromJson(Json.map(json['policy'])),
      labels: Json.list(json['labels'], ProfileTerm.fromJson),
      reasons: Json.list(json['reasons'], ProfileTerm.fromJson),
    );
  }

  /// Alternates for one channel, for the two lists the screen renders.
  List<ContactPoint> alternatesFor(String channel) =>
      alternates.where((point) => point.channel == channel).toList();

  /// A change already in flight for this channel, if any. Starting a second
  /// one supersedes the first, so the screen shows this instead of an
  /// "add" button.
  ContactChangeRequest? pendingFor(String channel) {
    for (final request in requests) {
      if (request.channel == channel && request.isOpen) return request;
    }
    return null;
  }
}

/// One email address or phone number.
class ContactPoint {
  const ContactPoint({
    required this.channel,
    this.id,
    this.value,
    this.countryCode,
    this.display,
    this.label,
    this.verified = false,
    this.verifiedAt,
    this.pending,
    this.isRecovery = false,
  });

  /// `email` or `phone`. Names the identifier, not the transport.
  final String channel;

  /// Null for a primary point, which lives on the user row.
  final int? id;

  final String? value;
  final String? countryCode;

  /// Pre-composed for display — "+91 98765 43210".
  final String? display;

  final String? label;
  final bool verified;
  final DateTime? verifiedAt;

  /// A change to this address waiting on a code.
  final ContactChangeRequest? pending;

  /// May be used to recover the account, not only to reach the person.
  final bool isRecovery;

  factory ContactPoint.fromJson(Map<String, dynamic> json, String channel) =>
      ContactPoint(
        channel: channel.isEmpty ? Json.str(json['channel'], 'email') : channel,
        id: json['id'] == null ? null : Json.integer(json['id']),
        value: Json.strOrNull(json['value']),
        countryCode: Json.strOrNull(json['country_code']),
        display: Json.strOrNull(json['display']),
        label: Json.strOrNull(json['label']),
        verified: Json.boolean(json['verified']),
        verifiedAt: Formatters.parseDate(json['verified_at']),
        pending: json['pending'] == null
            ? null
            : ContactChangeRequest.fromJson(Json.map(json['pending'])),
        isRecovery: Json.boolean(json['is_recovery']),
      );

  bool get isSet => (value ?? '').isNotEmpty;

  /// What to show on the row.
  String get shown => display ?? value ?? 'Not set';
}

/// A change to a contact point, waiting on the NEW value being proved.
///
/// The live address does not move until the code is confirmed, so a mistyped
/// address can never lock someone out of their own account.
class ContactChangeRequest {
  const ContactChangeRequest({
    required this.id,
    required this.channel,
    required this.newValue,
    this.status = 'pending',
    this.target = 'primary',
    this.expiresAt,
    this.attempts = 0,
    this.maxAttempts = 0,
    this.canResendAt,
  });

  final int id;
  final String channel;

  /// The address being moved to. The current one is untouched until this is
  /// verified.
  final String newValue;

  final String status;

  /// `primary` or `alternate`.
  final String target;

  final DateTime? expiresAt;
  final int attempts;
  final int maxAttempts;
  final DateTime? canResendAt;

  factory ContactChangeRequest.fromJson(Map<String, dynamic> json) =>
      ContactChangeRequest(
        id: Json.integer(json['id']),
        channel: Json.str(json['channel']),
        newValue: Json.str(json['new_value'] ?? json['value']),
        status: Json.str(json['status'], 'pending'),
        target: Json.str(json['target'], 'primary'),
        expiresAt: Formatters.parseDate(json['expires_at']),
        attempts: Json.integer(json['attempts']),
        maxAttempts: Json.integer(json['max_attempts']),
        canResendAt: Formatters.parseDate(json['can_resend_at']),
      );

  bool get isOpen => status == 'pending';

  bool get hasExpired {
    final at = expiresAt;
    return at != null && at.isBefore(DateTime.now());
  }

  /// Guesses left before the request has to be restarted.
  int get attemptsLeft =>
      maxAttempts > 0 && maxAttempts > attempts ? maxAttempts - attempts : 0;
}

/// What the operator currently allows.
class ContactPolicy {
  const ContactPolicy({
    this.otpVerification = true,
    this.allowEmailChange = true,
    this.allowMobileChange = true,
    this.allowAlternateContacts = true,
    this.maxAlternatesPerChannel = 1,
    this.changeTtlMinutes = 15,
    this.resendSeconds = 60,
    this.maxResends = 3,
    this.contactLocked = false,
  });

  /// When off, a change applies immediately without a code. The screen must
  /// not promise a code that will never arrive.
  final bool otpVerification;

  final bool allowEmailChange;
  final bool allowMobileChange;
  final bool allowAlternateContacts;
  final int maxAlternatesPerChannel;
  final int changeTtlMinutes;
  final int resendSeconds;
  final int maxResends;

  /// An administrator has frozen this account's contact section. Distinct from
  /// the two allow flags, which are platform-wide.
  final bool contactLocked;

  factory ContactPolicy.fromJson(Map<String, dynamic> json) => ContactPolicy(
        otpVerification: Json.boolean(json['otp_verification'], true),
        allowEmailChange: Json.boolean(json['allow_email_change'], true),
        allowMobileChange: Json.boolean(json['allow_mobile_change'], true),
        allowAlternateContacts: Json.boolean(json['allow_alternate_contacts'], true),
        maxAlternatesPerChannel: Json.integer(json['max_alternates_per_channel'], 1),
        changeTtlMinutes: Json.integer(json['change_ttl_minutes'], 15),
        resendSeconds: Json.integer(json['resend_seconds'], 60),
        maxResends: Json.integer(json['max_resends'], 3),
        contactLocked: Json.boolean(json['contact_locked']),
      );

  /// Whether this channel may be changed at all right now.
  bool canChange(String channel) {
    if (contactLocked) return false;
    return channel == 'email' ? allowEmailChange : allowMobileChange;
  }
}
