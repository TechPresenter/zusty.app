import 'package:flutter/material.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../motion/pressable.dart';

/// Visual weights available to [ZButton], per DESIGN.md § Buttons.
enum ZButtonVariant {
  /// Solid primary pink fill — the single main action on a screen.
  primary,

  /// Light pink tint — a supporting action beside a primary.
  tonal,

  /// 1px outlined ghost on white.
  outlined,

  /// Text-only, no container.
  text,

  /// Solid violet — used for secondary brand moments (chat, referrals).
  secondary,

  /// Solid red — destructive actions such as cancelling a booking.
  danger,
}

enum ZButtonSize { small, medium, large }

/// The app's button. Handles the loading spinner, icon slots, and the
/// full-width-on-mobile rule so screens never re-implement them.
class ZButton extends StatelessWidget {
  const ZButton({
    super.key,
    required this.label,
    this.onPressed,
    this.variant = ZButtonVariant.primary,
    this.size = ZButtonSize.large,
    this.icon,
    this.trailingIcon,
    this.isLoading = false,
    this.expand = true,
    this.pill = true,
  });

  /// Convenience constructor for a supporting action.
  const ZButton.tonal({
    super.key,
    required this.label,
    this.onPressed,
    this.size = ZButtonSize.large,
    this.icon,
    this.trailingIcon,
    this.isLoading = false,
    this.expand = true,
    this.pill = true,
  }) : variant = ZButtonVariant.tonal;

  const ZButton.outlined({
    super.key,
    required this.label,
    this.onPressed,
    this.size = ZButtonSize.large,
    this.icon,
    this.trailingIcon,
    this.isLoading = false,
    this.expand = true,
    this.pill = true,
  }) : variant = ZButtonVariant.outlined;

  final String label;
  final VoidCallback? onPressed;
  final ZButtonVariant variant;
  final ZButtonSize size;
  final IconData? icon;
  final IconData? trailingIcon;
  final bool isLoading;

  /// When false the button hugs its content instead of filling the row.
  final bool expand;

  /// Fully-rounded shape, used on the onboarding and auth screens.
  final bool pill;

  bool get _isEnabled => onPressed != null && !isLoading;

  BorderRadius get _radius => pill ? AppRadius.pill : AppRadius.lgAll;

  double get _height => switch (size) {
    ZButtonSize.small => 40,
    ZButtonSize.medium => 46,
    ZButtonSize.large => 52,
  };

  @override
  Widget build(BuildContext context) {
    final colors = _colorsFor(variant);

    // The label and the spinner cross-fade rather than swap.
    final child = AnimatedSwitcher(
      duration: Motion.of(context, Motion.fast),
      switchInCurve: Motion.standard,
      switchOutCurve: Motion.exit,
      child: isLoading
          ? SizedBox(
              key: const ValueKey('loading'),
              width: 20,
              height: 20,
              child: CircularProgressIndicator(
                strokeWidth: 2.2,
                valueColor: AlwaysStoppedAnimation(colors.foreground),
              ),
            )
          : Row(
              key: const ValueKey('label'),
              mainAxisSize: expand ? MainAxisSize.max : MainAxisSize.min,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (icon != null) ...[
                  Icon(icon, size: size == ZButtonSize.small ? 16 : 18),
                  const SizedBox(width: AppSpacing.xs),
                ],
                Flexible(
                  child: Text(
                    label,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.button.copyWith(
                      fontSize: size == ZButtonSize.small ? 14 : 16,
                    ),
                  ),
                ),
                if (trailingIcon != null) ...[
                  const SizedBox(width: AppSpacing.xs),
                  Icon(trailingIcon, size: size == ZButtonSize.small ? 16 : 18),
                ],
              ],
            ),
    );

    final button = Pressable(
      enabled: _isEnabled,
      child: Material(
        color: _isEnabled
            ? colors.background
            : AppColors.surfaceContainerHighest,
        borderRadius: _radius,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: _isEnabled ? onPressed : null,
          splashColor: colors.foreground.withValues(alpha: 0.10),
          highlightColor: colors.foreground.withValues(alpha: 0.05),
          child: Container(
            height: _height,
            padding: EdgeInsets.symmetric(
              horizontal: size == ZButtonSize.small
                  ? AppSpacing.md
                  : AppSpacing.lg,
            ),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              borderRadius: _radius,
              border: colors.border == null
                  ? null
                  : Border.all(color: colors.border!),
            ),
            child: DefaultTextStyle.merge(
              style: TextStyle(
                color: _isEnabled ? colors.foreground : AppColors.outline,
              ),
              child: IconTheme.merge(
                data: IconThemeData(
                  color: _isEnabled ? colors.foreground : AppColors.outline,
                ),
                child: child,
              ),
            ),
          ),
        ),
      ),
    );

    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }

  static _ButtonColors _colorsFor(ZButtonVariant variant) => switch (variant) {
    ZButtonVariant.primary => _ButtonColors(
      background: AppColors.primary,
      foreground: AppColors.onPrimary,
    ),
    ZButtonVariant.secondary => _ButtonColors(
      background: AppColors.secondary,
      foreground: AppColors.onSecondary,
    ),
    ZButtonVariant.tonal => _ButtonColors(
      background: AppColors.primaryFixed,
      foreground: AppColors.primaryDark,
    ),
    ZButtonVariant.outlined => _ButtonColors(
      background: AppColors.surfaceContainerLowest,
      foreground: AppColors.primary,
      border: AppColors.primary.withValues(alpha: 0.3),
    ),
    ZButtonVariant.text => _ButtonColors(
      background: Colors.transparent,
      foreground: AppColors.primary,
    ),
    ZButtonVariant.danger => _ButtonColors(
      background: AppColors.error,
      foreground: AppColors.onError,
    ),
  };
}

class _ButtonColors {
  const _ButtonColors({
    required this.background,
    required this.foreground,
    this.border,
  });

  final Color background;
  final Color foreground;
  final Color? border;
}

/// A circular icon button on a tonal surface — the share/favourite controls in
/// the creator profile header and the back button on image screens.
class ZIconButton extends StatelessWidget {
  const ZIconButton({
    super.key,
    required this.icon,
    this.onPressed,
    this.size = 40,
    this.background,
    this.foreground,
    this.tooltip,
  });

  final IconData icon;
  final VoidCallback? onPressed;
  final double size;

  /// Defaults to the tonal pink pair; resolved at build time so the palette
  /// follows the active light/dark theme.
  final Color? background;
  final Color? foreground;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    final button = Pressable(
      enabled: onPressed != null,
      pressedScale: 0.9,
      child: Material(
        color: background ?? AppColors.primaryFixed,
        shape: const CircleBorder(),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onPressed,
          child: SizedBox(
            width: size,
            height: size,
            child: Icon(
              icon,
              size: size * 0.5,
              color: foreground ?? AppColors.primary,
            ),
          ),
        ),
      ),
    );

    return tooltip == null ? button : Tooltip(message: tooltip!, child: button);
  }
}
