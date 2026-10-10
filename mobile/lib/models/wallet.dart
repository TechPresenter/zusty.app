import 'package:flutter/material.dart';

import '../core/utils/formatters.dart';
import '../core/utils/json.dart';
import 'booking.dart' show StatusTone;

/// Wallet summary from `GET /wallet`.
class Wallet {
  const Wallet({
    this.balance = 0,
    this.pending = 0,
    this.escrow = 0,
    this.lifetimeEarnings = 0,
    this.withdrawn = 0,
    this.currency = 'INR',
    this.monthlyEarnings = 0,
    this.canWithdraw = false,
    this.minWithdrawal = 500,
    this.totalSpent = 0,
    this.pendingWithdrawals = 0,
    this.holdDays,
  });

  /// Withdrawable right now.
  final double balance;

  /// Credited but not yet cleared.
  final double pending;

  /// Held against confirmed bookings, not yet released.
  final double escrow;

  final double lifetimeEarnings;
  final double withdrawn;
  final String currency;
  final double monthlyEarnings;
  final bool canWithdraw;
  final double minWithdrawal;

  /// What a brand has paid for bookings, all time.
  final double totalSpent;

  /// Payout requests still being reviewed or sent.
  final double pendingWithdrawals;

  /// Days a released payment clears for before it can be withdrawn.
  final int? holdDays;

  factory Wallet.fromJson(Map<String, dynamic> json) => Wallet(
        balance: Json.decimal(json['balance'] ?? json['available_balance']),
        pending: Json.decimal(json['pending'] ?? json['pending_balance']),
        escrow: Json.decimal(json['escrow'] ?? json['escrow_balance']),
        lifetimeEarnings:
            Json.decimal(json['lifetime_earnings'] ?? json['total_earned']),
        withdrawn: Json.decimal(json['withdrawn'] ?? json['total_withdrawn']),
        currency: Json.str(json['currency'], 'INR'),
        monthlyEarnings: Json.decimal(json['monthly_earnings']),
        canWithdraw: Json.boolean(json['can_withdraw'], true),
        minWithdrawal: Json.decimal(json['min_withdrawal'], 500),
        totalSpent: Json.decimal(json['total_spent']),
        pendingWithdrawals: Json.decimal(json['pending_withdrawals']),
        holdDays: Json.intOrNull(json['hold_days']),
      );

  String get balanceLabel => Formatters.currency(balance);
  String get pendingLabel => Formatters.currency(pending);
  String get escrowLabel => Formatters.currency(escrow);
  String get lifetimeLabel => Formatters.currency(lifetimeEarnings);
}

/// A ledger row from `GET /wallet/transactions`.
class WalletTransaction {
  const WalletTransaction({
    required this.id,
    required this.reference,
    required this.direction,
    required this.category,
    this.amount = 0,
    this.amountLabel = '',
    this.balanceAfter = 0,
    this.description = '',
    this.status = 'completed',
    this.referenceType,
    this.referenceId,
    this.dateLabel = '',
    this.icon = 'payments',
    this.createdAt,
  });

  final int id;
  final String reference;

  /// `credit` or `debit`.
  final String direction;

  /// `deposit`, `withdrawal`, `booking_earning`, `refund`, `commission`, …
  final String category;
  final double amount;

  /// Server-formatted and signed, e.g. `+ ₹12,500`.
  final String amountLabel;
  final double balanceAfter;
  final String description;
  final String status;
  final String? referenceType;
  final int? referenceId;
  final String dateLabel;

  /// Material Symbols name chosen by `WalletController::transactionIcon()`.
  final String icon;
  final DateTime? createdAt;

  factory WalletTransaction.fromJson(Map<String, dynamic> json) =>
      WalletTransaction(
        id: Json.integer(json['id']),
        reference: Json.str(json['reference']),
        direction: Json.str(json['direction'], 'credit'),
        category: Json.str(json['category']),
        amount: Json.decimal(json['amount']),
        amountLabel: Json.str(json['amount_label']),
        balanceAfter: Json.decimal(json['balance_after']),
        description: Json.str(json['description']),
        status: Json.str(json['status'], 'completed'),
        referenceType: Json.strOrNull(json['reference_type']),
        referenceId: Json.intOrNull(json['reference_id']),
        dateLabel: Json.str(json['date_label']),
        icon: Json.str(json['icon'], 'payments'),
        createdAt: Formatters.parseDate(json['created_at']),
      );

  bool get isCredit => direction == 'credit';

  /// Signed label, falling back to a locally-formatted amount.
  String get displayAmount => amountLabel.isNotEmpty
      ? amountLabel
      : '${isCredit ? '+' : '-'} ${Formatters.currency(amount)}';

