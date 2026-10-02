import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/campaign.dart';
import '../../providers/opportunity_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import '../kyc/widgets/kyc_gate.dart';
import 'widgets/opportunity_states.dart';

/// The application form: a pitch, a rate, optional portfolio links, and an
/// acknowledgement — then a confirmation in place of the form.
///
/// Everything the server checks is checked here first (pitch length, a rate
/// inside the brief's budget, well-formed links), so most mistakes are caught
/// before a request is spent. What only the server can know — a KYC gate, a
/// brief that closed a minute ago — comes back as an inline banner, and a 422
/// lands on the exact field it names.
class ApplyScreen extends ConsumerStatefulWidget {
  const ApplyScreen({super.key, required this.campaignId});

  final int campaignId;

  @override
  ConsumerState<ApplyScreen> createState() => _ApplyScreenState();
}

class _ApplyScreenState extends ConsumerState<ApplyScreen> {
  /// The server allows 2,000; below 30 a pitch says nothing a brand can act on.
  static const _pitchMin = 30;
  static const _pitchMax = 2000;
  static const _maxLinks = 3;

  final _formKey = GlobalKey<FormState>();
  final _pitch = TextEditingController();
  final _rate = TextEditingController();
  final List<TextEditingController> _links = [TextEditingController()];

  bool _agreed = false;
  bool _submitting = false;
  bool _triedSubmit = false;

  /// Field errors from a 422, keyed the way the form names its fields
  /// (`pitch`, `quoted_amount`, `link.N`). Cleared per field as it is edited.
  Map<String, String> _serverErrors = const {};

  /// A failure that is not about one field: KYC, a closed brief, a conflict.
  ApiFailure? _formError;

  /// Set once the application is accepted; the form gives way to the
  /// confirmation.
  MyApplication? _sent;

  @override
  void initState() {
    super.initState();
    _pitch.addListener(_onPitchChanged);
  }

  @override
  void dispose() {
    _pitch.removeListener(_onPitchChanged);
    _pitch.dispose();
    _rate.dispose();
    for (final link in _links) {
      link.dispose();
    }
    super.dispose();
  }

  // The character counter under the pitch follows every keystroke.
  void _onPitchChanged() => setState(() {});

  void _clearServerError(String field) {
    if (!_serverErrors.containsKey(field)) return;
    setState(() => _serverErrors = {..._serverErrors}..remove(field));
  }

