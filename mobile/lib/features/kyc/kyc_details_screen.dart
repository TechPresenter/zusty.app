import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/json.dart';
import '../../core/utils/validators.dart';
import '../../models/kyc.dart';
import '../../models/taxonomy.dart';
import '../../providers/kyc_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import 'kyc_validation.dart';
import 'widgets/kyc_on_file_field.dart';
import 'widgets/kyc_processing_consent.dart';
import 'widgets/kyc_secure_text_field.dart';

/// The KYC detail form — a business profile for brands, an identity profile
/// for creators.
///
/// Which set of fields a user sees follows their account type, decided by the
/// backend, so there is no way to fill in the wrong form. Every option list
/// (company type, industry, ID proof, gender) comes from the taxonomy bundle
/// and is editable in the admin panel.
///
/// Identity numbers (Aadhaar, PAN, GSTIN, CIN, bank details) are never put
/// into an editable field: the server only ever returns them masked, so they
/// show as "on file" with a Change action, and a save sends only the fields
/// the user actually changed.
class KycDetailsScreen extends ConsumerStatefulWidget {
  const KycDetailsScreen({super.key});

  @override
  ConsumerState<KycDetailsScreen> createState() => _KycDetailsScreenState();
}

class _KycDetailsScreenState extends ConsumerState<KycDetailsScreen> {
  final _formKey = GlobalKey<FormState>();
  final _controllers = <String, TextEditingController>{};

  /// Single-choice taxonomy answers, keyed by field name.
  final _choices = <String, String?>{};

  /// What each field held when the form opened, normalised — the baseline a
  /// save is diffed against, so only real changes are sent.
  final _initialText = <String, String>{};
  final _initialChoices = <String, String?>{};

  /// Sensitive fields the user chose to change, so they show an input
  /// instead of the masked value on file.
  final _changing = <String>{};

  /// Optional sensitive fields the user chose to remove; each goes to the
  /// server as an explicit null on the next save.
  final _removing = <String>{};

  bool _saving = false;
  bool _hydrated = false;

  /// Unsaved edits — leaving with these asks first, because this form is long
  /// and some of it (bank details, IDs) is tedious to type twice.
  bool _dirty = false;

  /// Field errors from the server's 422, shown under the field they concern.
  /// The client validators mirror the server's rules, but uniqueness ("This
  /// PAN is already linked to another account") can only be known there.
  Map<String, List<String>> _fieldErrors = const {};

  /// Server errors for keys this form has no field for.
  List<String> _otherErrors = const [];

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  /// Fills the form from the saved details, once, when they first arrive.
  ///
  /// Sensitive fields start EMPTY whatever the server sent: what comes back
  /// is a masked rendering, and an input holding `XXXXXX234F` is one save
  /// away from overwriting the real number.
  void _hydrate(KycOverview overview) {
    if (_hydrated) return;
    _hydrated = true;

    for (final field in _fieldsFor(overview.isBrand)) {
      final value = overview.details[field.name];

      if (field.taxonomy != null) {
        _choices[field.name] = _initialChoices[field.name] =
            Json.strOrNull(value);
        continue;
      }

      final text = field.sensitive ? '' : Json.str(value);
      _controllers[field.name] = TextEditingController(text: text);
      _initialText[field.name] = field.normalise(text);
    }
  }

  TextEditingController _controllerFor(String name) =>
      _controllers.putIfAbsent(name, TextEditingController.new);

  /// Only what changed since the form opened, normalised the way the server
  /// stores it.
  ///
  /// A field the user emptied is sent as an explicit null, which is what
  /// clears it server-side; an empty string used to be dropped on the way
  /// out, so the old value stayed while the form said "saved".
  Map<String, dynamic> _changes(bool isBrand) {
    final payload = <String, dynamic>{};

    for (final field in _fieldsFor(isBrand)) {
      if (field.taxonomy != null) {
        final choice = _choices[field.name];
        if (choice != _initialChoices[field.name]) payload[field.name] = choice;
        continue;
      }

      // Only while removing is still allowed: a CIN marked for removal
      // becomes required again if the company type changes to one with a
      // CIN.
      if (_removing.contains(field.name) && _canRemove(field)) {
        payload[field.name] = null;
        continue;
      }

      final value = field.normalise(_controllerFor(field.name).text);
      if (value != (_initialText[field.name] ?? '')) {
        payload[field.name] = value.isEmpty ? null : value;
      }
    }

    return payload;
  }

