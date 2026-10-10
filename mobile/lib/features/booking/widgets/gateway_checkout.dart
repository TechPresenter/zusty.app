import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/json.dart';
import '../../../providers/wallet_provider.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';

/// Takes an order the server just created — for a booking or a wallet
/// top-up — through to a confirmed payment, and answers whether it was paid.
///
/// The wallet settles server-side in one call, so an order that needs no
/// client action is already paid. A gateway order is paid on the gateway's
/// hosted checkout page, which keeps card details out of the app entirely and
/// works the same on Android, iOS and web; the app then asks our own backend
/// to confirm the outcome with the gateway. Only that confirmation counts as
/// paid: the payer's word that they finished is never enough.
///
/// If the payer never comes back, the gateway's webhook still settles the
/// order server-side — this path only makes the app catch up sooner.
///
/// A failed confirmation (the gateway says unpaid) is thrown as the server's
/// [ApiFailure] for the caller to show. Returns false, having told the user
/// why, when the payment never started or the payer cancelled.
Future<bool> completeGatewayPayment(
  BuildContext context,
  WidgetRef ref,
  Map<String, dynamic> order,
) async {
  if (!Json.boolean(order['requires_client_action'])) return true;

  final checkoutUrl = Json.str(order['checkout_url']);
  final orderId = Json.str(order['order_id']);
  final gateway = Json.str(order['gateway']);
  final checkout = Uri.tryParse(checkoutUrl);

  // Only a hosted page can be finished from this build. Gateways that need a
  // native SDK (Razorpay, Stripe) return no checkout URL.
  if (checkout == null || !checkout.hasScheme || orderId.isEmpty) {
    if (context.mounted) {
      showZSnack(
        context,
        'This payment method is not available right now.',
        isError: true,
      );
    }
    return false;
  }

  final launched = await launchUrl(
    checkout,
    mode: LaunchMode.externalApplication,
  );

  if (!context.mounted) return false;

  if (!launched) {
    showZSnack(context, 'Could not open the payment page.', isError: true);
    return false;
  }

  final finished =
      await showModalBottomSheet<bool>(
        context: context,
        isDismissible: false,
        enableDrag: false,
        useSafeArea: true,
        sheetAnimationStyle: zSheetMotion(context),
        builder: (_) => const AwaitingPaymentSheet(),
      ) ??
      false;

  if (!finished) return false;

  await ref.read(walletActionsProvider).verifyPayment({
    'gateway': gateway,
    'order_id': orderId,
  });

  return true;
}

/// Shown while the payer is on the gateway's hosted checkout page.
///
/// The app cannot observe another tab or app, so it asks rather than guesses —
/// and either answer is safe: confirming re-checks with the gateway, and
/// cancelling leaves the order unpaid until the webhook says otherwise.
class AwaitingPaymentSheet extends StatelessWidget {
  const AwaitingPaymentSheet({super.key});

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
