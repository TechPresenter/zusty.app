import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

/// Whether the device currently has a usable network interface.
///
/// This answers "is there a route out", not "is the API reachable" — a captive
/// portal still reports connected. The API client therefore uses this only to
/// turn what would be a 20-second timeout into an immediate, honest
/// [OfflineFailure], and still surfaces transport errors normally.
class ConnectivityService {
  ConnectivityService({Connectivity? connectivity})
      : _connectivity = connectivity ?? Connectivity();

  final Connectivity _connectivity;

  /// Web has no reliable interface list, so we never pre-empt a request there.
  bool get _canDetect => !kIsWeb;

  Future<bool> isOnline() async {
    if (!_canDetect) return true;

    try {
      final results = await _connectivity.checkConnectivity();
      return _isConnected(results);
    } on Object {
      // A platform channel failure must never block a request.
      return true;
    }
  }

  /// Emits `true` when a connection appears and `false` when it disappears.
  /// Screens listen to this to auto-retry an offline error state.
  Stream<bool> get onStatusChange => _connectivity.onConnectivityChanged
      .map(_isConnected)
      .distinct();

  static bool _isConnected(List<ConnectivityResult> results) =>
      results.isNotEmpty &&
      !results.every((result) => result == ConnectivityResult.none);
}
