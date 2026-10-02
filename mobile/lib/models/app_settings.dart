import 'package:flutter/material.dart';

import '../core/config/app_config.dart';
import '../core/utils/json.dart';

/// Parses a `#RRGGBB` or `#AARRGGBB` string from the admin panel.
///
/// Returns null for anything unparseable so callers keep their theme default
/// rather than painting black.
Color? hexColor(String? value) {
  final hex = value?.trim().replaceFirst('#', '');
  if (hex == null || (hex.length != 6 && hex.length != 8)) return null;

  final parsed = int.tryParse(hex.length == 6 ? 'FF$hex' : hex, radix: 16);
  return parsed == null ? null : Color(parsed);
}

/// App identity from `/app/config` → `app`.
///
/// Every field here is editable under Settings → General, and the app used to
/// carry its own compiled copy of each — which is why the support screen showed
/// a different address to the one an operator had configured.
class AppIdentity {
  const AppIdentity({
    this.name = AppConfig.appName,
    this.tagline = AppConfig.tagline,
    this.currency = AppConfig.currencyCode,
    this.currencySymbol = AppConfig.currencySymbol,
    this.locale = 'en',
    this.timezone = 'Asia/Kolkata',
    this.supportEmail = '',
    this.supportPhone = '',
  });

  final String name;
  final String tagline;
  final String currency;
  final String currencySymbol;
  final String locale;
  final String timezone;

  /// Empty when the operator has not set one — callers keep their own default.
  final String supportEmail;
  final String supportPhone;

  factory AppIdentity.fromJson(Map<String, dynamic> json) => AppIdentity(
        name: Json.str(json['name'], AppConfig.appName),
        tagline: Json.str(json['tagline'], AppConfig.tagline),
        currency: Json.str(json['currency'], AppConfig.currencyCode),
        currencySymbol:
            Json.str(json['currency_symbol'], AppConfig.currencySymbol),
        locale: Json.str(json['locale'], 'en'),
        timezone: Json.str(json['timezone'], 'Asia/Kolkata'),
        supportEmail: Json.str(json['support_email']),
        supportPhone: Json.str(json['support_phone']),
      );

  static const empty = AppIdentity();
}

/// Logos and accent colours from `/app/config` → `branding`.
class AppBranding {
  const AppBranding({
    this.logoUrl,
    this.logoDarkUrl,
    this.primaryHex,
    this.secondaryHex,
    this.tertiaryHex,
  });

  final String? logoUrl;
  final String? logoDarkUrl;
  final String? primaryHex;
  final String? secondaryHex;
  final String? tertiaryHex;

  Color? get primary => hexColor(primaryHex);
  Color? get secondary => hexColor(secondaryHex);
  Color? get tertiary => hexColor(tertiaryHex);

  /// The logo for [brightness], falling back to the light one when the operator
  /// has uploaded only a single mark.
  String? logoFor(Brightness brightness) =>
      brightness == Brightness.dark ? (logoDarkUrl ?? logoUrl) : logoUrl;

  factory AppBranding.fromJson(Map<String, dynamic> json) => AppBranding(
        logoUrl: Json.strOrNull(json['logo_url']),
        logoDarkUrl: Json.strOrNull(json['logo_dark_url']),
        primaryHex: Json.strOrNull(json['primary_color']),
        secondaryHex: Json.strOrNull(json['secondary_color']),
        tertiaryHex: Json.strOrNull(json['tertiary_color']),
      );

  static const empty = AppBranding();
}

/// The minimum-version gate from `/app/config` → `update`.
///
/// The server compares the `X-Platform` / `X-App-Version` headers this client
/// already sends against the per-platform minimum in Settings → App, so
/// [forceUpdate] is the whole decision — the client does not repeat it.
class UpdateGate {
  const UpdateGate({
    this.forceUpdate = false,
    this.minVersion = '',
    this.latestVersion = '',
    this.storeUrl = '',
    this.message = '',
  });

