import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/validators.dart';
import '../../models/contact.dart';
import '../../models/privacy.dart';
import '../../providers/core_providers.dart';
import '../../providers/privacy_provider.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import '../privacy/widgets/reauth_panel.dart';

/// Email and mobile: verify them, change them, add alternates.
///
/// The operator's policy travels with the data, so this screen enables and
/// disables its own controls from what the server allows rather than from
/// rules written here. Turning OTP off, locking mobile changes, or raising the
/// alternate limit all take effect without an app release.
///
/// The important property of the change flow: the live address does NOT move
/// until the new one is proved. A mistyped email can never lock anyone out.
class ContactsScreen extends ConsumerWidget {
  const ContactsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final book = ref.watch(contactBookProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Email & mobile'),
      body: book.when(
        loading: () => Padding(
          padding: EdgeInsets.all(margin),
          child: const ListSkeleton(count: 3, height: 120),
        ),
        error: (error, _) => ErrorState(
          error: error,
          onRetry: () => ref.read(contactBookProvider.notifier).refresh(),
        ),
        data: (data) => ZRefresh(
          onRefresh: () => ref.read(contactBookProvider.notifier).refresh(),
          child: ListView(
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.md,
              margin,
              AppSpacing.xxl,
            ),
            children: [
              if (data.policy.contactLocked) ...[
                const ZInfoBanner(
                  title: 'Contact details locked',
                  message:
                      'An administrator has locked your contact '
                      'details. Contact support if you need them changed.',
                  tone: StatusTone.error,
                  icon: Icons.lock_rounded,
                ),
                const SizedBox(height: AppSpacing.md),
              ],

              _ChannelBlock(
                book: data,
                channel: 'email',
                title: 'Email address',
              ),
              const SizedBox(height: AppSpacing.lg),
              _ChannelBlock(
                book: data,
                channel: 'phone',
                title: 'Mobile number',
              ),

              if (!data.policy.otpVerification) ...[
                const SizedBox(height: AppSpacing.lg),
                Text(
                  'Verification codes are currently switched off, so a change '
                  'takes effect immediately.',
                  style: AppTypography.bodySmall,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// One channel: the primary value, any pending change, and the alternates.
class _ChannelBlock extends ConsumerWidget {
  const _ChannelBlock({
    required this.book,
    required this.channel,
    required this.title,
  });

  final ContactBook book;
  final String channel;
  final String title;

  bool get isEmail => channel == 'email';

  /// The verification options for a change are on their way.
  static bool _loadingProofOptions = false;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final point = isEmail ? book.email : book.phone;
    final pending = book.pendingFor(channel);
    final alternates = book.alternatesFor(channel);
    final policy = book.policy;

    final canAddMore =
        policy.allowAlternateContacts &&
        !policy.contactLocked &&
        alternates.length < policy.maxAlternatesPerChannel;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(
            left: AppSpacing.base,
            bottom: AppSpacing.xs,
          ),
          child: Semantics(
            header: true,
            child: Text(title, style: AppTypography.titleMedium),
          ),
        ),

        ZCard(
          shadow: false,
          padding: EdgeInsets.zero,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: _PointRow(
                  point: point,
                  isPrimary: true,
                  isEmail: isEmail,
                ),
              ),

              // A change in flight replaces the "change" button — offering it
              // again would silently supersede the request the person is
              // part-way through.
              if (pending != null) ...[
                Divider(height: 1, color: AppColors.outlineVariant),
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: _PendingChange(request: pending),
                ),
              ] else if (policy.canChange(channel))
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.md,
                    0,
                    AppSpacing.md,
                    AppSpacing.md,
                  ),
                  child: ZButton.tonal(
                    label: point.isSet
                        ? 'Change ${isEmail ? 'email' : 'number'}'
                        : 'Add ${isEmail ? 'email' : 'number'}',
                    icon: point.isSet ? Icons.edit_outlined : Icons.add_rounded,
                    size: ZButtonSize.small,
                    onPressed: () => _startChange(context, ref),
                  ),
                ),

              // Alternates sit in the same card, under a hairline, so the
              // channel reads as one group.
              for (final alternate in alternates) ...[
                Divider(height: 1, color: AppColors.outlineVariant),
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.md,
                    AppSpacing.xs,
                    AppSpacing.xs,
                    AppSpacing.xs,
                  ),
                  child: _PointRow(
                    point: alternate,
                    isPrimary: false,
                    isEmail: isEmail,
                  ),
                ),
              ],
            ],
          ),
        ),

        if (canAddMore) ...[
          const SizedBox(height: AppSpacing.xs),
          ZButton(
            label: 'Add another ${isEmail ? 'email' : 'number'}',
            variant: ZButtonVariant.text,
            icon: Icons.add_rounded,
            size: ZButtonSize.small,
            expand: false,
            onPressed: () => _addAlternate(context, ref),
          ),
        ],
      ],
    );
  }

  Future<void> _startChange(BuildContext context, WidgetRef ref) async {
    final value = await _promptForValue(
      context,
      title: 'New ${isEmail ? 'email address' : 'mobile number'}',
      subtitle: book.policy.otpVerification
          ? 'We will send a code to confirm it. Your current '
                '${isEmail ? 'address' : 'number'} stays in place until then.'
          : null,
      isEmail: isEmail,
    );

    if (value == null || !context.mounted) return;

    final success = book.policy.otpVerification ? 'Code sent.' : 'Updated.';

    // A second change started while the options load would race this one.
    if (_loadingProofOptions) return;

    // The primary address is where security codes and notices go, so the
    // owner confirms it is them before it can change.
    final VerificationOptions options;
    _loadingProofOptions = true;
    try {
      options = (await ref.read(privacyRepositoryProvider).overview()).verification;
    } on ApiFailure catch (failure) {
      if (context.mounted) showZSnack(context, failure.message, isError: true);
      return;
    } finally {
      _loadingProofOptions = false;
    }

    if (!context.mounted) return;

    if (!options.canVerify) {
      // Nothing to confirm with: the server still holds the new address
      // until it is proved, and tells the old one about the change.
      await _run(context, ref, () async {
        final book = await ref
            .read(contactRepositoryProvider)
            .requestChange(channel: channel, value: value);
        ref.read(contactBookProvider.notifier).set(book);
      }, success: success);
      return;
    }

    final updated = await showZSheet<ContactBook>(
      context,
      title: 'Confirm it\'s you',
      subtitle:
          'For your security, confirm your identity before we change your '
          '${isEmail ? 'email address' : 'mobile number'}.',
      builder: (_) => _ConfirmChangeSheet(
        options: options,
        channel: channel,
        value: value,
      ),
    );

    if (updated == null || !context.mounted) return;
    ref.read(contactBookProvider.notifier).set(updated);
    showZSnack(context, success);
  }

  Future<void> _addAlternate(BuildContext context, WidgetRef ref) async {
    final value = await _promptForValue(
      context,
      title: 'Additional ${isEmail ? 'email address' : 'mobile number'}',
      subtitle:
          'A backup way to reach you. Your primary '
          '${isEmail ? 'address' : 'number'} does not change.',
      isEmail: isEmail,
    );

    if (value == null || !context.mounted) return;

    await _run(context, ref, () async {
      final book = await ref
          .read(contactRepositoryProvider)
          .addAlternate(channel: channel, value: value);
      ref.read(contactBookProvider.notifier).set(book);
    }, success: 'Added.');
  }
}

