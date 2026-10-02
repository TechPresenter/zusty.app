import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart' show Uint8List;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../core/utils/json.dart';
import '../../models/kyc.dart';
import '../../models/user.dart' show KycStatus;
import '../../providers/kyc_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_timeline.dart';
import 'kyc_share_cache.dart';
import 'widgets/kyc_document_tile.dart';
import 'widgets/kyc_document_viewer.dart';
import 'widgets/kyc_processing_consent.dart';
import 'widgets/kyc_progress_ring.dart';
import 'widgets/kyc_status_style.dart';
import 'widgets/kyc_trust_cards.dart';

/// Verification hub — status, the blue tick, the document checklist, consent,
/// and the review trail.
///
/// The checklist is whatever Admin → KYC defines for this account type, so a
/// new document requirement appears here without an app release.
///
/// Laid out top to bottom in the order a user works through it: where things
/// stand (and, when a reviewer pushed back, why), the details form, the
/// documents, consent, what has happened so far. The submit action is pinned
/// to the bottom and says exactly what is still missing while it cannot go.
class KycStatusScreen extends ConsumerStatefulWidget {
  const KycStatusScreen({super.key});

  @override
  ConsumerState<KycStatusScreen> createState() => _KycStatusScreenState();
}

class _KycStatusScreenState extends ConsumerState<KycStatusScreen> {
  /// The slot currently uploading, so only its tile shows a spinner.
  String? _busySlot;

  /// The document open in the viewer.
  int? _previewingId;

  bool _submitting = false;

  /// The consent box. Starts unticked on every visit — see [KycConsentCard].
  bool _consent = false;
  String? _consentError;

  /// Server errors from a refused submission that belong to no single field
  /// on this screen ("Add your PAN before submitting").
  List<String> _submitErrors = const [];

  bool _optionalOpen = false;

  final _documentsKey = GlobalKey();
  final _consentKey = GlobalKey();
  final _slotKeys = <String, GlobalKey>{};

  GlobalKey _keyFor(String slot) => _slotKeys.putIfAbsent(slot, GlobalKey.new);

