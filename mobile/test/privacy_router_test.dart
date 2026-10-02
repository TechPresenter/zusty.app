// The goodbye after an account deletion, through the app's REAL router.
//
// routerProvider builds a new GoRouter on every sign-in change, and on the
// web a rebuilt router starts from the address the app was first opened on,
// not from initialLocation. So the goodbye cannot rest on initialLocation
// alone: the redirect itself must send a signed-out user to /account-deleted
// while accountDeletedProvider is set, and stop once they leave.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/features/privacy/account_deleted_screen.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/privacy_provider.dart';
import 'package:zuste/router/app_router.dart';
import 'package:zuste/router/routes.dart';

/// Signed out from the start, as the device is right after a deletion.
class _SignedOut extends AuthController {
  @override
  AuthState build() => const AuthState(stage: AuthStage.unauthenticated);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({'zuste.onboarding_complete': true});
    cache = await KeyValueCache.open();
  });

  Future<ProviderContainer> pump(WidgetTester tester, {required bool justDeleted}) async {
    tester.view.physicalSize = const Size(1170, 2532);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    final container = ProviderContainer(overrides: [
      keyValueCacheProvider.overrideWithValue(cache),
      authProvider.overrideWith(_SignedOut.new),
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
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));
    return container;
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
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));
    expect(location(container), Routes.accountDeleted);

    // Leaving clears the flag, and sign-in is reachable again.
    await tester.tap(find.text('Back to sign in'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));
    expect(container.read(accountDeletedProvider), isFalse);
    expect(location(container), Routes.login);
  });

  testWidgets('without a deletion, a signed-out user goes to sign-in, never the goodbye', (tester) async {
    final container = await pump(tester, justDeleted: false);

    expect(location(container), Routes.login);

    container.read(routerProvider).go(Routes.accountDeleted);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));
    // Allowed signed out (it is harmless), but nothing sends anyone there.
    expect(find.byType(AccountDeletedScreen), findsOneWidget);

    container.read(routerProvider).go(Routes.home);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));
    expect(location(container), Routes.login);
  });
}
