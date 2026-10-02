import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

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

/// Registers this device's FCM token with the backend once somebody is signed
/// in, and keeps it current when Firebase rotates it.
///
/// Why it lives behind auth rather than in `main()`:
///   * `/me/devices` sits behind `auth` **and** `no-guest`, so registering
///     earlier is an unauthenticated call or a 403;
///   * `requestPermission()` shows a system dialog and `getToken()` does
///     network work — neither belongs on the path to first paint.
class PushController extends Notifier<PushState> {
  StreamSubscription<String>? _refresh;

  @override
  PushState build() {
    ref.onDispose(() => _refresh?.cancel());

    final auth = ref.watch(authProvider);

    // Guests are excluded deliberately: `/me/*` carries `no-guest`.
    if (!auth.isAuthenticated) return const PushState();

    Future.microtask(_register);
    return const PushState(status: PushStatus.registering);
  }

  Future<void> _register() async {
    try {
      final service = ref.read(pushServiceProvider);
      final token = await service.token();

      if (token == null || token.isEmpty) {
        state = const PushState(status: PushStatus.denied);
        return;
      }

      await ref
          .read(accountRepositoryProvider)
          .registerDevice(await service.devicePayload(token));

      _watchTokenRefresh();
      state = PushState(status: PushStatus.registered, token: token);
    } catch (error) {
      // Never fatal: a device that cannot register push must still be able to
      // use the app.
      debugPrint('[Zusty] Push registration failed: $error');
      state = const PushState(status: PushStatus.failed);
    }
  }

  /// Firebase rotates tokens. The server upsert is keyed on
  /// (user_id, device_id), so a refresh updates the row in place.
  void _watchTokenRefresh() {
    _refresh?.cancel();
    _refresh = ref.read(pushServiceProvider).onTokenRefresh.listen((token) async {
      try {
        final service = ref.read(pushServiceProvider);
        await ref
            .read(accountRepositoryProvider)
            .registerDevice(await service.devicePayload(token));
        state = PushState(status: PushStatus.registered, token: token);
      } catch (error) {
        debugPrint('[Zusty] Push token refresh failed: $error');
      }
    });
  }
}

final pushProvider =
    NotifierProvider<PushController, PushState>(PushController.new);
