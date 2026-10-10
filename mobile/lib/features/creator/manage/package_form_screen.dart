import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/creator.dart';
import '../../../models/creator_tools.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/creator_tools_provider.dart';
import '../../../providers/opportunity_provider.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_scaffold.dart';
import '../../../shared/widgets/z_text_field.dart';
import '../../campaigns/widgets/campaign_form_parts.dart';
import 'creator_tools_rules.dart';
import 'widgets/creator_tools_common.dart';

/// Add a package, or edit one.
///
/// Every field `POST`/`PUT /me/creator/packages` accepts, checked here by the
/// same rules the API applies ([CreatorToolsRules]) and with the price bounds
/// the API sent alongside the list. Whatever the server still refuses is put
/// back beside the field it named, or above the form when it named none.
class PackageFormScreen extends ConsumerWidget {
  const PackageFormScreen({super.key, this.packageId});

  /// Null for a new package.
  final int? packageId;

  bool get isNew => packageId == null;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final title = isNew ? 'New package' : 'Edit package';

    if (!ref.watch(isCreatorSessionProvider)) {
      return ZScaffold(
        appBar: ZAppBar(title: title),
        body: const CreatorsOnlyNote(),
      );
    }

    final packages = ref.watch(myPackagesProvider);

    return packages.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => ZScaffold(
          appBar: ZAppBar(title: title),
          body: const ZLoader(),
        ),
        error: (error, _) => ZScaffold(
          appBar: ZAppBar(title: title),
          body: CreatorToolsErrorView(
            error: error,
            onRetry: () => ref.invalidate(myPackagesProvider),
          ),
        ),
        data: (data) {
          final existing = isNew ? null : data.byId(packageId!);

          if (!isNew && existing == null) {
            return ZScaffold(
              appBar: ZAppBar(title: title),
              body: EmptyState(
                icon: Icons.search_off_rounded,
                title: 'This package is no longer here',
                message: 'It may have been deleted on another device.',
                actionLabel: 'Back to my packages',
                onAction: () => context.canPop()
                    ? context.pop()
                    : context.go(Routes.myPackages),
              ),
            );
          }

          return _PackageForm(
            // A different package must not inherit this one's half-typed form.
            key: ValueKey(existing?.id ?? 'new'),
            existing: existing,
            limits: data.limits,
            locked: data.locked,
          );
        },
    );
  }
}

class _PackageForm extends ConsumerStatefulWidget {
  const _PackageForm({
    super.key,
    required this.existing,
    required this.limits,
    required this.locked,
  });

  final OwnPackage? existing;
  final PackageLimits limits;
  final bool locked;

  @override
  ConsumerState<_PackageForm> createState() => _PackageFormState();
}

class _PackageFormState extends ConsumerState<_PackageForm> {
  final _formKey = GlobalKey<FormState>();

  late final TextEditingController _title;
  late final TextEditingController _description;
  late final TextEditingController _price;
  late final TextEditingController _quantity;
  late final TextEditingController _turnaround;
  late final TextEditingController _revisions;
  final _includeInput = TextEditingController();

  String? _platform;
  String? _deliverable;
  final List<String> _includes = [];
  bool _isActive = true;

  bool _submitted = false;
  bool _saving = false;
  String? _includeError;

  /// What the server said about specific fields on the last save.
  Map<String, String> _serverErrors = const {};

  /// What it said about nothing in particular — a lock, a limit.
  String? _formError;

  static const _fields = [
    'title',
    'description',
    'platform',
    'deliverable_type',
    'quantity',
    'price',
    'turnaround_days',
    'revisions',
    'includes',
    'is_active',
  ];

  static String? _nonEmpty(String? value) =>
      value == null || value.isEmpty ? null : value;

  /// `1500` rather than `1500.0`; paise kept when there are any.
  static String _plain(double value) => value == value.roundToDouble()
      ? value.round().toString()
      : value.toStringAsFixed(2);

  bool get _isNew => widget.existing == null;

