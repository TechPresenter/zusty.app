import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/api_failure.dart';
import 'package:zuste/core/network/api_response.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/data/repositories/wallet_repository.dart';
import 'package:zuste/features/booking/payment_screen.dart';
import 'package:zuste/features/wallet/wallet_screen.dart';
import 'package:zuste/features/wallet/widgets/wallet_sheets.dart';
import 'package:zuste/models/app_settings.dart';
import 'package:zuste/models/booking.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/models/wallet.dart';
import 'package:zuste/providers/app_config_provider.dart';
import 'package:zuste/providers/auth_provider.dart';
import 'package:zuste/providers/booking_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/wallet_provider.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

/// Brands pay each booking at checkout (decision U2, migration 056): no
/// "Add money" unless an admin switches wallet top-ups back on, Cashfree as
/// the way to pay, and the wallet only as a second option when the balance
/// already in it covers the whole booking.

/// Plays the server: records each request and answers with [answer].
class _Server implements HttpClientAdapter {
  _Server(this.answer);

  final ResponseBody Function(RequestOptions options) answer;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _ok([Object? data = const {}]) => ResponseBody.fromString(
      jsonEncode({'success': true, 'message': 'OK', 'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

/// Records which gateway the checkout asked for, then stops the payment
/// there, so no hosted page or navigation is involved.
class _RecordingCheckout extends WalletRepository {
  _RecordingCheckout()
      : super(ApiClient(secureStore: SecureStore(), connectivity: ConnectivityService()));

  final gateways = <String>[];

  @override
  Future<Map<String, dynamic>> payBooking(
    int bookingId, {
    required String gateway,
    String? couponCode,
    String? customerPhone,
  }) async {
    gateways.add(gateway);
    throw const ConflictFailure(message: 'Recorded by the test.');
  }
}

const _cashfree = PaymentMethod(
  key: 'cashfree',
  label: 'UPI / Card / Net Banking',
  description: 'UPI · Card · Net Banking',
  gateway: 'cashfree',
);

PaymentMethod _wallet(double balance) => PaymentMethod(
      key: 'wallet',
      label: 'Zusty Wallet',
      description: 'Wallet',
      gateway: 'wallet',
      balance: balance,
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    cache = await KeyValueCache.open();
    BaseRepository.demoMode = true;
    registerTestFixtures();
  });

  tearDown(() {
    BaseRepository.demoMode = false;
    clearTestFixtures();
  });

  User userAs(String role) => User.fromJson({
        ...MockData.currentUser,
        'role': role,
        'account_type': role,
      });

  Future<void> pump(
    WidgetTester tester,
    Widget screen, {
    String role = 'brand',
    List<Override> overrides = const [],
  }) async {
    tester.view.physicalSize = const Size(1170, 7200);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          currentUserProvider.overrideWithValue(userAs(role)),
          ...overrides,
        ],
        child: MaterialApp(theme: AppTheme.light, home: screen),
      ),
    );

