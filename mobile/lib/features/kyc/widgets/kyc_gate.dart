import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_failure.dart';
import '../../../models/user.dart' show KycStatus;
import '../../../providers/auth_provider.dart';
import '../../../providers/kyc_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_feedback.dart';
import 'kyc_status_style.dart';

/// Whether [error] is the server's verification gate (`kyc_required`).
bool isKycGate(Object? error) =>
    error is ApiFailure && error.code == 'kyc_required';

/// What a verification gate says, worked out from where the user's
/// verification actually stands.
///
/// The server's refusal already names the status ("being reviewed",
/// "suspended — contact support"), and its message is shown as-is. The title
/// and the way out follow the same status, so someone whose documents are
/// with a reviewer is not told to "verify your identity", and someone on hold
/// is sent to support rather than to a screen they cannot change.
class KycGateCopy {
  const KycGateCopy({
    required this.title,
    required this.actionLabel,
    required this.route,
    required this.tone,
  });

  final String title;
  final String actionLabel;
  final String route;
  final StatusTone tone;

  factory KycGateCopy.of(KycStatus? status) => switch (status) {
        KycStatus.pending || KycStatus.underReview => const KycGateCopy(
            title: 'Your verification is under review',
            actionLabel: 'View verification',
            route: Routes.kyc,
            tone: StatusTone.info,
          ),
        KycStatus.actionNeeded => const KycGateCopy(
            title: 'Your verification needs attention',
            actionLabel: 'Fix verification',
            route: Routes.kyc,
            tone: StatusTone.warning,
          ),
        KycStatus.rejected => const KycGateCopy(
            title: 'Your verification was not approved',
            actionLabel: 'Fix and resubmit',
            route: Routes.kyc,
            tone: StatusTone.error,
          ),
        KycStatus.suspended => const KycGateCopy(
            title: 'Your verification is on hold',
            actionLabel: 'Contact support',
            route: Routes.support,
            tone: StatusTone.error,
          ),
        KycStatus.expired => const KycGateCopy(
            title: 'Your verification has expired',
            actionLabel: 'Renew verification',
            route: Routes.kyc,
            tone: StatusTone.warning,
          ),
        KycStatus.notSubmitted => const KycGateCopy(
            title: 'Verify your identity to continue',
            actionLabel: 'Verify',
            route: Routes.kyc,
            tone: StatusTone.warning,
          ),
        // Approved here but refused by the server, or not known yet: the
        // verification screen has the current answer.
        KycStatus.approved || null => const KycGateCopy(
            title: 'Verification needed',
            actionLabel: 'View verification',
            route: Routes.kyc,
            tone: StatusTone.warning,
          ),
      };
}

/// The user's verification status as best known: the verification centre's
/// own reading, else the account's coarser `kyc_status`.
KycStatus? _knownStatus(WidgetRef ref) =>
    ref.watch(kycSummaryProvider)?.status ??
    ref.watch(currentUserProvider)?.kycStatus;

/// The message to show for [failure]: the server's own, which names the
/// status, or the status's explanation when it sent none.
String _messageFor(ApiFailure failure, KycStatus? status) {
  final message = failure.message.trim();
  if (message.isNotEmpty) return message;
  return status?.explanation ??
      'Verify your identity to continue. It only takes a few minutes.';
}

/// The gate as an inline banner, for a form the user should not lose —
/// the application form keeps what was typed while they sort it out.
class KycGateBanner extends ConsumerStatefulWidget {
  const KycGateBanner({super.key, required this.failure, this.footnote});

  final ApiFailure failure;

  /// Added after the message, e.g. "Your application is saved here."
  final String? footnote;

  @override
  ConsumerState<KycGateBanner> createState() => _KycGateBannerState();
}

class _KycGateBannerState extends ConsumerState<KycGateBanner> {
  @override
  void initState() {
    super.initState();
    // The refusal means the status the app holds may be stale.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) unawaited(ref.read(kycProvider.notifier).refreshQuietly());
    });
  }

  @override
  Widget build(BuildContext context) {
    final status = _knownStatus(ref);
    final copy = KycGateCopy.of(status);
    final message = _messageFor(widget.failure, status);

    return ZInfoBanner(
      tone: copy.tone,
      icon: Icons.verified_user_outlined,
      title: copy.title,
      message: widget.footnote == null ? message : '$message ${widget.footnote}',
      actionLabel: copy.actionLabel,
      onAction: () => context.push(copy.route),
    );
  }
}

/// The gate as a whole screen, for when the gated thing could not even load.
class KycGateView extends ConsumerStatefulWidget {
  const KycGateView({super.key, required this.failure});

  final ApiFailure failure;

  @override
  ConsumerState<KycGateView> createState() => _KycGateViewState();
}

class _KycGateViewState extends ConsumerState<KycGateView> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) unawaited(ref.read(kycProvider.notifier).refreshQuietly());
    });
  }

  @override
  Widget build(BuildContext context) {
    final status = _knownStatus(ref);
    final copy = KycGateCopy.of(status);

    return EmptyState(
      icon: status?.icon ?? Icons.verified_user_outlined,
      title: copy.title,
      message: _messageFor(widget.failure, status),
      actionLabel: copy.actionLabel,
      onAction: () => context.push(copy.route),
    );
  }
}