  @override
  void initState() {
    super.initState();

    // A new package starts from the API's own defaults: one item, a week,
    // one round of revisions.
    final CreatorPackage? original = widget.existing?.package;

    _title = TextEditingController(text: original?.title ?? '');
    _description = TextEditingController(text: original?.description ?? '');
    _price = TextEditingController(
      text: original == null ? '' : _plain(original.price),
    );
    _quantity = TextEditingController(text: '${original?.quantity ?? 1}');
    _turnaround = TextEditingController(
      text: '${original?.turnaroundDays ?? 7}',
    );
    _revisions = TextEditingController(text: '${original?.revisions ?? 1}');

    _platform = _nonEmpty(original?.platform);
    _deliverable = _nonEmpty(original?.deliverableType);
    _includes.addAll(original?.includes ?? const []);
    _isActive = widget.existing?.isActive ?? true;
  }

  @override
  void dispose() {
    for (final controller in [
      _title,
      _description,
      _price,
      _quantity,
      _turnaround,
      _revisions,
      _includeInput,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  /// Forget the server's complaint about a field once it has been edited.
  void _edited(String field) {
    if (_serverErrors.containsKey(field)) {
      setState(() => _serverErrors = {..._serverErrors}..remove(field));
    }
  }

  void _addInclude() {
    final error = _includes.length >= widget.limits.maxIncludes
        ? 'You can list up to ${widget.limits.maxIncludes} items.'
        : CreatorToolsRules.includeLine(_includeInput.text, widget.limits);

    setState(() {
      _includeError = error;
      if (error == null) {
        _includes.add(_includeInput.text.trim());
        _includeInput.clear();
      }
    });
    _edited('includes');
  }

  Future<void> _save() async {
    setState(() {
      _submitted = true;
      _serverErrors = const {};
      _formError = null;
    });

    // A line typed into "Add an item" but not added with + or Enter used to
    // be dropped without a word. It is added now, or the save stops on its
    // error, beside the box.
    var includeOk = true;
    if (_includeInput.text.trim().isNotEmpty) {
      _addInclude();
      includeOk = _includeError == null;
    }

    final fieldsOk = _formKey.currentState?.validate() ?? false;
    if (!fieldsOk || !includeOk || _platform == null || _deliverable == null) {
      showZSnack(context, 'Check the highlighted fields.', isError: true);
      return;
    }

    final draft = PackageDraft(
      title: _title.text,
      description: _description.text,
      platform: _platform!,
      deliverableType: _deliverable!,
      quantity: int.parse(_quantity.text.trim()),
      price: double.parse(_price.text.trim().replaceAll(',', '')),
      turnaroundDays: int.parse(_turnaround.text.trim()),
      revisions: int.parse(_revisions.text.trim()),
      includes: _includes,
      isActive: _isNew ? null : _isActive,
    );

    setState(() => _saving = true);
    final controller = ref.read(myPackagesProvider.notifier);

    try {
      if (_isNew) {
        await controller.create(draft);
      } else {
        await controller.save(widget.existing!.id, draft);
      }

      if (!mounted) return;
      showZSnack(context, _isNew ? 'Package added.' : 'Package saved.');
      // Opened from a link rather than the list, there is nothing to go
      // back to; the list is where the result shows.
      context.canPop() ? context.pop() : context.go(Routes.myPackages);
    } catch (error) {
      if (!mounted) return;

      final byField = fieldErrors(error, _fields);
      setState(() {
        _serverErrors = byField;
        // A refusal that names no field — a locked section, a lost
        // connection — goes above the form, in the server's words.
        _formError = byField.isEmpty || error is! ValidationFailure
            ? failureMessage(error)
            : null;
      });
      showZSnack(
        context,
        byField.isEmpty ? failureMessage(error) : 'Check the highlighted fields.',
        isError: true,
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);
    final platforms = ref.watch(termMapProvider(Tax.platform));
    final deliverables = ref.watch(termMapProvider(Tax.deliverableType));
    final limits = widget.limits;

    return ZScaffold(
      padded: false,
      appBar: ZAppBar(title: _isNew ? 'New package' : 'Edit package'),
      bottomBar: ZButton(
        label: _isNew ? 'Add package' : 'Save changes',
        isLoading: _saving,
        onPressed: _save,
      ),
      body: Form(
        key: _formKey,
        autovalidateMode: _submitted
            ? AutovalidateMode.onUserInteraction
            : AutovalidateMode.disabled,
        child: ListView(
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xl),
          children: [
            if (widget.locked) const LockedSectionBanner(what: 'Your pricing'),
            if (_formError != null) FormErrorBanner(message: _formError!),

            // -- What it is ----------------------------------------------
            FormSectionCard(
              title: 'The package',
              subtitle: 'What brands see first on your profile.',
              children: [
                ZTextField(
                  label: 'Title',
                  hint: 'e.g. One reel with a story reshare',
                  controller: _title,
                  maxLength: CreatorToolsRules.titleMax,
                  textCapitalization: TextCapitalization.sentences,
                  textInputAction: TextInputAction.next,
                  errorText: _serverErrors['title'],
                  onChanged: (_) => _edited('title'),
                  validator: CreatorToolsRules.packageTitle,
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Description (optional)',
                  hint: 'What the brand gets, and anything you need from them',
                  controller: _description,
                  maxLines: 5,
                  minLines: 3,
                  maxLength: CreatorToolsRules.descriptionMax,
                  textCapitalization: TextCapitalization.sentences,
                  errorText: _serverErrors['description'],
                  onChanged: (_) => _edited('description'),
                  validator: CreatorToolsRules.packageDescription,
                ),
              ],
            ),

            // -- What the brand gets --------------------------------------
            FormSectionCard(
              title: 'Deliverables',
              children: [
                const FieldLabel('Platform'),
                ChoiceChips(
                  options: platforms,
                  value: _platform,
                  onChanged: (value) {
                    setState(() => _platform = value);
                    _edited('platform');
                  },
                ),
                _ChipError(
                  message: _serverErrors['platform'] ??
                      (_submitted && _platform == null ? 'Choose a platform.' : null),
                ),
                const SizedBox(height: AppSpacing.md),
                const FieldLabel('Type of content'),
                ChoiceChips(
                  options: deliverables,
                  value: _deliverable,
                  onChanged: (value) {
                    setState(() => _deliverable = value);
                    _edited('deliverable_type');
                  },
                ),
                _ChipError(
                  message: _serverErrors['deliverable_type'] ??
                      (_submitted && _deliverable == null
                          ? 'Choose the type of content.'
                          : null),
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'How many',
                  helper: 'e.g. 3 for three reels. '
                      '${CreatorToolsRules.quantityMin} to ${CreatorToolsRules.quantityMax}.',
                  controller: _quantity,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  textInputAction: TextInputAction.next,
                  errorText: _serverErrors['quantity'],
                  onChanged: (_) => _edited('quantity'),
                  validator: CreatorToolsRules.quantity,
                ),
              ],
            ),

            // -- Price and delivery ---------------------------------------
            FormSectionCard(
              title: 'Price & delivery',
              children: [
                ZTextField(
                  label: 'Price (₹)',
                  hint: 'e.g. 15000',
                  helper: 'What the brand pays for the whole package, between '
                      '${_rupees(limits.minPrice)} and ${_rupees(limits.maxPrice)}.',
                  controller: _price,
                  prefixIcon: Icons.currency_rupee_rounded,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
                  ],
                  textInputAction: TextInputAction.next,
                  errorText: _serverErrors['price'],
                  onChanged: (_) => _edited('price'),
                  validator: (value) => CreatorToolsRules.price(value, limits),
                ),
                const SizedBox(height: AppSpacing.md),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: ZTextField(
                        label: 'Delivery (days)',
                        helper: '${CreatorToolsRules.turnaroundMin}–'
                            '${CreatorToolsRules.turnaroundMax}',
                        controller: _turnaround,
                        keyboardType: TextInputType.number,
                        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                        textInputAction: TextInputAction.next,
                        errorText: _serverErrors['turnaround_days'],
                        onChanged: (_) => _edited('turnaround_days'),
                        validator: CreatorToolsRules.turnaroundDays,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: ZTextField(
                        label: 'Revisions',
                        helper: '${CreatorToolsRules.revisionsMin}–'
                            '${CreatorToolsRules.revisionsMax} rounds',
                        controller: _revisions,
                        keyboardType: TextInputType.number,
                        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                        textInputAction: TextInputAction.next,
                        errorText: _serverErrors['revisions'],
                        onChanged: (_) => _edited('revisions'),
                        validator: CreatorToolsRules.revisions,
                      ),
                    ),
                  ],
                ),
              ],
            ),

            // -- What's included -----------------------------------------
            FormSectionCard(
              title: "What's included",
              subtitle: 'Optional. Up to ${limits.maxIncludes} short lines, '
                  'shown as a checklist on the package.',
              children: [
                for (var i = 0; i < _includes.length; i++)
                  _IncludeRow(
                    text: _includes[i],
                    onRemove: () {
                      setState(() => _includes.removeAt(i));
                      _edited('includes');
                    },
                  ),
                if (_includes.length < limits.maxIncludes) ...[
                  if (_includes.isNotEmpty) const SizedBox(height: AppSpacing.xs),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: ZTextField(
                          label: 'Add an item',
                          hint: 'e.g. Usage rights for 30 days',
                          controller: _includeInput,
                          maxLength: limits.includeMaxLength,
                          textCapitalization: TextCapitalization.sentences,
                          textInputAction: TextInputAction.done,
                          errorText: _includeError ?? _serverErrors['includes'],
                          onSubmitted: (_) => _addInclude(),
                        ),
                      ),
                      const SizedBox(width: AppSpacing.xs),
                      Padding(
                        padding: const EdgeInsets.only(top: 22),
                        child: IconButton.filledTonal(
                          tooltip: 'Add to the list',
                          icon: const Icon(Icons.add_rounded),
                          onPressed: _addInclude,
                        ),
                      ),
                    ],
                  ),
                ] else
                  _ChipError(message: _serverErrors['includes']),
              ],
            ),

            // -- Visibility (edit only) ------------------------------------
            if (!_isNew)
              FormSectionCard(
                title: 'On your profile',
                children: [
                  SwitchListTile.adaptive(
                    contentPadding: EdgeInsets.zero,
                    value: _isActive,
                    onChanged: (value) {
                      setState(() => _isActive = value);
                      _edited('is_active');
                    },
                    title: Text(
                      'Show this package to brands',
                      style: AppTypography.bodyMedium,
                    ),
                    subtitle: Text(
                      _isActive
                          ? 'Brands can book it from your profile.'
                          : 'Hidden. Bookings already made with it are not affected.',
                      style: AppTypography.bodySmall,
                    ),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  static String _rupees(double amount) => Formatters.currency(amount);
}

/// A validation message under a chip group, which has no field of its own to
/// carry one.
class _ChipError extends StatelessWidget {
  const _ChipError({required this.message});

  final String? message;

  @override
  Widget build(BuildContext context) {
    final text = message;
    if (text == null) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.xs),
      child: Semantics(
        liveRegion: true,
        child: Text(
          text,
          style: AppTypography.bodySmall.copyWith(color: AppColors.error),
        ),
      ),
    );
  }
}

class _IncludeRow extends StatelessWidget {
  const _IncludeRow({required this.text, required this.onRemove});

  final String text;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(Icons.check_circle_rounded, size: 18, color: AppColors.success),
        const SizedBox(width: AppSpacing.xs),
        Expanded(child: Text(text, style: AppTypography.bodyMedium)),
        IconButton(
          tooltip: 'Remove "$text"',
          icon: const Icon(Icons.close_rounded, size: 20),
          color: AppColors.onSurfaceVariant,
          onPressed: onRemove,
        ),
      ],
    );
  }
}
