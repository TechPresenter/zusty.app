import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_theme.dart';
import '../../../providers/theme_provider.dart';
import '../../../shared/motion/motion.dart';

/// Light / Dark / System, as three equal tiles.
///
/// The header carries a quick sun/moon toggle for the common flip; this is
/// the full control, including "System" for people who switch with their
/// device. Shared by the profile hub and Settings so both always agree.
class AppearancePicker extends ConsumerWidget {
  const AppearancePicker({super.key});

  static const _options = <ThemeMode, (String, IconData)>{
    ThemeMode.light: ('Light', Icons.light_mode_rounded),
    ThemeMode.dark: ('Dark', Icons.dark_mode_rounded),
    ThemeMode.system: ('System', Icons.brightness_auto_rounded),
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(themeModeProvider);

    return Row(
      children: [
        for (final entry in _options.entries) ...[
          Expanded(
            child: _ModeTile(
              label: entry.value.$1,
              icon: entry.value.$2,
              selected: mode == entry.key,
              onTap: () => ref.read(themeModeProvider.notifier).set(entry.key),
            ),
          ),
          if (entry.key != _options.keys.last)
            const SizedBox(width: AppSpacing.xs),
        ],
      ],
    );
  }
}

class _ModeTile extends StatelessWidget {
  const _ModeTile({
    required this.label,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final duration = Motion.of(context, Motion.fast);

    return Semantics(
      button: true,
      selected: selected,
      label: '$label theme',
      excludeSemantics: true,
      child: AnimatedContainer(
        duration: duration,
        curve: Motion.standard,
        decoration: BoxDecoration(
          color: selected
              ? AppColors.primaryFixed
              : AppColors.surfaceContainerLow,
          borderRadius: AppRadius.lgAll,
          border: Border.all(
            color: selected ? AppColors.primary : AppColors.outlineVariant,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            onTap: onTap,
            borderRadius: AppRadius.lgAll,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 64),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(
                      icon,
                      size: 20,
                      color: selected
                          ? AppColors.primary
                          : AppColors.onSurfaceVariant,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelMedium.copyWith(
                        color: selected
                            ? AppColors.onPrimaryFixedVariant
                            : AppColors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
