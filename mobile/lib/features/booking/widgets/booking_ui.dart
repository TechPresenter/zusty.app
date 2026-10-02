import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/booking.dart';
import '../../../shared/widgets/platform_icon.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_avatar.dart';
import '../../../shared/widgets/z_image.dart';

/// Small building blocks shared by the booking, payment, wallet, billing and
/// calendar screens, so the money screens read as one family.

/// A rounded square holding one glyph on a soft tint of [color] — the leading
/// mark on ledger rows, deliverables, offers and summary tiles.
class TintedIconTile extends StatelessWidget {
  const TintedIconTile({
    super.key,
    required this.icon,
    this.color,
    this.background,
    this.size = 40,
  });

  final IconData icon;

  /// Glyph colour; magenta when omitted.
  final Color? color;

  /// Defaults to a 12% wash of [color].
  final Color? background;
  final double size;

  @override
  Widget build(BuildContext context) {
    final tint = color ?? AppColors.primary;

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: background ?? tint.withValues(alpha: 0.12),
        borderRadius: AppRadius.mdAll,
      ),
      child: Icon(icon, size: size * 0.48, color: tint),
    );
  }
}

/// The other party's avatar with the campaign's platform pinned to its
/// corner.
///
/// Bookings carry no artwork of their own, so rather than a stock photo the
/// card leads with the person the booking is with, and the small network
/// badge says where the content will run.
class BookingAvatar extends StatelessWidget {
  const BookingAvatar({
    super.key,
    required this.counterparty,
    this.platform = '',
    this.size = 52,
  });

  final BookingCounterparty counterparty;
  final String platform;
  final double size;

  @override
  Widget build(BuildContext context) {
    final avatar = ZAvatar(
      imageUrl: counterparty.avatarUrl,
      name: counterparty.name,
      size: size,
    );

    if (platform.isEmpty) return avatar;

    final badge = (size * 0.42).clamp(18.0, 26.0);

    return SizedBox(
      width: size + 4,
      height: size + 4,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          avatar,
          Positioned(
            right: 0,
            bottom: 0,
            child: Container(
              padding: const EdgeInsets.all(2),
              decoration: BoxDecoration(
                color: AppColors.surfaceContainerLowest,
                shape: BoxShape.circle,
              ),
              child: PlatformIcon(platform: platform, size: badge),
            ),
          ),
        ],
      ),
    );
  }
}

/// A caption over a value — "Booking ID / ZB2400472" — for the compact fact
/// strips on cards.
class LabeledValue extends StatelessWidget {
  const LabeledValue({
    super.key,
    required this.label,
    required this.value,
    this.valueColor,
    this.emphasise = false,
    this.align = CrossAxisAlignment.start,
  });

  final String label;
  final String value;
  final Color? valueColor;
  final bool emphasise;
  final CrossAxisAlignment align;

  @override
  Widget build(BuildContext context) {
    final textAlign = switch (align) {
      CrossAxisAlignment.end => TextAlign.right,
      CrossAxisAlignment.center => TextAlign.center,
      _ => TextAlign.left,
    };

    return Column(
      crossAxisAlignment: align,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textAlign: textAlign,
          style: AppTypography.labelSmall.copyWith(
            color: AppColors.onSurfaceVariant,
            fontWeight: FontWeight.w500,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textAlign: textAlign,
          style:
              (emphasise
                      ? AppTypography.titleSmall.copyWith(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                        )
                      : AppTypography.labelLarge.copyWith(fontSize: 13))
                  .copyWith(color: valueColor ?? AppColors.onSurface),
        ),
      ],
    );
  }
}

/// The heading above a grouped card on the detail screens: a title, an
/// optional count or hint on the right, and the standard gap beneath.
class PanelHeading extends StatelessWidget {
  const PanelHeading(this.title, {super.key, this.trailing, this.subtitle});

  final String title;
  final String? subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(
        top: AppSpacing.lg,
        bottom: AppSpacing.xs,
        left: 2,
        right: 2,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: AppTypography.titleMedium.copyWith(fontSize: 17),
                ),
                if (subtitle != null) ...[
                  const SizedBox(height: 2),
                  Text(subtitle!, style: AppTypography.bodySmall),
                ],
              ],
            ),
          ),
          if (trailing != null) ...[
            const SizedBox(width: AppSpacing.xs),
            trailing!,
          ],
        ],
      ),
    );
  }
}

/// The figure that matters most on a money screen, in a soft tinted panel:
/// "Total payable ₹30,680.00".
class TotalPanel extends StatelessWidget {
  const TotalPanel({
    super.key,
    required this.label,
    required this.value,
    this.caption,
    this.color,
  });

  final String label;
  final String value;
  final String? caption;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final tint = color ?? AppColors.primary;

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: 0.07),
        borderRadius: AppRadius.lgAll,
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: AppTypography.bodyMedium.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                if (caption != null)
                  Text(
                    caption!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodySmall,
                  ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Flexible(
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerRight,
              child: Text(
                value,
                style: AppTypography.headlineSmall.copyWith(
                  color: tint,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A full-width shimmer slab in card shape, for page skeletons.
class ShimmerBlock extends StatelessWidget {
  const ShimmerBlock({
    super.key,
    required this.height,
    this.borderRadius = AppRadius.xlAll,
  });

  final double height;
  final BorderRadius borderRadius;

  @override
  Widget build(BuildContext context) =>
      ZShimmer(height: height, borderRadius: borderRadius);
}

/// Opens [url] outside the app, adding a scheme when a creator pasted a bare
/// domain, and says so when nothing could open it.
Future<void> openExternalLink(BuildContext context, String url) async {
  final uri = Uri.tryParse(url.contains('://') ? url : 'https://$url');

  final opened =
      uri != null && await launchUrl(uri, mode: LaunchMode.externalApplication);

  if (!opened && context.mounted) {
    showZSnack(context, 'Could not open that link.', isError: true);
  }
}

/// A text link out of the app with a touch target that meets the 44px
/// minimum.
class LinkButton extends StatelessWidget {
  const LinkButton({
    super.key,
    required this.label,
    required this.icon,
    required this.url,
  });

  final String label;
  final IconData icon;
  final String url;

  @override
  Widget build(BuildContext context) {
    return TextButton.icon(
      onPressed: () => openExternalLink(context, url),
      style: TextButton.styleFrom(
        foregroundColor: AppColors.primary,
        minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
      ),
      icon: Icon(icon, size: 16),
      label: Text(label, style: AppTypography.labelLarge),
    );
  }
}
