import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_endpoints.dart';
import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/json.dart';
import '../../core/utils/validators.dart';
import '../../data/repositories/base_repository.dart';
import '../../models/taxonomy.dart';
import '../../models/user.dart';
import '../../providers/account_provider.dart';
import '../../providers/auth_provider.dart';
import '../../providers/core_providers.dart';
import '../../providers/kyc_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import '../kyc/widgets/kyc_status_style.dart';

/// Business Profile — the brand-side counterpart to creator KYC.
///
/// `Zuste_Workflow.md` § 2 routes a new brand to "Brand Profile Setup: business
/// details, GST, PAN" before they are ready to search and book. This screen is
/// that step, and remains editable afterwards.
///
/// GST is optional but strongly encouraged: without it, invoices cannot carry
/// the brand's tax registration and input credit is lost (FR-014).
///
/// The bill-to block on every invoice is built from the account's company
/// name, GSTIN, city, state and PIN code, so those are the billing fields
/// here, and the form shows the result as it will print.
class BusinessProfileScreen extends ConsumerStatefulWidget {
  const BusinessProfileScreen({super.key, this.isOnboarding = false});

  /// When true this is the post-signup setup step, so the bar offers "Skip for
  /// now" and continues into the app rather than popping back.
  final bool isOnboarding;

  @override
  ConsumerState<BusinessProfileScreen> createState() =>
      _BusinessProfileScreenState();
}

