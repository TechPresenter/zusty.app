import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/utils/validators.dart';
import '../../../models/taxonomy.dart';
import '../../../models/wallet.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../providers/wallet_provider.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/z_text_field.dart';
import '../../booking/widgets/booking_ui.dart';
import '../../booking/widgets/gateway_checkout.dart';

/// The wallet's sheets all open through [showZSheet], so each has the same
/// title row, close button, keyboard lift and slide-up motion. The widgets
/// below are the sheet bodies only.

/// A small grey caption above a group of choices inside a sheet.
class _GroupLabel extends StatelessWidget {
  const _GroupLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Text(
        text,
        style: AppTypography.bodyMedium.copyWith(fontWeight: FontWeight.w600),
      ),
    );
  }
}

/// What the top-up sheet says about paying: the method is chosen on the
/// gateway's own page, not here.
const topUpPaymentNote =
    "You'll choose UPI, card or net banking on the secure Cashfree page. "
    'The money is added once the payment is confirmed.';

/// Wallet top-up (`POST /wallet/topup`).
///
/// Only opened while `walletTopupEnabledProvider` is on — off by default,
/// because brands pay each booking at checkout. The server refuses a top-up
/// with 403 `topup_disabled` otherwise, which this sheet shows as an error.
class TopUpSheet extends ConsumerStatefulWidget {
  const TopUpSheet({super.key});

  static Future<void> show(BuildContext context) => showZSheet<void>(
    context,
    title: 'Add money',
    subtitle: 'Top up your wallet to pay for bookings in one tap.',
    builder: (_) => const TopUpSheet(),
  );

  @override
  ConsumerState<TopUpSheet> createState() => _TopUpSheetState();
}

class _TopUpSheetState extends ConsumerState<TopUpSheet> {
  final _amount = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  /// Creates the deposit, sends the payer to the gateway's checkout, and only
  /// reports success once our backend has confirmed the payment with the
  /// gateway. Creating the order used to be reported as "topped up", when no
  /// money had moved at all.
  Future<void> _submit() async {
    final error = Validators.amount(_amount.text, min: 100, field: 'Amount');
    if (error != null) {
      setState(() => _error = error);
      return;
    }

    setState(() => _busy = true);
    try {
      final order = await ref
          .read(walletActionsProvider)
          .topup(amount: double.parse(_amount.text));

      if (!mounted) return;
      final paid = await completeGatewayPayment(context, ref, order);

      if (paid && mounted) {
        Navigator.of(context).pop();
        showZSnack(context, 'Payment confirmed. The money is in your wallet.');
      }
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    // Preset amounts are configured in the admin panel.
    final presets = ref
        .watch(taxonomiesProvider)
        .terms(Tax.topupPreset)
        .where((term) => term.amount != null)
        .toList();

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ZTextField(
          label: 'Amount',
          controller: _amount,
          keyboardType: TextInputType.number,
          prefixIcon: Icons.currency_rupee_rounded,
          autofocus: true,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          errorText: _error,
          onChanged: (_) => setState(() => _error = null),
        ),
        if (presets.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              for (final term in presets)
                ZChip(
                  label: term.label,
                  selected: _amount.text == '${term.amount!.round()}',
                  onTap: () => setState(() {
                    _amount.text = '${term.amount!.round()}';
                    _error = null;
                  }),
                ),
            ],
          ),
        ],
        const SizedBox(height: AppSpacing.lg),
        // No method chips: every top-up goes to Cashfree's hosted checkout,
        // where the payer picks UPI, card or net banking. Chips here chose
        // nothing, so they only promised a choice the app could not honour.
        Text(
          topUpPaymentNote,
          style: AppTypography.bodySmall,
        ),
        const SizedBox(height: AppSpacing.lg),
        ZButton(
          label: 'Add money',
          icon: Icons.add_rounded,
          isLoading: _busy,
          onPressed: _submit,
        ),
      ],
    );
  }
}

/// Withdrawal request (`POST /wallet/withdraw`) — the settlement workflow's
/// entry point.
class WithdrawSheet extends ConsumerStatefulWidget {
  const WithdrawSheet({super.key, required this.wallet});

  final Wallet wallet;

  /// [title] names what is being withdrawn: a creator's earnings, or the
  /// refunded balance a brand holds.
  static Future<void> show(
    BuildContext context, {
    required Wallet wallet,
    String title = 'Withdraw earnings',
  }) =>
      showZSheet<void>(
        context,
        title: title,
        subtitle: withdrawSubtitle(wallet),
        builder: (_) => WithdrawSheet(wallet: wallet),
      );

