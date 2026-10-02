/// Turns the server's `action_type` / `action_value` pair into a link.
///
/// Banners, notifications and popups all carry the target as a type and a
/// value (`booking` + `472`, `screen` + `/explore`, `url` + `https://…`)
/// rather than a ready path. Reading a single `action_url` field, as the app
/// used to, found nothing, so every banner and notification opened the
/// notifications list instead of what it was about.
///
/// Returns an in-app path, an absolute http(s) URL for `url` (the caller opens
/// it externally), or null when there is nothing to open.
String? actionLinkFor(Object? type, Object? value) {
  final kind = type?.toString().trim().toLowerCase() ?? '';
  final target = value?.toString().trim() ?? '';

  String? withValue(String base) => target.isEmpty ? base : '$base/$target';

  return switch (kind) {
    'screen' || 'route' || 'path' => target.isEmpty
        ? null
        : (target.startsWith('/') ? target : '/$target'),
    'url' || 'link' || 'external' => target.isEmpty ? null : target,
    'booking' || 'review' => withValue('/bookings'),
    'chat' || 'message' || 'conversation' => withValue('/messages'),
    'creator' || 'profile' => target.isEmpty ? null : '/creator/$target',
    'ticket' || 'support' => target.isEmpty ? '/support' : '/support/tickets/$target',
    'kyc' || 'verification' => '/profile/kyc',
    'wallet' || 'payment' || 'withdrawal' => '/wallet',
    'campaign' => target.isEmpty ? '/campaigns' : '/campaigns/$target',
    'notification' || 'notifications' => '/notifications',
    _ => null,
  };
}

/// True for a link that should open outside the app.
bool isExternalLink(String? link) =>
    link != null && (link.startsWith('http://') || link.startsWith('https://'));