class _BusinessProfileScreenState
    extends ConsumerState<BusinessProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  final _company = TextEditingController();
  final _website = TextEditingController();
  final _gst = TextEditingController();
  final _address = TextEditingController();
  final _contactName = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _pin = TextEditingController();

  String? _industry;
  String _companySize = '1-10';
  bool _saving = false;
  bool _seeded = false;

  /// True once the stored state and PIN code have been read. Until then they
  /// are left out of the save, so a slow or failed read can never blank them.
  bool _addressSeeded = false;

  /// Set when the server refused a rename because the account carries the
  /// verified badge (`identity_locked`).
  bool _identityLockedByServer = false;

  /// While the blue tick is granted, the business name it is drawn next to
  /// is frozen — a verified brand must not be able to rename itself into a
  /// better-known one and keep the badge. Support can still change it.
  bool _identityLocked({bool listen = false}) {
    if (_identityLockedByServer) return true;

    final kyc = listen
        ? ref.watch(kycSummaryProvider)
        : ref.read(kycSummaryProvider);
    if (kyc?.hasBlueTick ?? false) return true;

    final details = listen
        ? ref.watch(_accountDetailsProvider).valueOrNull
        : ref.read(_accountDetailsProvider).valueOrNull;
    return Json.boolean(details?['identity_locked']);
  }

  @override
  void initState() {
    super.initState();
    // Seeded before the first build, so no field is ever filled while it is
    // already on screen.
    final user = ref.read(currentUserProvider);
    if (user != null) _seed(user);

    // State and PIN code are not on [User]; they come from the raw profile.
    ref.listenManual<AsyncValue<Map<String, dynamic>>>(
      _accountDetailsProvider,
      (_, next) {
        final details = next.valueOrNull;
        if (details == null || _addressSeeded) return;
        _addressSeeded = true;
        // Never overwrite what the brand has already started typing.
        void fill(TextEditingController field, Object? value) {
          final text = value?.toString().trim() ?? '';
          if (field.text.isEmpty && text.isNotEmpty) field.text = text;
        }

        setState(() {
          fill(_state, details['state']);
          fill(_pin, details['postal_code']);
          fill(_city, details['city']);
          fill(_gst, details['gst_number']);
        });
      },
      fireImmediately: true,
    );
  }

  @override
  void dispose() {
    _company.dispose();
    _website.dispose();
    _gst.dispose();
    _address.dispose();
    _contactName.dispose();
    _city.dispose();
    _state.dispose();
    _pin.dispose();
    super.dispose();
  }

  /// Copies the signed-in account into the form, once.
  void _seed(User user) {
    if (_seeded) return;
    _seeded = true;
    _company.text = user.companyName ?? '';
    _website.text = user.website ?? '';
    _contactName.text = user.name;
    // GSTIN was never seeded, so opening this screen and pressing Save sent
    // an empty `gst_number` and wiped the brand's registration.
    _gst.text = user.gstNumber ?? '';
    _address.text = user.billingAddress ?? '';
    _city.text = user.city ?? '';
    if (user.industry != null && user.industry!.isNotEmpty) {
      _industry = user.industry;
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) {
      showZSnack(context, 'Check the highlighted fields.', isError: true);
      return;
    }

    setState(() => _saving = true);
    final locked = _identityLocked();

    try {
      await ref.read(profileControllerProvider).save({
        // Left out while locked: the server refuses any change to it, and the
        // name this screen holds may be older than the one on record.
        if (!locked) 'company_name': _company.text.trim(),
        'website': _website.text.trim(),
        'gst_number': _gst.text.trim().toUpperCase(),
        'billing_address': _address.text.trim(),
        'contact_person': _contactName.text.trim(),
        'industry': _industry,
        'company_size': _companySize,
        // The parts of the address the invoice bill-to block prints.
        'city': _city.text.trim(),
        if (_addressSeeded || _state.text.trim().isNotEmpty)
          'state': _state.text.trim(),
        if (_addressSeeded || _pin.text.trim().isNotEmpty)
          'postal_code': _pin.text.trim(),
      });
      ref.invalidate(_accountDetailsProvider);

      if (!mounted) return;
      showZSnack(context, 'Business profile saved.');

      widget.isOnboarding ? context.go(Routes.home) : context.pop();
    } on ApiFailure catch (e) {
      if (!mounted) return;

      if (e.code == 'identity_locked') {
        // The verified badge freezes the business name: put the stored name
        // back, lock the field, and say why in the server's own words.
        final user = ref.read(currentUserProvider);
        setState(() {
          _identityLockedByServer = true;
          _company.text = user?.companyName ?? _company.text;
        });
      }
      showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final margin = AppBreakpoints.pageMargin(context);
    // Industry and size lists are managed in the admin panel.
    final tax = ref.watch(taxonomiesProvider);
    final identityLocked = _identityLocked(listen: true);

    // The account arrived after the screen opened: fill in after this frame
    // rather than mid-build.
    if (user != null && !_seeded) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && !_seeded) setState(() => _seed(user));
      });
    }

    return ZScaffold(
      padded: false,
      appBar: ZAppBar(
        title: widget.isOnboarding ? 'Set up your business' : 'Business profile',
        showBack: !widget.isOnboarding,
        actions: [
          if (widget.isOnboarding)
            TextButton(
              onPressed: () => context.go(Routes.home),
              style: TextButton.styleFrom(
                minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
              ),
              child: const Text('Skip'),
            ),
        ],
      ),
      bottomBar: ZButton(
        label: widget.isOnboarding ? 'Save & continue' : 'Save changes',
        isLoading: _saving,
        onPressed: _save,
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.md,
            margin,
            AppSpacing.xl,
          ),
          children: [
            if (widget.isOnboarding)
              const _IntroCard()
            else if (user != null)
              _AccountHeader(
                user: user,
                company: _company,
                // The blue tick, granted by hand after approval — never
                // inferred from the KYC status.
                hasBlueTick:
                    ref.watch(kycSummaryProvider)?.hasBlueTick ?? false,
              ),
            const SizedBox(height: AppSpacing.md),

            // -- Company -----------------------------------------------------
            _Section(
              title: 'Company',
              subtitle: 'Creators see this when you book or invite them.',
              icon: Icons.storefront_rounded,
              children: [
                ZTextField(
                  label: 'Business name',
                  hint: 'Glow Skincare Co.',
                  controller: _company,
                  readOnly: identityLocked,
                  suffixIcon:
                      identityLocked ? Icons.lock_outline_rounded : null,
                  helper: identityLocked
                      ? 'Locked while your account has the verified badge. '
                            'Contact support to change it.'
                      : null,
                  textCapitalization: TextCapitalization.words,
                  textInputAction: TextInputAction.next,
                  onChanged: (_) => setState(() {}),
                  validator: (v) => identityLocked
                      ? null
                      : Validators.minLength(v, 2, field: 'Business name'),
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Primary contact',
                  hint: 'Who creators will deal with',
                  controller: _contactName,
                  textCapitalization: TextCapitalization.words,
                  textInputAction: TextInputAction.next,
                  prefixIcon: Icons.person_outline_rounded,
                  validator: (v) =>
                      Validators.minLength(v, 2, field: 'Contact name'),
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Website',
                  hint: 'https://glowskincare.in',
                  controller: _website,
                  keyboardType: TextInputType.url,
                  prefixIcon: Icons.language_rounded,
                  validator: Validators.optionalUrl,
                ),
                if (tax.terms(Tax.industry).isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.md),
                  const _FieldLabel('Industry'),
                  Wrap(
                    spacing: AppSpacing.xs,
                    runSpacing: AppSpacing.xs,
                    children: [
                      for (final term in tax.terms(Tax.industry))
                        Semantics(
                          selected: _industry == term.value,
                          child: ZChip(
                            label: term.label,
                            selected: _industry == term.value,
                            onTap: () => setState(
                              () => _industry =
                                  _industry == term.value ? null : term.value,
                            ),
                          ),
                        ),
                    ],
                  ),
                ],
                if (tax.terms(Tax.companySize).isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.md),
                  const _FieldLabel('Team size'),
                  Wrap(
                    spacing: AppSpacing.xs,
                    runSpacing: AppSpacing.xs,
                    children: [
                      for (final term in tax.terms(Tax.companySize))
                        Semantics(
                          selected: _companySize == term.value,
                          child: ZChip(
                            label: term.label,
                            selected: _companySize == term.value,
                            onTap: () =>
                                setState(() => _companySize = term.value),
                          ),
                        ),
                    ],
                  ),
                ],
              ],
            ),

            // -- Billing -----------------------------------------------------
            _Section(
              title: 'Billing & tax',
              subtitle:
                  'Used on every GST invoice Zusty issues for your bookings.',
              icon: Icons.receipt_long_rounded,
              children: [
                ZTextField(
                  label: 'GSTIN (optional)',
                  hint: '27AABCG1234M1Z5',
                  controller: _gst,
                  textCapitalization: TextCapitalization.characters,
                  inputFormatters: [
                    LengthLimitingTextInputFormatter(15),
                    FilteringTextInputFormatter.allow(RegExp('[a-zA-Z0-9]')),
                  ],
                  prefixIcon: Icons.verified_outlined,
                  helper: 'Add this to claim input tax credit on your invoices.',
                  onChanged: (_) => setState(() {}),
                  validator: Validators.optionalGst,
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Street address',
                  hint: 'Building, street, area',
                  controller: _address,
                  maxLines: 2,
                  minLines: 1,
                  textCapitalization: TextCapitalization.words,
                ),
                const SizedBox(height: AppSpacing.md),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: ZTextField(
                        label: 'City',
                        hint: 'Mumbai',
                        controller: _city,
                        textCapitalization: TextCapitalization.words,
                        textInputAction: TextInputAction.next,
                        onChanged: (_) => setState(() {}),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: ZTextField(
                        label: 'State',
                        hint: 'Maharashtra',
                        controller: _state,
                        textCapitalization: TextCapitalization.words,
                        textInputAction: TextInputAction.next,
                        onChanged: (_) => setState(() {}),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'PIN code',
                  hint: '400001',
                  controller: _pin,
                  keyboardType: TextInputType.number,
                  inputFormatters: [
                    FilteringTextInputFormatter.digitsOnly,
                    LengthLimitingTextInputFormatter(6),
                  ],
                  onChanged: (_) => setState(() {}),
                  validator: (v) {
                    final pin = v?.trim() ?? '';
                    if (pin.isEmpty) return null;
                    return RegExp(r'^[1-9]\d{5}$').hasMatch(pin)
                        ? null
                        : 'Enter a valid 6-digit PIN code.';
                  },
                ),
                const SizedBox(height: AppSpacing.md),
                _BillToPreview(
                  company: _company.text.trim(),
                  gst: _gst.text.trim().toUpperCase(),
                  city: _city.text.trim(),
                  state: _state.text.trim(),
                  pin: _pin.text.trim(),
                ),
              ],
            ),

            const _VerificationCard(),
          ],
        ),
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Pieces
// -----------------------------------------------------------------------------

