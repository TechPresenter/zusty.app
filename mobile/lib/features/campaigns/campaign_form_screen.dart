import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../core/utils/validators.dart';
import '../../models/campaign.dart';
import '../../models/city.dart';
import '../../models/taxonomy.dart';
import '../../providers/ai_provider.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/auth_provider.dart';
import '../../providers/campaign_provider.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/ai_draft_sheet.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import 'data/brand_campaign_api.dart';
import 'widgets/brand_campaign_view.dart';
import 'widgets/campaign_dates_sheet.dart';
import 'widgets/campaign_form_parts.dart';

/// Create / edit a campaign brief, as a four-step wizard:
/// Basics → Requirements → Budget & timing → Preview, then Published.
///
/// One long form hid the order a brand actually decides in, and put the
/// publish button a long scroll away from the fields it depended on. Each
/// step now validates before it lets you leave, and the preview shows the
/// brief exactly as creators will meet it on their board.
///
/// Saving as a draft is deliberately separated from publishing: a brief goes
/// out to every matching creator once it is public, so that step is explicit
/// rather than a side effect of pressing Save. A draft can be saved from any
/// step; it only needs a title and a description.
///
/// Editing uses the same steps and the same state. Fields the wizard does not
/// show (a seeded brief's shot list, usage rights, cover image) are preserved
/// on save — see [BrandCampaignActions.save].
class CampaignFormScreen extends ConsumerStatefulWidget {
  const CampaignFormScreen({super.key, this.campaignId});

  /// Null when creating.
  final int? campaignId;

  bool get isEditing => campaignId != null;

  @override
  ConsumerState<CampaignFormScreen> createState() => _CampaignFormScreenState();
}

enum _Busy { none, save, publish }

