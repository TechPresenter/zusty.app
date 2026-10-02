import '../core/utils/json.dart';
import 'user.dart' show KycStatus;

/// The KYC and business-verification payload from `GET /me/kyc`.
///
/// Brands complete a business profile and creators an identity profile; which
/// one a user gets follows their account type, decided server-side, so the app
/// never has to choose — it renders whatever `kind` comes back.
///
/// Every field added for the verification centre (review status per slot,
/// re-upload requests, the blue tick, consent, preview links) is read with a
/// default, so this parses the older payload too and simply shows less.
class KycOverview {
  const KycOverview({
    required this.profile,
    this.details = const {},
    this.requirements = const [],
    this.documents = const [],
    this.completeness = 0,
    this.canSubmit = false,
    this.timeline = const [],
  });

  final KycProfile profile;

  /// The brand or creator detail block, keyed by column name. Kept as a map
  /// because the two kinds have entirely different fields and the form is
  /// built from a field spec rather than a fixed class.
  ///
  /// Identity numbers only ever arrive masked (`pan_masked`,
  /// `aadhaar_masked`, …); see [maskedValue].
  final Map<String, dynamic> details;

  final List<KycRequirement> requirements;
  final List<KycDocument> documents;
  final int completeness;

  /// True when every mandatory slot is filled, the details are complete and
  /// the profile is editable. The server decides; the app only explains.
  final bool canSubmit;

  final List<KycEvent> timeline;

  factory KycOverview.fromJson(Map<String, dynamic> json) {
    final profileJson = Json.map(json['profile']);

    return KycOverview(
      profile: KycProfile.fromJson(profileJson),
      details: Json.map(json['details']),
      requirements: Json.list(json['requirements'], KycRequirement.fromJson),
      documents: Json.list(json['documents'], KycDocument.fromJson),
      completeness: Json.integer(json['completeness']),
      canSubmit: Json.boolean(json['can_submit'] ?? profileJson['can_submit']),
      // The server leaves internal review events out of the applicant's
      // trail; this is the same rule applied again, so an older server's
      // payload cannot show them either.
      timeline: Json.list(json['timeline'], KycEvent.fromJson)
          .where((event) => event.isUserFacing)
          .toList(growable: false),
    );
  }

  bool get isBrand => profile.kind == 'brand';

  /// The brand's company type, which decides whether a CIN and a
  /// Certificate of Incorporation are needed.
  String? get companyType => Json.strOrNull(details['company_type']);

  /// Current documents in [slot]. Superseded versions stay on the server for
  /// the reviewer's history but are not the user's to act on.
  List<KycDocument> documentsFor(String slot) => documents
      .where((doc) => doc.slot == slot && doc.isCurrent)
      .toList(growable: false);

  /// Whether [requirement] has to be filled before submitting.
  ///
  /// The Certificate of Incorporation follows the company type: only
  /// companies registered with the MCA have a CIN to show. Once the brand has
  /// picked a type, that decides; before then the server's rule stands.
  bool isRequired(KycRequirement requirement) {
    if (requirement.slot == KycSlots.incorporationCertificate &&
        companyType != null) {
      return KycCompanyTypes.requiresCin(companyType);
    }
    return requirement.isRequired;
  }

  List<KycRequirement> get requiredRequirements =>
      requirements.where(isRequired).toList(growable: false);

  List<KycRequirement> get optionalRequirements =>
      requirements.where((r) => !isRequired(r)).toList(growable: false);

  /// Mandatory slots still waiting on a usable file — what the submit CTA
  /// blocks on. A rejected file does not count; it has to be replaced.
  List<KycRequirement> get missingRequirements => requiredRequirements
      .where((req) => switch (slotState(req)) {
            KycSlotState.missing || KycSlotState.rejected => true,
            _ => false,
          })
      .toList(growable: false);

