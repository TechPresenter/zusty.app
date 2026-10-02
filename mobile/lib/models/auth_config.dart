import 'package:flutter/foundation.dart';

/// How the sign-in screen should present itself, decided by the server.
///
/// Everything here comes from `GET /app/config` → `auth`, which is driven by
/// the Authentication & Login screen in the admin panel. Nothing about which
/// buttons appear, in what order, or with which credentials is compiled into
/// the app — that is the whole point: an operator can turn Google Sign-In off
/// and it disappears from an already-installed app on its next launch.
///
/// The one thing that cannot work this way is anything baked into the build or
/// registered with the provider — bundle identifiers, Android signing
/// fingerprints, `google-services.json`. Those are recorded in the panel for
/// reference and flagged there as build-time.
@immutable
class AuthConfig {
  const AuthConfig({
    this.version = '',
    this.methods = const [],
    this.guest = const GuestConfig(),
    this.firebase = const FirebaseConfig(),
    this.signup = const SignupConfig(),
    this.dividerLabel = 'OR CONTINUE WITH',
    this.signupDividerLabel = 'OR SIGN UP WITH',
    this.showSocialDivider = false,
  });

  final String version;
  final List<AuthMethod> methods;
  final GuestConfig guest;
  final FirebaseConfig firebase;
  final SignupConfig signup;
  final String dividerLabel;
  final String signupDividerLabel;
  final bool showSocialDivider;

  /// Only the methods that are switched on, already in the order the operator
  /// chose. Screens should render straight from this and never re-sort.
  List<AuthMethod> get enabled =>
      methods.where((m) => m.enabled).toList(growable: false);

  AuthMethod? method(String key) {
    for (final m in methods) {
      if (m.key == key) return m;
    }
    return null;
  }

  bool isEnabled(String key) => method(key)?.enabled ?? false;

  /// Social providers that are on **and** available on this platform —
  /// Sign in with Apple, for instance, has no Android implementation.
  List<AuthMethod> socialFor(String platform) => enabled
      .where((m) =>
          const {'google', 'apple', 'facebook'}.contains(m.key) &&
          m.availableOn(platform))
      .toList(growable: false);

  /// True when the app has never successfully read a config from the server.
  bool get isEmpty => methods.isEmpty;

  factory AuthConfig.fromJson(Map<String, dynamic> json) {
    final rawMethods = (json['methods'] as List?) ?? const [];
    final ui = (json['ui'] as Map?)?.cast<String, dynamic>() ?? const {};

    return AuthConfig(
      version: json['version'] as String? ?? '',
      methods: rawMethods
          .whereType<Map>()
          .map((m) => AuthMethod.fromJson(m.cast<String, dynamic>()))
          .toList(growable: false),
      guest: GuestConfig.fromJson(
          (json['guest'] as Map?)?.cast<String, dynamic>() ?? const {}),
      firebase: FirebaseConfig.fromJson(
          (json['firebase'] as Map?)?.cast<String, dynamic>() ?? const {}),
      signup: SignupConfig.fromJson(
          (json['signup'] as Map?)?.cast<String, dynamic>() ?? const {}),
      dividerLabel: ui['divider_label'] as String? ?? 'OR CONTINUE WITH',
      signupDividerLabel: ui['signup_divider_label'] as String? ?? 'OR SIGN UP WITH',
      showSocialDivider: ui['show_social_divider'] == true,
    );
  }

  /// Round-trips through the device cache, so the login screen is correct on a
  /// cold start with no network rather than falling back to compiled defaults.
  Map<String, dynamic> toJson() => {
        'version': version,
        'methods': methods.map((m) => m.raw).toList(),
        'guest': guest.raw,
        'firebase': firebase.raw,
        'signup': signup.raw,
        'ui': {
          'divider_label': dividerLabel,
          'signup_divider_label': signupDividerLabel,
          'show_social_divider': showSocialDivider,
        },
      };
}

/// One way in.
@immutable
class AuthMethod {
  const AuthMethod({
    required this.key,
    required this.enabled,
    required this.label,
    required this.order,
    required this.raw,
  });

  /// `email_password`, `mobile_otp`, `google`, `apple`, `facebook`, `guest`.
  final String key;
  final bool enabled;
  final String label;
  final int order;

  /// The untouched server payload. Each method carries different fields and
  /// new ones may appear without an app release, so they are read by name
  /// rather than modelled into fixed properties.
  final Map<String, dynamic> raw;

  String string(String field, [String fallback = '']) =>
      raw[field] as String? ?? fallback;

  int integer(String field, int fallback) {
    final value = raw[field];
    if (value is int) return value;
    if (value is String) return int.tryParse(value) ?? fallback;
    return fallback;
  }