class _CampaignFormScreenState extends ConsumerState<CampaignFormScreen>
    with SingleTickerProviderStateMixin {
  static const _steps = ['Basics', 'Requirements', 'Budget', 'Preview'];
  static const _previewStep = 3;

  /// One form per editable step, so each validates on its own.
  final _formKeys = [
    GlobalKey<FormState>(),
    GlobalKey<FormState>(),
    GlobalKey<FormState>(),
  ];

  final _title = TextEditingController();
  final _description = TextEditingController();
  final _budgetMin = TextEditingController();
  final _budgetMax = TextEditingController();
  final _references = TextEditingController();
  final _briefUrl = TextEditingController();

  String _platform = 'instagram';
  String _deliverable = 'reel';
  int _postsCount = 1;
  int? _categoryId;
  DateTimeRange? _dates;

  int? _minFollowers;
  final Set<String> _cities = {};
  String _gender = 'any';
  double? _minRating;
  final Set<String> _languages = {};

  bool _isPublic = true;

  int _step = 0;
  _Busy _busy = _Busy.none;
  bool _seeded = false;

  /// The campaign being edited, as loaded.
  CampaignStatus? _existingStatus;
  String? _existingCode;
  int _existingApplicants = 0;

  /// Set after the first successful save, so a retry after a failed publish
  /// updates that brief instead of creating a second one.
  int? _savedId;

  /// Non-null once published: the screen shows the confirmation instead.
  Campaign? _published;

  late final AnimationController _stepIn = AnimationController(
    vsync: this,
    duration: Motion.medium,
    value: 1,
  );

  @override
  void dispose() {
    _title.dispose();
    _description.dispose();
    _budgetMin.dispose();
    _budgetMax.dispose();
    _references.dispose();
    _briefUrl.dispose();
    _stepIn.dispose();
    super.dispose();
  }

  /// Copies an existing campaign into the form the first time it loads.
  void _seed(Campaign campaign) {
    if (_seeded) return;
    _seeded = true;

    _title.text = campaign.title;
    _description.text = campaign.description;
    if (campaign.budgetMin > 0) {
      _budgetMin.text = campaign.budgetMin.round().toString();
    }
    if (campaign.budgetMax > 0) {
      _budgetMax.text = campaign.budgetMax.round().toString();
    }
    _references.text = campaign.referenceUrls.join('\n');
    _briefUrl.text = campaign.briefUrl ?? '';
    _platform = campaign.platform;
    _deliverable = campaign.deliverableType;
    _postsCount = campaign.postsCount;
    _categoryId = campaign.categoryId;
    _isPublic = campaign.isPublic;
    _minFollowers = campaign.requirements.minFollowers;
    _cities.addAll(campaign.requirements.cities);
    _gender = campaign.requirements.gender ?? 'any';
    _minRating = campaign.requirements.minRating;
    // Languages were never shown by the old form, so its save dropped them.
    _languages.addAll(campaign.requirements.languages);

    _existingStatus = campaign.status;
    _existingCode = campaign.code;
    _existingApplicants = campaign.applicationsCount;

    if (campaign.startsOn != null || campaign.endsOn != null) {
      final start = campaign.startsOn ?? campaign.endsOn!;
      final end = campaign.endsOn ?? start;
      _dates = DateTimeRange(start: start, end: end.isBefore(start) ? start : end);
    }
  }

  // -- Derived values ---------------------------------------------------------

  int? get _targetId => widget.campaignId ?? _savedId;

  /// Editing a brief that is already live: there is nothing to publish, and
  /// "draft" would be the wrong word for saving it.
  bool get _isLiveEdit => _existingStatus?.isLive ?? false;

  double get _minBudget =>
      double.tryParse(_budgetMin.text.replaceAll(RegExp(r'[^\d.]'), '')) ?? 0;

  double get _maxBudget =>
      double.tryParse(_budgetMax.text.replaceAll(RegExp(r'[^\d.]'), '')) ?? 0;

  bool get _budgetConflict =>
      _minBudget > 0 && _maxBudget > 0 && _maxBudget < _minBudget;

  List<String> get _referenceList => _references.text
      .split('\n')
      .map((line) => line.trim())
      .where((line) => line.isNotEmpty)
      .toList();

  CampaignRequirements get _requirements => CampaignRequirements(
        minFollowers: _minFollowers,
        cities: _cities.toList(),
        gender: _gender == 'any' ? null : _gender,
        minRating: _minRating,
        languages: _languages.toList(),
      );

  // -- Navigation -------------------------------------------------------------

  void _goTo(int step) {
    FocusScope.of(context).unfocus();
    setState(() => _step = step.clamp(0, _previewStep));
    if (!Motion.reduced(context)) _stepIn.forward(from: 0);
  }

  void _next() {
    if (!_validateStep(_step)) {
      showZSnack(context, 'Check the highlighted fields.', isError: true);
      return;
    }
    _goTo(_step + 1);
  }

  bool _validateStep(int step) {
    if (step >= _formKeys.length) return true;
    return _formKeys[step].currentState?.validate() ?? true;
  }

  /// Validates [steps] in order and moves to the first that fails, where its
  /// errors are already showing.
  bool _validateSteps(List<int> steps) {
    for (final step in steps) {
      if (!_validateStep(step)) {
        if (step != _step) _goTo(step);
        showZSnack(context, 'Check the highlighted fields.', isError: true);
        return false;
      }
    }
    if (_budgetConflict) {
      if (_step != 2) _goTo(2);
      showZSnack(
        context,
        'The maximum budget must be at least the minimum.',
        isError: true,
      );
      return false;
    }
    return true;
  }

  Future<void> _pickDates() async {
    final picked = await showCampaignDatesSheet(context, initial: _dates);
    if (picked != null && mounted) setState(() => _dates = picked);
  }

  // -- Saving -----------------------------------------------------------------

  Future<Campaign> _persist() async {
    final campaign = await ref.read(brandCampaignActionsProvider).save(
          id: _targetId,
          title: _title.text.trim(),
          description: _description.text.trim(),
          platform: _platform,
          deliverableType: _deliverable,
          postsCount: _postsCount,
          budgetMin: _minBudget,
          budgetMax: _maxBudget,
          categoryId: _categoryId,
          startsOn: _dates?.start,
          endsOn: _dates?.end,
          requirements: _requirements,
          referenceUrls: _referenceList,
          briefUrl: _briefUrl.text,
          // Saved as chosen even on a draft: the publish endpoint does not
          // set visibility, so a draft saved private stayed private once
          // published, whatever the toggle said.
          isPublic: _isPublic,
        );
    _savedId ??= campaign.id;
    return campaign;
  }

  /// Saves without publishing. A draft only needs what the server needs to
  /// store it; a live brief must stay complete, so it checks every step.
  Future<void> _save() async {
    if (!_validateSteps(_isLiveEdit ? const [0, 1, 2] : const [0, 1])) return;

    setState(() => _busy = _Busy.save);
    try {
      final campaign = await _persist();
      if (!mounted) return;
      showZSnack(context, _isLiveEdit ? 'Changes saved.' : 'Draft saved.');
      _leaveTo(campaign.id);
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = _Busy.none);
    }
  }

  Future<void> _publish() async {
    if (!_validateSteps(const [0, 1, 2])) return;

    setState(() => _busy = _Busy.publish);
    try {
      final saved = await _persist();
      // Publishing is its own endpoint; it also re-checks budget and brief.
      await ref.read(campaignActionsProvider).publish(saved.id);
      if (!mounted) return;
      HapticFeedback.mediumImpact();
      setState(() => _published = saved.copyWith(status: CampaignStatus.open));
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = _Busy.none);
    }
  }

  /// After saving: back to the campaign the edit was opened from, or on to
  /// the new campaign in place of this form.
  void _leaveTo(int id) {
    if (widget.isEditing) {
      context.canPop() ? context.pop() : context.go(Routes.campaign(id));
    } else {
      context.pushReplacement(Routes.campaign(id));
    }
  }

  /// Drafts the description from a one-line idea and what the form already
  /// holds; it lands in the field, unsaved, for the brand to edit.
  Future<void> _writeBriefWithAi() async {
    final title = _title.text.trim();

    final draft = await AiDraftSheet.show(
      context,
      title: 'Write the brief with AI',
      subtitle: 'Describe the campaign in a line or two. The draft also uses '
          'the platform, deliverables and budget set on this form.',
      inputLabel: 'What is the campaign about?',
      inputHint: 'e.g. Honest reviews of our new vitamin C serum before Diwali',
      initialInput: title,
      minInputLength: 10,
      useLabel: _description.text.trim().isEmpty
          ? 'Use this'
          : 'Replace description',
      generate: (idea) => ref.read(aiRepositoryProvider).writeCampaignBrief(
            idea: idea,
            title: title.isEmpty ? null : title,
            platform: _platform,
            deliverableType: _deliverable,
            postsCount: _postsCount,
            budgetMin: _minBudget,
            budgetMax: _maxBudget,
            categoryId: _categoryId,
          ),
    );

    if (draft != null && mounted) setState(() => _description.text = draft);
  }

  // -- Build ------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final published = _published;
    if (published != null) return _PublishedView(campaign: published, isEditing: widget.isEditing);

    // When editing, wait for the campaign before showing the form. Once
    // seeded, the form owns its state: a refresh of the campaign after a
    // save must not swap the wizard out for a loader mid-flow.
    if (widget.isEditing && !_seeded) {
      final existing = ref.watch(campaignDetailProvider(widget.campaignId!));
      return existing.when(
        loading: () => const ZScaffold(
          appBar: ZAppBar(title: 'Edit campaign'),
          body: ListSkeleton(count: 4, height: 120),
        ),
        error: (error, _) => ZScaffold(
          appBar: const ZAppBar(title: 'Edit campaign'),
          body: ErrorState(
            error: error,
            onRetry: () =>
                ref.invalidate(campaignDetailProvider(widget.campaignId!)),
          ),
        ),
        data: (campaign) {
          _seed(campaign);
          return _buildWizard(context);
        },
      );
    }

    return _buildWizard(context);
  }

  Widget _buildWizard(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);
    final busy = _busy != _Busy.none;
    final slide = Tween<Offset>(
      begin: const Offset(0.04, 0),
      end: Offset.zero,
    ).animate(CurvedAnimation(parent: _stepIn, curve: Motion.emphasized));

    return PopScope(
      // System back walks back through the steps before leaving.
      canPop: _step == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && _step > 0) _goTo(_step - 1);
      },
      child: ZScaffold(
        padded: false,
        appBar: ZAppBar(
          title: widget.isEditing ? 'Edit campaign' : 'New campaign',
          onBack: _step > 0 ? () => _goTo(_step - 1) : null,
          actions: [
            if (_step != _previewStep)
              TextButton(
                onPressed: busy ? null : _save,
                style: TextButton.styleFrom(
                  minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
                ),
                child: Text(_isLiveEdit ? 'Save' : 'Save draft'),
              ),
          ],
        ),
        bottomBar: _bottomBar(busy),
        body: Column(
          children: [
            Padding(
              padding: EdgeInsets.fromLTRB(margin, AppSpacing.xs, margin, 0),
              child: WizardProgress(
                steps: _steps,
                current: _step,
                onStepTap: busy ? null : _goTo,
              ),
            ),
            Divider(height: 1, color: AppColors.outlineVariant),
            Expanded(
              child: FadeTransition(
                opacity: CurvedAnimation(parent: _stepIn, curve: Motion.standard),
                child: SlideTransition(
                  position: slide,
                  // Every step stays built, so a step's fields keep their
                  // state and publishing can validate all of them at once.
                  child: IndexedStack(
                    index: _step,
                    children: [
                      _stepScroll(Form(key: _formKeys[0], child: _basicsStep())),
                      _stepScroll(
                        Form(key: _formKeys[1], child: _requirementsStep()),
                      ),
                      _stepScroll(Form(key: _formKeys[2], child: _budgetStep())),
                      _stepScroll(_previewStepView()),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// A step's scroll body. Not a lazy list: every field has to exist for its
  /// form to validate it, including the ones scrolled out of view.
  Widget _stepScroll(Widget child) {
    final margin = AppBreakpoints.pageMargin(context);
    return SingleChildScrollView(
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: EdgeInsets.fromLTRB(margin, AppSpacing.lg, margin, AppSpacing.xl),
      child: Center(
        child: ConstrainedBox(
          // On a tablet the page column already caps the width; a second,
          // narrower cap would leave the fields short of the step header.
          constraints: BoxConstraints(
            maxWidth: WindowSize.of(context).isCompact
                ? AppBreakpoints.maxContentWidth
                : double.infinity,
          ),
          child: child,
        ),
      ),
    );
  }

  Widget _bottomBar(bool busy) {
    if (_step < _previewStep) {
      return Row(
        children: [
          if (_step > 0) ...[
            Expanded(
              child: ZButton.outlined(
                label: 'Back',
                size: ZButtonSize.medium,
                onPressed: busy ? null : () => _goTo(_step - 1),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
          ],
          Expanded(
            flex: 2,
            child: ZButton(
              label: _step == _previewStep - 1 ? 'Preview' : 'Next',
              trailingIcon: Icons.arrow_forward_rounded,
              size: ZButtonSize.medium,
              onPressed: busy ? null : _next,
            ),
          ),
        ],
      );
    }

    if (_isLiveEdit) {
      return Row(
        children: [
          Expanded(
            child: ZButton.outlined(
              label: 'Back',
              size: ZButtonSize.medium,
              onPressed: busy ? null : () => _goTo(_step - 1),
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            flex: 2,
            child: ZButton(
              label: 'Save changes',
              icon: Icons.check_rounded,
              size: ZButtonSize.medium,
              isLoading: _busy == _Busy.save,
              onPressed: busy ? null : _save,
            ),
          ),
        ],
      );
    }

    return Row(
      children: [
        Expanded(
          child: ZButton.outlined(
            label: 'Save draft',
            size: ZButtonSize.medium,
            isLoading: _busy == _Busy.save,
            onPressed: busy ? null : _save,
          ),
        ),
        const SizedBox(width: AppSpacing.xs),
        Expanded(
          flex: 2,
          child: ZButton(
            label: 'Publish',
            icon: Icons.campaign_rounded,
            size: ZButtonSize.medium,
            isLoading: _busy == _Busy.publish,
            onPressed: busy ? null : _publish,
          ),
        ),
      ],
    );
  }

  // -- Step 1: Basics ---------------------------------------------------------

  Widget _basicsStep() {
    final tax = ref.watch(taxonomiesProvider);
    final ai = ref.watch(aiFeaturesProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const StepHeading(
          title: 'Start with the basics',
          subtitle: 'What you want made, and where it will run.',
        ),
        if (_existingApplicants > 0) ...[
          ZInfoBanner(
            tone: StatusTone.warning,
            message: '$_existingApplicants '
                '${_existingApplicants == 1 ? 'creator has' : 'creators have'} '
                'applied against this brief. Keep changes to what they '
                'applied for small, or tell them in a message.',
          ),
          const SizedBox(height: AppSpacing.md),
        ],
        FormSectionCard(
          title: 'The brief',
          subtitle: 'What you want made, in your own words.',
          children: [
            ZTextField(
              label: 'Campaign title',
              hint: 'e.g. Festive Glow — Diwali Collection',
              controller: _title,
              textCapitalization: TextCapitalization.sentences,
              textInputAction: TextInputAction.next,
              validator: (v) => Validators.minLength(v, 6, field: 'Title'),
            ),
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label: 'Description',
              hint: 'Tone, must-haves, hashtags, what to avoid…',
              helper: 'At least 30 characters. Creators read this before '
                  'they apply.',
              controller: _description,
              maxLines: 8,
              minLines: 5,
              textCapitalization: TextCapitalization.sentences,
              validator: (v) =>
                  Validators.minLength(v, 30, field: 'Description'),
            ),
            if (ai.campaignBrief) ...[
              const SizedBox(height: AppSpacing.sm),
              _AiAssist(onTap: _writeBriefWithAi),
            ],
          ],
        ),
        FormSectionCard(
          title: 'Deliverables',
          subtitle: 'What each hired creator makes for you.',
          children: [
            const FieldLabel('Platform'),
            ChoiceChips(
              options: _withCurrent(
                tax.map(Tax.platform),
                _platform,
                _previewCampaign.platformLabel,
              ),
              value: _platform,
              onChanged: (v) => setState(() => _platform = v),
            ),
            const SizedBox(height: AppSpacing.md),
            const FieldLabel('Content type'),
            ChoiceChips(
              options: _withCurrent(
                tax.map(Tax.deliverableType),
                _deliverable,
                _deliverable,
              ),
              value: _deliverable,
              onChanged: (v) => setState(() => _deliverable = v),
            ),
            const SizedBox(height: AppSpacing.md),
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Per creator',
                        style: AppTypography.labelLarge
                            .copyWith(color: AppColors.onSurface),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        _previewCampaign.deliverableLabel,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall,
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                CountStepper(
                  value: _postsCount,
                  unit: _deliverable,
                  onChanged: (v) => setState(() => _postsCount = v),
                ),
              ],
            ),
          ],
        ),
        FormSectionCard(
          title: 'Category',
          subtitle: 'Helps the right creators find your brief.',
          trailing: Text('Optional', style: AppTypography.labelSmall),
          children: [
            _CategoryChips(
              value: _categoryId,
              onChanged: (v) => setState(() => _categoryId = v),
            ),
          ],
        ),
      ],
    );
  }

  // -- Step 2: Requirements ---------------------------------------------------

  Widget _requirementsStep() {
    final tax = ref.watch(taxonomiesProvider);
    final languages = tax.terms(Tax.language);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const StepHeading(
          title: 'Who should apply',
          subtitle: 'Leave anything blank to keep the brief open to every '
              'creator.',
        ),
        FormSectionCard(
          title: 'Audience',
          children: [
            const FieldLabel('Minimum followers', hint: 'optional'),
            ChoiceChips(
              options: {
                for (final n in const [5000, 10000, 25000, 50000, 100000])
                  '$n': '${Formatters.compact(n)}+',
                if (_minFollowers != null &&
                    !const [5000, 10000, 25000, 50000, 100000]
                        .contains(_minFollowers))
                  '$_minFollowers': '${Formatters.compact(_minFollowers)}+',
              },
              value: _minFollowers?.toString(),
              allowClear: true,
              onChanged: (v) => setState(
                () => _minFollowers = v.isEmpty ? null : int.parse(v),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            const FieldLabel('Minimum rating', hint: 'optional'),
            ChoiceChips(
              options: {
                for (final r in const ['3.5', '4.0', '4.5']) r: '$r★+',
                if (_minRating != null &&
                    !const ['3.5', '4.0', '4.5']
                        .contains(_minRating!.toStringAsFixed(1)))
                  _minRating!.toStringAsFixed(1):
                      '${_minRating!.toStringAsFixed(1)}★+',
              },
              value: _minRating?.toStringAsFixed(1),
              allowClear: true,
              onChanged: (v) => setState(
                () => _minRating = v.isEmpty ? null : double.parse(v),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            const FieldLabel('Creator gender'),
            ChoiceChips(
              options: const {
                'any': 'Any',
                'female': 'Female',
                'male': 'Male',
                'non_binary': 'Non-binary',
              },
              value: _gender,
              onChanged: (v) => setState(() => _gender = v),
            ),
          ],
        ),
        FormSectionCard(
          title: 'Location & language',
          children: [
            const FieldLabel('Cities', hint: 'none means anywhere'),
            MultiChips(
              options: [for (final city in Cities.metros) city.name],
              selected: _cities,
              onToggle: (city) => setState(
                () => _cities.contains(city)
                    ? _cities.remove(city)
                    : _cities.add(city),
              ),
            ),
            if (languages.isNotEmpty || _languages.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.md),
              const FieldLabel('Languages', hint: 'optional'),
              MultiChips(
                options: [for (final term in languages) term.value],
                labels: {for (final term in languages) term.value: term.label},
                selected: _languages,
                onToggle: (language) => setState(
                  () => _languages.contains(language)
                      ? _languages.remove(language)
                      : _languages.add(language),
                ),
              ),
            ],
          ],
        ),
        FormSectionCard(
          title: 'References',
          subtitle: 'Optional, but briefs with examples get closer first '
              'drafts.',
          children: [
            ZTextField(
              label: 'Reference links',
              hint: 'https://instagram.com/p/…',
              helper: 'One link per line: posts with the look you want.',
              controller: _references,
              maxLines: 5,
              minLines: 3,
              keyboardType: TextInputType.multiline,
              validator: _validateReferences,
            ),
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label: 'Brief document',
              hint: 'https://drive.google.com/…',
              helper: 'A link to a full brief or mood board, if you have one.',
              controller: _briefUrl,
              keyboardType: TextInputType.url,
              prefixIcon: Icons.link_rounded,
              validator: Validators.optionalUrl,
            ),
          ],
        ),
      ],
    );
  }

  static String? _validateReferences(String? value) {
    final lines = (value ?? '')
        .split('\n')
        .map((line) => line.trim())
        .where((line) => line.isNotEmpty)
        .toList();
    for (var i = 0; i < lines.length; i++) {
      if (Validators.optionalUrl(lines[i]) != null) {
        return lines.length == 1
            ? 'Enter a full link, starting with https://'
            : 'Line ${i + 1} is not a full link. Start each with https://';
      }
    }
    return null;
  }

  // -- Step 3: Budget & timing ------------------------------------------------

  Widget _budgetStep() {
    final dates = _dates;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const StepHeading(
          title: 'Budget and timing',
          subtitle: 'What you will pay each creator, and when the work '
              'happens.',
        ),
        FormSectionCard(
          title: 'Budget per creator',
          subtitle:
              'A range invites better-matched applications than a fixed fee.',
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: ZTextField(
                    label: 'Minimum',
                    hint: '15000',
                    controller: _budgetMin,
                    keyboardType: TextInputType.number,
                    textInputAction: TextInputAction.next,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    prefixIcon: Icons.currency_rupee_rounded,
                    validator: (v) =>
                        Validators.amount(v, min: 500, field: 'Minimum'),
                    onChanged: (_) => setState(() {}),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: ZTextField(
                    label: 'Maximum',
                    hint: '50000',
                    controller: _budgetMax,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    prefixIcon: Icons.currency_rupee_rounded,
                    validator: (v) =>
                        Validators.amount(v, min: 500, field: 'Maximum') ??
                        (_budgetConflict ? 'Must be at least the minimum.' : null),
                    onChanged: (_) => setState(() {}),
                  ),
                ),
              ],
            ),
            if (_minBudget > 0 && _maxBudget >= _minBudget) ...[
              const SizedBox(height: AppSpacing.sm),
              _BudgetPreview(
                min: _minBudget,
                max: _maxBudget,
                posts: _postsCount,
              ),
            ],
          ],
        ),
        FormSectionCard(
          title: 'Timeline',
          subtitle: 'Optional. Without dates, creators see a flexible '
              'timeline.',
          children: [
            PickerField(
              icon: Icons.date_range_rounded,
              label: 'Campaign window',
              placeholder: 'Pick start and end dates',
              value: dates == null
                  ? null
                  : '${Formatters.date(dates.start)}  →  '
                      '${Formatters.date(dates.end)}',
              onTap: _pickDates,
              onClear: () => setState(() => _dates = null),
            ),
          ],
        ),
        FormSectionCard(
          title: 'Visibility',
          subtitle: 'You can still invite creators either way.',
          children: [
            _VisibilityOption(
              icon: Icons.public_rounded,
              title: 'Public',
              message: 'Listed on the creator board. Matching creators can '
                  'discover it and apply.',
              selected: _isPublic,
              onTap: () => setState(() => _isPublic = true),
            ),
            const SizedBox(height: AppSpacing.xs),
            _VisibilityOption(
              icon: Icons.lock_outline_rounded,
              title: 'Invite-only',
              message: 'Hidden from the board. Only creators you invite can '
                  'see it and apply.',
              selected: !_isPublic,
              onTap: () => setState(() => _isPublic = false),
            ),
          ],
        ),
      ],
    );
  }

  // -- Step 4: Preview --------------------------------------------------------

  /// The brief as it stands, shaped as the creator board will show it once
  /// published.
  Campaign get _previewCampaign {
    final user = ref.read(currentUserProvider);
    final categories = ref.read(categoriesProvider).valueOrNull;
    final category = categories?.firstWhereOrNull((c) => c.id == _categoryId);
    final title = _title.text.trim();

    return Campaign(
      id: _targetId ?? 0,
      code: _existingCode ?? '',
      title: title.isEmpty ? 'Untitled campaign' : title,
      description: _description.text.trim(),
      status: CampaignStatus.open,
      categoryId: _categoryId,
      categoryName: category?.name,
      platform: _platform,
      deliverableType: _deliverable,
      postsCount: _postsCount,
      budgetMin: _minBudget,
      budgetMax: _maxBudget,
      requirements: _requirements,
      referenceUrls: _referenceList,
      briefUrl: _briefUrl.text.trim().isEmpty ? null : _briefUrl.text.trim(),
      startsOn: _dates?.start,
      endsOn: _dates?.end,
      isPublic: _isPublic,
      createdAt: DateTime.now(),
      brand: user == null
          ? null
          : CampaignBrand(
              id: user.id,
              name: user.displayName,
              avatarUrl: user.avatarUrl,
            ),
    );
  }

  Widget _previewStepView() {
    // Watched so the category name in the preview fills in once loaded.
    ref.watch(categoriesProvider);
    final preview = _previewCampaign;
    final requirements = preview.requirements;
    final references = _referenceList;
    final brief = preview.description;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const StepHeading(
          title: 'Preview',
          subtitle: 'This is how creators will meet your brief. Check it, '
              'then publish.',
        ),
        const FieldLabel('On the creator board'),
        Semantics(
          label: 'Preview of your campaign card',
          child: ExcludeSemantics(
            child: AbsorbPointer(
              child: CampaignCard(
                campaign: preview,
                onTap: () {},
                onApply: () {},
              ),
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.lg),
        FormSectionCard(
          title: 'Basics',
          trailing: _EditButton(label: 'Basics', onTap: () => _goTo(0)),
          children: [
            SummaryRow(label: 'Title', value: preview.title),
            SummaryRow(
              label: 'Category',
              value: preview.categoryName ?? 'Not set',
            ),
            SummaryRow(label: 'Deliverables', value: preview.deliverableLabel),
            if (brief.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.xs),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(AppSpacing.sm),
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: AppRadius.mdAll,
                ),
                child: Text(
                  brief,
                  maxLines: 6,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall
                      .copyWith(color: AppColors.onSurface, height: 1.5),
                ),
              ),
            ],
          ],
        ),
        FormSectionCard(
          title: 'Requirements',
          trailing: _EditButton(label: 'Requirements', onTap: () => _goTo(1)),
          children: [
            if (requirements.isEmpty)
              const SummaryRow(
                label: 'Who can apply',
                value: 'Open to every creator',
              )
            else
              Wrap(
                spacing: AppSpacing.xs,
                runSpacing: AppSpacing.xs,
                children: [
                  for (final rule in requirements.summary)
                    ZChip(label: rule, color: AppColors.secondary),
                ],
              ),
            const SizedBox(height: AppSpacing.xs),
            SummaryRow(
              label: 'References',
              value: references.isEmpty
                  ? 'None'
                  : '${references.length} '
                      'link${references.length == 1 ? '' : 's'}',
            ),
            SummaryRow(
              label: 'Brief document',
              value: preview.briefUrl == null
                  ? 'None'
                  : Uri.tryParse(preview.briefUrl!)?.host ?? preview.briefUrl!,
            ),
          ],
        ),
        FormSectionCard(
          title: 'Budget & timing',
          trailing: _EditButton(label: 'Budget and timing', onTap: () => _goTo(2)),
          children: [
            SummaryRow(
              label: 'Per creator',
              value: _minBudget > 0 || _maxBudget > 0
                  ? Formatters.currencyRange(
                      _minBudget > 0 ? _minBudget : null,
                      _maxBudget > 0 ? _maxBudget : null,
                    )
                  : 'Not set',
            ),
            SummaryRow(label: 'Timeline', value: preview.timelineLabel),
            SummaryRow(
              label: 'Visibility',
              value: _isPublic ? 'Public' : 'Invite-only',
            ),
          ],
        ),
        ZInfoBanner(
          tone: _isPublic ? StatusTone.info : StatusTone.neutral,
          icon: _isPublic ? Icons.public_rounded : Icons.lock_outline_rounded,
          message: _isLiveEdit
              ? 'This campaign is live. Saved changes show to creators '
                  'straight away.'
              : _isPublic
                  ? 'Once published, the brief appears on the creator board '
                      'and matching creators can apply.'
                  : 'Once published, only creators you invite will see the '
                      'brief.',
        ),
      ],
    );
  }

  /// Taxonomy options plus the current value when the taxonomy lacks it, so
  /// a seeded brief's platform still shows as selected.
  static Map<String, String> _withCurrent(
    Map<String, String> options,
    String current,
    String label,
  ) =>
      options.containsKey(current) || current.isEmpty
          ? options
          : {...options, current: label};
}

