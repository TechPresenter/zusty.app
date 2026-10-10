// Privacy & account management in the app: "Download my data", "Delete my
// account", and the "confirm it's you" step they share with setting a first
// password and changing the primary email or mobile number.
//
// The repository is faked at the edge (it records what would be sent, can be
// told to fail, and can hold an answer back), so these pin what the screens
// promise: what is asked for, what is shown while waiting, where an error
// lands, and that a finished deletion hands the device over to
// signOutAfterDeletion. The fake auth only records that hand-off; a separate
// test runs the real controller to show the device is actually cleared.
import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/messaging/push_service.dart';
import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/core/utils/formatters.dart';
import 'package:zuste/data/repositories/auth_repository.dart';
import 'package:zuste/data/repositories/contact_repository.dart';
import 'package:zuste/data/repositories/privacy_repository.dart';
import 'package:zuste/features/privacy/account_deleted_screen.dart';
import 'package:zuste/features/privacy/data_export_screen.dart';
import 'package:zuste/features/privacy/delete_account_screen.dart';
import 'package:zuste/features/privacy/widgets/reauth_panel.dart';
import 'package:zuste/features/profile/contacts_screen.dart';
import 'package:zuste/features/settings/widgets/account_sheets.dart';
import 'package:zuste/models/contact.dart';
import 'package:zuste/models/privacy.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/privacy_provider.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/z_button.dart';
import 'package:zuste/shared/widgets/z_feedback.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

import 'support/mock_data.dart';

/// The latest export as the overview reports it: ready, with downloads
/// left, and never a link.
const _readyExport = {
  'id': 7,
  'status': 'ready',
  'file_size': 11441,
  'download_count': 0,
  'max_downloads': 5,
  'downloads_left': 5,
  'can_download': true,
  'created_at': '2026-10-01T16:48:43+05:30',
  'expires_at': '2026-10-04T16:48:43+05:30',
};

const _freshUrl = 'http://localhost:8000/api/v1/privacy/exports/download?token=abc';
const _relinkUrl = 'http://localhost:8000/api/v1/privacy/exports/download?token=def';

/// The same copy on the response that minted it: with its one-time link.
const _freshExport = {..._readyExport, 'download_url': _freshUrl};

const _emailNotice = {'channel': 'email', 'destination': 'it****@zuste.test'};

const _forfeit = {
  'amount': 250,
  'currency': 'INR',
  'label': '₹250.00',
  'reason': 'below_minimum',
  'message':
      'You have ₹250.00 in your wallet. That\'s below the ₹1,000.00 minimum '
      'withdrawal, so it can\'t be paid out.',
};

/// Shaped like a real `GET /me/privacy` response.
Map<String, dynamic> _overview({
  bool password = true,
  bool? hasPassword,
  bool email = true,
  bool sms = false,
  List<String> providers = const [],
  List<Map<String, dynamic>> unavailable = const [],
  List<Map<String, dynamic>> blockers = const [],
  Map<String, dynamic>? forfeit,
  bool canRequest = true,
  String? nextAvailableAt,
  Map<String, dynamic>? latest,
}) =>
    {
      'verification': {
        'password': password,
        'has_password': hasPassword ?? password,
        'channels': [
          if (email) {'channel': 'email', 'destination': 'it****@zuste.test'},
          if (sms) {'channel': 'sms', 'destination': '+91 ******3210'},
        ],
        'unavailable': unavailable,
        'providers': providers,
      },
      'export': {
        'latest': latest,
        'can_request': canRequest,
        'next_available_at': nextAvailableAt,
        'cooldown_hours': 24,
        'link_hours': 72,
        'max_downloads': 5,
        'format': 'ZIP archive of JSON files',
        'includes': [
          {'title': 'Profile & account', 'detail': 'Name, contact details and preferences.'},
          {'title': 'Bookings', 'detail': 'Every booking you were part of.'},
        ],
      },
      'deletion': {
        'can_delete': blockers.isEmpty,
        'blockers': blockers,
        'forfeit': forfeit,
        'confirm_phrase': 'DELETE',
        'reasons': [
          {'code': 'privacy', 'label': 'I\'m worried about my privacy'},
          {'code': 'other', 'label': 'Something else'},
        ],
        'erased': [
          {'key': 'profile', 'title': 'Your profile', 'detail': 'Name, photo, bio and contact details.'},
        ],
        'retained': [
          {'key': 'financial', 'title': 'Bookings, payments and invoices', 'detail': 'Kept for up to 8 years.'},
        ],
        'retained_counts': {'bookings': 3, 'invoices': 2, 'payments': 0, 'messages': 0},
      },
    };

const _contactBook = {
  'primary': {
    'email': {'value': 'it@zuste.test', 'verified': true},
    'phone': {'value': '9876543210', 'verified': false},
  },
  'alternates': <Map<String, dynamic>>[],
  'requests': <Map<String, dynamic>>[],
  'policy': {'otp_verification': true},
};

/// Records what the screens would send, fails on request, and can hold an
/// answer back until the test lets it go.
class _FakePrivacy extends PrivacyRepository {
  _FakePrivacy(super.client, this.overviewJson);

  Map<String, dynamic> overviewJson;
  int overviewCalls = 0;
  Completer<PrivacyOverview>? holdOverview;
  ApiFailure? failNextOverview;

  int codesSent = 0;
  String? lastCodeChannel;
  String? lastCodePurpose;
  Completer<SentCode>? holdCode;
  ApiFailure? failNextCode;
  Duration codeResendIn = const Duration(seconds: 30);

  Map<String, dynamic> exportJson = _freshExport;
  Completer<DataExport>? holdExport;
  ApiFailure? failNextExport;

  int linkCalls = 0;
  int? lastLinkId;

  Reauth? lastProof;
  String? lastConfirmation;
  String? lastReasonCode;
  String? lastReason;
  double? lastForfeitBalance;
  int deleteCalls = 0;
  Completer<void>? holdDelete;
  ApiFailure? failNextDelete;
  DeletionResult deleteResult = const DeletionResult();

  int checkCalls = 0;
  ApiFailure? failNextCheck;

  /// Thrown by every check until cleared.
  ApiFailure? checkFailure;

  @override
  Future<PrivacyOverview> overview() async {
    overviewCalls++;
    final hold = holdOverview;
    if (hold != null) {
      holdOverview = null;
      return hold.future;
    }
    final failure = failNextOverview;
    if (failure != null) {
      failNextOverview = null;
      throw failure;
    }
    return PrivacyOverview.fromJson(overviewJson);
  }

  @override
  Future<SentCode> sendCode({required String purpose, required String channel}) async {
    codesSent++;
    lastCodePurpose = purpose;
    lastCodeChannel = channel;
    final hold = holdCode;
    if (hold != null) {
      holdCode = null;
      return hold.future;
    }
    final failure = failNextCode;
    if (failure != null) {
      failNextCode = null;
      throw failure;
    }
    return SentCode(
      channel: channel,
      destination: channel == 'sms' ? '+91 ******3210' : 'it****@zuste.test',
      length: 6,
      expiresIn: const Duration(minutes: 5),
      resendIn: codeResendIn,
    );
  }

  @override
  Future<DataExport> requestExport(Reauth proof) async {
    lastProof = proof;
    final hold = holdExport;
    if (hold != null) {
      holdExport = null;
      return hold.future;
    }
    final failure = failNextExport;
    if (failure != null) {
      failNextExport = null;
      throw failure;
    }
    return DataExport.fromJson(exportJson);
  }

  @override
  Future<DataExport> exportLink(int id, Reauth proof) async {
    linkCalls++;
    lastLinkId = id;
    lastProof = proof;
    final failure = failNextExport;
    if (failure != null) {
      failNextExport = null;
      throw failure;
    }
    return DataExport.fromJson({..._readyExport, 'id': id, 'download_url': _relinkUrl});
  }

  @override
  Future<DeletionResult> deleteAccount({
    required Reauth proof,
    required String confirmation,
    String? reasonCode,
    String? reason,
    double? forfeitBalance,
  }) async {
    deleteCalls++;
    lastProof = proof;
    lastConfirmation = confirmation;
    lastReasonCode = reasonCode;
    lastReason = reason;
    lastForfeitBalance = forfeitBalance;
    final hold = holdDelete;
    if (hold != null) {
      holdDelete = null;
      await hold.future;
    }
    final failure = failNextDelete;
    if (failure != null) {
      failNextDelete = null;
      throw failure;
    }
    return deleteResult;
  }

