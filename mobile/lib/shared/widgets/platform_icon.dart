import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/app_theme.dart';
import '../../models/taxonomy.dart';
import '../../providers/taxonomy_provider.dart';

/// Glyphs and brand colours for social networks.
///
/// Driven by the icon NAME and colour the admin-managed `social_platform`
/// taxonomy sends, so a network an operator adds tomorrow gets a sensible glyph
/// without an app release. The slug fallback covers the window before the
/// taxonomy has loaded.
abstract final class PlatformIcons {
  static IconData forIconName(String? name) => switch (name) {
        'instagram' => Icons.camera_alt_rounded,
        'youtube' => Icons.smart_display_rounded,
        'facebook' => Icons.facebook_rounded,
        'twitter' => Icons.alternate_email_rounded,
        'linkedin' => Icons.business_center_rounded,
        'snapchat' => Icons.chat_bubble_rounded,
        'pinterest' => Icons.push_pin_rounded,
        'threads' => Icons.tag_rounded,
        'tiktok' => Icons.music_note_rounded,
        'play' => Icons.play_circle_rounded,
        'chat' => Icons.forum_rounded,
        'external' => Icons.language_rounded,
        'grid' => Icons.grid_view_rounded,
        'file' => Icons.article_rounded,
        'upload' => Icons.upload_file_rounded,
        'link' => Icons.link_rounded,
        _ => Icons.public_rounded,
      };

  /// `#RRGGBB` / `#AARRGGBB` to a colour, or null when it cannot be read.
  static Color? parseHex(String? hex) {
    final value = hex?.replaceFirst('#', '');
    if (value == null || (value.length != 6 && value.length != 8)) return null;

    final parsed = int.tryParse(value, radix: 16);
    if (parsed == null) return null;

    return Color(value.length == 6 ? 0xFF000000 | parsed : parsed);
  }
}

/// One network's glyph in a soft tinted circle, e.g. on a creator card.
class PlatformIcon extends ConsumerWidget {
  const PlatformIcon({
    super.key,
    required this.platform,
    this.size = 24,
    this.iconName,
    this.color,
  });

  /// The `social_platform` value, e.g. `instagram`.
  final String platform;
  final double size;

  /// Overrides for callers that already hold the term (a social account row).
  final String? iconName;
  final String? color;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final term = ref.watch(taxonomiesProvider).term(Tax.socialPlatform, platform);

    final tint = PlatformIcons.parseHex(color ?? term?.color) ?? AppColors.primary;

    // Pale brand colours (Snapchat's yellow) vanish as a glyph on white, so
    // those get a stronger fill and a dark glyph instead.
    final pale = tint.computeLuminance() > 0.6;
    final glyph = pale ? AppColors.onSurface : tint;

    return Tooltip(
      message: term?.label ?? platform,
      child: Container(
        width: size,
        height: size,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: tint.withValues(alpha: pale ? 0.45 : 0.10),
        ),
        child: Icon(
          PlatformIcons.forIconName(iconName ?? term?.icon ?? platform),
          size: size * 0.58,
          color: glyph,
        ),
      ),
    );
  }
}

/// The first few of a creator's networks, overlapping slightly, with a "+N"
/// for the rest.
class PlatformIconRow extends StatelessWidget {
  const PlatformIconRow({
    super.key,
    required this.platforms,
    this.max = 4,
    this.size = 22,
  });

  final List<String> platforms;
  final int max;
  final double size;

  @override
  Widget build(BuildContext context) {
    if (platforms.isEmpty) return const SizedBox.shrink();

    final shown = platforms.take(max).toList();
    final extra = platforms.length - shown.length;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (final platform in shown)
          Padding(
            padding: const EdgeInsets.only(right: 4),
            child: PlatformIcon(platform: platform, size: size),
          ),
        if (extra > 0)
          Text(
            '+$extra',
            style: AppTypography.labelSmall,
          ),
      ],
    );
  }
}
