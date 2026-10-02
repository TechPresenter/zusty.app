import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/motion/motion.dart';
import '../../../core/theme/app_theme.dart';

/// A one-time-code entry drawn as separate digit boxes.
///
/// Underneath it is a single ordinary text field, so paste, the SMS autofill
/// suggestion (`oneTimeCode`), the system keyboard and screen readers all
/// behave as they would on any input; the boxes are only how the digits are
/// shown. The field itself is laid over the boxes with its text and cursor
/// made invisible, so a tap anywhere on the row focuses it.
class OtpCodeField extends StatefulWidget {
  const OtpCodeField({
    super.key,
    required this.controller,
    this.length = 6,
    this.onCompleted,
    this.onChanged,
    this.errorText,
    this.autofocus = false,
    this.enabled = true,
  });

  final TextEditingController controller;
  final int length;

  /// Fired once every box is filled.
  final ValueChanged<String>? onCompleted;
  final ValueChanged<String>? onChanged;
  final String? errorText;
  final bool autofocus;
  final bool enabled;

  @override
  State<OtpCodeField> createState() => _OtpCodeFieldState();
}

class _OtpCodeFieldState extends State<OtpCodeField> {
  final _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_onText);
    _focus.addListener(_onText);
  }

  @override
  void didUpdateWidget(covariant OtpCodeField oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      oldWidget.controller.removeListener(_onText);
      widget.controller.addListener(_onText);
    }
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onText);
    _focus.dispose();
    super.dispose();
  }

  void _onText() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final code = widget.controller.text;
    final hasError = widget.errorText != null;
    final duration = Motion.of(context, Motion.fast);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        LayoutBuilder(
          builder: (context, constraints) {
            const gap = AppSpacing.xs;
            final box =
                ((constraints.maxWidth - gap * (widget.length - 1)) /
                        widget.length)
                    .clamp(36.0, 56.0);

            return SizedBox(
              height: box + 8,
              child: Stack(
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      for (var i = 0; i < widget.length; i++) ...[
                        if (i > 0) const SizedBox(width: gap),
                        _DigitBox(
                          size: box,
                          digit: i < code.length ? code[i] : '',
                          active:
                              _focus.hasFocus &&
                              (i == code.length ||
                                  (i == widget.length - 1 &&
                                      code.length == widget.length)),
                          hasError: hasError,
                          duration: duration,
                        ),
                      ],
                    ],
                  ),
                  // The real input, invisible, covering the row.
                  Positioned.fill(
                    child: Semantics(
                      label: 'Verification code, ${widget.length} digits',
                      child: TextField(
                        controller: widget.controller,
                        focusNode: _focus,
                        enabled: widget.enabled,
                        autofocus: widget.autofocus,
                        keyboardType: TextInputType.number,
                        textInputAction: TextInputAction.done,
                        autofillHints: const [AutofillHints.oneTimeCode],
                        maxLength: widget.length,
                        showCursor: false,
                        // Left on so a long-press still offers Paste for a
                        // code copied from an email.
                        enableInteractiveSelection: true,
                        inputFormatters: [
                          FilteringTextInputFormatter.digitsOnly,
                        ],
                        style: const TextStyle(
                          color: Colors.transparent,
                          fontSize: 1,
                        ),
                        scrollPadding: const EdgeInsets.fromLTRB(
                          20,
                          20,
                          20,
                          160,
                        ),
                        decoration: const InputDecoration(
                          counterText: '',
                          border: InputBorder.none,
                          enabledBorder: InputBorder.none,
                          focusedBorder: InputBorder.none,
                          disabledBorder: InputBorder.none,
                          errorBorder: InputBorder.none,
                          filled: false,
                          contentPadding: EdgeInsets.zero,
                        ),
                        onChanged: (value) {
                          widget.onChanged?.call(value);
                          if (value.length == widget.length) {
                            widget.onCompleted?.call(value);
                          }
                        },
                      ),
                    ),
                  ),
                ],
              ),
            );
          },
        ),
        if (hasError) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(
            widget.errorText!,
            style: AppTypography.bodySmall.copyWith(color: AppColors.error),
          ),
        ],
      ],
    );
  }
}

class _DigitBox extends StatelessWidget {
  const _DigitBox({
    required this.size,
    required this.digit,
    required this.active,
    required this.hasError,
    required this.duration,
  });

  final double size;
  final String digit;
  final bool active;
  final bool hasError;
  final Duration duration;

  @override
  Widget build(BuildContext context) {
    final filled = digit.isNotEmpty;

    final borderColor = hasError
        ? AppColors.error
        : active
        ? AppColors.primary
        : filled
        ? AppColors.primary.withValues(alpha: 0.35)
        : AppColors.outlineVariant;

    return AnimatedContainer(
      duration: duration,
      curve: Motion.standard,
      width: size,
      height: size + 8,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: filled
            ? AppColors.primaryFixed.withValues(alpha: 0.45)
            : AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.mdAll,
        border: Border.all(color: borderColor, width: active ? 1.8 : 1.2),
      ),
      child: Text(
        digit,
        style: AppTypography.headlineSmall.copyWith(
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
