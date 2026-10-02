import 'package:flutter/services.dart';

/// Format checks for Indian identity and registration numbers.
///
/// These mirror the server's rules (KYC spec § 2) so the user hears about a
/// typo while typing rather than after a round trip. The server stays the
/// authority — it alone can say a number is already linked to another
/// account — and its field errors are shown in place of these.
///
/// Every check is for an OPTIONAL field: blank passes, because the detail form
/// saves section by section and only submission requires the full set.
abstract final class KycRules {
  /// Upper-case, no spaces or hyphens — how every number is compared and sent.
  static String normalize(String value) =>
      value.toUpperCase().replaceAll(RegExp(r'[\s-]+'), '');

  static String digitsOnly(String value) =>
      value.replaceAll(RegExp(r'\D+'), '');

  // -- PAN -------------------------------------------------------------------

  static final _pan = RegExp(r'^[A-Z]{5}[0-9]{4}[A-Z]$');

  /// [individual] is for creators: the fourth character of a personal PAN is
  /// `P`. A company PAN (`C`), firm (`F`) or trust (`T`) belongs to a business.
  static String? pan(String? value, {bool individual = false}) {
    final text = normalize(value ?? '');
    if (text.isEmpty) return null;

    if (!_pan.hasMatch(text)) {
      return 'Enter a valid 10-character PAN, like ABCDE1234F.';
    }
    if (individual && text[3] != 'P') {
      return 'Enter your personal PAN (the 4th character is P).';
    }
    return null;
  }

  // -- Aadhaar ---------------------------------------------------------------

  /// 12 digits, not starting with 0 or 1, with a valid Verhoeff check digit —
  /// the same test UIDAI applies, which catches any single mistyped digit and
  /// almost every swapped pair.
  static String? aadhaar(String? value) {
    final digits = digitsOnly(value ?? '');
    if (digits.isEmpty) return null;

    if (digits.length != 12) return 'Aadhaar has 12 digits.';
    if (digits[0] == '0' || digits[0] == '1') {
      return 'Aadhaar numbers never start with 0 or 1.';
    }
    if (!verhoeffValid(digits)) {
      return 'That Aadhaar number does not look right — check each digit.';
    }
    return null;
  }

