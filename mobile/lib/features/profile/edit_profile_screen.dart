import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/validators.dart';
import '../../models/user.dart';
import '../../providers/account_provider.dart';
import '../../providers/ai_provider.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/kyc_provider.dart';
import '../../providers/auth_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/ai_draft_sheet.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';

/// Screen 19 — Edit Profile.
///
/// Avatar, identity fields, business details for brands, the bio (with an AI
/// draft for creators), and verification. Notification switches moved to
/// Settings, where they save as they flip; this screen links there.
class EditProfileScreen extends ConsumerStatefulWidget {
  const EditProfileScreen({super.key});

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();

  late final TextEditingController _name;
  late final TextEditingController _company;
  late final TextEditingController _email;
  late final TextEditingController _phone;
  late final TextEditingController _city;
  late final TextEditingController _bio;

  String? _pendingAvatarPath;
  bool _saving = false;

  /// Set after the first Save attempt, so fields validate as they are fixed
  /// rather than shouting at someone who has not finished typing.
  bool _submitted = false;

  /// What the server said about specific fields on the last save.
  Map<String, String> _serverErrors = const {};

  /// Set when the server refused a rename because the account carries the
  /// verified badge (`identity_locked`) — the app may not have known yet.
  bool _identityLockedByServer = false;

  /// While the blue tick is granted, the public name it is drawn next to is
  /// frozen: renaming would let a verified account borrow another brand's
  /// reputation. An administrator can still change it.
  bool get _identityLocked =>
      _identityLockedByServer ||
      (ref.read(kycSummaryProvider)?.hasBlueTick ?? false);

  static const _identityLockedNote =
      'Locked while your account has the verified badge. Contact support to '
      'change it.';

  /// The bio field's limit, which the AI draft is asked to fit.
  static const _bioMaxLength = 300;

  @override
  void initState() {
    super.initState();
    final user = ref.read(currentUserProvider);

    _name = TextEditingController(text: user?.name ?? '');
    _company = TextEditingController(text: user?.companyName ?? '');
    _email = TextEditingController(text: user?.email ?? '');
    _phone = TextEditingController(text: user?.phone ?? '');
    _city = TextEditingController(text: user?.city ?? '');
    _bio = TextEditingController(text: user?.bio ?? '');
  }

  @override
  void dispose() {
    _name.dispose();
    _company.dispose();
    _email.dispose();
    _phone.dispose();
    _city.dispose();
    _bio.dispose();
    super.dispose();
  }