/// Who is being edited: the company mark, its name and its sign-in email.
class _AccountHeader extends StatelessWidget {
  const _AccountHeader({
    required this.user,
    required this.company,
    this.hasBlueTick = false,
  });

  final User user;
  final TextEditingController company;
  final bool hasBlueTick;

  @override
  Widget build(BuildContext context) {
    final name = company.text.trim().isEmpty ? user.displayName : company.text.trim();

    return ZCard(
      child: Row(
        children: [
          ZAvatar(
            imageUrl: user.avatarUrl,
            name: name,
            size: 56,
            isVerified: hasBlueTick,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleMedium,
                ),
                if (user.email != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    user.email!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodySmall,
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _IntroCard extends StatelessWidget {
  const _IntroCard();

  @override
  Widget build(BuildContext context) {
    return ZCard(
      color: AppColors.primaryFixed,
      shadow: false,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.primary,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(
              Icons.storefront_rounded,
              size: 20,
              color: AppColors.onPrimary,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Creators check this before accepting',
                  style: AppTypography.titleSmall.copyWith(
                    fontWeight: FontWeight.w700,
                    color: AppColors.onPrimaryFixed,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'A complete business profile gets noticeably higher acceptance '
                  'rates. It takes about a minute.',
                  style: AppTypography.bodySmall
                      .copyWith(color: AppColors.onPrimaryFixedVariant),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The invoice's bill-to block, as these fields will print it.
class _BillToPreview extends StatelessWidget {
  const _BillToPreview({
    required this.company,
    required this.gst,
    required this.city,
    required this.state,
    required this.pin,
  });

  final String company;
  final String gst;
  final String city;
  final String state;
  final String pin;

  @override
  Widget build(BuildContext context) {
    final place = [city, state, pin].where((p) => p.isNotEmpty).join(', ');

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: AppRadius.mdAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'BILLED TO, ON YOUR INVOICES',
            style: AppTypography.labelSmall.copyWith(letterSpacing: 0.8),
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            company.isEmpty ? 'Your business name' : company,
            style: AppTypography.titleSmall.copyWith(
              color: company.isEmpty ? AppColors.outline : AppColors.onSurface,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            place.isEmpty ? 'City, state and PIN code' : place,
            style: AppTypography.bodySmall.copyWith(
              color: place.isEmpty ? AppColors.outline : AppColors.onSurface,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            gst.isEmpty ? 'No GSTIN — input credit cannot be claimed' : 'GSTIN $gst',
            style: AppTypography.bodySmall.copyWith(
              color: gst.isEmpty ? AppColors.warning : AppColors.onSurface,
              fontWeight: gst.isEmpty ? null : FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _VerificationCard extends ConsumerWidget {
  const _VerificationCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    // The verification centre's own reading when it has loaded; the account's
    // coarser `kyc_status` until then.
    final kyc = ref.watch(kycSummaryProvider);
    final status = kyc?.status ?? user?.kycStatus ?? KycStatus.notSubmitted;
    final label = kyc?.label ?? status.label;
    final hasBlueTick = kyc?.hasBlueTick ?? false;
    final tone = status.tone;
    final (tint, ink) = StatusChip.colorsFor(
      tone == StatusTone.neutral ? StatusTone.success : tone,
    );

    return ZCard(
      onTap: () => context.push(Routes.kyc),
      child: Semantics(
        button: true,
        label:
            'Business verification: $label'
            '${hasBlueTick ? ', blue tick granted' : ''}. Open verification',
        excludeSemantics: true,
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: tint,
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(Icons.verified_user_rounded, size: 22, color: ink),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Business verification', style: AppTypography.titleSmall),
                  const SizedBox(height: 2),
                  Text(
                    status == KycStatus.approved
                        ? 'Verified brands get priority placement with creators.'
                        : 'Get verified for priority placement with creators.',
                    style: AppTypography.bodySmall,
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  Wrap(
                    spacing: AppSpacing.xs,
                    runSpacing: 4,
                    children: [
                      StatusChip(label: label, tone: tone, dense: true),
                      if (hasBlueTick)
                        const StatusChip(
                          label: 'Blue tick',
                          tone: StatusTone.info,
                          icon: Icons.verified_rounded,
                          dense: true,
                        )
                      else if (status == KycStatus.approved)
                        const StatusChip(
                          label: 'Blue tick under review',
                          tone: StatusTone.neutral,
                          dense: true,
                        ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Icon(Icons.chevron_right_rounded, color: AppColors.outline),
          ],
        ),
      ),
    );
  }
}

class _FieldLabel extends StatelessWidget {
  const _FieldLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Text(
        text,
        style: AppTypography.labelLarge.copyWith(color: AppColors.onSurface),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({
    required this.title,
    required this.children,
    required this.icon,
    this.subtitle,
  });

  final String title;
  final String? subtitle;
  final IconData icon;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: ZCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLow,
                    borderRadius: AppRadius.mdAll,
                  ),
                  child: Icon(icon, size: 18, color: AppColors.primary),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Semantics(
                        header: true,
                        child: Text(title, style: AppTypography.titleMedium),
                      ),
                      if (subtitle != null) ...[
                        const SizedBox(height: 2),
                        Text(subtitle!, style: AppTypography.bodySmall),
                      ],
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.md),
            ...children,
          ],
        ),
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Data
// -----------------------------------------------------------------------------

/// The account exactly as `GET /me` returns it.
///
/// [User] does not carry the state or PIN code, but the invoice bill-to block
/// is built from them, so this screen reads them directly to show and keep
/// them. Kept beside the one screen that needs it.
class _AccountDetailsRepository extends BaseRepository {
  _AccountDetailsRepository(super.client);

  Future<Map<String, dynamic>> fetch() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.profile);
          return response.maybeMapAt('user') ?? response.dataMap;
        },
        'profile',
        (json) => asMap(json),
      );
}

final _accountDetailsProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) {
  return _AccountDetailsRepository(ref.watch(apiClientProvider)).fetch();
});