  static const _d = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
    [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
    [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
    [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
    [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
    [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
    [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
    [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
    [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
    [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
  ];

  static const _p = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
    [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
    [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
    [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
    [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
    [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
    [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
    [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
  ];

  /// The Verhoeff checksum over a string of digits, check digit included.
  static bool verhoeffValid(String digits) {
    if (digits.isEmpty || digits.contains(RegExp(r'\D'))) return false;

    var check = 0;
    final reversed = digits.split('').reversed.toList();
    for (var i = 0; i < reversed.length; i++) {
      check = _d[check][_p[i % 8][int.parse(reversed[i])]];
    }
    return check == 0;
  }

  // -- GSTIN -----------------------------------------------------------------

  static final _gstin = RegExp(
    r'^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$',
  );

  static const _base36 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

  static String? gstin(String? value) {
    final text = normalize(value ?? '');
    if (text.isEmpty) return null;

    if (!_gstin.hasMatch(text)) {
      return 'Enter a valid 15-character GSTIN, like 27AABCG1234M1Z5.';
    }

    final state = int.parse(text.substring(0, 2));
    if (!((state >= 1 && state <= 38) || state == 97)) {
      return 'The first two digits should be your state code.';
    }

    if (!gstinChecksumValid(text)) {
      return 'That GSTIN does not look right — check each character.';
    }
    return null;
  }

  /// The GSTN mod-36 check on the 15th character.
  static bool gstinChecksumValid(String gstin) {
    if (gstin.length != 15) return false;

    var sum = 0;
    for (var i = 0; i < 14; i++) {
      final value = _base36.indexOf(gstin[i]);
      if (value < 0) return false;

      final product = value * (i.isOdd ? 2 : 1);
      sum += product ~/ 36 + product % 36;
    }

    final check = (36 - sum % 36) % 36;
    return gstin[14] == _base36[check];
  }

  /// Characters 3–12 of a GSTIN are the holder's PAN.
  static String? panInGstin(String? gstin) {
    final text = normalize(gstin ?? '');
    return text.length >= 12 ? text.substring(2, 12) : null;
  }

  /// When both are known, whether the GSTIN belongs to the same PAN.
  static String? gstinPanMismatch(String? gstin, String? pan) {
    final embedded = panInGstin(gstin);
    final given = normalize(pan ?? '');
    if (embedded == null || given.length != 10) return null;

    return embedded == given
        ? null
        : 'Characters 3–12 of the GSTIN must match the company PAN.';
  }

  // -- CIN -------------------------------------------------------------------

  static final _cin = RegExp(
    r'^[LU][0-9]{5}[A-Z]{2}[0-9]{4}'
    r'(PLC|PTC|FLC|FTC|GOI|NPL|ULL|ULT|SGC|GAT|GAP|OPC)[0-9]{6}$',
  );

  static String? cin(String? value) {
    final text = normalize(value ?? '');
    if (text.isEmpty) return null;

    return _cin.hasMatch(text)
        ? null
        : 'Enter the 21-character CIN from your Certificate of '
            'Incorporation, like U74999MH2019PTC123456.';
  }

  // -- Other ID ----------------------------------------------------------------

  /// The "Other ID" types the server accepts, less Aadhaar: Aadhaar and PAN
  /// have fields of their own, and an Aadhaar typed here would skip the
  /// checks that field applies.
  static const otherIdTypes = {'passport', 'driving_license', 'voter_id'};

  static final _passport = RegExp(r'^[A-Z][0-9]{7}$');
  static final _drivingLicence = RegExp(r'^[A-Z]{2}[0-9A-Z]{8,16}$');
  static final _voterId = RegExp(r'^[A-Z][A-Z0-9]{7,15}$');

  static final _aadhaarShape = RegExp(r'^[2-9][0-9]{11}$');

  /// Shown when an Aadhaar number is typed as the "Other ID". The server
  /// refuses it with the same advice.
  static const otherIdAadhaarError =
      'That looks like an Aadhaar number. Enter it in the Aadhaar field '
      'instead — only its last four digits are kept.';

  /// Whether [value] is an Aadhaar number, however it is punctuated: once
  /// everything but letters and digits is stripped, twelve digits with the
  /// first 2–9. The server applies the same test to the "Other ID" number,
  /// so a full Aadhaar never lands there — where it would be kept whole,
  /// rather than as the last four digits the Aadhaar field keeps.
  static bool looksLikeAadhaar(String? value) => _aadhaarShape.hasMatch(
        (value ?? '').replaceAll(RegExp(r'[^A-Za-z0-9]+'), ''),
      );

  /// The number for the chosen [type] of other ID. Passports have one fixed
  /// format; licences and voter IDs vary by state and year, so those are
  /// checked only for shape. An Aadhaar number is refused whatever the type.
  static String? otherId(String? type, String? value) {
    final raw = (value ?? '').trim().toUpperCase();
    if (raw.isEmpty) return null;

    if (looksLikeAadhaar(raw)) return otherIdAadhaarError;

    if (type == null || !otherIdTypes.contains(type)) {
      return 'Choose passport, driving licence or voter ID above first.';
    }
    if (raw.length > 20) return 'ID numbers are at most 20 characters.';

    final compact = raw.replaceAll(RegExp(r'[\s/-]+'), '');

    return switch (type) {
      'passport' => _passport.hasMatch(compact)
          ? null
          : 'Enter the 8-character passport number, like A1234567.',
      'driving_license' => _drivingLicence.hasMatch(compact)
          ? null
          : 'Enter the licence number as printed, like MH0320080012345.',
      _ => _voterId.hasMatch(compact)
          ? null
          : 'Enter the voter ID (EPIC) number as printed, like ABC1234567.',
    };
  }
}

/// Aadhaar entry: digits only, at most twelve, shown in groups of four the
/// way the card prints them (`2345 6789 0123`).
class AadhaarInputFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final cursor = newValue.selection.baseOffset < 0
        ? newValue.text.length
        : newValue.selection.baseOffset.clamp(0, newValue.text.length);

    // Digits before the cursor decide where it lands after regrouping.
    final digitsBefore = KycRules.digitsOnly(
      newValue.text.substring(0, cursor),
    ).length;

    var digits = KycRules.digitsOnly(newValue.text);
    if (digits.length > 12) digits = digits.substring(0, 12);

    final buffer = StringBuffer();
    var offset = 0;
    final target = digitsBefore.clamp(0, digits.length);

    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && i % 4 == 0) buffer.write(' ');
      buffer.write(digits[i]);
      if (i + 1 == target) offset = buffer.length;
    }

    return TextEditingValue(
      text: buffer.toString(),
      selection: TextSelection.collapsed(offset: offset),
    );
  }
}

/// PAN, GSTIN and CIN entry: upper-case letters and digits only, capped at
/// the identifier's length, matching how the server normalises them.
class IdentifierInputFormatter extends TextInputFormatter {
  IdentifierInputFormatter(this.maxLength);

  final int maxLength;

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    var text = newValue.text.toUpperCase().replaceAll(RegExp('[^A-Z0-9]'), '');
    if (text.length > maxLength) text = text.substring(0, maxLength);

    if (text == newValue.text) return newValue;

    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}
