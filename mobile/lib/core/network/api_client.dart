import 'dart:async';
import 'dart:developer' as developer;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../config/app_config.dart';
import '../storage/key_value_cache.dart';
import '../storage/secure_store.dart';
import 'api_endpoints.dart';
import 'api_failure.dart';
import 'api_response.dart';
import 'connectivity_service.dart';

/// The single door to the REST API.
///
/// Responsibilities, in the order they run:
///  1. refuse the call with an [OfflineFailure] when the device has no route,
///     rather than letting it time out;
///  2. attach `Authorization: Bearer <jwt>` unless the call opts out;
///  3. unwrap `{success, message, data, meta}` into [ApiResponse];
///  4. translate every failure into an [ApiFailure] subtype — a
///     [DioException] never escapes this class;
///  5. on a 401, refresh once through a single shared future that every
///     concurrent request awaits, then replay the original request exactly
///     once. A replay can never trigger another refresh, so it cannot loop.
class ApiClient {
  ApiClient({
    required SecureStore secureStore,
    required ConnectivityService connectivity,
    KeyValueCache? responseCache,
    Dio? dio,
  }) : _secureStore = secureStore,
       _connectivity = connectivity,
       _responseCache = responseCache,
       _dio = dio ?? Dio() {
    _dio.options = _dio.options.copyWith(
      baseUrl: AppConfig.apiBaseUrl,
      connectTimeout: AppConfig.connectTimeout,
      receiveTimeout: AppConfig.receiveTimeout,
      sendTimeout: AppConfig.sendTimeout,
      headers: {'Accept': 'application/json', 'X-Platform': _platformName},
      // Envelopes are parsed by hand, so every status must reach the parser.
      validateStatus: (_) => true,
    );

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          if (options.extra[_skipAuthKey] != true) {
            final token = await _secureStore.readAccessToken();
            if (token != null) {
              options.headers['Authorization'] = 'Bearer $token';
            }
          }
          if (_loggingEnabled) {
            _log(
              '→ ${options.method} ${redactUri(options.uri)}',
              redactBody(options.uri, options.data),
            );
          }
          handler.next(options);
        },
        onResponse: (response, handler) {
          if (_loggingEnabled) {
            final uri = response.requestOptions.uri;
            _log(
              '← ${response.statusCode} ${redactUri(uri)}',
              redactBody(uri, response.data),
            );
          }
          handler.next(response);
        },
        onError: (error, handler) {
          if (_loggingEnabled) {
            _log(
              '✕ ${error.type.name} ${redactUri(error.requestOptions.uri)}',
              error.message,
            );
          }
          handler.next(error);
        },
      ),
    );
  }

  static const _skipAuthKey = 'zuste.skipAuth';

  /// Namespace for cached GET envelopes, so they can be cleared on sign-out
  /// without touching the rest of the cache.
  static const _cacheNamespace = 'http';

  /// GETs whose bodies are never written to the response cache.
  ///
  /// The cache is plain SharedPreferences. Identity records — KYC details and
  /// document metadata — and privacy exports, which carry an archive link,
  /// must not sit on the device in the clear, so these are always fetched
  /// live and are unavailable offline.
  static const _neverStored = ['/me/kyc', '/me/privacy'];

  static bool _mayStore(String path) => !_neverStored.any(
    (prefix) => path == prefix || path.startsWith('$prefix/'),
  );

  final Dio _dio;
  final SecureStore _secureStore;
  final ConnectivityService _connectivity;

  /// Stores the last successful body of every GET.
  ///
  /// When the backend is unreachable the app replays that body instead of
  /// showing an error — real data the user has seen before, rather than
  /// invented fixtures. Null in contexts that have no storage (tests).
  final KeyValueCache? _responseCache;

  /// Invoked once when the session cannot be recovered, so the app can route
  /// back to the welcome screen. Set by the auth controller during bootstrap.
  VoidCallback? onSessionExpired;

  /// De-duplicates refreshes: the first 401 starts one and every other
  /// in-flight request awaits the same future instead of racing it.
  Future<bool>? _refreshInFlight;

  /// While set, the host is treated as down and requests skip the network.
  ///
  /// Without this, a backend that is simply not running costs every screen a
  /// full connect timeout. One request pays it; the rest are answered from
  /// cache immediately until the window lapses and we probe again.
  DateTime? _unreachableUntil;

  static const _unreachableBackoff = Duration(seconds: 30);

  bool get _isBackingOff =>
      _unreachableUntil != null && DateTime.now().isBefore(_unreachableUntil!);

  /// Extra headers merged into every request — device id and app version, set
  /// once during bootstrap so the backend can bind sessions to a device.
  void setDeviceHeaders({String? deviceId, String? appVersion}) {
    if (deviceId != null && deviceId.isNotEmpty) {
      _dio.options.headers['X-Device-Id'] = deviceId;
    }
    if (appVersion != null && appVersion.isNotEmpty) {
      _dio.options.headers['X-App-Version'] = appVersion;
    }
  }

  // -- Verbs ---------------------------------------------------------------

  /// [headers] carries per-call headers such as `If-None-Match`, so a caller
  /// can make a conditional request without reaching for Dio directly.
  Future<ApiResponse> get(
    String path, {
    Map<String, dynamic>? query,
    bool skipAuth = false,
    CancelToken? cancelToken,
    Map<String, String>? headers,
  }) => _send(
    cacheKey: _mayStore(path) ? _cacheKeyFor(path, query) : null,
    () => _dio.get(
      path,
      queryParameters: _prune(query),
      cancelToken: cancelToken,
      options: Options(
        extra: {_skipAuthKey: skipAuth},
        headers: headers,
        // Every status reaches the envelope parser, as the client-wide
        // default intends: 304 is handled there as "not modified", and a
        // 401 must get there too. Rejecting 4xx here made Dio throw before
        // the refresh-and-retry ran, so once the access token expired
        // (after an hour) every GET failed until the user signed out.
        validateStatus: (_) => true,
      ),
    ),
  );

  /// [receiveTimeout] overrides the client-wide one for a single slow call,
  /// such as an AI request.
  Future<ApiResponse> post(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    bool skipAuth = false,
    CancelToken? cancelToken,
    Duration? receiveTimeout,
  }) => _send(
    () => _dio.post(
      path,
      data: _pruneBody(body),
      queryParameters: _prune(query),
      cancelToken: cancelToken,
      options: Options(
        extra: {_skipAuthKey: skipAuth},
        receiveTimeout: receiveTimeout,
      ),
    ),
  );

  /// [prune] drops null and empty values from a map body, so an untouched
  /// optional field is omitted rather than clearing a column. Pass false for
  /// an endpoint where an explicit null means "clear this field" — the KYC
  /// details form sends only what the user changed, so a null there is a
  /// field the user emptied, and pruning it made the save a silent no-op.
  Future<ApiResponse> put(String path, {Object? body, bool prune = true}) =>
      _send(() => _dio.put(path, data: prune ? _pruneBody(body) : body));

  Future<ApiResponse> patch(String path, {Object? body}) =>
      _send(() => _dio.patch(path, data: _pruneBody(body)));

  Future<ApiResponse> delete(String path, {Object? body}) =>
      _send(() => _dio.delete(path, data: _pruneBody(body)));

  /// Multipart upload for avatars, KYC documents, portfolio media and chat
  /// attachments. [field] is the form key the endpoint expects, e.g. `file`,
  /// `avatar`, `front_image`, `image`, `media`.
  Future<ApiResponse> upload(
    String path, {
    required String field,
    required String filePath,
    String? fileName,
    Map<String, dynamic> fields = const {},
    ProgressCallback? onProgress,
  }) async {
    final form = FormData.fromMap({
      ...?_prune(fields),
      field: await MultipartFile.fromFile(filePath, filename: fileName),
    });

    return _send(() => _dio.post(path, data: form, onSendProgress: onProgress));
  }

  /// Multipart upload from bytes — the only option on Flutter Web, where
  /// `image_picker` hands back bytes rather than a readable path.
  Future<ApiResponse> uploadBytes(
    String path, {
    required String field,
    required Uint8List bytes,
    required String fileName,
    Map<String, dynamic> fields = const {},
    ProgressCallback? onProgress,
  }) {
    final form = FormData.fromMap({
      ...?_prune(fields),
      field: MultipartFile.fromBytes(bytes, filename: fileName),
    });

    return _send(() => _dio.post(path, data: form, onSendProgress: onProgress));
  }

  // -- Response cache ------------------------------------------------------

  /// A stable key for a GET, including its query so `?page=2` does not
  /// overwrite `?page=1`.
  String _cacheKeyFor(String path, Map<String, dynamic>? query) {
    final pruned = _prune(query);

    if (pruned == null || pruned.isEmpty) return '$_cacheNamespace.$path';

    // Sorted so two equivalent calls that differ only in argument order share
    // one entry rather than each caching its own copy.
    final parts = pruned.entries.map((e) => '${e.key}=${e.value}').toList()
      ..sort();

    return '$_cacheNamespace.$path?${parts.join('&')}';
  }

  void _enterBackoff(ApiFailure failure) {
    final wasReachable = !_isBackingOff;
    _unreachableUntil = DateTime.now().add(_unreachableBackoff);

    if (wasReachable) {
      debugPrint(
        '[Zusty] API unreachable (${failure.code}) — replaying cached '
        'responses for ${_unreachableBackoff.inSeconds}s.',
      );
    }
  }

  ApiResponse? _replayFromCache(String? cacheKey) {
    if (cacheKey == null) return null;

    final document = _responseCache?.readDocument(cacheKey);
    if (document == null) return null;

    return ApiResponse.fromJson(document.json, fromCache: true);
  }

  /// True when the host could not be reached at all, as opposed to answering.
  static bool _isTransportFailure(ApiFailure failure) => switch (failure) {
    OfflineFailure() => true,
    TimeoutFailure() => true,
    ServerFailure() => true,
    UnknownFailure() => failure.statusCode == null,
    _ => false,
  };

  // -- Plumbing ------------------------------------------------------------

  Future<ApiResponse> _send(
    Future<Response<dynamic>> Function() request, {
    bool allowRefresh = true,
    String? cacheKey,
  }) async {
    if (!await _connectivity.isOnline()) {
      return _replayFromCache(cacheKey) ?? (throw const OfflineFailure());
    }

    // Inside the backoff window, do not spend another timeout finding out the
    // host is still down.
    if (_isBackingOff) {
      return _replayFromCache(cacheKey) ??
          (throw const ServerFailure(
            message: 'We cannot reach Zusty right now. Please try again.',
          ));
    }

    final Response<dynamic> response;
    try {
      response = await request();
    } on DioException catch (error) {
      final failure = ApiFailureMapper.fromDio(error);
      // Only a transport failure may be answered from cache. A 4xx is the
      // server telling us something true, and must reach the caller.
      if (_isTransportFailure(failure)) {
        _enterBackoff(failure);
        final cached = _replayFromCache(cacheKey);
        if (cached != null) return cached;
      }
      throw failure;
    } on Object {
      throw const UnknownFailure();
    }

    // The host answered, whatever it said — stop backing off.
    _unreachableUntil = null;

    final status = response.statusCode ?? 500;
    final body = response.data;

    // A conditional GET whose validator still matches. There is no body by
    // design — the caller keeps whatever it already cached.
    if (status == 304) {
      return const ApiResponse(
        success: true,
        message: 'Not modified',
        statusCode: 304,
      );
    }

    if (body is! Map) {
      // A 2xx with a non-JSON body, e.g. the HTML invoice rendering.
      if (status >= 200 && status < 300) {
        return ApiResponse(
          success: true,
          message: 'OK',
          data: body,
          statusCode: status,
        );
      }
      throw ApiFailureMapper.fromEnvelope(
        statusCode: status,
        message: 'The server sent an unexpected response.',
        code: 'bad_response',
      );
    }

    final json = Map<String, dynamic>.from(body);
    final envelope = ApiResponse.fromJson(json, statusCode: status);

    if (envelope.success && status >= 200 && status < 300) {
      if (cacheKey != null) {
        // Awaited, not fire-and-forget: the next call may need this body
        // immediately if the host drops between the two, and a racing write
        // would leave that call with nothing to replay.
        await _responseCache?.writeDocument(cacheKey, json);
      }
      return envelope;
    }

    // A 5xx is the backend failing, not answering — the last good body is
    // better than an error screen.
    if (status >= 500) {
      final cached = _replayFromCache(cacheKey);
      if (cached != null) return cached;
    }

    // Exactly one transparent refresh-and-replay per originating request.
    if (status == 401 && allowRefresh) {
      if (await _refreshSession()) {
        return _send(request, allowRefresh: false);
      }

      await _endSession();
      throw UnauthorisedFailure(
        message: envelope.message.isEmpty
            ? 'Your session has ended. Please sign in again.'
            : envelope.message,
      );
    }

    if (status == 401) {
      await _endSession();
    }

    throw ApiFailureMapper.fromEnvelope(
      statusCode: status,
      message: envelope.message,
      code: (json['code'] ?? 'error').toString(),
      errors: _parseFieldErrors(json['errors']),
      retryAfter: _parseRetryAfter(response.headers.value('retry-after')),
    );
  }

  Future<bool> _refreshSession() =>
      _refreshInFlight ??= _performRefresh().whenComplete(() {
        _refreshInFlight = null;
      });

  Future<bool> _performRefresh() async {
    final refreshToken = await _secureStore.readRefreshToken();
    if (refreshToken == null) return false;

    try {
      final response = await _dio.post(
        ApiEndpoints.refresh,
        data: {'refresh_token': refreshToken},
        options: Options(extra: {_skipAuthKey: true}),
      );

      final body = response.data;
      if (body is! Map || body['success'] != true) return false;

      final data = Map<String, dynamic>.from(body['data'] as Map? ?? const {});
      // `/auth/refresh` answers `{tokens: {...}}`; accept an inlined block too.
      final rawTokens = data['tokens'] is Map
          ? Map<String, dynamic>.from(data['tokens'] as Map)
          : data;

      final tokens = AuthTokens.tryParse(rawTokens);
      if (tokens == null) return false;

      await _secureStore.save(tokens);
      return true;
    } on Object {
      return false;
    }
  }

  Future<void> _endSession() async {
    await _secureStore.clear();
    onSessionExpired?.call();
  }

  static Map<String, List<String>> _parseFieldErrors(dynamic raw) {
    if (raw is! Map) return const {};

    return raw.map(
      (key, value) => MapEntry(
        key.toString(),
        value is List
            ? value.map((message) => message.toString()).toList(growable: false)
            : <String>[value.toString()],
      ),
    );
  }

  static Duration? _parseRetryAfter(String? header) {
    final seconds = int.tryParse(header ?? '');
    return seconds == null ? null : Duration(seconds: seconds);
  }

  /// Drops null and empty query values so they are not sent as `key=null`.
  static Map<String, dynamic>? _prune(Map<String, dynamic>? values) {
    if (values == null) return null;

    final pruned = <String, dynamic>{};
    values.forEach((key, value) {
      if (value != null && value != '') pruned[key] = value;
    });

    return pruned.isEmpty ? null : pruned;
  }

  /// Same pruning for a JSON body, so an untouched optional field is omitted
  /// rather than clearing a column server-side.
  static Object? _pruneBody(Object? body) => body is Map<String, dynamic>
      ? (_prune(body) ?? const <String, dynamic>{})
      : body;

  static String get _platformName {
    if (kIsWeb) return 'web';
    return switch (defaultTargetPlatform) {
      TargetPlatform.android => 'android',
      TargetPlatform.iOS => 'ios',
      _ => defaultTargetPlatform.name,
    };
  }

  static bool get _loggingEnabled =>
      kDebugMode && AppConfig.verboseNetworkLogging;

  static void _log(String label, Object? payload) {
    if (!_loggingEnabled) return;

    final body = payload == null ? '' : ' ${_truncate(payload.toString())}';
    developer.log('$label$body', name: 'zuste.api');
  }

  // -- Log redaction ---------------------------------------------------------
  //
  // Debug logs end up in IDE consoles, CI output and bug reports, so nothing
  // that identifies a person or opens a session goes into them: no identity
  // numbers, no tokens, no signed document links.

  /// Endpoints whose bodies are never logged at all — identity documents and
  /// numbers, privacy exports, and the auth routes that carry credentials and
  /// tokens.
  static const _unloggedBodies = [
    '/me/kyc',
    '/kyc',
    '/me/privacy',
    '/privacy',
    '/auth',
  ];

  /// Keys whose values are masked wherever they appear in a logged body.
  static const _sensitiveKeys = {
    'password',
    'password_confirmation',
    'current_password',
    'new_password',
    'token',
    'access_token',
    'refresh_token',
    'id_token',
    'download_token',
    'download_url',
    'preview_url',
    'signature',
    'otp',
    'fcm_token',
    'push_token',
    'device_token',
    'aadhaar',
    'aadhaar_number',
    'aadhaar_last4',
    'pan',
    'pan_number',
    'gst_number',
    'gstin',
    'cin_number',
    'id_proof_number',
    'bank_account_no',
    'bank_account_number',
    'account_number',
    'bank_ifsc',
    'ifsc',
    'upi_id',
  };

  /// Query parameters that carry a credential or a link signature.
  static const _sensitiveQuery = {
    'token',
    'access_token',
    'refresh_token',
    'signature',
    'sig',
    'key',
    'code',
  };

  static final _inlineSecret = RegExp(
    r'([?&](?:token|access_token|refresh_token|signature|sig|key)=)[^&#\s"]+',
    caseSensitive: false,
  );

  static bool _hidesBody(String path) => _unloggedBodies.any(
    (prefix) => path.contains('$prefix/') || path.endsWith(prefix),
  );

  /// [uri] with every credential-bearing query value masked.
  @visibleForTesting
  static String redactUri(Uri uri) {
    final text = uri.toString();
    if (!uri.hasQuery) return text;

    var changed = false;
    final query = uri.query.split('&').map((pair) {
      final split = pair.indexOf('=');
      final key = split < 0 ? pair : pair.substring(0, split);
      if (!_sensitiveQuery.contains(Uri.decodeQueryComponent(key).toLowerCase())) {
        return pair;
      }
      changed = true;
      return '$key=***';
    }).join('&');

    if (!changed) return text;

    final start = text.indexOf('?');
    final end = uri.hasFragment ? text.indexOf('#', start) : text.length;
    return '${text.substring(0, start + 1)}$query${text.substring(end)}';
  }

  /// What may be logged of a request or response body for [uri].
  @visibleForTesting
  static Object? redactBody(Uri uri, Object? payload) {
    if (payload == null) return null;
    if (_hidesBody(uri.path)) return '[redacted]';
    return _redactValue(payload);
  }

  static Object? _redactValue(Object? value) {
    if (value is Map) {
      return value.map(
        (key, item) => MapEntry(
          key,
          _sensitiveKeys.contains(key.toString().toLowerCase())
              ? '***'
              : _redactValue(item),
        ),
      );
    }
    if (value is List) return value.map(_redactValue).toList();
    if (value is String) {
      return value.replaceAllMapped(_inlineSecret, (m) => '${m[1]}***');
    }
    return value;
  }

  static String _truncate(String value, [int max = 1200]) =>
      value.length <= max ? value : '${value.substring(0, max)}…';
}
