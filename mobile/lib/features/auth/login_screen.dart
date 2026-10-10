import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/validators.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';
import '../../router/routes.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_text_field.dart';
import 'widgets/auth_scaffold.dart';
import 'widgets/social_buttons.dart';

/// Screen 04 — Welcome / Login.
///
/// One primary path (email or phone + password) and, under it, whichever
/// other ways in the operator has switched on in Admin → Authentication:
/// mobile OTP, social sign-in, guest browsing. Nothing below the password
/// field is shown speculatively.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifier = TextEditingController();
  final _password = TextEditingController();

  bool _submitting = false;
  String? _formError;

  /// Field errors from a 422, shown on the field they belong to.
  Map<String, List<String>> _fieldErrors = const {};

  @override
  void dispose() {
    _identifier.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() => _fieldErrors = const {});
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _submitting = true;
      _formError = null;
    });

    try {
      await ref
          .read(authProvider.notifier)
          .login(identifier: _identifier.text.trim(), password: _password.text);
      if (mounted) context.go(Routes.home);
    } on ValidationFailure catch (e) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = e.errors;
        // Anything the form has no field for still needs saying somewhere.
        _formError =
            (e.errorFor('identifier') == null && e.errorFor('password') == null)
            ? e.message
            : null;
      });
    } on ApiFailure catch (e) {
      if (mounted) setState(() => _formError = e.message);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  /// Starts a guest session, then lands on the home feed.
  ///
  /// Only reachable when the server says guest access is on — it refuses
  /// otherwise, so the button is never shown speculatively.
  Future<void> _continueAsGuest() async {
    setState(() {
      _submitting = true;
      _formError = null;
    });

    try {
      await ref.read(authProvider.notifier).continueAsGuest();
      if (mounted) context.go(Routes.home);
    } on ApiFailure catch (e) {
      if (mounted) setState(() => _formError = e.message);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    // Watched, not read: an admin change picked up on resume rebuilds this
    // screen with the new set of sign-in options.
    final authConfig = ref.watch(authConfigProvider);

    // Everything below the password field is driven by GET /app/config →
    // auth, so an operator switching a method off in the admin panel removes
    // it from installed apps on their next launch.
    final showForgot =
        authConfig
            .method('email_password')
            ?.flag('forgot_password_enabled', fallback: true) ??
        true;
    // Mobile OTP can be on only to verify numbers. A code signs someone in
    // only when the operator has also allowed OTP sign-in; otherwise the
    // server just confirms the code and no session follows.
    final showOtp =
        authConfig.isEnabled('mobile_otp') &&
        (authConfig.method('mobile_otp')?.flag('login_enabled') ?? false);
    final showGuest = authConfig.guest.enabled;

    return AuthScaffold(
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const AuthHeader(
              title: 'Welcome Back!',
              subtitle:
                  'Log in to pick up your collaborations where you '
                  'left off.',
            ),
            const SizedBox(height: AppSpacing.xl),
            ZTextField(
              label: 'Email or phone',
              hint: 'you@brand.com or 98765 43210',
              controller: _identifier,
              keyboardType: TextInputType.emailAddress,
              prefixIcon: Icons.person_outline_rounded,
              textInputAction: TextInputAction.next,
              validator: Validators.emailOrPhone,
              errorText: _fieldErrors['identifier']?.first,
              onChanged: (_) => _clearFieldError('identifier'),
            ),
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label: 'Password',
              hint: 'Your password',
              controller: _password,
              obscure: true,
              prefixIcon: Icons.lock_outline_rounded,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _submit(),
              validator: (value) =>
                  Validators.required(value, field: 'Password'),
              errorText: _fieldErrors['password']?.first,
              onChanged: (_) => _clearFieldError('password'),
            ),
            if (showForgot)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  onPressed: () => context.push(Routes.forgotPassword),
                  style: TextButton.styleFrom(
                    minimumSize: const Size(
                      AppSpacing.minTouch,
                      AppSpacing.minTouch,
                    ),
                  ),
                  child: Text(
                    'Forgot password?',
                    style: AppTypography.labelLarge.copyWith(
                      color: AppColors.primary,
                    ),
                  ),
                ),
              )
            else
              const SizedBox(height: AppSpacing.md),
            if (_formError != null) ...[
              AuthErrorBanner(message: _formError!),
              const SizedBox(height: AppSpacing.md),
            ],
            const SizedBox(height: AppSpacing.xs),
            ZButton(
              label: 'Log in',
              onPressed: _submit,
              isLoading: _submitting,
              trailingIcon: Icons.arrow_forward_rounded,
            ),
            if (showOtp) ...[
              const SizedBox(height: AppSpacing.sm),
              ZButton.outlined(
                label: authConfig.method('mobile_otp')?.label.isNotEmpty == true
                    ? authConfig.method('mobile_otp')!.label
                    : 'Continue with OTP',
                onPressed: _submitting ? null : () => context.push(Routes.otp),
                icon: Icons.sms_outlined,
              ),
            ],
            if (authConfig.showSocialDivider &&
                SocialLoginRow.hasProviders(authConfig)) ...[
              const SizedBox(height: AppSpacing.lg),
              OrDivider(label: authConfig.dividerLabel),
              const SizedBox(height: AppSpacing.lg),
              const SocialLoginRow(),
            ],
            if (showGuest) ...[
              const SizedBox(height: AppSpacing.sm),
              ZButton(
                label: authConfig.method('guest')?.label.isNotEmpty == true
                    ? authConfig.method('guest')!.label
                    : 'Continue as guest',
                variant: ZButtonVariant.text,
                onPressed: _submitting ? null : _continueAsGuest,
                icon: Icons.explore_outlined,
                size: ZButtonSize.medium,
              ),
            ],
            const SizedBox(height: AppSpacing.xl),
            AuthFooterLink(
              prompt: 'New to Zusty?',
              action: 'Create an account',
              onTap: () => context.push(Routes.signUp),
            ),
          ],
        ),
      ),
    );
  }

  void _clearFieldError(String field) {
    if (_fieldErrors.containsKey(field)) {
      setState(() => _fieldErrors = {..._fieldErrors}..remove(field));
    }
  }
}

