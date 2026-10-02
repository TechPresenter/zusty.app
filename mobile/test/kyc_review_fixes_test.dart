// KYC & verification fixes in the app, from the adversarial review.
//
// The KYC repository is faked at the edge (it records what would be sent and
// can be told to fail or to stall), so these pin what the screens and the
// provider promise: a refused write keeps the form and the checklist on
// screen, a cleared field is really sent as a clear, consent is asked for
// before the first upload or save, reviewer names and internal events never
// reach the applicant, and a decrypted document only touches the disk on an
// explicit "Open in another app" — in a folder that is wiped again.
import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/api_response.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/account_repository.dart';
import 'package:zuste/data/repositories/kyc_repository.dart';
import 'package:zuste/features/kyc/kyc_details_screen.dart';
import 'package:zuste/features/kyc/kyc_share_cache.dart';
import 'package:zuste/features/kyc/kyc_status_screen.dart';
import 'package:zuste/features/kyc/kyc_validation.dart';
import 'package:zuste/features/kyc/widgets/kyc_document_viewer.dart';
import 'package:zuste/features/kyc/widgets/kyc_gate.dart';
import 'package:zuste/features/profile/edit_profile_screen.dart';
import 'package:zuste/models/kyc.dart';
import 'package:zuste/models/notification.dart';
import 'package:zuste/models/taxonomy.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/account_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/kyc_provider.dart';
import 'package:zuste/providers/taxonomy_provider.dart';
import 'package:zuste/router/routes.dart';
import 'package:zuste/shared/widgets/status_chip.dart';
import 'package:zuste/shared/widgets/z_text_field.dart';

import 'support/mock_data.dart';

// -----------------------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------------------

const _consentText = 'I agree to Zusty processing my identity documents.';

/// Shaped like a real `GET /me/kyc` response.
Map<String, dynamic> _overview({
  String kind = 'creator',
  String status = 'draft',
  bool editable = true,
  bool processingConsentRequired = false,
  String? reviewedAt,
  List<String> resubmitSlots = const [],
  List<Map<String, dynamic>> requirements = const [],
  List<Map<String, dynamic>> documents = const [],
  Map<String, dynamic> details = const {},
  List<Map<String, dynamic>> timeline = const [],
  int completeness = 40,
}) =>
    {
      'profile': {
        'id': 1,
        'reference': 'KYC-0001',
        'kind': kind,
        'status': status,
        'status_label': status == 'draft' ? 'Draft' : status,
        'is_editable': editable,
        'reviewed_at': reviewedAt,
        'resubmit_slots': resubmitSlots,
        'badge_status': 'none',
        'consent_text': _consentText,
        'processing_consent_required': processingConsentRequired,
        'processing_consent_at': processingConsentRequired
            ? null
            : '2026-09-30 10:00:00',
      },
      'details': details,
      'requirements': requirements,
      'documents': documents,
      'completeness': completeness,
      'can_submit': false,
      'timeline': timeline,
    };

Map<String, dynamic> _requirement(
  String slot,
  String label, {
  bool multiple = false,
  String reviewStatus = 'missing',
  bool? resubmitRequested,
  String? rejectionReason,
}) =>
    {
      'slot': slot,
      'label': label,
      'description': '',
      'is_required': true,
      'allows_multiple': multiple,
      'allowed_formats': ['pdf', 'jpg', 'png'],
      'max_size_mb': 10,
      'review_status': reviewStatus,
      'rejection_reason': rejectionReason,
      'resubmit_requested': ?resubmitRequested,
    };

Map<String, dynamic> _document(
  int id,
  String slot, {
  String title = 'Document',
  String status = 'pending',
  String? reason,
  String uploadedAt = '2026-09-30 09:00:00',
}) =>
    {
      'id': id,
      'slot': slot,
      'title': title,
      'original_name': '$title.pdf',
      'mime_type': 'application/pdf',
      'status': status,
      'rejection_reason': reason,
      'is_current': true,
      'uploaded_at': uploadedAt,
      'download_token': 'tok-$id',
    };

const _creatorDetails = {
  'full_name': 'Ravi Kumar',
  'date_of_birth': '1995-04-01',
  'aadhaar_masked': 'XXXX XXXX 1234',
  'aadhaar_last4': '1234',
  'pan_masked': 'XXXXXX234P',
  'city': 'Pune',
};

