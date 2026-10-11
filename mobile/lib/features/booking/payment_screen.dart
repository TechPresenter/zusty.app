import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/booking.dart';
import '../../models/wallet.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/booking_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import 'widgets/booking_ui.dart';
import 'widgets/checkout_phone_sheet.dart';
import 'widgets/gateway_checkout.dart';

/// Screen 15 — Payment Details.
///
/// Order summary, coupon entry, gateway selection, and payment protection,
/// per the payment workflow in `Zuste_Workflow.md` § 6. The total on the Pay
/// button is always the server's figure, re-read after every coupon change.
///
/// A brand pays each booking through the gateway's hosted checkout
/// (Cashfree), which is chosen from the start. The wallet cannot be topped
/// up, so it is offered only as a second option, and only while the balance
/// already in it — a refund, say — covers the whole total.
class PaymentScreen extends ConsumerStatefulWidget {
  const PaymentScreen({super.key, required this.bookingId});

  final int bookingId;

  @override
  ConsumerState<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends ConsumerState<PaymentScreen> {
  final _coupon = TextEditingController();

  /// What the payer picked, if anything. The method actually used is
  /// [_CheckoutOptions.resolve]d against what is on offer at each build, so a
  /// wallet choice falls back to the gateway once a coupon change means the
  /// balance no longer covers the total.
  String? _method;
  bool _paying = false;
  bool _couponBusy = false;

  /// The mobile number typed at checkout for an account that has none.
  String? _checkoutPhone;

  @override
  void dispose() {
    _coupon.dispose();
    super.dispose();
  }

  /// Applies or removes the code server-side.
  ///
  /// The screen keeps no idea of its own about what a coupon is worth: the
  /// booking comes back repriced and every figure — including the amount on
  /// the Pay button — is re-read from it. Previously "Apply" only set a local
  /// string, so the summary said "Applied" while the customer was charged the
  /// full price.
  Future<void> _applyCoupon(String code) async {
    setState(() => _couponBusy = true);

    try {
      await ref
          .read(bookingActionsProvider)
          .applyCoupon(widget.bookingId, code);
      ref.invalidate(bookingCouponsProvider(widget.bookingId));

      if (mounted) {
        _coupon.clear();
        FocusScope.of(context).unfocus();
        showZSnack(context, 'Coupon applied.');
      }
    } on ApiFailure catch (e) {
      // The server's message is the useful one — "this coupon expired on
      // 3 March" rather than a generic failure.
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _couponBusy = false);
    }
  }

