import 'package:flutter/material.dart';

/// Colour tokens for the Zusty design system.
///
/// Magenta primary, deep-plum secondary, warm blush neutrals. The light values
/// are the brand palette (primary #C9004F, background #FFF8FA, text #211A1D,
/// border #F0DDE3); the dark values keep the same hues on warm near-black
/// surfaces so the brand reads the same in both modes.
///
/// **How theming works here:** every member is a *getter* that resolves against
/// [brightness], which [AppTheme] sets before it builds a [ThemeData]. That
/// keeps the ergonomic `AppColors.primary` call sites used across the app
/// working unchanged in both modes, at the cost of these no longer being
/// compile-time constants. Widgets read them during `build`, and the app root
/// re-keys its subtree when the mode changes, so every screen repaints.
abstract final class AppColors {
  /// The palette currently in force. Set by `AppTheme.of` when it builds a
  /// theme, before any widget reads a token.
  static Brightness brightness = Brightness.light;

  static bool get isDark => brightness == Brightness.dark;

  static Color _pick(Color light, Color dark) => isDark ? dark : light;

  // -- Surfaces ------------------------------------------------------------
  static Color get surface => _pick(_L.surface, _D.surface);
  static Color get surfaceDim => _pick(_L.surfaceDim, _D.surfaceDim);
  static Color get surfaceBright => _pick(_L.surfaceBright, _D.surfaceBright);
  static Color get surfaceContainerLowest =>
      _pick(_L.surfaceContainerLowest, _D.surfaceContainerLowest);
  static Color get surfaceContainerLow =>
      _pick(_L.surfaceContainerLow, _D.surfaceContainerLow);
  static Color get surfaceContainer =>
      _pick(_L.surfaceContainer, _D.surfaceContainer);
  static Color get surfaceContainerHigh =>
      _pick(_L.surfaceContainerHigh, _D.surfaceContainerHigh);
  static Color get surfaceContainerHighest =>
      _pick(_L.surfaceContainerHighest, _D.surfaceContainerHighest);
  static Color get surfaceVariant =>
      _pick(_L.surfaceVariant, _D.surfaceVariant);
  static Color get onSurface => _pick(_L.onSurface, _D.onSurface);
  static Color get onSurfaceVariant =>
      _pick(_L.onSurfaceVariant, _D.onSurfaceVariant);
  static Color get inverseSurface =>
      _pick(_L.inverseSurface, _D.inverseSurface);
  static Color get inverseOnSurface =>
      _pick(_L.inverseOnSurface, _D.inverseOnSurface);
  static Color get surfaceTint => _pick(_L.surfaceTint, _D.surfaceTint);

  static Color get background => surface;
  static Color get onBackground => onSurface;

  // -- Outlines ------------------------------------------------------------
  static Color get outline => _pick(_L.outline, _D.outline);
  static Color get outlineVariant =>
      _pick(_L.outlineVariant, _D.outlineVariant);

  // -- Primary (magenta) ---------------------------------------------------
  static Color get primary => _pick(_L.primary, _D.primary);
  static Color get onPrimary => _pick(_L.onPrimary, _D.onPrimary);
  static Color get primaryContainer =>
      _pick(_L.primaryContainer, _D.primaryContainer);
  static Color get onPrimaryContainer =>
      _pick(_L.onPrimaryContainer, _D.onPrimaryContainer);
  static Color get inversePrimary =>
      _pick(_L.inversePrimary, _D.inversePrimary);
  static Color get primaryFixed => _pick(_L.primaryFixed, _D.primaryFixed);
  static Color get primaryFixedDim =>
      _pick(_L.primaryFixedDim, _D.primaryFixedDim);
  static Color get onPrimaryFixed =>
      _pick(_L.onPrimaryFixed, _D.onPrimaryFixed);
  static Color get onPrimaryFixedVariant =>
      _pick(_L.onPrimaryFixedVariant, _D.onPrimaryFixedVariant);

  // -- Secondary (deep plum) ----------------------------------------------
  static Color get secondary => _pick(_L.secondary, _D.secondary);
  static Color get onSecondary => _pick(_L.onSecondary, _D.onSecondary);
  static Color get secondaryContainer =>
      _pick(_L.secondaryContainer, _D.secondaryContainer);
  static Color get onSecondaryContainer =>
      _pick(_L.onSecondaryContainer, _D.onSecondaryContainer);
  static Color get secondaryFixed =>
      _pick(_L.secondaryFixed, _D.secondaryFixed);
  static Color get secondaryFixedDim =>
      _pick(_L.secondaryFixedDim, _D.secondaryFixedDim);
  static Color get onSecondaryFixed =>
      _pick(_L.onSecondaryFixed, _D.onSecondaryFixed);
  static Color get onSecondaryFixedVariant =>
      _pick(_L.onSecondaryFixedVariant, _D.onSecondaryFixedVariant);

