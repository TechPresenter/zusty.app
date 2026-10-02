import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/menu_tile.dart';

/// A titled group of rows on one card — [ZMenuSection] with the heading in
/// sentence case and an optional note underneath.
///
/// [ZMenuSection] upper-cases its own title. The profile hub's section names
/// are admin-visible copy (an admin-built drawer section is titled with the
/// parent entry's label, exactly as typed in the panel), so the heading is
/// drawn here as written and the card is left to [ZMenuSection].
class SettingsGroup extends StatelessWidget {
  const SettingsGroup({
    super.key,
    required this.children,
    this.title,
    this.footer,
    this.padding = const EdgeInsets.only(top: AppSpacing.lg),
  });

  final String? title;
  final List<Widget> children;

  /// A short explanation under the card ("Changes take effect at once").
  final String? footer;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (title != null)
            Padding(
              padding: const EdgeInsets.only(
                left: AppSpacing.xs,
                bottom: AppSpacing.xs,
              ),
              child: Semantics(
                header: true,
                child: Text(
                  title!,
                  style: AppTypography.labelLarge.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
              ),
            ),
          ZMenuSection(children: children),
          if (footer != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.xs,
                AppSpacing.xs,
                AppSpacing.xs,
                0,
              ),
              child: Text(footer!, style: AppTypography.bodySmall),
            ),
        ],
      ),
    );
  }
}

/// A [ZMenuTile] with an on/off switch, the whole row tappable.
///
/// [busy] swaps the switch for a small spinner while the change is saving,
/// so a slow network never looks like a switch that ignored the tap.
class SettingsSwitchTile extends StatelessWidget {
  const SettingsSwitchTile({
    super.key,
    required this.icon,
    required this.title,
    required this.value,
    required this.onChanged,
    this.subtitle,
    this.busy = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final bool value;
  final ValueChanged<bool>? onChanged;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final enabled = onChanged != null && !busy;

    return MergeSemantics(
      child: ZMenuTile(
        icon: icon,
        title: title,
        subtitle: subtitle,
        onTap: enabled ? () => onChanged!(!value) : null,
        trailing: busy
            ? SizedBox(
                width: 52,
                height: 32,
                child: Center(
                  child: SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: AppColors.primary,
                    ),
                  ),
                ),
              )
            : Switch.adaptive(
                value: value,
                onChanged: enabled ? onChanged : null,
                activeTrackColor: AppColors.primary,
              ),
      ),
    );
  }
}
