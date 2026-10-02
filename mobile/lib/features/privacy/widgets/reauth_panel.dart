import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../data/repositories/privacy_repository.dart';
import '../../../models/privacy.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_feedback.dart';
import '../../../shared/widgets/z_text_field.dart';

/// The state of one "confirm it's you" step: which proof is being given,
/// what has been typed, whether a code went out, and what went wrong.
///
/// A step-up check, not a sign-in: the session is already valid, and this
/// proves the person holding it is the owner right now. The server picks
/// where a code goes (the account's own email or phone), so all the app
/// chooses is the channel.
class ReauthController extends ChangeNotifier {
  ReauthController({
    required this.options,
    required this.purpose,
    required this.repository,
  })  : useCode = !options.password,
        channel = options.channels.isEmpty ? null : options.channels.first;

  final VerificationOptions options;

  /// [PrivacyPurpose.export] or [PrivacyPurpose.delete].
  final String purpose;
  final PrivacyRepository repository;

  final password = TextEditingController();
  final code = TextEditingController();

  bool useCode;
  CodeChannel? channel;
  SentCode? sent;
  bool sending = false;

  String? passwordError;
  String? codeError;
  String? sendError;

  DateTime? _resendAt;
  Timer? _ticker;

  /// What to send with the request, or null while it is incomplete.
  Reauth? get proof {
    if (useCode) {
      final value = code.text.trim();
      final selected = channel;
      if (sent == null || selected == null || value.length < 4) return null;
      return Reauth.code(value, channel: selected.channel);
    }

    return password.text.isEmpty ? null : Reauth.password(password.text);
  }

  bool get isComplete => proof != null;

  /// Seconds until another code may be sent.
  int get resendIn => _resendAt == null
      ? 0
      : math.max(0, _resendAt!.difference(DateTime.now()).inSeconds);

  void useCodeInstead(bool value) {
    useCode = value;
    passwordError = null;
    codeError = null;
    notifyListeners();
  }

  void selectChannel(CodeChannel value) {
    if (value.channel == channel?.channel) return;
    channel = value;
    sent = null;
    code.clear();
    codeError = null;
    sendError = null;
    notifyListeners();
  }

  /// Typing again clears the last complaint about that field.
  void edited() {
    passwordError = null;
    codeError = null;
    notifyListeners();
  }

  Future<void> sendCode() async {
    final selected = channel;
    if (selected == null || sending || resendIn > 0) return;

    sending = true;
    sendError = null;
    codeError = null;
    notifyListeners();

    try {
      sent = await repository.sendCode(purpose: purpose, channel: selected.channel);
      code.clear();
      _holdResend(const Duration(seconds: 30));
    } on ApiFailure catch (failure) {
      sendError = failure.message;
      if (failure is RateLimitFailure && failure.retryAfter != null) {
        _holdResend(failure.retryAfter!);
      }
    } finally {
      sending = false;
      notifyListeners();
    }
  }

  /// Puts a failed request's message beside the field it is about.
  ///
  /// Returns whether the failure was about the proof. If it was not, the
  /// caller shows it in its own place.
  bool applyFailure(ApiFailure failure) {
    if (!useCode) {
      final message = failure.errorFor('password');
      if (message != null ||
          failure.code == 'invalid_credentials' ||
          failure.code == 'password_not_set') {
        passwordError = message ?? failure.message;
        notifyListeners();
        return true;
      }
      return false;
    }

    // otp_invalid, otp_expired, otp_not_found, and the 429 that follows too
    // many wrong codes.
    if (failure.code.startsWith('otp_') ||
        failure.errorFor('code') != null ||
        failure is RateLimitFailure) {
      codeError = failure.errorFor('code') ?? failure.message;
      notifyListeners();
      return true;
    }

    return false;
  }

  void _holdResend(Duration wait) {
    _resendAt = DateTime.now().add(wait);
    _ticker?.cancel();
    _ticker = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (resendIn == 0) timer.cancel();
      notifyListeners();
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    password.dispose();
    code.dispose();
    super.dispose();
  }
}

/// The "confirm it's you" form: a password, or a one-time code by email or
/// SMS, whichever the account can use.
class ReauthPanel extends StatelessWidget {
  const ReauthPanel({super.key, required this.controller, this.onSubmitted});

  final ReauthController controller;