  final bool forceUpdate;
  final String minVersion;
  final String latestVersion;
  final String storeUrl;
  final String message;

  bool get mustUpdate => forceUpdate;

  /// The store link, but only when it is a real web URL — an empty or
  /// malformed setting must not produce a button that does nothing.
  Uri? get storeUri {
    final uri = Uri.tryParse(storeUrl);
    if (uri == null) return null;
    return (uri.scheme == 'http' || uri.scheme == 'https') ? uri : null;
  }

  factory UpdateGate.fromJson(Map<String, dynamic> json) => UpdateGate(
        forceUpdate: Json.boolean(json['force_update']),
        minVersion: Json.str(json['min_version']),
        latestVersion: Json.str(json['latest_version']),
        storeUrl: Json.str(json['store_url']),
        message: Json.str(json['update_message']),
      );

  static const empty = UpdateGate();
}

/// The maintenance banner from `/app/config` → `maintenance`.
class MaintenanceNotice {
  const MaintenanceNotice({this.enabled = false, this.message = ''});

  final bool enabled;
  final String message;

  factory MaintenanceNotice.fromJson(Map<String, dynamic> json) =>
      MaintenanceNotice(
        enabled: Json.boolean(json['enabled']),
        message: Json.str(json['message']),
      );

  static const empty = MaintenanceNotice();
}

/// Every switch the server resolved for this caller, from `/app/config` →
/// `features`.
///
/// Two sources arrive merged: the settings-backed switches (wallet, referral,
/// counter offers, signup, realtime chat) and every row of `feature_flags`
/// already evaluated against this account's audience, platform, app version and
/// rollout bucket. The client only has to read the answer.
///
/// The named getters default to **on**: a build that has never reached the
/// server, or one talking to an older backend, must behave exactly as it did
/// before these were read rather than hiding half the app.
class FeatureFlags {
  const FeatureFlags([this.values = const {}]);

  final Map<String, bool> values;

  bool isOn(String key, {bool fallback = false}) => values[key] ?? fallback;

  bool get walletEnabled => isOn('wallet_enabled', fallback: true);
  bool get referralEnabled => isOn('referral_enabled', fallback: true);
  bool get counterOffersEnabled => isOn('counter_offers_enabled', fallback: true);
  bool get creatorSignupEnabled => isOn('creator_signup_enabled', fallback: true);
  bool get brandSignupEnabled => isOn('brand_signup_enabled', fallback: true);
  bool get realtimeChatEnabled => isOn('chat_realtime', fallback: true);

  /// Flags with no compiled consumer yet default to **off** — an experiment an
  /// operator switches on must not change a build that cannot render it.
  bool get couponsAtCheckout => isOn('coupons_at_checkout');
  bool get dynamicHomepage => isOn('dynamic_homepage');
  bool get instantPayouts => isOn('instant_payouts');

  factory FeatureFlags.fromJson(dynamic raw) {
    if (raw is! Map) return const FeatureFlags();

    return FeatureFlags({
      for (final entry in raw.entries)
        entry.key.toString(): Json.boolean(entry.value),
    });
  }

  static const empty = FeatureFlags();
}

/// `/app/config` → `ai`: which AI features to offer, as switched in
/// Admin → Settings → AI.
///
/// Unlike [FeatureFlags], everything here defaults to **off**. An AI button
/// the server has not enabled would only ever produce an error, so a build
/// that has not heard from the server, or talks to an older backend, shows
/// none. The server enforces the same switches on every request regardless;
/// this only decides what to draw.
class AiFeatures {
  const AiFeatures({
    this.enabled = false,
    this.bioWriter = false,
    this.campaignBrief = false,
    this.creatorMatch = false,
    this.dailyLimit = 0,
  });

  final bool enabled;
  final bool bioWriter;
  final bool campaignBrief;
  final bool creatorMatch;

  /// Requests one account may make per day, across all features.
  final int dailyLimit;

