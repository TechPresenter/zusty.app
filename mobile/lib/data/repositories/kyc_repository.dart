import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../../core/config/app_config.dart';
import '../../core/network/api_endpoints.dart';
import '../../core/network/api_failure.dart';
import '../../core/storage/secure_store.dart';
import '../../models/kyc.dart';
import 'base_repository.dart';

/// KYC and business verification.
///
/// Every write returns the full overview, so the caller replaces its state
/// from the server's answer rather than guessing what changed — which matters
/// here because a single upload can flip completeness, satisfy a requirement,
/// and unlock the submit button all at once.
class KycRepository extends BaseRepository {
  KycRepository(super.client);

  Future<KycOverview> overview() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.kyc);
          return KycOverview.fromJson(response.dataMap);
        },
        // KYC is a legal record. Showing an invented verification state would
        // be worse than showing the error, so the fixture is an empty draft
        // rather than a fabricated approval.
        'kyc.overview',
        (json) => KycOverview.fromJson(asMap(json)),
      );

  /// Saves part of the detail block. Only the keys sent are touched, so the
  /// caller sends just what the user changed — never a masked identity
  /// number echoed back, which used to overwrite the real one with
  /// `XXXXXX234F`.
  ///
  /// A `null` value clears that field (and, for an identifier, its stored
  /// fingerprint). The body is sent unpruned for exactly that reason: the
  /// client's usual pruning dropped every null, so a cleared address or a
  /// removed CIN never reached the server while the app said "saved".
  ///
  /// [consent] is the user's agreement to their details being processed,
  /// asked for before the first save (see [KycProfile.processingConsentRequired]).
  Future<KycOverview> saveDetails(
    Map<String, dynamic> details, {
    bool consent = false,
  }) async {
    final response = await client.put(
      ApiEndpoints.kycDetails,
      body: {...details, if (consent) 'consent': true},
      prune: false,
    );
    return KycOverview.fromJson(response.dataMap);
  }

  /// Uploads a file into [slot] from a readable path (Android, iOS, desktop).
  ///
  /// [replaceDocumentId] uploads a new version of that document: the old one
  /// is kept for the reviewer's history but stops being current. It is how a
  /// single-file slot is re-uploaded, including one the reviewer flagged.
  ///
  /// [consent] carries the processing consent asked for before the first
  /// upload; the server refuses that upload without it.
  Future<KycOverview> uploadDocument({
    required String slot,
    required String filePath,
    String? fileName,
    String? expiresOn,
    int? replaceDocumentId,
    bool consent = false,
    void Function(int sent, int total)? onProgress,
  }) async {
    final response = await client.upload(
      ApiEndpoints.kycDocuments,
      field: 'file',
      filePath: filePath,
      fileName: fileName,
      fields: _uploadFields(slot, expiresOn, replaceDocumentId, consent),
      onProgress: onProgress,
    );

    return KycOverview.fromJson(_overviewOf(response.dataMap));
  }

  /// Uploads from bytes — the only option on Flutter Web, where the picker
  /// hands back bytes rather than a path.
  Future<KycOverview> uploadDocumentBytes({
    required String slot,
    required Uint8List bytes,
    required String fileName,
    String? expiresOn,
    int? replaceDocumentId,
    bool consent = false,
    void Function(int sent, int total)? onProgress,
  }) async {
    final response = await client.uploadBytes(
      ApiEndpoints.kycDocuments,
      field: 'file',
      bytes: bytes,
      fileName: fileName,
      fields: _uploadFields(slot, expiresOn, replaceDocumentId, consent),
      onProgress: onProgress,
    );

    return KycOverview.fromJson(_overviewOf(response.dataMap));
  }

  Future<KycOverview> deleteDocument(int id) async {
    final response = await client.delete(ApiEndpoints.kycDocument(id));
    return KycOverview.fromJson(response.dataMap);
  }

  /// Hands the profile to the review queue.
  ///
  /// [consent] has to be an explicit `true` from a box the user ticked — the
  /// server refuses a submission without it, and it is never assumed here.
  /// The backend also rejects a submission that is missing a mandatory
  /// document, so the CTA can stay optimistic.
  Future<KycOverview> submit({bool consent = false}) async {
    final response = await client.post(
      ApiEndpoints.kycSubmit,
      body: consent ? const {'consent': true} : null,
    );
    return KycOverview.fromJson(response.dataMap);
  }

  Future<List<KycEvent>> timeline() async {
    final response = await client.get(ApiEndpoints.kycTimeline);
    return response
        .listAt('timeline')
        .map(KycEvent.fromJson)
        .where((event) => event.isUserFacing)
        .toList();
  }

  /// The public rulebook, for showing requirements before sign-up.
  Future<List<KycRequirement>> requirements(String kind) => readThrough(
        () async {
          final response = await client.get(
            '${ApiEndpoints.kycRequirements}?kind=$kind',
            skipAuth: true,
          );
          return response
              .listAt('requirements')
              .map(KycRequirement.fromJson)
              .toList();
        },
        'kyc.requirements',
        (json) => asRows(json).map(KycRequirement.fromJson).toList(),
      );

  Map<String, dynamic> _uploadFields(
    String slot,
    String? expiresOn,
    int? replaceDocumentId,
    bool consent,
  ) =>
      {
        'slot': slot,
        'expires_on': expiresOn,
        'replace_document_id': replaceDocumentId?.toString(),
        // Multipart fields are text; the server reads 'true' as a boolean.
        if (consent) 'consent': 'true',
      };

  /// The upload endpoint nests the overview beside the new documents; every
  /// other endpoint returns it at the top level.
  Map<String, dynamic> _overviewOf(Map<String, dynamic> data) {
    final nested = data['overview'];
    return nested is Map<String, dynamic> ? nested : data;
  }
}