// -----------------------------------------------------------------------------
// Pieces
// -----------------------------------------------------------------------------

class _AiAssist extends StatelessWidget {
  const _AiAssist({required this.onTap});

  final VoidCallback onTap;

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
        color: AppColors.primaryFixed,
        borderRadius: AppRadius.lgAll,
      ),
      child: Row(
        children: [
          Icon(
            Icons.auto_awesome_rounded,
            size: 18,
            color: AppColors.onPrimaryFixedVariant,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              'Draft it with AI, then make it yours.',
              style: AppTypography.bodySmall
                  .copyWith(color: AppColors.onPrimaryFixedVariant),
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          ZButton(
            label: 'Write with AI',
            variant: ZButtonVariant.primary,
            size: ZButtonSize.small,
            expand: false,
            onPressed: onTap,
          ),
        ],
      ),
    );
  }
}

class _EditButton extends StatelessWidget {
  const _EditButton({required this.label, required this.onTap});

  /// The section, for screen readers: "Edit Basics".
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: 'Edit $label',
      excludeSemantics: true,
      onTap: onTap,
      child: TextButton.icon(
        onPressed: onTap,
        style: TextButton.styleFrom(
          minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
        ),
        icon: const Icon(Icons.edit_outlined, size: 16),
        label: const Text('Edit'),
      ),
    );
  }
}

