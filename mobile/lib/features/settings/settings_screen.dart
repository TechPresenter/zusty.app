import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/config/app_config.dart';
import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../models/account_settings.dart';
import '../../models/user.dart';
import '../../providers/account_provider.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';
import '../../router/routes.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import 'widgets/account_sheets.dart';
import 'widgets/appearance_picker.dart';
import 'widgets/settings_group.dart';

/// Settings: account and security, notifications, privacy, appearance,
/// help, legal, and the ways out (log out, sign out everywhere, delete).
///
/// Every control here acts on the server immediately — a switch saves when
/// it flips, with no separate Save step to forget — and puts itself back if
/// the server says no.
class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});

  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  /// Channels whose switch is saving right now.
  final Set<String> _savingPrefs = {};
  bool _savingVisibility = false;

  Future<void> _setPref(
    NotificationPrefs current,
    String channel,
    bool value,
  ) async {
    final next = switch (channel) {
      'push' => current.copyWith(push: value),
      'email' => current.copyWith(email: value),
      'sms' => current.copyWith(sms: value),
      _ => current.copyWith(marketing: value),
    };

    setState(() => _savingPrefs.add(channel));
    try {
      // Updates the signed-in user too, so the switch reads the saved value.
      await ref.read(profileControllerProvider).updateNotificationPrefs(next);
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _savingPrefs.remove(channel));
    }
  }

  Future<void> _setVisibility(bool isPublic) async {
    setState(() => _savingVisibility = true);
    try {
      final result = await ref
          .read(accountRepositoryProvider)
          .setCreatorVisibility(isPublic);
      ref.invalidate(creatorVisibilityProvider);
      if (mounted && result.message.isNotEmpty) {
        showZSnack(context, result.message);
      }
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _savingVisibility = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final version = ref.watch(appVersionProvider).valueOrNull;
    final margin = AppBreakpoints.pageMargin(context);

    if (user == null) {
      return const ZScaffold(
        appBar: ZAppBar(title: 'Settings'),
        body: ZLoader(),
      );
    }

    final prefs = user.notificationPrefs;
    final isCreator = user.role.isCreator || user.isCreator;

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Settings'),
      body: ListView(
        padding: EdgeInsets.fromLTRB(margin, 0, margin, AppSpacing.xxl),
        children: [
          // -- Account & security ------------------------------------------
          SettingsGroup(
            title: 'Account',
            padding: const EdgeInsets.only(top: AppSpacing.md),
            children: [
              ZMenuTile(
                icon: Icons.person_outline_rounded,
                title: 'Edit profile',
                subtitle: 'Name, photo, city and bio',
                onTap: () => context.push(Routes.editProfile),
              ),
              ZMenuTile(
                icon: Icons.alternate_email_rounded,
                title: 'Email & mobile',
                subtitle: [
                  user.email,
                  user.fullPhone,
                ].where((v) => v != null && v.isNotEmpty).join(' · '),
                onTap: () => context.push(Routes.contacts),
              ),
              ZMenuTile(
                icon: Icons.verified_user_outlined,
                title: 'Verification',
                value: user.kycStatus.label,
                onTap: () => context.push(Routes.kyc),
              ),
            ],
          ),
          SettingsGroup(
            title: 'Security',
            children: [
              ZMenuTile(
                icon: Icons.lock_outline_rounded,
                title: 'Change password',
                subtitle: 'Signs you out on every device',
                onTap: () => showChangePassword(context, ref),
              ),
              ZMenuTile(
                icon: Icons.devices_rounded,
                title: 'Active sessions',
                subtitle: 'See and sign out signed-in devices',
                onTap: () => showSessions(context, ref),
              ),
            ],
          ),

          // -- Privacy & data ------------------------------------------------
          SettingsGroup(
            title: 'Privacy & data',
            footer:
                'Both ask you to confirm it\'s you first, with your password '
                'or a one-time code.',
            children: [
              ZMenuTile(
                icon: Icons.file_download_outlined,
                title: 'Download my data',
                subtitle: 'Get a copy of your personal data',
                onTap: () => context.push(Routes.privacyExport),
              ),
              ZMenuTile(
                icon: Icons.person_remove_outlined,
                title: 'Delete my account',
                subtitle: 'Permanently erase your account and personal data',
                destructive: true,
                onTap: () => context.push(Routes.deleteAccount),
              ),
            ],
          ),

          // -- Notifications -------------------------------------------------
          SettingsGroup(
            title: 'Notifications',
            footer: 'Each switch saves as soon as you flip it.',
            children: [
              SettingsSwitchTile(
                icon: Icons.notifications_active_outlined,
                title: 'Push notifications',
                subtitle: 'Bookings, payments and messages',
                value: prefs.push,
                busy: _savingPrefs.contains('push'),
                onChanged: (v) => _setPref(prefs, 'push', v),
              ),
              SettingsSwitchTile(
                icon: Icons.mail_outline_rounded,
                title: 'Email',
                subtitle: 'Receipts, invoices and summaries',
                value: prefs.email,
                busy: _savingPrefs.contains('email'),
                onChanged: (v) => _setPref(prefs, 'email', v),
              ),
              SettingsSwitchTile(
                icon: Icons.sms_outlined,
                title: 'SMS',
                subtitle: 'Critical booking and payout updates',
                value: prefs.sms,
                busy: _savingPrefs.contains('sms'),
                onChanged: (v) => _setPref(prefs, 'sms', v),
              ),
              SettingsSwitchTile(
                icon: Icons.campaign_outlined,
                title: 'Product updates & offers',
                subtitle: 'New features and offers from Zusty',
                value: prefs.marketing,
                busy: _savingPrefs.contains('marketing'),
                onChanged: (v) => _setPref(prefs, 'marketing', v),
              ),
            ],
          ),

          // -- Privacy (creators) ----------------------------------------------
          if (isCreator)
            _PrivacyGroup(saving: _savingVisibility, onChanged: _setVisibility),

          // -- Appearance & language -------------------------------------------
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(
                    left: AppSpacing.xs,
                    bottom: AppSpacing.xs,
                  ),
                  child: Text(
                    'Appearance',
                    style: AppTypography.labelLarge.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ),
                const ZCard(
                  shadow: false,
                  padding: EdgeInsets.all(AppSpacing.sm),
                  child: AppearancePicker(),
                ),
              ],
            ),
          ),
          // The app ships in English only. Shown so the question "can I
          // change the language?" has an answer, but not as a control that
          // offers a choice that does not exist.
          const SettingsGroup(
            padding: EdgeInsets.only(top: AppSpacing.sm),
            children: [
              ZMenuTile(
                icon: Icons.translate_rounded,
                title: 'Language',
                value: 'English',
              ),
            ],
          ),

          // -- Help ------------------------------------------------------------
          SettingsGroup(
            title: 'Help & support',
            children: [
              ZMenuTile(
                icon: Icons.support_agent_rounded,
                title: 'Help centre',
                subtitle: 'Guides and your support tickets',
                onTap: () => context.push(Routes.support),
              ),
              ZMenuTile(
                icon: Icons.help_outline_rounded,
                title: 'FAQs',
                onTap: () => context.push(Routes.faqs),
              ),
              ZMenuTile(
                icon: Icons.chat_bubble_outline_rounded,
                title: 'Contact support',
                subtitle: 'Open a ticket with our team',
                onTap: () => context.push(Routes.newTicket),
              ),
            ],
          ),

          // -- Legal -------------------------------------------------------------
          SettingsGroup(
            title: 'About',
            children: [
              ZMenuTile(
                icon: Icons.description_outlined,
                title: 'Terms & Conditions',
                onTap: () => context.push(Routes.page('terms')),
              ),
              ZMenuTile(
                icon: Icons.privacy_tip_outlined,
                title: 'Privacy Policy',
                onTap: () => context.push(Routes.page('privacy')),
              ),
              ZMenuTile(
                icon: Icons.info_outline_rounded,
                title: 'About ${AppConfig.appName}',
                onTap: () => context.push(Routes.page('about')),
              ),
            ],
          ),

          // -- Leaving -------------------------------------------------------------
          SettingsGroup(
            children: [
              ZMenuTile(
                icon: Icons.logout_rounded,
                title: 'Log out',
                showChevron: false,
                onTap: () => confirmAndLogout(context, ref),
              ),
            ],
          ),

          const SizedBox(height: AppSpacing.lg),
          Center(
            child: Text(
              version == null
                  ? AppConfig.appName
                  : '${AppConfig.appName} · Version $version',
              style: AppTypography.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}

/// The creator's public-profile switch, with the server's reasons when the
/// profile is switched on but still not live.
class _PrivacyGroup extends ConsumerWidget {
  const _PrivacyGroup({required this.saving, required this.onChanged});

  final bool saving;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final visibility = ref.watch(creatorVisibilityProvider);

    final CreatorVisibility? state = visibility.valueOrNull;
    final String subtitle;

    if (visibility.hasError && state == null) {
      return SettingsGroup(
        title: 'Privacy',
        children: [
          ZMenuTile(
            icon: Icons.visibility_outlined,
            title: 'Public creator profile',
            subtitle: 'Could not load this setting. Tap to try again.',
            onTap: () => ref.invalidate(creatorVisibilityProvider),
          ),
        ],
      );
    }

    if (state == null) {
      subtitle = 'Checking…';
    } else if (state.isLive) {
      subtitle = 'Brands can find you in search and on the explore page';
    } else if (state.blockers.isNotEmpty) {
      subtitle = state.blockers.first;
    } else {
      subtitle = 'Hidden from the public directory';
    }

    return SettingsGroup(
      title: 'Privacy',
      footer:
          'Switching your profile off and on again never sends it back '
          'for review.',
      children: [
        SettingsSwitchTile(
          icon: Icons.visibility_outlined,
          title: 'Public creator profile',
          subtitle: subtitle,
          value: state?.isPublic ?? false,
          busy: saving || visibility.isLoading,
          onChanged: state == null ? null : onChanged,
        ),
      ],
    );
  }
}
