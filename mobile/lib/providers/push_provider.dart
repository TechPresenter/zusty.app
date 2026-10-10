import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/auth/social_sign_in_service.dart';
import '../core/messaging/push_service.dart';
import '../core/utils/action_link.dart';
import '../features/notifications/notification_links.dart';
import '../models/auth_config.dart';
import '../router/app_router.dart';
import '../router/routes.dart';
import '../shared/widgets/states.dart';
import 'account_provider.dart';
import 'auth_provider.dart';
import 'core_providers.dart';

enum PushStatus { idle, registering, registered, denied, failed }

@immutable
class PushState {
  const PushState({this.status = PushStatus.idle, this.token});

  final PushStatus status;
  final String? token;

  bool get isRegistered => status == PushStatus.registered;
}

/// Where a tapped push should take the user: what it is about, or the inbox
/// when it is about nothing in particular. Never null, so a tap always lands
/// somewhere that shows the notification.
String pushTarget(PushMessage message) =>
    notificationTarget(message.notification) ?? Routes.notifications;

/// Starts Firebase from the server's options and answers whether it is
/// running. A provider so tests can stand in for Firebase.
final pushFirebaseStarterProvider =
    Provider<Future<bool> Function(FirebaseConfig config)>(
  (ref) => SocialSignInService.ensureFirebase,
);

/// Registers this device's FCM token with the backend once somebody is signed
/// in, keeps it current when Firebase rotates it, and handles the pushes
/// themselves: a tap opens what the push is about, and a push that arrives
/// while the app is open is shown in the app.
///
/// Why it lives behind auth rather than in `main()`:
///   * `/me/devices` sits behind `auth` **and** `no-guest`, so registering
///     earlier is an unauthenticated call or a 403;
///   * `requestPermission()` shows a system dialog and `getToken()` does
///     network work — neither belongs on the path to first paint.
///
/// It only works while something LISTENS to [pushProvider]: Riverpod rebuilds
/// a provider whose dependency changed only if it has listeners, so a single
/// `ref.read` at start-up (while the session is still being restored) built
/// it once, signed out, and it never ran again — no token was ever sent and
/// no tap or foreground handler was ever attached. `ZusteApp` holds a
/// subscription for the app's whole lifetime.
class PushController extends Notifier<PushState> {
  StreamSubscription<String>? _refresh;
  StreamSubscription<PushMessage>? _opened;
  StreamSubscription<PushMessage>? _foreground;

  /// Bumped on every build and disposal. A registration started for an
  /// earlier build — a session that has since signed out, or another
  /// account — sees the mismatch after each await and stops, so it can
  /// neither register the device nor attach handlers for somebody who is no
  /// longer here.
  int _attempt = 0;

  /// The launch push is handled once per process, whichever account is
  /// signed in when registration first succeeds.
  static bool _initialHandled = false;

  @override
  PushState build() {
    // Runs before every rebuild as well as on disposal, so signing out (or
    // switching account) drops the token-refresh, tap and foreground
    // handlers of the session that just ended, and retires any registration
    // still in flight for it.
    ref.onDispose(() {
      _attempt++;
      _cancelSubscriptions();
    });

    // Who is signed in, not the whole auth state: a profile edit or a
    // verification refresh replaces the user object, and must not re-register
    // the device or ask for notification permission again.
    final userId = ref.watch(
      authProvider.select((auth) => auth.isAuthenticated ? auth.user!.id : null),
    );

    final attempt = ++_attempt;

    // Guests are excluded deliberately: `/me/*` carries `no-guest`.
    if (userId == null) return const PushState();

    // On a fresh install `main()` has no cached config, so Firebase cannot
    // start before the first frame and the first attempt fails. Try again the
    // moment the server's Firebase options arrive.
    ref.listen<AuthConfig>(authConfigProvider, (previous, next) {
      final becameUsable =
          next.firebase.isUsable && !(previous?.firebase.isUsable ?? false);

      if (becameUsable && state.status == PushStatus.failed) {
        Future.microtask(() => _register(attempt));
      }
    });

    Future.microtask(() => _register(attempt));
    return const PushState(status: PushStatus.registering);
  }

  /// Whether [attempt] still belongs to the session this controller serves.
  bool _isCurrent(int attempt) => attempt == _attempt;

