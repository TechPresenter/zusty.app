import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/auth/social_sign_in_service.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../data/repositories/privacy_repository.dart';
import '../../../models/privacy.dart';
import '../../../providers/core_providers.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_feedback.dart';
import '../../../shared/widgets/z_text_field.dart';

/// Signs in with Google or Apple again and returns the fresh Firebase ID
/// token, sent as [Reauth.firebase]. [provider] is `google.com` or
/// `apple.com`.
typedef FirebaseTokenSource = Future<String> Function(String provider);

/// The token source the screens pass: a fresh sign-in through the app's own
/// Google and Apple sign-in, with the configuration the server serves at
/// the moment of the tap. A provider, so tests can stand in for the
/// platform's sign-in sheets.
final firebaseTokenSourceProvider = Provider<FirebaseTokenSource>((ref) {
  return (provider) {
    final config = ref.read(authConfigProvider);
    const service = SocialSignInService();
    return provider == 'apple.com' ? service.apple(config) : service.google(config);
  };
});

/// "Google" for `google.com`, "Apple" for `apple.com`.
String providerLabel(String provider) => switch (provider) {
      'apple.com' => 'Apple',
      'google.com' => 'Google',
      _ => provider,
    };

/// Why this account cannot confirm it is its owner right now, for the
/// blocked banner.
///
/// Built from what the server says is unavailable and why, so a phone-only
/// account with text messages switched off is told exactly that, never that
/// it has no mobile number, and an address changed a moment ago says when it
/// can take codes again.
String reauthBlockedMessage(VerificationOptions options) {
  final reasons = <String>[];
  var hasEmail = false;
  var hasPhone = false;

  for (final item in options.unavailable) {
    if (item.isSms) {
      hasPhone = true;
    } else {
      hasEmail = true;
    }

    if (item.recentlyChanged) {
      final what = item.isSms ? 'mobile number' : 'email address';
      final until = item.availableAt == null
          ? 'for a few days'
          : 'until ${Formatters.dateTime(item.availableAt)}';
      reasons.add('Your $what changed recently, so for your security it can\'t take codes $until.');
    } else if (item.reason == 'sms_unavailable') {
      reasons.add('We can\'t text you a code right now.');
    } else {
      reasons.add('We can\'t send a code to ${item.destination} right now.');
    }
  }

  if (reasons.isEmpty) {
    return 'Your account has no password, email address or mobile number '
        'we can send a code to. Contact support and we\'ll help.';
  }

  final missing = ['password', if (!hasEmail) 'email address', if (!hasPhone) 'mobile number'];
  final list = missing.length == 1
      ? missing.single
      : '${missing.sublist(0, missing.length - 1).join(', ')} or ${missing.last}';

  return '${reasons.join(' ')} Your account has no $list to confirm with '
      'instead. Contact support and we\'ll help.';
}

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
    this.firebaseToken,
  })  : useCode = !_offersPassword(options, purpose),
        channel = options.channels.isEmpty ? null : options.channels.first;

  /// Adding a first password must be proved some other way: the password
  /// being set proves nothing.
  static bool _offersPassword(VerificationOptions options, String purpose) =>
      options.password && purpose != PrivacyPurpose.setPassword;

  /// Server codes about a Google or Apple confirmation.
  static const _firebaseCodes = {
    'invalid_firebase_token',
    'reauth_mismatch',
    'reauth_stale',
    'reauth_reused',
    'reauth_method_unavailable',
  };

  /// Code failures that use the code up: only a new one can follow.
  static const _codeGone = {
    'otp_expired',
    'otp_not_found',
    'otp_attempts_exceeded',
    'otp_used',
  };

  /// Send failures after which the last code sent may not work either.
  static const _codeMayNotWork = {
    'reauth_locked',
    'contact_recently_changed',
    'sms_unavailable',
    'no_email',
    'no_phone',
    'invalid_channel',
  };

  final VerificationOptions options;

  /// [PrivacyPurpose.export], [PrivacyPurpose.delete],
  /// [PrivacyPurpose.setPassword] or [PrivacyPurpose.contactChange].
  final String purpose;
  final PrivacyRepository repository;

  /// Where a fresh Google or Apple token comes from. Without one, those
  /// confirmations are not offered.
  final FirebaseTokenSource? firebaseToken;

  final password = TextEditingController();
  final code = TextEditingController();

  bool useCode;
  CodeChannel? channel;
  SentCode? sent;
  bool sending = false;

  /// The provider being confirmed with right now (`google.com`, `apple.com`).
  String? confirmingWith;

  /// The provider the account confirmed with, while that proof is held.
  String? confirmedWith;
  Reauth? _firebaseProof;

  String? passwordError;
  String? codeError;
  String? sendError;
  String? firebaseError;

  DateTime? _resendAt;
  Timer? _ticker;
  bool _disposed = false;

  /// The password can confirm this action.
  bool get offersPassword => _offersPassword(options, purpose);

  /// Google and Apple, when the account signs in with them and this app can
  /// ask them for a fresh token.
  List<String> get providers => firebaseToken == null ? const [] : options.appProviders;

  /// A password or a code can be given here, as well as or instead of a
  /// Google or Apple confirmation.
  bool get offersForms => offersPassword || options.channels.isNotEmpty;

  bool get canVerify => offersForms || providers.isNotEmpty;

  /// A code or a Google/Apple token is on its way.
  bool get busy => sending || confirmingWith != null;

  /// What to send with the request, or null while it is incomplete.
  Reauth? get proof {
    final firebase = _firebaseProof;
    if (firebase != null) return firebase;

    if (useCode) {
      final value = code.text.trim();
      final selected = channel;
      final sentCode = sent;
      // The full code only: a partial one would spend one of its attempts.
      if (sentCode == null || selected == null || value.length != sentCode.length) {
        return null;
      }
      return Reauth.code(value, channel: selected.channel);
    }

    if (!offersPassword) return null;
    return password.text.isEmpty ? null : Reauth.password(password.text);
  }

  bool get isComplete => proof != null;

  /// Seconds until another code may be sent, rounded up so a 30 second wait
  /// starts at 0:30.
  int get resendIn {
    final at = _resendAt;
    if (at == null) return 0;
    final ms = at.difference(DateTime.now()).inMilliseconds;
    return ms <= 0 ? 0 : (ms + 999) ~/ 1000;
  }

  void useCodeInstead(bool value) {
    if (value && options.channels.isEmpty) return;
    if (!value && !offersPassword) return;
    useCode = value;
    passwordError = null;
    codeError = null;
    notifyListeners();
  }

  /// Ignored while a code is on its way: the answer belongs to the channel
  /// it was sent to.
  void selectChannel(CodeChannel value) {
    if (sending || value.channel == channel?.channel) return;
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
    if (_disposed || selected == null || sending || resendIn > 0) return;

    sending = true;
    sendError = null;
    codeError = null;
    notifyListeners();

    try {
      final result = await repository.sendCode(purpose: purpose, channel: selected.channel);
      if (_disposed) return;

      // The channel changed while the code was on its way (a backstop: the
      // chips are disabled meanwhile). It went to the other address, so it is
      // not this channel's to ask for, and the new channel may send at once.
      if (channel?.channel != selected.channel) return;

      sent = result;
      code.clear();
      _holdResend(result.resendIn);
    } on ApiFailure catch (failure) {
      if (_disposed) return;

      final wait = (failure is RateLimitFailure ? failure.retryAfter : null) ??
          const Duration(seconds: 30);

      if (channel?.channel != selected.channel) {
        // The server's throttle is per account, so it holds the new channel
        // too. Any other failure was about the channel that was left.
        if (failure is RateLimitFailure) _holdResend(wait);
        return;
      }

      _holdResend(wait);

      // A failed send leaves nothing behind, so a code already on screen
      // still works and its field stays.
      sendError = sent != null && !_codeMayNotWork.contains(failure.code)
          ? 'We could not send a new code. The last code we sent still works.'
          : failure.message;
    } finally {
      if (!_disposed) {
        sending = false;
        notifyListeners();
      }
    }
  }

  /// Confirms with a fresh Google or Apple sign-in, and holds its token as
  /// the proof the next request sends.
  Future<void> confirmWithProvider(String provider) async {
    final source = firebaseToken;
    if (_disposed || source == null || busy) return;

    confirmingWith = provider;
    firebaseError = null;
    passwordError = null;
    codeError = null;
    notifyListeners();

    try {
      final token = await source(provider);
      if (_disposed) return;
      _firebaseProof = Reauth.firebase(token);
      confirmedWith = provider;
    } on SocialSignInException catch (error) {
      if (_disposed) return;
      // Backing out of the provider's own sheet is a choice, not an error.
      if (!error.cancelled) firebaseError = error.message;
    } catch (_) {
      if (_disposed) return;
      firebaseError = 'We couldn\'t confirm it with ${providerLabel(provider)}. Please try again.';
    } finally {
      if (!_disposed) {
        confirmingWith = null;
        notifyListeners();
      }
    }
  }

  /// Drops a held Google or Apple confirmation, back to the other ways.
  void useAnotherWay() {
    _forgetFirebaseProof();
    firebaseError = null;
    notifyListeners();
  }

  void _forgetFirebaseProof() {
    _firebaseProof = null;
    confirmedWith = null;
  }

  /// Puts a failed request's message beside the field it is about.
  ///
  /// Returns whether the failure was about the proof. If it was not, the
  /// caller shows it in its own place.
  bool applyFailure(ApiFailure failure) {
    // The screen that owned this closed while its request was on its way.
    if (_disposed) return false;

    final spentMaybe = failure is ServerFailure ||
        failure is ConflictFailure ||
        failure is TimeoutFailure ||
        failure is UnknownFailure;

    // A Google or Apple confirmation the server would not take. Its token is
    // single use either way, so the next try needs a fresh sign-in.
    if (_firebaseCodes.contains(failure.code) ||
        (_firebaseProof != null && failure.code == 'reauth_locked')) {
      _forgetFirebaseProof();
      firebaseError = failure.message;
      notifyListeners();
      return true;
    }

    if (_firebaseProof != null) {
      // The server may have used the token before it failed.
      if (spentMaybe) {
        _forgetFirebaseProof();
        notifyListeners();
      }
      return false;
    }

    if (!useCode) {
      final message = failure.errorFor('password');
      if (message != null ||
          failure.code == 'invalid_credentials' ||
          failure.code == 'password_not_set' ||
          failure.code == 'password_unproven') {
        passwordError = message ?? failure.message;
        notifyListeners();
        return true;
      }
      return false;
    }

    // otp_invalid and the like, a field error on the code, or the account's
    // confirmation attempts used up for now.
    if (failure.code.startsWith('otp_') ||
        failure.errorFor('code') != null ||
        failure.code == 'reauth_locked') {
      final message = failure.errorFor('code') ?? failure.message;

      if (_codeGone.contains(failure.code)) {
        // This code is finished: offer "Send code" again, with the reason.
        sent = null;
        code.clear();
        codeError = null;
        sendError = message;
      } else {
        codeError = message;
      }

      notifyListeners();
      return true;
    }

    // The server may already have used the code before this went wrong, so
    // the next try needs a new one. A throttle, an export cooldown, a
    // validation error or no connection are refused before any code is
    // checked, so the code on screen stays.
    if (spentMaybe) {
      sent = null;
      code.clear();
      codeError = null;
      sendError = 'Send a new code to try again.';
      notifyListeners();
    }

    return false;
  }

  void _holdResend(Duration wait) {
    if (_disposed) return;

    _ticker?.cancel();
    if (wait <= Duration.zero) {
      _resendAt = null;
      return;
    }

    _resendAt = DateTime.now().add(wait);
    _ticker = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_disposed || resendIn == 0) timer.cancel();
      if (!_disposed) notifyListeners();
    });
  }

  /// A closed sheet or screen may still have a send in flight: nothing it
  /// brings back may reach the disposed fields or listeners.
  @override
  void notifyListeners() {
    if (!_disposed) super.notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    _ticker?.cancel();
    password.dispose();
    code.dispose();
    super.dispose();
  }
}

