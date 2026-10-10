import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/network/api_client.dart';
import 'package:zuste/core/network/connectivity_service.dart';
import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/storage/secure_store.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/wallet_repository.dart';
import 'package:zuste/features/booking/payment_screen.dart';
import 'package:zuste/features/booking/widgets/checkout_phone_sheet.dart';
import 'package:zuste/models/booking.dart';
import 'package:zuste/models/wallet.dart';
import 'package:zuste/providers/booking_provider.dart';
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/wallet_provider.dart';
import 'package:zuste/router/routes.dart';

/// Paying through Cashfree from an account with no phone number.
///
/// Sign-up is email-only and SMS is off, so a new account has no number and
/// cannot verify one, yet Cashfree needs one on every order. The server
/// answers `phone_required`; the app asks for a number in a sheet and sends
/// it as `customer_phone` with the same request. It is used for that order
/// only — nothing is saved to the account and no code is sent.

const _bookingId = 5;

/// Always online, without asking the platform.
class _Online extends ConnectivityService {
  @override
  Future<bool> isOnline() async => true;
}

/// Refuses a booking payment without `customer_phone` the way the backend
/// does for an account with no phone, and settles it once one is sent.
class _PaymentServer implements HttpClientAdapter {
  final requests = <RequestOptions>[];

  /// What the server says about the number, once one is sent.
  String? refuseNumberWith;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<List<int>>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);

    if (options.path.endsWith('/payments/booking/$_bookingId')) {
      final body = (options.data as Map?) ?? const {};
      final phone = body['customer_phone'];

      // Only Cashfree needs a number; the wallet settles without one.
      if (phone == null && body['gateway'] == 'cashfree') {
        return _reply(422, {
          'success': false,
          'message': 'Enter a mobile number for this payment.',
          'code': 'phone_required',
          'errors': {
            'customer_phone': ['Enter a mobile number for this payment.'],
          },
        });
      }

      if (phone != null && refuseNumberWith != null) {
        final message = refuseNumberWith!;
        refuseNumberWith = null;
        return _reply(422, {
          'success': false,
          'message': message,
          'code': 'phone_invalid',
          'errors': {
            'customer_phone': [message],
          },
        });
      }

      return _reply(200, {
        'success': true,
        'message': 'Payment successful.',
        'data': {
          'gateway': 'cashfree',
          'status': 'captured',
          'requires_client_action': false,
        },
      });
    }

    return _reply(200, {'success': true, 'message': 'OK', 'data': <String, dynamic>{}});
  }

  ResponseBody _reply(int status, Map<String, dynamic> json) =>
      ResponseBody.fromString(
        jsonEncode(json),
        status,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        },
      );

  @override
  void close({bool force = false}) {}

  List<Map> paymentsSent() => [
        for (final r in requests)
          if (r.path.endsWith('/payments/booking/$_bookingId'))
            (r.data as Map?) ?? const {},
      ];
}

