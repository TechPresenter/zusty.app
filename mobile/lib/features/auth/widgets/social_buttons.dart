import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/social_sign_in_service.dart';
import '../../../core/theme/app_theme.dart';
import '../../../models/auth_config.dart';
import '../../../models/user.dart';
import '../../../providers/auth_provider.dart';
import '../../../providers/core_providers.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';
import 'legal_links.dart';

/// "OR CONTINUE WITH" rule used between the credential form and social sign-in.
class OrDivider extends StatelessWidget {
  const OrDivider({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(child: Divider(color: AppColors.outlineVariant)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
          child: Text(
            label,
            style: AppTypography.labelMedium.copyWith(
              color: AppColors.onSurfaceVariant,
              letterSpacing: 1.2,
            ),
          ),
        ),
        Expanded(child: Divider(color: AppColors.outlineVariant)),
      ],
    );
  }
}

/// Social sign-in buttons, rendered from whatever the server says is enabled.
///
/// Nothing here is hardcoded: which providers appear, in what order, and with
/// which client credentials all come from `GET /app/config` → `auth`, driven by
/// the Authentication & Login screen in the admin panel. Switch Google off
/// there and the button disappears from installed apps on their next launch.
///
/// Each provider yields a Firebase ID token, which `POST /auth/firebase`
/// verifies and exchanges for Zusty tokens. The server checks which provider
/// issued the token, so a disabled method is refused there too — the button
/// vanishing is convenience, not the control.
///
/// The buttons always come with [LegalConsentNotice] directly above them: a
/// first sign-in creates an account, and the notice is what makes sending
/// `accept_terms` with the token true. Neither appears without the other.
class SocialLoginRow extends ConsumerWidget {
  const SocialLoginRow({super.key, this.role});

  /// Passed through on first sign-in so the account is created on the right
  /// side of the marketplace.
  final UserRole? role;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(authConfigProvider);
    final providers = config.socialFor(_platform);

    // Nothing enabled for this platform — render nothing rather than a row of
    // buttons that would only produce errors.
    if (providers.isEmpty) return const SizedBox.shrink();

    final buttons = <Widget>[];

    for (final method in providers) {
      if (buttons.isNotEmpty) {
        buttons.add(const SizedBox(width: AppSpacing.md));
      }

      buttons.add(
        Expanded(
          child: _SocialButton(
            label: method.label.isEmpty
                ? _defaultLabel(method.key)
                : method.label,
            icon: _iconFor(method.key),
            onTap: () => _signIn(context, ref, method.key),
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const LegalConsentNotice(),
        const SizedBox(height: AppSpacing.sm),
        Row(children: buttons),
      ],
    );
  }

  /// Whether any social provider is switched on for this platform, so a
  /// screen can leave out the "or continue with" rule when nothing follows it.
  static bool hasProviders(AuthConfig config) =>
      config.socialFor(_platform).isNotEmpty;

  static String get _platform {
    if (kIsWeb) return 'web';
    if (Platform.isIOS) return 'ios';
    return 'android';
  }

  static String _defaultLabel(String key) => switch (key) {
    'google' => 'Google',
    'apple' => 'Apple',
    'facebook' => 'Facebook',
    _ => key,
  };

  static Widget _iconFor(String key) => switch (key) {
    'google' => const _GoogleMark(),
    'apple' => const Icon(Icons.apple_rounded, size: 22),
    'facebook' => const Icon(Icons.facebook_rounded, size: 22),
    _ => const Icon(Icons.login_rounded, size: 22),
  };

  Future<void> _signIn(
    BuildContext context,
    WidgetRef ref,
    String provider,
  ) async {
    final config = ref.read(authConfigProvider);
    const service = SocialSignInService();

    try {
      // A real provider token, not a placeholder. This used to post the literal
      // string 'demo-google', which the server could only ever reject.
      final idToken = switch (provider) {
        'google' => await service.google(config),
        'apple' => await service.apple(config),
        _ => throw SocialSignInException(
          '${_defaultLabel(provider)} sign-in is not available in this build yet.',
        ),
      };

      // The terms notice sits directly above this button, so a first
      // sign-in has agreed to them.
      await ref
          .read(authProvider.notifier)
          .signInWithProvider(idToken, role: role, acceptTerms: true);

      if (context.mounted) context.go(Routes.home);
    } on SocialSignInException catch (e) {
      // Backing out of the provider's own sheet is a choice, not an error.
      if (e.cancelled || !context.mounted) return;
      showZSnack(context, e.message, isError: true);
    } catch (e) {
      if (context.mounted) {
        showZSnack(context, 'Sign-in failed. Please try again.', isError: true);
      }
    }
  }
}

class _SocialButton extends StatelessWidget {
  const _SocialButton({
    required this.label,
    required this.icon,
    required this.onTap,
  });

  final String label;
  final Widget icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    // Pill-shaped and the same height as the primary button above it, so the
    // alternatives read as one family of actions rather than a second style.
    return Semantics(
      button: true,
      label: 'Continue with $label',
      onTap: onTap,
      excludeSemantics: true,
      child: Material(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.pill,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Container(
            height: 52,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
            decoration: BoxDecoration(
              borderRadius: AppRadius.pill,
              border: Border.all(color: AppColors.outlineVariant),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                icon,
                const SizedBox(width: AppSpacing.xs),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.button.copyWith(
                      fontSize: 15,
                      color: AppColors.onSurface,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The four-colour Google "G", drawn rather than bundled as an asset.
class _GoogleMark extends StatelessWidget {
  const _GoogleMark();

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 20,
      height: 20,
      child: CustomPaint(painter: _GooglePainter()),
    );
  }
}

class _GooglePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTWH(0, 0, size.width, size.height);
    final centre = rect.center;
    final radius = size.width / 2;
    final stroke = size.width * 0.22;

    final arcs = <(double, double, Color)>[
      (-0.6, 1.35, const Color(0xFF4285F4)),
      (0.75, 1.6, const Color(0xFF34A853)),
      (2.35, 1.55, const Color(0xFFFBBC05)),
      (3.9, 1.5, const Color(0xFFEA4335)),
    ];

    for (final (start, sweep, color) in arcs) {
      canvas.drawArc(
        Rect.fromCircle(center: centre, radius: radius - stroke / 2),
        start,
        sweep,
        false,
        Paint()
          ..color = color
          ..style = PaintingStyle.stroke
          ..strokeWidth = stroke
          ..strokeCap = StrokeCap.butt,
      );
    }

    // The horizontal bar of the "G".
    canvas.drawRect(
      Rect.fromLTWH(centre.dx, centre.dy - stroke / 2, radius, stroke),
      Paint()..color = const Color(0xFF4285F4),
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