/// The "confirm it's you" form: a password, a one-time code by email or SMS,
/// or a fresh Google or Apple sign-in, whichever the account can use.
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
        if (!controller.canVerify) {
          return ZInfoBanner(
            tone: StatusTone.warning,
            title: 'We can\'t confirm it\'s you here',
            message: reauthBlockedMessage(controller.options),
            actionLabel: 'Contact support',
            onAction: () => context.push(Routes.newTicket),
          );
        }

        final confirmed = controller.confirmedWith;
        if (confirmed != null) {
          return _ProviderConfirmed(controller: controller, provider: confirmed);
        }

        return AnimatedSize(
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOutCubic,
          alignment: Alignment.topCenter,
          child: Column(
            key: ValueKey(controller.useCode ? 'reauth-code-mode' : 'reauth-password-mode'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              if (controller.offersForms)
                controller.useCode
                    ? _CodeForm(controller: controller, onSubmitted: onSubmitted)
                    : _PasswordForm(controller: controller, onSubmitted: onSubmitted),
              if (controller.providers.isNotEmpty)
                _ProviderOptions(controller: controller, showDivider: controller.offersForms),
            ],
          ),
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
                  // Fixed while a code is on its way: its answer belongs to
                  // the channel it was sent to.
                  onSelected: controller.sending
                      ? null
                      : (_) => controller.selectChannel(channel),
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
            label: wait > 0 ? 'Send code in ${Formatters.countdown(wait)}' : 'Send code',
            icon: Icons.send_rounded,
            size: ZButtonSize.medium,
            isLoading: controller.sending,
            onPressed: selected == null || wait > 0 || controller.busy ? null : controller.sendCode,
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
              onPressed: wait > 0 || controller.busy ? null : controller.sendCode,
              child: Text(
                wait > 0 ? 'Resend code in ${Formatters.countdown(wait)}' : 'Resend code',
              ),
            ),
          ),
        ],
        if (controller.sendError != null) ...[
          const SizedBox(height: AppSpacing.xs),
          ZInfoBanner(message: controller.sendError!, tone: StatusTone.error),
        ],
        if (controller.offersPassword)
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

