import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';

/// Serves a canned response, then fails like an unreachable host.
///
/// Driving the real [ApiClient] through a fake adapter exercises the whole
/// path — envelope parsing, cache write, cache replay — rather than testing
/// the cache class in isolation.
class _ScriptedAdapter implements HttpClientAdapter {
  _ScriptedAdapter(this.body);

  final Map<String, dynamic> body;

  /// Flip to make every subsequent request fail at the transport layer.
  bool isDown = false;

  /// How many requests actually reached the "network".
  int calls = 0;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    calls++;

    if (isDown) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'Connection refused',
      );
    }

    return ResponseBody.fromString(
      jsonEncode(body),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
  });

  ApiClient buildClient(_ScriptedAdapter adapter) {
    final dio = Dio()..httpClientAdapter = adapter;

    return ApiClient(
      secureStore: SecureStore(),
      connectivity: ConnectivityService(),
      responseCache: cache,
      dio: dio,
    );
  }

  test('a successful GET is replayed when the host goes away', () async {
    final adapter = _ScriptedAdapter({
      'success': true,
      'message': 'OK',
      'data': {
        'creators': [
          {'id': 7, 'name': 'Aditi Rao'},
        ],
      },
    });

    final client = buildClient(adapter);

    final live = await client.get('/creators');
    expect(live.fromCache, isFalse);
    expect(live.listAt('creators').single['name'], 'Aditi Rao');

    // The backend goes down; the app must still show what the user saw.
    adapter.isDown = true;

    final replayed = await client.get('/creators');
    expect(replayed.fromCache, isTrue);
    expect(replayed.listAt('creators').single['name'], 'Aditi Rao');
  });

  test('an uncached call still fails, rather than inventing data', () async {
    final adapter = _ScriptedAdapter({'success': true, 'message': 'OK'})
      ..isDown = true;

    final client = buildClient(adapter);

    await expectLater(client.get('/never-fetched'), throwsA(isA<ApiFailure>()));
  });

  test('query parameters are part of the cache key', () async {
    final adapter = _ScriptedAdapter({
      'success': true,
      'message': 'OK',
      'data': {'page': 1},
    });

    final client = buildClient(adapter);

    await client.get('/creators', query: {'page': 1});
    adapter.isDown = true;

    // Page 1 is cached...
    final cached = await client.get('/creators', query: {'page': 1});
    expect(cached.fromCache, isTrue);

    // ...but page 2 was never fetched, so it must not borrow page 1's body.
    await expectLater(
      client.get('/creators', query: {'page': 2}),
      throwsA(isA<ApiFailure>()),
    );
  });

  test('an unreachable host is probed once, then backs off', () async {
    final adapter = _ScriptedAdapter({'success': true, 'message': 'OK'})
      ..isDown = true;

    final client = buildClient(adapter);

    for (var i = 0; i < 5; i++) {
      try {
        await client.get('/health');
      } on ApiFailure {
        // Expected: nothing is cached for this path.
      }
    }

    // Only the first request pays a network round trip; the rest are answered
    // from the backoff window without touching the socket.
    expect(adapter.calls, 1);
  });

  test('identity records are never written to the device', () async {
    final adapter = _ScriptedAdapter({
      'success': true,
      'message': 'OK',
      'data': {
        'profile': {'legal_name': 'Aditi Rao', 'pan_masked': 'XXXXXX234F'},
      },
    });

    final client = buildClient(adapter);

    await client.get('/me/kyc');
    await client.get('/me/kyc/timeline');
    await client.get('/me/privacy');

    expect(cache.readDocument('http./me/kyc'), isNull);
    expect(cache.readDocument('http./me/kyc/timeline'), isNull);
    expect(cache.readDocument('http./me/privacy'), isNull);

    // Offline, the screen says so rather than replaying an identity record.
    adapter.isDown = true;
    await expectLater(client.get('/me/kyc'), throwsA(isA<ApiFailure>()));
  });

  test('a path that only shares the prefix is still cached', () async {
    final adapter = _ScriptedAdapter({'success': true, 'message': 'OK'});

    await buildClient(adapter).get('/me/kycfaq');

    expect(cache.readDocument('http./me/kycfaq'), isNotNull);
  });
}