  /// Called when the keyboard's done key is pressed on a complete proof.
  final VoidCallback? onSubmitted;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final options = controller.options;

        if (!options.canVerify) {
          return ZInfoBanner(
            tone: StatusTone.warning,
            title: 'We can\'t confirm it\'s you here',
            message:
                'Your account has no password, email address or mobile number '
                'we can send a code to. Contact support and we\'ll help.',
            actionLabel: 'Contact support',
            onAction: () => context.push(Routes.newTicket),
          );
        }

        return AnimatedSize(
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOutCubic,
          alignment: Alignment.topCenter,
          child: controller.useCode
              ? _CodeForm(controller: controller, onSubmitted: onSubmitted)
              : _PasswordForm(controller: controller, onSubmitted: onSubmitted),
        );
      },
    );
  }
}

class _PasswordForm extends StatelessWidget {
  const _PasswordForm({required this.controller, this.onSubmitted});

  final ReauthController controller;
  final VoidCallback? onSubmitted;

  @override
  Widget build(BuildContext context) {
    return Column(
      key: const ValueKey('reauth-password'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        ZTextField(
          label: 'Your password',
          hint: 'Enter your current password',
          controller: controller.password,
          obscure: true,
          prefixIcon: Icons.lock_outline_rounded,
          textInputAction: TextInputAction.done,
          errorText: controller.passwordError,
          onChanged: (_) => controller.edited(),
          onSubmitted: (_) {
            if (controller.isComplete) onSubmitted?.call();
          },
        ),
        if (controller.options.channels.isNotEmpty)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => controller.useCodeInstead(true),
              icon: const Icon(Icons.sms_outlined, size: 18),
              label: const Text('Use a one-time code instead'),
            ),
          ),
      ],
    );
  }
}

class _CodeForm extends StatelessWidget {
  const _CodeForm({required this.controller, this.onSubmitted});

  final ReauthController controller;
  final VoidCallback? onSubmitted;

  @override
  Widget build(BuildContext context) {
    final channels = controller.options.channels;
    final selected = controller.channel;
    final sent = controller.sent;
    final wait = controller.resendIn;

    return Column(
      key: const ValueKey('reauth-code'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (channels.length > 1) ...[
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              for (final channel in channels)
                ChoiceChip(
                  label: Text(channel.label),
                  avatar: Icon(
                    channel.isSms ? Icons.sms_outlined : Icons.mail_outline_rounded,
                    size: 18,
                  ),
                  selected: channel.channel == selected?.channel,
                  onSelected: (_) => controller.selectChannel(channel),
                ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
        if (sent == null) ...[
          Text(
            selected == null
                ? 'Choose where to send your code.'
                : 'We\'ll send a one-time code to ${selected.destination}.',
            style: AppTypography.bodyMedium.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.sm),
          ZButton.outlined(
            label: 'Send code',
            icon: Icons.send_rounded,
            size: ZButtonSize.medium,
            isLoading: controller.sending,
            onPressed: selected == null || wait > 0 ? null : controller.sendCode,
          ),
        ] else ...[
          Text(
            'Enter the ${sent.length}-digit code we sent to ${sent.destination}. '
            'It expires in ${(sent.expiresIn.inSeconds / 60).ceil()} minutes.',
            style: AppTypography.bodyMedium.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.sm),
          ZTextField(
            label: 'Verification code',
            controller: controller.code,
            keyboardType: TextInputType.number,
            prefixIcon: Icons.pin_outlined,
            maxLength: sent.length,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            textInputAction: TextInputAction.done,
            errorText: controller.codeError,
            onChanged: (_) => controller.edited(),
            onSubmitted: (_) {
              if (controller.isComplete) onSubmitted?.call();
            },
          ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              onPressed: wait > 0 || controller.sending ? null : controller.sendCode,
              child: Text(
                wait > 0
                    ? 'Resend code in 0:${wait.toString().padLeft(2, '0')}'
                    : 'Resend code',
              ),
            ),
          ),
        ],
        if (controller.sendError != null) ...[
          const SizedBox(height: AppSpacing.xs),
          ZInfoBanner(message: controller.sendError!, tone: StatusTone.error),
        ],
        if (controller.options.password)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => controller.useCodeInstead(false),
              icon: const Icon(Icons.lock_outline_rounded, size: 18),
              label: const Text('Use my password instead'),
            ),
          ),
      ],
    );
  }
}