  /// Maps the backend's icon name onto a bundled Material icon.
  IconData get iconData => switch (icon) {
        'refresh' => Icons.refresh_rounded,
        'arrow_downward' => Icons.arrow_downward_rounded,
        'arrow_upward' => Icons.arrow_upward_rounded,
        'account_balance' => Icons.account_balance_rounded,
        'payments' => Icons.payments_rounded,
        'card_giftcard' => Icons.card_giftcard_rounded,
        'percent' => Icons.percent_rounded,
        _ => isCredit
            ? Icons.arrow_downward_rounded
            : Icons.arrow_upward_rounded,
      };
}

/// A gateway payment from `GET /payments`.
class Payment {
  const Payment({
    required this.id,
    required this.reference,
    this.bookingId,
    this.bookingCode,
    this.bookingTitle,
    this.purpose = '',
    this.gateway = '',
    this.method,
    this.amount = 0,
    this.amountLabel = '',
    this.refunded = 0,
    this.currency = 'INR',
    this.status = 'pending',
    this.cardLast4,
    this.paidAt,
    this.createdAt,
  });

  final int id;
  final String reference;
  final int? bookingId;
  final String? bookingCode;
  final String? bookingTitle;
  final String purpose;

  /// `razorpay`, `stripe`, or `wallet`.
  final String gateway;

  /// `upi`, `card`, `netbanking`, `wallet`, …
  final String? method;
  final double amount;
  final String amountLabel;
  final double refunded;
  final String currency;
  final String status;
  final String? cardLast4;
  final DateTime? paidAt;
  final DateTime? createdAt;

  factory Payment.fromJson(Map<String, dynamic> json) => Payment(
        id: Json.integer(json['id']),
        reference: Json.str(json['reference']),
        bookingId: Json.intOrNull(json['booking_id']),
        bookingCode: Json.strOrNull(json['booking_code']),
        bookingTitle: Json.strOrNull(json['booking_title']),
        purpose: Json.str(json['purpose']),
        gateway: Json.str(json['gateway']),
        method: Json.strOrNull(json['method']),
        amount: Json.decimal(json['amount']),
        amountLabel: Json.str(
          json['amount_label'],
          Formatters.currency(Json.decimal(json['amount'])),
        ),
        refunded: Json.decimal(json['refunded']),
        currency: Json.str(json['currency'], 'INR'),
        status: Json.str(json['status'], 'pending'),
        cardLast4: Json.strOrNull(json['card_last4']),
        paidAt: Formatters.parseDate(json['paid_at']),
        createdAt: Formatters.parseDate(json['created_at']),
      );

  StatusTone get tone => switch (status) {
        'paid' || 'captured' || 'success' => StatusTone.success,
        'pending' || 'processing' || 'created' => StatusTone.warning,
        'failed' || 'cancelled' => StatusTone.error,
        'refunded' || 'partially_refunded' => StatusTone.info,
        _ => StatusTone.neutral,
      };

  String get statusLabel =>
      status[0].toUpperCase() + status.substring(1).replaceAll('_', ' ');
}

/// A payout request from `GET /wallet/withdrawals`.
class Withdrawal {
  const Withdrawal({
    required this.id,
    required this.reference,
    this.amount = 0,
    this.amountLabel = '',
    this.fee = 0,
    this.netAmount = 0,
    this.netLabel = '',
    this.method = 'bank',
    this.destination = '',
    this.status = 'pending',
    this.payoutReference,
    this.rejectionReason,
    this.canCancel = false,
    this.createdAt,
    this.processedAt,
  });

  final int id;
  final String reference;
  final double amount;
  final String amountLabel;
  final double fee;
  final double netAmount;
  final String netLabel;

  /// `bank` or `upi`.
  final String method;

  /// Masked destination, e.g. `HDFC Bank •••• 4821`.
  final String destination;
  final String status;
  final String? payoutReference;
  final String? rejectionReason;
  final bool canCancel;
  final DateTime? createdAt;
  final DateTime? processedAt;

  factory Withdrawal.fromJson(Map<String, dynamic> json) => Withdrawal(
        id: Json.integer(json['id']),
        reference: Json.str(json['reference']),
        amount: Json.decimal(json['amount']),
        amountLabel: Json.str(
          json['amount_label'],
          Formatters.currency(Json.decimal(json['amount'])),
        ),
        fee: Json.decimal(json['fee']),
        netAmount: Json.decimal(json['net_amount']),
        netLabel: Json.str(json['net_label']),
        method: Json.str(json['method'], 'bank'),
        destination: Json.str(json['destination']),
        status: Json.str(json['status'], 'pending'),
        payoutReference: Json.strOrNull(json['payout_reference']),
        rejectionReason: Json.strOrNull(json['rejection_reason']),
        canCancel: Json.boolean(json['can_cancel']),
        createdAt: Formatters.parseDate(json['created_at']),
        processedAt: Formatters.parseDate(json['processed_at']),
      );

  StatusTone get tone => switch (status) {
        'paid' || 'completed' => StatusTone.success,
        'pending' || 'processing' || 'approved' => StatusTone.warning,
        'rejected' || 'failed' || 'cancelled' => StatusTone.error,
        _ => StatusTone.neutral,
      };

