import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// The extended creator profile behind `GET /creators/{id}/fields` and
/// `GET /me/creator/fields`.
///
/// Every label, icon and colour on these objects is sent by the server, which
/// resolves them from admin-managed taxonomies. Nothing here declares a skill
/// name, a credential kind or a weekday label of its own — an operator adding
/// a category needs no app release, which is the whole point of the taxonomy.
class CreatorFields {
  const CreatorFields({
    this.details = const CreatorDetails(),
    this.terms = const {},
    this.credentials = const [],
    this.experience = const [],
    this.workingHours = const WorkingWeek(),
    this.faqs = const [],
  });

  final CreatorDetails details;

  /// Section name (`skills`, `interests`, `professions`, `languages`,
  /// `service_areas`, `service_cities`) to the terms the creator holds there.
  ///
  /// Read as a map rather than fixed fields so a section the server adds later
  /// still arrives and can be rendered generically.
  final Map<String, List<ProfileTerm>> terms;

  final List<Credential> credentials;
  final List<ExperienceEntry> experience;
  final WorkingWeek workingHours;
  final List<CreatorFaq> faqs;

  factory CreatorFields.fromJson(Map<String, dynamic> json) {
    final rawTerms = Json.map(json['terms']);
    final terms = <String, List<ProfileTerm>>{};

    rawTerms.forEach((section, value) {
      final items = Json.list(value, ProfileTerm.fromJson);
      if (items.isNotEmpty) terms[section] = items;
    });

    return CreatorFields(
      details: CreatorDetails.fromJson(Json.map(json['details'])),
      terms: terms,
      credentials: Json.list(json['credentials'], Credential.fromJson),
      experience: Json.list(json['experience'], ExperienceEntry.fromJson),
      workingHours: WorkingWeek.fromJson(Json.map(json['working_hours'])),
      faqs: Json.list(json['faqs'], CreatorFaq.fromJson),
    );
  }

  /// Whether there is anything at all to render, so a caller can skip the
  /// whole block rather than drawing a run of empty headings.
  bool get isEmpty =>
      terms.isEmpty &&
      credentials.isEmpty &&
      experience.isEmpty &&
      faqs.isEmpty &&
      !workingHours.hasAny &&
      details.isEmpty;

  /// Credentials grouped by their kind, in the order the server sent them.
  ///
  /// The kind is a taxonomy term (education, certification, award, …), so this
  /// groups on whatever arrives instead of switching over known values.
  Map<String, List<Credential>> get credentialsByKind {
    final grouped = <String, List<Credential>>{};

    for (final item in credentials) {
      grouped.putIfAbsent(item.kindLabel, () => <Credential>[]).add(item);
    }

    return grouped;
  }
}

/// Scalar profile details that live on the creator row itself.
class CreatorDetails {
  const CreatorDetails({
    this.introVideoUrl,
    this.introVideoThumbnailUrl,
    this.mediaKitUrl,
    this.resumeUrl,
    this.address = const CreatorAddress(),
    this.timezone,
    this.careerStartYear,
    this.yearsOfExperience,
    this.experienceLevel,
    this.experienceLevelLabel,
  });

  final String? introVideoUrl;
  final String? introVideoThumbnailUrl;
  final String? mediaKitUrl;
  final String? resumeUrl;
  final CreatorAddress address;
  final String? timezone;
  final int? careerStartYear;
  final int? yearsOfExperience;
  final String? experienceLevel;
  final String? experienceLevelLabel;

  factory CreatorDetails.fromJson(Map<String, dynamic> json) => CreatorDetails(
        introVideoUrl: Json.strOrNull(json['intro_video_url']),
        introVideoThumbnailUrl: Json.strOrNull(json['intro_video_thumbnail_url']),
        mediaKitUrl: Json.strOrNull(json['media_kit_url']),
        resumeUrl: Json.strOrNull(json['resume_url']),
        address: CreatorAddress.fromJson(Json.map(json['address'])),
        timezone: Json.strOrNull(json['timezone']),
        careerStartYear: json['career_start_year'] == null
            ? null
            : Json.integer(json['career_start_year']),
        yearsOfExperience: json['years_of_experience'] == null
            ? null
            : Json.integer(json['years_of_experience']),
        experienceLevel: Json.strOrNull(json['experience_level']),
        experienceLevelLabel: Json.strOrNull(json['experience_level_label']),
      );

  bool get isEmpty =>
      introVideoUrl == null &&
      mediaKitUrl == null &&
      resumeUrl == null &&
      yearsOfExperience == null &&
      address.isEmpty;

  String? get experienceLabel {
    final years = yearsOfExperience;
    final level = experienceLevelLabel;

    if (years == null && level == null) return null;
    if (years == null) return level;
    if (level == null) return '$years ${years == 1 ? 'year' : 'years'}';

    return '$level · $years ${years == 1 ? 'year' : 'years'}';
  }
}