/// A radio-style card for one visibility choice.
class _VisibilityOption extends StatelessWidget {
  const _VisibilityOption({
    required this.icon,
    required this.title,
    required this.message,
    required this.selected,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String message;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final duration = Motion.of(context, Motion.fast);

    return Semantics(
      inMutuallyExclusiveGroup: true,
      checked: selected,
      button: true,
      label: '$title. $message',
      excludeSemantics: true,
      onTap: onTap,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: AppRadius.lgAll,
          child: AnimatedContainer(
            duration: duration,
            curve: Motion.standard,
            padding: const EdgeInsets.all(AppSpacing.sm),
            decoration: BoxDecoration(
              color: selected
                  ? AppColors.primaryFixed
                  : AppColors.surfaceContainerLowest,
              borderRadius: AppRadius.lgAll,
              border: Border.all(
                color: selected
                    ? AppColors.primary.withValues(alpha: 0.5)
                    : AppColors.outlineVariant,
                width: selected ? 1.5 : 1,
              ),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: selected
                        ? AppColors.primary
                        : AppColors.surfaceContainerLow,
                    borderRadius: AppRadius.mdAll,
                  ),
                  child: Icon(
                    icon,
                    size: 18,
                    color: selected ? AppColors.onPrimary : AppColors.primary,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: AppTypography.titleSmall),
                      const SizedBox(height: 2),
                      Text(message, style: AppTypography.bodySmall),
                    ],
                  ),
                ),
                const SizedBox(width: AppSpacing.xs),
                Icon(
                  selected
                      ? Icons.radio_button_checked_rounded
                      : Icons.radio_button_off_rounded,
                  size: 22,
                  color: selected ? AppColors.primary : AppColors.outline,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Shows what the brief will cost across the whole campaign.
class _BudgetPreview extends StatelessWidget {
  const _BudgetPreview({
    required this.min,
    required this.max,
    required this.posts,
  });

  final double min;
  final double max;
  final int posts;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.primaryFixed,
        borderRadius: AppRadius.mdAll,
      ),
      child: Row(
        children: [
          Icon(
            Icons.calculate_outlined,
            size: 17,
            color: AppColors.onPrimaryFixedVariant,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              '${Formatters.currencyRange(min, max)} per creator · '
              '$posts deliverable${posts == 1 ? '' : 's'} each',
              style: AppTypography.labelMedium
                  .copyWith(color: AppColors.onPrimaryFixedVariant),
            ),
          ),
        ],
      ),
    );
  }
}

