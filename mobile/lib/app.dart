import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/config/app_config.dart';
import 'core/theme/app_theme.dart';
import 'providers/auth_provider.dart';
import 'providers/core_providers.dart';
import 'providers/push_provider.dart';
import 'providers/theme_provider.dart';
import 'router/app_router.dart';
import 'router/routes.dart';
import 'shared/motion/motion.dart';
import 'shared/widgets/zuste_logo.dart';

/// The application root.
///
/// Shows a branded splash while the stored session is restored, then hands off
/// to [routerProvider].
class ZusteApp extends ConsumerStatefulWidget {
  const ZusteApp({super.key});

  @override
  ConsumerState<ZusteApp> createState() => _ZusteAppState();
}

class _ZusteAppState extends ConsumerState<ZusteApp>
    with WidgetsBindingObserver {
  DateTime? _configFetchedAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);

    // A refresh failure must bounce the user back to the login screen.
    ref.read(apiClientProvider).onSessionExpired = () {
      ref.read(authProvider.notifier).restore();
    };

    // Mounted once here because no widget renders push state, and a Notifier
    // nobody reads is never built. It watches `authProvider` and does nothing
    // at all until somebody is signed in.
    ref.read(pushProvider);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _refreshConfig();
  }

  /// Re-fetches `/app/config`.
  ///
  /// The server resolves it per caller — feature flags, menus, popups and the
  /// AI switches all depend on who is signed in — and an admin's change has to
  /// reach an app that is already open. It used to be fetched only when the
  /// sign-in screen was built, so a signed-in user kept the guest answer, or
  /// last launch's, indefinitely.
  void _refreshConfig({bool force = false}) {
    final last = _configFetchedAt;

    if (!force &&
        last != null &&
        DateTime.now().difference(last) < const Duration(minutes: 1)) {
      return;
    }

    _configFetchedAt = DateTime.now();
    ref.read(authConfigProvider.notifier).refresh(force: true);
  }

  @override
  Widget build(BuildContext context) {
    // Signing in, signing out and restoring a session each change who the
    // config is resolved for.
    ref.listen<AuthState>(authProvider, (previous, next) {
      if (!next.isReady) return;

      if (previous?.stage != next.stage || previous?.user?.id != next.user?.id) {
        _refreshConfig(force: true);
      }
    });

    final auth = ref.watch(authProvider);
    final brightness = ref.watch(effectiveBrightnessProvider);

    // Building the theme also sets `AppColors.brightness`, which every token
    // getter reads — so this must happen before any screen builds.
    // Page enter/exit transitions for every route come from the motion system,
    // layered on here so the theme file stays purely visual.
    final theme = AppTheme.of(brightness).copyWith(
      pageTransitionsTheme: ZPageTransitions.theme,
    );

    if (auth.stage == AuthStage.unknown) {
      return MaterialApp(
        title: AppConfig.appName,
        debugShowCheckedModeBanner: false,
        theme: theme,
        home: const _Splash(),
      );
    }

    return MaterialApp.router(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: theme,
      routerConfig: ref.watch(routerProvider),
      builder: (context, child) {
        // Cap text scaling so the dense card layouts stay intact.
        final scale = MediaQuery.textScalerOf(context).clamp(
          minScaleFactor: 0.9,
          maxScaleFactor: 1.25,
        );
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: scale),
          // Screens read colours from the static `AppColors` table rather than
          // an InheritedWidget, so a theme change alone would not rebuild them.
          // Re-keying on brightness forces the whole page subtree to rebuild
          // while leaving the router's own state untouched.
          child: KeyedSubtree(
            key: ValueKey(brightness),
            child: child ?? const SizedBox.shrink(),
          ),
        );
      },
    );
  }
}

/// Branded splash shown while [AuthController.restore] runs: the full launch
/// artwork, with a live brand loader turning where the artwork draws its
/// loading ring.
///
/// The artwork is laid out like `BoxFit.cover`, so on any screen shape the
/// loader is placed by the same scale and offset as the picture under it.
/// The background is white like the native launch screen before it, so the
/// hand-over has no flash.
class _Splash extends StatelessWidget {
  const _Splash();

  /// The artwork's pixel size, and where its loading ring sits in it.
  static const _art = Size(946, 1663);
  static const _ringCentre = Offset(0.499, 0.778);
  static const _ringDiameter = 0.097; // of the artwork's width

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: LayoutBuilder(
        builder: (context, constraints) {
          final w = constraints.maxWidth;
          final h = constraints.maxHeight;
          final scale = w / _art.width > h / _art.height ? w / _art.width : h / _art.height;
          final shownW = _art.width * scale;
          final shownH = _art.height * scale;
          final left = (w - shownW) / 2;
          final top = (h - shownH) / 2;
          final ring = _ringDiameter * shownW;

          return Stack(
            children: [
              Positioned.fill(
                child: Image.asset(
                  BrandAssets.splash,
                  fit: BoxFit.cover,
                  semanticLabel: AppConfig.appName,
                  // Fades in over the white if it decodes a frame late.
                  frameBuilder: (context, child, frame, sync) => sync
                      ? child
                      : AnimatedOpacity(
                          opacity: frame == null ? 0 : 1,
                          duration: Motion.of(context, Motion.fast),
                          curve: Motion.standard,
                          child: child,
                        ),
                ),
              ),
              Positioned(
                left: left + _ringCentre.dx * shownW - ring / 2,
                top: top + _ringCentre.dy * shownH - ring / 2,
                width: ring,
                height: ring,
                child: Reveal(
                  delay: const Duration(milliseconds: 180),
                  child: BrandLoader(size: ring, strokeWidth: (ring * 0.07).clamp(2.0, 4.0)),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// Kept so `Routes` is referenced from the app root — deep links resolve
/// against the same table the router uses.
const String initialRoute = Routes.home;