const _brandDetails = {
  'company_name': 'Acme Foods Pvt Ltd',
  'company_type': 'private_limited',
  'gst_masked': 'XXXXXXXXXXX1Z5',
  'cin_masked': 'XXXXXXXXXXXXXXXXX3456',
  'registered_address': '1 Main Road, Pune',
  'office_address': 'Old office, Baner',
};

// -----------------------------------------------------------------------------
// Fakes
// -----------------------------------------------------------------------------

/// Records what the screens would send, and fails or stalls on request.
class _FakeKyc extends KycRepository {
  _FakeKyc(super.client, this.current);

  Map<String, dynamic> current;
  int overviewCalls = 0;

  /// Thrown by the next overview read.
  ApiFailure? failNextOverview;

  /// While set, overview reads answer only once it completes — with what
  /// the server held when the read STARTED, like a real slow request.
  Completer<void>? overviewGate;

  final saves = <({Map<String, dynamic> details, bool consent})>[];
  final saveFailures = <ApiFailure>[];

  /// What the server holds after a successful save.
  Map<String, dynamic>? afterSave;

  ApiFailure? failNextDelete;

  @override
  Future<KycOverview> overview() async {
    overviewCalls++;
    final snapshot = current;
    final gate = overviewGate;
    if (gate != null) await gate.future;

    final failure = failNextOverview;
    if (failure != null) {
      failNextOverview = null;
      throw failure;
    }
    return KycOverview.fromJson(snapshot);
  }

  @override
  Future<KycOverview> saveDetails(
    Map<String, dynamic> details, {
    bool consent = false,
  }) async {
    saves.add((details: Map.of(details), consent: consent));
    if (saveFailures.isNotEmpty) throw saveFailures.removeAt(0);
    if (afterSave != null) current = afterSave!;
    return KycOverview.fromJson(current);
  }

  @override
  Future<KycOverview> deleteDocument(int id) async {
    final failure = failNextDelete;
    if (failure != null) {
      failNextDelete = null;
      throw failure;
    }
    return KycOverview.fromJson(current);
  }
}

/// Refuses every profile save the way the API refuses a ticked rename.
class _LockedAccount extends AccountRepository {
  _LockedAccount(super.client);

  final sent = <Map<String, dynamic>>[];

  @override
  Future<User> updateProfile(Map<String, dynamic> fields) async {
    sent.add(Map.of(fields));
    throw const ConflictFailure(
      message: 'Your name is locked while your account has the verified '
          'badge. Contact support to change it.',
      code: 'identity_locked',
    );
  }
}

ApiClient _client([Dio? dio]) => ApiClient(
      secureStore: SecureStore(),
      connectivity: ConnectivityService(),
      dio: dio ?? Dio(),
    );

