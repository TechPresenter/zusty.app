import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../models/safety.dart';
import '../../providers/safety_provider.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_text_field.dart';
import 'block_dialogs.dart';

/// Opens the report sheet for one profile, message, review, booking, post or
/// brief, and files the report with `POST /reports`.
///
/// [name] is whose content it is ("Report Aanya's profile"), when that is
/// known and worth saying. With [blockUserId], the confirmation also offers
/// to block that account, the usual next step after reporting someone.
///
/// Resolves true when a report was filed, or had already been filed and is
/// still open; false when the sheet was closed without one.
Future<bool> showReportSheet(
  BuildContext context, {
  required ReportTarget target,
  required int id,
  String? name,
  int? blockUserId,
}) async {
  final filed = await showZSheet<bool>(
    context,
    title: reportTitle(target, name),
    subtitle:
        'Reports are private. ${name ?? 'The other person'} is not told '
        'who made them.',
    builder: (_) => ReportForm(
      target: target,
      id: id,
      name: name,
      blockUserId: blockUserId,
    ),
  );

  return filed ?? false;
}

/// "Report this message", or "Report Aanya's profile" when the owner is
/// named. A person is reported as themselves: "Report Aanya".
String reportTitle(ReportTarget target, String? name) {
  if (name == null || name.trim().isEmpty) return 'Report this ${target.noun}';
  if (target == ReportTarget.user) return 'Report ${name.trim()}';
  return 'Report ${name.trim()}\'s ${target.noun}';
}

/// Why something is being reported, an optional note, and then what happens
/// next. Lives in [showReportSheet]; public so it can be tested on its own.
class ReportForm extends ConsumerStatefulWidget {
  const ReportForm({
    super.key,
    required this.target,
    required this.id,
    this.name,
    this.blockUserId,
  });

  final ReportTarget target;
  final int id;
  final String? name;
  final int? blockUserId;

  @override
  ConsumerState<ReportForm> createState() => _ReportFormState();
}

class _ReportFormState extends ConsumerState<ReportForm> {
  final _note = TextEditingController();
  String? _reason;
  bool _busy = false;
  String? _error;

  /// The server's acknowledgement once the report is filed; the sheet then
  /// shows the confirmation instead of the form.
  String? _filed;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final reason = _reason;
    if (reason == null) {
      setState(() => _error = 'Choose a reason to continue.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final message = await ref
          .read(safetyActionsProvider)
          .report(
            target: widget.target,
            id: widget.id,
            reason: reason,
            note: _note.text,
          );
      if (mounted) setState(() => _filed = message);
    } on ConflictFailure catch (e) {
      // Already reported and still open: that is not the user's mistake, and
      // the outcome they wanted is in place, so it reads as a confirmation.
      if (!mounted) return;
      setState(() {
        if (e.code == 'already_reported') {
          _filed = e.message;
        } else {
          _error = e.message;
        }
      });
    } on ApiFailure catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _block() async {
    final userId = widget.blockUserId;
    if (userId == null) return;

    final blocked = await confirmBlock(
      context,
      ref,
      userId: userId,
      name: widget.name ?? 'this account',
    );

    if (blocked && mounted) Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: Motion.of(context, Motion.medium),
      switchInCurve: Motion.standard,
      switchOutCurve: Motion.exit,
      child: _filed == null
          ? KeyedSubtree(key: const ValueKey('form'), child: _form())
          : KeyedSubtree(key: const ValueKey('filed'), child: _confirmation()),
    );
  }

  Widget _form() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          'Why are you reporting this ${widget.target.noun}?',
          style: AppTypography.labelLarge,
        ),
        const SizedBox(height: AppSpacing.xs),
        RadioGroup<String>(
          groupValue: _reason,
          onChanged: (value) => setState(() {
            _reason = value;
            _error = null;
          }),
          child: Column(
            children: [
              for (final reason in ReportReason.all)
                RadioListTile<String>(
                  value: reason.code,
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  activeColor: AppColors.primary,
                  title: Text(reason.label, style: AppTypography.bodyMedium),
                ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.xs),
        ZTextField(
          label: 'Anything else we should know? (optional)',
          controller: _note,
          maxLines: 3,
          minLines: 2,
          maxLength: 2000,
          textCapitalization: TextCapitalization.sentences,
        ),
        if (_error != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(
            _error!,
            style: AppTypography.labelMedium.copyWith(color: AppColors.error),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        ZButton(
          label: 'Submit report',
          variant: ZButtonVariant.danger,
          isLoading: _busy,
          onPressed: _submit,
        ),
      ],
    );
  }

  Widget _confirmation() {
    final blockUserId = widget.blockUserId;
    final alreadyBlocked =
        blockUserId != null && ref.watch(isBlockedProvider(blockUserId));
    final name = widget.name;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const SizedBox(height: AppSpacing.sm),
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: AppColors.successContainer,
          ),
          child: Icon(
            Icons.check_rounded,
            size: 32,
            color: AppColors.success,
            semanticLabel: 'Report sent',
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        Text(
          'Report sent',
          textAlign: TextAlign.center,
          style: AppTypography.titleMedium,
        ),
        const SizedBox(height: AppSpacing.xs),
        Text(
          _filed!,
          textAlign: TextAlign.center,
          style: AppTypography.bodyMedium.copyWith(
            color: AppColors.onSurfaceVariant,
          ),
        ),
        if (blockUserId != null && !alreadyBlocked) ...[
          const SizedBox(height: AppSpacing.md),
          Text(
            'If you would rather not hear from ${name ?? 'them'} again, you '
            'can block them too.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.sm),
          ZButton.outlined(
            label: 'Block ${name ?? 'this account'}',
            icon: Icons.block_rounded,
            onPressed: _block,
          ),
        ],
        const SizedBox(height: AppSpacing.xs),
        ZButton(
          label: 'Done',
          onPressed: () => Navigator.of(context).pop(true),
        ),
      ],
    );
  }
}