  Future<void> _removeCoupon() async {
    setState(() => _couponBusy = true);

    try {
      await ref.read(bookingActionsProvider).removeCoupon(widget.bookingId);
      ref.invalidate(bookingCouponsProvider(widget.bookingId));
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _couponBusy = false);
    }
  }

  Future<void> _showOffers() async {
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      sheetAnimationStyle: zSheetMotion(context),
      builder: (_) => _OffersSheet(bookingId: widget.bookingId),
    );

    if (picked != null) await _applyCoupon(picked);
  }

  Future<void> _pay(String? method) async {
    if (method == null) {
      showZSnack(context, 'Choose a payment method.', isError: true);
      return;
    }

    setState(() => _paying = true);

    try {
      final order = await _startOrder(method);
      if (order == null) return;

      // Wallet settles server-side in one call; a gateway needs the payer to
      // finish on its own checkout page first, confirmed with our backend.
      if (!mounted) return;
      final confirmed = await completeGatewayPayment(context, ref, order);
      if (!confirmed) return;

      _onPaid();
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _paying = false);
    }
  }

  /// Creates the order, asking for a mobile number when the gateway needs
  /// one the account does not have (`phone_required`: every email or Google
  /// sign-up, as no number can be verified while SMS is off). The number is
  /// kept on this screen for a retry, never saved to the account. Null when
  /// the payer closes that sheet.
  Future<Map<String, dynamic>?> _startOrder(String method) async {
    for (var attempt = 0; attempt < 3; attempt++) {
      try {
        // No coupon is passed here: it is already on the booking, and the
        // server re-checks it is still valid before charging.
        return await ref.read(walletActionsProvider).payBooking(
              widget.bookingId,
              gateway: method,
              customerPhone: _checkoutPhone,
            );
      } on ValidationFailure catch (e) {
        final invalid = e.code == 'phone_invalid';
        final required = e.code == 'phone_required';
        // A server that still says "required" after a number was sent
        // does not take one at checkout; asking again would loop.
        if (!invalid && (!required || _checkoutPhone != null)) rethrow;
        if (!mounted) return null;

        final phone = await askCheckoutPhone(
          context,
          initial: _checkoutPhone,
          serverError: invalid
              ? (e.errorFor('customer_phone') ?? e.message)
              : null,
        );
        if (phone == null) return null;
        _checkoutPhone = phone;
      }
    }
    return null;
  }

  void _onPaid() {
    ref.invalidate(bookingDetailProvider(widget.bookingId));
    for (final tab in BookingTab.values) {
      ref.invalidate(bookingsProvider(tab));
    }

    // A dedicated screen rather than a dialog: this is where payment
    // protection and the next steps are explained.
    if (mounted) {
      context.pushReplacement(Routes.checkoutSuccess(widget.bookingId));
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(bookingDetailProvider(widget.bookingId));
    final methods = ref.watch(paymentMethodsProvider);

    // Worked out on every build: a coupon changes the total, and with it
    // whether the wallet still covers the bill.
    final options = _CheckoutOptions.from(
      methods.valueOrNull ?? const [],
      detail.valueOrNull?.totalAmount ?? 0,
    );
    final selected = options.resolve(_method);

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Payment details'),
      bottomBar: detail.maybeWhen(
        data: (data) => Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    Icons.verified_user_rounded,
                    size: 14,
                    color: AppColors.success,
                  ),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(
                      selected == null
                          ? 'Choose a payment method to continue'
                          : 'Held by Zusty until you approve the work',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            ZButton(
              label: 'Pay ${data.amounts.total} securely',
              icon: Icons.lock_rounded,
              isLoading: _paying,
              onPressed: () => _pay(selected),
            ),
          ],
        ),
        orElse: () => null,
      ),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(bookingDetailProvider(widget.bookingId)),
        loading: const _PaymentSkeleton(),
        builder: (data) => _buildBody(data, methods, options, selected),
      ),
    );
  }

  Widget _buildBody(
    BookingDetail detail,
    AsyncValue<List<PaymentMethod>> methods,
    _CheckoutOptions options,
    String? selected,
  ) {
    final margin = AppBreakpoints.pageMargin(context);
    final booking = detail.booking;
    final rate = detail.taxRate;

    return ListView(
      padding: EdgeInsets.fromLTRB(margin, 0, margin, AppSpacing.xl),
      children: [
        // -- Order summary -------------------------------------------------
        const PanelHeading('Order summary'),
        ZCard(
          child: Column(
            children: [
              Row(
                children: [
                  BookingAvatar(
                    counterparty: booking.counterparty,
                    platform: booking.platform,
                    size: 44,
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          booking.title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodyMedium.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          [
                            booking.counterparty.name,
                            if (booking.code.isNotEmpty) '#${booking.code}',
                          ].join(' · '),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodySmall,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: Divider(height: 1, color: AppColors.outlineVariant),
              ),
              ZDetailRow(label: 'Creator fee', value: detail.amounts.amount),
              ZDetailRow(
                label: rate == null || rate <= 0
                    ? 'GST (18%)'
                    : 'GST (${rate.toStringAsFixed(rate % 1 == 0 ? 0 : 1)}%)',
                value: detail.amounts.tax,
              ),
              if (detail.discountAmount > 0)
                ZDetailRow(
                  label: detail.couponCode == null
                      ? 'Discount'
                      : 'Coupon (${detail.couponCode})',
                  value: detail.amounts.discount,
                  valueColor: AppColors.success,
                ),
              const SizedBox(height: AppSpacing.xs),
              TotalPanel(label: 'Total payable', value: detail.amounts.total),
            ],
          ),
        ),

        // -- Coupon ---------------------------------------------------------
        PanelHeading(
          'Have a coupon?',
          trailing: TextButton.icon(
            onPressed: _couponBusy ? null : _showOffers,
            style: TextButton.styleFrom(
              foregroundColor: AppColors.primary,
              minimumSize: const Size(AppSpacing.minTouch, AppSpacing.minTouch),
            ),
            icon: const Icon(Icons.local_offer_outlined, size: 16),
            label: Text(
              'See offers',
              style: AppTypography.labelLarge.copyWith(
                color: AppColors.primary,
              ),
            ),
          ),
        ),
        AnimatedSwitcher(
          duration: Motion.of(context, Motion.medium),
          switchInCurve: Motion.standard,
          switchOutCurve: Motion.exit,
          child: detail.couponCode != null
              ? _AppliedCoupon(
                  key: const ValueKey('applied'),
                  code: detail.couponCode!,
                  saving: detail.amounts.discount.replaceFirst('-', ''),
                  busy: _couponBusy,
                  onRemove: _removeCoupon,
                )
              : Row(
                  key: const ValueKey('entry'),
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: ZTextField(
                        hint: 'Enter code',
                        controller: _coupon,
                        textCapitalization: TextCapitalization.characters,
                        prefixIcon: Icons.confirmation_number_outlined,
                        textInputAction: TextInputAction.done,
                        onSubmitted: (value) {
                          if (value.trim().isNotEmpty) {
                            _applyCoupon(value.trim());
                          }
                        },
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    Padding(
                      padding: const EdgeInsets.only(top: 3),
                      child: ZButton.tonal(
                        label: 'Apply',
                        size: ZButtonSize.medium,
                        expand: false,
                        isLoading: _couponBusy,
                        onPressed: () {
                          final code = _coupon.text.trim();
                          if (code.isEmpty) {
                            showZSnack(
                              context,
                              'Enter a coupon code first.',
                              isError: true,
                            );
                            return;
                          }
                          _applyCoupon(code);
                        },
                      ),
                    ),
                  ],
                ),
        ),

        // -- Payment methods -------------------------------------------------
        const PanelHeading('Payment method'),
        methods.when(
          data: (_) {
            if (options.isEmpty) {
              return ZCard(
                child: const EmptyState(
                  compact: true,
                  icon: Icons.credit_card_off_outlined,
                  title: 'No payment methods available',
                  message:
                      'Payments are switched off for now. Please try '
                      'again shortly, or contact support.',
                ),
              );
            }

            final wallet = options.wallet;

            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final option in options.gateways)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                    child: _MethodTile(
                      method: option,
                      selected: selected == option.key,
                      onTap: () => setState(() => _method = option.key),
                    ),
                  ),
                // Secondary on purpose: the gateway is how bookings are
                // paid. This is for a balance the brand already holds.
                if (wallet != null) ...[
                  Padding(
                    padding: const EdgeInsets.only(
                      left: 4,
                      top: AppSpacing.xs,
                      bottom: AppSpacing.xs,
                    ),
                    child: Text(
                      options.gateways.isEmpty
                          ? 'Pay from your Zusty balance'
                          : 'Or use your Zusty balance',
                      style: AppTypography.labelMedium.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ),
                  _MethodTile(
                    method: wallet,
                    subtitle:
                        '${Formatters.currency(wallet.balance ?? 0)} '
                        'available · covers this booking',
                    selected: selected == wallet.key,
                    onTap: () => setState(() => _method = wallet.key),
                  ),
                ],
              ],
            );
          },
          loading: () => const ListSkeleton(
            count: 3,
            height: 72,
            padding: EdgeInsets.zero,
          ),
          error: (error, _) => ErrorState(
            error: error,
            onRetry: () => ref.invalidate(paymentMethodsProvider),
          ),
        ),

        // -- Trust panel -----------------------------------------------------
        const SizedBox(height: AppSpacing.md),
        ZInfoBanner(
          tone: StatusTone.success,
          icon: Icons.shield_rounded,
          title: 'Payment protection',
          message:
              'When the booking is confirmed, your payment is held by Zusty. '
              'It is released to the creator when you approve the work, or '
              'automatically if you don\'t respond within '
              '${ref.watch(reviewWindowProvider)} of delivery. If there is a '
              'dispute, our team reviews it before any money is released.',
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.lock_outline_rounded,
              size: 13,
              color: AppColors.onSurfaceVariant,
            ),
            const SizedBox(width: 4),
            Flexible(
              child: Text(
                // Neutral on purpose: which gateway takes the payment is an
                // operator setting, and naming one that is switched off (or
                // claiming a certification for it) would be untrue.
                'Payments are processed securely by our payment partner',
                textAlign: TextAlign.center,
                style: AppTypography.labelSmall,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// The ways this booking can be paid, as the checkout offers them.
///
/// Every gateway is a first-class option. The wallet is held apart: it is
/// offered only when its balance covers [total] in full, because there is no
/// top-up to make up a shortfall and a booking is never split across two
/// methods.
class _CheckoutOptions {
  const _CheckoutOptions({this.gateways = const [], this.wallet});

  factory _CheckoutOptions.from(List<PaymentMethod> methods, double total) {
    final enabled = methods.where((m) => m.isEnabled).toList();
    final wallets = enabled.where((m) => m.isWallet && m.covers(total));

    return _CheckoutOptions(
      gateways: enabled.where((m) => !m.isWallet).toList(),
      wallet: wallets.isEmpty ? null : wallets.first,
    );
  }

  final List<PaymentMethod> gateways;
  final PaymentMethod? wallet;

  bool get isEmpty => gateways.isEmpty && wallet == null;

  /// [chosen] while it is still on offer; otherwise the first gateway — the
  /// checkout every brand pays through — and only failing that the wallet.
  String? resolve(String? chosen) {
    final held = wallet;
    final keys = [for (final m in gateways) m.key, if (held != null) held.key];

    if (chosen != null && keys.contains(chosen)) return chosen;

    return keys.isEmpty ? null : keys.first;
  }
}

/// The coupon on the booking, what it saves, and a way to take it off.
class _AppliedCoupon extends StatelessWidget {
  const _AppliedCoupon({
    super.key,
    required this.code,
    required this.saving,
    required this.busy,
    required this.onRemove,
  });

  final String code;
  final String saving;
  final bool busy;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.sm),
      color: AppColors.successContainer,
      shadow: false,
      child: Row(
        children: [
          TintedIconTile(
            icon: Icons.local_offer_rounded,
            color: AppColors.success,
            background: AppColors.surfaceContainerLowest,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  code,
                  style: AppTypography.labelLarge.copyWith(
                    color: AppColors.onSuccessContainer,
                  ),
                ),
                Text(
                  'You save $saving',
                  style: AppTypography.bodySmall.copyWith(
                    color: AppColors.onSuccessContainer,
                  ),
                ),
              ],
            ),
          ),
          ZButton(
            label: 'Remove',
            variant: ZButtonVariant.text,
            size: ZButtonSize.medium,
            expand: false,
            isLoading: busy,
            onPressed: onRemove,
          ),
        ],
      ),
    );
  }
}

