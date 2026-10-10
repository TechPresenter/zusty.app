import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../shared/widgets/states.dart';

/// Opens [uri] outside the app (the browser, an email app, the dialler) and
/// tells the user when nothing could.
///
/// Returns whether it opened. A missing handler is reported two ways, so both
/// are handled here: on Android url_launcher throws ACTIVITY_NOT_FOUND, and on
/// iOS it returns false. Either way the user sees why nothing happened.
///
/// For an email address or a phone number the message offers a Copy action,
/// so the address can still be used somewhere else. [copyText] is what it
/// copies; it defaults to the address or number in a `mailto:` or `tel:` URI.
Future<bool> openExternalUri(
  BuildContext context,
  Uri uri, {
  String? copyText,
}) async {
  var opened = false;
  try {
    opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {
    opened = false;
  }
  if (opened || !context.mounted) return opened;

  final scheme = uri.scheme.toLowerCase();
  final contact = scheme == 'mailto' || scheme == 'tel';
  final copy = copyText ?? (contact ? _addressOf(uri) : '');

  if (copy.isEmpty) {
    showZSnack(context, 'Could not open that link.', isError: true);
    return false;
  }

  showZSnack(
    context,
    switch (scheme) {
      'mailto' => 'No email app could open this. Copy $copy instead?',
      'tel' => 'No phone app could open this. Copy $copy instead?',
      _ => 'Could not open that link. Copy it instead?',
    },
    isError: true,
    actionLabel: 'Copy',
    onAction: () => Clipboard.setData(ClipboardData(text: copy)),
  );
  return false;
}

/// The address in `mailto:support@zusty.app?subject=…` or the number in
/// `tel:+911800123456`, without the query.
String _addressOf(Uri uri) {
  try {
    return Uri.decodeComponent(uri.path);
  } catch (_) {
    // A malformed escape: the raw path is still more useful than nothing.
    return uri.path;
  }
}
