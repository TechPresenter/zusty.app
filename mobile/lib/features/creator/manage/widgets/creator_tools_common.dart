import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/network/api_failure.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../providers/opportunity_provider.dart';
import '../../../../router/routes.dart';
import '../../../../shared/widgets/states.dart';
import '../../../../shared/widgets/status_chip.dart';
import '../../../../shared/widgets/z_dialogs.dart';
import '../../../../shared/widgets/z_feedback.dart';

/// Keeps the creator editors to creator accounts, decided from the session
/// before any request is made.
///
/// A brand can still reach these routes — an old link, an admin-built menu
/// entry — and the API would answer 403. That is not an error from the
/// brand's side; they are on the other side of the marketplace, so they get a
/// short explanation instead of a failure screen.
class CreatorToolsGate extends ConsumerWidget {
  const CreatorToolsGate({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(isCreatorSessionProvider)) return const CreatorsOnlyNote();
    return child;
  }
}

/// For a brand that opened a creator editor.
class CreatorsOnlyNote extends StatelessWidget {
  const CreatorsOnlyNote({super.key});

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      icon: Icons.storefront_outlined,
      title: 'This is for creator accounts',
      message:
          'Creators set out their packages, portfolio and social accounts '
          'here. Brands find them on each creator\'s profile.',
      actionLabel: 'Browse creators',
      onAction: () => context.go(Routes.search),
    );
  }
}

/// The failure state for the editors' lists: a 403 here means the account is
/// not a creator, anything else gets the standard retry.
class CreatorToolsErrorView extends StatelessWidget {
  const CreatorToolsErrorView({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return switch (error) {
      ForbiddenFailure() => const CreatorsOnlyNote(),
      _ => ErrorState(error: error, onRetry: onRetry),
    };
  }
}

/// Says up front that an administrator has frozen this part of the profile.
///
/// Controls stay where they are: a lock can be lifted while the screen is
/// open, and the server's refusal names the reason, which this cannot know.
class LockedSectionBanner extends StatelessWidget {
  const LockedSectionBanner({super.key, required this.what});

  /// "Your pricing", "Your portfolio".
  final String what;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: ZInfoBanner(
        tone: StatusTone.warning,
        icon: Icons.lock_outline_rounded,
        title: '$what is locked',
        message:
            'Our team has paused changes here. What brands see stays as it '
            'is. Contact support if you need something changed.',
        actionLabel: 'Support',
        onAction: () => context.push(Routes.support),
      ),
    );
  }
}

/// An error the server sent back, shown where the form is rather than in a
/// toast that disappears before it is read.
class FormErrorBanner extends StatelessWidget {
  const FormErrorBanner({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Semantics(
        liveRegion: true,
        child: ZInfoBanner(tone: StatusTone.error, message: message),
      ),
    );
  }
}

/// The server's words for a failure, or a plain fallback.
String failureMessage(Object error) => error is ApiFailure
    ? error.message
    : 'Something went wrong. Please try again.';

/// The field-keyed messages of a 422, for the fields a form knows about.
Map<String, String> fieldErrors(Object error, Iterable<String> fields) {
  if (error is! ApiFailure) return const {};

  return {
    for (final field in fields)
      if (error.errorFor(field) != null) field: error.errorFor(field)!,
  };
}

/// Asks before removing something the server cannot bring back.
Future<bool> confirmRemoval(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Remove',
}) => showZConfirm(
  context,
  title: title,
  message: message,
  confirmLabel: confirmLabel,
  cancelLabel: 'Keep it',
  destructive: true,
  icon: Icons.delete_outline_rounded,
);