class _MethodTile extends StatelessWidget {
  const _MethodTile({
    required this.method,
    required this.selected,
    required this.onTap,
    this.subtitle,
  });

  final PaymentMethod method;
  final bool selected;
  final VoidCallback onTap;

  /// Replaces the method's own description, e.g. the wallet's balance.
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    final description = subtitle ?? method.description;

    return Semantics(
      inMutuallyExclusiveGroup: true,
      selected: selected,
      child: ZCard(
        onTap: onTap,
        padding: const EdgeInsets.all(AppSpacing.sm),
        shadow: selected,
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
              icon: method.iconData,
              color: selected
                  ? AppColors.onPrimary
                  : AppColors.onSurfaceVariant,
              background: selected
                  ? AppColors.primary
                  : AppColors.surfaceContainerHigh,
            ),
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
                  if (description.isNotEmpty)
                    Text(
                      description,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodySmall,
                    ),
                ],
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            AnimatedSwitcher(
              duration: Motion.of(context, Motion.fast),
              child: Icon(
                selected
                    ? Icons.radio_button_checked_rounded
                    : Icons.radio_button_unchecked_rounded,
                key: ValueKey(selected),
                size: 22,
                color: selected ? AppColors.primary : AppColors.outline,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The public offers, each already priced against this booking.
///
/// Ineligible codes are shown greyed with the server's reason rather than
/// hidden: "needs a booking of at least ₹5,000" is something the customer can
/// act on, an absent row is not.
class _OffersSheet extends ConsumerWidget {
  const _OffersSheet({required this.bookingId});

  final int bookingId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final offers = ref.watch(bookingCouponsProvider(bookingId));

    return ConstrainedBox(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.sizeOf(context).height * 0.75,
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.marginMobile,
          0,
          AppSpacing.marginMobile,
          AppSpacing.md,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Available offers', style: AppTypography.headlineSmall),
            const SizedBox(height: 2),
            Text(
              'Each saving is worked out for this booking.',
              style: AppTypography.bodySmall,
            ),
            const SizedBox(height: AppSpacing.md),
            Flexible(
              child: offers.when(
                data: (rows) => rows.isEmpty
                    ? const EmptyState(
                        compact: true,
                        icon: Icons.local_offer_outlined,
                        title: 'No offers right now',
                        message:
                            'Check back later, or enter a code you were given.',
                      )
                    : ListView.separated(
                        shrinkWrap: true,
                        itemCount: rows.length,
                        separatorBuilder: (_, _) =>
                            const SizedBox(height: AppSpacing.xs),
                        itemBuilder: (_, index) =>
                            _OfferTile(offer: rows[index]),
                      ),
                loading: () => const ListSkeleton(
                  count: 3,
                  height: 72,
                  padding: EdgeInsets.zero,
                ),
                error: (error, _) => ErrorState(
                  error: error,
                  onRetry: () =>
                      ref.invalidate(bookingCouponsProvider(bookingId)),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OfferTile extends StatelessWidget {
  const _OfferTile({required this.offer});

  final CouponOffer offer;

  @override
  Widget build(BuildContext context) {
    final usable = offer.eligible && !offer.isApplied;

    return Opacity(
      opacity: offer.eligible ? 1 : 0.6,
      child: ZCard(
        onTap: usable ? () => Navigator.of(context).pop(offer.code) : null,
        padding: const EdgeInsets.all(AppSpacing.sm),
        shadow: false,
        child: Row(
          children: [
            TintedIconTile(
              icon: Icons.confirmation_number_outlined,
              color: offer.eligible
                  ? AppColors.primary
                  : AppColors.onSurfaceVariant,
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: AppSpacing.xs,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(offer.code, style: AppTypography.labelLarge),
                      if (offer.discountLabel.isNotEmpty)
                        Text(
                          offer.discountLabel,
                          style: AppTypography.bodySmall.copyWith(
                            color: AppColors.onSurfaceVariant,
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    offer.isApplied
                        ? 'Applied'
                        : offer.savingLabel ?? offer.reason ?? offer.name,
                    style: AppTypography.bodySmall.copyWith(
                      color: offer.eligible
                          ? AppColors.success
                          : AppColors.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
            if (usable)
              Text(
                'Apply',
                style: AppTypography.labelLarge.copyWith(
                  color: AppColors.primary,
                ),
              )
            else if (offer.isApplied)
              Icon(
                Icons.check_circle_rounded,
                size: 20,
                color: AppColors.success,
              ),
          ],
        ),
      ),
    );
  }
}

/// The page's silhouette while the booking loads.
class _PaymentSkeleton extends StatelessWidget {
  const _PaymentSkeleton();

  @override
  Widget build(BuildContext context) {
    final margin = AppBreakpoints.pageMargin(context);

    return ListView(
      physics: const NeverScrollableScrollPhysics(),
      padding: EdgeInsets.fromLTRB(
        margin,
        AppSpacing.lg,
        margin,
        AppSpacing.xl,
      ),
      children: const [
        ShimmerBlock(height: 240),
        SizedBox(height: AppSpacing.lg),
        ShimmerBlock(height: 52),
        SizedBox(height: AppSpacing.lg),
        ShimmerBlock(height: 72),
        SizedBox(height: AppSpacing.xs),
        ShimmerBlock(height: 72),
      ],
    );
  }
}