  @override
  Future<void> checkAccountExists() async {
    checkCalls++;
    final failure = failNextCheck ?? checkFailure;
    failNextCheck = null;
    if (failure != null) throw failure;
  }
}

/// Signed in, and records how the session ends.
class _FakeAuth extends AuthController {
  _FakeAuth({this.throwOnSignOut = false, this.signOutGate});

  final bool throwOnSignOut;
  final Completer<void>? signOutGate;

  bool signedOutAfterDeletion = false;
  int signOutCalls = 0;
  bool restored = false;
  bool loggedOut = false;

  @override
  AuthState build() => AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson(MockData.currentUser),
      );

  @override
  Future<void> signOutAfterDeletion() async {
    signOutCalls++;
    final gate = signOutGate;
    if (gate != null) await gate.future;
    if (throwOnSignOut) throw StateError('the device could not be cleared');
    signedOutAfterDeletion = true;
    state = const AuthState(stage: AuthStage.unauthenticated);
  }

  @override
  Future<void> restore() async {
    restored = true;
    state = const AuthState(stage: AuthStage.unauthenticated);
  }

  @override
  Future<void> logout() async {
    loggedOut = true;
    state = const AuthState(stage: AuthStage.unauthenticated);
  }
}

/// The real controller with a signed-in start, skipping restore().
class _SignedIn extends AuthController {
  @override
  AuthState build() => AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson(MockData.currentUser),
      );
}

class _FakePush extends PushService {
  _FakePush(super.cache);

  int deleted = 0;

  @override
  Future<void> deleteToken() async => deleted++;
}

/// Records a password change instead of sending it.
class _FakeAuthRepository extends AuthRepository {
  _FakeAuthRepository(ApiClient client, KeyValueCache cache) : super(client, SecureStore(), cache);

  String? lastCurrent;
  String? lastUpdated;
  Reauth? lastProof;
  ApiFailure? failNext;

  @override
  Future<void> changePassword({String current = '', required String updated, Reauth? proof}) async {
    lastCurrent = current;
    lastUpdated = updated;
    lastProof = proof;
    final failure = failNext;
    if (failure != null) {
      failNext = null;
      throw failure;
    }
  }
}

/// Serves one contact book and records a requested change.
class _FakeContacts extends ContactRepository {
  _FakeContacts(super.client);

  int changeCalls = 0;
  String? lastChannel;
  String? lastValue;
  Reauth? lastProof;
  ApiFailure? failNextChange;

  @override
  Future<ContactBook> load() async => ContactBook.fromJson(_contactBook);

  @override
  Future<ContactBook> requestChange({
    required String channel,
    required String value,
    String? countryCode,
    String? reason,
    Reauth? proof,
  }) async {
    changeCalls++;
    lastChannel = channel;
    lastValue = value;
    lastProof = proof;
    final failure = failNextChange;
    if (failure != null) {
      failNextChange = null;
      throw failure;
    }
    return ContactBook.fromJson(_contactBook);
  }
}

