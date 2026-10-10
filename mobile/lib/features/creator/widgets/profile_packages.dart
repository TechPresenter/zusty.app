import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/creator.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/platform_icon.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_dialogs.dart';
import 'profile_layout.dart';

/// What a caller does when someone picks a package — or, with null, asks for
/// something custom.
typedef BookPackage = void Function(CreatorPackage? package);

/// The Packages tab: the creator's rate range, every active package with
/// what it includes, and a way to ask for something custom.
class ProfilePackagesTab extends StatelessWidget {
  const ProfilePackagesTab({
    super.key,
    required this.profile,
    required this.onBook,
  });

  final CreatorProfile profile;

  /// Null when booking is not possible here: the viewer owns this profile,
  /// or the creator has paused bookings.
  final BookPackage? onBook;

  @override
  Widget build(BuildContext context) {
    final creator = profile.creator;
    final packages = profile.packages;
    final name = firstNameOf(creator.displayName);
    final paused = creator.availability == Availability.unavailable;

    return ProfileTabBody(
      children: [
        if (creator.priceLabel.isNotEmpty &&
            (creator.priceMin > 0 || creator.priceMax > 0))
          _RateCard(profile: profile),
        if (paused && !profile.isOwner) ...[
          const SizedBox(height: AppSpacing.md),
          const _PausedNotice(),
        ],
        if (packages.isEmpty)
          EmptyState(
            title: 'No packages yet',
            message: profile.isOwner
                ? 'Packages are fixed-price offers brands can pick when they '
                      'send you a booking request. You can also manage them '
                      'from the Profile tab, under For creators.'
                : '$name has not listed fixed packages. You can still send '
                      'a request with your brief and budget.',
            icon: Icons.inventory_2_outlined,
            actionLabel: profile.isOwner
                ? 'Manage packages'
                : (onBook == null ? null : 'Request a quote'),
            onAction: profile.isOwner
                ? () => context.push(Routes.myPackages)
                : (onBook == null ? null : () => onBook!(null)),
            compact: true,
          )
        else ...[
          ProfileSectionTitle(
            '${packages.length} ${packages.length == 1 ? 'package' : 'packages'}',
            subtitle: onBook == null
                ? null
                : 'Pick one to start a booking request',
          ),
          for (final package in packages)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.md),
              child: ProfilePackageCard(
                package: package,
                isCheapest:
                    packages.length > 1 &&
                    identical(package, profile.startingPackage),
                onBook: onBook == null ? null : () => onBook!(package),
              ),
            ),
          if (onBook != null) ...[
            const SizedBox(height: AppSpacing.xs),
            ZCard(
              color: AppColors.surfaceContainerLow,
              shadow: false,
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Need something different?',
                          style: AppTypography.titleSmall,
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Describe your campaign and budget and $name '
                          'will reply with a quote.',
                          style: AppTypography.bodySmall,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  ZButton.tonal(
                    label: 'Custom',
                    size: ZButtonSize.small,
                    expand: false,
                    onPressed: () => onBook!(null),
                  ),
                ],
              ),
            ),
          ],
        ],
      ],
    );
  }
}

class _RateCard extends StatelessWidget {
  const _RateCard({required this.profile});

  final CreatorProfile profile;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(
              Icons.payments_outlined,
              size: 22,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Typical rate', style: AppTypography.bodySmall),
                const SizedBox(height: 2),
                Text(
                  profile.creator.priceLabel,
                  style: AppTypography.titleMedium.copyWith(
                    color: AppColors.secondary,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _PausedNotice extends StatelessWidget {
  const _PausedNotice();

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = StatusChip.colorsFor(StatusTone.warning);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: background,
        borderRadius: AppRadius.lgAll,
      ),
      child: Row(
        children: [
          Icon(Icons.pause_circle_outline_rounded, color: foreground),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              'Bookings are paused for now. Follow or message to hear when '
              'they reopen.',
              style: AppTypography.bodyMedium.copyWith(color: foreground),
            ),
          ),
        ],
      ),
    );
  }
}

/// One bookable package: what it is, what it includes, how long it takes,
/// and its price, with its own Book action.
class ProfilePackageCard extends ConsumerWidget {
  const ProfilePackageCard({
    super.key,
    required this.package,
    required this.onBook,
    this.isCheapest = false,
  });

