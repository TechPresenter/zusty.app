import 'package:flutter/widgets.dart';

import '../../core/motion/motion.dart';

/// A number that counts up to its value the first time it shows, then glides
/// to each new value — for stats, balances and counts.
///
/// [format] turns the in-between value into text (currency, "12.4K", a
/// rating); by default it is rounded to a whole number. Digits use tabular
/// figures so the text does not jitter in width while it counts. Screen
/// readers hear only the final value, not every frame.
class AnimatedCounter extends StatelessWidget {
  const AnimatedCounter({
    super.key,
    required this.value,
    this.format,
    this.style,
    this.textAlign,
    this.duration = Motion.max,
    this.countUp = true,
  });

  final num value;
  final String Function(double value)? format;
  final TextStyle? style;
  final TextAlign? textAlign;
  final Duration duration;

  /// When false the first value appears as is; only later changes animate.
  final bool countUp;

  String _text(double value) => format?.call(value) ?? value.round().toString();

  /// Counts up the number inside already formatted text — "₹12,500",
  /// "4.8", "56.2K", "98%", "1,20,000+" — keeping its prefix, suffix,
  /// decimals and comma grouping (Indian or western). Text with no number in
  /// it is shown as is. The last frame is always [text] exactly.
  static Widget text(
    String text, {
    Key? key,
    TextStyle? style,
    TextAlign? textAlign,
    int? maxLines,
    TextOverflow? overflow,
  }) =>
      _AnimatedNumberText(
        key: key,
        text: text,
        style: style,
        textAlign: textAlign,
        maxLines: maxLines,
        overflow: overflow,
      );

  @override
  Widget build(BuildContext context) {
    final reduced = Motion.reduced(context);
    final end = value.toDouble();
    final resolved = (style ?? DefaultTextStyle.of(context).style).copyWith(
      fontFeatures: const [FontFeature.tabularFigures()],
    );

    return Semantics(
      value: _text(end),
      child: ExcludeSemantics(
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: countUp && !reduced ? 0 : end, end: end),
          duration: reduced ? Duration.zero : duration,
          curve: Motion.standard,
          builder: (context, current, _) => Text(
            _text(current),
            style: resolved,
            textAlign: textAlign,
            maxLines: 1,
            softWrap: false,
          ),
        ),
      ),
    );
  }
}

class _AnimatedNumberText extends StatelessWidget {
  const _AnimatedNumberText({
    super.key,
    required this.text,
    this.style,
    this.textAlign,
    this.maxLines,
    this.overflow,
  });

  final String text;
  final TextStyle? style;
  final TextAlign? textAlign;
  final int? maxLines;
  final TextOverflow? overflow;

  static final RegExp _number = RegExp(r'^(.*?)(\d[\d,]*(?:\.\d+)?)(.*)$', dotAll: true);

  @override
  Widget build(BuildContext context) {
    final match = _number.firstMatch(text);
    final digits = match?.group(2);
    final value = digits == null ? null : double.tryParse(digits.replaceAll(',', ''));

    Text plain(String shown, TextStyle? resolved) => Text(
          shown,
          style: resolved,
          textAlign: textAlign,
          maxLines: maxLines,
          overflow: overflow,
        );

    if (match == null || digits == null || value == null || value == 0 || Motion.reduced(context)) {
      return plain(text, style);
    }

    final prefix = match.group(1) ?? '';
    final suffix = match.group(3) ?? '';
    final decimals = digits.contains('.') ? digits.split('.').last.length : 0;
    final grouped = digits.contains(',');
    final integerGroups = digits.split('.').first.split(',');
    // A two-digit group between the first and the last marks Indian grouping
    // (12,34,567); otherwise commas come every three digits.
    final indian = integerGroups.length > 2 &&
        integerGroups.sublist(1, integerGroups.length - 1).every((g) => g.length == 2);

    String format(double current) {
      final fixed = current.toStringAsFixed(decimals);
      if (!grouped) return '$prefix$fixed$suffix';

      final parts = fixed.split('.');
      final whole = _group(parts.first, indian: indian);
      return '$prefix$whole${parts.length > 1 ? '.${parts[1]}' : ''}$suffix';
    }

    final resolved = (style ?? DefaultTextStyle.of(context).style).copyWith(
      fontFeatures: const [FontFeature.tabularFigures()],
    );

    return Semantics(
      value: text,
      child: ExcludeSemantics(
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: Motion.max,
          curve: Motion.standard,
          builder: (context, t, _) =>
              plain(t >= 1 ? text : format(value * t), resolved),
        ),
      ),
    );
  }

  static String _group(String digits, {required bool indian}) {
    if (digits.length <= 3) return digits;

    final last = digits.substring(digits.length - 3);
    var rest = digits.substring(0, digits.length - 3);
    final step = indian ? 2 : 3;
    final groups = <String>[];

    while (rest.length > step) {
      groups.insert(0, rest.substring(rest.length - step));
      rest = rest.substring(0, rest.length - step);
    }
    if (rest.isNotEmpty) groups.insert(0, rest);

    return '${groups.join(',')},$last';
  }
}
