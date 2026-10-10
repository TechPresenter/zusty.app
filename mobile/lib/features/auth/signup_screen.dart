import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../shared/motion/motion.dart';
import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/validators.dart';
import '../../models/auth_config.dart';
import '../../models/user.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';
import '../../router/routes.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_text_field.dart';
import 'widgets/auth_scaffold.dart';
import 'widgets/legal_links.dart';
import 'widgets/role_choice_card.dart';
import 'widgets/social_buttons.dart';

/// Screen 05 — Create Your Account.
///
/// Two steps. First the role — brand or creator — because it decides which
/// account the server creates (`account_type`) and everything the user sees
/// afterwards: brands go straight to search, creators continue into KYC. Then
/// the details.
///
/// Which roles may sign up at all is the operator's call (Admin →
/// Authentication → Sign-up): with only one open, the role step is skipped and
/// that role is used; with neither, the screen says sign-ups are paused rather
/// than showing a form the server would refuse.
///
/// Every account is made with an email address (there are no phone-only
/// accounts), the terms must be agreed to, and product updates and offers are
/// an unticked opt-in. Google sign-up agrees to the terms through the notice
/// above its button and starts with marketing off.
class SignUpScreen extends ConsumerStatefulWidget {
  const SignUpScreen({super.key});

  @override
  ConsumerState<SignUpScreen> createState() => _SignUpScreenState();
}

enum _Step { role, details }

