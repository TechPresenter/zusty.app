import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../data/repositories/privacy_repository.dart';
import '../../models/privacy.dart';
import '../../providers/auth_provider.dart';
import '../../providers/privacy_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import 'widgets/privacy_widgets.dart';
import 'widgets/reauth_panel.dart';

/// What the screen needs to finish a deletion, read before its first await:
/// the root providers behind these outlive the screen, which may be gone by
/// the time the server answers.
typedef _Handover = ({
  PrivacyRepository repository,
  StateController<bool> deletedFlag,
  AuthController auth,
});

/// What the server says when asked, after a deletion whose answer was lost.
enum _Outcome { notDeleted, deleted, signedOut, unknown }

/// "Delete my account", in three deliberate steps:
///
/// 1. **Before you go**: what is erased, what the law makes us keep, and
///    anything that must be settled first (open bookings, wallet money), or a
///    small balance the user can agree to give up.
/// 2. **Why are you leaving?**: optional.
/// 3. **Confirm**: prove it is you, type the confirmation phrase, tick that
///    you understand, then a last "are you sure".
///
/// The server erases the account and revokes every session. The app then
/// hands the device over to [finishAccountDeletion], which clears it and
/// lands on a goodbye screen. If the answer never arrives, the screen asks
/// the server whether the account still exists instead of guessing.
class DeleteAccountScreen extends ConsumerStatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  ConsumerState<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends ConsumerState<DeleteAccountScreen> {
  static const _steps = 3;

  /// When to ask whether a deletion went through after its answer was lost:
  /// about 3, 10 and 35 seconds later. The last falls outside the client's
  /// 30 second pause after a dropped connection, so it reaches the server.
  static const _checkWaits = [
    Duration(seconds: 3),
    Duration(seconds: 7),
    Duration(seconds: 25),
  ];

  int _step = 0;
  String? _reasonCode;
  final _reasonText = TextEditingController();
  final _phrase = TextEditingController();
  bool _understood = false;
  bool _forfeitAccepted = false;

  /// The deletion, or the check after it, is running: the overlay is up.
  bool _deleting = false;

  /// The overlay says it is checking whether the account was deleted.
  bool _checking = false;

  /// The server never answered and the checks could not reach it: the
  /// bottom button only checks again, rather than re-sending the deletion.
  bool _unconfirmed = false;

  /// The failure that left the outcome unknown, for clearing a code the
  /// server may have used once it turns out nothing was deleted.
  ApiFailure? _unknownFailure;

  /// Step 1's "Check again" is reloading the preflight.
  bool _rechecking = false;

  /// A failure on the confirm step that is not about the proof or phrase.
  String? _error;
  String? _phraseError;

  /// Why the flow was sent back to step 1 (something changed meanwhile).
  String? _notice;

  ReauthController? _reauth;

  Timer? _pauseTimer;
  Completer<void>? _pauseDone;

  @override
  void dispose() {
    _pauseTimer?.cancel();
    final pause = _pauseDone;
    if (pause != null && !pause.isCompleted) pause.complete();
    _reasonText.dispose();
    _phrase.dispose();
    _reauth?.dispose();
    super.dispose();
  }

  ReauthController _reauthFor(VerificationOptions options) => _reauth ??= ReauthController(
        options: options,
        purpose: PrivacyPurpose.delete,
        repository: ref.read(privacyRepositoryProvider),
        firebaseToken: ref.read(firebaseTokenSourceProvider),
      );

  void _goTo(int step) => setState(() {
        _step = step;
        _error = null;
      });

  /// Back out of the flow: to wherever the user came from, or to Privacy &
  /// data when this screen was opened on its own (a link, a reload).
  void _leave() => context.canPop() ? context.pop() : context.go(Routes.privacy);

  bool _phraseMatches(DeletionPreflight deletion) =>
      _phrase.text.trim().toUpperCase() == deletion.confirmPhrase.toUpperCase();

  void _openBlocker(DeletionBlocker blocker) {
    switch (blocker.action) {
      case 'bookings':
        context.go(Routes.bookings);
      case 'wallet':
        context.push(Routes.wallet);
      case 'kyc':
        context.push(Routes.kyc);
      default:
        context.push(Routes.newTicket);
    }
  }

