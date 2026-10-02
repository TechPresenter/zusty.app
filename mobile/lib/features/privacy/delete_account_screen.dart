import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
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

/// "Delete my account", in three deliberate steps:
///
/// 1. **Before you go**: what is erased, what the law makes us keep, and
///    anything that must be settled first (open bookings, wallet money).
/// 2. **Why are you leaving?**: optional.
/// 3. **Confirm**: prove it is you, type the confirmation phrase, tick that
///    you understand, then a last "are you sure".
///
/// The server erases the account and revokes every session. The app then
/// clears this device and lands on a goodbye screen.
class DeleteAccountScreen extends ConsumerStatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  ConsumerState<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends ConsumerState<DeleteAccountScreen> {
  static const _steps = 3;

  int _step = 0;
  String? _reasonCode;
  final _reasonText = TextEditingController();
  final _phrase = TextEditingController();
  bool _understood = false;
  bool _deleting = false;

  /// A failure on the confirm step that is not about the proof or phrase.
  String? _error;
  String? _phraseError;

  /// Why the flow was sent back to step 1 (something changed meanwhile).
  String? _notice;

  ReauthController? _reauth;

  @override
  void dispose() {
    _reasonText.dispose();
    _phrase.dispose();
    _reauth?.dispose();
    super.dispose();
  }

  ReauthController _reauthFor(VerificationOptions options) => _reauth ??= ReauthController(
        options: options,
        purpose: PrivacyPurpose.delete,
        repository: ref.read(privacyRepositoryProvider),
      );

  void _goTo(int step) => setState(() {
        _step = step;
        _error = null;
      });

  bool _phraseMatches(DeletionPreflight deletion) =>
      _phrase.text.trim().toUpperCase() == deletion.confirmPhrase.toUpperCase();

  void _openBlocker(DeletionBlocker blocker) {
    switch (blocker.action) {
      case 'bookings':
        context.go(Routes.bookings);
      case 'wallet':
        context.push(Routes.wallet);
      default:
        context.push(Routes.newTicket);
    }
  }

  Future<void> _delete(DeletionPreflight deletion) async {
    final reauth = _reauth;
    final proof = reauth?.proof;
    if (reauth == null || proof == null || _deleting) return;

    if (!_phraseMatches(deletion)) {
      setState(() => _phraseError = 'Type ${deletion.confirmPhrase} to confirm.');
      return;
    }

    final confirmed = await showZConfirm(
      context,
      title: 'Delete your account permanently?',
      message:
          'Your profile and personal data will be erased and you\'ll be '
          'signed out on every device. This can\'t be undone.',
      confirmLabel: 'Delete permanently',
      cancelLabel: 'Keep my account',
      destructive: true,
      icon: Icons.warning_amber_rounded,
    );
    if (!confirmed || !mounted) return;

    setState(() {
      _deleting = true;
      _error = null;
    });

    try {
      await ref.read(privacyRepositoryProvider).deleteAccount(
            proof: proof,
            confirmation: _phrase.text.trim(),
            reasonCode: _reasonCode,
            reason: _reasonText.text,
          );
    } on ApiFailure catch (failure) {
      if (!mounted) return;
      setState(() => _deleting = false);

      if (reauth.applyFailure(failure)) return;

      if (failure.code == 'confirmation_mismatch') {
        setState(() => _phraseError = failure.errorFor('confirmation') ?? failure.message);
        return;
      }

      // Something now blocks the deletion: a booking came in, money landed.
      // Back to the first step, where the blockers are listed with fixes.
      if (failure is ConflictFailure) {
        ref.invalidate(privacyOverviewProvider);
        setState(() {
          _step = 0;
          _notice = failure.message;
        });
        return;
      }

      setState(() => _error = failure.message);
      return;
    }

    // The account is erased server-side. Clear this device. The router is
    // rebuilt by the sign-out and opens on the goodbye screen, so nothing
    // here may touch `context` afterwards.
    ref.read(accountDeletedProvider.notifier).state = true;
    await ref.read(authProvider.notifier).signOutAfterDeletion();
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
              onBack: _step > 0 ? () => _goTo(_step - 1) : null,
            ),
            bottomBar: data == null ? null : _bottomBar(data),
            body: AsyncView(
              value: overview,
              onRetry: () => ref.invalidate(privacyOverviewProvider),
              builder: (data) => AnimatedSwitcher(
                duration: Motion.of(context, Motion.medium),
                switchInCurve: Motion.standard,
                switchOutCurve: Motion.exit,
                child: KeyedSubtree(
                  key: ValueKey(_step),
                  child: switch (_step) {
                    0 => _BeforeYouGo(
                        deletion: data.deletion,
                        notice: _notice,
                        onOpenBlocker: _openBlocker,
                        onRecheck: () {
                          setState(() => _notice = null);
                          ref.invalidate(privacyOverviewProvider);
                        },
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
                        onPhraseChanged: () => setState(() => _phraseError = null),
                        onUnderstood: (value) => setState(() => _understood = value),
                        onSubmitted: () => _delete(data.deletion),
                      ),
                  },
                ),
              ),
            ),
          ),
          if (_deleting) const _DeletingOverlay(),
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
              onPressed: deletion.canDelete ? () => _goTo(1) : null,
            ),
            TextButton(
              onPressed: () => context.pop(),
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
        final reauth = _reauthFor(data.verification);
        return ListenableBuilder(
          listenable: Listenable.merge([reauth, _phrase]),
          builder: (context, _) => ZButton(
            label: 'Delete my account',
            icon: Icons.delete_forever_rounded,
            variant: ZButtonVariant.danger,
            isLoading: _deleting,
            onPressed: data.verification.canVerify &&
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
    required this.notice,
    required this.onOpenBlocker,
    required this.onRecheck,
  });

  final DeletionPreflight deletion;
  final String? notice;
  final ValueChanged<DeletionBlocker> onOpenBlocker;
  final VoidCallback onRecheck;

  @override
  Widget build(BuildContext context) {
    final summary = deletion.retainedSummary;

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
        if (deletion.blockers.isNotEmpty) ...[
          const PrivacySectionTitle('Settle these first'),
          for (final blocker in deletion.blockers) ...[
            _BlockerCard(blocker: blocker, onAction: () => onOpenBlocker(blocker)),
            const SizedBox(height: AppSpacing.xs),
          ],
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: onRecheck,
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('Check again'),
            ),
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
                : 'From your account, that\'s $summary, kept without your name.',
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
        if (error != null) ...[
          const SizedBox(height: AppSpacing.xs),
          ZInfoBanner(message: error!, tone: StatusTone.error),
        ],
      ],
    );
  }
}

/// Covers the screen while the deletion runs, so nothing can be tapped and
/// the user can see it is happening.
class _DeletingOverlay extends StatelessWidget {
  const _DeletingOverlay();

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
                        'Deleting your account…',
                        style: AppTypography.titleMedium,
                        semanticsLabel: 'Deleting your account, please wait',
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        'This takes a few seconds. Please keep the app open.',
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
