import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/theme/app_theme.dart';
import 'voice_input_button.dart';

/// The app's text input. Wraps [TextFormField] with the design system's label
/// treatment, focus ring, and optional password toggle.
class ZTextField extends StatefulWidget {
  const ZTextField({
    super.key,
    this.label,
    this.hint,
    this.controller,
    this.initialValue,
    this.validator,
    this.keyboardType,
    this.obscure = false,
    this.prefixIcon,
    this.suffixIcon,
    this.onSuffixTap,
    this.maxLines = 1,
    this.minLines,
    this.maxLength,
    this.enabled = true,
    this.readOnly = false,
    this.onTap,
    this.onChanged,
    this.onSubmitted,
    this.textInputAction,
    this.inputFormatters,
    this.autofocus = false,
    this.helper,
    this.errorText,
    this.textCapitalization = TextCapitalization.none,
  });

  final String? label;
  final String? hint;
  final TextEditingController? controller;
  final String? initialValue;
  final String? Function(String?)? validator;
  final TextInputType? keyboardType;
  final bool obscure;
  final IconData? prefixIcon;
  final IconData? suffixIcon;
  final VoidCallback? onSuffixTap;
  final int maxLines;
  final int? minLines;
  final int? maxLength;
  final bool enabled;
  final bool readOnly;
  final VoidCallback? onTap;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final TextInputAction? textInputAction;
  final List<TextInputFormatter>? inputFormatters;
  final bool autofocus;
  final String? helper;
  final String? errorText;
  final TextCapitalization textCapitalization;

  @override
  State<ZTextField> createState() => _ZTextFieldState();
}

class _ZTextFieldState extends State<ZTextField> {
  late bool _obscured = widget.obscure;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (widget.label != null) ...[
          Text(
            widget.label!,
            style: AppTypography.labelLarge.copyWith(fontSize: 13),
          ),
          const SizedBox(height: AppSpacing.xs),
        ],
        TextFormField(
          controller: widget.controller,
          initialValue: widget.controller == null ? widget.initialValue : null,
          validator: widget.validator,
          keyboardType: widget.keyboardType,
          obscureText: _obscured,
          maxLines: _obscured ? 1 : widget.maxLines,
          minLines: widget.minLines,
          maxLength: widget.maxLength,
          enabled: widget.enabled,
          readOnly: widget.readOnly,
          onTap: widget.onTap,
          onChanged: widget.onChanged,
          onFieldSubmitted: widget.onSubmitted,
          textInputAction: widget.textInputAction,
          inputFormatters: widget.inputFormatters,
          autofocus: widget.autofocus,
          textCapitalization: widget.textCapitalization,
          style: AppTypography.bodyMedium,
          cursorColor: AppColors.primary,
          // Room for a sticky action bar as well as the keyboard, so the field
          // being typed into is never scrolled underneath either.
          scrollPadding: const EdgeInsets.fromLTRB(20, 20, 20, 140),
          decoration: InputDecoration(
            hintText: widget.hint,
            helperText: widget.helper,
            helperStyle: AppTypography.labelSmall,
            // Helper copy explains a rule; cutting it off mid-sentence hides
            // the rule.
            helperMaxLines: 3,
            errorText: widget.errorText,
            counterText: '',
            prefixIcon: widget.prefixIcon == null
                ? null
                : Icon(widget.prefixIcon, size: 20),
            suffixIcon: _buildSuffix(),
          ),
        ),
      ],
    );
  }

  Widget? _buildSuffix() {
    if (widget.obscure) {
      return IconButton(
        icon: Icon(
          _obscured
              ? Icons.visibility_outlined
              : Icons.visibility_off_outlined,
          size: 20,
        ),
        onPressed: () => setState(() => _obscured = !_obscured),
        tooltip: _obscured ? 'Show password' : 'Hide password',
      );
    }
    if (widget.suffixIcon != null) {
      return IconButton(
        icon: Icon(widget.suffixIcon, size: 20),
        onPressed: widget.onSuffixTap,
      );
    }
    return null;
  }
}

/// The rounded search field from the Home and Search screens, with an optional
/// trailing filter button.
class ZSearchField extends StatelessWidget {
  const ZSearchField({
    super.key,
    this.controller,
    this.hint = 'Search creators, categories...',
    this.onChanged,
    this.onSubmitted,
    this.onFilterTap,
    this.filterCount = 0,
    this.readOnly = false,
    this.onTap,
    this.autofocus = false,
    this.showClear = false,
    this.onClear,
    this.voiceInput = true,
  });

