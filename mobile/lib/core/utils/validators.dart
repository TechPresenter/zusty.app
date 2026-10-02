/// Client-side form validation.
///
/// Per the TRD these exist purely for UX — the Core PHP layer re-validates
/// every field and its errors are authoritative.
abstract final class Validators {
  static final _email = RegExp(r'^[\w.+-]+@[\w-]+\.[\w.-]+$');
  static final _phone = RegExp(r'^[6-9]\d{9}$');
  static final _handle = RegExp(r'^[a-zA-Z0-9._]{3,30}$');

  static String? required(String? value, {String field = 'This field'}) {
    if (value == null || value.trim().isEmpty) return '$field is required.';
    return null;
  }

  static String? email(String? value) {
    final empty = required(value, field: 'Email');
    if (empty != null) return empty;
    if (!_email.hasMatch(value!.trim())) {
      return 'Enter a valid email address.';
    }
    return null;
  }

  static String? phone(String? value) {
    final empty = required(value, field: 'Mobile number');
    if (empty != null) return empty;
    if (!_phone.hasMatch(value!.trim())) {
      return 'Enter a valid 10-digit mobile number.';
    }
    return null;
  }

  /// Accepts either an email or a 10-digit mobile — the login screen's field.
  static String? emailOrPhone(String? value) {
    final empty = required(value, field: 'Email or phone');
    if (empty != null) return empty;
    final text = value!.trim();
    if (_email.hasMatch(text) || _phone.hasMatch(text)) return null;
    return 'Enter a valid email address or mobile number.';
  }

  static String? password(String? value) {
    final empty = required(value, field: 'Password');
    if (empty != null) return empty;
    if (value!.length < 8) return 'Use at least 8 characters.';
    if (!value.contains(RegExp(r'[A-Za-z]')) ||
        !value.contains(RegExp(r'\d'))) {
      return 'Include at least one letter and one number.';
    }
    return null;
  }

  static String? confirmPassword(String? value, String original) {
    if (value != original) return 'Passwords do not match.';
    return null;
  }

  static String? handle(String? value) {
    final empty = required(value, field: 'Username');
    if (empty != null) return empty;
    if (!_handle.hasMatch(value!.trim())) {
      return 'Use 3–30 letters, numbers, dots, or underscores.';
    }
    return null;
  }

  static String? otp(String? value, {int length = 6}) {
    if (value == null || value.trim().length != length) {
      return 'Enter the $length-digit code.';
    }
    return null;
  }

  static String? minLength(String? value, int min, {String field = 'This'}) {
    final empty = required(value, field: field);
    if (empty != null) return empty;
    if (value!.trim().length < min) {
      return '$field must be at least $min characters.';
    }
    return null;
  }

  static String? amount(
    String? value, {
    double min = 1,
    double? max,
    String field = 'Amount',
  }) {
    final empty = required(value, field: field);
    if (empty != null) return empty;

    final parsed = double.tryParse(value!.replaceAll(RegExp(r'[^\d.]'), ''));
    if (parsed == null) return 'Enter a valid amount.';
    if (parsed < min) return '$field must be at least ${min.toStringAsFixed(0)}.';
    if (max != null && parsed > max) {
      return '$field cannot exceed ${max.toStringAsFixed(0)}.';
    }
    return null;
  }

  /// Indian bank IFSC, e.g. `HDFC0001234`.
  static String? ifsc(String? value) {
    final empty = required(value, field: 'IFSC code');
    if (empty != null) return empty;
    if (!RegExp(r'^[A-Z]{4}0[A-Z0-9]{6}$').hasMatch(value!.trim().toUpperCase())) {
      return 'Enter a valid IFSC code.';
    }
    return null;
  }

  static String? accountNumber(String? value) {
    final empty = required(value, field: 'Account number');
    if (empty != null) return empty;
    if (!RegExp(r'^\d{9,18}$').hasMatch(value!.trim())) {
      return 'Enter a valid account number.';
    }
    return null;
  }

  // -- Optional variants ---------------------------------------------------
  //
  // KYC forms save section by section, so almost every field can legitimately
  // be blank on the way to a complete profile. These check the format only
  // when something has actually been typed; the backend decides what is
  // mandatory at submission time.

  static String? optionalEmail(String? value) =>
      _skipIfBlank(value, email);

  static String? optionalPhone(String? value) =>
      _skipIfBlank(value, phone);

  static String? optionalIfsc(String? value) =>
      _skipIfBlank(value, ifsc);

  /// Indian GSTIN, e.g. `27AABCG1234M1Z5`.
  static String? optionalGst(String? value) => _skipIfBlank(value, (v) {
        final pattern = RegExp(
          r'^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$',
        );
        return pattern.hasMatch(v!.trim().toUpperCase())
            ? null
            : 'Enter a valid 15-character GSTIN.';
      });

  /// Indian PAN, e.g. `AABCG1234M`.
  static String? optionalPan(String? value) => _skipIfBlank(value, (v) {
        return RegExp(r'^[A-Z]{5}\d{4}[A-Z]$').hasMatch(v!.trim().toUpperCase())
            ? null
            : 'Enter a valid 10-character PAN.';
      });

  static String? optionalUrl(String? value) => _skipIfBlank(value, (v) {
        final uri = Uri.tryParse(v!.trim());
        return uri != null && uri.hasScheme && uri.host.isNotEmpty
            ? null
            : 'Enter a full URL, starting with https://';
      });

  static String? optionalYear(String? value) => _skipIfBlank(value, (v) {
        final year = int.tryParse(v!.trim());
        return year != null && year >= 1800 && year <= DateTime.now().year
            ? null
            : 'Enter a valid year.';
      });

  /// Runs [check] only when the field has content.
  static String? _skipIfBlank(
    String? value,
    String? Function(String?) check,
  ) {
    if (value == null || value.trim().isEmpty) return null;
    return check(value);
  }
}
