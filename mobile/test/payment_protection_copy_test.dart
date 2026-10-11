import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:zuste/models/app_settings.dart';

/// Decision P-ESCROW (2026-10-10): a brand's money settles to Zusty's own
/// account, so nothing the app shows may call it escrow, and nothing may
/// promise more than the product does. Internal names stay: the API's
/// `escrow_status` field, the `escrow_hold` and `escrow_release` ledger
/// categories and Dart symbols such as `Wallet.escrow` are not copy.
///
/// The copy check reads the source rather than pumping every screen: each
/// single-line string literal under lib/, with interpolations taken out, is
/// copy unless it is a bare key such as `'escrow_release'`. Comments are not.
void main() {
  group('payment protection copy', () {
    test('no string in lib/ says escrow, nodal, trust account, guaranteed, 100% safe or safely', () {
      final banned = RegExp(
        r'escrow|\bnodal\b|trust account|guaranteed|100\s*%\s*safe|\bsafely\b',
        caseSensitive: false,
      );
      final interpolation = RegExp(r'\$\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_.]*');
      final bareKey = RegExp(r'^[a-z0-9_.:-]*$');

      final offenders = <String>[];
      final files = Directory('lib')
          .listSync(recursive: true)
          .whereType<File>()
          .where((file) => file.path.endsWith('.dart'));

      for (final file in files) {
        final lines = file.readAsLinesSync();
        for (var i = 0; i < lines.length; i++) {
          for (final literal in _stringLiterals(lines[i])) {
            final text = literal.replaceAll(interpolation, '');
            if (bareKey.hasMatch(text)) continue;
            if (banned.hasMatch(text)) {
              offenders.add('${file.path}:${i + 1}: $literal');
            }
          }
        }
      }

      expect(offenders, isEmpty, reason: offenders.join('\n'));
    });
  });

  group('the review window the app quotes', () {
    test('comes from /app/config booking.auto_complete_days', () {
      final config = RemoteAppConfig.fromJson({
        'booking': {'auto_complete_days': 3},
      });

      expect(config.booking.autoCompleteDays, 3);
      expect(config.booking.reviewWindow, '3 days');
    });

    test('says "1 day" for one, and never less than one', () {
      expect(BookingOptions.fromJson({'auto_complete_days': 1}).reviewWindow, '1 day');
      expect(BookingOptions.fromJson({'auto_complete_days': 0}).reviewWindow, '1 day');
    });

    test('falls back to the seeded 7 days before the config arrives', () {
      expect(RemoteAppConfig.empty.booking.reviewWindow, '7 days');
      expect(RemoteAppConfig.fromJson(const {}).booking.reviewWindow, '7 days');
    });
  });
}

/// The contents of the quoted strings on one line of Dart, in order. Stops at
/// a `//` comment outside a string. Good enough for copy: a string spanning
/// lines is read one line at a time.
List<String> _stringLiterals(String line) {
  final found = <String>[];
  var i = 0;

  while (i < line.length) {
    final char = line[i];

    if (char == '/' && i + 1 < line.length && line[i + 1] == '/') break;

    if (char == "'" || char == '"') {
      final buffer = StringBuffer();
      var j = i + 1;
      while (j < line.length && line[j] != char) {
        if (line[j] == r'\' && j + 1 < line.length) {
          buffer.write(line[j + 1]);
          j += 2;
          continue;
        }
        buffer.write(line[j]);
        j++;
      }
      found.add(buffer.toString());
      i = j + 1;
      continue;
    }

    i++;
  }

  return found;
}
