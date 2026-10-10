import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_response.dart';
import '../models/invoice.dart';
import '../models/wallet.dart';
import 'core_providers.dart';

final walletProvider = FutureProvider.autoDispose<Wallet>((ref) {
  return ref.watch(walletRepositoryProvider).wallet();
});

/// Ledger filter chips on the Wallet screen.
enum TransactionFilter {
  all,
  credit,
  debit;

  String get label => switch (this) {
        TransactionFilter.all => 'All',
        TransactionFilter.credit => 'Money in',
        TransactionFilter.debit => 'Money out',
      };

  String? get direction => this == TransactionFilter.all ? null : name;
}

final transactionFilterProvider =
    StateProvider.autoDispose<TransactionFilter>((_) => TransactionFilter.all);

final transactionsProvider =
    FutureProvider.autoDispose<Paginated<WalletTransaction>>((ref) {
  final filter = ref.watch(transactionFilterProvider);
  return ref
      .watch(walletRepositoryProvider)
      .transactions(direction: filter.direction);
});

final paymentsProvider =
    FutureProvider.autoDispose<Paginated<Payment>>((ref) {
  return ref.watch(walletRepositoryProvider).payments();
});

final paymentMethodsProvider =
    FutureProvider.autoDispose<List<PaymentMethod>>((ref) {
  return ref.watch(walletRepositoryProvider).paymentMethods();
});

final invoicesProvider = FutureProvider.autoDispose<List<Invoice>>((ref) {
  return ref.watch(walletRepositoryProvider).invoices();
});

final invoiceProvider =
    FutureProvider.autoDispose.family<Invoice, int>((ref, id) {
  return ref.watch(walletRepositoryProvider).invoice(id);
});

final withdrawalsProvider = FutureProvider.autoDispose<List<Withdrawal>>((ref) {
  return ref.watch(walletRepositoryProvider).withdrawals();
});

final bankAccountsProvider =
    FutureProvider.autoDispose<List<BankAccount>>((ref) {
  return ref.watch(walletRepositoryProvider).bankAccounts();
});

/// Wallet mutations that refresh the balance and ledger together.
class WalletActionsController {
  WalletActionsController(this._ref);

  final Ref _ref;

  Future<void> withdraw({
    required double amount,
    required int bankAccountId,
  }) async {
    await _ref
        .read(walletRepositoryProvider)
        .withdraw(amount: amount, bankAccountId: bankAccountId);
    _refresh();
  }

  /// Creates the top-up's gateway order and hands it back for the caller to
  /// complete. The balance only changes once that payment is confirmed
  /// ([verifyPayment] refreshes it then).
  Future<Map<String, dynamic>> topup({
    required double amount,
    String? method,
  }) async {
    final order = await _ref
        .read(walletRepositoryProvider)
        .topup(amount: amount, method: method);
    _refresh();
    return order;
  }

  Future<void> cancelWithdrawal(int id) async {
    await _ref.read(walletRepositoryProvider).cancelWithdrawal(id);
    _refresh();
  }

  Future<void> addBankAccount({
    required String method,
    String? holderName,
    String? accountNumber,
    String? ifsc,
    String? bankName,
    String? upiId,
  }) async {
    await _ref.read(walletRepositoryProvider).addBankAccount(
          method: method,
          holderName: holderName,
          accountNumber: accountNumber,
          ifsc: ifsc,
          bankName: bankName,
          upiId: upiId,
        );
    _ref.invalidate(bankAccountsProvider);
  }

  Future<void> deleteBankAccount(int id) async {
    await _ref.read(walletRepositoryProvider).deleteBankAccount(id);
    _ref.invalidate(bankAccountsProvider);
  }

  Future<Map<String, dynamic>> payBooking(
    int bookingId, {
    required String gateway,
    String? couponCode,
  }) async {
    final result = await _ref.read(walletRepositoryProvider).payBooking(
          bookingId,
          gateway: gateway,
          couponCode: couponCode,
        );
    _refresh();
    return result;
  }

  /// Confirms a hosted-checkout result with the backend.
  ///
  /// The payload only identifies the order — the backend asks the gateway
  /// what actually happened, so a tampered client cannot claim success.
  Future<void> verifyPayment(Map<String, dynamic> payload) async {
    await _ref.read(walletRepositoryProvider).verifyPayment(payload);
    _refresh();
  }

  void _refresh() {
    _ref.invalidate(walletProvider);
    _ref.invalidate(transactionsProvider);
    _ref.invalidate(withdrawalsProvider);
  }
}

final walletActionsProvider = Provider<WalletActionsController>((ref) {
  return WalletActionsController(ref);
});