  bool flag(String field, {bool fallback = false}) =>
      raw[field] is bool ? raw[field] as bool : fallback;

  List<String> strings(String field) =>
      ((raw[field] as List?) ?? const []).whereType<String>().toList(growable: false);

  /// A method may declare which platforms it runs on. Absent means all.
  bool availableOn(String platform) {
    final platforms = strings('platforms');
    return platforms.isEmpty || platforms.contains(platform);
  }

  factory AuthMethod.fromJson(Map<String, dynamic> json) => AuthMethod(
        key: json['key'] as String? ?? '',
        enabled: json['enabled'] == true,
        label: json['label'] as String? ?? '',
        order: json['order'] is int ? json['order'] as int : 0,
        raw: json,
      );
}

@immutable
class GuestConfig {
  const GuestConfig({
    this.enabled = false,
    this.browseRoutes = const [],
    this.promptOn = const [],
    this.sessionDays = 30,
    this.raw = const {},
  });

  final bool enabled;

  /// Route names a guest may reach without being asked to sign up.
  final List<String> browseRoutes;

  /// Actions that should raise the create-account sheet instead of proceeding.
  final List<String> promptOn;

  final int sessionDays;
  final Map<String, dynamic> raw;

  bool mayBrowse(String route) => browseRoutes.contains(route);
  bool shouldPrompt(String action) => promptOn.contains(action);

  factory GuestConfig.fromJson(Map<String, dynamic> json) => GuestConfig(
        enabled: json['enabled'] == true,
        browseRoutes:
            ((json['browse_routes'] as List?) ?? const []).whereType<String>().toList(),
        promptOn:
            ((json['prompt_on'] as List?) ?? const []).whereType<String>().toList(),
        sessionDays: json['session_days'] is int ? json['session_days'] as int : 30,
        raw: json,
      );
}

/// The client half of the Firebase options.
///
/// Never includes the service-account credentials — those stay on the server
/// and are not in the settings group this block is built from.
@immutable
class FirebaseConfig {
  const FirebaseConfig({
    this.enabled = false,
    this.projectId = '',
    this.apiKey = '',
    this.appId = '',
    this.messagingSenderId = '',
    this.authDomain = '',
    this.storageBucket = '',
    this.raw = const {},
  });

  final bool enabled;
  final String projectId;
  final String apiKey;
  final String appId;
  final String messagingSenderId;
  final String authDomain;
  final String storageBucket;
  final Map<String, dynamic> raw;

  /// Enough to start Firebase. Half a configuration crashes on boot, so the
  /// caller must check this before calling initializeApp.
  bool get isUsable =>
      enabled && projectId.isNotEmpty && apiKey.isNotEmpty && appId.isNotEmpty;

  factory FirebaseConfig.fromJson(Map<String, dynamic> json) => FirebaseConfig(
        enabled: json['enabled'] == true,
        projectId: json['project_id'] as String? ?? '',
        apiKey: json['api_key'] as String? ?? '',
        appId: json['app_id'] as String? ?? '',
        messagingSenderId: json['messaging_sender_id'] as String? ?? '',
        authDomain: json['auth_domain'] as String? ?? '',
        storageBucket: json['storage_bucket'] as String? ?? '',
        raw: json,
      );
}

@immutable
class SignupConfig {
  const SignupConfig({
    this.creatorEnabled = true,
    this.brandEnabled = true,
    this.defaultRole = 'brand',
    this.roleChoiceVisible = true,
    this.requireTermsAccept = true,
    this.termsUrl = '',
    this.privacyUrl = '',
    this.raw = const {},
  });

  final bool creatorEnabled;
  final bool brandEnabled;
  final String defaultRole;
  final bool roleChoiceVisible;
  final bool requireTermsAccept;
  final String termsUrl;
  final String privacyUrl;
  final Map<String, dynamic> raw;

  /// Nobody may sign up at all — the screen should say so rather than showing
  /// a form that will 403.
  bool get isClosed => !creatorEnabled && !brandEnabled;

  factory SignupConfig.fromJson(Map<String, dynamic> json) => SignupConfig(
        creatorEnabled: json['creator_enabled'] != false,
        brandEnabled: json['brand_enabled'] != false,
        defaultRole: json['default_role'] as String? ?? 'brand',
        roleChoiceVisible: json['role_choice_visible'] != false,
        requireTermsAccept: json['require_terms_accept'] != false,
        termsUrl: json['terms_url'] as String? ?? '',
        privacyUrl: json['privacy_url'] as String? ?? '',
        raw: json,
      );
}
