import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/app_theme.dart';
import '../../../providers/app_config_provider.dart';
import '../../../providers/core_providers.dart';
import '../../../shared/widgets/states.dart';

/// Where the Terms and the Privacy Policy live: the operator's own URLs
/// (Admin → Authentication), falling back to Settings → Legal. Either may be
/// empty, and a link with no URL is shown as plain text.
({String terms, String privacy}) legalUrls(WidgetRef ref) {
  final signup = ref.watch(authConfigProvider).signup;
  final legal = ref.watch(legalLinksProvider);

  return (
    terms: signup.termsUrl.isNotEmpty ? signup.termsUrl : legal.termsUrl,
    privacy: signup.privacyUrl.isNotEmpty ? signup.privacyUrl : legal.privacyUrl,
  );
}

/// "By continuing, you agree to the Terms and Privacy Policy", with both
/// documents one tap away.
///
/// Sits directly above the social sign-in buttons on the login and sign-up
/// screens. A first Google sign-in creates an account, and Google Play's
/// user-generated content policy needs the terms agreed to first: tapping a
/// button under this line is that agreement, and the app says so to the
/// server (`accept_terms` on `POST /auth/firebase`). Someone who already has
/// an account just signs in.
class LegalConsentNotice extends ConsumerWidget {
  const LegalConsentNotice({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final urls = legalUrls(ref);
    final style = AppTypography.bodySmall.copyWith(
      color: AppColors.onSurfaceVariant,
    );

    WidgetSpan link(String label, String url) => WidgetSpan(
      alignment: PlaceholderAlignment.baseline,
      baseline: TextBaseline.alphabetic,
      child: LegalLink(label: label, url: url, style: style),
    );

    return Text.rich(
      TextSpan(
        style: style,
        children: [
          const TextSpan(text: 'By continuing, you agree to the '),
          link('Terms', urls.terms),
          const TextSpan(text: ' and '),
          link('Privacy Policy', urls.privacy),
          const TextSpan(text: '.'),
        ],
      ),
      textAlign: TextAlign.center,
    );
  }
}

/// One legal document's name as a link that opens it in the in-app browser,
/// or plain text when there is nowhere to go.
class LegalLink extends StatelessWidget {
  const LegalLink({
    super.key,
    required this.label,
    required this.url,
    this.style,
  });

  final String label;
  final String url;

  /// The surrounding text's style; the link adds the brand colour, weight and
  /// underline. Defaults to body text.
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final linkStyle = (style ?? AppTypography.bodyMedium).copyWith(
      color: AppColors.primary,
      fontWeight: FontWeight.w600,
    );

    final uri = Uri.tryParse(url);
    if (url.isEmpty || uri == null) return Text(label, style: linkStyle);

    return Semantics(
      link: true,
      child: InkWell(
        onTap: () async {
          final opened = await launchUrl(
            uri,
            mode: LaunchMode.inAppBrowserView,
          );
          if (!opened && context.mounted) {
            showZSnack(context, 'Could not open $label.', isError: true);
          }
        },
        borderRadius: AppRadius.smAll,
        // A full 48 dp touch target (Material and Play's accessibility
        // check), however small the text: the box grows, the text stays
        // centred on the line's baseline.
        child: ConstrainedBox(
          constraints: const BoxConstraints(
            minHeight: kMinInteractiveDimension,
            minWidth: kMinInteractiveDimension,
          ),
          child: Align(
            widthFactor: 1,
            heightFactor: 1,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 2),
              child: Text(
                label,
                style: linkStyle.copyWith(
                  decoration: TextDecoration.underline,
                  decorationColor: AppColors.primary.withValues(alpha: 0.4),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