  /// Whether the reviewer asked for [requirement] to be uploaded again and
  /// that has not happened yet.
  ///
  /// The request itself comes from the per-slot flag when the server sends
  /// one, and the profile-level list otherwise. Either way it is answered as
  /// soon as the slot holds a fresh copy — a current, non-rejected file
  /// uploaded at or after the request (which stamps `reviewed_at`). That is
  /// the same test the server's `can_submit` applies, so the slot stops
  /// saying "Re-upload requested" the moment the replacement lands instead of
  /// waiting for the next submission to clear the list.
  bool isResubmitRequested(KycRequirement requirement) {
    final requested = requirement.resubmitRequested ??
        profile.resubmitSlots.contains(requirement.slot);

    return requested && !hasFreshUpload(requirement.slot);
  }

  /// Whether [slot] has had a usable upload since the reviewer's last word.
  bool hasFreshUpload(String slot) {
    final since = _parseTime(profile.reviewedAt);

    return documentsFor(slot).any((doc) {
      if (doc.isRejected) return false;
      if (since == null) return true;

      final uploaded = _parseTime(doc.uploadedAt);
      return uploaded != null && !uploaded.isBefore(since);
    });
  }

  /// Server timestamps are `YYYY-MM-DD HH:MM:SS` in one clock, so comparing
  /// them as parsed is enough.
  static DateTime? _parseTime(String? raw) =>
      raw == null ? null : DateTime.tryParse(raw.trim());

  /// Where one slot stands, for its status chip.
  KycSlotState slotState(KycRequirement requirement) {
    if (isResubmitRequested(requirement)) return KycSlotState.reuploadRequested;

    switch (requirement.reviewStatus) {
      case 'missing':
        return KycSlotState.missing;
      case 'approved':
        return KycSlotState.approved;
      case 'rejected':
        return KycSlotState.rejected;
      case 'pending' || 'uploaded' || 'submitted':
        return KycSlotState.uploaded;
    }

    // Older payloads: work it out from the files themselves.
    final files = documentsFor(requirement.slot);
    if (files.isEmpty) {
      return requirement.isSatisfied
          ? KycSlotState.uploaded
          : KycSlotState.missing;
    }
    if (files.any((doc) => doc.isRejected)) return KycSlotState.rejected;
    if (files.every((doc) => doc.isApproved)) return KycSlotState.approved;
    return KycSlotState.uploaded;
  }

  /// The reviewer's reason for turning a slot down, from the slot or, failing
  /// that, its rejected file.
  String? rejectionReasonFor(KycRequirement requirement) {
    if (requirement.rejectionReason != null) return requirement.rejectionReason;

    for (final doc in documentsFor(requirement.slot)) {
      if (doc.isRejected && doc.rejectionReason != null) {
        return doc.rejectionReason;
      }
    }
    return null;
  }

  /// Whether [requirement] can take a new file right now.
  ///
  /// Normally only while the profile is editable, but a slot the reviewer
  /// asked to re-upload stays open even while the rest is locked.
  bool canUploadTo(KycRequirement requirement) =>
      profile.isEditable || isResubmitRequested(requirement);

  /// The masked rendering of a sensitive detail, e.g. `XXXX XXXX 1234`.
  ///
  /// Reads the dedicated `*_masked` key first. Older payloads put the masked
  /// value under the field's own name, so that is accepted too — but only if
  /// it actually looks masked, so a plain value is never shown as "on file".
  String? maskedValue(String field) {
    final key = KycSensitive.maskedKeyFor(field);
    final masked = Json.strOrNull(details[key]);
    if (masked != null) return masked;

    final raw = Json.strOrNull(details[field]);
    return raw != null && KycSensitive.looksMasked(raw) ? raw : null;
  }
}

/// How one document slot stands, as the user sees it.
enum KycSlotState {
  missing('Missing'),
  uploaded('Uploaded'),
  approved('Approved'),
  rejected('Rejected'),
  reuploadRequested('Re-upload requested');