/// Captures what reaches the network and answers with a success envelope.
class _CapturingAdapter implements HttpClientAdapter {
  RequestOptions? last;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    last = options;
    return ResponseBody.fromString(
      jsonEncode({'success': true, 'message': 'Saved.', 'data': {}}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

AppNotification _notification(int id, String type) => AppNotification(
      id: id,
      title: 'Verification update',
      kind: NotificationKind.kyc,
      type: type,
    );

final _inbox = StateProvider<List<AppNotification>>((_) => const []);

TaxonomyBundle get _taxonomies => const TaxonomyBundle(
      version: 'test',
      taxonomies: {
        Tax.idProofType: [
          TaxonomyTerm(value: 'aadhaar', label: 'Aadhaar'),
          TaxonomyTerm(value: 'pan', label: 'PAN card'),
          TaxonomyTerm(value: 'passport', label: 'Passport'),
          TaxonomyTerm(value: 'driving_license', label: 'Driving licence'),
          TaxonomyTerm(value: 'voter_id', label: 'Voter ID'),
        ],
      },
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late Directory scratch;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();

    // Every sweep and share in this file runs against a scratch folder,
    // never the platform's.
    scratch = Directory.systemTemp.createTempSync('kyc_share_test');
    KycShareCache.temporaryDirectory = () async => scratch;
  });

  tearDown(() {
    if (scratch.existsSync()) scratch.deleteSync(recursive: true);
  });

  List<Override> overridesFor(_FakeKyc fake) => [
        keyValueCacheProvider.overrideWithValue(cache),
        currentUserProvider.overrideWithValue(
          User.fromJson(MockData.currentUser),
        ),
        kycRepositoryProvider.overrideWithValue(fake),
        taxonomiesProvider.overrideWithValue(_taxonomies),
        notificationsProvider.overrideWith(
          (ref) async => Paginated(
            items: ref.watch(_inbox),
            pagination: Pagination.empty,
          ),
        ),
      ];

  // ---------------------------------------------------------------------------
  group('models', () {
    test('a requested re-upload clears once a fresh copy is uploaded', () {
      // mobile-4: the server keeps the slot in resubmit_slots until the next
      // submission, but a replacement uploaded after the request answers it.
      final requested = KycOverview.fromJson(_overview(
        status: 'info_required',
        reviewedAt: '2026-10-01 10:00:00',
        resubmitSlots: ['pan_card'],
        requirements: [
          _requirement('pan_card', 'PAN Card',
              reviewStatus: 'pending', resubmitRequested: true),
        ],
        documents: [
          _document(1, 'pan_card', uploadedAt: '2026-09-28 09:00:00'),
        ],
      ));
      final pan = requested.requirements.single;
      expect(requested.slotState(pan), KycSlotState.reuploadRequested);

      final replaced = KycOverview.fromJson(_overview(
        status: 'info_required',
        reviewedAt: '2026-10-01 10:00:00',
        resubmitSlots: ['pan_card'],
        requirements: [
          _requirement('pan_card', 'PAN Card',
              reviewStatus: 'pending', resubmitRequested: true),
        ],
        documents: [
          _document(2, 'pan_card', uploadedAt: '2026-10-01 11:30:00'),
        ],
      ));
      expect(replaced.isResubmitRequested(pan), isFalse);
      expect(replaced.slotState(pan), KycSlotState.uploaded);

      // A rejected replacement does not answer the request.
      final rejected = KycOverview.fromJson(_overview(
        status: 'info_required',
        reviewedAt: '2026-10-01 10:00:00',
        resubmitSlots: ['pan_card'],
        requirements: [_requirement('pan_card', 'PAN Card')],
        documents: [
          _document(3, 'pan_card',
              status: 'rejected', uploadedAt: '2026-10-01 11:30:00'),
        ],
      ));
      expect(rejected.isResubmitRequested(pan), isTrue);
    });

    test('the review trail never names a reviewer or shows internal events',
        () {
      // mobile-9 / C5.
      final overview = KycOverview.fromJson(_overview(
        timeline: [
          {
            'action': 'rejected',
            'label': 'Rejected',
            'note': 'PAN is blurred.',
            'actor_type': 'admin',
            'actor_name': 'Anil Reviewer',
          },
          {
            'action': 'note_added',
            'label': 'Internal note added',
            'actor_type': 'admin',
            'actor_name': 'Anil Reviewer',
          },
          {
            'action': 'document_previewed',
            'label': 'Document viewed',
            'actor_type': 'admin',
            'actor_name': 'Anil Reviewer',
          },
          {
            'action': 'document_previewed',
            'label': 'Document viewed',
            'actor_type': 'user',
          },
          {'action': 'submitted', 'label': 'Submitted', 'actor_type': 'user'},
        ],
      ));

      expect(overview.timeline.map((e) => e.action), [
        'rejected',
        'document_previewed',
        'submitted',
      ]);
      expect(overview.timeline.first.actorLabel, 'Zusty team');
      expect(
        overview.timeline.map((e) => e.actorLabel),
        isNot(contains('Anil Reviewer')),
      );
    });

    test('processing consent is read from the profile', () {
      final needed = KycOverview.fromJson(
        _overview(processingConsentRequired: true),
      );
      expect(needed.profile.processingConsentRequired, isTrue);
      expect(needed.profile.processingConsentAt, isNull);

      // An older server that knows nothing of it does not ask.
      final older = KycProfile.fromJson(const {'status': 'draft'});
      expect(older.processingConsentRequired, isFalse);
    });

    test('Other ID offers neither Aadhaar nor PAN, and checks the number', () {
      // mobile-6.
      expect(KycRules.otherIdTypes, isNot(contains('aadhaar')));
      expect(KycRules.otherIdTypes, isNot(contains('pan')));

      expect(KycRules.otherId('passport', 'A1234567'), isNull);
      expect(KycRules.otherId('passport', 'A12'), isNotNull);
      expect(KycRules.otherId(null, 'A1234567'), contains('Choose'));
      expect(KycRules.otherId('aadhaar', '234567890123'), contains('Choose'));
      expect(KycRules.otherId('voter_id', 'ABC1234567'), isNull);
      expect(KycRules.otherId('driving_license', 'MH-03-2008-0012345'), isNull);
      expect(KycRules.otherId('passport', ''), isNull);
    });

    test('a verification gate is worded for where verification stands', () {
      // mobile-8.
      expect(
        KycGateCopy.of(KycStatus.underReview).actionLabel,
        'View verification',
      );
      expect(KycGateCopy.of(KycStatus.pending).title, contains('under review'));
      expect(KycGateCopy.of(KycStatus.suspended).route, Routes.support);
      expect(KycGateCopy.of(KycStatus.notSubmitted).actionLabel, 'Verify');
      expect(KycGateCopy.of(KycStatus.rejected).route, Routes.kyc);
      expect(isKycGate(const ForbiddenFailure(code: 'kyc_required')), isTrue);
      expect(isKycGate(const ForbiddenFailure()), isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  group('network', () {
    test('a KYC details save sends explicit nulls instead of dropping them',
        () async {
      // mobile-5: the client used to prune nulls, so a cleared field never
      // reached the server.
      final adapter = _CapturingAdapter();
      final repository = KycRepository(
        _client(Dio()..httpClientAdapter = adapter),
      );

      await repository.saveDetails(
        {'office_address': null, 'cin_number': null, 'city': 'Pune'},
        consent: true,
      );

      final body = adapter.last!.data as Map;
      expect(body.containsKey('office_address'), isTrue);
      expect(body['office_address'], isNull);
      expect(body['cin_number'], isNull);
      expect(body['consent'], isTrue);

      // Everywhere else the pruning stays as it was.
      await _client(Dio()..httpClientAdapter = adapter)
          .put('/me', body: {'bio': null, 'city': 'Pune'});
      expect((adapter.last!.data as Map).containsKey('bio'), isFalse);
    });

    test('debug logs never carry identity numbers, tokens or signed links', () {
      // mobile-10.
      final kyc = Uri.parse('https://api.zusty.test/api/v1/me/kyc/details');
      expect(
        ApiClient.redactBody(kyc, {'aadhaar_number': '234567890123'}),
        '[redacted]',
      );

      final download = Uri.parse(
        'https://api.zusty.test/api/v1/me/kyc/documents/4/preview?token=abc.def',
      );
      expect(ApiClient.redactUri(download), isNot(contains('abc.def')));
      expect(ApiClient.redactUri(download), contains('token=***'));

      final profile = Uri.parse('https://api.zusty.test/api/v1/me');
      final logged = ApiClient.redactBody(profile, {
        'name': 'Ravi',
        'gst_number': '27AABCG1234M1Z5',
        'user': {
          'access_token': 'jwt',
          'avatar': 'https://cdn.test/a.png?token=secret&w=200',
        },
      }).toString();
      expect(logged, contains('Ravi'));
      expect(logged, isNot(contains('27AABCG1234M1Z5')));
      expect(logged, isNot(contains('jwt')));
      expect(logged, isNot(contains('secret')));

      expect(
        ApiClient.redactBody(
          Uri.parse('https://api.zusty.test/api/v1/auth/login'),
          {'password': 'hunter2'},
        ),
        '[redacted]',
      );
    });
  });

  // ---------------------------------------------------------------------------
  group('document copies', () {
    test('sharing writes one copy in its own folder and deletes it after',
        () async {
      // mobile-1 / coverage-7.
      String? sharedPath;
      bool? existedWhileShared;
      List<int>? sharedBytes;

      KycShareCache.shareFiles = (params) async {
        sharedPath = params.files!.single.path;
        existedWhileShared = File(sharedPath!).existsSync();
        sharedBytes = File(sharedPath!).readAsBytesSync();
        return const ShareResult('ok', ShareResultStatus.success);
      };
      addTearDown(() {
        KycShareCache.shareFiles = (params) => SharePlus.instance.share(params);
      });

      await KycShareCache.share(
        KycFile(
          bytes: Uint8List.fromList([37, 80, 68, 70]),
          mimeType: 'application/pdf',
          fileName: '../PAN_Ravi.pdf',
        ),
        subject: 'PAN Card',
      );

      expect(existedWhileShared, isTrue);
      expect(sharedBytes, [37, 80, 68, 70]);
      expect(
        sharedPath,
        startsWith(
          '${scratch.path}${Platform.pathSeparator}${KycShareCache.folderName}',
        ),
      );
      // Gone once the share sheet returned.
      expect(File(sharedPath!).existsSync(), isFalse);
      expect(File(sharedPath!).parent.existsSync(), isFalse);
    });

    test('the sweep removes every leftover copy and nothing else', () async {
      Directory make(String name) =>
          Directory('${scratch.path}${Platform.pathSeparator}$name')
            ..createSync(recursive: true);
      void file(Directory dir) =>
          File('${dir.path}${Platform.pathSeparator}doc.pdf')
              .writeAsBytesSync([1, 2, 3]);

      final ours = make('kyc_share/abc');
      final plugin = make('share_plus');
      final legacy = make('3f2504e0-4f89-41d3-9a0c-0305e82c3301');
      final images = make('libCachedImageData');
      for (final dir in [ours, plugin, legacy, images]) {
        file(dir);
      }

      await KycShareCache.purge();

      expect(
        Directory('${scratch.path}${Platform.pathSeparator}kyc_share')
            .existsSync(),
        isFalse,
      );
      expect(plugin.existsSync(), isFalse);
      expect(legacy.existsSync(), isFalse);
      expect(images.existsSync(), isTrue);
    });

    testWidgets('a PDF opens in the app; sharing needs an explicit, warned tap',
        (tester) async {
      var shares = 0;
      KycShareCache.shareFiles = (params) async {
        shares++;
        return const ShareResult('ok', ShareResultStatus.success);
      };
      addTearDown(() {
        KycShareCache.shareFiles = (params) => SharePlus.instance.share(params);
      });

      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light,
        home: KycDocumentViewer(
          title: 'PAN Card',
          file: Future.value(KycFile(
            bytes: Uint8List.fromList([37, 80, 68, 70]),
            mimeType: 'application/pdf',
            fileName: 'pan.pdf',
          )),
        ),
      ));
      await tester.pump();

      expect(find.textContaining('PDFs cannot be shown inside Zusty'),
          findsOneWidget);
      expect(shares, 0);

      await tester.tap(find.text('Open in another app'));
      await tester.pumpAndSettle();
      expect(find.text('Open in another app?'), findsOneWidget);
      expect(find.textContaining('decrypted copy'), findsOneWidget);

      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(shares, 0);
      expect(
        Directory('${scratch.path}${Platform.pathSeparator}kyc_share')
            .existsSync(),
        isFalse,
      );
    });
  });

  // ---------------------------------------------------------------------------
  group('provider', () {
    late _FakeKyc fake;
    late ProviderContainer container;

    void start(Map<String, dynamic> overview) {
      fake = _FakeKyc(_client(), overview);
      container = ProviderContainer(overrides: overridesFor(fake));
      addTearDown(container.dispose);
      container.listen(kycProvider, (_, _) {});
    }

    Future<void> settle() async {
      for (var i = 0; i < 5; i++) {
        await Future<void>.delayed(Duration.zero);
      }
    }

    test('a refused write keeps the overview and is rethrown', () async {
      // mobile-2.
      start(_overview(details: _creatorDetails));
      final before = await container.read(kycProvider.future);

      fake.saveFailures.add(const ValidationFailure(
        message: 'This PAN is already linked to another account.',
        errors: {
          'pan_number': ['This PAN is already linked to another account.'],
        },
      ));

      await expectLater(
        container.read(kycProvider.notifier).saveDetails({'pan_number': 'X'}),
        throwsA(isA<ValidationFailure>()),
      );

      final after = container.read(kycProvider);
      expect(after.hasError, isFalse);
      expect(after.valueOrNull, same(before));
    });

    test('a failed refresh keeps the last overview beside the error', () async {
      start(_overview(details: _creatorDetails));
      await container.read(kycProvider.future);

      fake.failNextOverview = const OfflineFailure();
      await container.read(kycProvider.notifier).refresh();

      final state = container.read(kycProvider);
      expect(state.hasValue, isTrue);
      expect(state.error, isA<OfflineFailure>());
    });

    test('a background read that raced a write never overwrites it', () async {
      start(_overview(completeness: 10));
      await container.read(kycProvider.future);

      // A background read starts (the app came back from the file picker)
      // and stalls; it will answer with what the server held at its start.
      final gate = Completer<void>();
      fake.overviewGate = gate;
      final quiet = container.read(kycProvider.notifier).refreshQuietly();
      await settle();

      // The upload (here: a save) lands while the read is in flight.
      fake.overviewGate = null;
      fake.afterSave = _overview(completeness: 90);
      await container.read(kycProvider.notifier).saveDetails({'city': 'Pune'});
      expect(container.read(kycProvider).valueOrNull?.completeness, 90);

      // The stale read finally answers — and is dropped.
      gate.complete();
      await quiet;
      expect(container.read(kycProvider).valueOrNull?.completeness, 90);
    });

    test('a new verification notification re-reads the overview', () async {
      // mobile-3.
      start(_overview());
      container.read(_inbox.notifier).state = [
        _notification(5, 'kyc.submitted'),
      ];
      await container.read(kycProvider.future);
      await settle();
      final baseline = fake.overviewCalls;

      // Something unrelated arrives: no re-read.
      container.read(_inbox.notifier).state = [
        _notification(6, 'booking.accepted'),
        _notification(5, 'kyc.submitted'),
      ];
      await settle();
      expect(fake.overviewCalls, baseline);

      // The reviewer decided.
      container.read(_inbox.notifier).state = [
        _notification(7, 'kyc.resubmission_requested'),
        _notification(6, 'booking.accepted'),
        _notification(5, 'kyc.submitted'),
      ];
      await settle();
      expect(fake.overviewCalls, baseline + 1);
    });
  });

  // ---------------------------------------------------------------------------
  group('details form', () {
    late _FakeKyc fake;

    Future<void> pump(WidgetTester tester, Map<String, dynamic> overview) async {
      tester.view.physicalSize = const Size(1170, 9000);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      fake = _FakeKyc(_client(), overview);

      final router = GoRouter(
        initialLocation: '/details',
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => const Scaffold(body: Text('route:home')),
            routes: [
              GoRoute(
                path: 'details',
                builder: (_, _) => const KycDetailsScreen(),
              ),
            ],
          ),
        ],
      );

      await tester.pumpWidget(ProviderScope(
        overrides: overridesFor(fake),
        child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
    }

    Finder fieldLabelled(String label) => find.descendant(
          of: find.ancestor(
            of: find.text(label),
            matching: find.byType(ZTextField),
          ),
          matching: find.byType(TextFormField),
        );

    Future<void> save(WidgetTester tester) async {
      await tester.tap(find.text('Save details'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
    }

    testWidgets('a refused save keeps the form and marks the field',
        (tester) async {
      // mobile-2.
      await pump(tester, _overview(details: _creatorDetails));

      fake.saveFailures.add(const ValidationFailure(
        message: 'The given data was invalid.',
        errors: {
          'full_name': ['Use your name exactly as it is on your PAN.'],
        },
      ));

      await tester.enterText(fieldLabelled('Full legal name *'), 'Ravi K');
      await save(tester);

      expect(fake.saves, hasLength(1));
      expect(find.byType(Form), findsOneWidget);
      expect(find.text('Use your name exactly as it is on your PAN.'),
          findsOneWidget);
      expect(find.text('We hit a snag'), findsNothing);
    });

    testWidgets('a cleared field is sent as a clear, and only then "saved"',
        (tester) async {
      // mobile-5.
      await pump(tester, _overview(kind: 'brand', details: _brandDetails));
      fake.afterSave = _overview(
        kind: 'brand',
        details: {..._brandDetails, 'office_address': null},
      );

      await tester.enterText(fieldLabelled('Office address'), '');
      await save(tester);

      expect(fake.saves.single.details, {'office_address': null});
      expect(find.text('route:home'), findsOneWidget);
      expect(find.text('Details saved.'), findsOneWidget);
    });

    testWidgets('a clear the server did not apply is not reported as saved',
        (tester) async {
      await pump(tester, _overview(kind: 'brand', details: _brandDetails));
      // The server answers with the old value still in place.
      fake.afterSave = _overview(kind: 'brand', details: _brandDetails);

      await tester.enterText(fieldLabelled('Office address'), '');
      await save(tester);

      expect(find.text('Details saved.'), findsNothing);
      expect(find.textContaining('These were not cleared: Office address'),
          findsOneWidget);
      expect(find.byType(Form), findsOneWidget);
    });

    testWidgets('an optional number on file can be removed', (tester) async {
      // mobile-5: a CIN entered for what turned out to be an LLP.
      await pump(
        tester,
        _overview(
          kind: 'brand',
          details: {..._brandDetails, 'company_type': 'llp'},
        ),
      );
      fake.afterSave = _overview(
        kind: 'brand',
        details: {..._brandDetails, 'company_type': 'llp', 'cin_masked': null},
      );

      // The CIN is the only optional number on file here.
      await tester.tap(find.text('Remove'));
      await tester.pump();
      expect(find.textContaining('will be removed when you save'),
          findsOneWidget);

      await save(tester);
      expect(fake.saves.single.details, {'cin_number': null});
      expect(find.text('Details saved.'), findsOneWidget);
    });

    testWidgets('Other ID offers only what the server accepts',
        (tester) async {
      // mobile-6.
      await pump(tester, _overview(details: _creatorDetails));

      expect(find.text('Passport'), findsOneWidget);
      expect(find.text('Voter ID'), findsOneWidget);
      expect(find.text('PAN card'), findsNothing);
      expect(find.widgetWithText(ZChip, 'Aadhaar'), findsNothing);
    });

    testWidgets('the first save asks for processing consent and sends it',
        (tester) async {
      // documents-5 / C3.
      await pump(
        tester,
        _overview(details: _creatorDetails, processingConsentRequired: true),
      );

      await tester.enterText(fieldLabelled('City'), 'Mumbai');
      await save(tester);
      await tester.pumpAndSettle();

      expect(find.text('Before you add your documents'), findsOneWidget);
      expect(find.text(_consentText), findsOneWidget);
      expect(fake.saves, isEmpty);

      // The button does nothing until the box is ticked.
      await tester.tap(find.text('Agree and continue'));
      await tester.pumpAndSettle();
      expect(find.text('Before you add your documents'), findsOneWidget);

      await tester.tap(find.byType(Checkbox).last);
      await tester.pump();
      await tester.tap(find.text('Agree and continue'));
      await tester.pumpAndSettle();

      expect(fake.saves.single.consent, isTrue);
      expect(fake.saves.single.details, {'city': 'Mumbai'});
    });

    testWidgets('a 422 for missing consent asks, then saves again',
        (tester) async {
      await pump(tester, _overview(details: _creatorDetails));
      fake.saveFailures.add(const ValidationFailure(
        message: 'Please agree to the processing of your documents first.',
        errors: {
          'consent': ['Please agree to the processing of your documents first.'],
        },
      ));

      await tester.enterText(fieldLabelled('City'), 'Mumbai');
      await save(tester);
      // Not pumpAndSettle: the save button keeps spinning under the sheet.
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Before you add your documents'), findsOneWidget);
      expect(
        find.text('Please agree to the processing of your documents first.'),
        findsOneWidget,
      );

      await tester.tap(find.byType(Checkbox).last);
      await tester.pump();
      await tester.tap(find.text('Agree and continue'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(fake.saves, hasLength(2));
      expect(fake.saves.first.consent, isFalse);
      expect(fake.saves.last.consent, isTrue);
      expect(find.text('Details saved.'), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  group('verification screen', () {
    late _FakeKyc fake;

    Future<void> pump(WidgetTester tester, Map<String, dynamic> overview) async {
      tester.view.physicalSize = const Size(1170, 9000);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      fake = _FakeKyc(_client(), overview);

      await tester.pumpWidget(ProviderScope(
        overrides: overridesFor(fake),
        child: MaterialApp(
          theme: AppTheme.light,
          home: const KycStatusScreen(),
        ),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));
    }

    testWidgets('every rejected file shows its own reason, in full',
        (tester) async {
      // mobile-7.
      const long = 'Page 2 is cut off at the bottom, so the registration '
          'number and the issuing office cannot be read. Upload all pages.';
      await pump(
        tester,
        _overview(
          status: 'rejected',
          requirements: [
            _requirement('tax_document', 'Tax documents',
                multiple: true,
                reviewStatus: 'rejected',
                rejectionReason: 'Blurry scan.'),
          ],
          documents: [
            _document(1, 'tax_document',
                title: 'ITR 2025', status: 'rejected', reason: 'Blurry scan.'),
            _document(2, 'tax_document',
                title: 'ITR 2024', status: 'rejected', reason: long),
          ],
        ),
      );

      expect(find.text('Reason: Blurry scan.'), findsOneWidget);
      expect(find.text('Reason: $long'), findsOneWidget);
      expect(find.textContaining('could not accept 2 files'), findsOneWidget);
    });

    testWidgets('the review trail says "Zusty team", never a reviewer',
        (tester) async {
      // mobile-9.
      await pump(
        tester,
        _overview(
          status: 'rejected',
          timeline: [
            {
              'action': 'rejected',
              'label': 'Rejected',
              'note': 'PAN is blurred.',
              'actor_type': 'admin',
              'actor_name': 'Anil Reviewer',
              'created_at': '2026-10-01 12:00:00',
            },
            {
              'action': 'note_added',
              'label': 'Internal note added',
              'actor_type': 'admin',
              'actor_name': 'Anil Reviewer',
            },
          ],
        ),
      );

      expect(find.textContaining('Zusty team'), findsWidgets);
      expect(find.textContaining('Anil Reviewer'), findsNothing);
      expect(find.text('Internal note added'), findsNothing);
    });

    testWidgets('a replaced slot stops asking for a re-upload', (tester) async {
      // mobile-4.
      await pump(
        tester,
        _overview(
          status: 'info_required',
          reviewedAt: '2026-10-01 10:00:00',
          resubmitSlots: ['pan_card'],
          requirements: [
            _requirement('pan_card', 'PAN Card',
                reviewStatus: 'pending', resubmitRequested: true),
          ],
          documents: [
            _document(9, 'pan_card',
                title: 'PAN Card', uploadedAt: '2026-10-01 10:30:00'),
          ],
        ),
      );

      expect(find.text('Re-upload requested'), findsNothing);
      expect(find.textContaining('To fix:'), findsNothing);
      expect(find.text('Uploaded'), findsOneWidget);
    });

    testWidgets('a refused removal keeps the checklist on screen',
        (tester) async {
      // mobile-2.
      await pump(
        tester,
        _overview(
          requirements: [
            _requirement('pan_card', 'PAN Card', reviewStatus: 'pending'),
          ],
          documents: [_document(3, 'pan_card', title: 'PAN Card')],
        ),
      );
      fake.failNextDelete = const RateLimitFailure(
        message: 'Too many changes. Try again in a minute.',
      );

      await tester.tap(find.byTooltip('Remove'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Remove').last);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('Too many changes. Try again in a minute.'),
          findsOneWidget);
      expect(find.text('Documents'), findsOneWidget);
      expect(find.text('We hit a snag'), findsNothing);
    });

    testWidgets('opening the screen re-reads the overview; a failure keeps it',
        (tester) async {
      // mobile-3 and mobile-2.
      await pump(tester, _overview());
      expect(fake.overviewCalls, 1);

      final element = tester.element(find.byType(KycStatusScreen));
      final container = ProviderScope.containerOf(element);

      fake.failNextOverview = const OfflineFailure();
      await container.read(kycProvider.notifier).refresh();
      await tester.pump();

      expect(find.textContaining('as it was last loaded'), findsOneWidget);
      expect(find.text('Documents'), findsOneWidget);
      expect(find.text('You are offline'), findsNothing);
    });

    testWidgets('masked Aadhaar is a requirement, not a tip', (tester) async {
      // coverage-6.
      await pump(
        tester,
        _overview(
          requirements: [_requirement('aadhaar_front', 'Aadhaar (front)')],
        ),
      );

      expect(find.textContaining('Masked Aadhaar required'), findsOneWidget);
      expect(find.textContaining('Tip:'), findsNothing);
    });
  });

  // ---------------------------------------------------------------------------
  group('identity lock', () {
    testWidgets('a refused rename restores the name and locks the field',
        (tester) async {
      // bluetick-8 / C4.
      tester.view.physicalSize = const Size(1170, 7200);
      tester.view.devicePixelRatio = 3.0;
      addTearDown(tester.view.reset);

      final fake = _FakeKyc(_client(), _overview(kind: 'brand'));
      final account = _LockedAccount(_client());

      await tester.pumpWidget(ProviderScope(
        overrides: [
          ...overridesFor(fake),
          accountRepositoryProvider.overrideWithValue(account),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          home: const EditProfileScreen(),
        ),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      final company = find.descendant(
        of: find.ancestor(
          of: find.text('Company / brand name'),
          matching: find.byType(ZTextField),
        ),
        matching: find.byType(TextFormField),
      );

      await tester.enterText(company, 'Famous FMCG Brand');
      await tester.tap(find.text('Save changes'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(account.sent.single['company_name'], 'Famous FMCG Brand');
      expect(
        find.textContaining('Your name is locked while your account has the '
            'verified badge'),
        findsOneWidget,
      );
      // The stored name is back, and the field no longer takes edits.
      expect(find.text('Glow Skincare Co.'), findsWidgets);
      expect(find.textContaining('Locked while your account has the verified '
          'badge'), findsWidgets);
    });
  });
}