  /// Step 1's "Check again": reloads the preflight and waits for it. A
  /// failure keeps what is on screen and says so.
  Future<void> _recheck() async {
    if (_rechecking) return;

    setState(() {
      _rechecking = true;
      _notice = null;
    });

    try {
      await ref.refresh(privacyOverviewProvider.future).then((_) {});
    } on ApiFailure catch (failure) {
      if (mounted) showZSnack(context, failure.message, isError: true);
    } catch (_) {
      if (mounted) showZSnack(context, 'We couldn\'t check again. Please try again.', isError: true);
    } finally {
      if (mounted) setState(() => _rechecking = false);
    }
  }

  _Handover _handover() => (
        repository: ref.read(privacyRepositoryProvider),
        deletedFlag: ref.read(accountDeletedProvider.notifier),
        auth: ref.read(authProvider.notifier),
      );

  Future<void> _delete(DeletionPreflight deletion) async {
    final reauth = _reauth;
    final proof = reauth?.proof;
    if (reauth == null || proof == null || _deleting) return;

    if (!_phraseMatches(deletion)) {
      setState(() => _phraseError = 'Type ${deletion.confirmPhrase} to confirm.');
      return;
    }

    // Read before the first await (see [_Handover]).
    final handover = _handover();
    final deletedNotice = ref.read(accountDeletedNoticeProvider.notifier);
    final inFlight = ref.read(deletionInFlightProvider.notifier);
    final forfeit = deletion.forfeit;

    final confirmed = await showZConfirm(
      context,
      title: 'Delete your account permanently?',
      message:
          'Your profile and personal data will be erased and you\'ll be '
          'signed out on every device.'
          '${forfeit == null ? '' : ' You also give up ${forfeit.label} in your wallet.'}'
          ' This can\'t be undone.',
      confirmLabel: 'Delete permanently',
      cancelLabel: 'Keep my account',
      destructive: true,
      icon: Icons.warning_amber_rounded,
    );
    if (!confirmed || !mounted) return;

    final confirmation = _phrase.text.trim();
    final reasonCode = _reasonCode;
    final reason = _reasonText.text;
    final forfeitBalance = forfeit != null && _forfeitAccepted ? forfeit.amount : null;

    setState(() {
      _deleting = true;
      _error = null;
      _unconfirmed = false;
    });
    // The route refuses to be left until the server has answered.
    inFlight.state = true;

    try {
      final DeletionResult result;
      try {
        result = await handover.repository.deleteAccount(
          proof: proof,
          confirmation: confirmation,
          reasonCode: reasonCode,
          reason: reason,
          forfeitBalance: forfeitBalance,
        );
      } on ApiFailure catch (failure) {
        await _deletionFailed(failure, reauth, handover);
        return;
      }

      // The account is erased server-side. Hand the device over. The router
      // is rebuilt by the sign-out and opens on the goodbye screen, so
      // nothing here may touch `context` afterwards.
      deletedNotice.state = result.notice;
      await finishAccountDeletion(handover.deletedFlag, handover.auth);
    } finally {
      inFlight.state = false;
      if (mounted) {
        setState(() {
          _deleting = false;
          _checking = false;
        });
      }
    }
  }

  /// No answer at all: the server may or may not have erased the account.
  static bool _outcomeUnknown(ApiFailure failure) => switch (failure) {
        TimeoutFailure() || OfflineFailure() || ServerFailure() => true,
        UnknownFailure() => failure.statusCode == null,
        _ => false,
      };