  const KycSlotState(this.label);

  final String label;

  /// The slot needs the user to do something.
  bool get needsAttention =>
      this == KycSlotState.rejected || this == KycSlotState.reuploadRequested;
}

/// Slot codes the app gives special treatment.
abstract final class KycSlots {
  static const aadhaarFront = 'aadhaar_front';
  static const aadhaarBack = 'aadhaar_back';
  static const panCard = 'pan_card';
  static const gstCertificate = 'gst_certificate';
  static const incorporationCertificate = 'incorporation_certificate';

  static bool isAadhaar(String slot) =>
      slot == aadhaarFront || slot == aadhaarBack;
}

/// Company types and whether they carry a Corporate Identification Number.
///
/// Mirrors the `company_type` taxonomy: private and public limited companies
/// and one person companies are registered with the MCA and have a CIN. An
/// LLP has an LLPIN instead, and proprietorships, partnerships, trusts and
/// societies have neither — they prove registration another way.
abstract final class KycCompanyTypes {
  static const withCin = {
    'private_limited',
    'public_limited',
    'opc',
    'one_person_company',
  };

  static bool requiresCin(String? type) => withCin.contains(type);
}

/// The encrypted detail fields: never prefilled, never sent back unchanged.
abstract final class KycSensitive {
  /// The `*_masked` key the API uses for each sensitive field.
  static String maskedKeyFor(String field) => switch (field) {
        'aadhaar_number' => 'aadhaar_masked',
        'pan_number' => 'pan_masked',
        'gst_number' => 'gst_masked',
        'cin_number' => 'cin_masked',
        _ => '${field}_masked',
      };

  static final _maskRun = RegExp(r'[Xx*•●]{2,}');

  /// True for `XXXXXX234F`, `**** 1234`, `•••• 1234` and the like.
  static bool looksMasked(String value) => _maskRun.hasMatch(value);
}

/// Status, badges and review outcome for one KYC profile.
class KycProfile {
  const KycProfile({
    this.id = 0,
    this.reference = '',
    this.kind = 'brand',
    this.status = 'draft',
    this.statusLabel = 'Not started',
    this.statusTone = 'neutral',
    this.completeness = 0,
    this.submissionCount = 0,
    this.submittedAt,
    this.reviewedAt,
    this.approvedAt,
    this.expiresOn,
    this.rejectionReason,
    this.infoRequested,
    this.isEditable = true,
    this.badges = const [],
    this.resubmitSlots = const [],
    this.badgeStatus = 'none',
    this.badgeGrantedAt,
    this.badgeRevokeReason,
    this.consentAt,
    this.consentVersion,
    this.consentVersionRequired,
    this.consentText,
    this.processingConsentAt,
    this.processingConsentRequired = false,
  });

  final int id;
  final String reference;

  /// `brand` or `creator`.
  final String kind;

  /// One of draft, submitted, under_review, info_required, approved,
  /// rejected, suspended, expired.
  final String status;

  /// The server's own wording for [status] — shown as-is when present.
  final String statusLabel;

  /// Admin-supplied chip tone, e.g. `success`, `warning`, `error`.
  final String statusTone;

  final int completeness;
  final int submissionCount;
  final String? submittedAt;
  final String? reviewedAt;
  final String? approvedAt;
  final String? expiresOn;

  /// Why the reviewer rejected the profile.
  final String? rejectionReason;

  /// What the reviewer asked the user to supply.
  final String? infoRequested;

  /// False while the profile sits with a reviewer — the forms lock.
  final bool isEditable;

  final List<KycBadge> badges;

  /// Document slots the reviewer asked to have uploaded again.
  final List<String> resubmitSlots;

  /// The blue tick: `none`, `granted` or `revoked`. Granted by a person,
  /// separately from — and only after — approval.
  final String badgeStatus;
  final String? badgeGrantedAt;
  final String? badgeRevokeReason;

