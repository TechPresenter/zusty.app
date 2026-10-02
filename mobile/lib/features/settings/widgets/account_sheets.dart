import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/utils/validators.dart';
import '../../../models/account_settings.dart';
import '../../../providers/account_provider.dart';
import '../../../providers/auth_provider.dart';
import '../../../providers/core_providers.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_feedback.dart';
import '../../../shared/widgets/z_text_field.dart';

/// "1.4.0 (37)" from the installed build, or null where the platform cannot
/// say (widget tests, an unsupported desktop target).
final appVersionProvider = FutureProvider<String?>((ref) async {
  try {
    final info = await PackageInfo.fromPlatform();
    if (info.version.isEmpty) return null;
    return info.buildNumber.isEmpty
        ? info.version
        : '${info.version} (${info.buildNumber})';
  } catch (_) {
    return null;
  }
});

/// Asks, then signs out of this device and returns to sign-in.
Future<void> confirmAndLogout(BuildContext context, WidgetRef ref) async {
  final confirmed = await showZConfirm(
    context,
    title: 'Log out?',
    message:
        'You will need to sign in again to see your bookings, messages and '
        'wallet.',
    confirmLabel: 'Log out',
    cancelLabel: 'Stay signed in',
    icon: Icons.logout_rounded,
  );
  if (!confirmed) return;

  await ref.read(authProvider.notifier).logout();
  if (context.mounted) context.go(Routes.login);
}

/// Change password, then sign in again.
///
/// The server revokes every session when the password changes — that is the
/// point of changing it after a scare — so this device is signed out too, and
/// the person is told so before it happens rather than finding out on their
/// next tap.
Future<void> showChangePassword(BuildContext context, WidgetRef ref) async {
  final changed = await showZSheet<bool>(
    context,
    title: 'Change password',
    subtitle: 'You will be signed out on every device, including this one.',
    builder: (_) => const _ChangePasswordForm(),
  );

  if (changed != true || !context.mounted) return;

  showZSnack(context, 'Password updated. Sign in with your new password.');
  await ref.read(authProvider.notifier).logout();
  if (context.mounted) context.go(Routes.login);
}

class _ChangePasswordForm extends ConsumerStatefulWidget {
  const _ChangePasswordForm();

  @override
  ConsumerState<_ChangePasswordForm> createState() =>
      _ChangePasswordFormState();
}

class _ChangePasswordFormState extends ConsumerState<_ChangePasswordForm> {
  final _form = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();

  bool _busy = false;
  String? _currentError;
  String? _nextError;
  String? _error;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _currentError = null;
      _nextError = null;
      _error = null;
    });
    if (!(_form.currentState?.validate() ?? false)) return;

    setState(() => _busy = true);
    try {
      await ref
          .read(authRepositoryProvider)
          .changePassword(current: _current.text, updated: _next.text);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiFailure catch (e) {
      if (!mounted) return;
      setState(() {
        // Put the server's words beside the field they are about.
        _currentError = e.code == 'invalid_current_password'
            ? e.message
            : e.errorFor('current_password');
        _nextError = e.errorFor('password');
        _error = _currentError == null && _nextError == null ? e.message : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _form,
      autovalidateMode: AutovalidateMode.onUserInteraction,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          ZTextField(
            label: 'Current password',
            controller: _current,
            obscure: true,
            prefixIcon: Icons.lock_outline_rounded,
            textInputAction: TextInputAction.next,
            helper:
                'Signed up with Google or Apple? Leave this blank to set '
                'your first password.',
            errorText: _currentError,
          ),
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'New password',
            controller: _next,
            obscure: true,
            prefixIcon: Icons.password_rounded,
            textInputAction: TextInputAction.next,
            helper: 'At least 8 characters, with a letter and a number.',
            validator: Validators.password,
            errorText: _nextError,
          ),
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Confirm new password',
            controller: _confirm,
            obscure: true,
            prefixIcon: Icons.password_rounded,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _submit(),
            validator: (value) =>
                Validators.required(value, field: 'Confirmation') ??
                Validators.confirmPassword(value, _next.text),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            ZInfoBanner(message: _error!, tone: StatusTone.error),
          ],
          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: 'Update password',
            isLoading: _busy,
            onPressed: _submit,
          ),
        ],
      ),
    );
  }
}

/// Devices signed in to the account, each with its own sign-out, and a way
/// to sign out everywhere at once.
Future<void> showSessions(BuildContext context, WidgetRef ref) {
  return showZSheet<void>(
    context,
    title: 'Active sessions',
    subtitle:
        'Devices signed in to your account, newest first. The one you '
        'are using now is among them.',
    builder: (_) => const _SessionsList(),
  );
}

class _SessionsList extends ConsumerStatefulWidget {
  const _SessionsList();

  @override
  ConsumerState<_SessionsList> createState() => _SessionsListState();
}