  factory AiFeatures.fromJson(Map<String, dynamic> json) {
    final enabled = Json.boolean(json['enabled']);

    return AiFeatures(
      enabled: enabled,
      bioWriter: enabled && Json.boolean(json['bio_writer']),
      campaignBrief: enabled && Json.boolean(json['campaign_brief']),
      creatorMatch: enabled && Json.boolean(json['creator_match']),
      dailyLimit: Json.integer(json['daily_limit']),
    );
  }

  static const off = AiFeatures();
}

/// One admin-authored popup from `/app/config` → `popups`.
///
/// The server has already decided this popup is eligible for this account —
/// audience, schedule window and placement filter are applied there. What is
/// left to the client is *when* to show it ([trigger]) and *how often*
/// ([frequency]).
class AppPopup {
  const AppPopup({
    required this.id,
    this.type = 'modal',
    this.title = '',
    this.content = '',
    this.imageUrl,
    this.backgroundHex,
    this.textHex,
    this.ctaLabel,
    this.ctaAction = 'none',
    this.ctaValue,
    this.placement = 'all',
    this.trigger = 'immediate',
    this.triggerValue = 0,
    this.frequency = 'once_per_session',
    this.isDismissible = true,
  });

  final int id;

  /// `modal`, `banner`, `slide_in`, `announcement_bar` or `full_screen`.
  final String type;
  final String title;
  final String content;
  final String? imageUrl;
  final String? backgroundHex;
  final String? textHex;
  final String? ctaLabel;

  /// `none`, `url`, `screen` or `dismiss`.
  final String ctaAction;
  final String? ctaValue;

  /// `all`, `home`, `search`, `creator`, `booking`, `wallet` or `checkout`.
  final String placement;

  /// `immediate`, `delay`, `scroll`, `exit_intent` or `session_count`.
  final String trigger;
  final int triggerValue;

  /// `always`, `once`, `once_per_session` or `once_per_day`.
  final String frequency;
  final bool isDismissible;

  Color? get background => hexColor(backgroundHex);
  Color? get textColor => hexColor(textHex);

  /// True when this popup belongs on [screen], one of the placement names.
  bool showsOn(String screen) => placement == 'all' || placement == screen;

  /// Announcement bars and inline banners sit in the layout; everything else
  /// is presented over it.
  bool get isInline => type == 'announcement_bar' || type == 'banner';

  bool get isSlideIn => type == 'slide_in';

  /// How long to wait before a `delay` popup appears. Clamped so a mistyped
  /// trigger value cannot park a modal two hours into the session.
  Duration get delay => trigger == 'delay'
      ? Duration(seconds: triggerValue.clamp(0, 300))
      : Duration.zero;

  /// How far down the screen has to be scrolled, as a percentage, before a
  /// `scroll` popup fires.
  int get scrollPercent =>
      trigger == 'scroll' ? triggerValue.clamp(1, 100) : 0;

  /// The body with any admin-entered markup removed.
  ///
  /// The panel stores the body as HTML. Rendering it verbatim in a Text widget
  /// would print the tags, and pulling in a full HTML renderer for a two-line
  /// announcement is not worth it.
  String get plainContent => content
      .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
      .replaceAll(RegExp(r'</p>', caseSensitive: false), '\n')
      .replaceAll(RegExp(r'<[^>]*>'), '')
      .replaceAll('&nbsp;', ' ')
      .replaceAll('&amp;', '&')
      .trim();

  factory AppPopup.fromJson(Map<String, dynamic> json) => AppPopup(
        id: Json.integer(json['id']),
        type: Json.str(json['type'], 'modal'),
        title: Json.str(json['title']),
        content: Json.str(json['content']),
        imageUrl: Json.strOrNull(json['image_url']),
        backgroundHex: Json.strOrNull(json['background']),
        textHex: Json.strOrNull(json['text_color']),
        ctaLabel: Json.strOrNull(json['cta_label']),
        ctaAction: Json.str(json['cta_action'], 'none'),
        ctaValue: Json.strOrNull(json['cta_value']),
        placement: Json.str(json['placement'], 'all'),
        trigger: Json.str(json['trigger'], 'immediate'),
        triggerValue: Json.integer(json['trigger_value']),
        frequency: Json.str(json['frequency'], 'once_per_session'),
        isDismissible: Json.boolean(json['is_dismissible'], true),
      );
}

