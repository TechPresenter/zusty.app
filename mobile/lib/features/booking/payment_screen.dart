import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/json.dart';
import '../../models/booking.dart';
import '../../models/wallet.dart';
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

/// Screen 15 — Payment Details.
///
/// Order summary, coupon entry, gateway selection, and the escrow guarantee,
/// per the payment workflow in `Zuste_Workflow.md` § 6. The total on the Pay
/// button is always the server's figure, re-read after every coupon change.
class PaymentScreen extends ConsumerStatefulWidget {
  const PaymentScreen({super.key, required this.bookingId});

  final int bookingId;

  @override
  ConsumerState<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends ConsumerState<PaymentScreen> {
  final _coupon = TextEditingController();

  String? _method;
  bool _paying = false;
  bool _couponBusy = false;

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

  Future<void> _pay() async {
    if (_method == null) {
      showZSnack(context, 'Choose a payment method.', isError: true);
      return;
    }

    setState(() => _paying = true);

    try {
      // No coupon is passed here: it is already on the booking, and the
      // server re-checks it is still valid before charging.
      final order = await ref
          .read(walletActionsProvider)
          .payBooking(widget.bookingId, gateway: _method!);

      // Wallet settles server-side in one call; a gateway needs the payer to
      // finish on its own checkout page first.
      if (Json.boolean(order['requires_client_action'])) {
        final confirmed = await _completeAtGateway(order);
        if (!confirmed) return;
      }

      _onPaid();
    } on ApiFailure catch (e) {
      if (mounted) showZSnack(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _paying = false);
    }
  }

  /// Sends the payer to the gateway's hosted checkout and then confirms the
  /// outcome with our own backend.
  ///
  /// The hosted page keeps card details out of the app entirely and works the
  /// same on Android, iOS and web. If the payer never comes back, the
  /// gateway's webhook still settles the booking server-side — this path only
  /// makes the app catch up sooner.
  Future<bool> _completeAtGateway(Map<String, dynamic> order) async {
    final checkoutUrl = Json.str(order['checkout_url']);
    final orderId = Json.str(order['order_id']);
    final gateway = Json.str(order['gateway']);

    if (checkoutUrl.isEmpty || orderId.isEmpty) {
      if (mounted) {
        showZSnack(
          context,
          'This payment method is not available right now.',
          isError: true,
        );
      }
      return false;
    }

    final launched = await launchUrl(
      Uri.parse(checkoutUrl),
      mode: LaunchMode.externalApplication,
    );

    if (!launched) {
      if (mounted) {
        showZSnack(context, 'Could not open the payment page.', isError: true);
      }
      return false;
    }

    if (!mounted) return false;

    final finished =
        await showModalBottomSheet<bool>(
          context: context,
          isDismissible: false,
          enableDrag: false,
          useSafeArea: true,
          sheetAnimationStyle: zSheetMotion(context),
          builder: (_) => const _AwaitingPaymentSheet(),
        ) ??
        false;

    if (!finished) return false;

    await ref.read(walletActionsProvider).verifyPayment({
      'gateway': gateway,
      'order_id': orderId,
    });

    return true;
  }

  void _onPaid() {
    ref.invalidate(bookingDetailProvider(widget.bookingId));
    for (final tab in BookingTab.values) {
      ref.invalidate(bookingsProvider(tab));
    }

    // A dedicated screen rather than a dialog: this is where the escrow
    // guarantee and the next steps are explained.
    if (mounted) {
      context.pushReplacement(Routes.checkoutSuccess(widget.bookingId));
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(bookingDetailProvider(widget.bookingId));
    final methods = ref.watch(paymentMethodsProvider);

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
                      _method == null
                          ? 'Choose a payment method to continue'
                          : 'Held in escrow until you approve the work',
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
              onPressed: _pay,
            ),
          ],
        ),
        orElse: () => null,
      ),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(bookingDetailProvider(widget.bookingId)),
        loading: const _PaymentSkeleton(),
        builder: (data) => _buildBody(data, methods),
      ),
    );
  }

  Widget _buildBody(
    BookingDetail detail,
    AsyncValue<List<PaymentMethod>> methods,
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
          data: (options) {
            final enabled = options
                .where((option) => option.isEnabled)
                .toList();

            if (enabled.isEmpty) {
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

            return Column(
              children: [
                for (final option in enabled)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                    child: _MethodTile(
                      method: option,
                      selected: _method == option.key,
                      onTap: () => setState(() => _method = option.key),
                    ),
                  ),
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
        const ZInfoBanner(
          tone: StatusTone.success,
          icon: Icons.shield_rounded,
          title: 'Escrow protected',
          message:
              'Zusty holds your payment until you approve the delivered '
              'work. If the creator does not deliver, you get a refund.',
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
                'Secured by Razorpay and Stripe · PCI-DSS compliant',
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
  });

  final PaymentMethod method;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
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
                  if (method.description.isNotEmpty)
                    Text(
                      method.description,
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

/// Shown while the payer is on the gateway's hosted checkout page.
///
/// The app cannot observe another tab or app, so it asks rather than guesses —
/// and either answer is safe: confirming re-checks with the gateway, and
/// cancelling leaves the booking unpaid until the webhook says otherwise.
class _AwaitingPaymentSheet extends StatelessWidget {
  const _AwaitingPaymentSheet();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.lg,
        AppSpacing.lg,
        AppSpacing.lg,
        AppSpacing.md,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              shape: BoxShape.circle,
            ),
            child: Icon(
              Icons.open_in_new_rounded,
              color: AppColors.primary,
              size: 28,
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          Text(
            'Finish paying in your browser',
            style: AppTypography.headlineSmall,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'Come back here once the payment is done and we will confirm it '
            'with your bank.',
            style: AppTypography.bodyMedium.copyWith(
              color: AppColors.onSurfaceVariant,
            ),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: "I've completed the payment",
            icon: Icons.check_rounded,
            onPressed: () => Navigator.of(context).pop(true),
          ),
          const SizedBox(height: AppSpacing.xs),
          ZButton(
            label: 'Cancel',
            variant: ZButtonVariant.text,
            onPressed: () => Navigator.of(context).pop(false),
          ),
        ],
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
