// Privacy & account management in the app: "Download my data" and
// "Delete my account".
//
// The repository is faked at the edge (it records what would be sent and can
// be told to fail), so these pin what the screens promise: what is asked
// for, what is shown while waiting, where an error lands, and that a
// finished deletion clears the device and flags the goodbye screen.
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/privacy_repository.dart';
import 'package:zuste/features/privacy/account_deleted_screen.dart';
import 'package:zuste/features/privacy/data_export_screen.dart';
import 'package:zuste/features/privacy/delete_account_screen.dart';
import 'package:zuste/models/privacy.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/privacy_provider.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/z_button.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

import 'support/mock_data.dart';

const _readyExport = {
  'id': 7,
  'status': 'ready',
  'file_size': 11441,
  'download_count': 0,
  'max_downloads': 5,
  'downloads_left': 5,
  'can_download': true,
  'download_url': 'http://localhost:8000/api/v1/privacy/exports/download?token=abc',
  'created_at': '2026-10-01T16:48:43+05:30',
  'expires_at': '2026-10-04T16:48:43+05:30',
};

/// Shaped like a real `GET /me/privacy` response.
Map<String, dynamic> _overview({
  bool password = true,
  List<Map<String, dynamic>> blockers = const [],
  bool canRequest = true,
  String? nextAvailableAt,
  Map<String, dynamic>? latest,
}) =>
    {
      'verification': {
        'password': password,
        'channels': [
          {'channel': 'email', 'destination': 'it****@zuste.test'},
        ],
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

/// Records what the screens would send, and fails on request.
class _FakePrivacy extends PrivacyRepository {
  _FakePrivacy(super.client, this.overviewJson);

  Map<String, dynamic> overviewJson;
  ApiFailure? failNextExport;
  ApiFailure? failNextDelete;

  Reauth? lastProof;
  String? lastConfirmation;
  String? lastReasonCode;
  String? lastReason;
  int deleteCalls = 0;
  int codesSent = 0;

  @override
  Future<PrivacyOverview> overview() async => PrivacyOverview.fromJson(overviewJson);

  @override
  Future<SentCode> sendCode({required String purpose, required String channel}) async {
    codesSent++;
    return SentCode(
      channel: channel,
      destination: 'it****@zuste.test',
      length: 6,
      expiresIn: const Duration(minutes: 5),
    );
  }

  @override
  Future<DataExport> requestExport(Reauth proof) async {
    lastProof = proof;
    final failure = failNextExport;
    if (failure != null) {
      failNextExport = null;
      throw failure;
    }
    return DataExport.fromJson(_readyExport);
  }

  @override
  Future<void> deleteAccount({
    required Reauth proof,
    required String confirmation,
    String? reasonCode,
    String? reason,
  }) async {
    deleteCalls++;
    lastProof = proof;
    lastConfirmation = confirmation;
    lastReasonCode = reasonCode;
    lastReason = reason;
    final failure = failNextDelete;
    if (failure != null) {
      failNextDelete = null;
      throw failure;
    }
  }
}

/// Signed in, and records the post-deletion sign-out.
class _FakeAuth extends AuthController {
  bool signedOutAfterDeletion = false;

  @override
  AuthState build() => AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson(MockData.currentUser),
      );

  @override
  Future<void> signOutAfterDeletion() async {
    signedOutAfterDeletion = true;
    state = const AuthState(stage: AuthStage.unauthenticated);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
  });

  // ---------------------------------------------------------------------------
  group('models', () {
    test('read the overview the server sends', () {
      final overview = PrivacyOverview.fromJson(_overview(latest: _readyExport));

      expect(overview.verification.password, isTrue);
      expect(overview.verification.channels.single.destination, 'it****@zuste.test');
      expect(overview.export.latest!.isReady, isTrue);
      expect(overview.export.latest!.sizeLabel, '12 KB');
      expect(overview.export.includes, hasLength(2));
      expect(overview.deletion.confirmPhrase, 'DELETE');
      expect(overview.deletion.retainedSummary, '3 bookings and 2 invoices');
    });

    test('an account with nothing to verify with cannot verify', () {
      final options = VerificationOptions.fromJson(const {'password': false, 'channels': []});
      expect(options.canVerify, isFalse);
    });

    test('a proof sends a password or a code, never both', () {
      expect(const Reauth.password('pw').toJson(), {'password': 'pw'});
      expect(
        const Reauth.code('123456', channel: 'email').toJson(),
        {'code': '123456', 'channel': 'email'},
      );
    });
  });

  // ---------------------------------------------------------------------------
  group('screens', () {
    late _FakePrivacy fake;
    late _FakeAuth auth;
    late ProviderContainer container;

    Future<void> settle(WidgetTester tester) async {
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));
    }

    Future<void> pump(
      WidgetTester tester,
      Widget screen, {
      Map<String, dynamic>? overview,
    }) async {
      tester.view.physicalSize = const Size(1170, 6000);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      fake = _FakePrivacy(
        ApiClient(
          secureStore: SecureStore(),
          connectivity: ConnectivityService(),
          responseCache: cache,
          dio: Dio(),
        ),
        overview ?? _overview(),
      );
      auth = _FakeAuth();

      final router = GoRouter(
        initialLocation: '/screen',
        routes: [
          GoRoute(path: '/screen', builder: (_, _) => screen),
          for (final path in [
            Routes.privacyExport,
            Routes.bookings,
            Routes.wallet,
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
        authProvider.overrideWith(() => auth),
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

    // -- Download my data ------------------------------------------------------

    testWidgets('an export asks for the password, then offers the download', (tester) async {
      await pump(tester, const DataExportScreen());

      expect(find.text('Profile & account'), findsOneWidget);
      expect(find.textContaining('3 days and 5 downloads'), findsOneWidget);

      await tester.tap(find.text('Request my data'));
      await settle(tester);
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
      expect(find.textContaining('emailed it to you'), findsOneWidget);
    });

    testWidgets('a one-time code can stand in for the password', (tester) async {
      await pump(tester, const DataExportScreen());

      await tester.tap(find.text('Request my data'));
      await settle(tester);
      await tester.tap(find.text('Use a one-time code instead'));
      await settle(tester);
      expect(find.textContaining('We\'ll send a one-time code to it****@zuste.test'), findsOneWidget);

      await tester.tap(find.text('Send code'));
      await settle(tester);
      expect(fake.codesSent, 1);
      expect(find.textContaining('Enter the 6-digit code we sent to it****@zuste.test'), findsOneWidget);
      expect(find.textContaining('Resend code in'), findsOneWidget, reason: 'a resend waits');

      await type(tester, 'Verification code', '123456');
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

    // -- Delete my account -------------------------------------------------------

    testWidgets('blockers stop the deletion and say how to settle them', (tester) async {
      await pump(
        tester,
        const DeleteAccountScreen(),
        overview: _overview(blockers: [
          {
            'code': 'wallet_balance',
            'title': 'Money in your wallet',
            'message': 'You have ₹250 in your wallet. Withdraw it before deleting your account.',
            'action': 'wallet',
          },
        ]),
      );

      expect(find.text('Settle these first'), findsOneWidget);
      expect(find.text('Money in your wallet'), findsOneWidget);
      expect(button(tester, 'Continue').onPressed, isNull);

      await tester.ensureVisible(find.text('Open wallet'));
      await tester.tap(find.text('Open wallet'));
      await settle(tester);
      expect(find.text('route:${Routes.wallet}'), findsOneWidget);
    });

    testWidgets('a full deletion: what goes, why, proof, phrase, a last check', (tester) async {
      await pump(tester, const DeleteAccountScreen());

      // Step 1: before you go.
      expect(find.text('Step 1 of 3'), findsOneWidget);
      expect(find.text('This is permanent'), findsOneWidget);
      expect(find.text('Your profile'), findsOneWidget);
      expect(find.text('Bookings, payments and invoices'), findsOneWidget);
      expect(find.textContaining('3 bookings and 2 invoices'), findsOneWidget);
      await tester.tap(find.text('Continue'));
      await settle(tester);

      // Step 2: an optional reason.
      expect(find.text('Why are you leaving?'), findsOneWidget);
      await tester.tap(find.text('I\'m worried about my privacy'));
      await tester.pump();
      await type(tester, 'Anything else you\'d like to tell us?', 'Just testing');
      await tester.tap(find.text('Continue'));
      await settle(tester);

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
      expect(auth.signedOutAfterDeletion, isTrue, reason: 'the device is cleared');
      expect(container.read(accountDeletedProvider), isTrue, reason: 'the router opens the goodbye');
    });

    testWidgets('a wrong password keeps the user on the confirm step', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await tester.tap(find.text('Continue'));
      await settle(tester);
      await tester.tap(find.text('Skip'));
      await settle(tester);

      fake.failNextDelete = const ValidationFailure(
        message: 'Incorrect password.',
        code: 'invalid_credentials',
        errors: {
          'password': ['Incorrect password.'],
        },
      );

      await type(tester, 'Your password', 'wrong');
      await type(tester, 'Type DELETE', 'DELETE');
      await tester.ensureVisible(find.byType(Checkbox));
      await tester.tap(find.byType(Checkbox));
      await tester.pump();
      await tester.tap(find.widgetWithText(ZButton, 'Delete my account'));
      await settle(tester);
      await tester.tap(find.text('Delete permanently'));
      await settle(tester);

      expect(find.text('Incorrect password.'), findsOneWidget);
      expect(find.text('Confirm deletion'), findsOneWidget);
      expect(fake.lastReasonCode, isNull, reason: 'skipping sends no reason');
      expect(auth.signedOutAfterDeletion, isFalse);
    });

    testWidgets('if something now blocks it, the flow goes back and says why', (tester) async {
      await pump(tester, const DeleteAccountScreen());
      await tester.tap(find.text('Continue'));
      await settle(tester);
      await tester.tap(find.text('Skip'));
      await settle(tester);

      fake.failNextDelete = const ConflictFailure(
        message: 'You have a booking in progress. Complete or cancel it first.',
        code: 'deletion_blocked',
      );

      await type(tester, 'Your password', 'pw');
      await type(tester, 'Type DELETE', 'DELETE');
      await tester.ensureVisible(find.byType(Checkbox));
      await tester.tap(find.byType(Checkbox));
      await tester.pump();
      await tester.tap(find.widgetWithText(ZButton, 'Delete my account'));
      await settle(tester);
      await tester.tap(find.text('Delete permanently'));
      await settle(tester);

      expect(find.text('Step 1 of 3'), findsOneWidget);
      expect(find.text('You have a booking in progress. Complete or cancel it first.'), findsOneWidget);
      expect(auth.signedOutAfterDeletion, isFalse);
    });

    testWidgets('the goodbye screen leads back to sign in and clears its flag', (tester) async {
      await pump(tester, const AccountDeletedScreen());
      container.read(accountDeletedProvider.notifier).state = true;

      expect(find.text('Your account has been deleted'), findsOneWidget);
      await tester.tap(find.text('Back to sign in'));
      await settle(tester);

      expect(find.text('route:${Routes.login}'), findsOneWidget);
      expect(container.read(accountDeletedProvider), isFalse);
    });
  });
}