/// Social profiles from `/app/config` → `social`.
class SocialLinks {
  const SocialLinks({
    this.instagram = '',
    this.youtube = '',
    this.linkedin = '',
    this.twitter = '',
    this.facebook = '',
  });

  final String instagram;
  final String youtube;
  final String linkedin;
  final String twitter;
  final String facebook;

  /// The configured profiles, in display order, as `(label, icon-key, url)`.
  /// Networks the operator has left blank are omitted entirely.
  List<(String, String, Uri)> get configured {
    final entries = <(String, String, String)>[
      ('Instagram', 'instagram', instagram),
      ('YouTube', 'youtube', youtube),
      ('LinkedIn', 'linkedin', linkedin),
      ('X', 'twitter', twitter),
      ('Facebook', 'facebook', facebook),
    ];

    final links = <(String, String, Uri)>[];

    for (final (label, key, url) in entries) {
      final uri = Uri.tryParse(url);
      if (uri == null || (uri.scheme != 'http' && uri.scheme != 'https')) {
        continue;
      }
      links.add((label, key, uri));
    }

    return links;
  }

  factory SocialLinks.fromJson(Map<String, dynamic> json) => SocialLinks(
        instagram: Json.str(json['instagram']),
        youtube: Json.str(json['youtube']),
        linkedin: Json.str(json['linkedin']),
        twitter: Json.str(json['twitter']),
        facebook: Json.str(json['facebook']),
      );

  static const empty = SocialLinks();
}

/// Terms and privacy URLs from `/app/config` → `legal`.
class LegalLinks {
  const LegalLinks({this.termsUrl = '', this.privacyUrl = ''});

  final String termsUrl;
  final String privacyUrl;

  factory LegalLinks.fromJson(Map<String, dynamic> json) => LegalLinks(
        termsUrl: Json.str(json['terms_url']),
        privacyUrl: Json.str(json['privacy_url']),
      );

  static const empty = LegalLinks();
}

/// The whole `/app/config` document, past the `auth` block that
/// [AuthConfig] already models.
///
/// Everything here is fetched on every resume and was previously written to
/// disk and never read: feature flags, both popups, the force-update gate,
/// branding, app identity and the social links.
class RemoteAppConfig {
  const RemoteAppConfig({
    this.identity = AppIdentity.empty,
    this.branding = AppBranding.empty,
    this.update = UpdateGate.empty,
    this.maintenance = MaintenanceNotice.empty,
    this.features = FeatureFlags.empty,
    this.ai = AiFeatures.off,
    this.popups = const [],
    this.social = SocialLinks.empty,
    this.legal = LegalLinks.empty,
  });

  final AppIdentity identity;
  final AppBranding branding;
  final UpdateGate update;
  final MaintenanceNotice maintenance;
  final FeatureFlags features;
  final AiFeatures ai;
  final List<AppPopup> popups;
  final SocialLinks social;
  final LegalLinks legal;

  factory RemoteAppConfig.fromJson(Map<String, dynamic> json) =>
      RemoteAppConfig(
        identity: AppIdentity.fromJson(Json.map(json['app'])),
        branding: AppBranding.fromJson(Json.map(json['branding'])),
        update: UpdateGate.fromJson(Json.map(json['update'])),
        maintenance: MaintenanceNotice.fromJson(Json.map(json['maintenance'])),
        features: FeatureFlags.fromJson(json['features']),
        ai: AiFeatures.fromJson(Json.map(json['ai'])),
        popups: Json.list(json['popups'], AppPopup.fromJson),
        social: SocialLinks.fromJson(Json.map(json['social'])),
        legal: LegalLinks.fromJson(Json.map(json['legal'])),
      );

  static const empty = RemoteAppConfig();
}
