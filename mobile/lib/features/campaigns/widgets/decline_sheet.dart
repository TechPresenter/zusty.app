import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/z_text_field.dart';

/// The answer from [showDeclineSheet]: confirmed, with an optional note.
typedef DeclineDecision = ({String? reason});

/// Confirms declining an applicant (or withdrawing an invitation) and asks,
/// optionally, why.
///
/// The reason is not decoration: the API stores it and the creator sees it
/// on their application, which is the difference between "Declined" and
/// something they can act on next time. Resolves null when the brand backs
/// out.
Future<DeclineDecision?> showDeclineSheet(
  BuildContext context, {
  required String name,
  bool isInvitation = false,
}) {
  return showZSheet<DeclineDecision>(
    context,
    title: isInvitation ? 'Withdraw the invitation?' : 'Decline $name?',
    subtitle: isInvitation
        ? '$name will be told the brief is no longer open to them.'
        : '$name will be told you went another way. A short reason helps '
            'them pitch better next time.',
    builder: (_) => _DeclineForm(isInvitation: isInvitation),
  );
}

class _DeclineForm extends StatefulWidget {
  const _DeclineForm({required this.isInvitation});

  final bool isInvitation;

  @override
  State<_DeclineForm> createState() => _DeclineFormState();
}

class _DeclineFormState extends State<_DeclineForm> {
  final _reason = TextEditingController();

  static const _quickReasons = [
    'Rate is above our budget',
    'Audience is not the right fit',
    'Content style does not match the brief',
    'We have filled the spots',
  ];

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (!widget.isInvitation) ...[
          Text(
            'Quick reasons',
            style: AppTypography.labelLarge.copyWith(color: AppColors.onSurface),
          ),
          const SizedBox(height: AppSpacing.xs),
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              for (final reason in _quickReasons)
                ZChip(
                  label: reason,
                  selected: _reason.text == reason,
                  onTap: () => setState(() {
                    _reason.text = _reason.text == reason ? '' : reason;
                  }),
                ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
        ],
        ZTextField(
          label: 'Note to the creator',
          hint: 'Optional',
          controller: _reason,
          maxLines: 3,
          minLines: 2,
          maxLength: 500,
          textCapitalization: TextCapitalization.sentences,
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: AppSpacing.md),
        ZButton(
          label: widget.isInvitation ? 'Withdraw invitation' : 'Decline application',
          variant: ZButtonVariant.danger,
          onPressed: () {
            final note = _reason.text.trim();
            Navigator.of(context).pop<DeclineDecision>(
              (reason: note.isEmpty ? null : note),
            );
          },
        ),
        const SizedBox(height: AppSpacing.xs),
        ZButton(
          label: 'Not now',
          variant: ZButtonVariant.text,
          onPressed: () => Navigator.of(context).pop(),
        ),
      ],
    );
  }
}