  // -- Tertiary (green, success) -------------------------------------------
  static Color get tertiary => _pick(_L.tertiary, _D.tertiary);
  static Color get onTertiary => _pick(_L.onTertiary, _D.onTertiary);
  static Color get tertiaryContainer =>
      _pick(_L.tertiaryContainer, _D.tertiaryContainer);
  static Color get onTertiaryContainer =>
      _pick(_L.onTertiaryContainer, _D.onTertiaryContainer);
  static Color get tertiaryFixed => _pick(_L.tertiaryFixed, _D.tertiaryFixed);
  static Color get tertiaryFixedDim =>
      _pick(_L.tertiaryFixedDim, _D.tertiaryFixedDim);
  static Color get onTertiaryFixed =>
      _pick(_L.onTertiaryFixed, _D.onTertiaryFixed);
  static Color get onTertiaryFixedVariant =>
      _pick(_L.onTertiaryFixedVariant, _D.onTertiaryFixedVariant);

  // -- Error ---------------------------------------------------------------
  static Color get error => _pick(_L.error, _D.error);
  static Color get onError => _pick(_L.onError, _D.onError);
  static Color get errorContainer =>
      _pick(_L.errorContainer, _D.errorContainer);
  static Color get onErrorContainer =>
      _pick(_L.onErrorContainer, _D.onErrorContainer);

  // -- Brand aliases -------------------------------------------------------
  // The names the brand palette uses, so new code can say what it means.

  /// Pressed / emphasis magenta, and the deep end of brand gradients.
  static Color get primaryDark =>
      _pick(const Color(0xFF8F0038), const Color(0xFFFFB3CB));

  /// The blush tint behind chips, icon tiles and selected rows.
  static Color get softPink => surfaceContainerLow;

  /// Hairline border on cards, inputs and dividers.
  static Color get border => outlineVariant;

  /// Secondary copy: captions, metadata, helper text.
  static Color get textSecondary => onSurfaceVariant;

  // -- Status --------------------------------------------------------------
  // Each tone has a strong hue for icons, a tint for backgrounds, and a text
  // colour that stays readable on that tint.

  /// Rating stars.
  static Color get star =>
      _pick(const Color(0xFFF2A100), const Color(0xFFFFC94D));

  /// Warning / "pending" status.
  static Color get warning =>
      _pick(const Color(0xFFC87900), const Color(0xFFFFC266));
  static Color get warningContainer =>
      _pick(const Color(0xFFFFF3DC), const Color(0xFF3D2800));
  static Color get onWarningContainer =>
      _pick(const Color(0xFF7A4A00), const Color(0xFFFFDDA6));

  /// Success / "confirmed" status.
  static Color get success => tertiary;
  static Color get successContainer =>
      _pick(const Color(0xFFE3F6EC), const Color(0xFF0B3322));
  static Color get onSuccessContainer =>
      _pick(const Color(0xFF0E5E3D), const Color(0xFFA6EBC8));

  /// Info / "in progress" status.
  static Color get info =>
      _pick(const Color(0xFF2E5AAC), const Color(0xFFA9C4FF));
  static Color get infoContainer =>
      _pick(const Color(0xFFE8EFFC), const Color(0xFF13254A));
  static Color get onInfoContainer =>
      _pick(const Color(0xFF1F3F7A), const Color(0xFFD3E1FF));

  /// Danger is the error role; the alias reads better at call sites.
  static Color get danger => error;

  /// The verified tick. Blue by convention: people read a blue check as
  /// "verified" across every social network, so the brand colour would not.
  static Color get verified =>
      _pick(const Color(0xFF1D8CF0), const Color(0xFF5AB0FF));

  /// Online presence dot, vivid in both modes.
  static Color get online => const Color(0xFF16A34A);

  /// Ambient shadow tint: a warm, very low-opacity plum rather than grey, so
  /// cards lift off the blush background without looking dirty.
  static Color get shadow =>
      _pick(const Color(0x0F5C1A3F), const Color(0x40000000));
}

/// Light palette.
abstract final class _L {
  static const surface = Color(0xFFFFF8FA);
  static const surfaceDim = Color(0xFFF3E3E8);
  static const surfaceBright = Color(0xFFFFF8FA);
  static const surfaceContainerLowest = Color(0xFFFFFFFF);
  static const surfaceContainerLow = Color(0xFFFFF1F5);
  static const surfaceContainer = Color(0xFFFCEAF0);
  static const surfaceContainerHigh = Color(0xFFF9E3EB);
  static const surfaceContainerHighest = Color(0xFFF4DAE3);
  static const surfaceVariant = Color(0xFFF4DAE3);
  static const onSurface = Color(0xFF211A1D);
  static const onSurfaceVariant = Color(0xFF746B70);
  static const inverseSurface = Color(0xFF362B30);
  static const inverseOnSurface = Color(0xFFFFEFF4);
  static const surfaceTint = Color(0xFFC9004F);