  /// A brand holding less than the usual minimum may withdraw all of it, so
  /// the server sends that balance as its minimum. "Minimum ₹400" next to
  /// "Available ₹400" reads as a riddle; say what it means instead.
  static String withdrawSubtitle(Wallet wallet) {
    final whole =
        wallet.balance > 0 &&
        (wallet.minWithdrawal * 100).round() == (wallet.balance * 100).round();

    return whole
        ? 'Available ${wallet.balanceLabel} · withdraw the full amount'
        : 'Available ${wallet.balanceLabel} · minimum '
              '${Formatters.currency(wallet.minWithdrawal)}';
  }

  @override
  ConsumerState<WithdrawSheet> createState() => _WithdrawSheetState();
}

/// The whole balance as the amount field shows it: rupees, plus paise only
/// when there are any. Rounding to rupees asked for more than the wallet
/// holds (₹400.50 became 401) or less than a brand's whole-balance minimum.
@visibleForTesting
String wholeAmountText(double balance) {
  final paise = (balance * 100).round();
  return paise % 100 == 0
      ? (paise ~/ 100).toString()
      : (paise / 100).toStringAsFixed(2);
}

class _WithdrawSheetState extends ConsumerState<WithdrawSheet> {
  final _amount = TextEditingController();
  int? _accountId;
  bool _busy = false;
  String? _amountError;
  bool _accountMissing = false;

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final error = Validators.amount(
      _amount.text,
      min: widget.wallet.minWithdrawal,
      max: widget.wallet.balance,
      field: 'Amount',
    );

    setState(() {
      _amountError = error;
      _accountMissing = _accountId == null;
    });

    if (error != null) return;

    if (_accountId == null) {
      showZSnack(context, 'Choose a payout account.', isError: true);
      return;
    }

    setState(() => _busy = true);
    try {
      await ref
          .read(walletActionsProvider)
          .withdraw(
            amount: double.parse(_amount.text),
            bankAccountId: _accountId!,
          );
      if (mounted) {
        Navigator.of(context).pop();
        showZSnack(
          context,
          'Withdrawal requested. Our team reviews payouts within 48 hours.',
        );
      }
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final accounts = ref.watch(bankAccountsProvider);

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ZTextField(
          label: 'Amount',
          controller: _amount,
          // Paise allowed: a refunded balance is rarely whole rupees, and a
          // brand may have to withdraw exactly all of it.
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          prefixIcon: Icons.currency_rupee_rounded,
          autofocus: true,
          inputFormatters: [
            FilteringTextInputFormatter.allow(RegExp(r'^\d*\.?\d{0,2}')),
          ],
          errorText: _amountError,
          onChanged: (_) => setState(() => _amountError = null),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton(
            onPressed: () => setState(() {
              _amount.text = wholeAmountText(widget.wallet.balance);
              _amountError = null;
            }),
            style: TextButton.styleFrom(
              minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
            ),
            child: Text(
              'Withdraw all',
              style: AppTypography.labelLarge.copyWith(
                color: AppColors.primary,
              ),
            ),
          ),
        ),
        const _GroupLabel('Payout to'),
        accounts.when(
          data: (items) => items.isEmpty
              ? ZCard(
                  shadow: false,
                  child: EmptyState(
                    title: 'No payout account',
                    message: 'Add a bank account or UPI ID to withdraw.',
                    icon: Icons.account_balance_rounded,
                    compact: true,
                    actionLabel: 'Add account',
                    onAction: () => AddBankAccountSheet.show(context),
                  ),
                )
              : Column(
                  children: [
                    for (final account in items)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                        child: _AccountTile(
                          account: account,
                          selected: _accountId == account.id,
                          onTap: () => setState(() {
                            _accountId = account.id;
                            _accountMissing = false;
                          }),
                        ),
                      ),
                  ],
                ),
          loading: () => const ListSkeleton(
            count: 2,
            height: 64,
            padding: EdgeInsets.zero,
          ),
          error: (error, _) => ErrorState(
            error: error,
            onRetry: () => ref.invalidate(bankAccountsProvider),
          ),
        ),
        if (_accountMissing && _accountId == null)
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(
              'Choose where the money should go.',
              style: AppTypography.bodySmall.copyWith(color: AppColors.error),
            ),
          ),
        const SizedBox(height: AppSpacing.md),
        Text(
          'Payouts are reviewed by our team and usually reach your account '
          'within 48 hours.',
          style: AppTypography.bodySmall,
        ),
        const SizedBox(height: AppSpacing.md),
        ZButton(
          label: 'Request withdrawal',
          icon: Icons.arrow_outward_rounded,
          isLoading: _busy,
          onPressed: _submit,
        ),
      ],
    );
  }
}

class _AccountTile extends StatelessWidget {
  const _AccountTile({
    required this.account,
    required this.selected,
    required this.onTap,
  });

