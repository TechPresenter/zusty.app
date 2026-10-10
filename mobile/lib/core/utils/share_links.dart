import '../../models/app_settings.dart';
import '../config/app_config.dart';

/// What "Share Zusty" sends.
///
/// The store listing the operator configured (Settings → App, the same link
/// the update screen opens) — but only once [storeLive] says the listing is
/// public (`app.android_store_live`). The store URL is filled in long before
/// that, and Google Play answers it with 404 until the listing is live, so
/// until then, and whenever no valid listing is set, it shares the website.
/// It used to share a hard-coded old-brand domain that is not ours, so every
/// share sent people to somebody else's site.
String shareAppText(UpdateGate gate, {required bool storeLive}) {
  final listing = storeLive ? gate.storeUri?.toString() : null;
  final link = listing ?? AppConfig.websiteUrl;

  return 'Find and book verified creators on ${AppConfig.appName} — $link';
}
