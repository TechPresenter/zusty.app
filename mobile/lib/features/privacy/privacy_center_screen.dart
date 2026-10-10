import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../providers/privacy_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../settings/widgets/settings_group.dart';
import 'widgets/privacy_widgets.dart';

/// Privacy & data: one place to take a copy of your data, close your
/// account, and read how Zusty handles personal data.
///
/// The tiles work at once. The overview only adds detail to their subtitles
/// when it arrives, so a slow network never blocks the way out.
class PrivacyCenterScreen extends ConsumerWidget {
  const PrivacyCenterScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final overview = ref.watch(privacyOverviewProvider).valueOrNull;
    final latest = overview?.export.latest;
    final blockers = overview?.deletion.blockers.length ?? 0;
    final margin = AppBreakpoints.pageMargin(context);

    final exportSubtitle = latest != null && latest.isReady
        ? 'Your copy is ready until ${Formatters.date(latest.expiresAt)}'
        : 'Get a copy of your personal data';

    final deleteSubtitle = blockers > 0
        ? '$blockers thing${blockers == 1 ? '' : 's'} to settle first'
        : 'Permanently erase your account and personal data';

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Privacy & data'),
      body: ListView(
        padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xxl),
        children: [
          const Reveal(
            index: 0,
            child: PrivacyHero(
              icon: Icons.shield_outlined,
              title: 'Your data, your choice',
              message:
                  'Take a copy of the personal data Zusty holds about you, or '
                  'close your account for good. Both ask you to confirm it\'s you '
                  'first, so nobody else can do either with your phone.',
            ),
          ),
          Reveal(
            index: 1,
            child: SettingsGroup(
              title: 'Your data',
              children: [
                ZMenuTile(
                  icon: Icons.file_download_outlined,
                  title: 'Download my data',
                  subtitle: exportSubtitle,
                  onTap: () => context.push(Routes.privacyExport),
                ),
              ],
            ),
          ),
          Reveal(
            index: 2,
            child: SettingsGroup(
              title: 'Your account',
              children: [
                ZMenuTile(
                  icon: Icons.person_remove_outlined,
                  title: 'Delete my account',
                  subtitle: deleteSubtitle,
                  destructive: true,
                  onTap: () => context.push(Routes.deleteAccount),
                ),
              ],
            ),
          ),
          Reveal(
            index: 3,
            child: SettingsGroup(
              title: 'Safety',
              children: [
                ZMenuTile(
                  icon: Icons.block_rounded,
                  title: 'Blocked accounts',
                  subtitle: 'See and unblock the people you blocked',
                  onTap: () => context.push(Routes.blockedUsers),
                ),
              ],
            ),
          ),
          Reveal(
            index: 4,
            child: SettingsGroup(
              title: 'Learn more',
              children: [
                ZMenuTile(
                  icon: Icons.privacy_tip_outlined,
                  title: 'Privacy Policy',
                  subtitle: 'What we collect, why, and for how long',
                  onTap: () => context.push(Routes.page('privacy')),
                ),
                ZMenuTile(
                  icon: Icons.description_outlined,
                  title: 'Terms & Conditions',
                  onTap: () => context.push(Routes.page('terms')),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