  @override
  void initState() {
    super.initState();

    // A reviewer may have acted since the overview was last read — this is
    // where a "View verification" notification lands — so opening the screen
    // re-reads it, quietly, keeping what is already on screen meanwhile.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) unawaited(ref.read(kycProvider.notifier).refreshQuietly());
    });
  }

  @override
  void dispose() {
    // Leaving verification: sweep any copy a document handed to another app
    // left in share_plus's folder, which cannot be removed while the other
    // app may still be reading it.
    unawaited(KycShareCache.purge());
    super.dispose();
  }

  /// Pull-to-refresh. A failure keeps the checklist and says so.
  Future<void> _refresh() async {
    await ref.read(kycProvider.notifier).refresh();
    if (!mounted) return;

    final state = ref.read(kycProvider);
    final error = state.error;
    if (state.hasValue && error != null) {
      showZSnack(
        context,
        error is ApiFailure
            ? error.message
            : 'Could not refresh. Please try again.',
        isError: true,
      );
    }
  }

  // -- Documents --------------------------------------------------------------

  /// Uploads a file into [requirement]'s slot — or, with [replacing], a new
  /// version of that document, which keeps the old one in the reviewer's
  /// history instead of deleting it.
  ///
  /// A refused upload (wrong type, too large, throttled, offline) is reported
  /// in a snackbar; the checklist stays exactly as it was.
  Future<void> _upload(
    KycRequirement requirement, {
    KycDocument? replacing,
  }) async {
    final profile = ref.read(kycProvider).valueOrNull?.profile;
    if (profile == null) return;

    // Only a masked Aadhaar is accepted: say so before the picker opens, not
    // after a reviewer has turned the upload down.
    if (KycSlots.isAadhaar(requirement.slot)) {
      final masked = await showZConfirm(
        context,
        title: 'Masked Aadhaar only',
        message:
            'Upload a masked Aadhaar, with the first 8 digits of the number '
            'hidden. You can download one from the UIDAI website. A copy '
            'showing the full number will be rejected.',
        confirmLabel: 'My copy is masked',
        cancelLabel: 'Not now',
        icon: Icons.privacy_tip_outlined,
      );
      if (!masked || !mounted) return;
    }

    // The first upload needs the user's agreement to processing at all.
    final consent = await ensureKycProcessingConsent(context, profile);
    if (consent == null || !mounted) return;

    final formats = requirement.allowedFormats;

    final file = await FilePicker.pickFile(
      type: formats.isEmpty ? FileType.any : FileType.custom,
      allowedExtensions: formats.isEmpty ? null : formats,
    );

    if (file == null) return;

    // Check the size here as well as server-side: a 40 MB upload that is going
    // to be refused should not cost the user their data allowance first.
    final size = file.lengthSync() ?? await file.length() ?? 0;

    if (size > requirement.maxSizeMb * 1024 * 1024) {
      if (mounted) {
        showZSnack(
          context,
          '${requirement.label} must be under ${requirement.maxSizeMb} MB.',
          isError: true,
        );
      }
      return;
    }

    String? expiresOn;

    if (requirement.requiresExpiry) {
      expiresOn = await _askExpiry(requirement);
      if (expiresOn == null) return;
    }

    if (!mounted) return;
    setState(() => _busySlot = requirement.slot);

    Future<void> send({required bool consent}) => _send(
          requirement,
          file,
          expiresOn: expiresOn,
          replacing: replacing,
          consent: consent,
        );

    try {
      try {
        await send(consent: consent);
      } on ValidationFailure catch (e) {
        // The server has no consent on record after all (a newer notice, or
        // an overview read before it changed): ask, then send it once more.
        final consentError = e.errorFor('consent');
        if (consentError == null || !mounted) rethrow;

        final agreed = await showKycProcessingConsent(
          context,
          text: profile.consentText,
          error: consentError,
        );
        if (!agreed) return;

        await send(consent: true);
      }

      if (mounted) {
        showZSnack(
          context,
          replacing == null
              ? '${requirement.label} uploaded.'
              : '${requirement.label} replaced.',
        );
      }
    } on ApiFailure catch (e) {
      if (mounted) {
        final fieldError = e.errors.values
            .expand((messages) => messages)
            .firstOrNull;
        showZSnack(context, fieldError ?? e.message, isError: true);
      }
    } finally {
      if (mounted) setState(() => _busySlot = null);
    }
  }

  Future<void> _send(
    KycRequirement requirement,
    PlatformFile file, {
    String? expiresOn,
    KycDocument? replacing,
    required bool consent,
  }) async {
    final controller = ref.read(kycProvider.notifier);

    // A local path streams from disk. Web, and Android documents handed back
    // as content:// URIs, have no path, so those are read into memory.
    final path = file.path;

    if (path != null) {
      await controller.uploadDocument(
        slot: requirement.slot,
        filePath: path,
        fileName: file.name,
        expiresOn: expiresOn,
        replaceDocumentId: replacing?.id,
        consent: consent,
      );
      return;
    }

    final Uint8List bytes;

    try {
      bytes = await file.readAsBytes();
    } catch (_) {
      throw const ServerFailure(message: 'That file could not be read.');
    }

    await controller.uploadDocumentBytes(
      slot: requirement.slot,
      bytes: bytes,
      fileName: file.name,
      expiresOn: expiresOn,
      replaceDocumentId: replacing?.id,
      consent: consent,
    );
  }

  /// Asks for the document's expiry, which the reviewer needs for anything
  /// time-limited (a passport, a trade licence).
  Future<String?> _askExpiry(KycRequirement requirement) async {
    final now = DateTime.now();

    final picked = await showDatePicker(
      context: context,
      helpText: '${requirement.label} — expires on',
      initialDate: DateTime(now.year + 1, now.month, now.day),
      firstDate: now,
      lastDate: DateTime(now.year + 30),
    );

    return picked == null ? null : DateFormat('yyyy-MM-dd').format(picked);
  }

  Future<void> _preview(KycDocument document) async {
    // The viewer opens straight away and shows its own progress; the button
    // stays busy until it closes, so a second tap cannot open a second one.
    if (_previewingId != null) return;
    setState(() => _previewingId = document.id);

    try {
      await openKycDocument(context, ref, document);
    } finally {
      if (mounted && _previewingId == document.id) {
        setState(() => _previewingId = null);
      }
    }
  }

  Future<void> _delete(KycDocument document) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Remove this document?',
      message:
          '${document.displayName} will be deleted and you will need to '
          'upload it again before submitting.',
      confirmLabel: 'Remove',
      cancelLabel: 'Keep',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );

    if (!confirmed) return;

    try {
      await ref.read(kycProvider.notifier).deleteDocument(document.id);
      if (mounted) showZSnack(context, 'Document removed.');
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    }
  }

  // -- Submission ---------------------------------------------------------------

  Future<void> _submit() async {
    if (!_consent) {
      setState(
        () => _consentError =
            'Tick the box to give your consent before submitting.',
      );
      _scrollTo(_consentKey);
      return;
    }

    // Submitting locks the form until a reviewer acts, so it is worth one
    // deliberate tap more.
    final confirmed = await showZConfirm(
      context,
      title: 'Submit for review?',
      message:
          'Your details and documents lock while our team reviews them. '
          'Most reviews finish within one business day.',
      confirmLabel: 'Submit',
      icon: Icons.verified_user_rounded,
    );
    if (!confirmed || !mounted) return;

    setState(() {
      _submitting = true;
      _submitErrors = const [];
    });

    try {
      await ref.read(kycProvider.notifier).submit(consent: true);
      if (mounted) {
        setState(() => _consent = false);
        await _showSubmitted();
      }
    } on ValidationFailure catch (e) {
      if (!mounted) return;

      final consentError = e.errorFor('consent');
      setState(() {
        _consentError = consentError;
        if (consentError != null) _consent = false;
        _submitErrors = [
          for (final entry in e.errors.entries)
            if (entry.key != 'consent') ...entry.value,
        ];
      });

      showZSnack(context, e.message, isError: true);
      _scrollTo(_consentKey);
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  /// The confirmation after submitting: what happened and what comes next.
  Future<void> _showSubmitted() {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      sheetAnimationStyle: zSheetMotion(context),
      builder: (sheetContext) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.lg,
          AppSpacing.md,
          AppSpacing.lg,
          AppSpacing.lg,
        ),
        child: SuccessView(
          title: 'Submitted for review',
          message:
              'We will notify you the moment there is a decision. '
              'You can follow progress here at any time.',
          primaryLabel: 'Done',
          onPrimary: () => Navigator.of(sheetContext).pop(),
        ),
      ),
    );
  }

  /// "Fix and resubmit": straight to the first thing the reviewer flagged.
  void _fixAndResubmit(KycOverview overview) {
    final flagged = overview.requirements.where(
      (req) => overview.slotState(req).needsAttention,
    );

    if (flagged.isNotEmpty) {
      final first = flagged.first;
      if (!overview.isRequired(first) && !_optionalOpen) {
        setState(() => _optionalOpen = true);
      }
      // After the optional group has had a frame to open.
      WidgetsBinding.instance.addPostFrameCallback(
        (_) => _scrollTo(_keyFor(first.slot)),
      );
      return;
    }

    if (_missingDetails(overview).isNotEmpty) {
      context.push(Routes.kycDetails);
      return;
    }

    _scrollTo(_documentsKey);
  }

  void _scrollTo(GlobalKey key) {
    final target = key.currentContext;
    if (target == null) return;

    Scrollable.ensureVisible(
      target,
      alignment: 0.1,
      duration: Motion.of(context, Motion.medium),
      curve: Motion.standard,
    );
  }

  // -- Build ----------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(kycProvider);
    final data = overview.valueOrNull;

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Verification'),
      bottomBar: data?.profile.isEditable == true
          ? _SubmitBar(
              overview: data!,
              consent: _consent,
              submitting: _submitting,
              onSubmit: _submit,
            )
          : null,
      // `skipError`: once there is an overview, a failed refresh is reported
      // beside it (see [_buildBody]) instead of replacing the checklist with
      // an error page. The error page is only for a first load that failed.
      body: overview.when(
        skipError: true,
        data: (data) => _buildBody(
          data,
          staleError: overview.hasError ? overview.error : null,
        ),
        loading: () => const ListSkeleton(
          count: 4,
          height: 96,
          padding: EdgeInsets.all(AppSpacing.md),
        ),
        error: (error, _) => ErrorState(
          error: error,
          onRetry: () => ref.read(kycProvider.notifier).refresh(),
        ),
      ),
    );
  }

  Widget _buildBody(KycOverview overview, {Object? staleError}) {
    final margin = AppBreakpoints.pageMargin(context);
    final profile = overview.profile;
    final state = profile.state;
    final note = profile.reviewerNote;

    final required = overview.requiredRequirements;
    final optional = overview.optionalRequirements;
    final requiredDone = required
        .where(
          (req) => switch (overview.slotState(req)) {
            KycSlotState.uploaded || KycSlotState.approved => true,
            _ => false,
          },
        )
        .length;

    final flagged = overview.requirements
        .where((req) => overview.slotState(req).needsAttention)
        .toList();

    // The optional group opens itself when something in it needs a look.
    final optionalOpen =
        _optionalOpen ||
        optional.any(
          (req) =>
              overview.slotState(req).needsAttention ||
              overview.documentsFor(req.slot).isNotEmpty,
        );

    var reveal = 0;

    return ZRefresh(
      onRefresh: _refresh,
      child: ListView(
        padding: EdgeInsets.fromLTRB(
          margin,
          AppSpacing.md,
          margin,
          AppSpacing.xl,
        ),
        children: [
          if (staleError != null) ...[
            ZInfoBanner(
              tone: StatusTone.warning,
              icon: staleError is OfflineFailure
                  ? Icons.wifi_off_rounded
                  : Icons.sync_problem_rounded,
              message: staleError is OfflineFailure
                  ? 'You are offline. This is your verification as it was '
                        'last loaded.'
                  : 'We could not refresh your verification. This is how it '
                        'was last loaded.',
              actionLabel: 'Retry',
              onAction: _refresh,
            ),
            const SizedBox(height: AppSpacing.sm),
          ],
          Reveal(
            index: reveal++,
            child: _StatusHeader(
              profile: profile,
              completeness: overview.completeness,
            ),
          ),

          // -- What the reviewer said ------------------------------------------
          if (_showsReviewerBanner(state)) ...[
            const SizedBox(height: AppSpacing.sm),
            _ReviewerBanner(
              tone: state == KycStatus.actionNeeded
                  ? StatusTone.warning
                  : StatusTone.error,
              title: switch (state) {
                KycStatus.actionNeeded => 'Action needed',
                KycStatus.suspended => 'Verification on hold',
                _ => 'Not approved',
              },
              reason: note != null && note.isNotEmpty
                  ? note
                  : state.explanation,
              toFix: [for (final req in flagged) req.label],
              onFix: profile.isEditable || flagged.isNotEmpty
                  ? () => _fixAndResubmit(overview)
                  : null,
            ),
          ],

          // -- Blue tick ---------------------------------------------------------
          const SizedBox(height: AppSpacing.sm),
          Reveal(
            index: reveal++,
            child: KycBlueTickCard(profile: profile),
          ),

          // -- Details -----------------------------------------------------------
          const SizedBox(height: AppSpacing.lg),
          SectionHeader(
            title: overview.isBrand ? 'Business details' : 'Your details',
          ),
          const SizedBox(height: AppSpacing.xs),
          Reveal(
            index: reveal++,
            child: _DetailsCard(
              isBrand: overview.isBrand,
              isEditable: profile.isEditable,
              missing: !overview.canSubmit && profile.isEditable
                  ? _missingDetails(overview)
                  : const [],
              onTap: () => context.push(Routes.kycDetails),
            ),
          ),

          // -- Documents ---------------------------------------------------------
          const SizedBox(height: AppSpacing.lg),
          Row(
            key: _documentsKey,
            children: [
              Expanded(
                child: Text(
                  'Documents',
                  style: AppTypography.titleMedium.copyWith(fontSize: 17),
                ),
              ),
              if (required.isNotEmpty)
                Flexible(
                  child: StatusChip(
                    label: '$requiredDone of ${required.length} required',
                    tone: requiredDone == required.length
                        ? StatusTone.success
                        : StatusTone.neutral,
                    dense: true,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 2),
          Text(
            'Clear photos or scans of the originals. Each one is checked by '
            'a person, not a machine.',
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.sm),
          if (overview.requirements.isEmpty)
            const EmptyState(
              icon: Icons.folder_open_rounded,
              title: 'Nothing to upload',
              message: 'No documents are required for your account right now.',
              compact: true,
            )
          else ...[
            for (final requirement in required)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Reveal(
                  index: reveal++,
                  child: _tileFor(overview, requirement),
                ),
              ),
            if (optional.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.xs),
              _OptionalHeader(
                count: optional.length,
                open: optionalOpen,
                onTap: () => setState(() => _optionalOpen = !optionalOpen),
              ),
              AnimatedSize(
                duration: Motion.of(context, Motion.medium),
                curve: Motion.standard,
                alignment: Alignment.topCenter,
                child: optionalOpen
                    ? Column(
                        children: [
                          const SizedBox(height: AppSpacing.sm),
                          for (final requirement in optional)
                            Padding(
                              padding: const EdgeInsets.only(
                                bottom: AppSpacing.sm,
                              ),
                              child: _tileFor(overview, requirement),
                            ),
                        ],
                      )
                    : const SizedBox(width: double.infinity),
              ),
            ],
          ],

          // -- Consent -----------------------------------------------------------
          if (profile.isEditable) ...[
            const SizedBox(height: AppSpacing.lg),
            SectionHeader(
              key: _consentKey,
              title: 'Consent',
              subtitle: 'Needed each time you submit.',
            ),
            const SizedBox(height: AppSpacing.sm),
            if (_submitErrors.isNotEmpty) ...[
              ZInfoBanner(
                tone: StatusTone.error,
                title: 'Fix these before submitting',
                message: _submitErrors.join('\n'),
              ),
              const SizedBox(height: AppSpacing.sm),
            ],
            KycConsentCard(
              value: _consent,
              text: profile.consentText,
              errorText: _consentError,
              lastConsentAt: profile.consentAt,
              onChanged: _submitting
                  ? null
                  : (value) => setState(() {
                      _consent = value;
                      if (value) _consentError = null;
                    }),
            ),
          ],

          // -- Review trail ------------------------------------------------------
          if (overview.timeline.isNotEmpty || profile.isUnderReview) ...[
            const SizedBox(height: AppSpacing.lg),
            const SectionHeader(title: 'Review trail'),
            const SizedBox(height: AppSpacing.sm),
            ZCard(child: _ReviewTrail(overview: overview)),
          ],

          // -- Privacy -----------------------------------------------------------
          const SizedBox(height: AppSpacing.lg),
          const KycPrivacyNotice(),
        ],
      ),
    );
  }

  bool _showsReviewerBanner(KycStatus state) => switch (state) {
    KycStatus.actionNeeded || KycStatus.rejected || KycStatus.suspended => true,
    _ => false,
  };

  Widget _tileFor(KycOverview overview, KycRequirement requirement) {
    final state = overview.slotState(requirement);
    final documents = overview.documentsFor(requirement.slot);

    return KycDocumentTile(
      key: _keyFor(requirement.slot),
      requirement: requirement,
      state: state,
      documents: documents,
      rejectionReason: overview.rejectionReasonFor(requirement),
      requirementNote: _requirementNote(overview, requirement),
      isBusy: _busySlot == requirement.slot,
      canUpload: overview.canUploadTo(requirement),
      canRemove: overview.profile.isEditable,
      previewingId: _previewingId,
      onUpload: () => _upload(requirement),
      onReplace: (document) => _upload(requirement, replacing: document),
      onPreview: _preview,
      onRemove: _delete,
    );
  }

  /// Explains a slot whose need depends on the company type.
  ///
  /// Until a type is chosen there is nothing to add: the slot's own
  /// description already says which companies need it.
  String? _requirementNote(KycOverview overview, KycRequirement requirement) {
    if (requirement.slot != KycSlots.incorporationCertificate) return null;

    final type = overview.companyType;
    if (type == null) return null;

    return KycCompanyTypes.requiresCin(type)
        ? 'Needed because your company type has a CIN.'
        : 'Not needed for your company type.';
  }
}