/// Reused by the signup screen.
class FormErrorBanner extends StatelessWidget {
  const FormErrorBanner({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => AuthErrorBanner(message: message);
}

/// Forgot-password screen reached from the login form.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() =>
      _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifier = TextEditingController();
  bool _submitting = false;
  bool _sent = false;
  String? _error;

  @override
  void dispose() {
    _identifier.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await ref
          .read(authRepositoryProvider)
          .forgotPassword(_identifier.text.trim());
      if (mounted) setState(() => _sent = true);
    } on ApiFailure catch (e) {
      if (!mounted) return;
      // A field error belongs under the field; anything else above the
      // button, where the eye already is.
      setState(() => _error = e.errorFor('email') ?? e.message);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _back() => context.canPop() ? context.pop() : context.go(Routes.login);

  @override
  Widget build(BuildContext context) {
    if (_sent) {
      return AuthScaffold(
        onBack: _back,
        child: Padding(
          padding: const EdgeInsets.only(top: AppSpacing.xl),
          child: SuccessView(
            icon: Icons.mark_email_read_rounded,
            title: 'Check your inbox',
            message:
                'If ${_identifier.text.trim()} is linked to a Zusty '
                'account, a reset link is on its way. It expires in 30 '
                'minutes.',
            primaryLabel: 'Back to login',
            onPrimary: () => context.go(Routes.login),
            secondaryLabel: 'Use a different email',
            onSecondary: () => setState(() => _sent = false),
          ),
        ),
      );
    }

    return AuthScaffold(
      onBack: _back,
      bottom: ZButton(
        label: 'Send reset link',
        onPressed: _submit,
        isLoading: _submitting,
        trailingIcon: Icons.arrow_forward_rounded,
      ),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const AuthHeader(
              badge: Icons.lock_reset_rounded,
              title: 'Reset your password',
              // The reset endpoint takes an email only, so the form asks for
              // exactly that rather than accepting a phone number it would
              // then refuse.
              subtitle:
                  'Enter the email address on your Zusty account and '
                  'we will send you a link to choose a new password.',
            ),
            const SizedBox(height: AppSpacing.xl),
            ZTextField(
              label: 'Email address',
              hint: 'you@brand.com',
              controller: _identifier,
              prefixIcon: Icons.alternate_email_rounded,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.send,
              onSubmitted: (_) => _submit(),
              validator: Validators.email,
            ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.md),
              AuthErrorBanner(message: _error!),
            ],
            const SizedBox(height: AppSpacing.lg),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  Icons.shield_outlined,
                  size: 18,
                  color: AppColors.onSurfaceVariant,
                ),
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  child: Text(
                    'For your security we never say whether an account '
                    'exists. The link works once.',
                    style: AppTypography.bodySmall,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
