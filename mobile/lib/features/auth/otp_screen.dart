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

/// Mobile OTP sign-in — the second authentication path in the registration
/// workflow (`Zuste_Workflow.md` § 2).
///
/// Two steps on one screen: the number, then the code. Problems are reported
/// on the field they concern rather than in a toast that disappears while the
/// user is still reading the SMS.
class OtpScreen extends ConsumerStatefulWidget {
  const OtpScreen({super.key});

  @override
  ConsumerState<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends ConsumerState<OtpScreen> {
  static const _codeLength = 6;

  final _phone = TextEditingController();
  final _code = TextEditingController();

  bool _codeSent = false;
  bool _busy = false;
  int _resendIn = 0;
  Timer? _ticker;

  String? _phoneError;
  String? _codeError;

  @override
  void dispose() {
    _ticker?.cancel();
    _phone.dispose();
    _code.dispose();
    super.dispose();
  }

  void _startResendCountdown() {
    _ticker?.cancel();
    setState(() => _resendIn = 30);
    _ticker = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return timer.cancel();
      setState(() => _resendIn--);
      if (_resendIn <= 0) timer.cancel();
    });
  }

  Future<void> _sendCode() async {
    final error = Validators.phone(_phone.text);
    if (error != null) {
      setState(() => _phoneError = error);
      return;
    }

    setState(() {
      _busy = true;
      _phoneError = null;
      _codeError = null;
    });
    try {
      await ref.read(authRepositoryProvider).sendOtp(_phone.text.trim());
      if (mounted) {
        setState(() => _codeSent = true);
        _startResendCountdown();
      }
    } on ApiFailure catch (e) {
      if (!mounted) return;
      // Before the code is sent the only thing that can be wrong is the
      // number; after, a failed resend belongs with the code.
      setState(() {
        if (_codeSent) {
          _codeError = e.message;
        } else {
          _phoneError =
              e.errorFor('destination') ?? e.errorFor('phone') ?? e.message;
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    if (_busy) return;

    final error = Validators.otp(_code.text, length: _codeLength);
    if (error != null) {
      setState(() => _codeError = error);
      return;
    }

    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _codeError = null;
    });
    try {
      await ref
          .read(authProvider.notifier)
          .verifyOtp(phone: _phone.text.trim(), code: _code.text.trim());
      if (mounted) context.go(Routes.home);
    } on ApiFailure catch (e) {
      if (!mounted) return;
      setState(() => _codeError = e.errorFor('code') ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _changeNumber() => setState(() {
    _codeSent = false;
    _code.clear();
    _codeError = null;
  });

  @override
  Widget build(BuildContext context) {
    final duration = Motion.of(context, Motion.medium);

    return PopScope(
      // System back on the code step returns to the number, like the chevron.
      canPop: !_codeSent,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _changeNumber();
      },
      child: AuthScaffold(
        onBack: () {
          if (_codeSent) return _changeNumber();
          context.canPop() ? context.pop() : context.go(Routes.login);
        },
        bottom: ZButton(
          label: _codeSent ? 'Verify and continue' : 'Send code',
          onPressed: _codeSent ? _verify : _sendCode,
          isLoading: _busy,
          trailingIcon: Icons.arrow_forward_rounded,
        ),
        child: AnimatedSwitcher(
          duration: duration,
          switchInCurve: Motion.emphasized,
          switchOutCurve: Motion.exit,
          child: _codeSent ? _buildCodeStep() : _buildPhoneStep(),
        ),
      ),
    );
  }

  Widget _buildPhoneStep() {
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
          controller: _phone,
          keyboardType: TextInputType.phone,
          prefixIcon: Icons.phone_iphone_rounded,
          maxLength: 10,
          autofocus: true,
          textInputAction: TextInputAction.send,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          helper: 'Indian numbers only (+91). Standard SMS rates may apply.',
          errorText: _phoneError,
          onChanged: (_) {
            if (_phoneError != null) setState(() => _phoneError = null);
          },
          onSubmitted: (_) => _sendCode(),
        ),
      ],
    );
  }

  Widget _buildCodeStep() {
    final phone = _phone.text.trim();

    return Column(
      key: const ValueKey('code'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const AuthHeader(
          badge: Icons.mark_chat_read_outlined,
          title: 'Enter the code',
        ),
        const SizedBox(height: AppSpacing.xs),
        // The number with an inline way to fix it, so a typo is one tap away.
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              'Sent to +91 $phone',
              style: AppTypography.bodyLarge.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
            TextButton(
              onPressed: _busy ? null : _changeNumber,
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
        const SizedBox(height: AppSpacing.lg),
        OtpCodeField(
          controller: _code,
          length: _codeLength,
          autofocus: true,
          enabled: !_busy,
          errorText: _codeError,
          onChanged: (_) {
            if (_codeError != null) setState(() => _codeError = null);
          },
          // Every digit is in: verify straight away rather than making the
          // user find the button under the keyboard.
          onCompleted: (_) => _verify(),
        ),
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
              onPressed: (_resendIn > 0 || _busy) ? null : _sendCode,
              style: TextButton.styleFrom(
                minimumSize: const Size(
                  AppSpacing.minTouch,
                  AppSpacing.minTouch,
                ),
              ),
              child: Text(
                _resendIn > 0
                    ? 'Resend in 0:${_resendIn.toString().padLeft(2, '0')}'
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
