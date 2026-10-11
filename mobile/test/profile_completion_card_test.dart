// The creator's profile-completion card on the Profile tab.
//
// With SMS off, the server no longer scores the mobile-number rules (migration
// 058), and the card itself lists only sections a screen in this app can
// finish, each opening that screen. These pin both halves: what reaches the
// card from `GET /me/creator/completion`, and that nothing it shows is a dead
// end.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/features/profile/menu_screen.dart';
import 'package:zuste/models/creator_completion.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/discovery_provider.dart';
import 'package:zuste/router/routes.dart';

/// One section as `ProfileCompletionService::breakdown()` sends it: `missing`
/// is a list of rule keys.
Map<String, dynamic> _section(
  String key,
  String label, {
  int percent = 0,
  int weight = 10,
  int rulesTotal = 2,
  int mandatoryMissing = 0,
  List<String> missing = const [],
  bool? applicable,
}) => {
  'section': key,
  'label': label,
  'percent': percent,
  'weight': weight,
  'rules_total': rulesTotal,
  'rules_met': rulesTotal - missing.length,
  'mandatory_missing': mandatoryMissing,
  'missing': missing,
  'applicable': ?applicable,
  'complete': rulesTotal > 0 && missing.isEmpty,
};

CreatorCompletion _completion(
  List<Map<String, dynamic>> sections, {
  int overall = 82,
  String status = 'approved',
  List<Map<String, dynamic>> gates = const [],
}) => CreatorCompletion.fromJson({
  'completion': {
    'overall': overall,
    'required_percent': 60,
    'status': status,
    'sections': {for (final s in sections) s['section'] as String: s},
  },
  'gates': gates,
});