  Future<void> _deletionFailed(
    ApiFailure failure,
    ReauthController reauth,
    _Handover handover,
  ) async {
    // Already gone: this request erased it and only the answer was lost, or
    // an earlier one did.
    if (failure is UnauthorisedFailure && failure.code == 'account_deleted') {
      await finishAccountDeletion(handover.deletedFlag, handover.auth);
      return;
    }

    if (!mounted) return;

    // Ask the server rather than send the deletion again, possibly with a
    // code it has already used.
    if (_outcomeUnknown(failure)) {
      _unknownFailure = failure;
      await _settleOutcome(handover, waits: _checkWaits);
      return;
    }

    // A definite refusal: nothing was deleted.
    setState(() => _deleting = false);

    if (reauth.applyFailure(failure)) return;

    if (failure.code == 'confirmation_mismatch') {
      setState(() => _phraseError = failure.errorFor('confirmation') ?? failure.message);
      return;
    }

    // Something now blocks the deletion: a booking came in, money landed,
    // the balance to give up changed. Back to the first step, where the
    // blockers are listed with fixes.
    if (failure is ConflictFailure) {
      ref.invalidate(privacyOverviewProvider);
      setState(() {
        _step = 0;
        _notice = failure.message;
        if (failure.code == 'forfeit_required') _forfeitAccepted = false;
      });
      return;
    }

    setState(() => _error = failure.message);
  }

  /// The bottom button while the outcome is unknown: asks once more.
  Future<void> _checkAgain() async {
    if (_deleting) return;

    final handover = _handover();
    final inFlight = ref.read(deletionInFlightProvider.notifier);

    setState(() {
      _deleting = true;
      _unconfirmed = false;
    });
    inFlight.state = true;

    try {
      await _settleOutcome(handover, waits: const [Duration.zero]);
    } finally {
      inFlight.state = false;
      if (mounted) {
        setState(() {
          _deleting = false;
          _checking = false;
        });
      }
    }
  }

  /// Asks the server, after each of [waits], whether the account still
  /// exists, and acts on the first clear answer.
  Future<void> _settleOutcome(_Handover handover, {required List<Duration> waits}) async {
    if (!mounted) return;
    setState(() => _checking = true);

    var outcome = _Outcome.unknown;
    for (final wait in waits) {
      if (wait > Duration.zero) await _pause(wait);
      if (!mounted) return;
      outcome = await _askWhetherDeleted(handover.repository);
      if (outcome != _Outcome.unknown) break;
    }

    switch (outcome) {
      case _Outcome.deleted:
        await finishAccountDeletion(handover.deletedFlag, handover.auth);
      case _Outcome.signedOut:
        // The session ended some other way, and the app is already signing
        // out. Nothing to add here.
        break;
      case _Outcome.notDeleted:
        if (!mounted) return;
        // The server may have used the code before it failed: ask for a new
        // one. The account itself is unchanged.
        final failure = _unknownFailure;
        if (failure != null) _reauth?.applyFailure(failure);
        setState(() {
          _checking = false;
          _unconfirmed = false;
          _unknownFailure = null;
          _error = 'Your account was not deleted. Nothing changed, so you can try again.';
        });
      case _Outcome.unknown:
        if (!mounted) return;
        setState(() {
          _checking = false;
          _unconfirmed = true;
          _error = null;
        });
    }
  }

  static Future<_Outcome> _askWhetherDeleted(PrivacyRepository repository) async {
    try {
      await repository.checkAccountExists();
      return _Outcome.notDeleted;
    } on UnauthorisedFailure catch (failure) {
      return failure.code == 'account_deleted' ? _Outcome.deleted : _Outcome.signedOut;
    } on ApiFailure {
      return _Outcome.unknown;
    }
  }

