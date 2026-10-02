import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'app_colors.dart';
import 'app_spacing.dart';
import 'app_typography.dart';

export 'app_colors.dart';
export 'app_shadows.dart';
export 'app_spacing.dart';
export 'app_typography.dart';

/// Assembles the Zusty [ThemeData] from the design tokens.
abstract final class AppTheme {
  /// Builds the theme for [brightness].
  ///
  /// Sets [AppColors.brightness] first so every token getter — and therefore
  /// every widget that reads `AppColors.x` during build — resolves against the
  /// right palette.
  static ThemeData of(Brightness brightness) {
    AppColors.brightness = brightness;
    return brightness == Brightness.dark ? _build(true) : _build(false);
  }

  static ThemeData get light => of(Brightness.light);
  static ThemeData get dark => of(Brightness.dark);

  static ColorScheme get colorScheme => ColorScheme(
        brightness: AppColors.brightness,
        primary: AppColors.primary,
        onPrimary: AppColors.onPrimary,
        primaryContainer: AppColors.primaryContainer,
        onPrimaryContainer: AppColors.onPrimaryContainer,
        primaryFixed: AppColors.primaryFixed,
        primaryFixedDim: AppColors.primaryFixedDim,
        onPrimaryFixed: AppColors.onPrimaryFixed,
        onPrimaryFixedVariant: AppColors.onPrimaryFixedVariant,
        secondary: AppColors.secondary,
        onSecondary: AppColors.onSecondary,
        secondaryContainer: AppColors.secondaryContainer,
        onSecondaryContainer: AppColors.onSecondaryContainer,
        secondaryFixed: AppColors.secondaryFixed,
        secondaryFixedDim: AppColors.secondaryFixedDim,
        onSecondaryFixed: AppColors.onSecondaryFixed,
        onSecondaryFixedVariant: AppColors.onSecondaryFixedVariant,
        tertiary: AppColors.tertiary,
        onTertiary: AppColors.onTertiary,
        tertiaryContainer: AppColors.tertiaryContainer,
        onTertiaryContainer: AppColors.onTertiaryContainer,
        tertiaryFixed: AppColors.tertiaryFixed,
        tertiaryFixedDim: AppColors.tertiaryFixedDim,
        onTertiaryFixed: AppColors.onTertiaryFixed,
        onTertiaryFixedVariant: AppColors.onTertiaryFixedVariant,
        error: AppColors.error,
        onError: AppColors.onError,
        errorContainer: AppColors.errorContainer,
        onErrorContainer: AppColors.onErrorContainer,
        surface: AppColors.surface,
        onSurface: AppColors.onSurface,
        surfaceDim: AppColors.surfaceDim,
        surfaceBright: AppColors.surfaceBright,
        surfaceContainerLowest: AppColors.surfaceContainerLowest,
        surfaceContainerLow: AppColors.surfaceContainerLow,
        surfaceContainer: AppColors.surfaceContainer,
        surfaceContainerHigh: AppColors.surfaceContainerHigh,
        surfaceContainerHighest: AppColors.surfaceContainerHighest,
        onSurfaceVariant: AppColors.onSurfaceVariant,
        outline: AppColors.outline,
        outlineVariant: AppColors.outlineVariant,
        inverseSurface: AppColors.inverseSurface,
        onInverseSurface: AppColors.inverseOnSurface,
        inversePrimary: AppColors.inversePrimary,
        surfaceTint: AppColors.surfaceTint,
        shadow: const Color(0xFF000000),
        scrim: const Color(0xFF000000),
      );

