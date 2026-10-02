import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/theme/app_theme.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/core_providers.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/zuste_logo.dart';

/// The blocking update screen.
///
/// The server already decides this: `/app/config` compares the `X-Platform` and
/// `X-App-Version` headers the client sends against the per-platform minimum in
/// Settings → App, and answers with `update.force_update`. Until this screen
/// existed the app asked the question, was told yes, and carried on — there was
/// no way to force an upgrade of a shipped build.
///
/// There is deliberately no way out except upgrading or the operator clearing
/// the flag: no back button, no skip. "Check again" refetches the config so a
/// minimum version set by mistake can be undone from the panel without every
/// installed app needing a reinstall.
class ForceUpdateScreen extends ConsumerStatefulWidget {
  const ForceUpdateScreen({super.key});

  @override
  ConsumerState<ForceUpdateScreen> createState() => _ForceUpdateScreenState();
}

class _ForceUpdateScreenState extends ConsumerState<ForceUpdateScreen> {
  bool _checking = false;

  @override
  Widget build(BuildContext context) {
    final gate = ref.watch(updateGateProvider);
    final identity = ref.watch(appIdentityProvider);
    final branding = ref.watch(appBrandingProvider);
    final logo = branding.logoFor(AppColors.brightness);
    final margin = AppBreakpoints.pageMargin(context);

    final versions = [
      if (gate.minVersion.isNotEmpty) ('Minimum', gate.minVersion),
      if (gate.latestVersion.isNotEmpty) ('Latest', gate.latestVersion),
    ];

    return PopScope(
      // The whole point of the gate: the system back gesture must not get past
      // it either.
      canPop: false,
      child: Scaffold(
        backgroundColor: AppColors.surface,
        body: Stack(
          children: [
            const Positioned.fill(child: _Backdrop()),
            SafeArea(
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(
                    maxWidth: AppBreakpoints.maxContentWidth,
                  ),
                  child: Column(
                    children: [
                      Expanded(
                        child: SingleChildScrollView(
                          padding: EdgeInsets.fromLTRB(
                            margin,
                            AppSpacing.xl,
                            margin,
                            AppSpacing.lg,
                          ),
                          child: Column(
                            children: [
                              if (logo != null && logo.isNotEmpty)
                                SizedBox(
                                  height: 48,
                                  child: ZImage(
                                    url: logo,
                                    fit: BoxFit.contain,
                                    fallbackIcon: Icons.system_update_rounded,
                                  ),
                                )
                              else
                                const ZusteLogoLockup(height: 32),
                              const SizedBox(height: AppSpacing.xxl),
                              const Reveal(child: _UpdateArt()),
                              const SizedBox(height: AppSpacing.xl),
                              Reveal(
                                index: 1,
                                child: Text(
                                  'Update ${identity.name} to continue',
                                  textAlign: TextAlign.center,
                                  style: AppTypography.headlineMedium,
                                ),
                              ),
                              const SizedBox(height: AppSpacing.sm),
                              Reveal(
                                index: 2,
                                child: Text(
                                  gate.message.isNotEmpty
                                      ? gate.message
                                      : 'This version is no longer supported. '
                                            'Install the latest one to keep '
                                            'using the app.',
                                  textAlign: TextAlign.center,
                                  style: AppTypography.bodyLarge.copyWith(
                                    color: AppColors.onSurfaceVariant,
                                    height: 1.5,
                                  ),
                                ),
                              ),
                              if (versions.isNotEmpty) ...[
                                const SizedBox(height: AppSpacing.lg),
                                Reveal(
                                  index: 3,
                                  child: _VersionStrip(versions: versions),
                                ),
                              ],
                              const SizedBox(height: AppSpacing.lg),
                              Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.shield_outlined,
                                    size: 16,
                                    color: AppColors.onSurfaceVariant,
                                  ),
                                  const SizedBox(width: 6),
                                  Flexible(
                                    child: Text(
                                      'Your account, bookings and wallet are '
                                      'safe and will be waiting.',
                                      textAlign: TextAlign.center,
                                      style: AppTypography.bodySmall,
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ),

                      // -- Actions, pinned -------------------------------------
                      Padding(
                        padding: EdgeInsets.fromLTRB(
                          margin,
                          AppSpacing.sm,
                          margin,
                          AppSpacing.md,
                        ),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            if (gate.storeUri != null) ...[
                              ZButton(
                                label: 'Update now',
                                icon: Icons.system_update_alt_rounded,
                                onPressed: () => _openStore(context),
                              ),
                              const SizedBox(height: AppSpacing.xs),
                            ],
                            ZButton(
                              label: 'Check again',
                              variant: gate.storeUri == null
                                  ? ZButtonVariant.primary
                                  : ZButtonVariant.text,
                              icon: Icons.refresh_rounded,
                              isLoading: _checking,
                              onPressed: _recheck,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _openStore(BuildContext context) async {
    final uri = ref.read(updateGateProvider).storeUri;
    if (uri == null) return;

    final launched = await launchUrl(uri, mode: LaunchMode.externalApplication);

    if (!launched && context.mounted) {
      showZSnack(context, 'Could not open the store.', isError: true);
    }
  }

  Future<void> _recheck() async {
    setState(() => _checking = true);
    await ref.read(authConfigProvider.notifier).refresh(force: true);
    if (mounted) setState(() => _checking = false);
  }
}

/// A large update glyph on concentric brand rings.
class _UpdateArt extends StatelessWidget {
  const _UpdateArt();

  @override
  Widget build(BuildContext context) {
    return ExcludeSemantics(
      child: Container(
        width: 168,
        height: 168,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: AppColors.primaryFixed.withValues(alpha: 0.6),
        ),
        child: Container(
          width: 120,
          height: 120,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: AppColors.surfaceContainerLowest,
            boxShadow: AppShadows.raised,
          ),
          child: Container(
            width: 80,
            height: 80,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [AppColors.primary, AppColors.primaryContainer],
              ),
            ),
            child: Icon(
              Icons.rocket_launch_rounded,
              size: 38,
              color: AppColors.onPrimary,
            ),
          ),
        ),
      ),
    );
  }
}

/// "Minimum 1.2.0 | Latest 1.4.0" — the figures the operator set, so support
/// can tell a user exactly what to install.
class _VersionStrip extends StatelessWidget {
  const _VersionStrip({required this.versions});

  final List<(String, String)> versions;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.pill,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Wrap(
        alignment: WrapAlignment.center,
        spacing: AppSpacing.md,
        runSpacing: 4,
        children: [
          for (final (label, version) in versions)
            Text.rich(
              TextSpan(
                text: '$label ',
                style: AppTypography.bodySmall,
                children: [
                  TextSpan(
                    text: version,
                    style: AppTypography.labelLarge.copyWith(
                      color: AppColors.onSurface,
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

/// Soft brand shapes off the top corner, matching the sign-in screens.
class _Backdrop extends StatelessWidget {
  const _Backdrop();

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;

    return IgnorePointer(
      child: Stack(
        children: [
          Positioned(
            top: -width * 0.35,
            left: -width * 0.25,
            child: Container(
              width: width * 0.8,
              height: width * 0.8,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.primaryFixed.withValues(alpha: 0.55),
              ),
            ),
          ),
          Positioned(
            bottom: -width * 0.2,
            right: -width * 0.25,
            child: Container(
              width: width * 0.6,
              height: width * 0.6,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.secondaryFixed.withValues(alpha: 0.6),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