class _SignUpScreenState extends ConsumerState<SignUpScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _referral = TextEditingController();

  _Step _step = _Step.role;

  /// Null until the user picks — the role is too consequential to preselect
  /// on someone's behalf.
  UserRole? _role;

  bool _agreed = false;

  /// Product updates and offers. Unticked until the person ticks it: consent
  /// to marketing is never assumed (DPDP Act).
  bool _marketing = false;
  bool _submitting = false;
  bool _showReferral = false;
  String? _formError;
  String? _termsError;
  Map<String, List<String>> _fieldErrors = const {};

  @override
  void initState() {
    super.initState();

    // With one side closed there is nothing to choose: go straight to the
    // details with the open side selected.
    final roles = _openRoles(ref.read(authConfigProvider).signup);
    if (roles.length == 1) {
      _role = roles.single;
      _step = _Step.details;
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    _referral.dispose();
    super.dispose();
  }

  /// The role to register with: the user's pick while that side is still
  /// open, or the only open side. Null means the user still has to choose.
  ///
  /// Re-checked against the live config rather than trusted from [initState]:
  /// the operator can close a side while the screen is open (the config is
  /// refetched on resume), and sending a closed side would only be refused.
  UserRole? _resolvedRole(List<UserRole> roles) {
    if (_role != null && roles.contains(_role)) return _role;
    return roles.length == 1 ? roles.single : null;
  }

  static List<UserRole> _openRoles(SignupConfig signup) => [
    if (signup.brandEnabled) UserRole.brand,
    if (signup.creatorEnabled) UserRole.creator,
  ];

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _fieldErrors = const {};
      _formError = null;
    });

    final formValid = _formKey.currentState!.validate();

    if (!_agreed) {
      setState(
        () => _termsError = 'Please accept the Terms & Conditions to continue.',
      );
    }
    if (!formValid || !_agreed) return;

    final role = _resolvedRole(_openRoles(ref.read(authConfigProvider).signup));
    if (role == null) {
      setState(() => _step = _Step.role);
      return;
    }

    setState(() => _submitting = true);

    try {
      await ref
          .read(authProvider.notifier)
          .register(
            name: _name.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
            // Sent as `account_type` by the repository, exactly as before.
            role: role,
            referralCode: _referral.text.trim(),
            marketingOptIn: _marketing,
          );
      if (mounted) context.go(Routes.home);
    } on ValidationFailure catch (e) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = e.errors;
        if (e.errorFor('referral_code') != null) _showReferral = true;
        _termsError = e.errorFor('accept_terms') ?? _termsError;

        // Errors with no field on this form still need a home.
        const onForm = {
          'name',
          'email',
          'password',
          'referral_code',
          'accept_terms',
        };
        final unplaced = e.errors.keys.where((k) => !onForm.contains(k));
        _formError = unplaced.isEmpty
            ? null
            : (e.errorFor(unplaced.first) ?? e.message);
      });
    } on ApiFailure catch (e) {
      if (mounted) setState(() => _formError = e.message);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _back() {
    final roles = _openRoles(ref.read(authConfigProvider).signup);
    if (_step == _Step.details && roles.length > 1) {
      setState(() => _step = _Step.role);
      return;
    }
    context.canPop() ? context.pop() : context.go(Routes.login);
  }

  void _clearFieldError(String field) {
    if (_fieldErrors.containsKey(field)) {
      setState(() => _fieldErrors = {..._fieldErrors}..remove(field));
    }
  }

  /// First server message for any of [fields].
  String? _errorFor(List<String> fields) {
    for (final field in fields) {
      final messages = _fieldErrors[field];
      if (messages != null && messages.isNotEmpty) return messages.first;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final authConfig = ref.watch(authConfigProvider);
    final signup = authConfig.signup;
    final roles = _openRoles(signup);

    if (signup.isClosed) {
      return AuthScaffold(
        onBack: _back,
        child: EmptyState(
          icon: Icons.pause_circle_outline_rounded,
          title: 'Sign-ups are paused',
          message:
              'New accounts are not being created right now. Please '
              'check back soon, or log in if you already have an account.',
          actionLabel: 'Back to login',
          onAction: () => context.go(Routes.login),
        ),
      );
    }

    final onRoleStep = _step == _Step.role && roles.length > 1;

    return PopScope(
      // The system back gesture on the details step returns to the role
      // choice, the same as the on-screen chevron.
      canPop: !(_step == _Step.details && roles.length > 1),
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(() => _step = _Step.role);
      },
      child: AuthScaffold(
        onBack: _back,
        bottom: onRoleStep
            ? ZButton(
                label: _role == null
                    ? 'Choose a role to continue'
                    : 'Continue as ${_role!.isCreator ? 'a creator' : 'a brand'}',
                trailingIcon: Icons.arrow_forward_rounded,
                onPressed: _role == null
                    ? null
                    : () => setState(() => _step = _Step.details),
              )
            : ZButton(
                label: 'Create account',
                onPressed: _submit,
                isLoading: _submitting,
                trailingIcon: Icons.arrow_forward_rounded,
              ),
        // Each step settles in as it appears. Not an AnimatedSwitcher: that
        // keeps the outgoing step alive while it fades, and stepping back and
        // forth quickly would put two copies of the form's GlobalKey in the
        // tree at once.
        child: Reveal(
          key: ValueKey(onRoleStep),
          child: onRoleStep
              ? _buildRoleStep(roles)
              : _buildDetailsStep(authConfig, roles.length > 1),
        ),
      ),
    );
  }

  // -- Step 1: role ----------------------------------------------------------

  Widget _buildRoleStep(List<UserRole> roles) {
    return Column(
      key: const ValueKey('role'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const AuthHeader(
          eyebrow: 'Step 1 of 2',
          title: 'Create Your Account',
          subtitle:
              'How will you use Zusty? We will set up the right tools '
              'for you.',
        ),
        const SizedBox(height: AppSpacing.xl),
        if (roles.contains(UserRole.brand))
          RoleChoiceCard(
            title: 'I am a Brand',
            benefit: 'Hire verified creators for your campaigns.',
            icon: Icons.storefront_rounded,
            accent: Icons.campaign_rounded,
            selected: _role == UserRole.brand,
            onTap: () => setState(() => _role = UserRole.brand),
          ),
        if (roles.length > 1) const SizedBox(height: AppSpacing.sm),
        if (roles.contains(UserRole.creator))
          RoleChoiceCard(
            title: 'I am a Creator',
            benefit: 'Get booked by brands and paid when the work is approved.',
            icon: Icons.auto_awesome_rounded,
            accent: Icons.photo_camera_rounded,
            selected: _role == UserRole.creator,
            onTap: () => setState(() => _role = UserRole.creator),
          ),
        const SizedBox(height: AppSpacing.xl),
        AuthFooterLink(
          prompt: 'Already have an account?',
          action: 'Log in',
          onTap: () => context.go(Routes.login),
        ),
      ],
    );
  }

  // -- Step 2: details -------------------------------------------------------

  Widget _buildDetailsStep(AuthConfig authConfig, bool canChangeRole) {
    final role =
        _resolvedRole(_openRoles(ref.read(authConfigProvider).signup)) ??
        UserRole.brand;

    return Form(
      key: _formKey,
      child: Column(
        key: const ValueKey('details'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AuthHeader(
            eyebrow: canChangeRole ? 'Step 2 of 2' : null,
            title: 'Create Your Account',
            subtitle: 'Join Zusty and start collaborating.',
          ),
          const SizedBox(height: AppSpacing.md),
          _RoleSummary(
            role: role,
            onChange: canChangeRole
                ? () => setState(() => _step = _Step.role)
                : null,
          ),
          const SizedBox(height: AppSpacing.lg),
          ZTextField(
            label: role.isBrand ? 'Your full name' : 'Full name',
            hint: 'As you would like it shown',
            controller: _name,
            prefixIcon: Icons.person_outline_rounded,
            textCapitalization: TextCapitalization.words,
            textInputAction: TextInputAction.next,
            validator: (value) =>
                Validators.minLength(value, 2, field: 'Full name'),
            errorText: _errorFor(['name']),
            onChanged: (_) => _clearFieldError('name'),
          ),
          const SizedBox(height: AppSpacing.md),
          // Email only: with no SMS a number could never be verified, so
          // every account is made with an email address.
          ZTextField(
            label: 'Email address',
            hint: 'you@brand.com',
            controller: _email,
            prefixIcon: Icons.alternate_email_rounded,
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.next,
            validator: Validators.email,
            errorText: _errorFor(['email']),
            onChanged: (_) => _clearFieldError('email'),
          ),
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Password',
            hint: 'Create a password',
            controller: _password,
            obscure: true,
            prefixIcon: Icons.lock_outline_rounded,
            helper: 'At least 8 characters, with a letter and a number.',
            validator: Validators.password,
            errorText: _errorFor(['password']),
            onChanged: (_) => _clearFieldError('password'),
          ),
          const SizedBox(height: AppSpacing.sm),
          AnimatedSize(
            duration: Motion.of(context, Motion.medium),
            curve: Motion.standard,
            alignment: Alignment.topCenter,
            child: _showReferral
                ? Padding(
                    padding: const EdgeInsets.only(top: AppSpacing.xs),
                    child: ZTextField(
                      label: 'Referral code (optional)',
                      hint: 'e.g. ZCRTR2001',
                      controller: _referral,
                      prefixIcon: Icons.card_giftcard_rounded,
                      textCapitalization: TextCapitalization.characters,
                      errorText: _errorFor(['referral_code']),
                      onChanged: (_) => _clearFieldError('referral_code'),
                    ),
                  )
                : Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: () => setState(() => _showReferral = true),
                      style: TextButton.styleFrom(
                        minimumSize: const Size(
                          AppSpacing.minTouch,
                          AppSpacing.minTouch,
                        ),
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.xs,
                        ),
                      ),
                      icon: Icon(
                        Icons.card_giftcard_rounded,
                        size: 18,
                        color: AppColors.primary,
                      ),
                      label: Text(
                        'Have a referral code?',
                        style: AppTypography.labelLarge.copyWith(
                          color: AppColors.primary,
                        ),
                      ),
                    ),
                  ),
          ),
          const SizedBox(height: AppSpacing.sm),
          _TermsCheckbox(
            value: _agreed,
            error: _termsError,
            onChanged: (value) => setState(() {
              _agreed = value;
              if (value) _termsError = null;
            }),
          ),
          _MarketingCheckbox(
            value: _marketing,
            onChanged: (value) => setState(() => _marketing = value),
          ),
          if (_formError != null) ...[
            const SizedBox(height: AppSpacing.md),
            AuthErrorBanner(message: _formError!),
          ],
          if (SocialLoginRow.hasProviders(authConfig)) ...[
            const SizedBox(height: AppSpacing.lg),
            OrDivider(label: authConfig.signupDividerLabel),
            const SizedBox(height: AppSpacing.lg),
            // The chosen role goes with a first-time social sign-in too, so
            // a creator who taps Google does not end up with a brand account.
            // The row carries its own terms notice; the boxes above belong to
            // the email form.
            SocialLoginRow(role: role),
          ],
          const SizedBox(height: AppSpacing.lg),
          AuthFooterLink(
            prompt: 'Already have an account?',
            action: 'Log in',
            onTap: () => context.go(Routes.login),
          ),
        ],
      ),
    );
  }
}

