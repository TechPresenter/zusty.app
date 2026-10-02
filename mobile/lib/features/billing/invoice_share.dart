import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/network/api_failure.dart';
import '../../shared/widgets/states.dart';

/// Hands an invoice to the system share sheet, from which it can be saved,
/// printed or opened in a browser.
///
/// The invoice endpoints need the bearer token, so the document is fetched by
/// the app and shared as a file. Opening the endpoint as a link would hand a
/// browser a URL it cannot authenticate against.
Future<void> shareInvoice(
  BuildContext context, {
  required String number,
  required Future<String> Function() load,
}) async {
  final String html;

  try {
    html = await load();
  } on ApiFailure catch (e) {
    if (context.mounted) showZSnack(context, e.message, isError: true);
    return;
  }

  final name =
      'Invoice-${number.replaceAll(RegExp(r'[^A-Za-z0-9-]+'), '-')}.html';

  await SharePlus.instance.share(
    ShareParams(
      files: [
        XFile.fromData(utf8.encode(html), mimeType: 'text/html', name: name),
      ],
      fileNameOverrides: [name],
      subject: 'Invoice $number',
    ),
  );
}