    await tester.pump();
    await tester.pump(const Duration(milliseconds: 800));
  }

  // -------------------------------------------------------------------------
  // The switch, as /app/config delivers it.
  // -------------------------------------------------------------------------

  group('wallet options from /app/config', () {
    test('top-ups are off unless the server says otherwise', () {
      expect(WalletOptions.empty.topupEnabled, isFalse);
      expect(RemoteAppConfig.empty.wallet.topupEnabled, isFalse);
      expect(RemoteAppConfig.fromJson(const {}).wallet.topupEnabled, isFalse);
      expect(RemoteAppConfig.fromJson(const {}).wallet.walletPayment, isTrue);
    });

    test('the wallet block is read when present', () {
      final on = RemoteAppConfig.fromJson(const {
        'wallet': {'topup_enabled': true, 'wallet_payment': false},
      });

      expect(on.wallet.topupEnabled, isTrue);
      expect(on.wallet.walletPayment, isFalse);
    });

    test('walletTopupEnabledProvider follows the cached config', () async {
      final container = ProviderContainer(
        overrides: [keyValueCacheProvider.overrideWithValue(cache)],
      );
      addTearDown(container.dispose);

      expect(container.read(walletTopupEnabledProvider), isFalse);

      await cache.writeDocument(remoteConfigCacheKey, {
        'wallet': {'topup_enabled': true, 'wallet_payment': true},
      });
      container.invalidate(remoteAppConfigProvider);

      expect(container.read(walletTopupEnabledProvider), isTrue);
    });
  });

  // -------------------------------------------------------------------------
  // The wallet screen.
  // -------------------------------------------------------------------------

  group('wallet screen', () {
    testWidgets('a brand gets no Add money while top-ups are off',
        (tester) async {
      await pump(tester, const WalletScreen());

      expect(tester.takeException(), isNull);
      expect(find.text('Available balance'), findsOneWidget);
      expect(find.text('Add money'), findsNothing);
      expect(find.text('Withdraw'), findsOneWidget);
      expect(find.text('Accounts'), findsOneWidget);
      expect(find.text('Use it to pay for bookings in one tap.'), findsNothing);
      expect(
        find.text(
          'Money refunded here can pay for a booking it covers, or be withdrawn.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('a brand can still withdraw a balance it holds',
        (tester) async {
      await pump(tester, const WalletScreen());

      await tester.tap(find.text('Withdraw'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Withdraw balance'), findsOneWidget);
      expect(find.text('Request withdrawal'), findsOneWidget);
    });

    testWidgets('Add money comes back when an admin switches top-ups on',
        (tester) async {
      await pump(
        tester,
        const WalletScreen(),
        overrides: [
          remoteAppConfigProvider.overrideWithValue(
            const RemoteAppConfig(wallet: WalletOptions(topupEnabled: true)),
          ),
        ],
      );

      expect(find.text('Add money'), findsOneWidget);
      expect(find.text('Use it to pay for bookings in one tap.'), findsOneWidget);
    });

    testWidgets('a creator keeps earnings and withdrawals, without Add money',
        (tester) async {
      await pump(tester, const WalletScreen(), role: 'creator');

      expect(find.text('Earnings & wallet'), findsOneWidget);
      expect(find.text('Add money'), findsNothing);
      expect(find.textContaining('Ready to withdraw'), findsOneWidget);

      await tester.tap(find.text('Withdraw'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Withdraw earnings'), findsOneWidget);
    });

    testWidgets('an empty ledger does not promise top-ups', (tester) async {
      await pump(
        tester,
        const WalletScreen(),
        overrides: [
          transactionsProvider.overrideWith(
            (ref) async => Paginated<WalletTransaction>(
              items: const [],
              pagination: Pagination.singlePage(0),
            ),
          ),
        ],
      );

      expect(find.text('Refunds and payouts will show up here.'), findsOneWidget);
      expect(find.textContaining('top-up'), findsNothing);
    });

    // A refund below the minimum withdrawal cannot be withdrawn from the app,
    // so the brand is not promised that it can.
    test('a brand balance under the minimum is not promised a withdrawal', () {
      const small = Wallet(balance: 400, minWithdrawal: 1000, canWithdraw: true);
      const enough = Wallet(balance: 1500, minWithdrawal: 1000, canWithdraw: true);
      const empty = Wallet(minWithdrawal: 1000, canWithdraw: true);

      expect(walletBrandHint(small), isNot(contains('or be withdrawn')));
      expect(walletBrandHint(small), contains('ask support to pay out'));
      expect(walletBrandHint(small), contains('1,000'));
      expect(
        walletBrandHint(enough),
        'Money refunded here can pay for a booking it covers, or be withdrawn.',
      );
      expect(walletBrandHint(empty), walletBrandHint(enough));
    });

    testWidgets('the wallet screen shows that to a brand holding a small refund',
        (tester) async {
      await pump(
        tester,
        const WalletScreen(),
        overrides: [
          walletProvider.overrideWith(
            (ref) async => const Wallet(balance: 400, minWithdrawal: 1000, canWithdraw: true),
          ),
        ],
      );

      expect(tester.takeException(), isNull);
      expect(find.textContaining('ask support to pay out a smaller balance'), findsOneWidget);
      expect(find.textContaining('or be withdrawn'), findsNothing);
    });

    test('the withdraw sheet names the minimum, or the full amount when they match', () {
      const whole = Wallet(balance: 1000, minWithdrawal: 1000, canWithdraw: true);
      const creator = Wallet(balance: 5000, minWithdrawal: 1000, canWithdraw: true);

      expect(WithdrawSheet.withdrawSubtitle(whole), contains('withdraw the full amount'));
      expect(WithdrawSheet.withdrawSubtitle(creator), contains('minimum'));
      expect(WithdrawSheet.withdrawSubtitle(creator), isNot(contains('full amount')));
    });

    test('"Withdraw all" fills in the exact balance, paise included', () {
      expect(wholeAmountText(400), '400');
      expect(wholeAmountText(400.5), '400.50');
      expect(wholeAmountText(1234.56), '1234.56');
    });
  });

  // -------------------------------------------------------------------------
  // The checkout.
  // -------------------------------------------------------------------------

  group('checkout', () {
    final detail = BookingDetail.fromJson(MockData.bookingDetail(1));

    Future<_RecordingCheckout> pumpCheckout(
      WidgetTester tester,
      List<PaymentMethod> methods,
    ) async {
      final checkout = _RecordingCheckout();

      await pump(
        tester,
        const PaymentScreen(bookingId: 1),
        overrides: [
          walletRepositoryProvider.overrideWithValue(checkout),
          bookingDetailProvider(1).overrideWith((ref) async => detail),
          paymentMethodsProvider.overrideWith((ref) async => methods),
        ],
      );

      return checkout;
    }

    Future<void> pay(WidgetTester tester) async {
      await tester.tap(find.textContaining(RegExp(r'^Pay .+ securely$')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));
    }

    testWidgets('pays through Cashfree, chosen from the start', (tester) async {
      final checkout = await pumpCheckout(tester, const [_cashfree]);

      expect(tester.takeException(), isNull);
      expect(find.text('UPI / Card / Net Banking'), findsOneWidget);
      expect(find.text('Held by Zusty until you approve the work'), findsOneWidget);

      await pay(tester);

      expect(checkout.gateways, ['cashfree']);
    });

    testWidgets('a wallet balance short of the total is not offered',
        (tester) async {
      final checkout = await pumpCheckout(
        tester,
        [_cashfree, _wallet(detail.totalAmount - 1)],
      );

      expect(find.text('Zusty Wallet'), findsNothing);
      expect(find.text('Or use your Zusty balance'), findsNothing);

      await pay(tester);

      expect(checkout.gateways, ['cashfree']);
    });

    testWidgets('a balance that covers the total is a second option',
        (tester) async {
      final checkout = await pumpCheckout(
        tester,
        [_cashfree, _wallet(detail.totalAmount)],
      );

      expect(find.text('Or use your Zusty balance'), findsOneWidget);
      expect(find.text('Zusty Wallet'), findsOneWidget);
      expect(find.textContaining('covers this booking'), findsOneWidget);

      // Cashfree stays the default even with the wallet on offer.
      await pay(tester);
      expect(checkout.gateways, ['cashfree']);

      await tester.tap(find.text('Zusty Wallet'));
      await tester.pump();
      await pay(tester);

      expect(checkout.gateways, ['cashfree', 'wallet']);
    });
  });

  // -------------------------------------------------------------------------
  // What the checkout is told about the wallet.
  // -------------------------------------------------------------------------

  group('payment methods', () {
    test('the wallet option carries the balance it can spend', () async {
      BaseRepository.demoMode = false;

      final server = _Server(
        (_) => _ok({
          'gateways': [
            {'id': 'cashfree', 'label': 'UPI / Card / Net Banking', 'methods': ['upi', 'card', 'netbanking']},
            {'id': 'wallet', 'label': 'Zusty Wallet', 'methods': ['wallet']},
          ],
          'wallet_balance': 1500.0,
          'currency': 'INR',
        }),
      );

      final methods = await WalletRepository(
        ApiClient(
          secureStore: SecureStore(),
          connectivity: ConnectivityService(),
          responseCache: cache,
          dio: Dio()..httpClientAdapter = server,
        ),
      ).paymentMethods();

      final wallet = methods.singleWhere((m) => m.isWallet);
      final cashfree = methods.singleWhere((m) => m.key == 'cashfree');

      expect(wallet.balance, 1500.0);
      expect(cashfree.balance, isNull);
      expect(wallet.covers(1500), isTrue);
      expect(wallet.covers(1500.01), isFalse);
      expect(cashfree.covers(10), isFalse);
    });
  });
}
