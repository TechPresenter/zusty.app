import 'package:flutter/foundation.dart' show defaultTargetPlatform, kIsWeb;
import 'package:flutter/material.dart';
import 'package:speech_to_text/speech_to_text.dart';

import '../../core/theme/app_theme.dart';
import 'states.dart';

/// Dictation for a text field.
///
/// One widget, dropped into [ZSearchField], so every search box in the app
/// gains voice input from a single place rather than eight copies drifting
/// apart.
///
/// Three rules shape it:
///
///  - **It hides when it cannot work.** Speech recognition is unavailable on
///    plenty of real devices — no recogniser installed, permission refused
///    permanently, an emulator without Google services, most desktop browsers.
///    A microphone that does nothing when tapped is worse than no microphone,
///    so the button is not rendered at all until [SpeechToText.initialize] has
///    said yes.
///  - **Partial results stream into the field.** Waiting for the final result
///    makes dictation feel broken for the two or three seconds it takes; the
///    caller sees the words appear as they are spoken.
///  - **It stops itself.** A listening session that runs until the user
///    remembers to tap again holds the microphone open, which is both a
///    battery and a privacy problem. Recognition ends on the natural pause the
///    platform detects, and a hard ceiling backs that up.
class VoiceInputButton extends StatefulWidget {
  const VoiceInputButton({
    super.key,
    required this.onResult,
    this.onFinal,
    this.tooltip = 'Search by voice',
    this.size = 20,
  });

  /// Called on every partial and final transcript, so the field updates live.
  final ValueChanged<String> onResult;

  /// Called once, with the settled transcript, when listening ends. Screens
  /// use this to run the search rather than firing on every partial word.
  final ValueChanged<String>? onFinal;

  final String tooltip;
  final double size;

  @override
  State<VoiceInputButton> createState() => _VoiceInputButtonState();
}

class _VoiceInputButtonState extends State<VoiceInputButton>
    with SingleTickerProviderStateMixin {
  final _speech = SpeechToText();

  /// Null until initialize() has answered — the button renders nothing while
  /// the answer is unknown, so it never appears and then vanishes.
  bool? _available;
  bool _listening = false;
  String _transcript = '';

  /// Created eagerly in initState, NOT as a `late final` initialiser.
  ///
  /// Lazy initialisation looks tidier and is wrong here: when speech is
  /// unavailable, build() returns early and never touches the controller, so
  /// `dispose()` becomes its first access — and constructing an
  /// AnimationController there does an ancestor lookup on an element that is
  /// already deactivated. Flutter asserts, and every screen holding a search
  /// box fails to tear down.
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();

    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    );

    _probe();
  }

  @override
  void dispose() {
    _pulse.dispose();
    // Releases the microphone if the screen is closed mid-sentence.
    if (_listening) _speech.cancel();
    super.dispose();
  }

  /// On Android and iOS, initialize() itself raises the microphone prompt
  /// whenever access has not been granted yet.
  static bool get _initializePrompts =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  /// Asks the platform whether dictation is possible at all.
  ///
  /// This must not put a system prompt in front of somebody who has merely
  /// opened a search screen. Where initialize() would prompt and access is not
  /// granted yet, the button is offered and initialisation waits for the first
  /// tap, which is where the prompt belongs.
  Future<void> _probe() async {
    try {
      if (_initializePrompts && !await _speech.hasPermission) {
        if (mounted) setState(() => _available = true);
        return;
      }

      final ready = await _initialize();

      if (mounted) setState(() => _available = ready);
    } catch (e) {
      // A platform with no implementation throws rather than returning false.
      debugPrint('[Zusty] speech recognition unavailable: $e');
      if (mounted) setState(() => _available = false);
    }
  }

  Future<bool> _initialize() => _speech.initialize(
        onError: (error) {
          // `error_no_match` and `error_speech_timeout` are ordinary outcomes
          // of saying nothing, not faults; only a genuine failure should stop
          // the animation and reset.
          if (mounted) _stop();
        },
        onStatus: (status) {
          if (status == 'done' || status == 'notListening') {
            if (mounted && _listening) _stop();
          }
        },
        debugLogging: false,
      );

  Future<void> _toggle() async {
    if (_listening) {
      await _speech.stop();
      _stop();
      return;
    }

    if (!_speech.isAvailable) {
      bool ready;

      try {
        ready = await _initialize();
      } catch (e) {
        debugPrint('[Zusty] speech recognition unavailable: $e');
        ready = false;
      }

      if (!mounted) return;

      // Refused, or no recogniser on the device: the button goes, per the
      // rule that it hides when it cannot work, and the user is told why.
      if (!ready) {
        setState(() => _available = false);
        showVoiceUnavailable(context);
        return;
      }
    }

    _transcript = '';

    await _speech.listen(
      onResult: (result) {
        _transcript = result.recognizedWords;
        widget.onResult(_transcript);

        if (result.finalResult) {
          _stop();
        }
      },
      listenOptions: SpeechListenOptions(
        // Words as they are spoken, not one block at the end.
        partialResults: true,
        onDevice: false,
        cancelOnError: true,
        listenMode: ListenMode.search,
        // `pauseFor` ends the session on the natural silence after a phrase.
        // `listenFor` is a ceiling behind it, for the case where the platform
        // never reports that pause and the microphone would otherwise stay
        // open — a battery and a privacy problem, not just an untidy one.
        pauseFor: const Duration(seconds: 3),
        listenFor: const Duration(seconds: 20),
      ),
    );

    if (!mounted) return;

    setState(() => _listening = true);
    _pulse.repeat(reverse: true);
  }

  void _stop() {
    _pulse.stop();
    _pulse.value = 0;

    final settled = _transcript.trim();

    if (mounted) setState(() => _listening = false);

    if (settled.isNotEmpty) widget.onFinal?.call(settled);
  }

  @override
  Widget build(BuildContext context) {
    // Unknown or unsupported: render nothing at all. No dead button, and no
    // layout shift once the answer arrives, because the row it sits in sizes
    // to its other children either way.
    if (_available != true) return const SizedBox.shrink();

    return Semantics(
      button: true,
      label: _listening ? 'Stop listening' : widget.tooltip,
      child: IconButton(
        tooltip: _listening ? 'Stop listening' : widget.tooltip,
        onPressed: _toggle,
        icon: AnimatedBuilder(
          animation: _pulse,
          builder: (context, child) {
            if (!_listening) return child!;

            // A gentle pulse while the microphone is open. It is the only
            // signal that the app is listening, so it is deliberately obvious.
            return Transform.scale(
              scale: 1 + (_pulse.value * 0.18),
              child: Icon(
                Icons.mic_rounded,
                size: widget.size,
                color: AppColors.error,
              ),
            );
          },
          child: Icon(
            Icons.mic_none_rounded,
            size: widget.size,
            color: AppColors.onSurfaceVariant,
          ),
        ),
      ),
    );
  }
}

/// Shows why dictation could not start, in words that suggest a fix.
///
/// Kept next to the button so every caller reports the same thing; a screen
/// that invents its own wording is how "microphone not working" becomes three
/// different bug reports.
void showVoiceUnavailable(BuildContext context) {
  showZSnack(
    context,
    'Voice search needs microphone access. You can turn it on in Settings.',
    isError: true,
  );
}
