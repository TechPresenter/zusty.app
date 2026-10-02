import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../models/ai.dart';
import '../../providers/core_providers.dart';
import 'z_button.dart';
import 'z_dialogs.dart';
import 'z_text_field.dart';

/// The "Write with AI" sheet: ask for a draft, show it, hand it back.
///
/// [generate] makes the request with whatever the user typed in the optional
/// input field. The sheet pops with the accepted text, or null when dismissed
/// — the caller decides where the draft goes, and nothing is saved until the
/// user saves the form it lands in.
class AiDraftSheet extends ConsumerStatefulWidget {
  const AiDraftSheet({
    super.key,
    required this.title,
    required this.subtitle,
    required this.generate,
    this.inputLabel,
    this.inputHint,
    this.initialInput = '',
    this.minInputLength = 0,
    this.useLabel = 'Use this',
  });

  final String title;
  final String subtitle;
  final Future<AiDraft> Function(String input) generate;

  /// Shows a text field when set: the creator's notes, the brand's idea.
  final String? inputLabel;
  final String? inputHint;
  final String initialInput;

  /// Zero makes the input optional.
  final int minInputLength;

  /// The accept button, e.g. "Replace description" when it would overwrite.
  final String useLabel;

  static Future<String?> show(
    BuildContext context, {
    required String title,
    required String subtitle,
    required Future<AiDraft> Function(String input) generate,
    String? inputLabel,
    String? inputHint,
    String initialInput = '',
    int minInputLength = 0,
    String useLabel = 'Use this',
  }) =>
      showModalBottomSheet<String>(
        context: context,
        isScrollControlled: true,
        sheetAnimationStyle: zSheetMotion(context),
        builder: (sheetContext) => Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.viewInsetsOf(sheetContext).bottom,
          ),
          child: AiDraftSheet(
            title: title,
            subtitle: subtitle,
            generate: generate,
            inputLabel: inputLabel,
            inputHint: inputHint,
            initialInput: initialInput,
            minInputLength: minInputLength,
            useLabel: useLabel,
          ),
        ),
      );

  @override
  ConsumerState<AiDraftSheet> createState() => _AiDraftSheetState();
}

class _AiDraftSheetState extends ConsumerState<AiDraftSheet> {
  late final _input = TextEditingController(text: widget.initialInput);

  AiDraft? _draft;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _generate() async {
    final input = _input.text.trim();

    if (input.length < widget.minInputLength) {
      setState(() => _error =
          'Describe it in at least ${widget.minInputLength} characters.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final draft = await widget.generate(input);
      if (mounted) setState(() => _draft = draft);
    } on ApiFailure catch (failure) {
      if (!mounted) return;
      setState(() => _error = failure.message);

      // The admin switched this off, or the budget ran out, since the config
      // was last fetched. Refreshing it takes the button away.
      if (failure.statusCode == 403 && failure.code.startsWith('ai_')) {
        ref.read(authConfigProvider.notifier).refresh(force: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final draft = _draft;
    final remaining = draft?.remainingToday;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.auto_awesome_rounded, color: AppColors.primary),
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  child: Text(widget.title, style: AppTypography.headlineSmall),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.base),
            Text(
              widget.subtitle,
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant),
            ),
            if (widget.inputLabel != null) ...[
              const SizedBox(height: AppSpacing.md),
              ZTextField(
                label: widget.inputLabel,
                hint: widget.inputHint,
                controller: _input,
                maxLines: 3,
                minLines: 2,
                maxLength: 500,
                enabled: !_busy,
                textCapitalization: TextCapitalization.sentences,
              ),
            ],
            if (draft != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Container(
                constraints: const BoxConstraints(maxHeight: 280),
                padding: const EdgeInsets.all(AppSpacing.sm),
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: AppRadius.lgAll,
                ),
                child: SingleChildScrollView(
                  child: SelectableText(draft.text, style: AppTypography.bodyMedium),
                ),
              ),
              const SizedBox(height: AppSpacing.xs),
              Text(
                'AI can get things wrong. Check the draft before you save.'
                '${remaining == null ? '' : ' $remaining AI request${remaining == 1 ? '' : 's'} left today.'}',
                style: AppTypography.labelSmall
                    .copyWith(color: AppColors.onSurfaceVariant),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(
                _error!,
                style: AppTypography.bodyMedium.copyWith(color: AppColors.error),
              ),
            ],
            const SizedBox(height: AppSpacing.md),
            if (draft == null)
              ZButton(
                label: _busy ? 'Writing…' : 'Write with AI',
                icon: Icons.auto_awesome_rounded,
                isLoading: _busy,
                onPressed: _busy ? null : _generate,
              )
            else
              Row(
                children: [
                  Expanded(
                    child: ZButton.outlined(
                      label: 'Try again',
                      icon: Icons.refresh_rounded,
                      size: ZButtonSize.medium,
                      isLoading: _busy,
                      onPressed: _busy ? null : _generate,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.xs),
                  Expanded(
                    child: ZButton(
                      label: widget.useLabel,
                      size: ZButtonSize.medium,
                      onPressed: _busy
                          ? null
                          : () => Navigator.of(context).pop(draft.text),
                    ),
                  ),
                ],
              ),
            const SizedBox(height: AppSpacing.xs),
          ],
        ),
      ),
    );
  }
}
