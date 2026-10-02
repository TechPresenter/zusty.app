import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';

/// Plays the server: an expired access token gets a 401 until the client
/// refreshes, and the refresh hands out a new pair.
class _ExpiringServer implements HttpClientAdapter {
  final requests = <String>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final auth = options.headers['Authorization']?.toString() ?? '';
    requests.add('${options.method} ${options.path} $auth');

    ResponseBody json(int status, Map<String, dynamic> body) =>
        ResponseBody.fromString(
          jsonEncode(body),
          status,
          headers: {
            Headers.contentTypeHeader: [Headers.jsonContentType],
          },
        );

    if (options.path.endsWith('/auth/refresh')) {
      return json(200, {
        'success': true,
        'message': 'OK',
        'data': {
          'tokens': {'access_token': 'fresh', 'refresh_token': 'refresh-2'},
        },
      });
    }

    if (auth == 'Bearer expired') {
      return json(401, {
        'success': false,
        'message': 'Token has expired.',
        'code': 'token_expired',
      });
    }

    return json(200, {
      'success': true,
      'message': 'OK',
      'data': {'items': []},
    });
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  // A GET used to declare 4xx invalid, so Dio threw on a 401 before the client
  // could refresh: an hour after sign-in every screen that loads data failed
  // with "Something went wrong" until the user signed out and back in.
  test('a GET with an expired token refreshes once and retries', () async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    final store = SecureStore();
    await store.save(
      const AuthTokens(accessToken: 'expired', refreshToken: 'refresh-1'),
    );

    final server = _ExpiringServer();
    final client = ApiClient(
      secureStore: store,
      connectivity: ConnectivityService(),
      responseCache: await KeyValueCache.open(),
      dio: Dio()..httpClientAdapter = server,
    );

    final response = await client.get('/bookings');

    expect(response.success, isTrue);
    expect(
      server.requests.where((r) => r.contains('/auth/refresh')),
      hasLength(1),
    );
    expect(server.requests.last, contains('Bearer fresh'));
    expect(await store.readAccessToken(), 'fresh');
  });
}