  /// A wait that ends early, and harmlessly, if the screen closes.
  Future<void> _pause(Duration duration) {
    final done = _pauseDone = Completer<void>();
    _pauseTimer = Timer(duration, () {
      if (!done.isCompleted) done.complete();
    });
    return done.future;
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(privacyOverviewProvider);
    final data = overview.valueOrNull;

    return PopScope(
      canPop: _step == 0 && !_deleting,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && !_deleting && _step > 0) _goTo(_step - 1);
      },
      child: Stack(
        children: [
          ZScaffold(
            padded: false,
            appBar: ZAppBar(
              title: 'Delete my account',
              onBack: _step > 0 ? () => _goTo(_step - 1) : _leave,
            ),
            bottomBar: data == null ? null : _bottomBar(data),
            // `skipError`: a failed "Check again" keeps the steps on screen
            // (and says so); the error page is only for a first load.
            body: overview.when(
              skipError: true,
              loading: () => const ListSkeleton(),
              error: (error, _) => ErrorState(
                error: error,
                onRetry: () => ref.invalidate(privacyOverviewProvider),
              ),
              data: (data) => AnimatedSwitcher(
                duration: Motion.of(context, Motion.medium),
                switchInCurve: Motion.standard,
                switchOutCurve: Motion.exit,
                child: KeyedSubtree(
                  key: ValueKey(_step),
                  child: switch (_step) {
                    0 => _BeforeYouGo(
                        deletion: data.deletion,
                        verification: data.verification,
                        notice: _notice,
                        forfeitAccepted: _forfeitAccepted,
                        rechecking: _rechecking,
                        onOpenBlocker: _openBlocker,
                        onRecheck: _recheck,
                        onForfeitChanged: (value) => setState(() => _forfeitAccepted = value),
                        onContactSupport: () => context.push(Routes.newTicket),
                      ),
                    1 => _WhyLeaving(
                        reasons: data.deletion.reasons,
                        selected: _reasonCode,
                        controller: _reasonText,
                        onSelected: (code) => setState(() => _reasonCode = code),
                      ),
                    _ => _ConfirmDeletion(
                        deletion: data.deletion,
                        reauth: _reauthFor(data.verification),
                        phrase: _phrase,
                        phraseError: _phraseError,
                        understood: _understood,
                        error: _error,
                        unconfirmed: _unconfirmed,
                        onPhraseChanged: () => setState(() => _phraseError = null),
                        onUnderstood: (value) => setState(() => _understood = value),
                        onSubmitted: () => _delete(data.deletion),
                      ),
                  },
                ),
              ),
            ),
          ),
          if (_deleting) _DeletingOverlay(checking: _checking),
        ],
      ),
    );
  }

  Widget _bottomBar(PrivacyOverview data) {
    final deletion = data.deletion;

    switch (_step) {
      case 0:
        return Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ZButton(
              label: 'Continue',
              onPressed: deletion.canDelete && (deletion.forfeit == null || _forfeitAccepted)
                  ? () => _goTo(1)
                  : null,
            ),
            TextButton(
              onPressed: _leave,
              child: const Text('Keep my account'),
            ),
          ],
        );
      case 1:
        return Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ZButton(label: 'Continue', onPressed: () => _goTo(2)),
            TextButton(
              onPressed: () {
                setState(() {
                  _reasonCode = null;
                  _reasonText.clear();
                });
                _goTo(2);
              },
              child: const Text('Skip'),
            ),
          ],
        );
      default:
        // Whether the account was deleted is unknown: sending the deletion
        // again could spend a code for nothing, so the only way on is to ask.
        if (_unconfirmed) {
          return ZButton(
            label: 'Check again',
            icon: Icons.refresh_rounded,
            isLoading: _deleting,
            onPressed: _checkAgain,
          );
        }

        final reauth = _reauthFor(data.verification);
        return ListenableBuilder(
          listenable: Listenable.merge([reauth, _phrase]),
          builder: (context, _) => ZButton(
            label: 'Delete my account',
            icon: Icons.delete_forever_rounded,
            variant: ZButtonVariant.danger,
            isLoading: _deleting,
            onPressed: reauth.canVerify &&
                    reauth.isComplete &&
                    _phraseMatches(deletion) &&
                    _understood
                ? () => _delete(deletion)
                : null,
          ),
        );
    }
  }
}

/// "Step 2 of 3" with a three-segment progress bar.
class _StepHeader extends StatelessWidget {
  const _StepHeader({required this.step, required this.title, this.subtitle});

  final int step;
  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            for (var i = 0; i < _DeleteAccountScreenState._steps; i++)
              Expanded(
                child: AnimatedContainer(
                  duration: Motion.of(context, Motion.medium),
                  curve: Motion.standard,
                  height: 4,
                  margin: EdgeInsets.only(right: i < _DeleteAccountScreenState._steps - 1 ? 6 : 0),
                  decoration: BoxDecoration(
                    color: i <= step ? AppColors.error : AppColors.outlineVariant,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: AppSpacing.md),
        Text(
          'Step ${step + 1} of ${_DeleteAccountScreenState._steps}',
          style: AppTypography.labelMedium.copyWith(color: AppColors.onSurfaceVariant),
        ),
        const SizedBox(height: 2),
        Semantics(header: true, child: Text(title, style: AppTypography.headlineMedium)),
        if (subtitle != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(
            subtitle!,
            style: AppTypography.bodyMedium.copyWith(color: AppColors.onSurfaceVariant, height: 1.5),
          ),
        ],
      ],
    );
  }
}

