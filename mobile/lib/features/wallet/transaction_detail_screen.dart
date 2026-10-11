import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/wallet.dart';
import '../../providers/wallet_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../booking/widgets/booking_ui.dart';

/// Transaction Detail — the receipt behind a wallet ledger row.
///
/// The ledger list is deliberately terse, so this screen carries the full
/// audit trail the TRD requires of financial records: reference, direction,
/// category, running balance, and a link back to the booking that caused it.
class TransactionDetailScreen extends ConsumerWidget {
  const TransactionDetailScreen({super.key, required this.transactionId});

  final int transactionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final transactions = ref.watch(transactionsProvider);

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Transaction'),
      body: AsyncView(
        value: transactions,
        onRetry: () => ref.invalidate(transactionsProvider),
        builder: (page) {
          final match = page.items.firstWhereOrNull(
            (t) => t.id == transactionId,
          );

          if (match == null) {
            return const EmptyState(
              icon: Icons.receipt_long_rounded,
              title: 'Transaction not found',
              message:
                  'It may have been removed, or belongs to another '
                  'account.',
            );
          }

          return _Body(transaction: match);
        },
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.transaction});

  final WalletTransaction transaction;

  @override
  Widget build(BuildContext context) {
    final credit = transaction.isCredit;
    final tone = _tone(transaction.status);
    final accent = credit ? AppColors.success : AppColors.onSurface;

    return ListView(
      padding: EdgeInsets.fromLTRB(
        AppBreakpoints.pageMargin(context),
        AppSpacing.xs,
        AppBreakpoints.pageMargin(context),
        AppSpacing.xl,
      ),
      children: [
        // -- Amount headline ------------------------------------------------
        Reveal(
          child: ZCard(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.md,
            ),
            child: Column(
              children: [
                Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    color: credit
                        ? AppColors.successContainer
                        : AppColors.surfaceContainerHigh,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    transaction.iconData,
                    size: 28,
                    color: credit
                        ? AppColors.success
                        : AppColors.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  credit ? 'Money in' : 'Money out',
                  style: AppTypography.labelMedium.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 2),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    transaction.displayAmount,
                    style: AppTypography.displayLarge.copyWith(
                      color: accent,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                Text(
                  transaction.description,
                  textAlign: TextAlign.center,
                  style: AppTypography.bodyMedium.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                StatusChip(
                  label: _statusLabel(transaction.status),
                  tone: tone,
                  icon: switch (tone) {
                    StatusTone.success => Icons.check_circle_rounded,
                    StatusTone.warning => Icons.schedule_rounded,
                    StatusTone.error => Icons.error_outline_rounded,
                    _ => Icons.info_outline_rounded,
                  },
                ),
              ],
            ),
          ),
        ),

        // -- Audit trail ----------------------------------------------------
        const PanelHeading('Details'),
        ZCard(
          child: Column(
            children: [
              ZDetailRow(label: 'Reference', value: transaction.reference),
              ZDetailRow(
                label: 'Type',
                value: credit ? 'Credit' : 'Debit',
                valueColor: credit ? AppColors.success : AppColors.onSurface,
              ),
              ZDetailRow(
                label: 'Category',
                value: _categoryLabel(transaction.category),
              ),
              ZDetailRow(
                label: 'Date',
                value: transaction.createdAt == null
                    ? transaction.dateLabel
                    : Formatters.dateTime(transaction.createdAt),
              ),
              const SizedBox(height: AppSpacing.xs),
              TotalPanel(
                label: 'Balance after',
                value: Formatters.currency(transaction.balanceAfter),
                color: AppColors.secondary,
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        ZButton.tonal(
          label: 'Copy reference',
          icon: Icons.copy_rounded,
          size: ZButtonSize.medium,
          onPressed: () async {
            await Clipboard.setData(ClipboardData(text: transaction.reference));
            if (context.mounted) showZSnack(context, 'Reference copied.');
          },
        ),

        // -- Linked booking -------------------------------------------------
        if (transaction.referenceType == 'booking' &&
            transaction.referenceId != null) ...[
          const PanelHeading('Related'),
          ZCard(
            onTap: () => context.push(Routes.booking(transaction.referenceId!)),
            child: Row(
              children: [
                TintedIconTile(
                  icon: Icons.receipt_long_rounded,
                  color: AppColors.secondary,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Related booking',
                        style: AppTypography.bodyMedium.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      Text(
                        'View the campaign this payment belongs to',
                        style: AppTypography.bodySmall,
                      ),
                    ],
                  ),
                ),
                Icon(
                  Icons.chevron_right_rounded,
                  size: 22,
                  color: AppColors.outline,
                ),
              ],
            ),
          ),
        ],

        const SizedBox(height: AppSpacing.lg),
        ZButton.outlined(
          label: 'Report an issue',
          icon: Icons.flag_outlined,
          size: ZButtonSize.medium,
          onPressed: () => context.push(Routes.support),
        ),
      ],
    );
  }

  static String _categoryLabel(String category) => switch (category) {
    'booking_earning' => 'Campaign earning',
    'escrow_release' => 'Earnings released',
    'escrow_hold' => 'Earnings on hold',
    'withdrawal' => 'Withdrawal',
    'deposit' => 'Wallet top-up',
    'refund' => 'Refund',
    'reversal' => 'Reversal',
    'commission' || 'platform_fee' => 'Platform commission',
    'referral' => 'Referral reward',
    'bonus' => 'Bonus',
    'tax' => 'Tax',
    _ =>
      category.isEmpty
          ? 'Transaction'
          : category[0].toUpperCase() +
                category.substring(1).replaceAll('_', ' '),
  };

  static String _statusLabel(String status) => status.isEmpty
      ? 'Completed'
      : status[0].toUpperCase() + status.substring(1).replaceAll('_', ' ');

  static StatusTone _tone(String status) => switch (status) {
    'completed' || 'success' => StatusTone.success,
    'pending' || 'processing' => StatusTone.warning,
    'failed' || 'reversed' => StatusTone.error,
    _ => StatusTone.neutral,
  };
}