/// Plays the server for the repository tests: records every request and
/// answers each with an empty success.
class _RecordingServer implements HttpClientAdapter {
  final requests = <RequestOptions>[];
  Map<String, dynamic> data = const {};

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return ResponseBody.fromString(
      jsonEncode({'success': true, 'message': 'OK', 'data': data}),
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

  /// URLs the app asked the platform to open.
  final launched = <String>[];
  const launcher = MethodChannel('plugins.flutter.io/url_launcher');

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    cache = await KeyValueCache.open();

    launched.clear();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(launcher, (call) async {
      if (call.method == 'launch') {
        launched.add('${(call.arguments as Map)['url']}');
      }
      return true;
    });
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(launcher, null);
  });

  ApiClient client({HttpClientAdapter? adapter}) => ApiClient(
        secureStore: SecureStore(),
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: adapter == null ? Dio() : (Dio()..httpClientAdapter = adapter),
      );

  // ---------------------------------------------------------------------------
  group('models', () {
    test('read the overview the server sends', () {
      final overview = PrivacyOverview.fromJson(_overview(latest: _readyExport));

      expect(overview.verification.password, isTrue);
      expect(overview.verification.hasPassword, isTrue);
      expect(overview.verification.channels.single.destination, 'it****@zuste.test');
      expect(overview.export.includes, hasLength(2));
      expect(overview.deletion.confirmPhrase, 'DELETE');
      expect(overview.deletion.retainedSummary, '3 bookings and 2 invoices');
      expect(overview.deletion.forfeit, isNull);
    });

    test('a ready copy with downloads left is ready, though the overview carries no link', () {
      final latest = PrivacyOverview.fromJson(_overview(latest: _readyExport)).export.latest!;

      expect(latest.downloadUrl, isNull);
      expect(latest.isReady, isTrue);
      expect(latest.sizeLabel, '12 KB');

      final usedUp = DataExport.fromJson({..._readyExport, 'downloads_left': 0, 'can_download': false});
      expect(usedUp.isReady, isFalse);
    });

    test('the server copy wins on counts and state, the fresh link stays', () {
      final fresh = DataExport.fromJson({..._freshExport, 'security_notice': _emailNotice});

      final counted = fresh.mergedWith(
        DataExport.fromJson({..._readyExport, 'download_count': 2, 'downloads_left': 3}),
      );
      expect(counted.downloadsLeft, 3);
      expect(counted.downloadUrl, _freshUrl);
      expect(counted.securityNotice?.destination, 'it****@zuste.test');

      final usedUp = fresh.mergedWith(
        DataExport.fromJson({..._readyExport, 'download_count': 5, 'downloads_left': 0, 'can_download': false}),
      );
      expect(usedUp.isReady, isFalse);
      expect(usedUp.downloadUrl, isNull);
    });

    test('an account with nothing to verify with cannot verify', () {
      final options = VerificationOptions.fromJson(const {'password': false, 'channels': []});
      expect(options.canVerify, isFalse);
    });

    test('verification options say what is unavailable, and which sign-ins can confirm', () {
      final options = VerificationOptions.fromJson({
        'password': false,
        'has_password': true,
        'channels': [],
        'unavailable': [
          {
            'channel': 'email',
            'destination': 'it****@zuste.test',
            'reason': 'contact_recently_changed',
            'available_at': '2026-10-05T16:48:43+05:30',
          },
        ],
        'providers': ['google.com', 'phone'],
      });

      expect(options.hasPassword, isTrue, reason: 'set, but without proof');
      expect(options.password, isFalse);
      expect(options.unavailable.single.recentlyChanged, isTrue);
      expect(options.unavailable.single.availableAt, isNotNull);
      expect(options.appProviders, ['google.com'], reason: 'a phone sign-in needs a flow the app lacks');
      expect(options.canVerify, isTrue);

      final phoneOnly = VerificationOptions.fromJson(const {
        'password': false,
        'channels': [],
        'providers': ['phone'],
      });
      expect(phoneOnly.canVerify, isFalse);
    });

    test('a proof sends a password, a code or a Google/Apple token, one at a time', () {
      expect(const Reauth.password('pw').toJson(), {'password': 'pw'});
      expect(
        const Reauth.code('123456', channel: 'email').toJson(),
        {'code': '123456', 'channel': 'email'},
      );
      expect(const Reauth.firebase('id-token').toJson(), {'id_token': 'id-token'});
      expect(const Reauth.firebase('id-token').usesFirebase, isTrue);
    });

    test('a sent code says how long the next one waits', () {
      expect(SentCode.fromJson(const {'resend_in': 60}).resendIn, const Duration(seconds: 60));
      expect(SentCode.fromJson(const {}).resendIn, const Duration(seconds: 30));
    });

    test('a forfeit, a deletion result and a security notice are read', () {
      final deletion = PrivacyOverview.fromJson(_overview(forfeit: _forfeit)).deletion;
      expect(deletion.forfeit?.amount, 250);
      expect(deletion.forfeit?.label, '₹250.00');

      final result = DeletionResult.fromJson(const {
        'deleted': true,
        'retained': {'bookings': 3, 'invoices': 2},
        'notice': {'channel': 'sms', 'destination': '+91 ******3210'},
      });
      expect(result.notice?.isSms, isTrue);
      expect(result.retained['invoices'], 2);
      expect(DeletionResult.fromJson(const {'notice': null}).notice, isNull);
    });

    test('a 503 that is not maintenance stays a retryable server failure with its code', () {
      final failure = ApiFailureMapper.fromEnvelope(
        statusCode: 503,
        message: 'We could not send the verification code right now.',
        code: 'otp_delivery_failed',
      );
      expect(failure, isA<ServerFailure>());
      expect(failure.code, 'otp_delivery_failed');
      expect(failure.isRetryable, isTrue);

      expect(
        ApiFailureMapper.fromEnvelope(statusCode: 503, message: 'Down.', code: 'maintenance'),
        isA<MaintenanceFailure>(),
      );
    });

    test('a countdown reads as minutes and seconds', () {
      expect(Formatters.countdown(5), '0:05');
      expect(Formatters.countdown(59), '0:59');
      expect(Formatters.countdown(60), '1:00');
      expect(Formatters.countdown(780), '13:00');
      expect(Formatters.countdown(900), '15:00');
      expect(Formatters.countdown(-3), '0:00');
    });

    test('the blocked message says why, never that a phone with SMS off is missing', () {
      final smsOff = VerificationOptions.fromJson(const {
        'password': false,
        'channels': [],
        'unavailable': [
          {'channel': 'sms', 'destination': '+91 ******3210', 'reason': 'sms_unavailable'},
        ],
      });
      final message = reauthBlockedMessage(smsOff);
      expect(message, contains('We can\'t text you a code right now'));
      expect(message, contains('no password or email address'));
      expect(message, isNot(contains('mobile number')));

      final held = VerificationOptions.fromJson(const {
        'password': false,
        'channels': [],
        'unavailable': [
          {
            'channel': 'email',
            'destination': 'it****@zuste.test',
            'reason': 'contact_recently_changed',
            'available_at': '2026-10-05T16:48:43+05:30',
          },
        ],
      });
      expect(reauthBlockedMessage(held), contains('email address changed recently'));
      expect(reauthBlockedMessage(held), contains('until'));

      final nothing = VerificationOptions.fromJson(const {'password': false, 'channels': []});
      expect(reauthBlockedMessage(nothing), contains('no password, email address or mobile number'));
    });
  });

  // ---------------------------------------------------------------------------
  group('repositories', () {
    test('an export waits as long as the server may take to build it', () async {
      final server = _RecordingServer()..data = {'export': _freshExport};
      final repository = PrivacyRepository(client(adapter: server));

      final export = await repository.requestExport(const Reauth.password('pw'));

      expect(export.downloadUrl, _freshUrl);
      final request = server.requests.single;
      expect(request.path, endsWith('/me/privacy/exports'));
      expect(request.receiveTimeout, isNotNull);
      expect(request.receiveTimeout! >= const Duration(seconds: 110), isTrue);
    });

    test('a fresh link is asked for the copy it belongs to, with the proof', () async {
      final server = _RecordingServer()..data = {'export': {..._readyExport, 'download_url': _relinkUrl}};
      final repository = PrivacyRepository(client(adapter: server));

      final export = await repository.exportLink(7, const Reauth.code('123456', channel: 'email'));

      expect(export.downloadUrl, _relinkUrl);
      expect(server.requests.single.path, endsWith('/me/privacy/exports/7/link'));
      expect(server.requests.single.data, {'code': '123456', 'channel': 'email'});
    });

    test('a deletion sends the balance it gives up, waits long, and reads the notice', () async {
      final server = _RecordingServer()
        ..data = {
          'deleted': true,
          'retained': {'bookings': 1},
          'notice': _emailNotice,
        };
      final repository = PrivacyRepository(client(adapter: server));

      final result = await repository.deleteAccount(
        proof: const Reauth.password('pw'),
        confirmation: 'DELETE',
        forfeitBalance: 250,
      );

      final request = server.requests.single;
      expect((request.data as Map)['forfeit_balance'], 250);
      expect(request.receiveTimeout! >= const Duration(seconds: 110), isTrue);
      expect(result.notice?.destination, 'it****@zuste.test');

      await repository.deleteAccount(proof: const Reauth.password('pw'), confirmation: 'DELETE');
      expect((server.requests.last.data as Map).containsKey('forfeit_balance'), isFalse);
    });

    test('checking the account asks the privacy overview, which is never cached', () async {
      final server = _RecordingServer();
      final repository = PrivacyRepository(client(adapter: server));

      await repository.checkAccountExists();

      expect(server.requests.single.method, 'GET');
      expect(server.requests.single.path, endsWith('/me/privacy'));
    });

    test('a contact change carries its proof, with the code channel kept apart', () async {
      final server = _RecordingServer()..data = _contactBook;
      final repository = ContactRepository(client(adapter: server));

      await repository.requestChange(
        channel: 'email',
        value: 'new@zuste.test',
        proof: const Reauth.code('123456', channel: 'sms'),
      );
      expect(server.requests.last.data, {
        'channel': 'email',
        'value': 'new@zuste.test',
        'code': '123456',
        'code_channel': 'sms',
      });

      await repository.requestChange(channel: 'phone', value: '9876500000', proof: const Reauth.password('pw'));
      expect(server.requests.last.data, {'channel': 'phone', 'value': '9876500000', 'password': 'pw'});

      await repository.requestChange(channel: 'email', value: 'n@zuste.test', proof: const Reauth.firebase('tok'));
      expect(server.requests.last.data, {'channel': 'email', 'value': 'n@zuste.test', 'id_token': 'tok'});

      await repository.requestChange(channel: 'email', value: 'n@zuste.test');
      expect(server.requests.last.data, {'channel': 'email', 'value': 'n@zuste.test'});
    });
  });

  // ---------------------------------------------------------------------------
  group('handing the device over', () {
    test('signOutAfterDeletion really clears the device', () async {
      FlutterSecureStorage.setMockInitialValues({
        'zuste.access_token': 'a',
        'zuste.refresh_token': 'r',
        'zuste.access_expires_at': '',
      });
      final store = SecureStore();
      final push = _FakePush(cache);
      await cache.writeDocument('profile', {'id': 1, 'name': 'Someone'});
      await cache.addRecentSearch('lipstick');

      final container = ProviderContainer(overrides: [
        keyValueCacheProvider.overrideWithValue(cache),
        secureStoreProvider.overrideWithValue(store),
        pushServiceProvider.overrideWithValue(push),
        authProvider.overrideWith(_SignedIn.new),
      ]);
      addTearDown(container.dispose);

      await container.read(authProvider.notifier).signOutAfterDeletion();

      expect(push.deleted, 1);
      expect(await store.hasSession(), isFalse);
      expect(await store.readRefreshToken(), isNull);
      expect(cache.readDocument('profile'), isNull);
      expect(cache.recentSearches, isEmpty);
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
    });

    test('concurrent hand-overs share one run, with the goodbye flag raised first', () async {
      final gate = Completer<void>();
      final auth = _FakeAuth(signOutGate: gate);
      final container = ProviderContainer(overrides: [
        keyValueCacheProvider.overrideWithValue(cache),
        authProvider.overrideWith(() => auth),
      ]);
      addTearDown(container.dispose);

      final flag = container.read(accountDeletedProvider.notifier);
      final controller = container.read(authProvider.notifier);

      final first = finishAccountDeletion(flag, controller);
      final second = finishAccountDeletion(flag, controller);
      await pumpEventQueue();

      expect(container.read(accountDeletedProvider), isTrue, reason: 'raised before the sign-out ends');
      expect(auth.signOutCalls, 1);

      gate.complete();
      await Future.wait([first, second]);
      expect(auth.signedOutAfterDeletion, isTrue);
    });

    test('a sign-out that fails still ends signed out', () async {
      final auth = _FakeAuth(throwOnSignOut: true);
      final container = ProviderContainer(overrides: [
        keyValueCacheProvider.overrideWithValue(cache),
        authProvider.overrideWith(() => auth),
      ]);
      addTearDown(container.dispose);

      await finishAccountDeletion(
        container.read(accountDeletedProvider.notifier),
        container.read(authProvider.notifier),
      );

      expect(auth.restored, isTrue);
      expect(container.read(authProvider).stage, AuthStage.unauthenticated);
    });
  });

  // ---------------------------------------------------------------------------
  group('screens', () {
    late _FakePrivacy fake;
    late _FakeAuth auth;
    late _FakeAuthRepository authRepository;
    late _FakeContacts contacts;
    late GoRouter router;
    late ProviderContainer container;
    late List<String> tokenRequests;

    Future<void> settle(WidgetTester tester) async {
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));
    }

    Future<void> pump(
      WidgetTester tester,
      Widget screen, {
      Map<String, dynamic>? overview,
      _FakeAuth? signedIn,
    }) async {
      tester.view.physicalSize = const Size(1170, 6000);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      fake = _FakePrivacy(client(), overview ?? _overview());
      auth = signedIn ?? _FakeAuth();
      authRepository = _FakeAuthRepository(client(), cache);
      contacts = _FakeContacts(client());
      tokenRequests = [];

      router = GoRouter(
        initialLocation: '/screen',
        routes: [
          GoRoute(path: '/screen', builder: (_, _) => screen),
          for (final path in [
            Routes.privacy,
            Routes.privacyExport,
            Routes.bookings,
            Routes.wallet,
            Routes.kyc,
            Routes.newTicket,
            Routes.login,
            Routes.signUp,
          ])
            GoRoute(path: path, builder: (_, _) => Scaffold(body: Text('route:$path'))),
        ],
      );

      container = ProviderContainer(overrides: [
        keyValueCacheProvider.overrideWithValue(cache),
        privacyRepositoryProvider.overrideWithValue(fake),
        authRepositoryProvider.overrideWithValue(authRepository),
        contactRepositoryProvider.overrideWithValue(contacts),
        authProvider.overrideWith(() => auth),
        firebaseTokenSourceProvider.overrideWithValue((provider) async {
          tokenRequests.add(provider);
          return 'fake-${providerLabel(provider).toLowerCase()}-token';
        }),
      ]);
      addTearDown(container.dispose);

      await tester.pumpWidget(UncontrolledProviderScope(
        container: container,
        child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
      ));
      await settle(tester);
    }

    Finder field(String label) => find.descendant(
          of: find.ancestor(of: find.text(label), matching: find.byType(ZTextField)),
          matching: find.byType(TextFormField),
        );

    Future<void> type(WidgetTester tester, String label, String text) async {
      await tester.ensureVisible(field(label));
      await tester.enterText(field(label), text);
      await tester.pump();
    }

    ZButton button(WidgetTester tester, String label) =>
        tester.widget<ZButton>(find.widgetWithText(ZButton, label));

    Future<void> tapText(WidgetTester tester, String text) async {
      await tester.ensureVisible(find.text(text));
      await tester.tap(find.text(text));
      await settle(tester);
    }

    // -- Download my data ------------------------------------------------------

    Future<void> openExportSheet(WidgetTester tester) => tapText(tester, 'Request my data');

    Future<void> sendCodeByEmail(WidgetTester tester) async {
      await tapText(tester, 'Use a one-time code instead');
      await tapText(tester, 'Send code');
    }

    testWidgets('an export asks for the password, then offers the download', (tester) async {
      await pump(tester, const DataExportScreen());

      expect(find.text('Profile & account'), findsOneWidget);
      expect(find.textContaining('3 days and 5 downloads'), findsOneWidget);
      expect(find.textContaining('full document numbers'), findsOneWidget);
      expect(find.textContaining('our team\'s internal notes'), findsOneWidget);

      await openExportSheet(tester);
      // The sheet's subtitle: "Confirm it's you" is also a step on the page.
      expect(find.textContaining('For your security'), findsOneWidget);
      expect(button(tester, 'Prepare my data').onPressed, isNull, reason: 'no proof typed yet');

      // A wrong password: the server's words land beside the field, and the
      // sheet stays open for another try.
      fake.failNextExport = const ValidationFailure(
        message: 'Incorrect password.',
        code: 'invalid_credentials',
        errors: {
          'password': ['Incorrect password.'],
        },
      );
      await type(tester, 'Your password', 'wrong');
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);
      expect(find.text('Incorrect password.'), findsOneWidget);
      expect(find.textContaining('For your security'), findsOneWidget);

      await type(tester, 'Your password', 'right-password');
      expect(find.text('Incorrect password.'), findsNothing, reason: 'typing clears the error');
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(fake.lastProof?.password, 'right-password');
      expect(find.text('Your data is ready'), findsOneWidget);
      expect(find.textContaining('Download now'), findsOneWidget);
      expect(find.textContaining('5 of 5 downloads left'), findsOneWidget);
      // No notice came back, so none is claimed, and the link is never said
      // to have been emailed.
      expect(find.textContaining('emailed'), findsNothing);
      expect(find.textContaining('Keep this link private'), findsOneWidget);
    });

    testWidgets('the ready card names where the security notice went', (tester) async {
      await pump(tester, const DataExportScreen());
      fake.exportJson = {..._freshExport, 'security_notice': _emailNotice};

      await openExportSheet(tester);
      await type(tester, 'Your password', 'pw');
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.textContaining('We\'ve sent a security notice to it****@zuste.test.'), findsOneWidget);
      expect(find.textContaining('Keep this link private'), findsNothing);
      expect(find.textContaining('emailed'), findsNothing);
    });

    testWidgets('an account without an email is never told the link was emailed', (tester) async {
      await pump(
        tester,
        const DataExportScreen(),
        overview: _overview(email: false, sms: true, password: true),
      );

      await openExportSheet(tester);
      await type(tester, 'Your password', 'pw');
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.text('Your data is ready'), findsOneWidget);
      expect(find.textContaining('emailed it to you'), findsNothing);
      expect(find.textContaining('Keep this link private'), findsOneWidget);
    });

    testWidgets('a one-time code can stand in for the password, and only a whole one', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await tapText(tester, 'Use a one-time code instead');
      expect(find.textContaining('We\'ll send a one-time code to it****@zuste.test'), findsOneWidget);

      await tapText(tester, 'Send code');
      expect(fake.codesSent, 1);
      expect(fake.lastCodePurpose, PrivacyPurpose.export);
      expect(find.textContaining('Enter the 6-digit code we sent to it****@zuste.test'), findsOneWidget);
      expect(find.textContaining('Resend code in 0:'), findsOneWidget, reason: 'a resend waits');

      // Four of six digits would spend one of the code's attempts.
      await type(tester, 'Verification code', '1234');
      expect(button(tester, 'Prepare my data').onPressed, isNull);

      await type(tester, 'Verification code', '123456');
      expect(button(tester, 'Prepare my data').onPressed, isNotNull);
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(fake.lastProof?.code, '123456');
      expect(fake.lastProof?.channel, 'email');
      expect(find.text('Your data is ready'), findsOneWidget);
    });

    testWidgets('inside the cooldown the request waits and says until when', (tester) async {
      await pump(
        tester,
        const DataExportScreen(),
        overview: _overview(
          canRequest: false,
          nextAvailableAt: '2026-10-02T16:48:43+05:30',
          latest: _readyExport,
        ),
      );

      expect(find.textContaining('You can request a new copy after'), findsOneWidget);
      expect(button(tester, 'Request my data').onPressed, isNull);
      expect(find.textContaining('5 of 5 downloads left'), findsOneWidget);
      expect(find.textContaining('Download (12 KB)'), findsOneWidget);
    });

    testWidgets('a ready copy asks for a fresh link, then a tap downloads it', (tester) async {
      await pump(tester, const DataExportScreen(), overview: _overview(latest: _readyExport));

      await tapText(tester, 'Download (12 KB)');
      expect(find.textContaining('For your security'), findsOneWidget);
      expect(launched, isEmpty, reason: 'the overview has no link to open');

      await type(tester, 'Your password', 'pw');
      await tester.tap(find.text('Get download link'));
      await settle(tester);

      expect(fake.linkCalls, 1);
      expect(fake.lastLinkId, 7);
      expect(fake.lastProof?.password, 'pw');
      expect(find.textContaining('For your security'), findsNothing, reason: 'the sheet closed');

      // The link opens from a tap of its own, which a browser lets through.
      await tapText(tester, 'Download now (12 KB)');
      expect(launched, [_relinkUrl]);
    });

    testWidgets('the confirm sheet stays open while the archive is prepared', (tester) async {
      await pump(tester, const DataExportScreen());
      final hold = fake.holdExport = Completer<DataExport>();

      await openExportSheet(tester);
      expect(tester.widget<BottomSheet>(find.byType(BottomSheet)).enableDrag, isFalse);
      await type(tester, 'Your password', 'pw');
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      void stillOpen() {
        expect(find.textContaining('For your security'), findsOneWidget);
        expect(find.textContaining('keep this open'), findsOneWidget);
      }

      stillOpen();

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      stillOpen();

      await tester.binding.handlePopRoute();
      await settle(tester);
      stillOpen();

      await tester.tapAt(const Offset(20, 20));
      await settle(tester);
      stillOpen();

      hold.complete(DataExport.fromJson(_freshExport));
      await settle(tester);
      expect(find.text('Your data is ready'), findsOneWidget);
    });

    testWidgets('a timed-out request says the copy may be ready, and the page checks again', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await type(tester, 'Your password', 'pw');

      fake.failNextExport = const TimeoutFailure();
      fake.overviewJson = _overview(
        canRequest: false,
        nextAvailableAt: '2026-10-02T16:48:43+05:30',
        latest: _readyExport,
      );
      final callsBefore = fake.overviewCalls;

      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.textContaining('taking longer than usual'), findsOneWidget);
      expect(find.textContaining('may still be ready'), findsOneWidget);
      expect(find.textContaining('took too long'), findsNothing);
      expect(fake.overviewCalls, greaterThan(callsBefore), reason: 'the overview is asked again');

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);

      expect(find.text('Your latest copy'), findsOneWidget);
      expect(find.textContaining('You can request a new copy after'), findsOneWidget);
      expect(button(tester, 'Request my data').onPressed, isNull);
    });

    testWidgets('closing the sheet without asking still refreshes the page', (tester) async {
      await pump(tester, const DataExportScreen());
      await openExportSheet(tester);
      final callsBefore = fake.overviewCalls;

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);

      expect(fake.overviewCalls, callsBefore + 1);
    });

    testWidgets('a cooldown refusal is said in the sheet, not as a wrong code', (tester) async {
      await pump(tester, const DataExportScreen());
      const message = 'You can request a new copy of your data after 3 Oct 2026, 4:48 PM.';

      await openExportSheet(tester);
      await sendCodeByEmail(tester);
      await type(tester, 'Verification code', '123456');

      fake.failNextExport = const RateLimitFailure(message: message, code: 'export_cooldown');
      fake.overviewJson = _overview(
        canRequest: false,
        nextAvailableAt: '2026-10-03T16:48:43+05:30',
        latest: _readyExport,
      );
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.widgetWithText(ZInfoBanner, message), findsOneWidget);
      expect(
        find.descendant(of: field('Verification code'), matching: find.textContaining('request a new copy')),
        findsNothing,
      );
      expect(button(tester, 'Prepare my data').onPressed, isNull);

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      expect(button(tester, 'Request my data').onPressed, isNull);
    });

    testWidgets('a failure after the code may have been used asks for a new code', (tester) async {
      await pump(tester, const DataExportScreen());
      fake.codeResendIn = Duration.zero;

      await openExportSheet(tester);
      await sendCodeByEmail(tester);
      await type(tester, 'Verification code', '123456');

      fake.failNextExport = const ServerFailure(
        message: 'We could not prepare your data. Please try again.',
        statusCode: 500,
        code: 'export_failed',
      );
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.text('Send code'), findsOneWidget);
      expect(find.text('Send a new code to try again.'), findsOneWidget);
      expect(find.text('We could not prepare your data. Please try again.'), findsOneWidget);
      expect(button(tester, 'Prepare my data').onPressed, isNull);
    });

    testWidgets('too many wrong codes is said in the code area and offers a new one', (tester) async {
      await pump(tester, const DataExportScreen());
      fake.codeResendIn = Duration.zero;
      const message = 'Too many incorrect codes. Request a new code.';

      await openExportSheet(tester);
      await sendCodeByEmail(tester);
      await type(tester, 'Verification code', '123456');

      fake.failNextExport = const RateLimitFailure(
        message: message,
        code: 'otp_attempts_exceeded',
        retryAfter: Duration(minutes: 10),
      );
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.descendant(of: find.byType(ReauthPanel), matching: find.text(message)), findsOneWidget);
      expect(find.text(message), findsOneWidget, reason: 'not repeated in the sheet\'s own banner');
      expect(find.text('Send code'), findsOneWidget);
    });

    testWidgets('a code that could not be resent leaves the last one usable', (tester) async {
      await pump(tester, const DataExportScreen());
      fake.codeResendIn = Duration.zero;

      await openExportSheet(tester);
      await sendCodeByEmail(tester);

      fake.failNextCode = const ServerFailure(
        message: 'We could not send the verification code right now.',
        statusCode: 503,
        code: 'otp_delivery_failed',
      );
      await tapText(tester, 'Resend code');

      expect(field('Verification code'), findsOneWidget);
      expect(find.text('We could not send a new code. The last code we sent still works.'), findsOneWidget);
      final resend = find.ancestor(
        of: find.textContaining(RegExp(r'Resend code in 0:[23]\d')),
        matching: find.byWidgetPredicate((widget) => widget is TextButton),
      );
      expect(resend, findsOneWidget);
      expect(tester.widget<TextButton>(resend).onPressed, isNull);
    });

    testWidgets('a first send that fails waits before another try', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await tapText(tester, 'Use a one-time code instead');
      fake.failNextCode = const ServerFailure(
        message: 'We could not send the verification code right now.',
        statusCode: 503,
        code: 'otp_delivery_failed',
      );
      await tapText(tester, 'Send code');

      expect(find.text('We could not send the verification code right now.'), findsOneWidget);
      final send = find.ancestor(
        of: find.textContaining(RegExp(r'Send code in 0:[23]\d')),
        matching: find.byType(ZButton),
      );
      expect(send, findsOneWidget);
      expect(tester.widget<ZButton>(send).onPressed, isNull);
    });

    testWidgets('a long throttle reads in minutes', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await tapText(tester, 'Use a one-time code instead');
      fake.failNextCode = const RateLimitFailure(
        message: 'Too many requests. Please try again in 780 seconds.',
        retryAfter: Duration(seconds: 780),
      );
      await tester.tap(find.text('Send code'));
      await tester.pump();

      expect(find.textContaining(RegExp(r'in 1[23]:[0-5]\d')), findsOneWidget);
      expect(find.textContaining(RegExp(r'in 0:\d{3}')), findsNothing);
    });

    testWidgets('the channel cannot change while a code is on its way', (tester) async {
      await pump(tester, const DataExportScreen(), overview: _overview(sms: true));

      await openExportSheet(tester);
      await tapText(tester, 'Use a one-time code instead');
      final hold = fake.holdCode = Completer<SentCode>();
      await tester.tap(find.text('Send code'));
      await tester.pump();

      expect(tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Text message')).onSelected, isNull);

      hold.complete(const SentCode(
        channel: 'email',
        destination: 'it****@zuste.test',
        length: 6,
        expiresIn: Duration(minutes: 5),
      ));
      await settle(tester);

      expect(find.textContaining('Enter the 6-digit code we sent to it****@zuste.test'), findsOneWidget);
      expect(tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Email')).selected, isTrue);

      await type(tester, 'Verification code', '123456');
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);
      expect(fake.lastProof?.channel, 'email');
    });

    testWidgets('switching channel during the resend wait says how long', (tester) async {
      await pump(tester, const DataExportScreen(), overview: _overview(sms: true));

      await openExportSheet(tester);
      await sendCodeByEmail(tester);
      await tapText(tester, 'Text message');

      final send = find.ancestor(
        of: find.textContaining(RegExp(r'Send code in 0:[0-3]\d')),
        matching: find.byType(ZButton),
      );
      expect(send, findsOneWidget);
      expect(tester.widget<ZButton>(send).onPressed, isNull);
    });

    testWidgets('closing the sheet while a code is on its way leaves nothing running', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await tapText(tester, 'Use a one-time code instead');
      final hold = fake.holdCode = Completer<SentCode>();
      await tester.tap(find.text('Send code'));
      await tester.pump();

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);

      hold.complete(const SentCode(
        channel: 'email',
        destination: 'it****@zuste.test',
        length: 6,
        expiresIn: Duration(minutes: 5),
      ));
      await tester.pump();
      expect(tester.takeException(), isNull);
    });

    testWidgets('a throttle that answers after the sheet closed starts no timer', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await tapText(tester, 'Use a one-time code instead');
      final hold = fake.holdCode = Completer<SentCode>();
      await tester.tap(find.text('Send code'));
      await tester.pump();

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);

      hold.completeError(const RateLimitFailure(retryAfter: Duration(seconds: 780)));
      await tester.pump();
      expect(tester.takeException(), isNull);
    });

    testWidgets('pull to refresh waits for the reload', (tester) async {
      await pump(tester, const DataExportScreen());
      final hold = fake.holdOverview = Completer<PrivacyOverview>();

      // The pull that arms the indicator grows with the view, which is tall here.
      await tester.fling(find.byType(ListView), const Offset(0, 700), 1000);
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));
      expect(find.byType(RefreshProgressIndicator), findsOneWidget);

      hold.complete(PrivacyOverview.fromJson(_overview()));
      await settle(tester);
      expect(find.byType(RefreshProgressIndicator), findsNothing);
    });

    testWidgets('a failed refresh keeps the page and says it is not current', (tester) async {
      await pump(tester, const DataExportScreen());
      fake.failNextOverview = const OfflineFailure();

      // The pull that arms the indicator grows with the view, which is tall here.
      await tester.fling(find.byType(ListView), const Offset(0, 700), 1000);
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));
      await settle(tester);

      expect(find.text('Get a copy of your data'), findsOneWidget);
      expect(find.textContaining('as it was last loaded'), findsOneWidget);
      expect(find.text('Try again'), findsNothing);
      expect(button(tester, 'Request my data').onPressed, isNull, reason: 'stale data is not acted on');
    });

    testWidgets('the ready card follows the server once the copy is used up', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await type(tester, 'Your password', 'pw');
      fake.overviewJson = _overview(
        canRequest: false,
        latest: {..._readyExport, 'download_count': 5, 'downloads_left': 0, 'can_download': false},
      );
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.textContaining('Download now'), findsNothing);
      expect(find.text('Used up'), findsOneWidget);
    });

    testWidgets('the ready card counts down the downloads the server reports', (tester) async {
      await pump(tester, const DataExportScreen());

      await openExportSheet(tester);
      await type(tester, 'Your password', 'pw');
      fake.overviewJson = _overview(
        canRequest: false,
        latest: {..._readyExport, 'download_count': 2, 'downloads_left': 3},
      );
      await tester.tap(find.text('Prepare my data'));
      await settle(tester);

      expect(find.textContaining('Download now'), findsOneWidget);
      expect(find.textContaining('3 of 5 downloads left'), findsOneWidget);
    });

    testWidgets('a phone-only account with texts off is told so, never that it has no number', (tester) async {
      await pump(
        tester,
        const DataExportScreen(),
        overview: _overview(
          password: false,
          email: false,
          unavailable: [
            {'channel': 'sms', 'destination': '+91 ******3210', 'reason': 'sms_unavailable'},
          ],
        ),
      );

      expect(find.textContaining('We can\'t text you a code right now'), findsOneWidget);
      expect(find.textContaining('mobile number'), findsNothing);
      expect(button(tester, 'Request my data').onPressed, isNull);
    });

    testWidgets('a Google account can confirm with a fresh Google sign-in', (tester) async {
      await pump(
        tester,
        const DataExportScreen(),
        overview: _overview(password: false, email: false, providers: ['google.com']),
      );

      expect(button(tester, 'Request my data').onPressed, isNotNull);
      await openExportSheet(tester);
      expect(find.text('Your password'), findsNothing);

      await tapText(tester, 'Confirm with Google');
      expect(tokenRequests, ['google.com']);
      expect(find.text('Confirmed with Google'), findsOneWidget);

      await tester.tap(find.text('Prepare my data'));
      await settle(tester);
      expect(fake.lastProof?.toJson(), {'id_token': 'fake-google-token'});
      expect(find.text('Your data is ready'), findsOneWidget);
    });

    // -- Delete my account -------------------------------------------------------

    Future<void> toConfirmStep(WidgetTester tester) async {
      await tapText(tester, 'Continue');
      await tapText(tester, 'Skip');
    }

    Future<void> completeConfirmStep(WidgetTester tester, {String? password = 'pw'}) async {
      if (password != null) await type(tester, 'Your password', password);
      await type(tester, 'Type DELETE', 'DELETE');
      await tester.ensureVisible(find.byType(Checkbox));
      await tester.tap(find.byType(Checkbox));
      await tester.pump();
    }

    Future<void> submitDeletion(WidgetTester tester) async {
      await tester.tap(find.widgetWithText(ZButton, 'Delete my account'));
      await settle(tester);
      await tester.tap(find.text('Delete permanently'));
    }

    testWidgets('blockers stop the deletion and say how to settle them', (tester) async {
      await pump(
        tester,
        const DeleteAccountScreen(),
        overview: _overview(blockers: [
          {
            'code': 'wallet_balance',
            'title': 'Money in your wallet',
            'message': 'You have ₹2,500 in your wallet. Withdraw it before deleting your account.',
            'action': 'wallet',
          },
        ]),
      );

      expect(find.text('Settle these first'), findsOneWidget);
      expect(find.text('Money in your wallet'), findsOneWidget);
      expect(button(tester, 'Continue').onPressed, isNull);

      await tapText(tester, 'Open wallet');
      expect(find.text('route:${Routes.wallet}'), findsOneWidget);
    });

    testWidgets('a balance only KYC can release points to verification', (tester) async {
      await pump(
        tester,
        const DeleteAccountScreen(),
        overview: _overview(blockers: [
          {
            'code': 'wallet_balance',
            'title': 'Money in your wallet',
            'message': 'Verify your identity so we can pay out your ₹5,000, or contact support.',
            'action': 'kyc',
          },
        ]),
      );

      await tapText(tester, 'Verify your identity');
      expect(find.text('route:${Routes.kyc}'), findsOneWidget);
    });

    testWidgets('a full deletion: what goes, why, proof, phrase, a last check', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      fake.deleteResult = const DeletionResult(
        notice: SecurityNotice(channel: 'email', destination: 'it****@zuste.test'),
      );

      // Step 1: before you go.
      expect(find.text('Step 1 of 3'), findsOneWidget);
      expect(find.text('This is permanent'), findsOneWidget);
      expect(find.text('Your profile'), findsOneWidget);
      expect(find.text('Bookings, payments and invoices'), findsOneWidget);
      expect(find.textContaining('3 bookings and 2 invoices, kept for the legal period'), findsOneWidget);
      expect(find.textContaining('without your name'), findsNothing);
      await tapText(tester, 'Continue');

      // Step 2: an optional reason.
      expect(find.text('Why are you leaving?'), findsOneWidget);
      await tester.tap(find.text('I\'m worried about my privacy'));
      await tester.pump();
      await type(tester, 'Anything else you\'d like to tell us?', 'Just testing');
      await tapText(tester, 'Continue');

      // Step 3: proof, the phrase, the tick, then the last dialog.
      expect(find.text('Confirm deletion'), findsOneWidget);
      expect(button(tester, 'Delete my account').onPressed, isNull);

      await type(tester, 'Your password', 'pw');
      await type(tester, 'Type DELETE', 'delete');
      expect(button(tester, 'Delete my account').onPressed, isNull, reason: 'not ticked yet');

      await tester.ensureVisible(find.byType(Checkbox));
      await tester.tap(find.byType(Checkbox));
      await tester.pump();
      expect(button(tester, 'Delete my account').onPressed, isNotNull);

      await tester.tap(find.widgetWithText(ZButton, 'Delete my account'));
      await settle(tester);
      expect(find.text('Delete your account permanently?'), findsOneWidget);
      expect(fake.deleteCalls, 0, reason: 'nothing is sent before the last confirmation');

      await tester.tap(find.text('Delete permanently'));
      await settle(tester);

      expect(fake.deleteCalls, 1);
      expect(fake.lastProof?.password, 'pw');
      expect(fake.lastConfirmation, 'delete');
      expect(fake.lastReasonCode, 'privacy');
      expect(fake.lastReason, 'Just testing');
      expect(fake.lastForfeitBalance, isNull);
      expect(auth.signedOutAfterDeletion, isTrue,
          reason: 'the screen hands the device over to signOutAfterDeletion');
      expect(container.read(accountDeletedProvider), isTrue, reason: 'the router opens the goodbye');
      expect(container.read(accountDeletedNoticeProvider)?.destination, 'it****@zuste.test');
      expect(container.read(deletionInFlightProvider), isFalse);
    });

    testWidgets('a wrong password keeps the user on the confirm step', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await toConfirmStep(tester);

      fake.failNextDelete = const ValidationFailure(
        message: 'Incorrect password.',
        code: 'invalid_credentials',
        errors: {
          'password': ['Incorrect password.'],
        },
      );

      await completeConfirmStep(tester, password: 'wrong');
      await submitDeletion(tester);
      await settle(tester);

      expect(find.text('Incorrect password.'), findsOneWidget);
      expect(find.text('Confirm deletion'), findsOneWidget);
      expect(fake.lastReasonCode, isNull, reason: 'skipping sends no reason');
      expect(auth.signedOutAfterDeletion, isFalse);
    });

    testWidgets('if something now blocks it, the flow goes back and says why', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await toConfirmStep(tester);

      fake.failNextDelete = const ConflictFailure(
        message: 'You have a booking in progress. Complete or cancel it first.',
        code: 'deletion_blocked',
      );

      await completeConfirmStep(tester);
      await submitDeletion(tester);
      await settle(tester);

      expect(find.text('Step 1 of 3'), findsOneWidget);
      expect(find.text('You have a booking in progress. Complete or cancel it first.'), findsOneWidget);
      expect(auth.signedOutAfterDeletion, isFalse);
    });

    testWidgets('a small balance can be given up with an explicit tick', (tester) async {
      await pump(tester, const DeleteAccountScreen(), overview: _overview(forfeit: _forfeit));

      expect(find.textContaining('below the ₹1,000.00 minimum'), findsOneWidget);
      expect(find.text('Contact support instead'), findsOneWidget);
      expect(button(tester, 'Continue').onPressed, isNull, reason: 'not given up yet');

      final giveUp = find.widgetWithText(CheckboxListTile, 'I give up ₹250.00 in my wallet');
      await tester.ensureVisible(giveUp);
      await tester.tap(giveUp);
      await tester.pump();
      expect(button(tester, 'Continue').onPressed, isNotNull);

      await toConfirmStep(tester);
      await completeConfirmStep(tester);
      await tester.tap(find.widgetWithText(ZButton, 'Delete my account'));
      await settle(tester);
      expect(find.textContaining('You also give up ₹250.00 in your wallet.'), findsOneWidget);

      await tester.tap(find.text('Delete permanently'));
      await settle(tester);
      expect(fake.lastForfeitBalance, 250);
      expect(auth.signedOutAfterDeletion, isTrue);
    });

    testWidgets('a balance that changed meanwhile goes back to step 1, unticked', (tester) async {
      await pump(tester, const DeleteAccountScreen(), overview: _overview(forfeit: _forfeit));

      final giveUp = find.widgetWithText(CheckboxListTile, 'I give up ₹250.00 in my wallet');
      await tester.ensureVisible(giveUp);
      await tester.tap(giveUp);
      await tester.pump();
      await toConfirmStep(tester);

      fake.failNextDelete = const ConflictFailure(
        message: 'Your wallet balance changed. Check it again before deleting your account.',
        code: 'forfeit_required',
      );
      await completeConfirmStep(tester);
      await submitDeletion(tester);
      await settle(tester);

      expect(find.text('Step 1 of 3'), findsOneWidget);
      expect(find.textContaining('Your wallet balance changed'), findsOneWidget);
      expect(button(tester, 'Continue').onPressed, isNull, reason: 'the tick is cleared');
    });

    testWidgets('"Check again" shows it is busy, and a failure keeps the blockers', (tester) async {
      await pump(
        tester,
        const DeleteAccountScreen(),
        overview: _overview(blockers: [
          {
            'code': 'open_bookings',
            'title': 'Bookings in progress',
            'message': 'Complete or cancel your bookings first.',
            'action': 'bookings',
          },
        ]),
      );
      final hold = fake.holdOverview = Completer<PrivacyOverview>();

      final recheck = find.ancestor(
        of: find.text('Check again'),
        matching: find.byWidgetPredicate((widget) => widget is TextButton),
      );
      await tester.ensureVisible(recheck);
      await tester.tap(recheck);
      await tester.pump();

      expect(tester.widget<TextButton>(recheck).onPressed, isNull);
      expect(find.descendant(of: recheck, matching: find.byType(CircularProgressIndicator)), findsOneWidget);

      hold.completeError(const OfflineFailure());
      await settle(tester);

      expect(find.text('Bookings in progress'), findsOneWidget);
      expect(find.text('You are offline. Check your connection and try again.'), findsOneWidget);
      expect(tester.widget<TextButton>(recheck).onPressed, isNotNull);
    });

    testWidgets('"Keep my account" works when this screen was opened on its own', (tester) async {
      await pump(tester, const DeleteAccountScreen());

      await tapText(tester, 'Keep my account');

      expect(tester.takeException(), isNull);
      expect(find.text('route:${Routes.privacy}'), findsOneWidget);
    });

    testWidgets('a deletion whose answer was lost, then found to have happened, says goodbye', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await toConfirmStep(tester);
      await completeConfirmStep(tester);

      fake.failNextDelete = const TimeoutFailure();
      fake.failNextCheck = const UnauthorisedFailure(
        message: 'This account has been deleted.',
        code: 'account_deleted',
      );
      await submitDeletion(tester);
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));
      expect(find.textContaining('Checking whether your account was deleted'), findsOneWidget);

      for (var i = 0; i < 40; i++) {
        await tester.pump(const Duration(seconds: 1));
      }

      expect(find.textContaining('took too long'), findsNothing);
      expect(container.read(accountDeletedProvider), isTrue);
      expect(auth.signedOutAfterDeletion, isTrue);
      expect(fake.deleteCalls, 1, reason: 'the deletion is never sent twice');
    });

    testWidgets('a deletion whose answer was lost, then found not to have happened, can be tried again', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await toConfirmStep(tester);
      await completeConfirmStep(tester);

      fake.failNextDelete = const TimeoutFailure();
      await submitDeletion(tester);
      for (var i = 0; i < 5; i++) {
        await tester.pump(const Duration(seconds: 1));
      }
      await settle(tester);

      expect(fake.checkCalls, 1);
      expect(find.text('Your account was not deleted. Nothing changed, so you can try again.'), findsOneWidget);
      expect(button(tester, 'Delete my account').onPressed, isNotNull);
      expect(fake.deleteCalls, 1);
      expect(auth.signedOutAfterDeletion, isFalse);
    });

    testWidgets('when nobody can say whether it was deleted, the only way on is to check again', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await toConfirmStep(tester);
      await completeConfirmStep(tester);

      fake.failNextDelete = const TimeoutFailure();
      fake.checkFailure = const TimeoutFailure();
      await submitDeletion(tester);
      for (var i = 0; i < 40; i++) {
        await tester.pump(const Duration(seconds: 1));
      }
      await settle(tester);

      expect(fake.checkCalls, 3);
      expect(find.textContaining('We couldn\'t confirm whether your account was deleted'), findsOneWidget);
      expect(find.widgetWithText(ZButton, 'Check again'), findsOneWidget);
      expect(find.widgetWithText(ZButton, 'Delete my account'), findsNothing);

      fake.checkFailure = null;
      await tester.tap(find.widgetWithText(ZButton, 'Check again'));
      await settle(tester);

      expect(fake.checkCalls, 4);
      expect(fake.deleteCalls, 1, reason: '"Check again" only asks');
      expect(find.text('Your account was not deleted. Nothing changed, so you can try again.'), findsOneWidget);
      expect(button(tester, 'Delete my account').onPressed, isNotNull);
    });

    testWidgets('an account already deleted is handed over, with no error', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await toConfirmStep(tester);
      await completeConfirmStep(tester);

      fake.failNextDelete = const UnauthorisedFailure(
        message: 'This account has been deleted.',
        code: 'account_deleted',
      );
      await submitDeletion(tester);
      await settle(tester);

      expect(container.read(accountDeletedProvider), isTrue);
      expect(auth.signedOutAfterDeletion, isTrue);
      expect(find.text('This account has been deleted.'), findsNothing);
    });

    testWidgets('the screen closing mid-deletion still hands the device over', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await toConfirmStep(tester);
      await completeConfirmStep(tester);

      final hold = fake.holdDelete = Completer<void>();
      await submitDeletion(tester);
      await tester.pump();
      expect(container.read(deletionInFlightProvider), isTrue);

      router.go(Routes.login);
      await settle(tester);
      expect(find.text('route:${Routes.login}'), findsOneWidget);

      hold.complete();
      await settle(tester);

      expect(tester.takeException(), isNull);
      expect(container.read(accountDeletedProvider), isTrue);
      expect(auth.signedOutAfterDeletion, isTrue);
      expect(container.read(deletionInFlightProvider), isFalse);
    });

    testWidgets('a sign-out that throws still releases the overlay', (tester) async {
      await pump(tester, const DeleteAccountScreen(), signedIn: _FakeAuth(throwOnSignOut: true));
      await toConfirmStep(tester);
      await completeConfirmStep(tester);

      await submitDeletion(tester);
      await settle(tester);

      expect(find.text('Deleting your account…'), findsNothing);
      expect(auth.restored, isTrue);
      expect(container.read(accountDeletedProvider), isTrue);
    });

    testWidgets('a Google account confirms the deletion with Google', (tester) async {
      await pump(
        tester,
        const DeleteAccountScreen(),
        overview: _overview(password: false, email: false, providers: ['google.com']),
      );
      await toConfirmStep(tester);

      await tapText(tester, 'Confirm with Google');
      expect(find.text('Confirmed with Google'), findsOneWidget);

      await completeConfirmStep(tester, password: null);
      await submitDeletion(tester);
      await settle(tester);

      expect(fake.lastProof?.idToken, 'fake-google-token');
      expect(auth.signedOutAfterDeletion, isTrue);
    });

    // -- Goodbye -------------------------------------------------------------

    testWidgets('the goodbye screen leads back to sign in and clears its flags', (tester) async {
      await pump(tester, const AccountDeletedScreen());
      container.read(accountDeletedProvider.notifier).state = true;
      container.read(accountDeletedNoticeProvider.notifier).state =
          const SecurityNotice(channel: 'email', destination: 'it****@zuste.test');
      await settle(tester);

      expect(find.text('Your account has been deleted'), findsOneWidget);
      expect(find.textContaining('Invoices keep the billing details printed on them'), findsOneWidget);
      expect(find.textContaining('without your name'), findsNothing);
      await tapText(tester, 'Back to sign in');

      expect(find.text('route:${Routes.login}'), findsOneWidget);
      expect(container.read(accountDeletedProvider), isFalse);
      expect(container.read(accountDeletedNoticeProvider), isNull);
    });

    testWidgets('the goodbye names where the confirmation went, and only when one was sent', (tester) async {
      await pump(tester, const AccountDeletedScreen());

      expect(find.text('Thank you for being part of Zusty.'), findsOneWidget);
      expect(find.textContaining('confirmation'), findsNothing);

      container.read(accountDeletedNoticeProvider.notifier).state =
          const SecurityNotice(channel: 'sms', destination: '+91 ******3210');
      await settle(tester);

      expect(
        find.text('A confirmation is on its way to +91 ******3210. Thank you for being part of Zusty.'),
        findsOneWidget,
      );
    });

    // -- The shared "confirm it's you" panel -----------------------------------

    testWidgets('adding a password never takes a password as proof', (tester) async {
      final repository = _FakePrivacy(client(), _overview());
      final controller = ReauthController(
        options: const VerificationOptions(
          password: false,
          channels: [CodeChannel(channel: 'email', destination: 'it****@zuste.test')],
        ),
        purpose: PrivacyPurpose.setPassword,
        repository: repository,
      );

      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light,
        home: Scaffold(body: SingleChildScrollView(child: ReauthPanel(controller: controller))),
      ));

      expect(find.text('Your password'), findsNothing);
      expect(find.text('Use my password instead'), findsNothing);
      expect(controller.isComplete, isFalse);

      await tester.tap(find.text('Send code'));
      await settle(tester);
      expect(repository.lastCodePurpose, 'set_password');
      expect(controller.isComplete, isFalse, reason: 'sent, but not typed');

      await tester.enterText(field('Verification code'), '123456');
      await tester.pump();
      expect(controller.proof?.toJson(), {'code': '123456', 'channel': 'email'});

      await tester.pumpWidget(const SizedBox());
      controller.dispose();
    });

    testWidgets('a passwordless account sets a first password only after a code', (tester) async {
      await pump(
        tester,
        Consumer(
          builder: (context, ref, _) => Scaffold(
            body: Center(
              child: TextButton(
                onPressed: () => showChangePassword(context, ref),
                child: const Text('Open'),
              ),
            ),
          ),
        ),
        overview: _overview(password: false, hasPassword: false),
      );

      await tapText(tester, 'Open');
      expect(find.text('Set a password'), findsOneWidget);
      expect(find.text('Current password'), findsNothing);
      expect(find.text('Your password'), findsNothing);
      expect(button(tester, 'Set password').onPressed, isNull, reason: 'not confirmed yet');

      await tapText(tester, 'Send code');
      expect(fake.lastCodePurpose, PrivacyPurpose.setPassword);
      await type(tester, 'Verification code', '123456');
      await type(tester, 'New password', 'Newpass123');
      await type(tester, 'Confirm new password', 'Newpass123');
      await tapText(tester, 'Set password');

      expect(authRepository.lastUpdated, 'Newpass123');
      expect(authRepository.lastCurrent, '');
      expect(authRepository.lastProof?.toJson(), {'code': '123456', 'channel': 'email'});
      expect(auth.loggedOut, isTrue, reason: 'every session ends with a password change');
    });

    testWidgets('an account with a password changes it with the current one', (tester) async {
      await pump(
        tester,
        Consumer(
          builder: (context, ref, _) => Scaffold(
            body: Center(
              child: TextButton(
                onPressed: () => showChangePassword(context, ref),
                child: const Text('Open'),
              ),
            ),
          ),
        ),
      );

      await tapText(tester, 'Open');
      expect(find.text('Change password'), findsOneWidget);
      expect(find.text('Current password'), findsOneWidget);
      expect(find.textContaining('Leave this blank'), findsNothing);
    });

    testWidgets('an account with nothing to confirm with is pointed to support', (tester) async {
      await pump(
        tester,
        Consumer(
          builder: (context, ref, _) => Scaffold(
            body: Center(
              child: TextButton(
                onPressed: () => showChangePassword(context, ref),
                child: const Text('Open'),
              ),
            ),
          ),
        ),
        overview: _overview(password: false, hasPassword: false, email: false),
      );

      await tapText(tester, 'Open');
      expect(find.text('Set a password'), findsOneWidget);
      expect(find.text('Contact support'), findsOneWidget);
      expect(find.text('New password'), findsNothing);
    });

    testWidgets('changing the primary email asks the owner to confirm first', (tester) async {
      await pump(tester, const ContactsScreen());

      await tapText(tester, 'Change email');
      await tester.enterText(
        find.ancestor(of: find.text('you@example.com'), matching: find.byType(TextFormField)),
        'new@zuste.test',
      );
      await tester.pump();
      await tapText(tester, 'Continue');

      expect(find.textContaining('before we change your email address'), findsOneWidget);
      expect(contacts.changeCalls, 0, reason: 'nothing is asked before the proof');

      contacts.failNextChange = const ValidationFailure(
        message: 'Incorrect password.',
        code: 'invalid_credentials',
        errors: {
          'password': ['Incorrect password.'],
        },
      );
      await type(tester, 'Your password', 'wrong');
      await tapText(tester, 'Continue');
      expect(find.text('Incorrect password.'), findsOneWidget, reason: 'beside the field');

      await type(tester, 'Your password', 'pw');
      await tapText(tester, 'Continue');

      expect(contacts.changeCalls, 2);
      expect(contacts.lastChannel, 'email');
      expect(contacts.lastValue, 'new@zuste.test');
      expect(contacts.lastProof?.password, 'pw');
      expect(find.text('Code sent.'), findsOneWidget);
    });
  });
}