  /// When the user last consented to document processing, and under which
  /// version of the notice.
  final String? consentAt;
  final String? consentVersion;

  /// The notice version a submission has to consent to now.
  final String? consentVersionRequired;

  /// The consent wording, when the server supplies its own.
  final String? consentText;

  /// When the user agreed to their documents and details being processed at
  /// all — asked once, before the first upload or details save, and kept
  /// apart from the consent each submission needs.
  final String? processingConsentAt;

  /// True until that agreement is on record for the current notice version.
  /// While it is, the first upload or details save must carry `consent`.
  final bool processingConsentRequired;

  factory KycProfile.fromJson(Map<String, dynamic> json) => KycProfile(
        id: Json.integer(json['id']),
        reference: Json.str(json['reference']),
        kind: Json.str(json['kind'], 'brand'),
        status: Json.str(json['status'], 'draft'),
        statusLabel: Json.str(json['status_label'], 'Not started'),
        statusTone: Json.str(json['status_tone'], 'neutral'),
        completeness: Json.integer(json['completeness']),
        submissionCount: Json.integer(json['submission_count']),
        submittedAt: Json.strOrNull(json['submitted_at']),
        reviewedAt: Json.strOrNull(json['reviewed_at']),
        approvedAt: Json.strOrNull(json['approved_at']),
        expiresOn: Json.strOrNull(json['expires_on']),
        rejectionReason: Json.strOrNull(json['rejection_reason']),
        infoRequested: Json.strOrNull(json['info_requested']),
        isEditable: Json.boolean(json['is_editable'] ?? json['editable'], true),
        badges: Json.list(json['badges'], KycBadge.fromJson),
        resubmitSlots: Json.strings(json['resubmit_slots']),
        // `badge_status` is the record; `has_blue_tick` is accepted on its own
        // in case a payload carries only the flag.
        badgeStatus: Json.strOrNull(json['badge_status'])?.toLowerCase() ??
            (Json.boolean(json['has_blue_tick']) ? 'granted' : 'none'),
        badgeGrantedAt: Json.strOrNull(json['badge_granted_at']),
        badgeRevokeReason: Json.strOrNull(json['badge_revoke_reason']),
        consentAt: Json.strOrNull(json['consent_at']),
        consentVersion: Json.strOrNull(json['consent_version']),
        consentVersionRequired:
            Json.strOrNull(json['consent_version_required']),
        consentText: Json.strOrNull(json['consent_text']),
        processingConsentAt: Json.strOrNull(json['processing_consent_at']),
        // Absent from servers that predate the processing consent, which do
        // not ask for it; the server's 422 is still handled if one does.
        processingConsentRequired:
            Json.boolean(json['processing_consent_required']),
      );

  /// [status] as the app's lifecycle enum.
  KycStatus get state => KycStatus.fromString(status);

  /// The server's label when it sent one, the app's own otherwise.
  String get displayLabel =>
      statusLabel.trim().isNotEmpty ? statusLabel : state.label;

  bool get isApproved => state.isApproved;
  bool get isUnderReview => state.isInReview;
  bool get needsAction => state.needsAction;

  bool get hasBlueTick => badgeStatus == 'granted';
  bool get isBlueTickRevoked => badgeStatus == 'revoked';

  /// The one line that explains the current state, when there is one.
  String? get reviewerNote => switch (state) {
        KycStatus.actionNeeded => infoRequested ?? rejectionReason,
        KycStatus.rejected || KycStatus.suspended => rejectionReason,
        _ => null,
      };
}

/// A verification badge the profile has earned.
class KycBadge {
  const KycBadge({required this.code, required this.label, this.icon});

  final String code;
  final String label;
  final String? icon;

  factory KycBadge.fromJson(Map<String, dynamic> json) => KycBadge(
        code: Json.str(json['code']),
        label: Json.str(json['label']),
        icon: Json.strOrNull(json['icon']),
      );
}

