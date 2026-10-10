import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/creator_tools.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/creator_tools_provider.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/platform_icon.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_feedback.dart';
import '../../../shared/widgets/z_scaffold.dart';
import 'widgets/creator_tools_common.dart';

/// The creator's packages: fixed-price offers a brand can pick when sending
/// a booking request.
///
/// Lists hidden packages as well as live ones — the profile shows only the
/// live ones, so this is the only place a hidden package can be found again.
/// Adding and editing open [PackageFormScreen]; hiding and deleting happen
/// here, each after the creator has said yes.
class MyPackagesScreen extends ConsumerWidget {
  const MyPackagesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final packages = ref.watch(myPackagesProvider);
    final hasAny = packages.valueOrNull?.packages.isNotEmpty ?? false;

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'My packages'),
      bottomBar: hasAny
          ? ZButton(
              label: 'Add a package',
              icon: Icons.add_rounded,
              onPressed: () => context.push(Routes.newPackage),
            )
          : null,
      body: CreatorToolsGate(
        child: packages.when(
          skipLoadingOnRefresh: true,
          loading: () => Padding(
            padding: EdgeInsets.all(AppBreakpoints.pageMargin(context)),
            child: const ListSkeleton(count: 3, height: 140),
          ),
          error: (error, _) => CreatorToolsErrorView(
            error: error,
            onRetry: () => ref.invalidate(myPackagesProvider),
          ),
          data: (data) => _PackageList(data: data),
        ),
      ),
    );
  }
}

class _PackageList extends ConsumerWidget {
  const _PackageList({required this.data});

  final OwnPackages data;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final margin = AppBreakpoints.pageMargin(context);
    final starting = data.startingPackage;
    final live = data.packages.where((p) => p.isActive).length;

    return ZRefresh(
      onRefresh: () => ref.read(myPackagesProvider.notifier).refresh(),
      child: ListView(
        padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xxl),
        children: [
          if (data.locked) const LockedSectionBanner(what: 'Your pricing'),
          if (data.packages.isEmpty)
            EmptyState(
              icon: Icons.inventory_2_outlined,
              title: 'No packages yet',
              message:
                  'A package is a fixed price for a set piece of work, such '
                  'as one reel with a story reshare. Brands can pick it from '
                  'your profile when they send you a booking request.',
              actionLabel: 'Add a package',
              onAction: () => context.push(Routes.newPackage),
            )
          else ...[
            ZInfoBanner(
              icon: Icons.visibility_outlined,
              message: live == 0
                  ? 'All your packages are hidden, so brands cannot book a '
                        'package from your profile.'
                  : 'Brands see your live packages on your profile. Your '
                        'cheapest live package is shown as your starting price.',
            ),
            const SizedBox(height: AppSpacing.md),
            for (var i = 0; i < data.packages.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.md),
                child: Reveal(
                  index: i,
                  revealKey: data.packages[i].id,
                  child: _PackageTile(
                    own: data.packages[i],
                    isStarting: identical(data.packages[i], starting) && live > 1,
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

enum _PackageAction { edit, toggle, delete }

class _PackageTile extends ConsumerWidget {
  const _PackageTile({required this.own, required this.isStarting});

  final OwnPackage own;
  final bool isStarting;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final package = own.package;
    final taxonomy = ref.watch(taxonomiesProvider);

    final kind = [
      if (package.deliverableType.isNotEmpty)
        taxonomy.label(Tax.deliverableType, package.deliverableType),
      if (package.quantity > 1) '× ${package.quantity}',
      if (package.platform.isNotEmpty)
        'on ${taxonomy.label(Tax.platform, package.platform)}',
    ].join(' ');

    return ZCard(
      onTap: () => context.push(Routes.editPackage(own.id)),
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.xs,
        AppSpacing.md,
      ),
      child: Row(
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
                  style: AppTypography.titleSmall,
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
                const SizedBox(height: AppSpacing.xs),
                Text(
                  package.priceFormatted,
                  style: AppTypography.titleMedium.copyWith(
                    color: own.isActive ? AppColors.primary : AppColors.onSurfaceVariant,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  children: [
                    StatusChip(
                      label: own.isActive ? 'Live' : 'Hidden',
                      icon: own.isActive
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                      tone: own.isActive ? StatusTone.success : StatusTone.neutral,
                      dense: true,
                    ),
                    if (isStarting)
                      const StatusChip(
                        label: 'Starting price',
                        tone: StatusTone.info,
                        dense: true,
                      ),
                    StatusChip(
                      label: '${package.turnaroundLabel} delivery',
                      icon: Icons.schedule_rounded,
                      tone: StatusTone.neutral,
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
              ],
            ),
          ),
          PopupMenuButton<_PackageAction>(
            tooltip: 'Options for ${package.title}',
            icon: Icon(
              Icons.more_vert_rounded,
              color: AppColors.onSurfaceVariant,
            ),
            onSelected: (action) => _act(context, ref, action),
            itemBuilder: (_) => [
              const PopupMenuItem(
                value: _PackageAction.edit,
                child: ListTile(
                  leading: Icon(Icons.edit_outlined),
                  title: Text('Edit'),
                ),
              ),
              PopupMenuItem(
                value: _PackageAction.toggle,
                child: ListTile(
                  leading: Icon(
                    own.isActive
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                  ),
                  title: Text(own.isActive ? 'Hide from profile' : 'Show on profile'),
                ),
              ),
              const PopupMenuItem(
                value: _PackageAction.delete,
                child: ListTile(
                  leading: Icon(Icons.delete_outline_rounded),
                  title: Text('Delete'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _act(
    BuildContext context,
    WidgetRef ref,
    _PackageAction action,
  ) async {
    final controller = ref.read(myPackagesProvider.notifier);

    switch (action) {
      case _PackageAction.edit:
        await context.push(Routes.editPackage(own.id));

      case _PackageAction.toggle:
        await _run(
          context,
          () => controller.setActive(own.id, active: !own.isActive),
          own.isActive
              ? 'Hidden. Brands can no longer book this package.'
              : 'Live. Brands can book this package again.',
        );

      case _PackageAction.delete:
        final confirmed = await confirmRemoval(
          context,
          title: 'Delete "${own.package.title}"?',
          message:
              'Brands will no longer see it. Bookings already made with it '
              'are not affected. To take it down for now instead, hide it.',
          confirmLabel: 'Delete',
        );
        if (!confirmed || !context.mounted) return;

        await _run(context, () => controller.remove(own.id), 'Package deleted.');
    }
  }

  Future<void> _run(
    BuildContext context,
    Future<void> Function() write,
    String success,
  ) async {
    try {
      await write();
      if (context.mounted) showZSnack(context, success);
    } catch (error) {
      if (context.mounted) {
        showZSnack(context, failureMessage(error), isError: true);
      }
    }
  }
}