class _CategoryChips extends ConsumerWidget {
  const _CategoryChips({required this.value, required this.onChanged});

  final int? value;
  final ValueChanged<int?> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final categories = ref.watch(categoriesProvider);

    return categories.when(
      data: (list) => Wrap(
        spacing: AppSpacing.xs,
        runSpacing: AppSpacing.xs,
        children: [
          for (final category in list)
            Semantics(
              selected: value == category.id,
              child: ZChip(
                label: category.name,
                selected: value == category.id,
                onTap: () =>
                    onChanged(value == category.id ? null : category.id),
              ),
            ),
        ],
      ),
      loading: () => const Wrap(
        spacing: AppSpacing.xs,
        runSpacing: AppSpacing.xs,
        children: [
          ZShimmer(width: 84, height: 34, borderRadius: AppRadius.pill),
          ZShimmer(width: 104, height: 34, borderRadius: AppRadius.pill),
          ZShimmer(width: 72, height: 34, borderRadius: AppRadius.pill),
        ],
      ),
      error: (_, _) => Row(
        children: [
          Expanded(
            child: Text(
              'Categories could not load.',
              style: AppTypography.bodySmall,
            ),
          ),
          TextButton(
            onPressed: () => ref.invalidate(categoriesProvider),
            child: const Text('Retry'),
          ),
        ],
      ),
    );
  }
}