/// One document slot in the admin-managed rulebook.
///
/// The whole checklist is configured in Admin → KYC, so adding a document type
/// changes the app without a release.
class KycRequirement {
  const KycRequirement({
    required this.slot,
    required this.label,
    this.description = '',
    this.documentType = '',
    this.isRequired = true,
    this.allowsMultiple = false,
    this.requiresExpiry = false,
    this.allowedFormats = const ['pdf', 'jpg', 'jpeg', 'png'],
    this.maxSizeMb = 10,
    this.documentIds = const [],
    this.isSatisfied = false,
    this.reviewStatus,
    this.rejectionReason,
    this.resubmitRequested,
  });

  final String slot;
  final String label;
  final String description;
  final String documentType;
  final bool isRequired;
  final bool allowsMultiple;

  /// When true the upload sheet asks for an expiry date.
  final bool requiresExpiry;

  final List<String> allowedFormats;
  final int maxSizeMb;
  final List<int> documentIds;
  final bool isSatisfied;

  /// `missing`, `pending`, `approved` or `rejected`; null from older servers,
  /// in which case the state is worked out from the files.
  final String? reviewStatus;

  final String? rejectionReason;

  /// Null when the server does not say — see
  /// [KycOverview.isResubmitRequested].
  final bool? resubmitRequested;

  factory KycRequirement.fromJson(Map<String, dynamic> json) => KycRequirement(
        slot: Json.str(json['slot']),
        label: Json.str(json['label']),
        description: Json.str(json['description']),
        documentType: Json.str(json['document_type']),
        isRequired: Json.boolean(json['is_required'], true),
        allowsMultiple: Json.boolean(json['allows_multiple']),
        requiresExpiry: Json.boolean(json['requires_expiry']),
        allowedFormats: Json.strings(json['allowed_formats']),
        maxSizeMb: Json.integer(json['max_size_mb'], 10),
        documentIds: (json['document_ids'] is List
                ? json['document_ids'] as List
                : const [])
            .map(Json.integer)
            .toList(growable: false),
        isSatisfied: Json.boolean(json['is_satisfied']),
        reviewStatus: Json.strOrNull(json['review_status'])?.toLowerCase(),
        rejectionReason: Json.strOrNull(json['rejection_reason']),
        resubmitRequested: json.containsKey('resubmit_requested')
            ? Json.boolean(json['resubmit_requested'])
            : null,
      );

  /// `PDF, JPG or PNG · up to 10 MB`
  String get constraintLabel {
    final formats = allowedFormats.map((f) => f.toUpperCase()).toList();
    if (formats.isEmpty) return 'Up to $maxSizeMb MB';

    final list = formats.length == 1
        ? formats.first
        : '${formats.take(formats.length - 1).join(', ')} or ${formats.last}';

    return '$list · up to $maxSizeMb MB';
  }
}

/// An uploaded file, as the API presents it. The bytes never sit in the
/// payload — only metadata and short-lived, viewer-bound links.
class KycDocument {
  const KycDocument({
    required this.id,
    required this.slot,
    this.title = '',
    this.documentType = '',
    this.originalName = '',
    this.mimeType = '',
    this.sizeLabel = '',
    this.isImage = false,
    this.isPdf = false,
    this.version = 1,
    this.status = 'pending',
    this.rejectionReason,
    this.isCurrent = true,
    this.expiresOn,
    this.isExpired = false,
    this.uploadedAt,
    this.downloadToken,
    this.previewUrl,
  });

  final int id;
  final String slot;
  final String title;
  final String documentType;
  final String originalName;
  final String mimeType;
  final String sizeLabel;
  final bool isImage;
  final bool isPdf;
  final int version;

  /// `pending`, `approved` or `rejected` — the reviewer's call on this file.
  final String status;
  final String? rejectionReason;

