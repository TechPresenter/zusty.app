// The motion system: timing tokens, the animated building blocks, and the
// rule every one of them follows — under the system's reduce-motion setting,
// entrances are skipped, loops stop and changes happen at once.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/shared/motion/motion.dart';

Widget _host(Widget child, {bool reduced = false}) => MaterialApp(
      theme: AppTheme.light,
      builder: (context, app) => MediaQuery(
        data: MediaQuery.of(context).copyWith(disableAnimations: reduced),
        child: app!,
      ),
      home: Scaffold(body: Center(child: child)),
    );

/// A tap target with a visible size, for gesture tests.
Widget _box({VoidCallback? onTap}) => GestureDetector(
      onTap: onTap,
      child: const SizedBox(
        width: 120,
        height: 48,
        child: ColoredBox(color: Colors.pink),
      ),
    );

void main() {
  // ---------------------------------------------------------------------------
  group('tokens', () {
    test('every transition duration sits inside the 150–400 ms budget', () {
      for (final duration in [Motion.fast, Motion.medium, Motion.slow, Motion.max]) {
        expect(duration.inMilliseconds, inInclusiveRange(150, 400));
      }
    });

    test('the stagger is capped so long lists never wait', () {
      expect(Motion.staggerFor(2), Motion.stagger * 2);
      expect(Motion.staggerFor(500), Motion.stagger * Motion.staggerLimit);
    });

    testWidgets('reduce-motion collapses durations to zero', (tester) async {
      late bool reduced;
      late Duration duration;

      await tester.pumpWidget(_host(
        Builder(builder: (context) {
          reduced = Motion.reduced(context);
          duration = Motion.of(context, Motion.slow);
          return const SizedBox();
        }),
        reduced: true,
      ));

      expect(reduced, isTrue);
      expect(duration, Duration.zero);
    });
  });

  // ---------------------------------------------------------------------------
  group('Pressable', () {
    double scale(WidgetTester tester) => tester
        .widget<ScaleTransition>(find.descendant(
          of: find.byType(Pressable),
          matching: find.byType(ScaleTransition),
        ))
        .scale
        .value;

    testWidgets('shrinks while held, springs back, and never eats the tap', (tester) async {
      var taps = 0;
      await tester.pumpWidget(_host(Pressable(child: _box(onTap: () => taps++))));

      final gesture = await tester.startGesture(tester.getCenter(find.byType(Pressable)));
      // An animation's clock starts on the frame after it is triggered.
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));
      expect(scale(tester), closeTo(0.97, 0.001));

      await gesture.up();
      await tester.pumpAndSettle();
      expect(scale(tester), 1.0);
      expect(taps, 1);
    });

    testWidgets('a drag the length of a scroll lets go at once', (tester) async {
      await tester.pumpWidget(_host(Pressable(child: _box())));

      final gesture = await tester.startGesture(tester.getCenter(find.byType(Pressable)));
      await tester.pump(const Duration(milliseconds: 100));
      await gesture.moveBy(const Offset(0, 40));
      await tester.pumpAndSettle();

      expect(scale(tester), 1.0, reason: 'still held, but released by the drag');
      await gesture.up();
    });

    testWidgets('stays still when disabled or when motion is reduced', (tester) async {
      for (final (enabled, reduced) in [(false, false), (true, true)]) {
        await tester.pumpWidget(_host(
          Pressable(enabled: enabled, child: _box()),
          reduced: reduced,
        ));

        final gesture = await tester.startGesture(tester.getCenter(find.byType(Pressable)));
        await tester.pump(const Duration(milliseconds: 200));
        expect(scale(tester), 1.0, reason: 'enabled=$enabled reduced=$reduced');
        await gesture.up();
        await tester.pumpAndSettle();
      }
    });
  });

  // ---------------------------------------------------------------------------
  group('Reveal', () {
    double opacity(WidgetTester tester) => tester
        .widget<FadeTransition>(find.descendant(
          of: find.byType(Reveal),
          matching: find.byType(FadeTransition),
        ))
        .opacity
        .value;

    testWidgets('fades in and settles', (tester) async {
      await tester.pumpWidget(_host(const Reveal(child: Text('hello'))));
      expect(opacity(tester), 0);

      await tester.pumpAndSettle();
      expect(opacity(tester), 1);
    });

    testWidgets('appears at rest under reduced motion', (tester) async {
      await tester.pumpWidget(_host(const Reveal(child: Text('hello')), reduced: true));
      expect(opacity(tester), 1);
    });

    testWidgets('inside a RevealScope an item reveals only the first time', (tester) async {
      var shown = true;
      late StateSetter toggle;

      await tester.pumpWidget(_host(RevealScope(
        child: StatefulBuilder(builder: (context, setState) {
          toggle = setState;
          return shown
              ? const Reveal(revealKey: 7, child: Text('row 7'))
              : const SizedBox();
        }),
      )));
      await tester.pumpAndSettle();

      toggle(() => shown = false);
      await tester.pump();
      toggle(() => shown = true);
      await tester.pump();

      expect(opacity(tester), 1, reason: 'scrolled back into view: already revealed');
    });
  });

  // ---------------------------------------------------------------------------
  group('AnimatedCounter', () {
    testWidgets('counts up and lands exactly on the value', (tester) async {
      await tester.pumpWidget(_host(const AnimatedCounter(value: 1250)));
      expect(find.text('0'), findsOneWidget);

      await tester.pumpAndSettle();
      expect(find.text('1250'), findsOneWidget);
    });

    testWidgets('counts inside formatted text and ends on it exactly', (tester) async {
      await tester.pumpWidget(_host(AnimatedCounter.text('₹12,34,567')));
      await tester.pump(const Duration(milliseconds: 200));

      final midway = tester.widget<Text>(find.byType(Text)).data!;
      expect(midway, startsWith('₹'));
      expect(midway, isNot('₹12,34,567'));
      // Indian grouping holds mid-count: a final group of three, pairs before it.
      expect(RegExp(r'^₹(\d{1,2},)?(\d{2},)*\d{1,3}$').hasMatch(midway), isTrue,
          reason: midway);

      await tester.pumpAndSettle();
      expect(find.text('₹12,34,567'), findsOneWidget);
    });

    testWidgets('leaves text with no number in it alone', (tester) async {
      await tester.pumpWidget(_host(AnimatedCounter.text('New')));
      expect(find.text('New'), findsOneWidget);
    });

    testWidgets('screen readers get the final value, not every frame', (tester) async {
      await tester.pumpWidget(_host(AnimatedCounter.text('4.8')));

      expect(
        find.byWidgetPredicate((w) => w is Semantics && w.properties.value == '4.8'),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  group('PopToggleIcon', () {
    testWidgets('swaps to the filled heart when favourited', (tester) async {
      Widget heart(bool on) => _host(AnimatedFavoriteIcon(isFavorite: on, size: 24));

      await tester.pumpWidget(heart(false));
      expect(find.byIcon(Icons.favorite_border_rounded), findsOneWidget);

      await tester.pumpWidget(heart(true));
      await tester.pumpAndSettle();
      expect(find.byIcon(Icons.favorite_rounded), findsOneWidget);
      expect(find.byIcon(Icons.favorite_border_rounded), findsNothing);
    });
  });

  // ---------------------------------------------------------------------------
  group('ambient loops', () {
    testWidgets('the online pulse runs, and draws no ring under reduced motion', (tester) async {
      await tester.pumpWidget(_host(const PulseDot()));
      expect(
        find.descendant(of: find.byType(PulseDot), matching: find.byType(RepaintBoundary)),
        findsOneWidget,
      );
      expect(tester.binding.hasScheduledFrame, isTrue, reason: 'the ring is animating');

      await tester.pumpWidget(_host(const PulseDot(), reduced: true));
      expect(
        find.descendant(of: find.byType(PulseDot), matching: find.byType(RepaintBoundary)),
        findsNothing,
      );
    });

    testWidgets('the typing dots hold still under reduced motion', (tester) async {
      await tester.pumpWidget(_host(const TypingIndicator(), reduced: true));
      await tester.pump();
      expect(tester.binding.hasScheduledFrame, isFalse);
    });

    testWidgets('the success check plays once and finishes', (tester) async {
      await tester.pumpWidget(_host(const SuccessCheck()));
      await tester.pumpAndSettle(); // would time out if it looped
      expect(tester.binding.hasScheduledFrame, isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  group('BranchFadeContainer', () {
    testWidgets('keeps every tab alive and shows only the current one', (tester) async {
      Widget shell(int index) => _host(BranchFadeContainer(
            currentIndex: index,
            children: const [_TapCounter(name: 'home'), _TapCounter(name: 'search')],
          ));

      await tester.pumpWidget(shell(0));
      await tester.tap(find.text('home: 0'));
      await tester.pump();
      expect(find.text('home: 1'), findsOneWidget);

      await tester.pumpWidget(shell(1));
      await tester.pumpAndSettle();
      expect(find.text('search: 0'), findsOneWidget);
      expect(find.text('home: 1', skipOffstage: true), findsNothing,
          reason: 'the old tab is offstage once the fade ends');

      await tester.pumpWidget(shell(0));
      await tester.pumpAndSettle();
      expect(find.text('home: 1'), findsOneWidget, reason: 'its state survived the switch');
    });
  });

  // ---------------------------------------------------------------------------
  group('ZHeroSlider', () {
    Widget slider(List<int> seen, {bool reduced = false}) => _host(
          SizedBox(
            width: 360,
            child: ZHeroSlider(
              itemCount: 3,
              interval: const Duration(seconds: 1),
              onPageChanged: seen.add,
              itemBuilder: (context, index, offset) => Text('slide $index'),
            ),
          ),
          reduced: reduced,
        );

    testWidgets('advances on its own and loops forward to the first slide', (tester) async {
      final seen = <int>[];
      await tester.pumpWidget(slider(seen));

      // Frame by frame, as a device would: five seconds at 20 fps.
      for (var i = 0; i < 100; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }

      expect(seen.take(3), [1, 2, 0]);
    });

    testWidgets('does not auto-play under reduced motion', (tester) async {
      final seen = <int>[];
      await tester.pumpWidget(slider(seen, reduced: true));

      await tester.pump(const Duration(seconds: 3));
      expect(seen, isEmpty);
    });

    testWidgets('still swipes by hand', (tester) async {
      final seen = <int>[];
      await tester.pumpWidget(slider(seen, reduced: true));

      await tester.fling(find.text('slide 0'), const Offset(-300, 0), 1000);
      await tester.pumpAndSettle();
      expect(seen, [1]);
    });
  });

  // ---------------------------------------------------------------------------
  group('page transitions', () {
    testWidgets('reduced motion leaves only a fade', (tester) async {
      late Widget built;

      await tester.pumpWidget(_host(
        Builder(builder: (context) {
          built = const ZFadeSlidePageTransitionsBuilder().buildTransitions(
            MaterialPageRoute<void>(builder: (_) => const SizedBox()),
            context,
            kAlwaysCompleteAnimation,
            kAlwaysDismissedAnimation,
            const SizedBox(),
          );
          return const SizedBox();
        }),
        reduced: true,
      ));

      expect(built, isA<FadeTransition>());
    });

    test('Material platforms get the fade-slide, Apple platforms the native slide', () {
      final builders = ZPageTransitions.theme.builders;
      expect(builders[TargetPlatform.android], isA<ZFadeSlidePageTransitionsBuilder>());
      expect(builders[TargetPlatform.iOS], isA<CupertinoPageTransitionsBuilder>());
      expect(const ZFadeSlidePageTransitionsBuilder().transitionDuration, Motion.slow);
    });
  });
}

/// A tab with state of its own, to prove switching tabs does not reset it.
class _TapCounter extends StatefulWidget {
  const _TapCounter({required this.name});

  final String name;

  @override
  State<_TapCounter> createState() => _TapCounterState();
}

class _TapCounterState extends State<_TapCounter> {
  int _count = 0;

  @override
  Widget build(BuildContext context) => Center(
        child: TextButton(
          onPressed: () => setState(() => _count++),
          child: Text('${widget.name}: $_count'),
        ),
      );
}