void main() {
  Future<void> settle(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
  }

  Future<void> pumpCard(
    WidgetTester tester,
    CreatorCompletion completion, {
    KycStatus? kyc,
  }) async {
    tester.view.physicalSize = const Size(1170, 2400);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    Widget screen(String name) => Scaffold(body: Text('screen $name'));

    final router = GoRouter(
      initialLocation: '/menu',
      routes: [
        GoRoute(
          path: '/menu',
          builder: (_, _) => Scaffold(
            body: Padding(
              padding: const EdgeInsets.all(16),
              child: ProfileCompletionCard(kycStatus: kyc),
            ),
          ),
        ),
        for (final path in [
          Routes.contacts,
          Routes.kyc,
          Routes.editProfile,
          Routes.myPackages,
          Routes.myPortfolio,
          Routes.socialAccounts,
        ])
          GoRoute(path: path, builder: (_, _) => screen(path)),
      ],
    );
    addTearDown(router.dispose);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          creatorCompletionProvider.overrideWith((ref) async => completion),
        ],
        child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
      ),
    );
    await settle(tester);
  }

  group('the completion model', () {
    test('reads the rule keys the server sends as missing', () {
      final section = CompletionSection.fromJson(
        _section('contact', 'Contact details', missing: ['email_verified']),
      );

      expect(section.missingKeys, ['email_verified']);
      expect(section.missing, isEmpty);
    });

    test('a section with no rule that applies is never a gap', () {
      final completion = _completion([
        _section('contact', 'Contact details', rulesTotal: 0, applicable: false),
        _section('kyc', 'KYC', missing: ['kyc_approved'], mandatoryMissing: 1),
      ]);

      expect(completion.incomplete.map((s) => s.section), ['kyc']);
    });

    test('an older payload without applicable reads it from rules_total', () {
      expect(
        CompletionSection.fromJson(_section('contact', 'Contact', rulesTotal: 0)).applicable,
        isFalse,
      );
      expect(
        CompletionSection.fromJson(_section('contact', 'Contact')).applicable,
        isTrue,
      );
    });
  });

  group('where each gap leads', () {
    CompletionSection section(String key, List<String> missing) =>
        CompletionSection.fromJson(_section(key, key, missing: missing));

    test('every gap the app can close opens the screen that closes it', () {
      expect(ProfileCompletionCard.routeFor(section('contact', ['email_verified'])), Routes.contacts);
      expect(ProfileCompletionCard.routeFor(section('kyc', ['kyc_submitted', 'kyc_approved'])), Routes.kyc);
      expect(ProfileCompletionCard.routeFor(section('pricing', ['package'])), Routes.myPackages);
      expect(ProfileCompletionCard.routeFor(section('portfolio', ['work_samples'])), Routes.myPortfolio);
      expect(ProfileCompletionCard.routeFor(section('social', ['linked_account'])), Routes.socialAccounts);
      expect(ProfileCompletionCard.routeFor(section('gallery', ['profile_photo', 'cover_image'])), Routes.editProfile);
      expect(ProfileCompletionCard.routeFor(section('verification', ['identity_badge'])), Routes.kyc);
    });

    test('a gap no screen here can close has no route', () {
      expect(ProfileCompletionCard.routeFor(section('bio', ['about_detailed'])), isNull);
      expect(ProfileCompletionCard.routeFor(section('gallery', ['cover_image', 'gallery_media'])), isNull);
      expect(ProfileCompletionCard.routeFor(section('verification', ['creator_badge'])), isNull);
      expect(ProfileCompletionCard.routeFor(section('social', ['verified_account'])), isNull);
      expect(ProfileCompletionCard.routeFor(section('basic', ['headline'])), isNull);
    });

    test('a rule this build does not know falls back to its section', () {
      expect(ProfileCompletionCard.routeFor(section('contact', ['whatsapp'])), Routes.contacts);
      expect(ProfileCompletionCard.routeFor(section('basic', ['nickname'])), isNull);
      expect(ProfileCompletionCard.routeFor(section('somethingnew', ['x'])), isNull);
    });

    test('a badge that follows a confirmed address opens nothing once it is confirmed', () {
      final confirmed = [section('contact', [])];
      final unconfirmed = [section('contact', ['email_verified'])];
      final noMobile = [section('contact', ['phone_verified'])];

      // The address is confirmed: Email & mobile has nothing left to do, and
      // the badge follows on the server.
      expect(ProfileCompletionCard.routeFor(section('verification', ['email_badge'])), isNull);
      expect(ProfileCompletionCard.routeFor(section('verification', ['email_badge']), all: confirmed), isNull);
      expect(ProfileCompletionCard.routeFor(section('verification', ['email_badge', 'creator_badge']), all: confirmed), isNull);
      // …so the section opens the next thing the creator can do instead.
      expect(
        ProfileCompletionCard.routeFor(section('verification', ['email_badge', 'identity_badge']), all: confirmed),
        Routes.kyc,
      );
      // Still unconfirmed: Email & mobile is where it is earned.
      expect(ProfileCompletionCard.routeFor(section('verification', ['email_badge']), all: unconfirmed), Routes.contacts);
      // The mobile badge waits on the mobile rules the same way.
      expect(ProfileCompletionCard.routeFor(section('verification', ['mobile_badge']), all: unconfirmed), isNull);
      expect(ProfileCompletionCard.routeFor(section('verification', ['mobile_badge']), all: noMobile), Routes.contacts);
    });

    test('a KYC gap opens the verification screen only while there is something to do there', () {
      final kyc = section('kyc', ['kyc_approved']);
      final badge = section('verification', ['identity_badge']);
      final unknown = section('kyc', ['kyc_renewed']);

      for (final status in [KycStatus.notSubmitted, KycStatus.actionNeeded, KycStatus.rejected, KycStatus.expired]) {
        expect(ProfileCompletionCard.routeFor(kyc, kyc: status), Routes.kyc, reason: '$status');
        expect(ProfileCompletionCard.routeFor(badge, kyc: status), Routes.kyc, reason: '$status');
      }
      // Under review the forms are locked; approved, the badge follows on
      // the server; suspended is the team's call.
      for (final status in [KycStatus.pending, KycStatus.underReview, KycStatus.approved, KycStatus.suspended]) {
        expect(ProfileCompletionCard.routeFor(kyc, kyc: status), isNull, reason: '$status');
        expect(ProfileCompletionCard.routeFor(badge, kyc: status), isNull, reason: '$status');
        expect(ProfileCompletionCard.routeFor(unknown, kyc: status), isNull, reason: '$status');
      }
      // Not known yet: offered, as before.
      expect(ProfileCompletionCard.routeFor(kyc), Routes.kyc);
      // A section with another way forward still opens that.
      expect(
        ProfileCompletionCard.routeFor(section('verification', ['identity_badge', 'email_badge']),
            all: [section('contact', ['email_verified'])], kyc: KycStatus.underReview),
        Routes.contacts,
      );
    });
  });

  group('the card', () {
    testWidgets('lists only what the creator can do, and opens it', (tester) async {
      // An approved creator as the API answers with SMS off: no mobile item
      // anywhere, KYC outstanding, and three gaps no screen here can close.
      await pumpCard(
        tester,
        _completion([
          _section('contact', 'Contact details', percent: 100),
          _section('kyc', 'KYC', weight: 15, mandatoryMissing: 1, missing: ['kyc_submitted', 'kyc_approved']),
          _section('bio', 'Bio', percent: 75, weight: 5, missing: ['about_detailed']),
          _section('gallery', 'Gallery', percent: 83, weight: 5, missing: ['gallery_media']),
          _section('verification', 'Verification', percent: 86, weight: 5, missing: ['creator_badge']),
        ]),
      );

      expect(find.text('Profile 82% complete'), findsOneWidget);
      expect(find.text('KYC · 0%'), findsOneWidget);
      expect(find.textContaining('Bio'), findsNothing);
      expect(find.textContaining('Gallery'), findsNothing);
      expect(find.textContaining('Verification'), findsNothing);
      expect(find.textContaining('Contact'), findsNothing);
      // Live already, so nothing is "needed to go live".
      expect(find.textContaining('needed to go live'), findsNothing);

      await tester.tap(find.text('KYC · 0%'));
      await settle(tester);

      expect(find.text('screen ${Routes.kyc}'), findsOneWidget);
    });

    testWidgets('each row opens its own screen', (tester) async {
      await pumpCard(
        tester,
        _completion([
          // Pricing weighs more, so it is first and the card itself opens it.
          _section('pricing', 'Pricing', weight: 15, missing: ['package']),
          _section('contact', 'Contact details', percent: 50, weight: 10, mandatoryMissing: 1, missing: ['email_verified']),
        ]),
      );

      expect(find.text('Pricing · 0%'), findsOneWidget);

      await tester.tap(find.text('Contact details · 50%'));
      await settle(tester);
      expect(find.text('screen ${Routes.contacts}'), findsOneWidget);
    });

    testWidgets('a mobile item from an older breakdown opens Email & mobile', (tester) async {
      await pumpCard(
        tester,
        _completion([
          _section('contact', 'Contact details', percent: 50, rulesTotal: 4, mandatoryMissing: 2, missing: ['phone', 'phone_verified']),
        ]),
      );

      await tester.tap(find.text('Contact details · 50%'));
      await settle(tester);
      expect(find.text('screen ${Routes.contacts}'), findsOneWidget);
    });

    testWidgets('a confirmed address never leaves a Verification row that leads nowhere', (tester) async {
      // A creator still in review, email confirmed, as a breakdown stored
      // before the badge caught up would read: the email badge outstanding
      // and mandatory, nothing else in the section the app can do.
      await pumpCard(
        tester,
        _completion(
          [
            _section('contact', 'Contact details', percent: 100),
            _section('kyc', 'KYC', weight: 15, mandatoryMissing: 1, missing: ['kyc_submitted', 'kyc_approved']),
            _section('verification', 'Verification', percent: 60, weight: 5, mandatoryMissing: 1, missing: ['email_badge', 'creator_badge']),
          ],
          overall: 55,
          status: 'pending_review',
        ),
      );

      expect(find.text('KYC · 0%'), findsOneWidget);
      expect(find.textContaining('Verification'), findsNothing);
      expect(find.textContaining('Contact'), findsNothing);
    });

    testWidgets('a KYC under review is not offered as something to do', (tester) async {
      final completion = _completion(
        [
          _section('kyc', 'KYC', percent: 50, weight: 15, mandatoryMissing: 1, missing: ['kyc_approved']),
          _section('verification', 'Verification', percent: 60, weight: 12, missing: ['identity_badge', 'creator_badge']),
          _section('pricing', 'Pricing', percent: 67, weight: 10, missing: ['package']),
        ],
        overall: 55,
        status: 'pending_review',
      );

      await pumpCard(tester, completion, kyc: KycStatus.underReview);

      expect(find.text('Pricing · 67%'), findsOneWidget);
      expect(find.textContaining('KYC'), findsNothing);
      expect(find.textContaining('Verification'), findsNothing);

      // Sent back for changes: the verification screen is where to go, and
      // both rows open it.
      await pumpCard(tester, completion, kyc: KycStatus.actionNeeded);

      expect(find.text('KYC · 50%'), findsOneWidget);
      expect(find.text('Verification · 60%'), findsOneWidget);

      await tester.tap(find.text('Verification · 60%'));
      await settle(tester);
      expect(find.text('screen ${Routes.kyc}'), findsOneWidget);
    });

    testWidgets('renders nothing when no gap has a screen here', (tester) async {
      await pumpCard(
        tester,
        _completion(
          [
            _section('bio', 'Bio', percent: 75, missing: ['about_detailed']),
            _section('verification', 'Verification', percent: 86, missing: ['creator_badge']),
            _section('contact', 'Contact details', rulesTotal: 0, applicable: false),
          ],
          gates: [
            {
              'feature_key': 'appear_in_search',
              'name': 'Appear in brand search',
              'gated': true,
              'allowed': false,
              'percent': 82,
              'required': 70,
              'reasons': ['Bio is 75% complete and must be finished.'],
            },
          ],
        ),
      );

      expect(find.textContaining('complete'), findsNothing);
      expect(find.textContaining('Appear in brand search'), findsNothing);
    });

    testWidgets('claims no locked feature, since nothing is withheld by completion', (tester) async {
      await pumpCard(
        tester,
        _completion(
          [_section('kyc', 'KYC', mandatoryMissing: 1, missing: ['kyc_approved'])],
          overall: 40,
          status: 'pending_review',
          gates: [
            {
              'feature_key': 'accept_bookings',
              'name': 'Accept bookings',
              'gated': true,
              'allowed': false,
              'percent': 40,
              'required': 60,
            },
          ],
        ),
      );

      expect(find.text('KYC · 0%'), findsOneWidget);
      expect(find.text('60% needed to go live'), findsOneWidget);
      expect(find.textContaining('unlock'), findsNothing);
    });
  });
}