/// The creator's address.
///
/// The street lines and PIN code are withheld by the server unless the creator
/// marked them public, so a null here means "not shared", not "not set".
class CreatorAddress {
  const CreatorAddress({
    this.line1,
    this.line2,
    this.city,
    this.state,
    this.country,
    this.pinCode,
    this.isPublic = false,
  });

  final String? line1;
  final String? line2;
  final String? city;
  final String? state;
  final String? country;
  final String? pinCode;
  final bool isPublic;

  factory CreatorAddress.fromJson(Map<String, dynamic> json) => CreatorAddress(
        line1: Json.strOrNull(json['line1']),
        line2: Json.strOrNull(json['line2']),
        city: Json.strOrNull(json['city']),
        state: Json.strOrNull(json['state']),
        country: Json.strOrNull(json['country']),
        pinCode: Json.strOrNull(json['pin_code']),
        isPublic: Json.boolean(json['is_public']),
      );

  bool get isEmpty => city == null && state == null && country == null && line1 == null;

  /// One line, skipping whatever was not shared.
  String get summary => [line1, line2, city, state, country, pinCode]
      .whereType<String>()
      .where((part) => part.isNotEmpty)
      .join(', ');
}

/// A taxonomy term the creator holds in one profile section — a skill, an
/// interest, a profession, a language, a service area.
class ProfileTerm {
  const ProfileTerm({
    required this.value,
    required this.label,
    this.section = '',
    this.taxonomyKey = '',
    this.description = '',
    this.icon,
    this.color,
    this.isPrimary = false,
  });

  final String value;
  final String label;
  final String section;
  final String taxonomyKey;
  final String description;
  final String? icon;
  final String? color;

  /// The one the creator nominated as their main skill / profession.
  final bool isPrimary;

  factory ProfileTerm.fromJson(Map<String, dynamic> json) => ProfileTerm(
        value: Json.str(json['value']),
        label: Json.str(json['label']),
        section: Json.str(json['section']),
        taxonomyKey: Json.str(json['taxonomy_key']),
        description: Json.str(json['description']),
        icon: Json.strOrNull(json['icon']),
        color: Json.strOrNull(json['color']),
        isPrimary: Json.boolean(json['is_primary']),
      );
}

/// Education, a certification, or an award (`creator_credentials`).
///
/// One class rather than three: the rows differ only by `kind`, which is a
/// taxonomy term carrying its own label, icon and colour.
class Credential {
  const Credential({
    required this.id,
    required this.kind,
    required this.title,
    this.kindLabel = '',
    this.kindIcon,
    this.kindColor,
    this.dateLabel = '',
    this.startedOn,
    this.endedOn,
    this.issuer,
    this.fieldOfStudy,
    this.grade,
    this.credentialId,
    this.credentialUrl,
    this.mediaUrl,
    this.description = '',
    this.isCurrent = false,
    this.isExpired = false,
    this.isVerified = false,
  });

  final int id;
  final String kind;
  final String kindLabel;
  final String? kindIcon;
  final String? kindColor;
  final String title;

  /// The VERB the kind reads with — "Studied", "Issued", "Received" — not a
  /// date. It comes off the credential-type term's metadata so a degree and a
  /// certificate word themselves correctly without a switch here. Combine it
  /// with [period] to get the full line.
  final String dateLabel;

  final DateTime? startedOn;
  final DateTime? endedOn;

  final String? issuer;
  final String? fieldOfStudy;
  final String? grade;
  final String? credentialId;
  final String? credentialUrl;
  final String? mediaUrl;
  final String description;
  final bool isCurrent;
  final bool isExpired;

  /// Checked by the platform rather than self-declared.
  final bool isVerified;

  factory Credential.fromJson(Map<String, dynamic> json) => Credential(
        id: Json.integer(json['id']),
        kind: Json.str(json['kind']),
        kindLabel: Json.str(json['kind_label']),
        kindIcon: Json.strOrNull(json['kind_icon']),
        kindColor: Json.strOrNull(json['kind_color']),
        title: Json.str(json['title']),
        dateLabel: Json.str(json['date_label']),
        startedOn: Formatters.parseDate(json['started_on']),
        endedOn: Formatters.parseDate(json['ended_on']),
        issuer: Json.strOrNull(json['issuer']),
        fieldOfStudy: Json.strOrNull(json['field_of_study']),
        grade: Json.strOrNull(json['grade']),
        credentialId: Json.strOrNull(json['credential_id']),
        credentialUrl: Json.strOrNull(json['credential_url']),
        mediaUrl: Json.strOrNull(json['media_url']),
        description: Json.str(json['description']),
        isCurrent: Json.boolean(json['is_current']),
        isExpired: Json.boolean(json['is_expired']),
        isVerified: Json.boolean(json['is_verified']),
      );