  @override
  Widget build(BuildContext context) {
    final campaign = ref.watch(opportunityProvider(widget.campaignId));
    final isCreator = ref.watch(isCreatorSessionProvider);
    final showForm = isCreator &&
        _sent == null &&
        (campaign.valueOrNull?.canApply ?? false);

    return ZScaffold(
      padded: false,
      appBar: ZAppBar(title: _sent == null ? 'Apply' : 'Application sent'),
      bottomBar: showForm
          ? ZButton(
              label: 'Send application',
              icon: Icons.send_rounded,
              isLoading: _submitting,
              onPressed: () => _submit(campaign.requireValue),
            )
          : null,
      body: CreatorOnlyGate(
        child: campaign.when(
          loading: () => const ZLoader(),
          error: (error, _) => isKycGate(error)
              ? KycGateView(failure: error as ApiFailure)
              : OpportunityErrorView(
                  error: error,
                  onRetry: () =>
                      ref.invalidate(opportunityProvider(widget.campaignId)),
                ),
          data: (data) {
            if (_sent != null) return _success(data, _sent!);
            if (!data.canApply) return _cannotApply(data);
            return _form(data);
          },
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // States
  // ---------------------------------------------------------------------------

  Widget _cannotApply(Campaign campaign) {
    final application = campaign.myApplication;

    if (application != null) {
      return EmptyState(
        icon: application.icon,
        title: 'You\'ve already applied',
        message: application.explanation,
        actionLabel: 'View application',
        onAction: () => context.canPop()
            ? context.pop()
            : context.go(Routes.opportunity(campaign.id)),
      );
    }

    return EmptyState(
      icon: Icons.lock_clock_outlined,
      title: 'This brief is closed',
      message: 'The brand is no longer taking applications for it. There are '
          'more open briefs on the board.',
      actionLabel: 'Browse opportunities',
      onAction: () => context.go(Routes.creatorCampaigns),
    );
  }

  Widget _success(Campaign campaign, MyApplication application) {
    final brand = campaign.brand?.name ?? 'The brand';
    final margin = AppBreakpoints.pageMargin(context);

    return SingleChildScrollView(
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.xl, margin, AppSpacing.xl),
      child: SuccessView(
        title: 'Application sent',
        message: '$brand will review your pitch. We\'ll notify you the moment '
            'they shortlist, hire or pass.',
        details: ZCard(
          shadow: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // The title gets its own lines: a detail row keeps its value on
              // one unbroken line, which a long brief title would overflow.
              Text(
                campaign.title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.titleSmall,
              ),
              const SizedBox(height: AppSpacing.xs),
              if (application.quoteLabel != null)
                ZDetailRow(
                  label: 'Your rate',
                  value: application.quoteLabel!,
                  emphasise: true,
                ),
              ZDetailRow(label: 'Status', value: application.label),
            ],
          ),
        ),
        primaryLabel: 'Track application',
        onPrimary: () => context.pushReplacement(Routes.applications),
        secondaryLabel: 'Browse more',
        onSecondary: () => context.go(Routes.creatorCampaigns),
      ),
    );
  }

  Widget _form(Campaign campaign) {
    final margin = AppBreakpoints.pageMargin(context);

    return Form(
      key: _formKey,
      autovalidateMode: _triedSubmit
          ? AutovalidateMode.onUserInteraction
          : AutovalidateMode.disabled,
      child: ListView(
        padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, AppSpacing.xl),
        children: [
          _Summary(campaign: campaign),
          if (_formError != null) ...[
            const SizedBox(height: AppSpacing.md),
            Reveal(child: _FormErrorBanner(failure: _formError!)),
          ],
          const SizedBox(height: AppSpacing.lg),
          _pitchSection(campaign),
          const SizedBox(height: AppSpacing.lg),
          _rateSection(campaign),
          const SizedBox(height: AppSpacing.lg),
          _linksSection(),
          const SizedBox(height: AppSpacing.lg),
          _TermsCheck(
            value: _agreed,
            showError: _triedSubmit && !_agreed,
            onChanged: (value) => setState(() => _agreed = value),
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Sections
  // ---------------------------------------------------------------------------

  Widget _pitchSection(Campaign campaign) {
    final length = _pitch.text.trim().length;
    final enough = length >= _pitchMin;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          title: 'Why are you a good fit?',
          subtitle: 'Your audience, similar work you have done, and how you '
              'would bring this brief to life.',
        ),
        const SizedBox(height: AppSpacing.sm),
        ZTextField(
          controller: _pitch,
          hint: 'e.g. My audience is 70% women aged 22–30 in Mumbai and '
              'Pune. Last month my skincare reel for…',
          maxLines: 8,
          minLines: 5,
          maxLength: _pitchMax,
          textCapitalization: TextCapitalization.sentences,
          errorText: _serverErrors['pitch'],
          onChanged: (_) => _clearServerError('pitch'),
          validator: (value) {
            final text = value?.trim() ?? '';
            if (text.isEmpty) return 'Tell the brand why you are a good fit.';
            if (text.length < _pitchMin) {
              return 'Write at least $_pitchMin characters '
                  '(${_pitchMin - text.length} to go).';
            }
            return null;
          },
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            Icon(
              enough ? Icons.check_circle_rounded : Icons.info_outline_rounded,
              size: 14,
              color: enough ? AppColors.success : AppColors.onSurfaceVariant,
            ),
            const SizedBox(width: 4),
            Expanded(
              child: Text(
                enough ? 'Looks good' : 'At least $_pitchMin characters',
                style: AppTypography.labelSmall.copyWith(
                  color: enough ? AppColors.success : AppColors.onSurfaceVariant,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
            Text(
              '${_pitch.text.length}/$_pitchMax',
              style: AppTypography.labelSmall.copyWith(
                color: _pitch.text.length > _pitchMax * 0.9
                    ? AppColors.onWarningContainer
                    : AppColors.onSurfaceVariant,
                fontWeight: FontWeight.w500,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _rateSection(Campaign campaign) {
    final max = campaign.budgetMax;
    final min = campaign.budgetMin;
    final hasBudget = max > 0;

    // Quick picks across the advertised range: the floor, the middle, the
    // ceiling. Rounded to the nearest 500 so the middle is a believable rate.
    final picks = <double>{
      if (min > 0) min,
      if (hasBudget && min > 0 && max > min) ((min + max) / 2 / 500).round() * 500,
      if (hasBudget) max,
    }.toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _SectionTitle(
          title: 'Your rate',
          subtitle: hasBudget
              ? 'The brand\'s budget is ${campaign.displayBudget} for the '
                  'whole brief.'
              : 'What you would charge for the whole brief.',
        ),
        const SizedBox(height: AppSpacing.sm),
        ZTextField(
          controller: _rate,
          hint: hasBudget ? 'Up to ${Formatters.currency(max)}' : 'Amount',
          prefixIcon: Icons.currency_rupee_rounded,
          keyboardType: TextInputType.number,
          textInputAction: TextInputAction.next,
          inputFormatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(9),
          ],
          helper: hasBudget
              ? 'Budget: ${campaign.displayBudget}'
              : null,
          errorText: _serverErrors['quoted_amount'],
          onChanged: (_) => _clearServerError('quoted_amount'),
          validator: (value) {
            final amount = double.tryParse(value?.trim() ?? '');
            if (amount == null) return 'Enter your rate.';
            if (amount <= 0) return 'Your rate must be more than zero.';
            if (hasBudget && amount > max) {
              return 'This brief\'s budget tops out at '
                  '${Formatters.currency(max)}.';
            }
            return null;
          },
        ),
        if (picks.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              for (final pick in picks)
                Semantics(
                  button: true,
                  label: 'Use ${Formatters.currency(pick)}',
                  excludeSemantics: true,
                  child: ZChip(
                    label: Formatters.currencyCompact(pick),
                    selected: _rate.text == pick.toStringAsFixed(0),
                    onTap: () {
                      HapticFeedback.selectionClick();
                      setState(() => _rate.text = pick.toStringAsFixed(0));
                      _clearServerError('quoted_amount');
                    },
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _linksSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionTitle(
          title: 'Portfolio links',
          subtitle: 'Optional. Up to three posts, reels or videos that show '
              'what you would make for this brief.',
        ),
        const SizedBox(height: AppSpacing.sm),
        for (var i = 0; i < _links.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.sm),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: ZTextField(
                    controller: _links[i],
                    hint: 'https://instagram.com/p/…',
                    prefixIcon: Icons.link_rounded,
                    keyboardType: TextInputType.url,
                    textInputAction: TextInputAction.next,
                    errorText: _serverErrors['link.$i'],
                    onChanged: (_) => _clearServerError('link.$i'),
                    validator: _validateLink,
                  ),
                ),
                if (_links.length > 1)
                  IconButton(
                    tooltip: 'Remove link',
                    constraints: const BoxConstraints(
                      minWidth: AppSpacing.minTouch,
                      minHeight: AppSpacing.minTouch + 8,
                    ),
                    icon: Icon(
                      Icons.remove_circle_outline_rounded,
                      color: AppColors.onSurfaceVariant,
                    ),
                    onPressed: () => setState(() {
                      _links.removeAt(i).dispose();
                      _serverErrors = const {};
                    }),
                  ),
              ],
            ),
          ),
        if (_links.length < _maxLinks)
          TextButton.icon(
            onPressed: () =>
                setState(() => _links.add(TextEditingController())),
            style: TextButton.styleFrom(
              minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
            ),
            icon: const Icon(Icons.add_rounded, size: 18),
            label: Text(
              'Add another link',
              style: AppTypography.labelLarge.copyWith(color: AppColors.primary),
            ),
          ),
      ],
    );
  }

  /// Optional, but when given it must be a full http(s) URL — the same rule
  /// the server applies, so a link that passes here passes there.
  static String? _validateLink(String? value) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return null;
    final uri = Uri.tryParse(text);
    final ok = uri != null &&
        (uri.scheme == 'http' || uri.scheme == 'https') &&
        uri.host.contains('.');
    return ok ? null : 'Enter a full link starting with https://';
  }

  // ---------------------------------------------------------------------------
  // Submit
  // ---------------------------------------------------------------------------

  Future<void> _submit(Campaign campaign) async {
    FocusScope.of(context).unfocus();
    setState(() {
      _triedSubmit = true;
      _formError = null;
      _serverErrors = const {};
    });

    final valid = _formKey.currentState?.validate() ?? false;
    if (!valid || !_agreed) {
      HapticFeedback.mediumImpact();
      if (valid && !_agreed) {
        showZSnack(context, 'Please confirm the terms to send your application.',
            isError: true);
      }
      return;
    }

    // Which form field each submitted link came from, so a server error on
    // `portfolio_links.N` (N counts only the links sent) lands on the right
    // text box.
    final sentFrom = <int>[];
    final links = <String>[];
    for (var i = 0; i < _links.length; i++) {
      final text = _links[i].text.trim();
      if (text.isNotEmpty) {
        sentFrom.add(i);
        links.add(text);
      }
    }

    setState(() => _submitting = true);

    try {
      final application = await ref.read(opportunityActionsProvider).apply(
            campaign.id,
            quotedAmount: double.parse(_rate.text.trim()),
            pitch: _pitch.text.trim(),
            portfolioLinks: links,
          );
      if (!mounted) return;
      HapticFeedback.lightImpact();
      setState(() {
        _submitting = false;
        _sent = application;
      });
    } on ApiFailure catch (failure) {
      if (!mounted) return;
      final fieldErrors = _mapFieldErrors(failure, sentFrom);
      final kycGate = isKycGate(failure);
      setState(() {
        _submitting = false;
        // Errors that name a field are shown on that field. The KYC gate gets
        // a banner with a way through it; anything else is a toast, since
        // the creator can only read it and try again.
        _serverErrors = fieldErrors;
        _formError = kycGate ? failure : null;
      });
      if (fieldErrors.isEmpty && !kycGate) {
        showZSnack(context, failure.message, isError: true);
      } else if (fieldErrors.isNotEmpty) {
        HapticFeedback.mediumImpact();
      }
    }
  }

  static Map<String, String> _mapFieldErrors(
    ApiFailure failure,
    List<int> sentFrom,
  ) {
    final mapped = <String, String>{};
    failure.errors.forEach((field, messages) {
      if (messages.isEmpty) return;
      final message = messages.first;
      if (field == 'pitch' || field == 'quoted_amount') {
        mapped[field] = message;
      } else if (field.startsWith('portfolio_links.')) {
        final sent = int.tryParse(field.split('.').last);
        if (sent != null && sent < sentFrom.length) {
          mapped['link.${sentFrom[sent]}'] = message;
        }
      } else if (field == 'portfolio_links' && sentFrom.isNotEmpty) {
        mapped['link.${sentFrom.last}'] = message;
      }
    });
    return mapped;
  }
}

// -----------------------------------------------------------------------------
// Pieces
// -----------------------------------------------------------------------------

/// What the creator is applying to, so the form never feels detached from
/// the brief.
class _Summary extends ConsumerWidget {
  const _Summary({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final style = campaignCategoryStyle(ref, campaign);
    final brand = campaign.brand;

    return ZCard(
      child: Row(
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              color: style.tint.withValues(alpha: 0.14),
              borderRadius: AppRadius.lgAll,
            ),
            child: Icon(style.icon, color: style.tint, size: 26),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  campaign.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    if (brand != null) ...[
                      ZAvatar(
                        imageUrl: brand.avatarUrl,
                        name: brand.name,
                        size: 18,
                      ),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          brand.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodySmall,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Text('·', style: AppTypography.bodySmall),
                      const SizedBox(width: 6),
                    ],
                    Flexible(
                      child: Text(
                        campaign.deliverableShortLabel,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  campaign.displayBudget,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.labelLarge.copyWith(
                    color: AppColors.primaryDark,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.title, this.subtitle});

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          header: true,
          child: Text(title, style: AppTypography.titleMedium),
        ),
        if (subtitle != null) ...[
          const SizedBox(height: 2),
          Text(
            subtitle!,
            style: AppTypography.bodySmall.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }
}

/// A failure that is about the application as a whole. A KYC gate gets the
/// way out that fits where verification actually stands — the review screen
/// while a reviewer has it, support while it is on hold — with the server's
/// own status-specific message.
class _FormErrorBanner extends StatelessWidget {
  const _FormErrorBanner({required this.failure});

  final ApiFailure failure;

  @override
  Widget build(BuildContext context) {
    if (isKycGate(failure)) {
      return KycGateBanner(
        failure: failure,
        footnote: 'Your application is saved on this screen.',
      );
    }

    return ZInfoBanner(
      tone: StatusTone.error,
      title: 'Your application was not sent',
      message: failure.message,
    );
  }
}

/// The acknowledgement before sending. The whole row is the touch target, not
/// just the 18px box.
class _TermsCheck extends StatelessWidget {
  const _TermsCheck({
    required this.value,
    required this.showError,
    required this.onChanged,
  });

  final bool value;
  final bool showError;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        MergeSemantics(
          child: InkWell(
            onTap: () => onChanged(!value),
            borderRadius: AppRadius.mdAll,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: AppSpacing.minTouch),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Checkbox(
                    value: value,
                    onChanged: (v) => onChanged(v ?? false),
                    isError: showError,
                  ),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Text(
                        'My rate and details are accurate, and I will deliver '
                        'to the brief if hired, under the Zusty creator terms.',
                        style: AppTypography.bodyMedium,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.only(left: 40),
          child: TextButton(
            onPressed: () => context.push(Routes.page('terms')),
            style: TextButton.styleFrom(
              minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
            ),
            child: Text(
              'Read the terms',
              style: AppTypography.labelLarge.copyWith(color: AppColors.primary),
            ),
          ),
        ),
        if (showError)
          Padding(
            padding: const EdgeInsets.only(left: 48),
            child: Text(
              'Please confirm before sending.',
              style: AppTypography.labelSmall.copyWith(
                color: AppColors.error,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
      ],
    );
  }
}
