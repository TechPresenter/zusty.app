import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/motion/motion.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/z_text_field.dart';

/// A yes/no confirmation for irreversible booking actions.
///
/// Kept as a thin alias over [showZConfirm] so every booking confirmation
/// looks like every other confirmation in the app.
Future<bool?> confirmDialog(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Confirm',
  bool destructive = false,
  IconData? icon,
}) {
  return showZConfirm(
    context,
    title: title,
    message: message,
    confirmLabel: confirmLabel,
    destructive: destructive,
    icon: icon,
  );
}

/// Asks for a written reason before an action that needs one — decline,
/// cancel, request changes, dispute.
///
/// Styled like [showZConfirm] (icon disc, title, explanation, one strong
/// button and a quiet way out) so a reason prompt does not feel like a
/// different app. [minLength] > 0 makes the reason required and says so
/// inline; the dialog never just refuses to close without explaining why,
/// which is what the old prompts did below ten characters.
///
/// Returns null when dismissed, or the trimmed text (possibly empty when the
/// reason is optional) when confirmed.
Future<String?> reasonDialog(
  BuildContext context, {
  required String title,
  required String hint,
  String? message,
  String confirmLabel = 'Confirm',
  String cancelLabel = 'Not now',
  String? fieldLabel,
  bool destructive = true,
  int minLength = 0,
  IconData? icon,
}) {
  return showDialog<String>(
    context: context,
    animationStyle: Motion.reduced(context)
        ? AnimationStyle.noAnimation
        : AnimationStyle(
            duration: Motion.medium,
            reverseDuration: Motion.fast,
            curve: Motion.emphasized,
            reverseCurve: Motion.exit,
          ),
    builder: (_) => _ReasonDialog(
      title: title,
      hint: hint,
      message: message,
      confirmLabel: confirmLabel,
      cancelLabel: cancelLabel,
      fieldLabel: fieldLabel,
      destructive: destructive,
      minLength: minLength,
      icon: icon,
    ),
  );
}

class _ReasonDialog extends StatefulWidget {
  const _ReasonDialog({
    required this.title,
    required this.hint,
    required this.message,
    required this.confirmLabel,
    required this.cancelLabel,
    required this.fieldLabel,
    required this.destructive,
    required this.minLength,
    required this.icon,
  });

  final String title;
  final String hint;
  final String? message;
  final String confirmLabel;
  final String cancelLabel;
  final String? fieldLabel;
  final bool destructive;
  final int minLength;
  final IconData? icon;

  @override
  State<_ReasonDialog> createState() => _ReasonDialogState();
}

class _ReasonDialogState extends State<_ReasonDialog> {
  final _controller = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    final text = _controller.text.trim();

    if (text.length < widget.minLength) {
      setState(() {
        _error = text.isEmpty
            ? 'Please add a short reason.'
            : 'A little more detail, please (at least ${widget.minLength} '
                  'characters).';
      });
      return;
    }

    Navigator.of(context).pop(text);
  }

  @override
  Widget build(BuildContext context) {
    final destructive = widget.destructive;
    final optional = widget.minLength == 0;

    return Dialog(
      insetPadding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.lg,
      ),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.lg,
          AppSpacing.md,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 56,
              height: 56,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: destructive
                    ? AppColors.errorContainer
                    : AppColors.primaryFixed,
              ),
              child: Icon(
                widget.icon ??
                    (destructive
                        ? Icons.warning_amber_rounded
                        : Icons.edit_note_rounded),
                color: destructive ? AppColors.error : AppColors.primary,
                size: 28,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            Text(
              widget.title,
              textAlign: TextAlign.center,
              style: AppTypography.titleMedium,
            ),
            if (widget.message != null) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(
                widget.message!,
                textAlign: TextAlign.center,
                style: AppTypography.bodyMedium.copyWith(
                  color: AppColors.onSurfaceVariant,
                ),
              ),
            ],
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label:
                  widget.fieldLabel ??
                  (optional ? 'Reason (optional)' : 'Reason'),
              controller: _controller,
              hint: widget.hint,
              maxLines: 4,
              minLines: 3,
              autofocus: true,
              textCapitalization: TextCapitalization.sentences,
              errorText: _error,
              onChanged: (_) {
                if (_error != null) setState(() => _error = null);
              },
            ),
            const SizedBox(height: AppSpacing.lg),
            ZButton(
              label: widget.confirmLabel,
              variant: destructive
                  ? ZButtonVariant.danger
                  : ZButtonVariant.primary,
              onPressed: _submit,
            ),
            const SizedBox(height: AppSpacing.xs),
            ZButton(
              label: widget.cancelLabel,
              variant: ZButtonVariant.text,
              onPressed: () => Navigator.of(context).pop(),
            ),
          ],
        ),
      ),
    );
  }
}

/// The heading every booking sheet opens with: a title, a line of context,
/// and an optional chip carrying the figure the sheet is about.
class _SheetHeader extends StatelessWidget {
  const _SheetHeader({required this.title, this.subtitle, this.trailing});