  /// "NID Ahmedabad · Communication Design" — whichever parts exist.
  String get subtitle => [issuer, fieldOfStudy, grade]
      .whereType<String>()
      .where((part) => part.isNotEmpty)
      .join(' · ');

  /// "Studied 2014 – 2018", "Received Mar 2022", "Studied 2019 – Present".
  ///
  /// The verb comes from the taxonomy and the dates from the row, so a kind an
  /// operator adds tomorrow words itself correctly with no change here. Falls
  /// back to whichever half exists rather than rendering a dangling verb.
  String get period {
    final from = startedOn;
    final to   = endedOn;

    String range;

    if (from == null && to == null) {
      range = '';
    } else if (isCurrent) {
      range = '${Formatters.monthYear(from)} – Present';
    } else if (from != null && to != null) {
      range = '${Formatters.monthYear(from)} – ${Formatters.monthYear(to)}';
    } else {
      range = Formatters.monthYear(from ?? to);
    }

    if (range.isEmpty) return dateLabel;
    if (dateLabel.isEmpty) return range;

    return '$dateLabel $range';
  }
}

/// Work history or a previous brand collaboration (`creator_experience`).
class ExperienceEntry {
  const ExperienceEntry({
    required this.id,
    required this.organisation,
    this.kind = '',
    this.kindLabel = '',
    this.kindIcon,
    this.kindColor,
    this.organisationLogoUrl,
    this.organisationUrl,
    this.role,
    this.location,
    this.startedOn,
    this.endedOn,
    this.isCurrent = false,
    this.description = '',
    this.referenceUrl,
    this.isPlatformVerified = false,
    this.isVerified = false,
    this.isFeatured = false,
  });

  final int id;
  final String kind;
  final String kindLabel;
  final String? kindIcon;
  final String? kindColor;
  final String organisation;
  final String? organisationLogoUrl;
  final String? organisationUrl;
  final String? role;
  final String? location;
  final DateTime? startedOn;
  final DateTime? endedOn;
  final bool isCurrent;
  final String description;
  final String? referenceUrl;

  /// Backed by a completed booking on this platform, not just claimed. This is
  /// the distinction a brand actually cares about when reading work history.
  final bool isPlatformVerified;

  final bool isVerified;
  final bool isFeatured;

  factory ExperienceEntry.fromJson(Map<String, dynamic> json) => ExperienceEntry(
        id: Json.integer(json['id']),
        kind: Json.str(json['kind']),
        kindLabel: Json.str(json['kind_label']),
        kindIcon: Json.strOrNull(json['kind_icon']),
        kindColor: Json.strOrNull(json['kind_color']),
        organisation: Json.str(json['organisation']),
        organisationLogoUrl: Json.strOrNull(json['organisation_logo_url']),
        organisationUrl: Json.strOrNull(json['organisation_url']),
        role: Json.strOrNull(json['role']),
        location: Json.strOrNull(json['location']),
        startedOn: Formatters.parseDate(json['started_on']),
        endedOn: Formatters.parseDate(json['ended_on']),
        isCurrent: Json.boolean(json['is_current']),
        description: Json.str(json['description']),
        referenceUrl: Json.strOrNull(json['reference_url']),
        isPlatformVerified: Json.boolean(json['is_platform_verified']),
        isVerified: Json.boolean(json['is_verified']),
        isFeatured: Json.boolean(json['is_featured']),
      );

  /// "Jun 2018 – Mar 2021" / "Jun 2018 – Present".
  String get period {
    final from = startedOn;
    if (from == null) return isCurrent ? 'Present' : '';

    final start = Formatters.monthYear(from);
    if (isCurrent) return '$start – Present';

    final to = endedOn;
    return to == null ? start : '$start – ${Formatters.monthYear(to)}';
  }
}

/// A creator-authored question and answer (`creator_faqs`).
class CreatorFaq {
  const CreatorFaq({required this.id, required this.question, required this.answer});

  final int id;
  final String question;
  final String answer;

  factory CreatorFaq.fromJson(Map<String, dynamic> json) => CreatorFaq(
        id: Json.integer(json['id']),
        question: Json.str(json['question']),
        answer: Json.str(json['answer']),
      );
}

/// The creator's recurring weekly availability (`creator_working_hours`).
///
/// Distinct from the availability calendar, which is per-date. This is "I work
/// Monday to Friday, 10:00–18:00" and does not change week to week.
class WorkingWeek {
  const WorkingWeek({this.timezone, this.days = const []});

  final String? timezone;
  final List<WorkingDay> days;

