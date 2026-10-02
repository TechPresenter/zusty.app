import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../core/utils/validators.dart';
import '../../models/booking.dart';
import '../../models/creator.dart';
import '../../models/taxonomy.dart';
import '../../providers/booking_provider.dart';
import '../../providers/core_providers.dart';
import '../../providers/discovery_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_avatar.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import '../calendar/booking_calendar_sheet.dart';
import 'widgets/booking_ui.dart';

/// Screen 11 — New Booking Request.
///
/// Collects the brief, deliverables, budget, and timeline required by
/// FR-007, and shows the live escrow breakdown from `GET /bookings/quote`
/// before the brand commits.
class NewBookingScreen extends ConsumerStatefulWidget {
  const NewBookingScreen({super.key, required this.handle, this.packageId});

  final String handle;
  final int? packageId;

  @override
  ConsumerState<NewBookingScreen> createState() => _NewBookingScreenState();
}

class _NewBookingScreenState extends ConsumerState<NewBookingScreen> {
  final _formKey = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _brief = TextEditingController();
  final _budget = TextEditingController();

  String _campaignType = '';
  String _platform = '';
  int _postsCount = 1;
  final Set<String> _deliverables = {'Instagram Reel'};
  DateTimeRange? _dates;
  int? _selectedPackageId;
  bool _submitting = false;

  /// Fields validate as you type only after the first attempt to send, so a
  /// fresh form is not covered in red before anyone has typed.
  AutovalidateMode _autovalidate = AutovalidateMode.disabled;
  bool _showDeliverableError = false;

  @override
  void initState() {
    super.initState();
    _selectedPackageId = widget.packageId;
  }

  @override
  void dispose() {
    _title.dispose();
    _brief.dispose();
    _budget.dispose();
    super.dispose();
  }

  double get _budgetValue =>
      double.tryParse(_budget.text.replaceAll(RegExp(r'[^\d.]'), '')) ?? 0;

  /// Opens the availability-aware calendar rather than the stock Material
  /// range picker, so the brand can only choose dates the creator is open for.
  Future<void> _pickDates(Creator creator) async {
    final picked = await BookingCalendarSheet.show(
      context,
      creatorId: creator.id,
      creatorName: creator.displayName,
      initialStart: _dates?.start,
      initialEnd: _dates?.end,
    );

    if (picked != null) {
      setState(() {
        _dates = DateTimeRange(start: picked.start, end: picked.end);
      });
    }
  }