/// "Confirm with Google" / "Confirm with Apple", for accounts that sign in
/// with them: a fresh sign-in proves it is the owner without a password or
/// a code.
class _ProviderOptions extends StatelessWidget {
  const _ProviderOptions({required this.controller, required this.showDivider});

  final ReauthController controller;
  final bool showDivider;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (showDivider)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
            child: Row(
              children: [
                Expanded(child: Divider(color: AppColors.outlineVariant)),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
                  child: Text('or', style: AppTypography.bodySmall),
                ),
                Expanded(child: Divider(color: AppColors.outlineVariant)),
              ],
            ),
          ),
        for (final provider in controller.providers) ...[
          ZButton.outlined(
            label: 'Confirm with ${providerLabel(provider)}',
            icon: provider == 'apple.com' ? Icons.apple_rounded : Icons.account_circle_outlined,
            size: ZButtonSize.medium,
            isLoading: controller.confirmingWith == provider,
            onPressed: controller.busy ? null : () => controller.confirmWithProvider(provider),
          ),
          const SizedBox(height: AppSpacing.xs),
        ],
        if (controller.firebaseError != null)
          ZInfoBanner(message: controller.firebaseError!, tone: StatusTone.error),
      ],
    );
  }
}

/// A Google or Apple confirmation held for the next request.
class _ProviderConfirmed extends StatelessWidget {
  const _ProviderConfirmed({required this.controller, required this.provider});

  final ReauthController controller;
  final String provider;

  @override
  Widget build(BuildContext context) {
    return Column(
      key: const ValueKey('reauth-provider-confirmed'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        ZInfoBanner(
          tone: StatusTone.success,
          title: 'Confirmed with ${providerLabel(provider)}',
          message: 'Carry on within a few minutes, or you\'ll be asked to confirm again.',
        ),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton(
            onPressed: controller.useAnotherWay,
            child: const Text('Use another way'),
          ),
        ),
      ],
    );
  }
}
