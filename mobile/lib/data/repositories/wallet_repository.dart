import '../../core/network/api_endpoints.dart';
import '../../core/network/api_response.dart';
import '../../models/invoice.dart';
import '../../models/wallet.dart';
import 'base_repository.dart';

/// Wallet, transactions, payouts, and gateway payments.
class WalletRepository extends BaseRepository {
  WalletRepository(super.client);

  Future<Wallet> wallet() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.wallet);
          return Wallet.fromJson(
            _flattenWallet(response.maybeMapAt('wallet') ?? response.dataMap),
          );
        },
        'wallet',
        (json) => Wallet.fromJson(_flattenWallet(asMap(json))),
      );

  /// `GET /wallet` nests the money inside `balance` and the thresholds inside
  /// `limits`; [Wallet.fromJson] reads flat keys.
  ///
  /// Without this the model called `Json.decimal` on an object and every
  /// figure on the wallet screen rendered as zero. Flat keys already present
  /// win, so a payload that is flat to begin with is untouched.
  static Map<String, dynamic> _flattenWallet(Map<String, dynamic> json) {
    final nested = json['balance'];
    final limits = json['limits'];
    if (nested is! Map && limits is! Map) return json;

    final flat = Map<String, dynamic>.from(json);

    if (nested is Map) {
      final balance = Map<String, dynamic>.from(nested);
      flat['balance'] = balance['balance'];
      flat['escrow'] ??= balance['locked'];
      flat['currency'] ??= balance['currency'];
      // The server exposes the freeze, not a verdict. A frozen wallet is the
      // one case the app must not offer a withdrawal for.
      flat['can_withdraw'] ??= balance['is_frozen'] != true;
    }

    if (limits is Map) {
      flat['min_withdrawal'] ??= limits['min_withdrawal'];
      flat['hold_days'] ??= limits['hold_days'];
    }

    return flat;
  }

  Future<Paginated<WalletTransaction>> transactions({
    String? category,
    String? direction,
    int page = 1,
  }) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.walletTransactions,
            query: {
              'category': category,
              'direction': direction,
              'page': page,
              'per_page': 20,
            },
          );
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('transactions');
          return Paginated(
            items: rows.map(WalletTransaction.fromJson).toList(),
            pagination: response.pagination,
          );
        },
        'wallet.transactions',
        (json) {
          var rows = asRows(json).map(WalletTransaction.fromJson).toList();
          if (direction != null) {
            rows = rows.where((t) => t.direction == direction).toList();
          }
          if (category != null) {
            rows = rows.where((t) => t.category == category).toList();
          }
          return Paginated(
            items: rows,
            pagination: Pagination.singlePage(rows.length),
          );
        },
      );

  Future<Paginated<Payment>> payments({int page = 1}) => readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.payments,
            query: {'page': page, 'per_page': 20},
          );
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('payments');
          return Paginated(
            items: rows.map(Payment.fromJson).toList(),
            pagination: response.pagination,
          );
        },
        'payments',
        (json) {
          final items = asRows(json).map(Payment.fromJson).toList();
          return Paginated(
            items: items,
            pagination: Pagination.singlePage(items.length),
          );
        },
      );

  /// GST invoices issued to the signed-in brand (FR-014).
  Future<List<Invoice>> invoices() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.invoices);
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('invoices');
          return rows.map(Invoice.fromJson).toList();
        },
        'invoices',
        (json) => asRows(json).map(Invoice.fromJson).toList(),
      );

  Future<Invoice> invoice(int id) => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.invoiceDetail(id));
          return Invoice.fromJson(
            response.maybeMapAt('invoice') ?? response.dataMap,
          );
        },
        'invoices',
        (json) {
          final rows = asRows(json);
          return Invoice.fromJson(
            rows.firstWhere((i) => i['id'] == id, orElse: () => rows.first),
          );
        },
      );

  /// The invoice as the server renders it, fetched with the bearer token the
  /// endpoint requires.
  Future<String> invoiceHtml(int id) async {
    final response = await client.get(
      ApiEndpoints.invoiceDetail(id),
      query: {'format': 'html'},
    );
    return response.data.toString();
  }

  Future<List<PaymentMethod>> paymentMethods() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.paymentMethods);
          final gateways = response.listAt('gateways');

          if (gateways.isNotEmpty) {
            return gateways.map(PaymentMethod.fromGateway).toList();
          }

          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('methods');
          return rows.map(PaymentMethod.fromJson).toList();
        },
        'payment.methods',
        (json) => asRows(json).map(PaymentMethod.fromJson).toList(),
      );

  Future<List<Withdrawal>> withdrawals() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.withdrawals);
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('withdrawals');
          return rows.map(Withdrawal.fromJson).toList();
        },
        'withdrawals',
        (json) => asRows(json).map(Withdrawal.fromJson).toList(),
      );

  Future<List<BankAccount>> bankAccounts() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.bankAccounts);
          var rows = response.dataList;
          if (rows.isEmpty) rows = response.listAt('bank_accounts');
          if (rows.isEmpty) rows = response.listAt('accounts');
          return rows.map(_bankAccount).toList();
        },
        'bank.accounts',
        (json) => asRows(json).map(_bankAccount).toList(),
      );

  /// The endpoint names the columns `account_holder_name` and `ifsc_code`;
  /// [BankAccount.fromJson] reads `account_holder` and `ifsc`. Aliasing here
  /// keeps both spellings working.
  static BankAccount _bankAccount(Map<String, dynamic> row) =>
      BankAccount.fromJson({
        ...row,
        'account_holder': row['account_holder'] ?? row['account_holder_name'],
        'ifsc': row['ifsc'] ?? row['ifsc_code'],
      });

  Future<void> addBankAccount({
    required String method,
    String? holderName,
    String? accountNumber,
    String? ifsc,
    String? bankName,
    String? upiId,
  }) =>
      writeThrough(
        () async {
          // Key names must match WalletController::addBankAccount()'s
          // validator exactly — Validator::validated() drops anything else, so
          // `account_holder`/`ifsc` arrived as empty and the server rejected
          // every bank payout account as incomplete.
          await client.post(ApiEndpoints.bankAccounts, body: {
            'method': method,
            'account_holder_name': holderName,
            'account_number': accountNumber,
            'ifsc_code': ifsc,
            'bank_name': bankName,
            'upi_id': upiId,
          });
        },
        'noop',
        (_) {},
      );

  Future<void> deleteBankAccount(int id) => writeThrough(
        () async {
          await client.delete(ApiEndpoints.bankAccount(id));
        },
        'noop',
        (_) {},
      );

  /// Starts a wallet deposit.
  ///
  /// [method] is what the sheet offers — an instrument (`upi`, `card`,
  /// `netbanking`) or a gateway id. The endpoint names the field `gateway` and
  /// only accepts a gateway id, so an instrument is resolved against the
  /// gateways the backend actually has switched on. Sending `method` (the old
  /// key) made every top-up fail validation before it reached a gateway.
  Future<void> topup({required double amount, required String method}) =>
      writeThrough(
        () async {
          await client.post(
            ApiEndpoints.walletTopup,
            body: {'amount': amount, 'gateway': await _gatewayFor(method)},
          );
        },
        'noop',
        (_) {},
      );

  /// Gateway ids `POST /wallet/topup` accepts. `wallet` is deliberately absent
  /// — a wallet cannot fund itself.
  static const _topupGateways = {'razorpay', 'stripe', 'cashfree'};

  /// Maps a payment instrument onto an enabled gateway.
  ///
  /// Falls back to the value it was given so an unresolvable choice is
  /// answered by the server's own validation message rather than a guess.
  Future<String> _gatewayFor(String method) async {
    if (_topupGateways.contains(method)) return method;

    try {
      final gateways = await paymentMethods();
      final supports = gateways.firstWhere(
        (g) => _topupGateways.contains(g.gateway) && g.key == method,
        orElse: () => gateways.firstWhere(
          (g) => _topupGateways.contains(g.gateway),
          orElse: () => const PaymentMethod(key: '', label: '', gateway: ''),
        ),
      );
      if (supports.gateway.isNotEmpty) return supports.gateway;
    } on Object {
      // Fall through — the top-up itself reports the real problem.
    }

    return method;
  }

  Future<void> withdraw({
    required double amount,
    required int bankAccountId,
  }) =>
      writeThrough(
        () async {
          await client.post(
            ApiEndpoints.walletWithdraw,
            body: {'amount': amount, 'bank_account_id': bankAccountId},
          );
        },
        'noop',
        (_) {},
      );

  Future<void> cancelWithdrawal(int id) => writeThrough(
        () async {
          await client.post(ApiEndpoints.cancelWithdrawal(id));
        },
        'noop',
        (_) {},
      );

  /// Starts a payment for a booking on [gateway].
  ///
  /// The reply either settles immediately (wallet) or carries
  /// `requires_client_action` with a `checkout_url` to hand to the gateway's
  /// hosted page. The outcome is never taken from the client — the caller
  /// confirms through [verifyPayment], and the gateway webhook settles it
  /// independently if the app never comes back.
  Future<Map<String, dynamic>> payBooking(
    int bookingId, {
    required String gateway,
    String? couponCode,
  }) =>
      writeThrough(
        () async {
          final response = await client.post(
            ApiEndpoints.payBooking(bookingId),
            body: {'gateway': gateway, 'coupon_code': couponCode},
          );
          return response.dataMap;
        },
        'noop',
        (_) => {
          'order_id': 'order_demo_$bookingId',
          'status': 'captured',
          'gateway': gateway,
          'requires_client_action': false,
        },
      );

  Future<void> verifyPayment(Map<String, dynamic> payload) => writeThrough(
        () async {
          await client.post(ApiEndpoints.verifyPayment, body: payload);
        },
        'noop',
        (_) {},
      );
}