EdgeInsets _pagePadding(BuildContext context) {
  final margin = AppBreakpoints.pageMargin(context);
  return EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xxl);
}

// ---------------------------------------------------------------------------
// Step 1: Before you go
// ---------------------------------------------------------------------------

class _BeforeYouGo extends StatelessWidget {
  const _BeforeYouGo({
    required this.deletion,
    required this.verification,
    required this.notice,
    required this.forfeitAccepted,
    required this.rechecking,
    required this.onOpenBlocker,
    required this.onRecheck,
    required this.onForfeitChanged,
    required this.onContactSupport,
  });

  final DeletionPreflight deletion;
  final VerificationOptions verification;
  final String? notice;
  final bool forfeitAccepted;
  final bool rechecking;
  final ValueChanged<DeletionBlocker> onOpenBlocker;
  final VoidCallback onRecheck;
  final ValueChanged<bool> onForfeitChanged;
  final VoidCallback onContactSupport;

  @override
  Widget build(BuildContext context) {
    final summary = deletion.retainedSummary;
    final forfeit = deletion.forfeit;

    return ListView(
      padding: _pagePadding(context),
      children: [
        const _StepHeader(step: 0, title: 'Before you go'),
        const SizedBox(height: AppSpacing.md),
        if (notice != null) ...[
          ZInfoBanner(tone: StatusTone.warning, message: notice!),
          const SizedBox(height: AppSpacing.sm),
        ],
        const ZInfoBanner(
          tone: StatusTone.error,
          icon: Icons.warning_amber_rounded,
          title: 'This is permanent',
          message:
              'Deleting your account erases your profile and personal data and '
              'signs you out everywhere. It can\'t be undone, and the account '
              'can\'t be recovered.',
        ),
        if (!verification.canVerify) ...[
          const SizedBox(height: AppSpacing.sm),
          ZInfoBanner(
            tone: StatusTone.warning,
            title: 'We can\'t confirm it\'s you in the app',
            message: reauthBlockedMessage(verification),
            actionLabel: 'Contact support',
            onAction: onContactSupport,
          ),
        ],
        if (deletion.blockers.isNotEmpty) ...[
          const PrivacySectionTitle('Settle these first'),
          for (final blocker in deletion.blockers) ...[
            _BlockerCard(blocker: blocker, onAction: () => onOpenBlocker(blocker)),
            const SizedBox(height: AppSpacing.xs),
          ],
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: rechecking ? null : onRecheck,
              icon: rechecking
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('Check again'),
            ),
          ),
        ],
        if (forfeit != null) ...[
          const PrivacySectionTitle('Money in your wallet'),
          _ForfeitCard(
            forfeit: forfeit,
            accepted: forfeitAccepted,
            onChanged: onForfeitChanged,
            onContactSupport: onContactSupport,
          ),
        ],
        const PrivacySectionTitle('What will be deleted'),
        Reveal(
          index: 0,
          child: PrivacyListCard(
            items: deletion.erased,
            icon: Icons.remove_circle_outline_rounded,
            iconColor: AppColors.error,
          ),
        ),
        const PrivacySectionTitle('What we keep, and why'),
        Reveal(
          index: 1,
          child: PrivacyListCard(
            items: deletion.retained,
            icon: Icons.inventory_2_outlined,
            iconColor: AppColors.onSurfaceVariant,
            footer: summary.isEmpty
                ? null
                : 'From your account, that\'s $summary, kept for the legal period.',
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        Reveal(
          index: 2,
          child: ZCard(
            shadow: false,
            onTap: () => context.push(Routes.privacyExport),
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Row(
              children: [
                Icon(Icons.file_download_outlined, color: AppColors.primary),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Want a copy first?', style: AppTypography.titleSmall),
                      const SizedBox(height: 2),
                      Text(
                        'Download your data before you delete your account. Once it\'s gone, we can\'t send it to you.',
                        style: AppTypography.bodySmall.copyWith(height: 1.45),
                      ),
                    ],
                  ),
                ),
                Icon(Icons.chevron_right_rounded, color: AppColors.onSurfaceVariant),
              ],
            ),
          ),
        ),
        if (!deletion.canDelete) ...[
          const SizedBox(height: AppSpacing.md),
          Text(
            'Settle the items above, then come back to delete your account.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
        ],
      ],
    );
  }
}

