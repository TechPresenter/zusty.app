import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';

/// A settings / profile menu row: an icon in a blush square, a title with an
/// optional subtitle, and a chevron, badge, value or switch on the right.
class ZMenuTile extends StatelessWidget {
  const ZMenuTile({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.onTap,
    this.trailing,
    this.value,
    this.badge,
    this.destructive = false,
    this.showChevron = true,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final VoidCallback? onTap;

  /// Replaces the chevron entirely (a switch, a status chip).
  final Widget? trailing;

  /// A short current value before the chevron, e.g. "English".
  final String? value;

  /// A count bubble before the chevron; hidden at zero.
  final int? badge;

  /// Red icon and label, for Log out / Delete account.
  final bool destructive;
  final bool showChevron;

  @override
  Widget build(BuildContext context) {
    final tint = destructive ? AppColors.error : AppColors.primary;

    // Each row is its own semantics node: a button labelled with its title
    // and subtitle. Without the boundary, a section's only row merged into
    // the section title and was not announced as a button.
    return MergeSemantics(
      child: Semantics(
        button: onTap != null,
        child: _ink(tint),
      ),
    );
  }

  Widget _ink(Color tint) {
    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 56),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.sm,
          ),
          child: Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: destructive
                      ? AppColors.errorContainer
                      : AppColors.surfaceContainerLow,
                  borderRadius: AppRadius.mdAll,
                ),
                child: Icon(icon, size: 20, color: tint),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: AppTypography.titleSmall.copyWith(
                        fontWeight: FontWeight.w500,
                        color: destructive
                            ? AppColors.error
                            : AppColors.onSurface,
                      ),
                    ),
                    if (subtitle != null && subtitle!.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        subtitle!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall,
                      ),
                    ],
                  ],
                ),
              ),
              if (trailing != null)
                trailing!
              else ...[
                if (value != null)
                  Padding(
                    padding: const EdgeInsets.only(left: AppSpacing.xs),
                    child: Text(value!, style: AppTypography.bodySmall),
                  ),
                if (badge != null && badge! > 0)
                  Container(
                    margin: const EdgeInsets.only(left: AppSpacing.xs),
                    padding: const EdgeInsets.symmetric(
                      horizontal: 7,
                      vertical: 2,
                    ),
                    decoration: BoxDecoration(
                      color: AppColors.primary,
                      borderRadius: AppRadius.pill,
                    ),
                    child: Text(
                      badge! > 99 ? '99+' : '$badge',
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.onPrimary,
                      ),
                    ),
                  ),
                if (showChevron && onTap != null)
                  Padding(
                    padding: const EdgeInsets.only(left: AppSpacing.xs),
                    child: Icon(
                      Icons.chevron_right_rounded,
                      size: 22,
                      color: AppColors.outline,
                    ),
                  ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// A titled group of [ZMenuTile]s on one card, with hairlines between rows.
class ZMenuSection extends StatelessWidget {
  const ZMenuSection({super.key, required this.children, this.title});

  final String? title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title != null)
          Padding(
            padding: const EdgeInsets.only(
              left: AppSpacing.xs,
              bottom: AppSpacing.xs,
            ),
            child: Text(
              title!.toUpperCase(),
              style: AppTypography.labelSmall.copyWith(letterSpacing: 0.8),
            ),
          ),
        // The card is the Material the tiles' InkWells paint on, so hover
        // and press ink is clipped to its rounded corners rather than drawn
        // as a square on whatever Material sits further up the tree.
        Material(
          color: AppColors.surfaceContainerLowest,
          clipBehavior: Clip.antiAlias,
          shape: RoundedRectangleBorder(
            borderRadius: AppRadius.xlAll,
            side: BorderSide(color: AppColors.outlineVariant),
          ),
          child: Column(
            children: [
              for (var i = 0; i < children.length; i++) ...[
                if (i > 0)
                  Divider(
                    height: 1,
                    indent: AppSpacing.md + 36 + AppSpacing.sm,
                    color: AppColors.outlineVariant,
                  ),
                children[i],
              ],
            ],
          ),
        ),
      ],
    );
  }
}