  final BankAccount account;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      selected: selected,
      inMutuallyExclusiveGroup: true,
      child: ZCard(
        onTap: onTap,
        shadow: false,
        padding: const EdgeInsets.all(AppSpacing.sm),
        color: selected
            ? AppColors.primaryFixed.withValues(alpha: 0.55)
            : AppColors.surfaceContainerLowest,
        border: Border.all(
          color: selected ? AppColors.primary : AppColors.outlineVariant,
          width: selected ? 1.6 : 1,
        ),
        child: Row(
          children: [
            TintedIconTile(
              icon: account.isUpi
                  ? Icons.qr_code_rounded
                  : Icons.account_balance_rounded,
              color: selected ? AppColors.primary : AppColors.onSurfaceVariant,
              background: selected
                  ? AppColors.surfaceContainerLowest
                  : AppColors.surfaceContainerHigh,
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    account.displayLabel,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodyMedium.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(
                    account.accountHolder,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodySmall,
                  ),
                ],
              ),
            ),
            if (account.isVerified) ...[
              const SizedBox(width: AppSpacing.xs),
              const StatusChip(
                label: 'Verified',
                tone: StatusTone.success,
                dense: true,
              ),
            ],
            const SizedBox(width: AppSpacing.xs),
            Icon(
              selected
                  ? Icons.radio_button_checked_rounded
                  : Icons.radio_button_unchecked_rounded,
              size: 22,
              color: selected ? AppColors.primary : AppColors.outline,
            ),
          ],
        ),
      ),
    );
  }
}

/// Manage saved payout destinations.
class BankAccountsSheet extends ConsumerWidget {
  const BankAccountsSheet({super.key});

  static Future<void> show(BuildContext context) => showZSheet<void>(
    context,
    title: 'Payout accounts',
    subtitle: 'Where your withdrawals are sent.',
    builder: (_) => const BankAccountsSheet(),
  );

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    BankAccount account,
  ) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Remove this account?',
      message:
          '${account.displayLabel} will no longer be available for '
          'payouts. You can add it again at any time.',
      confirmLabel: 'Remove account',
      destructive: true,
      icon: Icons.delete_outline_rounded,
    );

    if (!confirmed) return;

    try {
      await ref.read(walletActionsProvider).deleteBankAccount(account.id);
      if (context.mounted) showZSnack(context, 'Payout account removed.');
    } on ApiFailure catch (e) {
      if (context.mounted) showZSnack(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final accounts = ref.watch(bankAccountsProvider);

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        accounts.when(
          data: (items) => items.isEmpty
              ? EmptyState(
                  title: 'No payout accounts',
                  message: 'Add a bank account or UPI ID to receive payouts.',
                  icon: Icons.account_balance_rounded,
                  compact: true,
                )
              : Column(
                  children: [
                    for (final account in items)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                        child: ZCard(
                          shadow: false,
                          padding: const EdgeInsets.only(
                            left: AppSpacing.sm,
                            top: AppSpacing.xs,
                            bottom: AppSpacing.xs,
                          ),
                          child: Row(
                            children: [
                              TintedIconTile(
                                icon: account.isUpi
                                    ? Icons.qr_code_rounded
                                    : Icons.account_balance_rounded,
                                color: AppColors.secondary,
                              ),
                              const SizedBox(width: AppSpacing.sm),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Flexible(
                                          child: Text(
                                            account.displayLabel,
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: AppTypography.bodyMedium
                                                .copyWith(
                                                  fontWeight: FontWeight.w600,
                                                ),
                                          ),
                                        ),
                                        if (account.isPrimary) ...[
                                          const SizedBox(width: AppSpacing.xs),
                                          const StatusChip(
                                            label: 'Primary',
                                            tone: StatusTone.info,
                                            dense: true,
                                          ),
                                        ],
                                      ],
                                    ),
                                    Text(
                                      [
                                        account.accountHolder,
                                        if (account.isVerified) 'Verified',
                                      ].where((s) => s.isNotEmpty).join(' · '),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: AppTypography.bodySmall,
                                    ),
                                  ],
                                ),
                              ),
                              IconButton(
                                tooltip: 'Remove ${account.displayLabel}',
                                icon: const Icon(
                                  Icons.delete_outline_rounded,
                                  size: 20,
                                ),
                                color: AppColors.error,
                                onPressed: () => _delete(context, ref, account),
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
          loading: () => const ListSkeleton(
            count: 2,
            height: 64,
            padding: EdgeInsets.zero,
          ),
          error: (error, _) => ErrorState(
            error: error,
            onRetry: () => ref.invalidate(bankAccountsProvider),
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        ZButton.tonal(
          label: 'Add account',
          icon: Icons.add_rounded,
          onPressed: () => AddBankAccountSheet.show(context),
        ),
      ],
    );
  }
}

/// Add a bank account or UPI ID.
class AddBankAccountSheet extends ConsumerStatefulWidget {
  const AddBankAccountSheet({super.key});

  static Future<void> show(BuildContext context) => showZSheet<void>(
    context,
    title: 'Add payout account',
    subtitle: 'Withdrawals can only go to an account in your own name.',
    builder: (_) => const AddBankAccountSheet(),
  );

  @override
  ConsumerState<AddBankAccountSheet> createState() =>
      _AddBankAccountSheetState();
}

class _AddBankAccountSheetState extends ConsumerState<AddBankAccountSheet> {
  final _formKey = GlobalKey<FormState>();
  final _holder = TextEditingController();
  final _bank = TextEditingController();
  final _account = TextEditingController();
  final _ifsc = TextEditingController();
  final _upi = TextEditingController();

  String _method = 'bank';
  bool _busy = false;
  AutovalidateMode _autovalidate = AutovalidateMode.disabled;

  @override
  void dispose() {
    _holder.dispose();
    _bank.dispose();
    _account.dispose();
    _ifsc.dispose();
    _upi.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) {
      setState(() => _autovalidate = AutovalidateMode.onUserInteraction);
      return;
    }

    setState(() => _busy = true);
    try {
      await ref
          .read(walletActionsProvider)
          .addBankAccount(
            method: _method,
            holderName: _holder.text.trim(),
            bankName: _bank.text.trim(),
            accountNumber: _account.text.trim(),
            ifsc: _ifsc.text.trim().toUpperCase(),
            upiId: _upi.text.trim(),
          );
      if (mounted) {
        Navigator.of(context).pop();
        showZSnack(context, 'Payout account added.');
      }
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isBank = _method == 'bank';

    return Form(
      key: _formKey,
      autovalidateMode: _autovalidate,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              ZChip(
                label: 'Bank account',
                icon: Icons.account_balance_rounded,
                selected: isBank,
                onTap: () => setState(() => _method = 'bank'),
              ),
              ZChip(
                label: 'UPI',
                icon: Icons.qr_code_rounded,
                selected: !isBank,
                onTap: () => setState(() => _method = 'upi'),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Account holder name',
            controller: _holder,
            textCapitalization: TextCapitalization.words,
            textInputAction: TextInputAction.next,
            validator: (value) => Validators.minLength(value, 3, field: 'Name'),
          ),
          if (isBank) ...[
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label: 'Bank name',
              controller: _bank,
              textCapitalization: TextCapitalization.words,
              textInputAction: TextInputAction.next,
              validator: (value) =>
                  Validators.required(value, field: 'Bank name'),
            ),
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label: 'Account number',
              controller: _account,
              keyboardType: TextInputType.number,
              textInputAction: TextInputAction.next,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              validator: Validators.accountNumber,
            ),
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label: 'IFSC code',
              hint: 'HDFC0001234',
              controller: _ifsc,
              textCapitalization: TextCapitalization.characters,
              textInputAction: TextInputAction.done,
              validator: Validators.ifsc,
            ),
          ] else ...[
            const SizedBox(height: AppSpacing.md),
            ZTextField(
              label: 'UPI ID',
              hint: 'yourname@bank',
              controller: _upi,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.done,
              validator: (value) => Validators.required(value, field: 'UPI ID'),
            ),
          ],
          const SizedBox(height: AppSpacing.lg),
          ZButton(label: 'Save account', isLoading: _busy, onPressed: _submit),
        ],
      ),
    );
  }
}