class _BlockerCard extends StatelessWidget {
  const _BlockerCard({required this.blocker, required this.onAction});

  final DeletionBlocker blocker;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) {
    final (IconData icon, String label) = switch (blocker.action) {
      'bookings' => (Icons.event_busy_rounded, 'View bookings'),
      'wallet' => (Icons.account_balance_wallet_outlined, 'Open wallet'),
      'kyc' => (Icons.verified_user_outlined, 'Verify your identity'),
      _ => (Icons.support_agent_rounded, 'Contact support'),
    };

    return ZCard(
      shadow: false,
      color: AppColors.errorContainer.withValues(alpha: 0.35),
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: AppColors.error),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(blocker.title, style: AppTypography.titleSmall),
                const SizedBox(height: 2),
                Text(blocker.message, style: AppTypography.bodySmall.copyWith(height: 1.45)),
                const SizedBox(height: AppSpacing.xs),
                ZButton.outlined(
                  label: label,
                  size: ZButtonSize.small,
                  expand: false,
                  onPressed: onAction,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A balance that can't be paid out, which the user may give up to delete
/// now, or have support pay out first.
class _ForfeitCard extends StatelessWidget {
  const _ForfeitCard({
    required this.forfeit,
    required this.accepted,
    required this.onChanged,
    required this.onContactSupport,
  });

  final DeletionForfeit forfeit;
  final bool accepted;
  final ValueChanged<bool> onChanged;
  final VoidCallback onContactSupport;

  @override
  Widget build(BuildContext context) {
    final (background, _) = StatusChip.colorsFor(StatusTone.warning);

    return ZCard(
      shadow: false,
      color: background.withValues(alpha: 0.6),
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.account_balance_wallet_outlined, color: AppColors.onSurfaceVariant),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  forfeit.message,
                  style: AppTypography.bodySmall.copyWith(height: 1.45),
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          CheckboxListTile(
            value: accepted,
            onChanged: (value) => onChanged(value ?? false),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            activeColor: AppColors.error,
            title: Text(
              'I give up ${forfeit.label} in my wallet',
              style: AppTypography.bodyMedium,
            ),
          ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: onContactSupport,
              icon: const Icon(Icons.support_agent_rounded, size: 18),
              label: const Text('Contact support instead'),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Step 2: Why are you leaving?
// ---------------------------------------------------------------------------

class _WhyLeaving extends StatelessWidget {
  const _WhyLeaving({
    required this.reasons,
    required this.selected,
    required this.controller,
    required this.onSelected,
  });

  final List<DeletionReason> reasons;
  final String? selected;
  final TextEditingController controller;
  final ValueChanged<String?> onSelected;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: _pagePadding(context),
      children: [
        const _StepHeader(
          step: 1,
          title: 'Why are you leaving?',
          subtitle: 'Optional. It helps us make Zusty better for everyone.',
        ),
        const SizedBox(height: AppSpacing.md),
        ZCard(
          shadow: false,
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
          child: Column(
            children: [
              for (final reason in reasons)
                _ReasonOption(
                  label: reason.label,
                  selected: reason.code == selected,
                  onTap: () => onSelected(reason.code == selected ? null : reason.code),
                ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        ZTextField(
          label: 'Anything else you\'d like to tell us?',
          hint: 'Optional',
          controller: controller,
          maxLines: 4,
          minLines: 3,
          maxLength: 500,
          textCapitalization: TextCapitalization.sentences,
        ),
      ],
    );
  }
}

class _ReasonOption extends StatelessWidget {
  const _ReasonOption({required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      selected: selected,
      inMutuallyExclusiveGroup: true,
      button: true,
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: AppSpacing.minTouch),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.xs),
            child: Row(
              children: [
                AnimatedSwitcher(
                  duration: Motion.of(context, Motion.fast),
                  child: Icon(
                    selected ? Icons.radio_button_checked_rounded : Icons.radio_button_unchecked_rounded,
                    key: ValueKey(selected),
                    color: selected ? AppColors.primary : AppColors.onSurfaceVariant,
                    size: 22,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(child: Text(label, style: AppTypography.bodyMedium)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Step 3: Confirm
// ---------------------------------------------------------------------------

class _ConfirmDeletion extends ConsumerWidget {
  const _ConfirmDeletion({
    required this.deletion,
    required this.reauth,
    required this.phrase,
    required this.phraseError,
    required this.understood,
    required this.error,
    required this.unconfirmed,
    required this.onPhraseChanged,
    required this.onUnderstood,
    required this.onSubmitted,
  });

  final DeletionPreflight deletion;
  final ReauthController reauth;
  final TextEditingController phrase;
  final String? phraseError;
  final bool understood;
  final String? error;

  /// The server never answered, and whether the account was deleted is not
  /// known yet.
  final bool unconfirmed;
  final VoidCallback onPhraseChanged;
  final ValueChanged<bool> onUnderstood;
  final VoidCallback onSubmitted;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);

    return ListView(
      padding: _pagePadding(context),
      children: [
        const _StepHeader(step: 2, title: 'Confirm deletion'),
        const SizedBox(height: AppSpacing.md),
        if (user != null)
          ZCard(
            shadow: false,
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Row(
              children: [
                ZAvatar(imageUrl: user.avatarUrl, name: user.name, size: 44),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('You\'re deleting', style: AppTypography.bodySmall),
                      Text(
                        user.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleSmall,
                      ),
                      if ((user.email ?? '').isNotEmpty)
                        Text(
                          user.email!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodySmall,
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        const PrivacySectionTitle('Confirm it\'s you'),
        ReauthPanel(controller: reauth),
        PrivacySectionTitle('Type ${deletion.confirmPhrase} to confirm'),
        ZTextField(
          label: 'Type ${deletion.confirmPhrase}',
          controller: phrase,
          prefixIcon: Icons.keyboard_outlined,
          textCapitalization: TextCapitalization.characters,
          errorText: phraseError,
          onChanged: (_) => onPhraseChanged(),
        ),
        const SizedBox(height: AppSpacing.xs),
        CheckboxListTile(
          value: understood,
          onChanged: (value) => onUnderstood(value ?? false),
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          activeColor: AppColors.error,
          title: Text(
            'I understand my account and personal data will be permanently '
            'deleted, and this can\'t be undone.',
            style: AppTypography.bodyMedium,
          ),
        ),
        if (unconfirmed) ...[
          const SizedBox(height: AppSpacing.xs),
          const ZInfoBanner(
            tone: StatusTone.warning,
            icon: Icons.sync_problem_rounded,
            message:
                'We couldn\'t confirm whether your account was deleted. Check '
                'your connection, then tap Check again.',
          ),
        ],
        if (error != null) ...[
          const SizedBox(height: AppSpacing.xs),
          ZInfoBanner(message: error!, tone: StatusTone.error),
        ],
      ],
    );
  }
}

/// Covers the screen while the deletion runs, or while the screen checks
/// whether a deletion whose answer was lost went through, so nothing can be
/// tapped and the user can see it is happening.
class _DeletingOverlay extends StatelessWidget {
  const _DeletingOverlay({required this.checking});

  final bool checking;

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: Stack(
        children: [
          ModalBarrier(dismissible: false, color: Colors.black.withValues(alpha: 0.45)),
          Center(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Material(
                color: Colors.transparent,
                child: ZCard(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const SizedBox(
                        width: 36,
                        height: 36,
                        child: CircularProgressIndicator(strokeWidth: 3),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        checking ? 'Checking whether your account was deleted…' : 'Deleting your account…',
                        textAlign: TextAlign.center,
                        style: AppTypography.titleMedium,
                        semanticsLabel: checking
                            ? 'Checking whether your account was deleted, please wait'
                            : 'Deleting your account, please wait',
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        checking
                            ? 'We didn\'t hear back in time. This can take up to a '
                                'minute. Please keep the app open.'
                            : 'This takes a few seconds. Please keep the app open.',
                        textAlign: TextAlign.center,
                        style: AppTypography.bodySmall,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