  Future<void> _submit(Creator creator) async {
    final valid = _formKey.currentState!.validate();

    setState(() {
      _autovalidate = AutovalidateMode.onUserInteraction;
      _showDeliverableError = _deliverables.isEmpty;
    });

    if (!valid) {
      showZSnack(context, 'Check the highlighted fields.', isError: true);
      return;
    }

    if (_deliverables.isEmpty) {
      showZSnack(context, 'Pick at least one deliverable.', isError: true);
      return;
    }

    setState(() => _submitting = true);

    try {
      final booking = await ref
          .read(bookingRepositoryProvider)
          .create(
            creatorId: creator.id,
            title: _title.text.trim(),
            brief: _brief.text.trim(),
            budget: _budgetValue,
            campaignType: _campaignType,
            platform: _platform,
            postsCount: _postsCount,
            packageId: _selectedPackageId,
            startDate: _dates?.start,
            endDate: _dates?.end,
            deliverables: _deliverables.toList(),
          );

      for (final tab in BookingTab.values) {
        ref.invalidate(bookingsProvider(tab));
      }

      if (!mounted) return;
      await _showConfirmation(creator, booking);
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  /// The confirmation state after sending: what happened, what the creator
  /// can do next, and that nothing is charged yet.
  Future<void> _showConfirmation(Creator creator, BookingDetail booking) async {
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      animationStyle: Motion.reduced(context)
          ? AnimationStyle.noAnimation
          : AnimationStyle(
              duration: Motion.medium,
              reverseDuration: Motion.fast,
              curve: Motion.emphasized,
              reverseCurve: Motion.exit,
            ),
      builder: (dialogContext) => Dialog(
        insetPadding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.lg,
            AppSpacing.xl,
            AppSpacing.lg,
            AppSpacing.lg,
          ),
          child: SuccessView(
            title: 'Request sent',
            message:
                '${creator.displayName} has been notified and can accept, '
                'decline, or send a counter-offer. You will only be asked to '
                'pay once the booking is confirmed.',
            primaryLabel: 'View booking',
            onPrimary: () {
              Navigator.of(dialogContext).pop();
              context.pushReplacement(Routes.booking(booking.booking.id));
            },
            secondaryLabel: 'Back to profile',
            onSecondary: () {
              Navigator.of(dialogContext).pop();
              context.pop();
            },
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(creatorProfileProvider(widget.handle));

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'New booking request'),
      bottomBar: profile.maybeWhen(
        data: (data) => Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    Icons.verified_user_rounded,
                    size: 14,
                    color: AppColors.success,
                  ),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(
                      'Nothing is charged until '
                      '${data.creator.displayName.split(' ').first} accepts',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            ZButton(
              label: 'Send request',
              icon: Icons.send_rounded,
              isLoading: _submitting,
              onPressed: () => _submit(data.creator),
            ),
          ],
        ),
        orElse: () => null,
      ),
      body: AsyncView(
        value: profile,
        onRetry: () => ref.invalidate(creatorProfileProvider(widget.handle)),
        builder: (data) => _buildForm(data),
      ),
    );
  }

  Widget _buildForm(CreatorProfile profile) {
    final creator = profile.creator;
    final margin = AppBreakpoints.pageMargin(context);
    // Campaign types, platforms and deliverables are admin-managed.
    final tax = ref.watch(taxonomiesProvider);

    return Form(
      key: _formKey,
      autovalidateMode: _autovalidate,
      child: ListView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: EdgeInsets.fromLTRB(
          margin,
          AppSpacing.xs,
          margin,
          AppSpacing.xl,
        ),
        children: [
          // -- Who you are booking ----------------------------------------
          ZCard(
            child: Row(
              children: [
                ZAvatar(
                  imageUrl: creator.avatarUrl,
                  name: creator.displayName,
                  size: 56,
                  isVerified: creator.isVerified,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Booking',
                        style: AppTypography.labelSmall.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                      Text(
                        creator.displayName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleMedium,
                      ),
                      Text(
                        creator.headline.isEmpty
                            ? (creator.category ?? 'Creator')
                            : creator.headline,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySmall,
                      ),
                      if (creator.priceLabel.isNotEmpty) ...[
                        const SizedBox(height: AppSpacing.xs),
                        StatusChip(
                          label: creator.priceLabel,
                          tone: StatusTone.neutral,
                          icon: Icons.sell_outlined,
                          dense: true,
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),

          // -- Packages -----------------------------------------------------
          if (profile.packages.isNotEmpty) ...[
            const PanelHeading(
              'Start from a package',
              subtitle:
                  'Fills in the title and budget. You can still edit '
                  'both.',
            ),
            ...profile.packages.map(
              (package) => Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: _PackageOption(
                  package: package,
                  selected: _selectedPackageId == package.id,
                  onTap: () => setState(() {
                    if (_selectedPackageId == package.id) {
                      _selectedPackageId = null;
                      return;
                    }
                    _selectedPackageId = package.id;
                    _budget.text = package.price.toStringAsFixed(0);
                    if (_title.text.isEmpty) _title.text = package.title;
                  }),
                ),
              ),
            ),
          ],

          // -- Campaign details ---------------------------------------------
          const PanelHeading('Campaign details'),
          ZCard(
            child: Column(
              children: [
                ZTextField(
                  label: 'Campaign title',
                  hint: 'Summer Glow Product Launch',
                  controller: _title,
                  textCapitalization: TextCapitalization.sentences,
                  textInputAction: TextInputAction.next,
                  validator: (value) =>
                      Validators.minLength(value, 4, field: 'Campaign title'),
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Brief',
                  hint: 'What should the creator make, and what should it say?',
                  controller: _brief,
                  maxLines: 6,
                  minLines: 4,
                  textCapitalization: TextCapitalization.sentences,
                  helper:
                      'Mention the product, the message, tags and '
                      'anything to avoid.',
                  validator: (value) =>
                      Validators.minLength(value, 20, field: 'Brief'),
                ),
              ],
            ),
          ),

          // -- Format -------------------------------------------------------
          const PanelHeading('Format'),
          ZCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const _Label('Campaign type'),
                Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  children: [
                    for (final term in tax.terms(Tax.campaignType))
                      ZChip(
                        label: term.label,
                        selected: _campaignType == term.value,
                        onTap: () => setState(() => _campaignType = term.value),
                      ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                const _Label('Platform'),
                Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  children: [
                    for (final term in tax.terms(Tax.platform))
                      ZChip(
                        label: term.label,
                        selected: _platform == term.value,
                        onTap: () => setState(() => _platform = term.value),
                      ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                const _Label('Deliverables'),
                Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  children: [
                    for (final term in tax.terms(Tax.deliverableType))
                      ZChip(
                        label: term.label,
                        selected: _deliverables.contains(term.value),
                        icon: _deliverables.contains(term.value)
                            ? Icons.check_rounded
                            : null,
                        onTap: () => setState(() {
                          _deliverables.contains(term.value)
                              ? _deliverables.remove(term.value)
                              : _deliverables.add(term.value);
                          if (_deliverables.isNotEmpty) {
                            _showDeliverableError = false;
                          }
                        }),
                      ),
                  ],
                ),
                if (_showDeliverableError && _deliverables.isEmpty) ...[
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    'Pick at least one deliverable.',
                    style: AppTypography.bodySmall.copyWith(
                      color: AppColors.error,
                    ),
                  ),
                ],
                const SizedBox(height: AppSpacing.md),
                Divider(height: 1, color: AppColors.outlineVariant),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Number of posts',
                            style: AppTypography.bodyMedium.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            'Across all deliverables',
                            style: AppTypography.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    _Stepper(
                      value: _postsCount,
                      onChanged: (value) => setState(() => _postsCount = value),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // -- Timeline -----------------------------------------------------
          const PanelHeading(
            'Timeline',
            subtitle: 'Only dates the creator is open for can be picked.',
          ),
          ZCard(
            onTap: () => _pickDates(profile.creator),
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.md,
              vertical: AppSpacing.sm,
            ),
            child: Row(
              children: [
                TintedIconTile(
                  icon: Icons.date_range_rounded,
                  color: _dates == null
                      ? AppColors.onSurfaceVariant
                      : AppColors.primary,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Campaign window',
                        style: AppTypography.labelSmall.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                      Text(
                        _dates == null
                            ? 'Select dates'
                            : '${Formatters.date(_dates!.start)}  →  ${Formatters.date(_dates!.end)}',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodyMedium.copyWith(
                          fontWeight: FontWeight.w600,
                          color: _dates == null
                              ? AppColors.onSurfaceVariant
                              : AppColors.onSurface,
                        ),
                      ),
                    ],
                  ),
                ),
                Icon(
                  Icons.chevron_right_rounded,
                  size: 22,
                  color: AppColors.onSurfaceVariant,
                ),
              ],
            ),
          ),

          // -- Budget -------------------------------------------------------
          const PanelHeading('Budget'),
          ZCard(
            child: Column(
              children: [
                ZTextField(
                  hint: 'Enter your offer',
                  controller: _budget,
                  keyboardType: TextInputType.number,
                  prefixIcon: Icons.currency_rupee_rounded,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  onChanged: (_) => setState(() {}),
                  helper: 'Creator range: ${creator.priceLabel}',
                  validator: (value) =>
                      Validators.amount(value, min: 500, field: 'Budget'),
                ),
                _QuoteBreakdown(creatorId: creator.id, amount: _budgetValue),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          const _EscrowNotice(),
        ],
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Text(
        text,
        style: AppTypography.bodyMedium.copyWith(fontWeight: FontWeight.w600),
      ),
    );
  }
}

class _PackageOption extends StatelessWidget {
  const _PackageOption({
    required this.package,
    required this.selected,
    required this.onTap,
  });

  final CreatorPackage package;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      selected: selected,
      inMutuallyExclusiveGroup: true,
      child: ZCard(
        onTap: onTap,
        padding: const EdgeInsets.all(AppSpacing.sm),
        border: Border.all(
          color: selected ? AppColors.primary : AppColors.outlineVariant,
          width: selected ? 1.6 : 1,
        ),
        color: selected
            ? AppColors.primaryFixed.withValues(alpha: 0.55)
            : AppColors.surfaceContainerLowest,
        shadow: selected,
        child: Row(
          children: [
            Icon(
              selected
                  ? Icons.radio_button_checked_rounded
                  : Icons.radio_button_unchecked_rounded,
              size: 22,
              color: selected ? AppColors.primary : AppColors.outline,
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    package.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodyMedium.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(
                    '${package.turnaroundLabel} · ${package.revisions} revisions',
                    style: AppTypography.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Text(
              package.priceFormatted,
              style: AppTypography.labelLarge.copyWith(
                color: AppColors.primary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Stepper extends StatelessWidget {
  const _Stepper({required this.value, required this.onChanged});

  final int value;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        _StepButton(
          icon: Icons.remove_rounded,
          tooltip: 'Fewer posts',
          onTap: value > 1 ? () => onChanged(value - 1) : null,
        ),
        SizedBox(
          width: 44,
          child: Text(
            '$value',
            textAlign: TextAlign.center,
            style: AppTypography.titleMedium,
          ),
        ),
        _StepButton(
          icon: Icons.add_rounded,
          tooltip: 'More posts',
          onTap: value < 30 ? () => onChanged(value + 1) : null,
        ),
      ],
    );
  }
}

class _StepButton extends StatelessWidget {
  const _StepButton({required this.icon, required this.tooltip, this.onTap});

  final IconData icon;
  final String tooltip;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    return Tooltip(
      message: tooltip,
      child: Material(
        color: enabled
            ? AppColors.primaryFixed
            : AppColors.surfaceContainerHighest,
        borderRadius: AppRadius.mdAll,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: SizedBox(
            width: AppSpacing.minTouch,
            height: AppSpacing.minTouch,
            child: Icon(
              icon,
              size: 20,
              color: enabled ? AppColors.primary : AppColors.outline,
            ),
          ),
        ),
      ),
    );
  }
}

/// Live commission + GST breakdown from `GET /bookings/quote`, shown under
/// the budget field as soon as there is a figure to price.
class _QuoteBreakdown extends ConsumerWidget {
  const _QuoteBreakdown({required this.creatorId, required this.amount});

  final int creatorId;
  final double amount;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (amount <= 0) return const SizedBox.shrink();

    final quote = ref.watch(
      bookingQuoteProvider((
        creatorId: creatorId,
        amount: amount,
        packageId: null,
      )),
    );

    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.sm),
      child: AnimatedSize(
        duration: Motion.of(context, Motion.medium),
        curve: Motion.standard,
        alignment: Alignment.topCenter,
        child: quote.maybeWhen(
          data: (data) => Column(
            children: [
              Divider(height: AppSpacing.md, color: AppColors.outlineVariant),
              ZDetailRow(
                label: 'Creator fee',
                value: Formatters.currency(data.amount),
              ),
              ZDetailRow(
                label: 'GST (${data.taxRate.toStringAsFixed(0)}%)',
                value: Formatters.currency(data.tax),
              ),
              const SizedBox(height: AppSpacing.xs),
              TotalPanel(
                label: 'You pay',
                value: Formatters.currency(data.total),
                caption:
                    'Creator receives '
                    '${Formatters.currency(data.creatorEarning)} after the '
                    '${data.commissionRate.toStringAsFixed(0)}% platform fee.',
              ),
            ],
          ),
          orElse: () => Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
            child: ClipRRect(
              borderRadius: AppRadius.pill,
              child: LinearProgressIndicator(
                minHeight: 3,
                color: AppColors.primary,
                backgroundColor: AppColors.surfaceContainerHigh,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The escrow reassurance panel — the trust guarantee from PRD goal G2.
class _EscrowNotice extends StatelessWidget {
  const _EscrowNotice();

  @override
  Widget build(BuildContext context) {
    return const ZInfoBanner(
      tone: StatusTone.success,
      icon: Icons.verified_user_rounded,
      title: 'Protected by escrow',
      message:
          'Your payment is held in escrow and only released to the '
          'creator after you approve the delivered work.',
    );
  }
}
