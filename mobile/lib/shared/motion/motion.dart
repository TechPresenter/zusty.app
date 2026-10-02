/// The app's motion system in one import: the timing and curve tokens, and
/// the animated building blocks screens use.
///
/// Every piece keeps to the 150–400 ms budget on ease-out curves with no
/// overshoot, and every piece checks [Motion.reduced] — under the system's
/// reduced-motion setting, entrances are skipped, loops stop, and changes
/// happen at once.
library;

export '../../core/motion/motion.dart';
export '../../core/motion/page_transitions.dart';
export 'animated_counter.dart';
export 'branch_fade_container.dart';
export 'brand_loader.dart';
export 'hero_slider.dart';
export 'pop_toggle_icon.dart';
export 'pressable.dart';
export 'pulse_dot.dart';
export 'reveal.dart';
export 'success_check.dart';
export 'typing_indicator.dart';
export 'z_refresh.dart';
