import '../../../core/utils/formatters.dart';
import '../../../models/creator_tools.dart';

/// The API's validation rules for the creator editors, as form validators.
///
/// Each mirrors one rule in `CreatorController` so a form refuses what the
/// server would — with the same limits — before the creator presses Save.
/// The server stays the authority: its message is what shows when it
/// disagrees (a taxonomy edited a minute ago, a price floor an operator
/// raised).
abstract final class CreatorToolsRules {
  // ---- Packages: createPackage / updatePackage ----------------------------

  static const titleMin = 3;
  static const titleMax = 150;
  static const descriptionMax = 1000;
  static const quantityMin = 1;
  static const quantityMax = 100;
  static const turnaroundMin = 1;
  static const turnaroundMax = 180;
  static const revisionsMin = 0;
  static const revisionsMax = 10;

  static String? packageTitle(String? value) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return 'Give the package a title.';
    if (text.length < titleMin) return 'Use at least $titleMin characters.';
    if (text.length > titleMax) return 'Keep it to $titleMax characters.';
    return null;
  }

  static String? packageDescription(String? value) =>
      (value ?? '').trim().length > descriptionMax
      ? 'Keep it to $descriptionMax characters.'
      : null;

  /// Whole rupees or rupees and paise, inside the booking bounds.
  static String? price(String? value, PackageLimits limits) {
    final text = (value ?? '').trim().replaceAll(',', '');
    if (text.isEmpty) return 'Enter the price.';

    final amount = double.tryParse(text);
    if (amount == null || amount.isNaN || amount.isInfinite) {
      return 'Enter the price as a number.';
    }
    if (amount < limits.minPrice) {
      return 'The lowest price a package can have is ${_rupees(limits.minPrice)}.';
    }
    if (amount > limits.maxPrice) {
      return 'The highest price a package can have is ${_rupees(limits.maxPrice)}.';
    }
    return null;
  }

  static String? quantity(String? value) =>
      _wholeNumber(value, quantityMin, quantityMax, 'How many');

  static String? turnaroundDays(String? value) =>
      _wholeNumber(value, turnaroundMin, turnaroundMax, 'Delivery time');

  static String? revisions(String? value) =>
      _wholeNumber(value, revisionsMin, revisionsMax, 'Revisions');

  /// One "what's included" line, checked as it is added.
  static String? includeLine(String? value, PackageLimits limits) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return 'Type what is included first.';
    if (text.length > limits.includeMaxLength) {
      return 'Keep each line to ${limits.includeMaxLength} characters.';
    }
    return null;
  }

  // ---- Portfolio: addPortfolioItem ----------------------------------------

  static const portfolioTitleMax = 191;
  static const captionMax = 1000;
  static const urlMax = 500;

  static String? portfolioTitle(String? value) =>
      (value ?? '').trim().length > portfolioTitleMax
      ? 'Keep it to $portfolioTitleMax characters.'
      : null;

  static String? caption(String? value) =>
      (value ?? '').trim().length > captionMax
      ? 'Keep it to $captionMax characters.'
      : null;

  static String? optionalLink(String? value) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return null;
    return link(text);
  }

  // ---- Social accounts: upsertSocialAccount -------------------------------

  static const handleMax = 120;
  static const labelMax = 80;

  static String? handle(String? value) {
    final text = (value ?? '').trim().replaceFirst(RegExp(r'^@+'), '');
    if (text.isEmpty) return 'Enter your handle.';
    if (text.length > handleMax) return 'Keep it to $handleMax characters.';
    return null;
  }

  /// Required: the server will not store an account without a link to it.
  static String? link(String? value) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return 'Enter the link.';
    if (text.length > urlMax) return 'Keep the link to $urlMax characters.';

    final uri = Uri.tryParse(text);
    final ok = uri != null &&
        (uri.scheme == 'http' || uri.scheme == 'https') &&
        uri.host.isNotEmpty;
    return ok ? null : 'Enter a full link, starting with https://';
  }

  static String? label(String? value) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return 'Name this link, e.g. "My newsletter".';
    if (text.length > labelMax) return 'Keep it to $labelMax characters.';
    return null;
  }

  static String? followers(String? value) {
    final text = (value ?? '').trim().replaceAll(',', '');
    if (text.isEmpty) return null;
    final count = int.tryParse(text);
    if (count == null || count < 0) return 'Enter a whole number.';
    return null;
  }

  static String? engagementRate(String? value) {
    final text = (value ?? '').trim().replaceAll('%', '');
    if (text.isEmpty) return null;
    final rate = double.tryParse(text);
    if (rate == null || rate < 0 || rate > 100) {
      return 'Enter a percentage between 0 and 100.';
    }
    return null;
  }

  // ---- Helpers ------------------------------------------------------------

  static String? _wholeNumber(String? value, int min, int max, String field) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return '$field is required.';
    final number = int.tryParse(text);
    if (number == null) return 'Enter a whole number.';
    if (number < min || number > max) return 'Between $min and $max.';
    return null;
  }

  /// `₹1,000` — whole rupees with Indian grouping, as the server writes it.
  static String _rupees(double amount) => Formatters.currency(amount);
}