  final String title;
  final String? subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: AppTypography.headlineSmall),
              if (subtitle != null) ...[
                const SizedBox(height: AppSpacing.base),
                Text(
                  subtitle!,
                  style: AppTypography.bodyMedium.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
              ],
            ],
          ),
        ),
        if (trailing != null) ...[
          const SizedBox(width: AppSpacing.sm),
          trailing!,
        ],
      ],
    );
  }
}

/// Lifts a sheet's content above the keyboard and lets it scroll when the
/// keyboard leaves too little room, so the focused field is never hidden.
class _SheetBody extends StatelessWidget {
  const _SheetBody({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.marginMobile,
          0,
          AppSpacing.marginMobile,
          AppSpacing.lg,
        ),
        child: child,
      ),
    );
  }
}

/// What a counter-offer submission carries back.
typedef CounterOfferResult = ({double amount, String? note});

/// The negotiation sheet (FR-008): propose a new figure with an optional note.
class CounterOfferSheet extends StatefulWidget {
  const CounterOfferSheet({super.key, required this.currentAmount});

  final double currentAmount;

  static Future<CounterOfferResult?> show(
    BuildContext context, {
    required double currentAmount,
  }) => showModalBottomSheet<CounterOfferResult>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    sheetAnimationStyle: zSheetMotion(context),
    builder: (_) => CounterOfferSheet(currentAmount: currentAmount),
  );

  @override
  State<CounterOfferSheet> createState() => _CounterOfferSheetState();
}

class _CounterOfferSheetState extends State<CounterOfferSheet> {
  late final _amount = TextEditingController(
    text: widget.currentAmount.toStringAsFixed(0),
  );
  final _note = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  double? get _value =>
      double.tryParse(_amount.text.replaceAll(RegExp(r'[^\d.]'), ''));

  void _submit() {
    final value = _value;

    if (value == null || value <= 0) {
      setState(() => _error = 'Enter the amount you would like to propose.');
      return;
    }

    if (value == widget.currentAmount) {
      setState(
        () => _error = 'That is the current offer. Propose a new figure.',
      );
      return;
    }

    Navigator.of(context).pop((
      amount: value,
      note: _note.text.trim().isEmpty ? null : _note.text.trim(),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final value = _value;
    final difference = value == null ? null : value - widget.currentAmount;

    return _SheetBody(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _SheetHeader(
            title: 'Send a counter-offer',
            subtitle:
                'Propose a new figure. The other side can accept it or '
                'counter again.',
            trailing: StatusChip(
              label: 'Now ${Formatters.currency(widget.currentAmount)}',
              tone: StatusTone.neutral,
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          ZTextField(
            label: 'Your amount',
            controller: _amount,
            keyboardType: TextInputType.number,
            prefixIcon: Icons.currency_rupee_rounded,
            autofocus: true,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            errorText: _error,
            textInputAction: TextInputAction.next,
            onChanged: (_) => setState(() => _error = null),
            helper: difference == null || difference == 0
                ? null
                : '${difference > 0 ? '+' : '−'}'
                      '${Formatters.currency(difference.abs())} against the '
                      'current offer',
          ),
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Note (optional)',
            hint: 'Explain what the new figure covers',
            controller: _note,
            maxLines: 3,
            minLines: 2,
            textCapitalization: TextCapitalization.sentences,
          ),
          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: 'Send counter-offer',
            icon: Icons.send_rounded,
            onPressed: _submit,
          ),
        ],
      ),
    );
  }
}

/// What the deliver sheet returns.
typedef DeliverResult = ({String? note, List<String> urls});

/// The creator's "mark as complete" sheet, per the approval workflow.
class DeliverSheet extends StatefulWidget {
  const DeliverSheet({super.key});

  static Future<DeliverResult?> show(BuildContext context) =>
      showModalBottomSheet<DeliverResult>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        sheetAnimationStyle: zSheetMotion(context),
        builder: (_) => const DeliverSheet(),
      );

  @override
  State<DeliverSheet> createState() => _DeliverSheetState();
}