/// The detail fields a submission cannot go without that are still blank.
///
/// Only a hint for the details card — the server's `can_submit` is what the
/// button follows.
List<String> _missingDetails(KycOverview overview) {
  final details = overview.details;
  bool blank(String key) => Json.strOrNull(details[key]) == null;

  if (overview.isBrand) {
    return [
      if (blank('company_name')) 'company name',
      if (blank('company_type')) 'company type',
      if (overview.maskedValue('gst_number') == null) 'GSTIN',
      if (KycCompanyTypes.requiresCin(overview.companyType) &&
          overview.maskedValue('cin_number') == null)
        'CIN',
      if (blank('registered_address')) 'registered address',
    ];
  }

  return [
    if (blank('full_name')) 'full name',
    if (blank('date_of_birth')) 'date of birth',
    if (overview.maskedValue('aadhaar_number') == null &&
        blank('aadhaar_last4'))
      'Aadhaar number',
    if (overview.maskedValue('pan_number') == null) 'PAN',
  ];
}

/// Headline status: a completeness ring, where the profile stands, and what
/// that means for the user.
class _StatusHeader extends StatelessWidget {
  const _StatusHeader({required this.profile, required this.completeness});

  final KycProfile profile;
  final int completeness;

  @override
  Widget build(BuildContext context) {
    final state = profile.state;

    return ZCard(
      padding: EdgeInsets.zero,
      clip: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  profile.isApproved
                      ? AppColors.successContainer
                      : AppColors.primaryFixed,
                  AppColors.surfaceContainerLowest,
                ],
              ),
            ),
            child: Row(
              children: [
                KycProgressRing(
                  percent: profile.isApproved ? 100 : completeness,
                  complete: profile.isApproved,
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(state.headline, style: AppTypography.headlineSmall),
                      const SizedBox(height: AppSpacing.xs),
                      StatusChip(
                        label: profile.displayLabel,
                        tone: state.tone,
                        icon: state.icon,
                        dense: true,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.md,
              AppSpacing.sm,
              AppSpacing.md,
              AppSpacing.md,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  state.explanation,
                  style: AppTypography.bodyMedium.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
                if (profile.badges.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Wrap(
                    spacing: AppSpacing.xs,
                    runSpacing: AppSpacing.xs,
                    children: [
                      for (final badge in profile.badges)
                        StatusChip(
                          label: badge.label,
                          tone: StatusTone.success,
                          icon: Icons.verified_user_rounded,
                          dense: true,
                        ),
                    ],
                  ),
                ],
                if (profile.expiresOn != null ||
                    profile.reference.isNotEmpty ||
                    profile.submittedAt != null) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    [
                      if (profile.reference.isNotEmpty)
                        'Ref ${profile.reference}',
                      if (kycDate(profile.submittedAt) case final date?)
                        'Submitted $date',
                      if (profile.expiresOn != null)
                        'Valid until '
                            '${kycDate(profile.expiresOn) ?? profile.expiresOn}',
                    ].join('  ·  '),
                    style: AppTypography.labelSmall.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
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

/// What the reviewer said, what to fix, and the way to fix it.
///
/// Its own banner rather than the shared one because the action sits under
/// the reason: beside it, "Fix and resubmit" squeezed a long reviewer note
/// into a sliver on a small phone at large text sizes.
class _ReviewerBanner extends StatelessWidget {
  const _ReviewerBanner({
    required this.tone,
    required this.title,
    required this.reason,
    required this.toFix,
    this.onFix,
  });

  final StatusTone tone;
  final String title;
  final String reason;

  /// Labels of the slots the reviewer flagged.
  final List<String> toFix;
  final VoidCallback? onFix;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = StatusChip.colorsFor(tone);

    return Container(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: background,
        borderRadius: AppRadius.lgAll,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                tone == StatusTone.warning
                    ? Icons.assignment_late_outlined
                    : Icons.error_outline_rounded,
                size: 20,
                color: foreground,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: AppTypography.titleSmall.copyWith(
                        color: foreground,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      reason,
                      style: AppTypography.bodySmall.copyWith(
                        color: foreground,
                      ),
                    ),
                    if (toFix.isNotEmpty) ...[
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        'To fix: ${toFix.join(', ')}',
                        style: AppTypography.labelMedium.copyWith(
                          color: foreground,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (onFix != null)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: onFix,
                style: TextButton.styleFrom(
                  foregroundColor: foreground,
                  minimumSize: const Size(AppSpacing.minTouch, 44),
                ),
                icon: const Icon(Icons.arrow_forward_rounded, size: 18),
                label: Text(
                  'Fix and resubmit',
                  style: AppTypography.labelLarge.copyWith(color: foreground),
                ),
              ),
            )
          else
            const SizedBox(height: AppSpacing.xs),
        ],
      ),
    );
  }
}

/// The entry into the details form.
class _DetailsCard extends StatelessWidget {
  const _DetailsCard({
    required this.isBrand,
    required this.isEditable,
    required this.missing,
    required this.onTap,
  });

  final bool isBrand;
  final bool isEditable;

  /// Required details still blank, in reading order.
  final List<String> missing;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final subtitle = !isEditable
        ? 'Locked while under review'
        : missing.isEmpty
        ? 'Review and edit what you have entered'
        : 'Still needed: ${missing.join(', ')}';

    return ZCard(
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(
              isBrand ? Icons.business_rounded : Icons.badge_rounded,
              size: 22,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  isBrand
                      ? 'Company, GST and CIN'
                      : 'Identity, Aadhaar, PAN and payout',
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: AppTypography.bodySmall.copyWith(
                    color: missing.isEmpty || !isEditable
                        ? null
                        : AppColors.onWarningContainer,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Icon(
            isEditable ? Icons.chevron_right_rounded : Icons.lock_rounded,
            size: isEditable ? 24 : 18,
            color: AppColors.onSurfaceVariant,
          ),
        ],
      ),
    );
  }
}

