import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/privacy.dart';
import '../../../shared/widgets/z_card.dart';

/// The header of a privacy screen: an icon on a soft disc, a title and a
/// short explanation.
class PrivacyHero extends StatelessWidget {
  const PrivacyHero({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.color,
  });

  final IconData icon;
  final String title;
  final String message;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final tint = color ?? AppColors.primary;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 56,
          height: 56,
          decoration: BoxDecoration(
            color: tint.withValues(alpha: 0.12),
            borderRadius: AppRadius.lgAll,
          ),
          child: Icon(icon, color: tint, size: 28),
        ),
        const SizedBox(height: AppSpacing.md),
        Semantics(
          header: true,
          child: Text(title, style: AppTypography.headlineMedium),
        ),
        const SizedBox(height: AppSpacing.xs),
        Text(
          message,
          style: AppTypography.bodyMedium.copyWith(
            color: AppColors.onSurfaceVariant,
            height: 1.5,
          ),
        ),
      ],
    );
  }
}

/// A small, quiet section label above a card.
class PrivacySectionTitle extends StatelessWidget {
  const PrivacySectionTitle(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(
        left: AppSpacing.xs,
        top: AppSpacing.lg,
        bottom: AppSpacing.xs,
      ),
      child: Semantics(
        header: true,
        child: Text(
          text,
          style: AppTypography.labelLarge.copyWith(color: AppColors.onSurfaceVariant),
        ),
      ),
    );
  }
}

/// A card listing what an export contains, what deletion erases, or what is
/// kept, one titled line each.
class PrivacyListCard extends StatelessWidget {
  const PrivacyListCard({
    super.key,
    required this.items,
    required this.icon,
    required this.iconColor,
    this.footer,
  });

  final List<PrivacyItem> items;
  final IconData icon;
  final Color iconColor;
  final String? footer;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      shadow: false,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < items.length; i++) ...[
            if (i > 0) const SizedBox(height: AppSpacing.sm),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 1),
                  child: Icon(icon, size: 20, color: iconColor),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(items[i].title, style: AppTypography.titleSmall),
                      if (items[i].detail.isNotEmpty) ...[
                        const SizedBox(height: 2),
                        Text(
                          items[i].detail,
                          style: AppTypography.bodySmall.copyWith(height: 1.45),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ],
          if (footer != null && footer!.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.md),
            Divider(height: 1, color: AppColors.outlineVariant),
            const SizedBox(height: AppSpacing.sm),
            Text(
              footer!,
              style: AppTypography.bodySmall.copyWith(
                color: AppColors.onSurfaceVariant,
                height: 1.45,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
