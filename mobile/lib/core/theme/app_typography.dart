import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import 'app_colors.dart';

/// The Plus Jakarta Sans type scale.
///
/// Screens should reach for [AppTypography] (or the matching [TextTheme] slot)
/// rather than composing ad-hoc [TextStyle]s, so weight and line-height stay
/// consistent. Nothing is smaller than 11px: below that, metadata stops being
/// readable on a phone held at arm's length.
abstract final class AppTypography {
  static TextStyle get _base => GoogleFonts.plusJakartaSans();

  /// 32/40, bold, -0.02em — hero headings and onboarding titles.
  static TextStyle get displayLarge => _base.copyWith(
        fontSize: 32,
        fontWeight: FontWeight.w700,
        height: 40 / 32,
        letterSpacing: -0.64, // -0.02em at 32px
        color: AppColors.onSurface,
      );

  /// 24/32, bold — screen titles.
  static TextStyle get headlineMedium => _base.copyWith(
        fontSize: 24,
        fontWeight: FontWeight.w700,
        height: 32 / 24,
        color: AppColors.onSurface,
      );

  /// 20/28, semi-bold — section and app-bar titles.
  static TextStyle get headlineSmall => _base.copyWith(
        fontSize: 20,
        fontWeight: FontWeight.w600,
        height: 28 / 20,
        color: AppColors.onSurface,
      );

  /// 16/24, regular — primary body copy.
  static TextStyle get bodyLarge => _base.copyWith(
        fontSize: 16,
        fontWeight: FontWeight.w400,
        height: 24 / 16,
        color: AppColors.onSurface,
      );

  /// 14/20, regular — secondary body copy, the app default.
  static TextStyle get bodyMedium => _base.copyWith(
        fontSize: 14,
        fontWeight: FontWeight.w400,
        height: 20 / 14,
        color: AppColors.onSurface,
      );

  /// 12/16, semi-bold — labels, chips, nav items, metadata.
  static TextStyle get labelMedium => _base.copyWith(
        fontSize: 12,
        fontWeight: FontWeight.w600,
        height: 16 / 12,
        color: AppColors.onSurface,
      );

  /// 16/20, semi-bold — button text.
  static TextStyle get button => _base.copyWith(
        fontSize: 16,
        fontWeight: FontWeight.w600,
        height: 20 / 16,
      );

  // -- Convenience derivations -------------------------------------------
  // The Stitch screens use a handful of sizes below the label scale (10-11px)
  // for dense card metadata; these keep that consistent.

  /// 12/16, regular — dense card subtitles and metadata.
  static TextStyle get bodySmall => _base.copyWith(
        fontSize: 12,
        fontWeight: FontWeight.w400,
        height: 16 / 12,
        color: AppColors.onSurfaceVariant,
      );

  /// 11/14, semi-bold — the smallest label (category captions, nav labels).
  static TextStyle get labelSmall => _base.copyWith(
        fontSize: 11,
        fontWeight: FontWeight.w600,
        height: 14 / 11,
        color: AppColors.onSurfaceVariant,
      );

  /// 14/20, semi-bold — list titles, card titles, tab labels.
  static TextStyle get titleSmall => _base.copyWith(
        fontSize: 14,
        fontWeight: FontWeight.w600,
        height: 20 / 14,
        color: AppColors.onSurface,
      );

  /// 14/20, semi-bold — compact button text and prominent labels.
  static TextStyle get labelLarge => _base.copyWith(
        fontSize: 14,
        fontWeight: FontWeight.w600,
        height: 20 / 14,
        color: AppColors.onSurface,
      );

  /// 18/26, bold — the "sub-headline" used for card titles on detail screens.
  static TextStyle get titleMedium => _base.copyWith(
        fontSize: 18,
        fontWeight: FontWeight.w700,
        height: 26 / 18,
        color: AppColors.onSurface,
      );

  /// The Material [TextTheme] wired to the roles above.
  static TextTheme get textTheme => TextTheme(
        displayLarge: displayLarge,
        displayMedium: displayLarge,
        displaySmall: headlineMedium,
        headlineLarge: headlineMedium,
        headlineMedium: headlineMedium,
        headlineSmall: headlineSmall,
        titleLarge: headlineSmall,
        titleMedium: titleMedium,
        titleSmall: titleSmall,
        bodyLarge: bodyLarge,
        bodyMedium: bodyMedium,
        bodySmall: bodySmall,
        labelLarge: button,
        labelMedium: labelMedium,
        labelSmall: labelSmall,
      );
}