  static const outline = Color(0xFF9E9196);
  static const outlineVariant = Color(0xFFF0DDE3);

  static const primary = Color(0xFFC9004F);
  static const onPrimary = Color(0xFFFFFFFF);
  static const primaryContainer = Color(0xFFE5246E);
  static const onPrimaryContainer = Color(0xFFFFFFFF);
  static const inversePrimary = Color(0xFFFFB3CB);
  static const primaryFixed = Color(0xFFFFE0EA);
  static const primaryFixedDim = Color(0xFFFFB3CB);
  static const onPrimaryFixed = Color(0xFF3F0018);
  static const onPrimaryFixedVariant = Color(0xFF8F0038);

  static const secondary = Color(0xFF6B1E4A);
  static const onSecondary = Color(0xFFFFFFFF);
  static const secondaryContainer = Color(0xFF8A2D61);
  static const onSecondaryContainer = Color(0xFFFFFFFF);
  static const secondaryFixed = Color(0xFFF8E4EF);
  static const secondaryFixedDim = Color(0xFFE9BFD5);
  static const onSecondaryFixed = Color(0xFF2E0019);
  static const onSecondaryFixedVariant = Color(0xFF6B1E4A);

  static const tertiary = Color(0xFF168A5B);
  static const onTertiary = Color(0xFFFFFFFF);
  static const tertiaryContainer = Color(0xFF1FA06B);
  static const onTertiaryContainer = Color(0xFFFFFFFF);
  static const tertiaryFixed = Color(0xFFC8F1DC);
  static const tertiaryFixedDim = Color(0xFF8ED9B4);
  static const onTertiaryFixed = Color(0xFF002113);
  static const onTertiaryFixedVariant = Color(0xFF0E5E3D);

  static const error = Color(0xFFD92D20);
  static const onError = Color(0xFFFFFFFF);
  static const errorContainer = Color(0xFFFEE4E2);
  static const onErrorContainer = Color(0xFF912018);
}

/// Dark palette: the same hues on warm near-black surfaces.
abstract final class _D {
  static const surface = Color(0xFF161013);
  static const surfaceDim = Color(0xFF161013);
  static const surfaceBright = Color(0xFF3D3237);
  static const surfaceContainerLowest = Color(0xFF110B0E);
  static const surfaceContainerLow = Color(0xFF1F171B);
  static const surfaceContainer = Color(0xFF241B20);
  static const surfaceContainerHigh = Color(0xFF2F252A);
  static const surfaceContainerHighest = Color(0xFF3A2F35);
  static const surfaceVariant = Color(0xFF4D3F45);
  static const onSurface = Color(0xFFF3E5EA);
  static const onSurfaceVariant = Color(0xFFCDBEC4);
  static const inverseSurface = Color(0xFFF3E5EA);
  static const inverseOnSurface = Color(0xFF362B30);
  static const surfaceTint = Color(0xFFFF8DB5);

  static const outline = Color(0xFF978A90);
  static const outlineVariant = Color(0xFF3F3338);

  static const primary = Color(0xFFFF8DB5);
  static const onPrimary = Color(0xFF5E0025);
  static const primaryContainer = Color(0xFFC9004F);
  static const onPrimaryContainer = Color(0xFFFFFFFF);
  static const inversePrimary = Color(0xFFC9004F);
  static const primaryFixed = Color(0xFF4A1027);
  static const primaryFixedDim = Color(0xFFFFB3CB);
  static const onPrimaryFixed = Color(0xFFFFE0EA);
  static const onPrimaryFixedVariant = Color(0xFFFFD1E0);

  static const secondary = Color(0xFFF0A9CF);
  static const onSecondary = Color(0xFF4A0F31);
  static const secondaryContainer = Color(0xFF6B1E4A);
  static const onSecondaryContainer = Color(0xFFFFFFFF);
  static const secondaryFixed = Color(0xFF3B1A2C);
  static const secondaryFixedDim = Color(0xFFE9BFD5);
  static const onSecondaryFixed = Color(0xFFF8E4EF);
  static const onSecondaryFixedVariant = Color(0xFFF8E4EF);

  static const tertiary = Color(0xFF6FD8A6);
  static const onTertiary = Color(0xFF003822);
  static const tertiaryContainer = Color(0xFF168A5B);
  static const onTertiaryContainer = Color(0xFFFFFFFF);
  static const tertiaryFixed = Color(0xFF0B3322);
  static const tertiaryFixedDim = Color(0xFF8ED9B4);
  static const onTertiaryFixed = Color(0xFFC8F1DC);
  static const onTertiaryFixedVariant = Color(0xFFA6EBC8);

  static const error = Color(0xFFFF9C94);
  static const onError = Color(0xFF5F0D07);
  static const errorContainer = Color(0xFF4A120D);
  static const onErrorContainer = Color(0xFFFFDAD6);
}