// -----------------------------------------------------------------------------
// Published
// -----------------------------------------------------------------------------

/// The confirmation after publishing, with the two things a brand does next:
/// look at the live brief, or go and find creators for it.
class _PublishedView extends ConsumerWidget {
  const _PublishedView({required this.campaign, required this.isEditing});

  final Campaign campaign;

  /// Opened from the campaign's own screen, which is still underneath.
  final bool isEditing;

  void _viewCampaign(BuildContext context) {
    if (isEditing && context.canPop()) {
      context.pop();
    } else {
      context.pushReplacement(Routes.campaign(campaign.id));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ai = ref.watch(aiFeaturesProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Campaign published'),
      body: Center(
        child: SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.lg, margin, AppSpacing.xl),
          child: SuccessView(
            title: 'Campaign published',
            message: campaign.isPublic
                ? '"${campaign.title}" is live on the creator board. Matching '
                    'creators can apply from now.'
                : '"${campaign.title}" is live and invite-only. Invite '
                    'creators to start receiving applications.',
            details: _PublishedSummary(campaign: campaign),
            primaryLabel: 'View campaign',
            onPrimary: () => _viewCampaign(context),
            secondaryLabel:
                ai.creatorMatch ? 'Find matching creators' : 'Browse creators',
            onSecondary: () => context.pushReplacement(
              ai.creatorMatch
                  ? Routes.campaignMatches(campaign.id)
                  : Routes.explore,
            ),
          ),
        ),
      ),
    );
  }
}

class _PublishedSummary extends StatelessWidget {
  const _PublishedSummary({required this.campaign});

  final Campaign campaign;

  @override
  Widget build(BuildContext context) {
    final status = campaign.brandStatus;

    return ZCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  campaign.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              StatusChip(
                label: status.label,
                tone: status.tone,
                icon: status.icon,
                dense: true,
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          if (campaign.code.isNotEmpty)
            ZDetailRow(label: 'Reference', value: campaign.code),
          ZDetailRow(label: 'Deliverables', value: campaign.deliverableShortLabel),
          ZDetailRow(label: 'Budget / creator', value: campaign.compactBudget),
          ZDetailRow(
            label: 'Visibility',
            value: campaign.isPublic ? 'Public' : 'Invite-only',
          ),
        ],
      ),
    );
  }
}