BookingDetail _detail() => BookingDetail.fromJson({
      'id': _bookingId,
      'code': 'ZB000005',
      'title': 'Launch reel',
      'status': 'accepted',
      'payment_status': 'unpaid',
      'amount': 1000,
      'tax_amount': 180,
      'total_amount': 1180,
      'viewer_role': 'brand',
      'formatted': {
        'amount': '₹1,000',
        'tax': '₹180',
        'total': '₹1,180',
      },
    });

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;
  late SecureStore store;
  late _PaymentServer server;
  late ApiClient client;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    cache = await KeyValueCache.open();
    store = SecureStore();
    server = _PaymentServer();
    client = ApiClient(
      secureStore: store,
      connectivity: _Online(),
      responseCache: cache,
      dio: Dio()..httpClientAdapter = server,
    );
  });

  Future<void> pumpCheckout(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1170, 4800);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: '/pay',
      routes: [
        GoRoute(
          path: '/pay',
          builder: (_, _) => const PaymentScreen(bookingId: _bookingId),
        ),
        GoRoute(
          path: Routes.checkoutSuccess(_bookingId),
          builder: (_, _) => const Scaffold(body: Text('PAID')),
        ),
      ],
    );
    addTearDown(router.dispose);

    await tester.pumpWidget(ProviderScope(
      overrides: [
        keyValueCacheProvider.overrideWithValue(cache),
        secureStoreProvider.overrideWithValue(store),
        apiClientProvider.overrideWithValue(client),
        bookingDetailProvider.overrideWith((ref, id) async => _detail()),
        paymentMethodsProvider.overrideWith(
          (ref) async => const [
            PaymentMethod(
              key: 'cashfree',
              label: 'UPI, card or net banking',
              gateway: 'cashfree',
            ),
          ],
        ),
      ],
      child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
    ));
    await settle(tester);
  }

  Future<void> tapPay(WidgetTester tester) async {
    await tester.tap(find.text('Pay ₹1,180 securely'));
    await settle(tester);
  }

  Finder phoneField() => find.descendant(
        of: find.byKey(const ValueKey('checkout-phone')),
        matching: find.byType(TextField),
      );

  group('paying from an account with no phone number', () {
    testWidgets('asks for a number, then pays with it', (tester) async {
      await pumpCheckout(tester);
      await tapPay(tester);

      // The first try had no number, and the server said so.
      expect(server.paymentsSent(), hasLength(1));
      expect(server.paymentsSent().single.containsKey('customer_phone'), isFalse);
      expect(find.text('Mobile number for this payment'), findsOneWidget);
      expect(find.textContaining('not added to your account'), findsOneWidget);

      await tester.enterText(phoneField(), '9876543210');
      await tester.tap(find.text('Continue to payment'));
      await settle(tester);

      expect(server.paymentsSent(), hasLength(2));
      expect(server.paymentsSent().last['gateway'], 'cashfree');
      expect(server.paymentsSent().last['customer_phone'], '9876543210');
      expect(find.text('PAID'), findsOneWidget);
    });

    testWidgets('checks the number before sending it', (tester) async {
      await pumpCheckout(tester);
      await tapPay(tester);

      await tester.enterText(phoneField(), '12345');
      await tester.tap(find.text('Continue to payment'));
      await settle(tester);

      expect(find.text('Enter a valid 10-digit mobile number.'), findsOneWidget);
      expect(server.paymentsSent(), hasLength(1));
    });

    testWidgets("shows the server's reason when it refuses the number", (tester) async {
      server.refuseNumberWith = 'Enter a valid 10-digit Indian mobile number.';

      await pumpCheckout(tester);
      await tapPay(tester);
      await tester.enterText(phoneField(), '9876543210');
      await tester.tap(find.text('Continue to payment'));
      await settle(tester);

      // Asked again, with the reason under the field and the number kept.
      expect(find.text('Enter a valid 10-digit Indian mobile number.'), findsOneWidget);
      expect(find.text('9876543210'), findsOneWidget);

      await tester.tap(find.text('Continue to payment'));
      await settle(tester);

      expect(server.paymentsSent(), hasLength(3));
      expect(find.text('PAID'), findsOneWidget);
    });

    testWidgets('closing the sheet pays nothing', (tester) async {
      await pumpCheckout(tester);
      await tapPay(tester);

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);

      expect(find.text('Mobile number for this payment'), findsNothing);
      expect(server.paymentsSent(), hasLength(1));
      expect(find.text('PAID'), findsNothing);
      expect(find.text('Pay ₹1,180 securely'), findsOneWidget);
    });
  });

  group('the payment request', () {
    test('carries no number unless one was typed', () async {
      final repository = WalletRepository(client);

      await repository.payBooking(_bookingId, gateway: 'wallet');
      await repository.payBooking(
        _bookingId,
        gateway: 'cashfree',
        customerPhone: '9876543210',
      );

      final sent = server.paymentsSent();
      expect(sent.first.containsKey('customer_phone'), isFalse);
      expect(sent.last['customer_phone'], '9876543210');
    });
  });

  group('the number sheet', () {
    testWidgets('returns the ten digits typed', (tester) async {
      String? result;

      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light,
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () async => result = await askCheckoutPhone(context),
              child: const Text('open'),
            ),
          ),
        ),
      ));

      await tester.tap(find.text('open'));
      await settle(tester);

      // Digits only, and no more than ten of them.
      await tester.enterText(phoneField(), '+91 98765-432109');
      await tester.pump();
      expect(find.text('9198765432'), findsOneWidget);

      await tester.enterText(phoneField(), '9876543210');
      await tester.tap(find.text('Continue to payment'));
      await settle(tester);

      expect(result, '9876543210');
    });
  });
}

/// A few frames, enough for a request to go out and its answer to land.
Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}
