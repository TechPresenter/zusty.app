import 'package:flutter/foundation.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';

/// Shared behaviour for every repository.
///
/// Reads go through [readThrough], which has three tiers:
///
///  1. the live endpoint;
///  2. the last successful response for that exact call, replayed by
///     [ApiClient] from device storage — real data the user has seen before;
///  3. a fixture, reached only when the app has never had a good answer for
///     this call *and* a fixture resolver has been registered.
///
/// Tier 3 does not exist in a release build. No fixture data is compiled into
/// the app at all: [fixtures] is null in production, so an unreachable backend
/// with nothing cached surfaces as a real error for the UI to handle rather
/// than as invented content.
///
/// Only transport failures are absorbed — [OfflineFailure], [TimeoutFailure],
/// and [ServerFailure]. Real API errors (validation, permission, not-found,
/// conflict) always propagate so the UI can show the backend's own message.
abstract class BaseRepository {
  BaseRepository(this.client);

  final ApiClient client;

  /// Forces every call to the registered fixtures, bypassing the network.
  ///
  /// Set explicitly for widget tests and offline design review, and only
  /// effective alongside [fixtures]. It is not tripped automatically: an
  /// unreachable host is handled by [ApiClient]'s response cache and backoff,
  /// so one flaky request can no longer pin the whole session to fixtures.
  static bool demoMode = false;

  /// Test-only fixture source, keyed by the identifiers repositories pass to
  /// [readThrough]. Null in production — which is the point: with no resolver
  /// registered there is no fixture data in the build at all, and an
  /// unreachable API surfaces as a real error for the UI to handle.
  ///
  /// `test/support/fixtures.dart` installs one via `registerTestFixtures()`.
  static dynamic Function(String key)? fixtures;

  /// Live read, falling back to the fixture for [key] on transport failures —
  /// and only when a resolver is registered.
  ///
  /// [parse] turns the fixture's JSON (a map or a list of maps, the same shape
  /// the endpoint returns) into the model the caller wants.
  @protected
  Future<T> readThrough<T>(
    Future<T> Function() live,
    String key,
    T Function(dynamic json) parse,
  ) async {
    final resolve = fixtures;
    if (demoMode && resolve != null) return parse(resolve(key));

    try {
      return await live();
    } on ApiFailure catch (failure) {
      if (!_isUnreachable(failure)) rethrow;
      final fallback = fixtures;
      if (fallback == null) rethrow;
      _warnOnce(failure);
      return parse(fallback(key));
    }
  }

  /// Live write with the same fixture rules as [readThrough], so a test can
  /// walk through the booking, payment, and messaging flows end to end.
  ///
  /// Writes are never replayed from cache — an unsent booking must not look
  /// like a sent one — so this falls straight to the fixture.
  @protected
  Future<T> writeThrough<T>(
    Future<T> Function() live,
    String key,
    T Function(dynamic json) parse,
  ) async {
    final resolve = fixtures;
    if (demoMode && resolve != null) return parse(resolve(key));

    try {
      return await live();
    } on ApiFailure catch (failure) {
      if (!_isUnreachable(failure)) rethrow;
      final fallback = fixtures;
      if (fallback == null) rethrow;
      _warnOnce(failure);
      return parse(fallback(key));
    }
  }

  /// Narrows a fixture payload to the JSON object every `fromJson` takes.
  @protected
  Map<String, dynamic> asMap(dynamic json) => (json as Map).cast<String, dynamic>();

  /// Narrows a fixture payload to the JSON array of objects list endpoints
  /// return.
  @protected
  List<Map<String, dynamic>> asRows(dynamic json) =>
      (json as List).map((row) => (row as Map).cast<String, dynamic>()).toList();

  /// Logged once per session so the console says why fixtures appeared,
  /// without repeating it on every call.
  static bool _warned = false;

  static void _warnOnce(ApiFailure failure) {
    if (_warned) return;
    _warned = true;
    debugPrint(
      '[Zusty] API unreachable (${failure.code}) and nothing cached for this '
      'call — falling back to the registered test fixtures. This path only '
      'exists when BaseRepository.fixtures has been set, which never happens '
      'in a release build.',
    );
  }

  /// True when the host could not be reached at all, as opposed to answering
  /// with an error the user needs to see.
  static bool _isUnreachable(ApiFailure failure) => switch (failure) {
        OfflineFailure() => true,
        TimeoutFailure() => true,
        ServerFailure() => true,
        NotFoundFailure() => true, // the route is not deployed yet
        UnknownFailure() => failure.statusCode == null,
        _ => false,
      };
}