  factory WorkingWeek.fromJson(Map<String, dynamic> json) => WorkingWeek(
        timezone: Json.strOrNull(json['timezone']),
        days: Json.list(json['week'], WorkingDay.fromJson),
      );

  /// The server always sends all seven days, so "has anything to show" means
  /// at least one day the creator actually set.
  bool get hasAny => days.any((day) => day.isSet);
}

class WorkingDay {
  const WorkingDay({
    required this.weekday,
    required this.label,
    this.shortLabel = '',
    this.isSet = false,
    this.isWorking = false,
    this.slots = const [],
  });

  final int weekday;
  final String label;
  final String shortLabel;

  /// The creator has said something about this day. A day that is not set is
  /// unknown, which is different from a day they marked as off.
  final bool isSet;

  final bool isWorking;
  final List<WorkingSlot> slots;

  factory WorkingDay.fromJson(Map<String, dynamic> json) => WorkingDay(
        weekday: Json.integer(json['weekday']),
        label: Json.str(json['label']),
        shortLabel: Json.str(json['short_label']),
        isSet: Json.boolean(json['is_set']),
        isWorking: Json.boolean(json['is_working']),
        slots: Json.list(json['slots'], WorkingSlot.fromJson),
      );

  String get summary {
    if (!isSet) return 'Not set';
    if (!isWorking || slots.isEmpty) return 'Unavailable';

    return slots.map((slot) => slot.label).join(', ');
  }
}

class WorkingSlot {
  const WorkingSlot({required this.startsAt, required this.endsAt, this.isWorking = true});

  final String startsAt;
  final String endsAt;
  final bool isWorking;

  factory WorkingSlot.fromJson(Map<String, dynamic> json) => WorkingSlot(
        startsAt: Json.str(json['starts_at']),
        endsAt: Json.str(json['ends_at']),
        isWorking: Json.boolean(json['is_working'], true),
      );

  /// "10:00 – 18:00", trimming the seconds MySQL sends on a TIME column.
  String get label => '${_short(startsAt)} – ${_short(endsAt)}';

  static String _short(String time) {
    final parts = time.split(':');
    return parts.length >= 2 ? '${parts[0]}:${parts[1]}' : time;
  }
}

/// The share sheet payload behind `GET /creators/{id}/share`.
///
/// The URL is derived from the creator's handle on the server, so there is no
/// second identifier to keep in step and nothing here needs to build a link.
class CreatorShare {
  const CreatorShare({
    required this.creatorId,
    required this.url,
    this.handle = '',
    this.title = '',
    this.text = '',
    this.qr,
    this.channels = const [],
    this.isLive = true,
  });

  final int creatorId;
  final String handle;

  /// The public profile URL. Also the QR payload.
  final String url;

  final String title;

  /// Pre-composed share text — "Aanya Kapoor — Lifestyle creator on Zusty".
  final String text;

  final ShareQr? qr;

  /// Destinations the operator has enabled, from the `share_channel` taxonomy.
  /// Sent so the sheet is admin-configurable rather than a hardcoded row of
  /// buttons.
  final List<ProfileTerm> channels;

  /// False when the profile is not currently public — the sheet should say so
  /// rather than handing out a link that 404s.
  final bool isLive;

  factory CreatorShare.fromJson(Map<String, dynamic> json) => CreatorShare(
        creatorId: Json.integer(json['creator_id']),
        handle: Json.str(json['handle']),
        url: Json.str(json['url']),
        title: Json.str(json['title']),
        text: Json.str(json['text']),
        qr: json['qr'] == null ? null : ShareQr.fromJson(Json.map(json['qr'])),
        channels: Json.list(json['channels'], ProfileTerm.fromJson),
        isLive: Json.boolean(json['is_live'], true),
      );
}

/// A QR code for the profile URL, rendered server-side.
///
/// [svg] is present by default; the base64 data URI and the raw module matrix
/// are opt-in via the `qr` query parameter, because asking for all three
/// returns roughly 52 KB.
class ShareQr {
  const ShareQr({this.text = '', this.modules = 0, this.svg, this.dataUri, this.matrix});

  final String text;

  /// Width of the code in modules, for sizing the rendering box.
  final int modules;

  final String? svg;
  final String? dataUri;
  final List<List<int>>? matrix;

  factory ShareQr.fromJson(Map<String, dynamic> json) {
    final raw = json['matrix'];
    List<List<int>>? matrix;

    if (raw is List) {
      matrix = raw
          .whereType<List>()
          .map((row) => row.map((cell) => Json.integer(cell)).toList())
          .toList();
    }

    return ShareQr(
      text: Json.str(json['text']),
      modules: Json.integer(json['modules']),
      svg: Json.strOrNull(json['svg']),
      dataUri: Json.strOrNull(json['data_uri']),
      matrix: matrix,
    );
  }
}
