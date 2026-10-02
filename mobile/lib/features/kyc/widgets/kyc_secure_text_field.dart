import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/theme/app_theme.dart';

/// The input for an identity or payout number — Aadhaar, PAN, GSTIN, CIN,
/// account number, IFSC, UPI ID, another ID.
///
/// Looks exactly like `ZTextField`, but tells the keyboard to keep its hands
/// off: no suggestions, no autocorrect, and no personalised learning. With
/// those on, Gboard and others remember what is typed and offer it again in
/// other apps — a PAN typed here would turn up as a suggestion in a chat.
class KycSecureTextField extends StatelessWidget {
  const KycSecureTextField({
    super.key,
    required this.label,
    required this.controller,
    this.hint,
    this.helper,
    this.errorText,
    this.validator,
    this.keyboardType,
    this.inputFormatters,
    this.textCapitalization = TextCapitalization.none,
    this.enabled = true,
    this.onChanged,
  });

  final String label;
  final TextEditingController controller;
  final String? hint;
  final String? helper;
  final String? errorText;
  final String? Function(String?)? validator;
  final TextInputType? keyboardType;
  final List<TextInputFormatter>? inputFormatters;
  final TextCapitalization textCapitalization;
  final bool enabled;
  final ValueChanged<String>? onChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: AppTypography.labelLarge.copyWith(fontSize: 13)),
        const SizedBox(height: AppSpacing.xs),
        TextFormField(
          controller: controller,
          validator: validator,
          // A visible-password keyboard is the one Android IMEs reliably
          // treat as "do not learn"; digits keep their number pad.
          keyboardType: keyboardType ?? TextInputType.visiblePassword,
          enableSuggestions: false,
          autocorrect: false,
          enableIMEPersonalizedLearning: false,
          smartDashesType: SmartDashesType.disabled,
          smartQuotesType: SmartQuotesType.disabled,
          enabled: enabled,
          onChanged: onChanged,
          inputFormatters: inputFormatters,
          textCapitalization: textCapitalization,
          style: AppTypography.bodyMedium,
          cursorColor: AppColors.primary,
          scrollPadding: const EdgeInsets.fromLTRB(20, 20, 20, 140),
          decoration: InputDecoration(
            hintText: hint,
            helperText: helper,
            helperStyle: AppTypography.labelSmall,
            helperMaxLines: 3,
            errorText: errorText,
            counterText: '',
          ),
        ),
      ],
    );
  }
}