  /// False for a version that has since been replaced.
  final bool isCurrent;

  final String? expiresOn;
  final bool isExpired;
  final String? uploadedAt;

  /// Signed for this viewer and this document only, so the URL cannot be
  /// forwarded to anyone else.
  final String? downloadToken;

  /// A short-lived signed link to the inline preview. Still needs the
  /// bearer token — it is not a public URL.
  final String? previewUrl;

  factory KycDocument.fromJson(Map<String, dynamic> json) {
    final mime = Json.str(json['mime_type']).toLowerCase();
    final name = Json.str(json['original_name']);

    return KycDocument(
      id: Json.integer(json['id']),
      slot: Json.str(json['slot']),
      title: Json.str(json['title']),
      documentType: Json.str(json['document_type']),
      originalName: name,
      mimeType: mime,
      sizeLabel: Json.str(json['size_label']),
      isImage: Json.boolean(json['is_image'], mime.startsWith('image/')),
      isPdf: Json.boolean(
        json['is_pdf'],
        mime == 'application/pdf' || name.toLowerCase().endsWith('.pdf'),
      ),
      version: Json.integer(json['version'], 1),
      status: Json.str(json['status'], 'pending').toLowerCase(),
      rejectionReason: Json.strOrNull(json['rejection_reason']),
      isCurrent: Json.boolean(json['is_current'], true),
      expiresOn: Json.strOrNull(json['expires_on']),
      isExpired: Json.boolean(json['is_expired']),
      uploadedAt: Json.strOrNull(json['uploaded_at']),
      downloadToken: Json.strOrNull(json['download_token']),
      previewUrl: Json.strOrNull(json['preview_url']),
    );
  }

  String get displayName =>
      title.isNotEmpty ? title : (originalName.isNotEmpty ? originalName : slot);

  bool get isApproved => status == 'approved';
  bool get isRejected => status == 'rejected';

  /// Whether the server has handed out a link this viewer can open.
  bool get canPreview => previewUrl != null || downloadToken != null;
}

/// One entry in the audit trail — every KYC action is recorded.
class KycEvent {
  const KycEvent({
    required this.action,
    required this.label,
    this.fromStatus,
    this.toStatus,
    this.note,
    this.actorType = 'system',
    this.actorName,
    this.createdAt,
  });

  final String action;
  final String label;
  final String? fromStatus;
  final String? toStatus;
  final String? note;
  final String actorType;
  final String? actorName;
  final String? createdAt;

  factory KycEvent.fromJson(Map<String, dynamic> json) => KycEvent(
        action: Json.str(json['action']),
        label: Json.str(json['label']),
        fromStatus: Json.strOrNull(json['from_status']),
        toStatus: Json.strOrNull(json['to_status']),
        note: Json.strOrNull(json['note']),
        actorType: Json.str(json['actor_type'], 'system'),
        actorName: Json.strOrNull(json['actor_name']),
        createdAt: Json.strOrNull(json['created_at']),
      );

  /// `You`, `Zusty team` or `System`.
  ///
  /// Never a reviewer's own name, even when a payload carries one: who on
  /// the team handled a case is internal, and naming staff to the people
  /// they review invites pressure and harassment.
  String get actorLabel => switch (actorType) {
        'user' => 'You',
        'admin' => 'Zusty team',
        _ => 'System',
      };

  /// Review-side bookkeeping the applicant has no use for: internal notes,
  /// and a reviewer opening or downloading a file.
  static const _internalActions = {
    'note_added',
    'internal_note',
    'badge_note',
  };

  static const _reviewerOnlyActions = {
    'document_previewed',
    'document_downloaded',
  };

  /// Whether this event belongs on the applicant's own review trail.
  bool get isUserFacing {
    if (_internalActions.contains(action)) return false;
    if (actorType == 'admin' && _reviewerOnlyActions.contains(action)) {
      return false;
    }
    return true;
  }
}