  Future<void> _pickAvatar() async {
    final file = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
      maxWidth: 800,
    );
    if (file != null) setState(() => _pendingAvatarPath = file.path);
  }

  Future<void> _save() async {
    setState(() {
      _submitted = true;
      _serverErrors = const {};
    });

    if (!_formKey.currentState!.validate()) {
      showZSnack(context, 'Check the highlighted fields.', isError: true);
      return;
    }

    setState(() => _saving = true);
    final controller = ref.read(profileControllerProvider);
    final locked = _identityLocked;

    try {
      String? avatarUrl;
      if (_pendingAvatarPath != null) {
        avatarUrl = await controller.uploadAvatar(_pendingAvatarPath!);
      }

      await controller.save({
        // Left out while locked: the server refuses any change to them, and
        // the name this screen holds may be older than the one on record.
        if (!locked) 'name': _name.text.trim(),
        if (!locked) 'company_name': _company.text.trim(),
        'email': _email.text.trim(),
        'phone': _phone.text.trim(),
        'city': _city.text.trim(),
        'bio': _bio.text.trim(),
        'avatar_url': ?avatarUrl,
      });

      if (mounted) {
        showZSnack(context, 'Profile updated.');
        context.pop();
      }
    } on ApiFailure catch (e) {
      if (!mounted) return;

      if (e.code == 'identity_locked') {
        // The verified badge freezes the public name. Put the stored name
        // back, lock the fields, and say why in the server's own words; the
        // rest of the form is still there to save without the rename.
        final user = ref.read(currentUserProvider);
        setState(() {
          _identityLockedByServer = true;
          _name.text = user?.name ?? _name.text;
          _company.text = user?.companyName ?? _company.text;
        });
        showZSnack(context, e.message, isError: true);
        return;
      }

      // Field-level messages go beside their fields; anything else is a
      // toast.
      final fieldErrors = <String, String>{
        for (final key in [
          'name',
          'company_name',
          'email',
          'phone',
          'city',
          'bio',
        ])
          if (e.errorFor(key) != null) key: e.errorFor(key)!,
      };
      setState(() => _serverErrors = fieldErrors);
      showZSnack(
        context,
        fieldErrors.isEmpty ? e.message : 'Check the highlighted fields.',
        isError: true,
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  /// Drafts the bio from the creator's profile; nothing is saved until the
  /// user presses "Save changes".
  Future<void> _writeBioWithAi() async {
    final draft = await AiDraftSheet.show(
      context,
      title: 'Write your bio with AI',
      subtitle:
          'We draft it from your profile: your niche, city and linked '
          'platforms. Add anything you want it to mention.',
      inputLabel: 'Anything to include? (optional)',
      inputHint: 'e.g. Honest skincare reviews in Hindi and English',
      useLabel: _bio.text.trim().isEmpty ? 'Use this' : 'Replace my bio',
      generate: (notes) => ref
          .read(aiRepositoryProvider)
          .writeBio(
            notes: notes.isEmpty ? null : notes,
            maxChars: _bioMaxLength,
          ),
    );

    if (draft != null && mounted) setState(() => _bio.text = draft);
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final ai = ref.watch(aiFeaturesProvider);
    final margin = AppBreakpoints.pageMargin(context);
    final identityLocked =
        _identityLockedByServer ||
        (ref.watch(kycSummaryProvider)?.hasBlueTick ?? false);

    if (user == null) {
      return const ZScaffold(
        appBar: ZAppBar(title: 'Edit profile'),
        body: ZLoader(),
      );
    }

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Edit profile'),
      bottomBar: ZButton(
        label: 'Save changes',
        isLoading: _saving,
        onPressed: _save,
      ),
      body: Form(
        key: _formKey,
        autovalidateMode: _submitted
            ? AutovalidateMode.onUserInteraction
            : AutovalidateMode.disabled,
        child: ListView(
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.lg,
            margin,
            AppSpacing.xl,
          ),
          children: [
            // -- Avatar ------------------------------------------------------
            _AvatarEditor(
              user: user,
              pendingPath: _pendingAvatarPath,
              onPick: _pickAvatar,
            ),

            // -- Identity -----------------------------------------------------
            const _SectionTitle('Your details'),
            ZCard(
              shadow: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ZTextField(
                    label: 'Full name',
                    controller: _name,
                    prefixIcon: Icons.person_outline_rounded,
                    suffixIcon: identityLocked ? Icons.lock_outline_rounded : null,
                    readOnly: identityLocked,
                    textCapitalization: TextCapitalization.words,
                    textInputAction: TextInputAction.next,
                    helper: identityLocked ? _identityLockedNote : null,
                    errorText: _serverErrors['name'],
                    validator: (value) => identityLocked
                        ? null
                        : Validators.minLength(value, 2, field: 'Full name'),
                  ),
                  if (user.role.isBrand) ...[
                    const SizedBox(height: AppSpacing.md),
                    ZTextField(
                      label: 'Company / brand name',
                      controller: _company,
                      prefixIcon: Icons.storefront_outlined,
                      suffixIcon:
                          identityLocked ? Icons.lock_outline_rounded : null,
                      readOnly: identityLocked,
                      textCapitalization: TextCapitalization.words,
                      textInputAction: TextInputAction.next,
                      helper: identityLocked
                          ? _identityLockedNote
                          : 'Shown to creators instead of your name.',
                      errorText: _serverErrors['company_name'],
                    ),
                  ],
                  const SizedBox(height: AppSpacing.md),
                  ZTextField(
                    label: 'City',
                    controller: _city,
                    prefixIcon: Icons.location_on_outlined,
                    textCapitalization: TextCapitalization.words,
                    textInputAction: TextInputAction.next,
                    errorText: _serverErrors['city'],
                  ),
                ],
              ),
            ),

            // -- Contact -------------------------------------------------------
            const _SectionTitle('Contact'),
            ZCard(
              shadow: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ZTextField(
                    label: 'Email',
                    controller: _email,
                    prefixIcon: Icons.mail_outline_rounded,
                    keyboardType: TextInputType.emailAddress,
                    textInputAction: TextInputAction.next,
                    suffixIcon: user.emailVerified
                        ? Icons.verified_rounded
                        : null,
                    helper: user.emailVerified ? 'Verified' : null,
                    errorText: _serverErrors['email'],
                    validator: Validators.email,
                  ),
                  const SizedBox(height: AppSpacing.md),
                  ZTextField(
                    label: 'Mobile number',
                    controller: _phone,
                    prefixIcon: Icons.phone_iphone_rounded,
                    keyboardType: TextInputType.phone,
                    maxLength: 10,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    suffixIcon: user.phoneVerified
                        ? Icons.verified_rounded
                        : null,
                    helper: user.phoneVerified
                        ? 'Verified'
                        : '10-digit Indian mobile number',
                    errorText: _serverErrors['phone'],
                    validator: Validators.phone,
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: ZButton(
                      label: 'Verify or add another email or number',
                      variant: ZButtonVariant.text,
                      size: ZButtonSize.small,
                      trailingIcon: Icons.arrow_forward_rounded,
                      expand: false,
                      onPressed: () => context.push(Routes.contacts),
                    ),
                  ),
                ],
              ),
            ),

            // -- Bio ------------------------------------------------------------
            _SectionTitle(
              user.role.isCreator ? 'About you' : 'About your brand',
            ),
            ZCard(
              shadow: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ZTextField(
                    label: 'Bio',
                    hint: user.role.isCreator
                        ? 'Tell brands what you create and who it is for'
                        : 'Tell creators about your brand',
                    controller: _bio,
                    maxLines: 5,
                    minLines: 3,
                    maxLength: _bioMaxLength,
                    textCapitalization: TextCapitalization.sentences,
                    errorText: _serverErrors['bio'],
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  Row(
                    children: [
                      Expanded(
                        child: ValueListenableBuilder<TextEditingValue>(
                          valueListenable: _bio,
                          builder: (_, value, _) => Text(
                            '${value.text.characters.length}/$_bioMaxLength',
                            style: AppTypography.bodySmall,
                          ),
                        ),
                      ),
                      if (user.role.isCreator && ai.bioWriter)
                        ZButton.tonal(
                          label: 'Write with AI',
                          icon: Icons.auto_awesome_rounded,
                          size: ZButtonSize.small,
                          expand: false,
                          onPressed: _writeBioWithAi,
                        ),
                    ],
                  ),
                ],
              ),
            ),

            // -- Verification ---------------------------------------------------
            const _SectionTitle('Verification'),
            _VerificationCard(user: user),

            // -- Notification preferences ------------------------------------------
            const _SectionTitle('Notifications'),
            ZMenuSection(
              children: [
                ZMenuTile(
                  icon: Icons.notifications_active_outlined,
                  title: 'Notification preferences',
                  subtitle: 'Push, email, SMS and product updates',
                  onTap: () => context.push(Routes.settings),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(
        top: AppSpacing.lg,
        bottom: AppSpacing.xs,
        left: AppSpacing.base,
      ),
      child: Semantics(
        header: true,
        child: Text(text, style: AppTypography.titleMedium),
      ),
    );
  }
}

/// The avatar with its change-photo control, and the account type under it.
class _AvatarEditor extends StatelessWidget {
  const _AvatarEditor({
    required this.user,
    required this.pendingPath,
    required this.onPick,
  });

  final User user;
  final String? pendingPath;
  final VoidCallback onPick;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Semantics(
          button: true,
          label: 'Change profile photo',
          child: GestureDetector(
            onTap: onPick,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                ZAvatar(
                  imageUrl: pendingPath ?? user.avatarUrl,
                  name: user.displayName,
                  size: 104,
                  ringColor: AppColors.primary,
                  ringWidth: 2,
                ),
                Positioned(
                  right: -4,
                  bottom: -4,
                  child: Container(
                    width: AppSpacing.minTouch,
                    height: AppSpacing.minTouch,
                    decoration: BoxDecoration(
                      color: AppColors.primary,
                      shape: BoxShape.circle,
                      border: Border.all(color: AppColors.surface, width: 3),
                      boxShadow: AppShadows.subtle,
                    ),
                    child: Icon(
                      Icons.photo_camera_outlined,
                      size: 20,
                      color: AppColors.onPrimary,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        if (pendingPath != null)
          Text(
            'New photo selected. It is saved with your changes.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall.copyWith(color: AppColors.primary),
          )
        else
          StatusChip(
            label: '${user.role.label} account',
            tone: StatusTone.neutral,
            icon: user.role.isCreator
                ? Icons.auto_awesome_rounded
                : Icons.business_center_rounded,
            dense: true,
          ),
      ],
    );
  }
}

/// Where verification stands, and the way into the verification centre.
///
/// Reads the centre's own status, as the profile menu does, so the two never
/// disagree; `user.kycStatus` covers the moment before it loads.
class _VerificationCard extends ConsumerWidget {
  const _VerificationCard({required this.user});

  final User user;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final kyc = ref.watch(kycSummaryProvider);
    final status = kyc?.status ?? user.kycStatus;
    final approved = status == KycStatus.approved;

    // The copy and the button follow what the user can actually do: while a
    // reviewer has the documents they can only look, and an account on hold
    // is a conversation with support, not a form to fill in again.
    final (tone, icon, note, action, route) = switch (status) {
      KycStatus.approved => (
        StatusTone.success,
        Icons.verified_user_rounded,
        'Your identity is verified.',
        null,
        Routes.kyc,
      ),
      KycStatus.pending || KycStatus.underReview => (
        StatusTone.info,
        Icons.hourglass_top_rounded,
        'Our team is reviewing your documents. We will notify you when '
            'there is a decision.',
        'View',
        Routes.kyc,
      ),
      KycStatus.actionNeeded => (
        StatusTone.warning,
        Icons.assignment_late_outlined,
        'Our reviewer needs something more. Update your documents to '
            'continue.',
        'Update',
        Routes.kyc,
      ),
      KycStatus.rejected => (
        StatusTone.error,
        Icons.gpp_bad_outlined,
        'Your verification was not approved. Fix the issue and resubmit.',
        'Fix',
        Routes.kyc,
      ),
      KycStatus.suspended => (
        StatusTone.error,
        Icons.block_rounded,
        'Your verification is on hold. Contact support to find out why.',
        'Support',
        Routes.support,
      ),
      KycStatus.expired => (
        StatusTone.warning,
        Icons.update_rounded,
        'Your verification has lapsed. Submit current documents to renew it.',
        'Renew',
        Routes.kyc,
      ),
      KycStatus.notSubmitted => (
        StatusTone.warning,
        Icons.shield_outlined,
        'Verified accounts rank higher and can withdraw earnings.',
        'Verify',
        Routes.kyc,
      ),
    };
    final colors = StatusChip.colorsFor(tone);

    return ZCard(
      shadow: false,
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: colors.$1,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(icon, size: 22, color: colors.$2),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  kyc?.label ?? status.label,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                Text(note, style: AppTypography.bodySmall),
              ],
            ),
          ),
          if (!approved && action != null) ...[
            const SizedBox(width: AppSpacing.xs),
            ZButton.outlined(
              label: action,
              size: ZButtonSize.small,
              expand: false,
              onPressed: () => context.push(route),
            ),
          ],
        ],
      ),
    );
  }
}