/// A verification document fetched for viewing. Held in memory only; the one
/// way it reaches the disk is an explicit "Open in another app", which goes
/// through `KycShareCache` and is deleted again.
class KycFile {
  const KycFile({
    required this.bytes,
    required this.mimeType,
    required this.fileName,
  });

  final Uint8List bytes;
  final String mimeType;
  final String fileName;

  bool get isImage => mimeType.startsWith('image/');
}

/// Thrown when a preview link or the session has gone stale, so the caller
/// can fetch a fresh overview (which also refreshes the session) and retry.
class KycLinkExpired implements Exception {
  const KycLinkExpired();
}

/// Fetches the bytes of the user's own verification documents.
///
/// The preview route streams the decrypted file, which the shared API client
/// cannot carry: it reads every body as JSON or text, and a JPEG decoded as
/// text is no longer a JPEG. So this makes the one binary call itself, with
/// the same bearer token from the same secure store.
///
/// The token is only ever sent to the configured API origin. A link the
/// server built with a different host (a `localhost` APP_URL seen from an
/// emulator, say) is re-rooted onto that origin rather than followed, so a
/// bad link can never carry the session to somebody else's server.
class KycDocumentLoader {
  KycDocumentLoader({required SecureStore secureStore, Dio? dio})
      : _secureStore = secureStore,
        _dio = dio ??
            Dio(
              BaseOptions(
                connectTimeout: AppConfig.connectTimeout,
                receiveTimeout: AppConfig.receiveTimeout,
                responseType: ResponseType.bytes,
                // Read every status so the error envelope can be surfaced.
                validateStatus: (_) => true,
              ),
            );

  final SecureStore _secureStore;
  final Dio _dio;

  Future<KycFile> fetch(KycDocument document) async {
    final url = resolve(document);
    if (url == null) {
      throw const NotFoundFailure(
        message: 'This document cannot be opened right now.',
      );
    }

    final token = await _secureStore.readAccessToken();

    final Response<List<int>> response;
    try {
      response = await _dio.get<List<int>>(
        url.toString(),
        options: Options(
          headers: {
            'Accept': '*/*',
            if (token != null) 'Authorization': 'Bearer $token',
          },
        ),
      );
    } on DioException catch (error) {
      throw ApiFailureMapper.fromDio(error);
    }

    final status = response.statusCode ?? 500;
    final body = response.data ?? const <int>[];

    // An expired session (401) or an expired signed link (403) both clear up
    // with a fresh overview, which the caller fetches before one retry.
    if (status == 401 || status == 403) throw const KycLinkExpired();

    if (status < 200 || status >= 300) {
      throw ApiFailureMapper.fromEnvelope(
        statusCode: status,
        message: _messageFrom(body),
        code: 'document_unavailable',
      );
    }

    final mime = (response.headers.value('content-type') ?? document.mimeType)
        .split(';')
        .first
        .trim()
        .toLowerCase();

    return KycFile(
      bytes: Uint8List.fromList(body),
      mimeType: mime.isEmpty ? 'application/octet-stream' : mime,
      fileName: _fileNameFor(document, mime),
    );
  }

  /// The URL to fetch [document] from, on the configured API origin.
  ///
  /// Prefers the inline preview link; an older server without one still has
  /// the download route, which streams the same bytes.
  static Uri? resolve(KycDocument document) {
    final base = Uri.parse(AppConfig.apiBaseUrl);
    final preview = document.previewUrl;

    if (preview != null && preview.isNotEmpty) {
      final link = Uri.tryParse(preview);
      if (link == null) return null;

      // Absolute or rooted at the origin (`/api/v1/...`): keep the path and
      // query, take scheme and host from our own configuration.
      if (link.hasScheme || link.path.startsWith('/api/')) {
        return base.replace(
          path: link.path,
          query: link.hasQuery ? link.query : null,
        );
      }

      // Relative to the API (`/me/kyc/...` or `me/kyc/...`).
      final path = link.path.startsWith('/') ? link.path : '/${link.path}';
      return base.replace(
        path: '${base.path}$path',
        query: link.hasQuery ? link.query : null,
      );
    }

    final token = document.downloadToken;
    if (token == null) return null;

    return base.replace(
      path: '${base.path}${ApiEndpoints.kycDocumentDownload(document.id)}',
      queryParameters: {'token': token},
    );
  }

  static String _fileNameFor(KycDocument document, String mime) {
    final original = document.originalName.trim();
    if (original.isNotEmpty) {
      return original.replaceAll(RegExp(r'[\\/:*?"<>|]+'), '-');
    }

    final extension = switch (mime) {
      'application/pdf' => 'pdf',
      'image/png' => 'png',
      'image/jpeg' || 'image/jpg' => 'jpg',
      'image/webp' => 'webp',
      _ => 'bin',
    };
    return '${document.slot}-${document.id}.$extension';
  }

  static String _messageFrom(List<int> body) {
    try {
      final decoded = jsonDecode(utf8.decode(body, allowMalformed: true));
      if (decoded is Map && decoded['message'] is String) {
        return decoded['message'] as String;
      }
    } catch (_) {
      // Not an envelope — fall through to the generic message.
    }
    return 'This document could not be opened. Please try again.';
  }
}