  /// Whether [field]'s number may be taken off file right now.
  ///
  /// Never while it is needed to submit. And not an "Other ID" still filed
  /// under the old `aadhaar` type: the server reads such a number as the
  /// Aadhaar, so clearing it would clear the Aadhaar on file instead.
  bool _canRemove(_KycField field) {
    if (!field.removable || field.isRequired(this)) return false;
    if (field.name == 'id_proof_number') {
      return _choices['id_proof_type'] != 'aadhaar';
    }
    return true;
  }

  /// The labels of cleared or removed fields that the server's answer still
  /// shows a value for — a save that did not do what the user asked must not
  /// be reported as done.
  List<String> _notApplied(
    Map<String, dynamic> payload,
    KycOverview saved,
  ) {
    final fields = {for (final f in _fieldsFor(saved.isBrand)) f.name: f};

    return [
      for (final entry in payload.entries)
        if (entry.value == null && fields[entry.key] != null)
          if (_stillOnFile(saved, fields[entry.key]!)) fields[entry.key]!.label,
    ];
  }

  static bool _stillOnFile(KycOverview overview, _KycField field) =>
      field.sensitive
          ? overview.maskedValue(field.name) != null
          : Json.strOrNull(overview.details[field.name]) != null;

  Future<void> _save(KycOverview overview) async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      showZSnack(context, 'Fix the highlighted fields.', isError: true);
      return;
    }

    final payload = _changes(overview.isBrand);

    if (payload.isEmpty) {
      setState(() => _dirty = false);
      showZSnack(context, 'No changes to save.');
      context.pop();
      return;
    }

    // The first save needs the user's agreement to processing at all.
    final consent = await ensureKycProcessingConsent(context, overview.profile);
    if (consent == null || !mounted) return;

    setState(() {
      _saving = true;
      _fieldErrors = const {};
      _otherErrors = const [];
    });

    final controller = ref.read(kycProvider.notifier);

    try {
      KycOverview saved;

      try {
        saved = await controller.saveDetails(payload, consent: consent);
      } on ValidationFailure catch (e) {
        // No consent on record after all (a newer notice, or an overview
        // read before it changed): ask, then save once more.
        final consentError = e.errorFor('consent');
        if (consentError == null || !mounted) rethrow;

        final agreed = await showKycProcessingConsent(
          context,
          text: overview.profile.consentText,
          error: consentError,
        );
        if (!agreed) return;

        saved = await controller.saveDetails(payload, consent: true);
      }

      if (!mounted) return;

      final missed = _notApplied(payload, saved);
      if (missed.isNotEmpty) {
        showZSnack(
          context,
          'These were not cleared: ${missed.join(', ')}. Please try again.',
          isError: true,
        );
        return;
      }

      setState(() => _dirty = false);
      showZSnack(context, 'Details saved.');
      context.pop();
    } on ValidationFailure catch (e) {
      if (!mounted) return;

      final fields = {for (final f in _fieldsFor(overview.isBrand)) f.name: f};
      final mapped = <String, List<String>>{};
      final other = <String>[];

      for (final entry in e.errors.entries) {
        final name = _serverAliases[entry.key] ?? entry.key;
        if (fields.containsKey(name)) {
          mapped[name] = entry.value;
        } else {
          other.addAll(entry.value);
        }
      }

      setState(() {
        _fieldErrors = mapped;
        _otherErrors = other;
        // A flagged number has to be visible to be fixed.
        final flagged = mapped.keys.where(
          (name) => fields[name]?.sensitive == true,
        );
        _changing.addAll(flagged);
        _removing.removeAll(flagged);
      });
      showZSnack(
        context,
        e.errors.isEmpty ? e.message : 'Fix the highlighted fields.',
        isError: true,
      );
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  /// Error keys the server may use for a field under another name.
  static const _serverAliases = {
    'aadhaar': 'aadhaar_number',
    'pan': 'pan_number',
    'gst': 'gst_number',
    'gstin': 'gst_number',
    'cin': 'cin_number',
  };

  /// Asks before throwing away unsaved edits.
  Future<void> _leave() async {
    if (_dirty) {
      final discard = await showZConfirm(
        context,
        title: 'Discard your changes?',
        message: 'You have edits on this form that have not been saved.',
        confirmLabel: 'Discard',
        cancelLabel: 'Keep editing',
        destructive: true,
      );
      if (!discard || !mounted) return;
      setState(() => _dirty = false);
    }
    if (mounted) context.pop();
  }

  /// Marks the form dirty and clears the server's complaint about [field].
  ///
  /// [rebuild] forces a frame even when nothing else changed, for fields
  /// whose helper text follows what is typed (GSTIN and PAN).
  void _edited(String field, {bool rebuild = false}) {
    if (rebuild || !_dirty || _fieldErrors.containsKey(field)) {
      setState(() {
        _dirty = true;
        if (_fieldErrors.containsKey(field)) {
          _fieldErrors = {..._fieldErrors}..remove(field);
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(kycProvider);

    return PopScope(
      canPop: !_dirty,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _leave();
      },
      child: ZScaffold(
        backgroundColor: AppColors.surface,
        padded: false,
        constrain: false,
        appBar: ZAppBar(
          title: overview.valueOrNull?.isBrand == false
              ? 'Your details'
              : 'Business details',
          onBack: _leave,
        ),
        bottomBar: overview.valueOrNull?.profile.isEditable == true
            ? ZButton(
                label: 'Save details',
                icon: Icons.check_rounded,
                isLoading: _saving,
                onPressed: () {
                  final data = ref.read(kycProvider).valueOrNull;
                  if (data != null) _save(data);
                },
              )
            : null,
        // `skipError`: a refused save or a failed background refresh keeps
        // the form — and everything typed into it — on screen. Only a first
        // load that failed shows the error page.
        body: overview.when(
          skipError: true,
          data: (data) {
            _hydrate(data);
            return _buildForm(data);
          },
          loading: () => const ListSkeleton(
            count: 4,
            height: 120,
            padding: EdgeInsets.all(AppSpacing.md),
          ),
          error: (error, _) => ErrorState(
            error: error,
            onRetry: () => ref.read(kycProvider.notifier).refresh(),
          ),
        ),
      ),
    );
  }

  Widget _buildForm(KycOverview overview) {
    final margin = AppBreakpoints.pageMargin(context);
    final tax = ref.watch(taxonomiesProvider);
    final locked = !overview.profile.isEditable;

    final sections = _sectionsFor(overview.isBrand);

    // A Column, not a lazy ListView: every field has to be built for the
    // form to validate it, and a field scrolled out of view would otherwise
    // be skipped and only fail on the server.
    return Form(
      key: _formKey,
      child: SingleChildScrollView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: EdgeInsets.fromLTRB(
          margin,
          AppSpacing.md,
          margin,
          AppSpacing.xl,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (locked)
              const ZInfoBanner(
                tone: StatusTone.neutral,
                icon: Icons.lock_rounded,
                message:
                    'These details are locked while your submission is '
                    'with our review team.',
              )
            else
              ZInfoBanner(
                tone: StatusTone.info,
                icon: Icons.shield_outlined,
                message: overview.isBrand
                    ? 'Used only to verify your business and issue GST '
                          'invoices. Save as you go — nothing is sent for '
                          'review until you submit. Fields marked * are '
                          'needed to submit.'
                    : 'Used only to verify who you are and to pay you. Save '
                          'as you go — nothing is sent for review until you '
                          'submit. Fields marked * are needed to submit.',
              ),
            if (_otherErrors.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.sm),
              ZInfoBanner(
                tone: StatusTone.error,
                title: 'Could not save',
                message: _otherErrors.join('\n'),
              ),
            ],
            const SizedBox(height: AppSpacing.lg),
            for (var s = 0; s < sections.length; s++) ...[
              _SectionTitle(
                number: s + 1,
                title: sections[s].title,
                subtitle: sections[s].subtitle,
              ),
              const SizedBox(height: AppSpacing.sm),
              ZCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (var i = 0; i < sections[s].fields.length; i++) ...[
                      if (i > 0) const SizedBox(height: AppSpacing.md),
                      _buildField(sections[s].fields[i], overview, tax, locked),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildField(
    _KycField field,
    KycOverview overview,
    TaxonomyBundle tax,
    bool locked,
  ) {
    final serverError = _fieldErrors[field.name]?.first;
    final label = field.labelFor(this);

    if (field.taxonomy != null) {
      var terms = tax.terms(field.taxonomy!);

      // "Other ID" offers only what the server accepts there, and never
      // Aadhaar or PAN, which have fields of their own.
      if (field.name == 'id_proof_type') {
        terms = terms
            .where((term) => KycRules.otherIdTypes.contains(term.value))
            .toList(growable: false);
      }

      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppTypography.labelLarge.copyWith(fontSize: 13)),
          const SizedBox(height: AppSpacing.xs),
          if (terms.isEmpty)
            Text(
              'No options configured yet.',
              style: AppTypography.labelSmall.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            )
          else
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                for (final term in terms)
                  ZChip(
                    label: term.label,
                    selected: _choices[field.name] == term.value,
                    onTap: locked
                        ? null
                        : () {
                            _edited(field.name);
                            setState(
                              () => _choices[field.name] =
                                  _choices[field.name] == term.value
                                  ? null
                                  : term.value,
                            );
                          },
                  ),
              ],
            ),
          if (serverError != null) ...[
            const SizedBox(height: AppSpacing.xs),
            Text(
              serverError,
              style: AppTypography.bodySmall.copyWith(color: AppColors.error),
            ),
          ],
        ],
      );
    }

    if (field.isDate) {
      final controller = _controllerFor(field.name);

      return ZTextField(
        label: label,
        hint: 'YYYY-MM-DD',
        controller: controller,
        readOnly: true,
        enabled: !locked,
        prefixIcon: Icons.event_rounded,
        errorText: serverError,
        onTap: locked
            ? null
            : () async {
                final before = controller.text;
                await _pickDate(controller);
                if (controller.text != before) _edited(field.name);
              },
      );
    }

    // A number already on file shows masked, read-only, until "Change" — or,
    // for an optional one, "Remove".
    if (field.sensitive) {
      final masked = overview.maskedValue(field.name);

      if (masked != null && !_changing.contains(field.name)) {
        final removable = !locked && _canRemove(field);

        return KycOnFileField(
          label: label,
          onFileLabel: field.onFileLabel ?? '${field.label} on file',
          masked: masked,
          errorText: serverError,
          removing: removable && _removing.contains(field.name),
          onChange: locked
              ? null
              : () => setState(() {
                  _removing.remove(field.name);
                  _changing.add(field.name);
                }),
          onRemove: removable
              ? () {
                  _edited(field.name);
                  setState(() => _removing.add(field.name));
                }
              : null,
          onUndoRemove: () => setState(() => _removing.remove(field.name)),
        );
      }

      final input = _textField(field, label, serverError, locked, overview);
      if (masked == null || locked) return input;

      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          input,
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: () => setState(() {
                _changing.remove(field.name);
                _controllerFor(field.name).clear();
                if (_fieldErrors.containsKey(field.name)) {
                  _fieldErrors = {..._fieldErrors}..remove(field.name);
                }
              }),
              style: TextButton.styleFrom(
                minimumSize: const Size(AppSpacing.minTouch, 44),
              ),
              child: Text('Keep the one on file ($masked)'),
            ),
          ),
        ],
      );
    }

    return _textField(field, label, serverError, locked, overview);
  }

  Widget _textField(
    _KycField field,
    String label,
    String? serverError,
    bool locked,
    KycOverview overview,
  ) {
    // Identity and payout numbers: no suggestions, no autocorrect, nothing
    // for the keyboard to learn and offer again in another app.
    if (field.sensitive) {
      return KycSecureTextField(
        label: label,
        hint: field.hint,
        controller: _controllerFor(field.name),
        enabled: !locked,
        keyboardType: field.keyboard,
        textCapitalization: field.uppercase
            ? TextCapitalization.characters
            : TextCapitalization.none,
        inputFormatters:
            field.formatters ??
            (field.uppercase ? [UpperCaseTextFormatter()] : null),
        validator: field.validator,
        helper: _helperFor(field, overview),
        errorText: serverError,
        onChanged: (_) => _edited(field.name, rebuild: field.liveHelper),
      );
    }

    return ZTextField(
      label: label,
      hint: field.hint,
      controller: _controllerFor(field.name),
      enabled: !locked,
      keyboardType: field.keyboard,
      maxLines: field.maxLines,
      minLines: field.maxLines > 1 ? 3 : null,
      textCapitalization: field.uppercase
          ? TextCapitalization.characters
          : TextCapitalization.sentences,
      inputFormatters:
          field.formatters ??
          (field.uppercase ? [UpperCaseTextFormatter()] : null),
      validator: field.validator,
      helper: _helperFor(field, overview),
      errorText: serverError,
      onChanged: (_) => _edited(field.name, rebuild: field.liveHelper),
    );
  }

  /// Helper text that follows what is typed or chosen elsewhere on the form.
  String? _helperFor(_KycField field, KycOverview overview) {
    switch (field.name) {
      case 'gst_number' when overview.isBrand:
        final gstin = _controllerFor('gst_number').text;
        if (KycRules.gstin(gstin) != null || KycRules.normalize(gstin).isEmpty) {
          return field.helper;
        }

        final embedded = KycRules.panInGstin(gstin)!;
        final typedPan = KycRules.normalize(_controllerFor('pan_number').text);
        if (typedPan.isNotEmpty) return field.helper;

        final panOnFile = overview.maskedValue('pan_number');
        if (panOnFile != null && panOnFile.length >= 4) {
          final last4 = panOnFile.substring(panOnFile.length - 4);
          return embedded.endsWith(last4)
              ? 'Matches the company PAN on file.'
              : 'Check this GSTIN: characters 3–12 should be your company '
                    'PAN, which ends in $last4.';
        }
        return 'Characters 3–12 are your company PAN: $embedded.';

      case 'cin_number' when overview.isBrand:
        final type = _choices['company_type'];
        if (type == null) {
          return 'Needed for private, public and one person companies.';
        }
        if (KycCompanyTypes.requiresCin(type)) {
          return 'Required for your company type. It is printed on your '
              'Certificate of Incorporation.';
        }
        if (type == 'llp') {
          return 'LLPs have an LLPIN, not a CIN — leave this blank.';
        }
        return 'Not needed for your company type.';
    }
    return field.helper;
  }

  Future<void> _pickDate(TextEditingController controller) async {
    final now = DateTime.now();
    final current = DateTime.tryParse(controller.text);

    final picked = await showDatePicker(
      context: context,
      initialDate: current ?? DateTime(now.year - 25),
      firstDate: DateTime(now.year - 100),
      lastDate: now,
    );

    if (picked != null) {
      controller.text = DateFormat('yyyy-MM-dd').format(picked);
    }
  }

  /// Whether the brand's chosen company type needs a CIN.
  bool get _cinRequired => KycCompanyTypes.requiresCin(_choices['company_type']);

  // -----------------------------------------------------------------
  // Field specs
  //
  // Mirrors the validation rules in `Api\KycController::validateBrand()` and
  // `validateCreator()`, so the client asks for exactly what the server will
  // accept — no field the backend would silently drop. Built per call rather
  // than held statically because some checks read other fields (a GSTIN
  // embeds the company PAN).
  // -----------------------------------------------------------------

  List<_KycSection> _sectionsFor(bool isBrand) =>
      isBrand ? _brandSections() : _creatorSections();

  List<_KycField> _fieldsFor(bool isBrand) => [
    for (final section in _sectionsFor(isBrand)) ...section.fields,
  ];

  List<_KycSection> _brandSections() => [
    const _KycSection(
      title: 'Company',
      fields: [
        _KycField(
          name: 'company_name',
          label: 'Company name',
          hint: 'Glow Cosmetics Pvt Ltd',
          requiredToSubmit: true,
        ),
        _KycField(
          name: 'legal_name',
          label: 'Registered legal name',
          helper: 'As it appears on your incorporation certificate.',
        ),
        _KycField(
          name: 'company_type',
          label: 'Company type',
          taxonomy: Tax.companyType,
          requiredToSubmit: true,
        ),
        _KycField(name: 'industry', label: 'Industry', taxonomy: Tax.industry),
        _KycField(
          name: 'company_size',
          label: 'Team size',
          taxonomy: Tax.companySize,
        ),
        _KycField(
          name: 'year_established',
          label: 'Year established',
          hint: '2019',
          keyboard: TextInputType.number,
          validator: Validators.optionalYear,
        ),
        _KycField(name: 'description', label: 'About the company', maxLines: 4),
      ],
    ),
    _KycSection(
      title: 'Tax & registration',
      subtitle: 'Stored encrypted and shown only to our verification team.',
      fields: [
        _KycField(
          name: 'gst_number',
          label: 'GSTIN',
          hint: '27AABCG1234M1Z5',
          sensitive: true,
          onFileLabel: 'GSTIN on file',
          normalisation: _Normalise.identifier,
          formatters: [IdentifierInputFormatter(15)],
          requiredToSubmit: true,
          liveHelper: true,
          validator: (value) =>
              KycRules.gstin(value) ??
              KycRules.gstinPanMismatch(
                value,
                _controllerFor('pan_number').text,
              ),
        ),
        _KycField(
          name: 'cin_number',
          label: 'CIN',
          hint: 'U74999MH2019PTC123456',
          sensitive: true,
          onFileLabel: 'CIN on file',
          normalisation: _Normalise.identifier,
          formatters: [IdentifierInputFormatter(21)],
          requiredWhen: (state) => state._cinRequired,
          validator: KycRules.cin,
          // An LLP or a partnership has no CIN; one entered by mistake has
          // to be removable.
          removable: true,
        ),
        _KycField(
          name: 'pan_number',
          label: 'Company PAN (optional)',
          hint: 'AABCG1234M',
          sensitive: true,
          onFileLabel: 'PAN on file',
          normalisation: _Normalise.identifier,
          formatters: [IdentifierInputFormatter(10)],
          liveHelper: true,
          validator: (value) => KycRules.pan(value),
          removable: true,
        ),
      ],
    ),
    const _KycSection(
      title: 'Addresses',
      fields: [
        _KycField(
          name: 'registered_address',
          label: 'Registered address',
          maxLines: 3,
          requiredToSubmit: true,
        ),
        _KycField(name: 'office_address', label: 'Office address', maxLines: 3),
        _KycField(name: 'city', label: 'City'),
        _KycField(name: 'state', label: 'State'),
        _KycField(
          name: 'pin_code',
          label: 'PIN code',
          keyboard: TextInputType.number,
        ),
      ],
    ),
    _KycSection(
      title: 'Primary contact',
      fields: [
        const _KycField(name: 'contact_name', label: 'Full name'),
        const _KycField(name: 'contact_designation', label: 'Designation'),
        _KycField(
          name: 'contact_email',
          label: 'Work email',
          keyboard: TextInputType.emailAddress,
          validator: Validators.optionalEmail,
        ),
        _KycField(
          name: 'contact_phone',
          label: 'Phone',
          keyboard: TextInputType.phone,
          validator: Validators.optionalPhone,
        ),
      ],
    ),
    _KycSection(
      title: 'Business contact',
      subtitle: 'Where Zusty sends invoices and verification updates.',
      fields: [
        _KycField(
          name: 'business_email',
          label: 'Business email',
          keyboard: TextInputType.emailAddress,
          validator: Validators.optionalEmail,
        ),
        _KycField(
          name: 'business_phone',
          label: 'Business phone',
          keyboard: TextInputType.phone,
          validator: Validators.optionalPhone,
        ),
        _KycField(
          name: 'website',
          label: 'Website',
          hint: 'https://',
          keyboard: TextInputType.url,
          validator: Validators.optionalUrl,
        ),
      ],
    ),
  ];

  List<_KycSection> _creatorSections() => [
    const _KycSection(
      title: 'About you',
      fields: [
        _KycField(
          name: 'full_name',
          label: 'Full legal name',
          helper: 'Must match your PAN and Aadhaar exactly.',
          requiredToSubmit: true,
        ),
        _KycField(name: 'display_name', label: 'Display name'),
        _KycField(name: 'gender', label: 'Gender', taxonomy: Tax.gender),
        _KycField(
          name: 'date_of_birth',
          label: 'Date of birth',
          isDate: true,
          requiredToSubmit: true,
        ),
        _KycField(name: 'bio', label: 'Short bio', maxLines: 4),
      ],
    ),
    const _KycSection(
      title: 'Address',
      fields: [
        _KycField(name: 'address', label: 'Address', maxLines: 3),
        _KycField(name: 'city', label: 'City'),
        _KycField(name: 'state', label: 'State'),
        _KycField(
          name: 'pin_code',
          label: 'PIN code',
          keyboard: TextInputType.number,
        ),
      ],
    ),
    _KycSection(
      title: 'Identity',
      subtitle: 'Stored encrypted and never shown to brands.',
      fields: [
        _KycField(
          name: 'aadhaar_number',
          label: 'Aadhaar number',
          hint: '2345 6789 0123',
          helper: 'We keep only the last 4 digits — never the full number.',
          sensitive: true,
          onFileLabel: 'Aadhaar on file',
          keyboard: TextInputType.number,
          normalisation: _Normalise.digits,
          formatters: [AadhaarInputFormatter()],
          requiredToSubmit: true,
          validator: KycRules.aadhaar,
        ),
        _KycField(
          name: 'pan_number',
          label: 'PAN',
          hint: 'ABCDE1234F',
          helper: 'Your personal PAN — the 4th character is P.',
          sensitive: true,
          onFileLabel: 'PAN on file',
          normalisation: _Normalise.identifier,
          formatters: [IdentifierInputFormatter(10)],
          requiredToSubmit: true,
          validator: (value) => KycRules.pan(value, individual: true),
        ),
        const _KycField(
          name: 'id_proof_type',
          label: 'Other ID (optional)',
          taxonomy: Tax.idProofType,
        ),
        _KycField(
          name: 'id_proof_number',
          label: 'Other ID number',
          sensitive: true,
          onFileLabel: 'ID number on file',
          uppercase: true,
          removable: true,
          // Also refuses an Aadhaar number, however it is punctuated, and
          // points to the Aadhaar field above, which keeps only the last
          // four digits.
          validator: (value) =>
              KycRules.otherId(_choices['id_proof_type'], value),
        ),
      ],
    ),
    _KycSection(
      title: 'Payout',
      subtitle: 'Where your earnings are settled after each booking.',
      fields: [
        const _KycField(name: 'bank_account_name', label: 'Account holder'),
        const _KycField(
          name: 'bank_account_no',
          label: 'Account number',
          sensitive: true,
          onFileLabel: 'Account on file',
          keyboard: TextInputType.number,
          removable: true,
        ),
        _KycField(
          name: 'bank_ifsc',
          label: 'IFSC',
          hint: 'HDFC0001234',
          sensitive: true,
          onFileLabel: 'IFSC on file',
          uppercase: true,
          normalisation: _Normalise.identifier,
          validator: Validators.optionalIfsc,
          removable: true,
        ),
        const _KycField(name: 'bank_name', label: 'Bank name'),
        const _KycField(
          name: 'upi_id',
          label: 'UPI ID',
          hint: 'name@bank',
          sensitive: true,
          onFileLabel: 'UPI ID on file',
          removable: true,
        ),
      ],
    ),
    _KycSection(
      title: 'Work',
      fields: [
        _KycField(
          name: 'portfolio_url',
          label: 'Portfolio link',
          hint: 'https://',
          keyboard: TextInputType.url,
          validator: Validators.optionalUrl,
        ),
      ],
    ),
  ];
}