  String get statusLabel =>
      status[0].toUpperCase() + status.substring(1).replaceAll('_', ' ');
}

/// A saved payout destination (`GET /wallet/bank-accounts`).
class BankAccount {
  const BankAccount({
    required this.id,
    this.accountHolder = '',
    this.bankName = '',
    this.accountLast4 = '',
    this.ifsc = '',
    this.upiId,
    this.method = 'bank',
    this.isPrimary = false,
    this.isVerified = false,
  });

  final int id;
  final String accountHolder;
  final String bankName;
  final String accountLast4;
  final String ifsc;
  final String? upiId;
  final String method;
  final bool isPrimary;
  final bool isVerified;

  factory BankAccount.fromJson(Map<String, dynamic> json) => BankAccount(
        id: Json.integer(json['id']),
        accountHolder: Json.str(json['account_holder'] ?? json['holder_name']),
        bankName: Json.str(json['bank_name']),
        accountLast4: Json.str(json['account_last4']),
        ifsc: Json.str(json['ifsc']),
        upiId: Json.strOrNull(json['upi_id']),
        method: Json.str(json['method'], 'bank'),
        isPrimary: Json.boolean(json['is_primary']),
        isVerified: Json.boolean(json['is_verified']),
      );

  bool get isUpi => method == 'upi';

  String get displayLabel =>
      isUpi ? (upiId ?? 'UPI') : '$bankName •••• $accountLast4';
}

/// A gateway option from `GET /payments/methods`.
class PaymentMethod {
  const PaymentMethod({
    required this.key,
    required this.label,
    this.description = '',
    this.gateway = '',
    this.isEnabled = true,
    this.iconName,
    this.balance,
  });

  /// `upi`, `card`, `netbanking`, `wallet`, …
  final String key;
  final String label;
  final String description;
  final String gateway;
  final bool isEnabled;
  final String? iconName;

  /// The spendable wallet balance, on the wallet option only (`wallet_balance`
  /// on `GET /payments/methods`). Null for every gateway.
  final double? balance;

  factory PaymentMethod.fromJson(Map<String, dynamic> json) => PaymentMethod(
        key: Json.str(json['key'] ?? json['method']),
        label: Json.str(json['label'] ?? json['name']),
        description: Json.str(json['description']),
        gateway: Json.str(json['gateway']),
        isEnabled: Json.boolean(json['is_enabled'], true),
        iconName: Json.strOrNull(json['icon']),
        balance: Json.doubleOrNull(json['balance']),
      );

  /// Builds an option from a row of `GET /payments/methods` → `gateways`.
  ///
  /// The API offers *gateways*, not instruments, because that is what
  /// `POST /payments/booking/{id}` selects on. The instruments a gateway
  /// supports become the subtitle, so the user still sees "UPI, Card, Net
  /// Banking" without the app inventing an option the backend cannot honour.
  ///
  /// [walletBalance] is the response's `wallet_balance`; only the wallet row
  /// keeps it.
  factory PaymentMethod.fromGateway(
    Map<String, dynamic> json, {
    double? walletBalance,
  }) {
    final id = Json.str(json['id']);
    final methods = Json.strings(json['methods']);

    return PaymentMethod(
      key: id,
      label: Json.str(json['label']),
      description: methods.map(_instrumentLabel).join(' · '),
      gateway: id,
      isEnabled: true,
      balance: id == 'wallet' ? walletBalance : null,
    );
  }

  static String _instrumentLabel(String method) => switch (method) {
        'upi' => 'UPI',
        'card' => 'Card',
        'netbanking' => 'Net Banking',
        'wallet' => 'Wallet',
        'emi' => 'EMI',
        'paylater' => 'Pay Later',
        _ => method.isEmpty
            ? method
            : method[0].toUpperCase() + method.substring(1),
      };

  IconData get iconData => switch (key) {
        'upi' => Icons.qr_code_rounded,
        'card' || 'credit_card' || 'debit_card' => Icons.credit_card_rounded,
        'netbanking' => Icons.account_balance_rounded,
        'wallet' => Icons.account_balance_wallet_rounded,
        'emi' => Icons.calendar_month_rounded,
        // Gateway ids, for options built by [PaymentMethod.fromGateway].
        'cashfree' || 'razorpay' => Icons.account_balance_wallet_outlined,
        'stripe' => Icons.credit_card_rounded,
        _ => Icons.payments_rounded,
      };

  /// True when the balance covers the bill without leaving the app.
  bool get isWallet => key == 'wallet';

  /// True when this is the wallet and its balance pays [amount] in full.
  ///
  /// The wallet cannot be topped up to make up a shortfall, and a booking is
  /// never split across two methods, so anything less is no option at all.
  /// Compared in paise, so a balance of exactly the total is enough.
  bool covers(double amount) {
    final held = balance;
    if (!isWallet || held == null || amount <= 0) return false;
    return (held * 100).round() >= (amount * 100).round();
  }
}