  static ThemeData _build(bool isDark) {
    final scheme = colorScheme;
    final text = AppTypography.textTheme;

    return ThemeData(
      useMaterial3: true,
      brightness: isDark ? Brightness.dark : Brightness.light,
      colorScheme: scheme,
      scaffoldBackgroundColor: AppColors.surface,
      canvasColor: AppColors.surface,
      textTheme: text,
      splashFactory: InkSparkle.splashFactory,
      visualDensity: VisualDensity.standard,

      // -- App bar ---------------------------------------------------------
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.surface,
        surfaceTintColor: Colors.transparent,
        foregroundColor: AppColors.onSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: AppTypography.headlineSmall,
        iconTheme: IconThemeData(color: AppColors.onSurface, size: 24),
        actionsIconTheme:
            IconThemeData(color: AppColors.onSurface, size: 24),
        systemOverlayStyle:
            (isDark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark)
                .copyWith(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness:
              isDark ? Brightness.light : Brightness.dark,
          statusBarBrightness: isDark ? Brightness.dark : Brightness.light,
        ),
      ),

      // -- Buttons ---------------------------------------------------------
      // Pill-shaped, 52 tall, full-width on mobile. Solid magenta for the one
      // primary action on a screen; outlined for everything beside it.
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: AppColors.onPrimary,
          disabledBackgroundColor: AppColors.surfaceContainerHighest,
          disabledForegroundColor: AppColors.outline,
          minimumSize: const Size.fromHeight(52),
          elevation: 0,
          shadowColor: Colors.transparent,
          textStyle: AppTypography.button,
          shape: const StadiumBorder(),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: AppColors.onPrimary,
          minimumSize: const Size.fromHeight(52),
          textStyle: AppTypography.button,
          shape: const StadiumBorder(),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.primary,
          backgroundColor: AppColors.surfaceContainerLowest,
          minimumSize: const Size.fromHeight(52),
          side: BorderSide(color: AppColors.primary.withValues(alpha: 0.35)),
          textStyle: AppTypography.button,
          shape: const StadiumBorder(),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.primary,
          textStyle: AppTypography.labelMedium,
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.xs,
            vertical: AppSpacing.base,
          ),
          minimumSize: Size.zero,
          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(
          foregroundColor: AppColors.onSurface,
          highlightColor: AppColors.primaryFixed.withValues(alpha: 0.4),
          minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
        ),
      ),

      // -- Inputs ----------------------------------------------------------
      // White fill, hairline blush border, magenta focus ring.
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surfaceContainerLowest,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.md - 1,
        ),
        hintStyle: AppTypography.bodyMedium
            .copyWith(color: AppColors.outline),
        labelStyle: AppTypography.bodyMedium
            .copyWith(color: AppColors.onSurfaceVariant),
        floatingLabelStyle:
            AppTypography.labelMedium.copyWith(color: AppColors.primary),
        errorStyle: AppTypography.bodySmall.copyWith(color: AppColors.error),
        errorMaxLines: 2,
        prefixIconColor: AppColors.outline,
        suffixIconColor: AppColors.outline,
        border: OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.outlineVariant),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.outlineVariant),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.primary, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.error),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.error, width: 1.5),
        ),
        disabledBorder: OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.outlineVariant),
        ),
      ),

      // -- Containers ------------------------------------------------------
      cardTheme: CardThemeData(
        color: AppColors.surfaceContainerLowest,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.xlAll,
          side: BorderSide(color: AppColors.outlineVariant),
        ),
      ),
      dividerTheme: DividerThemeData(
        color: AppColors.outlineVariant,
        thickness: 1,
        space: 1,
      ),
      chipTheme: ChipThemeData(
        backgroundColor: AppColors.surfaceContainerLowest,
        selectedColor: AppColors.primary,
        secondarySelectedColor: AppColors.primary,
        labelStyle: AppTypography.labelMedium,
        secondaryLabelStyle:
            AppTypography.labelMedium.copyWith(color: AppColors.onPrimary),
        side: BorderSide(color: AppColors.outlineVariant),
        showCheckmark: false,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.sm,
          vertical: AppSpacing.xs,
        ),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.pill),
      ),

      // -- Overlays --------------------------------------------------------
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: AppColors.surfaceContainerLowest,
        surfaceTintColor: Colors.transparent,
        modalBackgroundColor: AppColors.surfaceContainerLowest,
        elevation: 0,
        showDragHandle: true,
        dragHandleColor: AppColors.outlineVariant,
        shape: RoundedRectangleBorder(
          borderRadius:
              BorderRadius.vertical(top: Radius.circular(AppRadius.xxl)),
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: AppColors.surfaceContainerLowest,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        titleTextStyle: AppTypography.headlineSmall,
        contentTextStyle: AppTypography.bodyMedium
            .copyWith(color: AppColors.onSurfaceVariant),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.xxlAll),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: AppColors.inverseSurface,
        contentTextStyle: AppTypography.bodyMedium
            .copyWith(color: AppColors.inverseOnSurface),
        actionTextColor: AppColors.inversePrimary,
        behavior: SnackBarBehavior.floating,
        elevation: 0,
        insetPadding: const EdgeInsets.fromLTRB(
          AppSpacing.md,
          0,
          AppSpacing.md,
          AppSpacing.md,
        ),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.lgAll),
      ),
      tooltipTheme: TooltipThemeData(
        decoration: BoxDecoration(
          color: AppColors.inverseSurface,
          borderRadius: AppRadius.smAll,
        ),
        textStyle: AppTypography.labelMedium
            .copyWith(color: AppColors.inverseOnSurface),
      ),

      // -- Navigation ------------------------------------------------------
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: AppColors.surfaceContainerLowest,
        selectedItemColor: AppColors.primary,
        unselectedItemColor: AppColors.onSurfaceVariant,
        selectedLabelStyle: AppTypography.labelMedium,
        unselectedLabelStyle: AppTypography.labelMedium,
        type: BottomNavigationBarType.fixed,
        elevation: 0,
      ),
      tabBarTheme: TabBarThemeData(
        labelColor: AppColors.primary,
        unselectedLabelColor: AppColors.onSurfaceVariant,
        labelStyle: AppTypography.titleSmall,
        unselectedLabelStyle: AppTypography.bodyMedium,
        indicator: UnderlineTabIndicator(
          borderSide: BorderSide(color: AppColors.primary, width: 2.5),
          borderRadius: const BorderRadius.vertical(top: Radius.circular(3)),
        ),
        indicatorSize: TabBarIndicatorSize.label,
        dividerColor: AppColors.outlineVariant,
        overlayColor: WidgetStatePropertyAll(
          AppColors.primaryFixed.withValues(alpha: 0.3),
        ),
      ),

      // -- Controls --------------------------------------------------------
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((states) =>
            states.contains(WidgetState.selected)
                ? AppColors.onPrimary
                : AppColors.surfaceContainerLowest),
        trackColor: WidgetStateProperty.resolveWith((states) =>
            states.contains(WidgetState.selected)
                ? AppColors.primary
                : AppColors.surfaceContainerHighest),
        trackOutlineColor:
            WidgetStatePropertyAll(AppColors.outlineVariant),
      ),
      checkboxTheme: CheckboxThemeData(
        fillColor: WidgetStateProperty.resolveWith((states) =>
            states.contains(WidgetState.selected)
                ? AppColors.primary
                : Colors.transparent),
        checkColor: WidgetStatePropertyAll(AppColors.onPrimary),
        side: BorderSide(color: AppColors.outline, width: 1.5),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.smAll),
      ),
      radioTheme: RadioThemeData(
        fillColor: WidgetStateProperty.resolveWith((states) =>
            states.contains(WidgetState.selected)
                ? AppColors.primary
                : AppColors.outline),
      ),
      sliderTheme: SliderThemeData(
        activeTrackColor: AppColors.primary,
        inactiveTrackColor: AppColors.surfaceContainerHighest,
        thumbColor: AppColors.primary,
        overlayColor: Color(0x1FC9004F),
        rangeThumbShape: RoundRangeSliderThumbShape(enabledThumbRadius: 10),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: AppColors.primary,
        linearTrackColor: AppColors.surfaceContainerHighest,
        circularTrackColor: AppColors.surfaceContainerHighest,
      ),
      listTileTheme: ListTileThemeData(
        iconColor: AppColors.onSurfaceVariant,
        textColor: AppColors.onSurface,
        titleTextStyle: AppTypography.bodyLarge,
        subtitleTextStyle: AppTypography.bodyMedium
            .copyWith(color: AppColors.onSurfaceVariant),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.mdAll),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.base,
        ),
      ),
    );
  }
}