/// A numbered section heading, so a long form reads as a short sequence of
/// steps rather than one endless list.
class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.number,
    required this.title,
    this.subtitle,
  });

  final int number;
  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ExcludeSemantics(
          child: Container(
            width: 28,
            height: 28,
            margin: const EdgeInsets.only(top: 1),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              shape: BoxShape.circle,
            ),
            child: Text(
              '$number',
              style: AppTypography.labelMedium.copyWith(
                color: AppColors.primary,
              ),
            ),
          ),
        ),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: AppTypography.titleMedium.copyWith(fontSize: 17),
              ),
              if (subtitle != null) ...[
                const SizedBox(height: 2),
                Text(subtitle!, style: AppTypography.bodySmall),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

/// A group of fields rendered as one card.
class _KycSection {
  const _KycSection({required this.title, required this.fields, this.subtitle});

  final String title;
  final String? subtitle;
  final List<_KycField> fields;
}

/// How a value is compared and sent.
enum _Normalise {
  /// Trimmed.
  text,

  /// Digits only — Aadhaar is typed in groups of four but sent as twelve.
  digits,

  /// Upper-case, no spaces or hyphens — PAN, GSTIN, CIN, IFSC.
  identifier,
}

/// One input, either free text or a choice from an admin-managed list.
class _KycField {
  const _KycField({
    required this.name,
    required this.label,
    this.hint,
    this.helper,
    this.taxonomy,
    this.keyboard,
    this.maxLines = 1,
    this.uppercase = false,
    this.isDate = false,
    this.validator,
    this.sensitive = false,
    this.onFileLabel,
    this.normalisation = _Normalise.text,
    this.formatters,
    this.requiredToSubmit = false,
    this.requiredWhen,
    this.liveHelper = false,
    this.removable = false,
  });

  /// The API column this maps to.
  final String name;
  final String label;
  final String? hint;
  final String? helper;

  /// When set, the field renders as a chip row from this taxonomy.
  final String? taxonomy;

  final TextInputType? keyboard;
  final int maxLines;

  /// Tax and bank identifiers are stored upper-case server-side.
  final bool uppercase;

  final bool isDate;
  final String? Function(String?)? validator;

  /// Encrypted server-side and only ever returned masked: never prefilled,
  /// shown "on file" instead, and sent only when the user types a new one.
  final bool sensitive;

  /// "Aadhaar on file".
  final String? onFileLabel;

  final _Normalise normalisation;
  final List<TextInputFormatter>? formatters;

  /// Needed before the profile can be submitted (not before it can be saved).
  final bool requiredToSubmit;

  /// As [requiredToSubmit], when it depends on another answer.
  final bool Function(_KycDetailsScreenState state)? requiredWhen;

  /// Rebuild on every keystroke, because the helper text reads the value.
  final bool liveHelper;

  /// A sensitive number the user may take off file entirely ("Remove") —
  /// only ever an optional one, and only while it is not required.
  final bool removable;

  bool isRequired(_KycDetailsScreenState state) =>
      requiredToSubmit || (requiredWhen?.call(state) ?? false);

  String labelFor(_KycDetailsScreenState state) =>
      isRequired(state) ? '$label *' : label;

  String normalise(String value) => switch (normalisation) {
    _Normalise.text => value.trim(),
    _Normalise.digits => KycRules.digitsOnly(value),
    _Normalise.identifier => KycRules.normalize(value),
  };
}

/// Keeps IFSC and ID entry upper-case as the user types, matching how the
/// backend normalises them before encrypting.
class UpperCaseTextFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) => newValue.copyWith(text: newValue.text.toUpperCase());
}