/// "Signing up as a Brand · Change" — keeps the step-1 choice visible while
/// the form is filled in.
class _RoleSummary extends StatelessWidget {
  const _RoleSummary({required this.role, this.onChange});

  final UserRole role;
  final VoidCallback? onChange;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.sm,
        AppSpacing.xs,
        AppSpacing.xs,
        AppSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.lgAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(
              role.isCreator
                  ? Icons.auto_awesome_rounded
                  : Icons.storefront_rounded,
              size: 20,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text.rich(
              TextSpan(
                text: 'Signing up as a ',
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onSurfaceVariant,
                ),
                children: [
                  TextSpan(text: role.label, style: AppTypography.titleSmall),
                ],
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (onChange != null)
            TextButton(
              onPressed: onChange,
              style: TextButton.styleFrom(
                minimumSize: const Size(
                  AppSpacing.minTouch,
                  AppSpacing.minTouch,
                ),
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
    );
  }
}

/// The terms agreement, with the documents one tap away.
///
/// Links open the operator's own Terms and Privacy URLs (Admin →
/// Authentication, falling back to Settings → Legal); with neither set the
/// names are plain text rather than links to nowhere.
class _TermsCheckbox extends ConsumerWidget {
  const _TermsCheckbox({
    required this.value,
    required this.onChanged,
    this.error,
  });

  final bool value;
  final ValueChanged<bool> onChanged;
  final String? error;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final urls = legalUrls(ref);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Semantics(
              label: 'I agree to the Terms and Conditions and Privacy Policy',
              child: Checkbox(
                value: value,
                onChanged: (v) => onChanged(v ?? false),
                isError: error != null,
              ),
            ),
            // No top padding: each link is a 48 dp touch target, so a run of
            // the Wrap is already as tall as the checkbox and centres its
            // text on it.
            Expanded(
              child: Wrap(
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  GestureDetector(
                    onTap: () => onChanged(!value),
                    child: Text(
                      'I agree to the ',
                      style: AppTypography.bodyMedium,
                    ),
                  ),
                  LegalLink(label: 'Terms & Conditions', url: urls.terms),
                  Text(' and ', style: AppTypography.bodyMedium),
                  LegalLink(label: 'Privacy Policy', url: urls.privacy),
                ],
              ),
            ),
          ],
        ),
        if (error != null)
          Padding(
            padding: const EdgeInsets.only(left: 48),
            child: Text(
              error!,
              style: AppTypography.bodySmall.copyWith(color: AppColors.error),
            ),
          ),
      ],
    );
  }
}

/// The marketing opt-in: unticked, optional, and saying where it can be
/// changed later. Ticked, the account starts with "product updates and
/// offers" on in Settings → Notifications; left alone, it starts off.
class _MarketingCheckbox extends StatelessWidget {
  const _MarketingCheckbox({required this.value, required this.onChanged});

  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          label: 'Send me product updates and offers',
          child: Checkbox(
            value: value,
            onChanged: (v) => onChanged(v ?? false),
          ),
        ),
        Expanded(
          child: GestureDetector(
            onTap: () => onChanged(!value),
            behavior: HitTestBehavior.opaque,
            child: Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Send me product updates and offers',
                    style: AppTypography.bodyMedium,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Optional. You can change this any time in '
                    'Settings → Notifications.',
                    style: AppTypography.bodySmall.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}