  final CreatorPackage package;

  /// Null hides the action (own profile) or disables it (bookings paused).
  final VoidCallback? onBook;
  final bool isCheapest;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final taxonomy = ref.watch(taxonomiesProvider);

    final kind = [
      if (package.deliverableType.isNotEmpty)
        taxonomy.label(Tax.deliverableType, package.deliverableType),
      if (package.quantity > 1) '× ${package.quantity}',
      if (package.platform.isNotEmpty)
        'on ${taxonomy.label(Tax.socialPlatform, package.platform)}',
    ].join(' ');

    return ZCard(
      onTap: onBook,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (package.platform.isNotEmpty) ...[
                PlatformIcon(platform: package.platform, size: 40),
                const SizedBox(width: AppSpacing.sm),
              ],
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      package.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.titleSmall.copyWith(fontSize: 15),
                    ),
                    if (kind.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        kind,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall,
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    package.priceFormatted,
                    style: AppTypography.titleMedium.copyWith(
                      color: AppColors.primary,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  if (isCheapest)
                    Text('Starting price', style: AppTypography.labelSmall),
                ],
              ),
            ],
          ),
          if (package.description.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(
              package.description,
              maxLines: 4,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ],
          if (package.includes.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            for (final item in package.includes)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.base),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 1),
                      child: Icon(
                        Icons.check_circle_rounded,
                        size: 16,
                        color: AppColors.success,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    Expanded(
                      child: Text(item, style: AppTypography.bodyMedium),
                    ),
                  ],
                ),
              ),
          ],
          const SizedBox(height: AppSpacing.md),
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              StatusChip(
                label: '${package.turnaroundLabel} delivery',
                icon: Icons.schedule_rounded,
                tone: StatusTone.info,
                dense: true,
              ),
              StatusChip(
                label: package.revisionsLabel,
                icon: Icons.refresh_rounded,
                tone: StatusTone.neutral,
                dense: true,
              ),
            ],
          ),
          if (onBook != null) ...[
            const SizedBox(height: AppSpacing.md),
            ZButton(
              label: 'Book this package',
              size: ZButtonSize.medium,
              trailingIcon: Icons.arrow_forward_rounded,
              onPressed: onBook,
            ),
          ],
        ],
      ),
    );
  }
}

/// Asks which package to start a booking from, or whether to send a custom
/// request instead.
///
/// Resolves to the chosen package; to [customRequest] for a custom request;
/// or to null when the sheet was dismissed without a choice.
Future<Object?> showPackageChooser(
  BuildContext context, {
  required CreatorProfile profile,
}) {
  return showZSheet<Object>(
    context,
    title: 'Choose a package',
    subtitle: 'You can adjust the brief and budget on the next step.',
    builder: (sheetContext) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final package in profile.packages)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: _PackageOption(
              package: package,
              onTap: () => Navigator.of(sheetContext).pop(package),
            ),
          ),
        const SizedBox(height: AppSpacing.xs),
        ZButton.outlined(
          label: 'Send a custom request',
          icon: Icons.edit_note_rounded,
          size: ZButtonSize.medium,
          onPressed: () => Navigator.of(sheetContext).pop(customRequest),
        ),
      ],
    ),
  );
}

/// The package chooser's "something else" answer, distinct from a dismissal.
const Object customRequest = Object();

class _PackageOption extends StatelessWidget {
  const _PackageOption({required this.package, required this.onTap});

  final CreatorPackage package;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      onTap: onTap,
      shadow: false,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Row(
        children: [
          if (package.platform.isNotEmpty) ...[
            PlatformIcon(platform: package.platform, size: 36),
            const SizedBox(width: AppSpacing.sm),
          ],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  package.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.labelLarge,
                ),
                const SizedBox(height: 2),
                Text(
                  '${package.turnaroundLabel} · ${package.revisionsLabel}',
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Text(
            package.priceFormatted,
            style: AppTypography.titleSmall.copyWith(color: AppColors.primary),
          ),
          const SizedBox(width: AppSpacing.base),
          Icon(Icons.chevron_right_rounded, color: AppColors.onSurfaceVariant),
        ],
      ),
    );
  }
}