class _SessionsListState extends ConsumerState<_SessionsList> {
  final Set<int> _revoking = {};
  bool _signingOutAll = false;

  Future<void> _revoke(AccountSession session) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Sign out this device?',
      message:
          '${session.title} will need to sign in again. If it is the '
          'device you are holding, that includes you.',
      confirmLabel: 'Sign out device',
      destructive: true,
      icon: Icons.phonelink_erase_rounded,
    );
    if (!confirmed || !mounted) return;

    setState(() => _revoking.add(session.id));
    try {
      await ref.read(accountRepositoryProvider).revokeSession(session.id);
      ref.invalidate(accountSessionsProvider);
      if (mounted) showZSnack(context, 'Device signed out.');
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _revoking.remove(session.id));
    }
  }

  Future<void> _signOutEverywhere() async {
    final confirmed = await showZConfirm(
      context,
      title: 'Sign out of all devices?',
      message:
          'Every device, this one included, will need to sign in '
          'again. Use this if you think someone else has your password.',
      confirmLabel: 'Sign out everywhere',
      destructive: true,
      icon: Icons.devices_other_rounded,
    );
    if (!confirmed || !mounted) return;

    // Held before the awaits: once signed out, this sheet's context is gone
    // along with the screen that opened it.
    final router = GoRouter.of(context);
    final messenger = ScaffoldMessenger.of(context);

    setState(() => _signingOutAll = true);
    try {
      await ref.read(accountRepositoryProvider).logoutEverywhere();
    } on ApiFailure catch (e) {
      if (mounted) {
        setState(() => _signingOutAll = false);
        showZSnack(context, e.message, isError: true);
      }
      return;
    }

    // The server has revoked every token; clear this device to match.
    await ref.read(authProvider.notifier).logout();
    messenger.showSnackBar(
      const SnackBar(content: Text('Signed out of every device.')),
    );
    router.go(Routes.login);
  }

  @override
  Widget build(BuildContext context) {
    final sessions = ref.watch(accountSessionsProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        sessions.when(
          loading: () => const ListSkeleton(count: 3, height: 64),
          error: (error, _) => ErrorState(
            error: error,
            onRetry: () => ref.invalidate(accountSessionsProvider),
          ),
          data: (items) => items.isEmpty
              ? const EmptyState(
                  title: 'No other sessions',
                  message: 'Only this device is signed in.',
                  icon: Icons.devices_rounded,
                  compact: true,
                )
              : Container(
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLowest,
                    borderRadius: AppRadius.xlAll,
                    border: Border.all(color: AppColors.outlineVariant),
                  ),
                  child: Column(
                    children: [
                      for (var i = 0; i < items.length; i++) ...[
                        if (i > 0)
                          Divider(
                            height: 1,
                            indent: 64,
                            color: AppColors.outlineVariant,
                          ),
                        _SessionRow(
                          session: items[i],
                          busy: _revoking.contains(items[i].id),
                          onSignOut: () => _revoke(items[i]),
                        ),
                      ],
                    ],
                  ),
                ),
        ),
        const SizedBox(height: AppSpacing.lg),
        ZButton(
          label: 'Sign out of all devices',
          variant: ZButtonVariant.danger,
          icon: Icons.logout_rounded,
          isLoading: _signingOutAll,
          onPressed: _signOutEverywhere,
        ),
      ],
    );
  }
}

class _SessionRow extends StatelessWidget {
  const _SessionRow({
    required this.session,
    required this.busy,
    required this.onSignOut,
  });

  final AccountSession session;
  final bool busy;
  final VoidCallback onSignOut;

  @override
  Widget build(BuildContext context) {
    final icon = switch (session.platform) {
      'android' => Icons.phone_android_rounded,
      'ios' => Icons.phone_iphone_rounded,
      'web' => Icons.language_rounded,
      _ => Icons.devices_rounded,
    };

    final details = [
      if (session.lastActive != null)
        'Active ${Formatters.relative(session.lastActive)}',
      if (session.createdAt != null)
        'Signed in ${Formatters.date(session.createdAt)}',
      if (session.ipAddress != null && session.ipAddress!.isNotEmpty)
        session.ipAddress!,
    ].join(' · ');

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.sm,
        AppSpacing.xs,
        AppSpacing.sm,
      ),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: AppColors.surfaceContainerLow,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(icon, size: 20, color: AppColors.primary),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  session.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                if (details.isNotEmpty)
                  Text(
                    details,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodySmall,
                  ),
              ],
            ),
          ),
          busy
              ? const Padding(
                  padding: EdgeInsets.all(AppSpacing.sm),
                  child: SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                )
              : TextButton(
                  onPressed: onSignOut,
                  style: TextButton.styleFrom(
                    foregroundColor: AppColors.error,
                    minimumSize: const Size(0, AppSpacing.minTouch),
                  ),
                  child: const Text('Sign out'),
                ),
        ],
      ),
    );
  }
}