/// One address or number, with its verification state.
class _PointRow extends ConsumerWidget {
  const _PointRow({
    required this.point,
    required this.isPrimary,
    required this.isEmail,
  });

  final ContactPoint point;
  final bool isPrimary;
  final bool isEmail;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Row(
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerLow,
            borderRadius: AppRadius.mdAll,
          ),
          child: Icon(
            isEmail ? Icons.mail_outline_rounded : Icons.phone_iphone_rounded,
            size: 20,
            color: AppColors.primary,
          ),
        ),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                point.shown,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.titleSmall,
              ),
              const SizedBox(height: 4),
              Wrap(
                spacing: 6,
                runSpacing: 4,
                children: [
                  if (point.isSet)
                    StatusChip(
                      label: point.verified ? 'Verified' : 'Not verified',
                      tone: point.verified
                          ? StatusTone.success
                          : StatusTone.warning,
                      icon: point.verified
                          ? Icons.verified_rounded
                          : Icons.error_outline_rounded,
                      dense: true,
                    ),
                  if (isPrimary && point.isSet)
                    const StatusChip(
                      label: 'Primary',
                      tone: StatusTone.neutral,
                      dense: true,
                    ),
                ],
              ),
            ],
          ),
        ),
        if (!isPrimary) ...[
          if (!point.verified)
            TextButton(
              onPressed: () => _resend(context, ref),
              style: TextButton.styleFrom(
                minimumSize: const Size(0, AppSpacing.minTouch),
              ),
              child: const Text('Verify'),
            ),
          IconButton(
            icon: const Icon(Icons.delete_outline_rounded, size: 20),
            tooltip: 'Remove',
            color: AppColors.onSurfaceVariant,
            onPressed: () => _remove(context, ref),
          ),
        ],
      ],
    );
  }

  Future<void> _resend(BuildContext context, WidgetRef ref) async {
    final id = point.id;
    if (id == null) return;

    await _run(context, ref, () async {
      final book = await ref
          .read(contactRepositoryProvider)
          .reverifyAlternate(id);
      ref.read(contactBookProvider.notifier).set(book);
    }, success: 'Verification sent.');
  }

  Future<void> _remove(BuildContext context, WidgetRef ref) async {
    final id = point.id;
    if (id == null) return;

    final confirmed = await showZConfirm(
      context,
      title: 'Remove ${point.shown}?',
      message:
          'It will no longer be used to reach you or to recover your '
          'account.',
      confirmLabel: 'Remove',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );
    if (!confirmed || !context.mounted) return;

    await _run(context, ref, () async {
      final book = await ref
          .read(contactRepositoryProvider)
          .removeAlternate(id);
      ref.read(contactBookProvider.notifier).set(book);
    }, success: 'Removed.');
  }
}