class _DeliverSheetState extends State<DeliverSheet> {
  final _note = TextEditingController();
  final _url = TextEditingController();
  final _urls = <String>[];
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    _url.dispose();
    super.dispose();
  }

  /// A link is anything with a scheme or a dot in it — strict enough to catch
  /// a stray word, loose enough for every network's share URL.
  static bool _looksLikeLink(String value) =>
      value.contains('.') && !value.contains(' ');

  bool _addUrl() {
    final value = _url.text.trim();
    if (value.isEmpty) return true;

    if (!_looksLikeLink(value)) {
      setState(() => _error = 'That does not look like a link.');
      return false;
    }

    setState(() {
      if (!_urls.contains(value)) _urls.add(value);
      _url.clear();
      _error = null;
    });
    return true;
  }

  void _submit() {
    // A link still sitting in the field counts — people rarely tap "+" for
    // the only link they have.
    if (!_addUrl()) return;

    // The server refuses a delivery without at least one link, so say so
    // here rather than after a round trip.
    if (_urls.isEmpty) {
      setState(() => _error = 'Add at least one link to the published work.');
      return;
    }

    Navigator.of(context).pop((
      note: _note.text.trim().isEmpty ? null : _note.text.trim(),
      urls: List<String>.from(_urls),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return _SheetBody(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const _SheetHeader(
            title: 'Submit deliverables',
            subtitle:
                'The brand reviews and approves before escrow is '
                'released to you.',
          ),
          const SizedBox(height: AppSpacing.lg),
          ZTextField(
            label: 'Link to the published work',
            hint: 'https://instagram.com/reel/...',
            controller: _url,
            keyboardType: TextInputType.url,
            suffixIcon: Icons.add_circle_rounded,
            onSuffixTap: _addUrl,
            onSubmitted: (_) => _addUrl(),
            errorText: _error,
            onChanged: (_) {
              if (_error != null) setState(() => _error = null);
            },
            helper: 'Add one link per post, reel or story.',
          ),
          if (_urls.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            for (final url in _urls)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: _LinkRow(
                  url: url,
                  onRemove: () => setState(() => _urls.remove(url)),
                ),
              ),
          ],
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Note for the brand (optional)',
            hint: 'Anything they should know while reviewing',
            controller: _note,
            maxLines: 3,
            minLines: 2,
            textCapitalization: TextCapitalization.sentences,
          ),
          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: _urls.length > 1
                ? 'Submit ${_urls.length} links for review'
                : 'Submit for review',
            icon: Icons.upload_rounded,
            onPressed: _submit,
          ),
        ],
      ),
    );
  }
}

/// One added link, with a remove control big enough to hit.
class _LinkRow extends StatelessWidget {
  const _LinkRow({required this.url, required this.onRemove});

  final String url;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.only(left: AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: AppRadius.mdAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Row(
        children: [
          Icon(Icons.link_rounded, size: 18, color: AppColors.primary),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              url,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.bodyMedium,
            ),
          ),
          IconButton(
            tooltip: 'Remove link',
            onPressed: onRemove,
            icon: Icon(
              Icons.close_rounded,
              size: 18,
              color: AppColors.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

/// What the review sheet returns.
typedef ReviewResult = ({double rating, String comment});

/// The two-way rating sheet shown once a booking completes (FR-019).
class ReviewSheet extends StatefulWidget {
  const ReviewSheet({super.key});

  static Future<ReviewResult?> show(BuildContext context) =>
      showModalBottomSheet<ReviewResult>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        sheetAnimationStyle: zSheetMotion(context),
        builder: (_) => const ReviewSheet(),
      );

  @override
  State<ReviewSheet> createState() => _ReviewSheetState();
}

class _ReviewSheetState extends State<ReviewSheet> {
  final _comment = TextEditingController();
  double _rating = 5;

  static const _moods = {
    1: 'Poor',
    2: 'Below expectations',
    3: 'Okay',
    4: 'Great',
    5: 'Outstanding',
  };

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  // The written review is optional, as it is on the server: a rating alone
  // is still worth recording.
  void _submit() => Navigator.of(
    context,
  ).pop((rating: _rating, comment: _comment.text.trim()));

  @override
  Widget build(BuildContext context) {
    return _SheetBody(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const _SheetHeader(
            title: 'How did it go?',
            subtitle:
                'Your rating helps other brands and creators decide who '
                'to work with.',
          ),
          const SizedBox(height: AppSpacing.lg),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(5, (i) {
              final value = i + 1;
              final filled = _rating >= value;
              return Semantics(
                button: true,
                selected: _rating == value,
                label: '$value star${value == 1 ? '' : 's'}',
                child: IconButton(
                  iconSize: 38,
                  tooltip: '$value star${value == 1 ? '' : 's'}',
                  onPressed: () => setState(() => _rating = value.toDouble()),
                  icon: AnimatedScale(
                    scale: filled ? 1 : 0.9,
                    duration: Motion.of(context, Motion.fast),
                    curve: Motion.standard,
                    child: Icon(
                      filled ? Icons.star_rounded : Icons.star_outline_rounded,
                      color: filled ? AppColors.star : AppColors.outline,
                    ),
                  ),
                ),
              );
            }),
          ),
          Text(
            _moods[_rating.round()] ?? '',
            textAlign: TextAlign.center,
            style: AppTypography.labelLarge.copyWith(color: AppColors.primary),
          ),
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Your review (optional)',
            hint: 'What worked well? What could be better?',
            controller: _comment,
            maxLines: 4,
            minLines: 3,
            textCapitalization: TextCapitalization.sentences,
          ),
          const SizedBox(height: AppSpacing.lg),
          ZButton(label: 'Submit review', onPressed: _submit),
        ],
      ),
    );
  }
}