  Future<void> _register(int attempt) async {
    if (!_isCurrent(attempt)) return;

    try {
      // Push needs a running Firebase app, and nothing else may have started
      // one yet (a first launch has no cached config for `main()` to use).
      final started = await ref.read(pushFirebaseStarterProvider)(
        ref.read(authConfigProvider).firebase,
      );
      if (!_isCurrent(attempt)) return;

      if (!started) {
        state = const PushState(status: PushStatus.failed);
        return;
      }

      final service = ref.read(pushServiceProvider);
      _listenForMessages(service);

      final token = await service.token();
      if (!_isCurrent(attempt)) return;

      if (token == null || token.isEmpty) {
        state = const PushState(status: PushStatus.denied);
        return;
      }

      final payload = await service.devicePayload(token);
      if (!_isCurrent(attempt)) return;

      await ref.read(accountRepositoryProvider).registerDevice(payload);
      if (!_isCurrent(attempt)) return;

      _watchTokenRefresh(attempt);
      state = PushState(status: PushStatus.registered, token: token);
    } catch (error) {
      // Never fatal: a device that cannot register push must still be able to
      // use the app.
      debugPrint('[Zusty] Push registration failed: $error');
      if (_isCurrent(attempt)) {
        state = const PushState(status: PushStatus.failed);
      }
    }
  }

  /// Firebase rotates tokens. The server upsert is keyed on
  /// (user_id, device_id), so a refresh updates the row in place.
  void _watchTokenRefresh(int attempt) {
    _refresh?.cancel();
    _refresh = ref.read(pushServiceProvider).onTokenRefresh.listen((token) async {
      if (!_isCurrent(attempt)) return;

      try {
        final service = ref.read(pushServiceProvider);
        final payload = await service.devicePayload(token);
        if (!_isCurrent(attempt)) return;

        await ref.read(accountRepositoryProvider).registerDevice(payload);
        if (!_isCurrent(attempt)) return;

        state = PushState(status: PushStatus.registered, token: token);
      } catch (error) {
        debugPrint('[Zusty] Push token refresh failed: $error');
      }
    });
  }

  void _listenForMessages(PushService service) {
    _opened?.cancel();
    _opened = service.onMessageOpened.listen(_open);

    _foreground?.cancel();
    _foreground = service.onForegroundMessage.listen(_showInApp);

    if (_initialHandled) return;
    _initialHandled = true;

    service.initialMessage().then((message) {
      if (message != null) _open(message);
    }).catchError((Object error) {
      debugPrint('[Zusty] Could not read the launch notification: $error');
    });
  }

  /// Opens what a tapped push is about.
  ///
  /// After the next frame, so a cold start has mounted the router before it
  /// is asked to navigate.
  void _open(PushMessage message) {
    ref.invalidate(notificationsProvider);

    final target = pushTarget(message);

    WidgetsBinding.instance
      ..addPostFrameCallback((_) => _go(target))
      ..scheduleFrame();
  }

  Future<void> _go(String target) async {
    try {
      if (isExternalLink(target)) {
        final uri = Uri.tryParse(target);
        if (uri != null) {
          await launchUrl(uri, mode: LaunchMode.externalApplication);
        }
        return;
      }

      ref.read(routerProvider).push(target);
    } catch (error) {
      debugPrint('[Zusty] Could not open a notification: $error');
    }
  }

  /// A push that arrives while the app is open: the bell's count and the
  /// inbox are refreshed, and the message shows briefly with a way to open it.
  void _showInApp(PushMessage message) {
    ref.invalidate(notificationsProvider);

    final text = [message.title, message.body]
        .where((part) => part.trim().isNotEmpty)
        .join(' — ');
    if (text.isEmpty) return;

    final context =
        ref.read(routerProvider).routerDelegate.navigatorKey.currentContext;
    if (context == null || !context.mounted) return;

    showZSnack(
      context,
      text,
      actionLabel: 'View',
      onAction: () => _open(message),
    );
  }

  void _cancelSubscriptions() {
    _refresh?.cancel();
    _opened?.cancel();
    _foreground?.cancel();
    _refresh = null;
    _opened = null;
    _foreground = null;
  }
}

final pushProvider =
    NotifierProvider<PushController, PushState>(PushController.new);