  final TextEditingController? controller;
  final String hint;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final VoidCallback? onFilterTap;

  /// Renders a count badge on the filter button when greater than zero.
  final int filterCount;
  final bool readOnly;
  final VoidCallback? onTap;
  final bool autofocus;
  final bool showClear;
  final VoidCallback? onClear;

  /// Shows a microphone for dictation. On by default, so every search box in
  /// the app gets it from this one widget rather than eight separate copies.
  ///
  /// The button hides itself when the device cannot do speech recognition, so
  /// leaving this on costs nothing where it will not work. Turn it off for a
  /// field that only ever filters a short local list, where dictation is
  /// slower than typing.
  final bool voiceInput;

  @override
  Widget build(BuildContext context) {
    final pill = OutlineInputBorder(
      borderRadius: AppRadius.pill,
      borderSide: BorderSide(color: AppColors.outlineVariant),
    );

    return SizedBox(
      height: 52,
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: controller,
              readOnly: readOnly,
              onTap: onTap,
              onChanged: onChanged,
              onSubmitted: onSubmitted,
              autofocus: autofocus,
              textInputAction: TextInputAction.search,
              style: AppTypography.bodyMedium,
              cursorColor: AppColors.primary,
              decoration: InputDecoration(
                hintText: hint,
                isDense: true,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.sm,
                  vertical: AppSpacing.md - 1,
                ),
                border: pill,
                enabledBorder: pill,
                focusedBorder: pill.copyWith(
                  borderSide: BorderSide(color: AppColors.primary, width: 1.5),
                ),
                prefixIcon: Padding(
                  padding: const EdgeInsets.only(left: AppSpacing.xs),
                  child: Icon(
                    Icons.search_rounded,
                    size: 22,
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
                prefixIconConstraints:
                    const BoxConstraints(minWidth: 44, minHeight: 44),
                // Clear and microphone can both apply, so the suffix is a
                // row that shrink-wraps. mainAxisSize.min matters: without it
                // the Row expands and pushes the text out of the field.
                suffixIcon: (showClear || voiceInput)
                    ? Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (showClear)
                            IconButton(
                              icon: const Icon(Icons.close_rounded, size: 18),
                              onPressed: onClear,
                              tooltip: 'Clear',
                            ),
                          if (voiceInput && !readOnly)
                            VoiceInputButton(
                              // Partial results land in the field as they are
                              // spoken. Writing through the controller keeps
                              // the caller's onChanged the single place that
                              // reacts to a query change, whether it was typed
                              // or dictated.
                              onResult: (text) {
                                controller?.value = TextEditingValue(
                                  text: text,
                                  selection:
                                      TextSelection.collapsed(offset: text.length),
                                );
                                onChanged?.call(text);
                              },
                              // Running the search is deliberately deferred to
                              // the settled transcript. Firing on every partial
                              // would send a request per syllable.
                              onFinal: (text) => onSubmitted?.call(text),
                            ),
                        ],
                      )
                    : null,
                suffixIconConstraints:
                    const BoxConstraints(minWidth: 40, minHeight: 40),
              ),
            ),
          ),
          if (onFilterTap != null) ...[
            const SizedBox(width: AppSpacing.xs),
            Stack(
              clipBehavior: Clip.none,
              children: [
                Material(
                  color: AppColors.primary,
                  shape: const CircleBorder(),
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    onTap: onFilterTap,
                    child: SizedBox(
                      width: 52,
                      height: 52,
                      child: Icon(
                        Icons.tune_rounded,
                        color: AppColors.onPrimary,
                        size: 22,
                        semanticLabel: 'Filters',
                      ),
                    ),
                  ),
                ),
                if (filterCount > 0)
                  Positioned(
                    top: -4,
                    right: -4,
                    child: Container(
                      padding: const EdgeInsets.all(4),
                      constraints:
                          const BoxConstraints(minWidth: 18, minHeight: 18),
                      decoration: BoxDecoration(
                        color: AppColors.secondary,
                        shape: BoxShape.circle,
                        border:
                            Border.all(color: AppColors.surface, width: 1.5),
                      ),
                      child: Text(
                        '$filterCount',
                        textAlign: TextAlign.center,
                        style: AppTypography.labelSmall.copyWith(
                          color: AppColors.onSecondary,
                          fontSize: 10,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
