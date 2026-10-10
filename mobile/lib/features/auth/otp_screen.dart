import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/motion/motion.dart';
import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/validators.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';
import '../../router/routes.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_text_field.dart';
import 'widgets/auth_scaffold.dart';
import 'widgets/otp_code_field.dart';

/// One-time code sign-in for an existing account.
///
/// Two steps on one screen: where to send the code, then the code. Where it
/// goes is the server's call (`GET /app/config` → `auth` → `mobile_otp` →
/// `channels`): the account's **email address** by default, and a mobile
/// number only when the operator offers codes by SMS alone. Problems are
/// reported on the field they concern rather than in a toast that disappears
/// while the user is still reading the message.
///
/// A code never creates an account: an address with none is told so after
/// the code checks out, with a way to sign up.
class OtpScreen extends ConsumerStatefulWidget {
  const OtpScreen({super.key});

  @override
  ConsumerState<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends ConsumerState<OtpScreen> {
  final _destination = TextEditingController();
  final _code = TextEditingController();

  bool _codeSent = false;
  bool _busy = false;
  int _resendIn = 0;
  Timer? _ticker;

  /// The channel the current code went out on, fixed at send time so a config
  /// refresh mid-flow cannot change what the code step says.
  String? _sentChannel;

  /// The code checked out but no account uses the address.
  bool _noAccount = false;

  String? _destinationError;
  String? _codeError;

  @override
  void dispose() {
    _ticker?.cancel();
    _destination.dispose();
    _code.dispose();
    super.dispose();
  }

  void _startResendCountdown(int seconds) {
    _ticker?.cancel();
    setState(() => _resendIn = seconds);
    _ticker = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return timer.cancel();
      setState(() => _resendIn--);
      if (_resendIn <= 0) timer.cancel();
    });
  }

  Future<void> _sendCode(String channel, int resendFallback) async {
    final byEmail = channel == 'email';
    final error = byEmail
        ? Validators.email(_destination.text)
        : Validators.phone(_destination.text);
    if (error != null) {
      setState(() => _destinationError = error);
      return;
    }

    setState(() {
      _busy = true;
      _destinationError = null;
      _codeError = null;
      _noAccount = false;
    });
    try {
      final resendIn = await ref
          .read(authRepositoryProvider)
          .sendOtp(_destination.text.trim(), channel: channel);
      if (mounted) {
        setState(() {
          _codeSent = true;
          _sentChannel = channel;
        });
        _startResendCountdown(
          resendIn != null && resendIn > 0 ? resendIn : resendFallback,
        );
      }
    } on ApiFailure catch (e) {
      if (!mounted) return;
      // Before the code is sent the only thing that can be wrong is the
      // address; after, a failed resend belongs with the code.
      setState(() {
        if (_codeSent) {
          _codeError = e.message;
        } else {
          _destinationError = e.errorFor('destination') ??
              e.errorFor(byEmail ? 'email' : 'phone') ??
              e.message;
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify(int codeLength) async {
    if (_busy) return;

    final error = Validators.otp(_code.text, length: codeLength);
    if (error != null) {
      setState(() => _codeError = error);
      return;
    }

    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _codeError = null;
      _noAccount = false;
    });
    try {
      await ref
          .read(authProvider.notifier)
          .verifyOtp(destination: _destination.text.trim(), code: _code.text.trim());
      if (mounted) context.go(Routes.home);
    } on ApiFailure catch (e) {
      if (!mounted) return;
      setState(() {
        _codeError = e.errorFor('code') ?? e.message;
        _noAccount = e.code == 'account_not_found';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _changeDestination() => setState(() {
    _codeSent = false;
    _sentChannel = null;
    _code.clear();
    _codeError = null;
    _noAccount = false;
  });

  @override
  Widget build(BuildContext context) {
    final duration = Motion.of(context, Motion.medium);
    final config = ref.watch(authConfigProvider);
    final method = config.method('mobile_otp');
    final channel = _sentChannel ?? config.otpChannel;
    final codeLength = method?.integer('otp_length', 6) ?? 6;
    final resendFallback = method?.integer('resend_after_seconds', 30) ?? 30;

    return PopScope(
      // System back on the code step returns to the first step, like the
      // chevron.
      canPop: !_codeSent,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _changeDestination();
      },
      child: AuthScaffold(
        onBack: () {
          if (_codeSent) return _changeDestination();
          context.canPop() ? context.pop() : context.go(Routes.login);
        },
        bottom: ZButton(
          label: _codeSent ? 'Verify and continue' : 'Send code',
          onPressed: _codeSent
              ? () => _verify(codeLength)
              : () => _sendCode(channel, resendFallback),
          isLoading: _busy,
          trailingIcon: Icons.arrow_forward_rounded,
        ),
        child: AnimatedSwitcher(
          duration: duration,
          switchInCurve: Motion.emphasized,
          switchOutCurve: Motion.exit,
          child: _codeSent
              ? _buildCodeStep(channel, codeLength, resendFallback)
              : channel == 'email'
                  ? _buildEmailStep(codeLength, resendFallback)
                  : _buildPhoneStep(resendFallback),
        ),
      ),
    );
  }

  Widget _buildEmailStep(int codeLength, int resendFallback) {
    return Column(
      key: const ValueKey('email'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AuthHeader(
          badge: Icons.mail_outline_rounded,
          title: 'Log in with a code',
          subtitle:
              "No password needed. We'll email you a $codeLength-digit code "
              'to log in with.',
        ),
        const SizedBox(height: AppSpacing.xl),
        ZTextField(
          label: 'Email address',
          hint: 'you@brand.com',
          controller: _destination,
          keyboardType: TextInputType.emailAddress,
          prefixIcon: Icons.alternate_email_rounded,
          autofocus: true,
          textInputAction: TextInputAction.send,
          helper: 'The email address on your Zusty account.',
          errorText: _destinationError,
          onChanged: (_) {
            if (_destinationError != null) {
              setState(() => _destinationError = null);
            }
          },
          onSubmitted: (_) => _sendCode('email', resendFallback),
        ),
        const SizedBox(height: AppSpacing.lg),
        AuthFooterLink(
          prompt: 'New to Zusty?',
          action: 'Create an account',
          onTap: () => context.push(Routes.signUp),
        ),
      ],
    );
  }

  Widget _buildPhoneStep(int resendFallback) {
    return Column(
      key: const ValueKey('phone'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const AuthHeader(
          badge: Icons.sms_outlined,
          title: 'Sign in with OTP',
          subtitle:
              'No password needed. We will text a one-time code to '
              'your mobile number.',
        ),
        const SizedBox(height: AppSpacing.xl),
        ZTextField(
          label: 'Mobile number',
          hint: '98765 43210',
          controller: _destination,
          keyboardType: TextInputType.phone,
          prefixIcon: Icons.phone_iphone_rounded,
          maxLength: 10,
          autofocus: true,
          textInputAction: TextInputAction.send,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          helper: 'Indian numbers only (+91). Standard SMS rates may apply.',
          errorText: _destinationError,
          onChanged: (_) {
            if (_destinationError != null) {
              setState(() => _destinationError = null);
            }
          },
          onSubmitted: (_) => _sendCode('sms', resendFallback),
        ),
      ],
    );
  }

  Widget _buildCodeStep(String channel, int codeLength, int resendFallback) {
    final byEmail = channel == 'email';
    final destination = _destination.text.trim();

    return Column(
      key: const ValueKey('code'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AuthHeader(
          badge: byEmail
              ? Icons.mark_email_read_outlined
              : Icons.mark_chat_read_outlined,
          title: 'Enter the code',
        ),
        const SizedBox(height: AppSpacing.xs),
        // Where it went, with an inline way to fix it, so a typo is one tap
        // away.
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              byEmail ? 'Sent to $destination' : 'Sent to +91 $destination',
              style: AppTypography.bodyLarge.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
            TextButton(
              onPressed: _busy ? null : _changeDestination,
              style: TextButton.styleFrom(
                minimumSize: const Size(
                  AppSpacing.minTouch,
                  AppSpacing.minTouch,
                ),
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
              ),
              child: Text(
                'Change',
                style: AppTypography.labelLarge.copyWith(
                  color: AppColors.primary,
                ),
              ),
            ),
          ],
        ),
        if (byEmail) ...[
          Text(
            'Check your inbox, and your spam folder if it is not there.',
            style: AppTypography.bodyMedium.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.lg),
        OtpCodeField(
          controller: _code,
          length: codeLength,
          autofocus: true,
          enabled: !_busy,
          errorText: _codeError,
          onChanged: (_) {
            if (_codeError != null) setState(() => _codeError = null);
          },
          // Every digit is in: verify straight away rather than making the
          // user find the button under the keyboard.
          onCompleted: (_) => _verify(codeLength),
        ),
        if (_noAccount) ...[
          const SizedBox(height: AppSpacing.sm),
          AuthFooterLink(
            prompt: 'No account yet?',
            action: 'Create an account',
            onTap: () => context.push(Routes.signUp),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        Row(
          children: [
            Expanded(
              child: Text(
                "Didn't get it?",
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onSurfaceVariant,
                ),
              ),
            ),
            TextButton(
              onPressed: (_resendIn > 0 || _busy)
                  ? null
                  : () => _sendCode(channel, resendFallback),
              style: TextButton.styleFrom(
                minimumSize: const Size(
                  AppSpacing.minTouch,
                  AppSpacing.minTouch,
                ),
              ),
              child: Text(
                _resendIn > 0
                    ? 'Resend in ${_resendIn ~/ 60}:${(_resendIn % 60).toString().padLeft(2, '0')}'
                    : 'Resend code',
                style: AppTypography.labelLarge.copyWith(
                  color: _resendIn > 0 ? AppColors.outline : AppColors.primary,
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
}