/// The collapsible heading over the optional documents, so the slots that
/// matter are not buried under eight that do not.
class _OptionalHeader extends StatelessWidget {
  const _OptionalHeader({
    required this.count,
    required this.open,
    required this.onTap,
  });

  final int count;
  final bool open;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      expanded: open,
      label: 'Optional documents, $count',
      excludeSemantics: true,
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          onTap: onTap,
          borderRadius: AppRadius.mdAll,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: AppSpacing.minTouch),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Optional', style: AppTypography.titleSmall),
                      Text(
                        '$count more you can add to strengthen your profile',
                        style: AppTypography.bodySmall,
                      ),
                    ],
                  ),
                ),
                AnimatedRotation(
                  turns: open ? 0.5 : 0,
                  duration: Motion.of(context, Motion.fast),
                  child: Icon(
                    Icons.expand_more_rounded,
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The audit trail as a timeline, newest first — the order the API sends it.
///
/// While a reviewer has the profile, a pending "Decision" step sits on top so
/// the trail shows what is coming as well as what has happened.
class _ReviewTrail extends StatelessWidget {
  const _ReviewTrail({required this.overview});

  final KycOverview overview;

  @override
  Widget build(BuildContext context) {
    final profile = overview.profile;

    return ZTimeline(
      steps: [
        if (profile.isUnderReview)
          const TimelineStep(
            title: 'Decision',
            subtitle:
                'With our verification team. Most reviews finish '
                'within one business day.',
            state: TimelineState.current,
          ),
        for (final event in overview.timeline)
          TimelineStep(
            title: event.label,
            subtitle: event.note,
            time: [
              event.actorLabel,
              if (event.createdAt != null) _when(event.createdAt!),
            ].join(' · '),
          ),
      ],
    );
  }

  static String _when(String raw) {
    final parsed = Formatters.parseDate(raw);
    return parsed == null ? raw : Formatters.dateTime(parsed);
  }
}

/// The pinned submit action, with the reason it cannot go spelled out.
class _SubmitBar extends StatelessWidget {
  const _SubmitBar({
    required this.overview,
    required this.consent,
    required this.submitting,
    required this.onSubmit,
  });

  final KycOverview overview;
  final bool consent;
  final bool submitting;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) {
    final missing = overview.missingRequirements.length;

    final hint = !overview.canSubmit
        ? missing > 0
              ? 'Upload $missing more required '
                    '${missing == 1 ? 'document' : 'documents'} to submit.'
              : 'Complete your details to submit.'
        : !consent
        ? 'Tick the consent box to submit.'
        : null;

    final resubmitting =
        overview.profile.submissionCount > 0 ||
        switch (overview.profile.state) {
          KycStatus.actionNeeded ||
          KycStatus.rejected ||
          KycStatus.expired => true,
          _ => false,
        };

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (hint != null) ...[
          Text(
            hint,
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xs),
        ],
        ZButton(
          label: resubmitting ? 'Resubmit for review' : 'Submit for review',
          icon: Icons.verified_user_rounded,
          isLoading: submitting,
          // Stays tappable without consent: the tap takes the user to the
          // box rather than leaving them to guess why nothing happens.
          onPressed: overview.canSubmit ? onSubmit : null,
        ),
      ],
    );
  }
}
