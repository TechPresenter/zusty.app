// The goodbye after an account deletion, and the delete screen's route
// guard, through the app's REAL router.
//
// routerProvider builds a new GoRouter on every sign-in change, and on the
// web a rebuilt router starts from the address the app was first opened on,
// not from initialLocation. So the goodbye cannot rest on initialLocation
// alone: the redirect itself must send a signed-out user to /account-deleted
// while accountDeletedProvider is set, and stop once they leave. The flag
// lives in memory, so an /account-deleted address met without it (a reload,
// a restored tab, a stray link) is sent through the normal gates instead.
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/privacy_repository.dart';
import 'package:zuste/features/privacy/account_deleted_screen.dart';
import 'package:zuste/features/privacy/delete_account_screen.dart';
import 'package:zuste/models/privacy.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/privacy_provider.dart';
import 'package:zuste/router/app_router.dart';
import 'package:zuste/router/routes.dart';

import 'support/mock_data.dart';

/// Signed out from the start, as the device is right after a deletion.
class _SignedOut extends AuthController {
  @override
  AuthState build() => const AuthState(stage: AuthStage.unauthenticated);
}

/// A first launch that has not finished onboarding.
class _Onboarding extends AuthController {
  @override
  AuthState build() => const AuthState(stage: AuthStage.onboarding);
}

/// Signed in, for the delete screen.
class _SignedIn extends AuthController {
  @override
  AuthState build() => AuthState(
        stage: AuthStage.authenticated,
        user: User.fromJson(MockData.currentUser),
      );
}

/// Serves the overview the delete and privacy screens read.
class _FakePrivacy extends PrivacyRepository {
  _FakePrivacy(super.client);

  @override
  Future<PrivacyOverview> overview() async => PrivacyOverview.fromJson(const {
        'verification': {
          'password': true,
          'channels': [
            {'channel': 'email', 'destination': 'it****@zuste.test'},
          ],
        },
        'export': {'latest': null, 'can_request': true},
        'deletion': {
          'can_delete': true,
          'blockers': [],
          'confirm_phrase': 'DELETE',
          'reasons': [],
          'erased': [],
          'retained': [],
          'retained_counts': {},
        },
      });
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({'zuste.onboarding_complete': true});
    cache = await KeyValueCache.open();
  });

  Future<void> frames(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));
  }

  Future<ProviderContainer> pump(
    WidgetTester tester, {
    required bool justDeleted,
    AuthController Function() auth = _SignedOut.new,
  }) async {
    tester.view.physicalSize = const Size(1170, 2532);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    final container = ProviderContainer(overrides: [
      keyValueCacheProvider.overrideWithValue(cache),
      authProvider.overrideWith(auth),
      privacyRepositoryProvider.overrideWithValue(_FakePrivacy(ApiClient(
        secureStore: SecureStore(),
        connectivity: ConnectivityService(),
        responseCache: cache,
        dio: Dio(),
      ))),
    ]);
    addTearDown(container.dispose);
    container.read(accountDeletedProvider.notifier).state = justDeleted;

    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: Consumer(
        builder: (context, ref, _) => MaterialApp.router(
          theme: AppTheme.light,
          routerConfig: ref.watch(routerProvider),
        ),
      ),
    ));
    await frames(tester);
    return container;
  }

  /// Starts the app on [route], as a reload or a restored browser tab does.
  void openOn(WidgetTester tester, String route) {
    tester.binding.platformDispatcher.defaultRouteNameTestValue = route;
    addTearDown(tester.binding.platformDispatcher.clearDefaultRouteNameTestValue);
  }

  String location(ProviderContainer container) =>
      container.read(routerProvider).routerDelegate.currentConfiguration.uri.path;

  testWidgets('a signed-out device that just deleted its account opens on the goodbye', (tester) async {
    final container = await pump(tester, justDeleted: true);

    expect(location(container), Routes.accountDeleted);
    expect(find.byType(AccountDeletedScreen), findsOneWidget);
  });

  testWidgets('wherever the rebuilt router starts, the goodbye wins until it is left', (tester) async {
    final container = await pump(tester, justDeleted: true);

    // The web case: the router comes back on the address the app was opened
    // on, which is the sign-in form.
    container.read(routerProvider).go(Routes.login);
    await frames(tester);
    expect(location(container), Routes.accountDeleted);

    // Leaving clears the flag, and sign-in is reachable again.
    await tester.tap(find.text('Back to sign in'));
    await frames(tester);
    expect(container.read(accountDeletedProvider), isFalse);
    expect(location(container), Routes.login);
  });

  testWidgets('without a deletion, a signed-out user goes to sign-in, never the goodbye', (tester) async {
    final container = await pump(tester, justDeleted: false);

    expect(location(container), Routes.login);

    container.read(routerProvider).go(Routes.accountDeleted);
    await frames(tester);
    expect(location(container), Routes.login);
    expect(find.byType(AccountDeletedScreen), findsNothing);

    container.read(routerProvider).go(Routes.home);
    await frames(tester);
    expect(location(container), Routes.login);
  });

  testWidgets('a reload on the goodbye address lands on sign-in', (tester) async {
    openOn(tester, Routes.accountDeleted);
    final container = await pump(tester, justDeleted: false);

    expect(location(container), Routes.login);
    expect(find.byType(AccountDeletedScreen), findsNothing);
  });

  testWidgets('the goodbye address still shows the goodbye right after a deletion', (tester) async {
    openOn(tester, Routes.accountDeleted);
    final container = await pump(tester, justDeleted: true);

    expect(location(container), Routes.accountDeleted);
    expect(find.byType(AccountDeletedScreen), findsOneWidget);
  });

  testWidgets('before onboarding is done, the goodbye address leads to onboarding', (tester) async {
    final container = await pump(tester, justDeleted: false, auth: _Onboarding.new);

    container.read(routerProvider).go(Routes.accountDeleted);
    await frames(tester);
    expect(location(container), Routes.onboarding);
    expect(find.byType(AccountDeletedScreen), findsNothing);
  });

  testWidgets('Back on the goodbye leaves the app instead of being swallowed', (tester) async {
    final platformCalls = <String>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      platformCalls.add(call.method);
      return null;
    });
    addTearDown(() => tester.binding.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, null));

    await pump(tester, justDeleted: true);
    expect(find.byType(AccountDeletedScreen), findsOneWidget);

    expect(await tester.binding.handlePopRoute(), isFalse);
    expect(platformCalls, contains('SystemNavigator.pop'));
  });

  testWidgets('while a deletion is on its way, nothing navigates off the delete screen', (tester) async {
    openOn(tester, Routes.deleteAccount);
    final container = await pump(tester, justDeleted: false, auth: _SignedIn.new);

    expect(location(container), Routes.deleteAccount);
    expect(find.byType(DeleteAccountScreen), findsOneWidget);

    container.read(deletionInFlightProvider.notifier).state = true;
    container.read(routerProvider).go(Routes.home);
    await frames(tester);
    expect(location(container), Routes.deleteAccount);
    expect(find.byType(DeleteAccountScreen), findsOneWidget);

    container.read(deletionInFlightProvider.notifier).state = false;
    container.read(routerProvider).go(Routes.privacy);
    await frames(tester);
    expect(location(container), Routes.privacy);
  });
}