/// The ways a payment can be made, from `GET /payments/methods` — shown
/// read-only so a brand knows before checkout what it can pay with.
class PaymentMethodsSheet extends ConsumerWidget {
  const PaymentMethodsSheet({super.key});

  static Future<void> show(BuildContext context) => showZSheet<void>(
    context,
    title: 'Ways to pay',
    subtitle: 'What you can pay for a booking with at checkout.',
    builder: (_) => const PaymentMethodsSheet(),
  );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final methods = ref.watch(paymentMethodsProvider);

    return methods.when(
      data: (items) {
        final enabled = items.where((m) => m.isEnabled).toList();

        if (enabled.isEmpty) {
          return const EmptyState(
            compact: true,
            icon: Icons.credit_card_off_outlined,
            title: 'No payment methods right now',
            message: 'Payments are switched off for the moment.',
          );
        }

        return Column(
          children: [
            for (final method in enabled)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ZCard(
                  shadow: false,
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Row(
                    children: [
                      TintedIconTile(icon: method.iconData),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              method.label,
                              style: AppTypography.bodyMedium.copyWith(
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            if (method.description.isNotEmpty)
                              Text(
                                method.description,
                                style: AppTypography.bodySmall,
                              ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        );
      },
      loading: () =>
          const ListSkeleton(count: 3, height: 64, padding: EdgeInsets.zero),
      error: (error, _) => ErrorState(
        error: error,
        onRetry: () => ref.invalidate(paymentMethodsProvider),
      ),
    );
  }
}
