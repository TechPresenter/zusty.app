import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_failure.dart';
import '../../../providers/auth_provider.dart';
import '../../../providers/opportunity_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';

/// Keeps the opportunity screens to creators, decided from the session before
/// any request is made.
///
/// A brand can reach these routes — a shared link, an admin-configured menu
/// entry — and the API would answer with the brand's own campaigns or a 403.
/// Neither is an error from the brand's point of view; they are simply on the
/// wrong side of the marketplace, so they get a polite pointer to their own
/// campaigns instead.
class CreatorOnlyGate extends ConsumerWidget {
  const CreatorOnlyGate({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(authProvider).isAuthenticated) return const SignInPrompt();
    if (!ref.watch(isCreatorSessionProvider)) return const CreatorsOnlyState();
    return child;
  }
}

/// For a brand that opened a creator-only screen.
class CreatorsOnlyState extends StatelessWidget {
  const CreatorsOnlyState({super.key});

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      icon: Icons.storefront_outlined,
      title: 'Campaign discovery is for creators',
      message: 'Creators browse and apply to briefs here. Your own campaigns '
          'and their applicants live under Campaigns.',
      actionLabel: 'Go to my campaigns',
      onAction: () => context.push(Routes.campaigns),
    );
  }
}

/// For a signed-out or guest session: what they would get, and how.
class SignInPrompt extends StatelessWidget {
  const SignInPrompt({super.key});

  @override
  Widget build(BuildContext context) {
    return EmptyState(
      icon: Icons.lock_outline_rounded,
      title: 'Sign in to see opportunities',
      message: 'Paid briefs from brands are open to creator accounts. Sign in '
          'or create one to browse and apply.',
      actionLabel: 'Sign in',
      onAction: () => context.go(Routes.login),
    );
  }
}

/// The failure state for every opportunity screen.
///
/// Splits the failures a creator can act on from the ones they cannot: an
/// expired session asks them to sign in, a brand account gets the creator-only
/// note, a brief that has closed or been removed says so and offers the
/// board, and anything else falls through to the standard retry.
class OpportunityErrorView extends StatelessWidget {
  const OpportunityErrorView({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final failure = error;

    return switch (failure) {
      UnauthorisedFailure() => const SignInPrompt(),
      ForbiddenFailure(code: final code) when code.contains('guest') =>
        const SignInPrompt(),
      ForbiddenFailure(code: 'kyc_required') => ErrorState(
          error: failure,
          onRetry: onRetry,
        ),
      ForbiddenFailure() => const CreatorsOnlyState(),
      NotFoundFailure() => EmptyState(
          icon: Icons.search_off_rounded,
          title: 'This brief is no longer available',
          message: 'The brand may have closed or removed it. There are '
              'plenty more on the board.',
          actionLabel: 'Browse opportunities',
          onAction: () => context.canPop()
              ? context.pop()
              : context.go(Routes.creatorCampaigns),
        ),
      _ => ErrorState(error: failure, onRetry: onRetry),
    };
  }
}