/// A change waiting on its code.
class _PendingChange extends ConsumerStatefulWidget {
  const _PendingChange({required this.request});

  final ContactChangeRequest request;

  @override
  ConsumerState<_PendingChange> createState() => _PendingChangeState();
}

class _PendingChangeState extends ConsumerState<_PendingChange> {
  final _code = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final request = widget.request;
    final noun = request.channel == 'email' ? 'address' : 'number';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              Icons.mark_email_unread_outlined,
              size: 20,
              color: AppColors.primary,
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Confirm ${request.newValue}',
                    style: AppTypography.titleSmall,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    // The point of the whole flow, said plainly: nothing has
                    // changed yet.
                    'Enter the code we sent there. Your current $noun stays '
                    'in place until this is confirmed.',
                    style: AppTypography.bodySmall,
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        ZTextField(
          label: 'Verification code',
          hint: '6-digit code',
          controller: _code,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          maxLength: 8,
          prefixIcon: Icons.pin_outlined,
          textInputAction: TextInputAction.done,
          helper: request.attemptsLeft > 0
              ? '${request.attemptsLeft} attempt'
                    '${request.attemptsLeft == 1 ? '' : 's'} left'
              : null,
          // Rebuild so Confirm enables the moment a code is typed.
          onChanged: (_) => setState(() {}),
          onSubmitted: (_) {
            if (_code.text.trim().isNotEmpty) _confirm();
          },
        ),
        const SizedBox(height: AppSpacing.sm),
        ZButton(
          label: 'Confirm',
          size: ZButtonSize.medium,
          isLoading: _busy,
          onPressed: _code.text.trim().isEmpty ? null : _confirm,
        ),
        const SizedBox(height: AppSpacing.xs),
        Row(
          children: [
            Expanded(
              child: ZButton(
                label: 'Resend code',
                variant: ZButtonVariant.text,
                size: ZButtonSize.small,
                onPressed: _busy ? null : _resend,
              ),
            ),
            Expanded(
              child: ZButton(
                label: 'Cancel change',
                variant: ZButtonVariant.text,
                size: ZButtonSize.small,
                onPressed: _busy ? null : _cancel,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Future<void> _guard(Future<void> Function() action) async {
    setState(() => _busy = true);
    try {
      await action();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _confirm() => _guard(
    () => _run(context, ref, () async {
      final book = await ref
          .read(contactRepositoryProvider)
          .confirmChange(widget.request.id, _code.text.trim());
      ref.read(contactBookProvider.notifier).set(book);
    }, success: 'Confirmed. Your details are updated.'),
  );

  Future<void> _resend() => _guard(
    () => _run(context, ref, () async {
      final book = await ref
          .read(contactRepositoryProvider)
          .resendCode(widget.request.id);
      ref.read(contactBookProvider.notifier).set(book);
    }, success: 'A new code is on its way.'),
  );

  Future<void> _cancel() => _guard(
    () => _run(context, ref, () async {
      final book = await ref
          .read(contactRepositoryProvider)
          .cancelChange(widget.request.id);
      ref.read(contactBookProvider.notifier).set(book);
    }, success: 'Change cancelled.'),
  );
}

/// "Confirm it's you" before a primary email or number changes: the owner's
/// password, a code to the account's current email or phone, or a fresh
/// Google or Apple sign-in. The change is requested from here, so a wrong
/// password or code lands beside its field and the sheet stays open.
class _ConfirmChangeSheet extends ConsumerStatefulWidget {
  const _ConfirmChangeSheet({
    required this.options,
    required this.channel,
    required this.value,
  });

  final VerificationOptions options;

  /// `email` or `phone`.
  final String channel;

  /// The new address or number.
  final String value;

  @override
  ConsumerState<_ConfirmChangeSheet> createState() => _ConfirmChangeSheetState();
}

class _ConfirmChangeSheetState extends ConsumerState<_ConfirmChangeSheet> {
  late final ReauthController _reauth = ReauthController(
    options: widget.options,
    purpose: PrivacyPurpose.contactChange,
    repository: ref.read(privacyRepositoryProvider),
    firebaseToken: ref.read(firebaseTokenSourceProvider),
  );

  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reauth.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final proof = _reauth.proof;
    if (proof == null || _busy) return;

    FocusManager.instance.primaryFocus?.unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final book = await ref.read(contactRepositoryProvider).requestChange(
            channel: widget.channel,
            value: widget.value,
            proof: proof,
          );
      if (mounted) Navigator.of(context).pop(book);
    } on ApiFailure catch (failure) {
      if (!mounted) return;
      if (!_reauth.applyFailure(failure)) setState(() => _error = failure.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !_busy,
      child: ListenableBuilder(
        listenable: _reauth,
        builder: (context, _) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            IgnorePointer(
              ignoring: _busy,
              child: ReauthPanel(controller: _reauth, onSubmitted: _submit),
            ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              ZInfoBanner(message: _error!, tone: StatusTone.error),
            ],
            const SizedBox(height: AppSpacing.lg),
            ZButton(
              label: 'Continue',
              isLoading: _busy,
              onPressed: _reauth.canVerify && _reauth.isComplete ? _submit : null,
            ),
          ],
        ),
      ),
    );
  }
}

/// Ask for an address or number, in a sheet with inline validation.
Future<String?> _promptForValue(
  BuildContext context, {
  required String title,
  required bool isEmail,
  String? subtitle,
}) {
  return showZSheet<String>(
    context,
    title: title,
    subtitle: subtitle,
    builder: (_) => _ValueForm(isEmail: isEmail),
  );
}

class _ValueForm extends StatefulWidget {
  const _ValueForm({required this.isEmail});

  final bool isEmail;

  @override
  State<_ValueForm> createState() => _ValueFormState();
}

class _ValueFormState extends State<_ValueForm> {
  final _controller = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _submitted = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    setState(() => _submitted = true);
    if (_formKey.currentState?.validate() ?? false) {
      Navigator.of(context).pop(_controller.text.trim());
    }
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _formKey,
      autovalidateMode: _submitted
          ? AutovalidateMode.onUserInteraction
          : AutovalidateMode.disabled,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          ZTextField(
            controller: _controller,
            autofocus: true,
            hint: widget.isEmail ? 'you@example.com' : '9876543210',
            prefixIcon: widget.isEmail
                ? Icons.mail_outline_rounded
                : Icons.phone_iphone_rounded,
            keyboardType: widget.isEmail
                ? TextInputType.emailAddress
                : TextInputType.phone,
            inputFormatters: widget.isEmail
                ? null
                : [FilteringTextInputFormatter.digitsOnly],
            maxLength: widget.isEmail ? null : 10,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _submit(),
            validator: widget.isEmail ? Validators.email : Validators.phone,
          ),
          const SizedBox(height: AppSpacing.lg),
          ZButton(label: 'Continue', onPressed: _submit),
        ],
      ),
    );
  }
}

/// Run a mutation, surfacing whatever the server said.
///
/// The server's message is shown verbatim rather than replaced with something
/// generic: it is the only thing that can explain *why* a change was refused —
/// a locked section, an address already in use, a proof already in progress —
/// and each of those needs a different response from the person reading it.
Future<void> _run(
  BuildContext context,
  WidgetRef ref,
  Future<void> Function() action, {
  String success = 'Done.',
}) async {
  try {
    await action();
    if (context.mounted) showZSnack(context, success);
  } catch (error) {
    if (context.mounted) {
      showZSnack(
        context,
        error is ApiFailure ? error.message : '$error',
        isError: true,
      );
    }
  }
}
