import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/wallet.dart';
import '../../providers/auth_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/stat_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import '../booking/widgets/booking_ui.dart';
import 'widgets/wallet_sheets.dart';

/// Screen 16 — Wallet.
///
/// The balance and what can be done with it first, then the lifetime
/// figures, payouts in flight, where money comes from and goes to, and the
/// ledger grouped by day with money-in / money-out filters.
class WalletScreen extends ConsumerWidget {
  const WalletScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final wallet = ref.watch(walletProvider);
    final isCreator = ref.watch(currentUserProvider)?.role.isCreator ?? false;
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        title: isCreator ? 'Earnings & wallet' : 'Wallet',
        actions: [
          IconButton(
            icon: const Icon(Icons.account_balance_rounded, size: 21),
            tooltip: 'Payout accounts',
            onPressed: () => BankAccountsSheet.show(context),
          ),
        ],
      ),
      body: ZRefresh(
        onRefresh: () async {
          ref.invalidate(walletProvider);
          ref.invalidate(transactionsProvider);
          ref.invalidate(withdrawalsProvider);
          ref.invalidate(bankAccountsProvider);
          // Holds the spinner until the balance is back; a failure shows in
          // the card itself, so it is not rethrown here.
          try {
            await ref.read(walletProvider.future);
          } catch (_) {}
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.xs,
            margin,
            AppSpacing.xl,
          ),
          children: [
            AsyncView(
              value: wallet,
              onRetry: () => ref.invalidate(walletProvider),
              loading: const ZShimmerCard(height: 248),
              builder: (data) => Column(
                children: [
                  Reveal(
                    child: _BalanceCard(wallet: data, isCreator: isCreator),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Reveal(
                    index: 1,
                    child: _LifetimeStats(wallet: data, isCreator: isCreator),
                  ),
                ],
              ),
            ),
            const _WithdrawalsSection(),
            const _PaymentMethodsSection(),
            const _TransactionsSection(),
          ],
        ),
      ),
    );
  }
}

/// A shimmer block shaped like a card — used while the balance loads.
class ZShimmerCard extends StatelessWidget {
  const ZShimmerCard({super.key, this.height = 120});

  final double height;

  @override
  Widget build(BuildContext context) {
    return ZShimmer(height: height, borderRadius: AppRadius.xxlAll);
  }
}

/// The balance hero: what can be spent or withdrawn now, what is still held,
/// and the three things to do with money — add, withdraw, manage accounts.
///
/// The one place on the money screens that earns a brand gradient: it is the
/// screen's single focal point.
class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.wallet, required this.isCreator});

  final Wallet wallet;
  final bool isCreator;

  @override
  Widget build(BuildContext context) {
    final onHero = AppColors.onPrimary;

    // `pending` is credited money still clearing; when the server has none
    // to report, payouts already requested are the more useful figure.
    final showPayouts = wallet.pending <= 0 && wallet.pendingWithdrawals > 0;

    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        borderRadius: AppRadius.xxlAll,
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.primary, AppColors.primaryDark],
        ),
        boxShadow: AppShadows.raised,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Available balance',
                  style: AppTypography.labelLarge.copyWith(
                    color: onHero.withValues(alpha: 0.88),
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.xs,
                  vertical: 4,
                ),
                decoration: BoxDecoration(
                  color: onHero.withValues(alpha: 0.16),
                  borderRadius: AppRadius.pill,
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      wallet.canWithdraw
                          ? Icons.lock_open_rounded
                          : Icons.lock_rounded,
                      size: 12,
                      color: onHero,
                    ),
                    const SizedBox(width: 4),
                    Text(
                      wallet.canWithdraw ? wallet.currency : 'Frozen',
                      style: AppTypography.labelSmall.copyWith(color: onHero),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: AnimatedCounter.text(
              wallet.balanceLabel,
              maxLines: 1,
              style: AppTypography.displayLarge.copyWith(
                color: onHero,
                fontSize: 36,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          const SizedBox(height: 2),
          Text(
            !wallet.canWithdraw
                ? 'Withdrawals are paused on this wallet. Contact support.'
                : isCreator
                ? 'Ready to withdraw · minimum '
                      '${Formatters.currency(wallet.minWithdrawal)}'
                : 'Use it to pay for bookings in one tap.',
            style: AppTypography.bodySmall.copyWith(
              color: onHero.withValues(alpha: 0.8),
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: AppSpacing.xs + 2,
            ),
            decoration: BoxDecoration(
              color: onHero.withValues(alpha: 0.12),
              borderRadius: AppRadius.lgAll,
            ),
            child: Row(
              children: [
                _MiniStat(
                  label: 'In escrow',
                  value: wallet.escrowLabel,
                  icon: Icons.lock_rounded,
                ),
                Container(
                  width: 1,
                  height: 32,
                  margin: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
                  color: onHero.withValues(alpha: 0.25),
                ),
                _MiniStat(
                  label: showPayouts ? 'Payouts pending' : 'Pending',
                  value: Formatters.currency(
                    showPayouts ? wallet.pendingWithdrawals : wallet.pending,
                  ),
                  icon: Icons.schedule_rounded,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              Expanded(
                child: _WalletAction(
                  label: 'Add money',
                  icon: Icons.add_rounded,
                  onTap: () => TopUpSheet.show(context),
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: _WalletAction(
                  label: 'Withdraw',
                  icon: Icons.arrow_outward_rounded,
                  onTap: wallet.canWithdraw
                      ? () => WithdrawSheet.show(context, wallet: wallet)
                      : null,
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Expanded(
                child: _WalletAction(
                  label: 'Accounts',
                  icon: Icons.account_balance_rounded,
                  onTap: () => BankAccountsSheet.show(context),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final onHero = AppColors.onPrimary;

    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 13, color: onHero.withValues(alpha: 0.8)),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.labelSmall.copyWith(
                    color: onHero.withValues(alpha: 0.8),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 2),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.labelLarge.copyWith(
              color: onHero,
              fontSize: 15,
            ),
          ),
        ],
      ),
    );
  }
}

/// A glassy action on the balance hero: a 44px-tall icon-and-label pill.
class _WalletAction extends StatelessWidget {
  const _WalletAction({required this.label, required this.icon, this.onTap});

  final String label;
  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    final onHero = AppColors.onPrimary;
    final foreground = onHero.withValues(alpha: enabled ? 1 : 0.5);

    return Semantics(
      button: true,
      enabled: enabled,
      label: label,
      excludeSemantics: true,
      child: Pressable(
        enabled: enabled,
        child: Material(
          color: onHero.withValues(alpha: enabled ? 0.18 : 0.08),
          borderRadius: AppRadius.lgAll,
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: Container(
              constraints: const BoxConstraints(minHeight: 56),
              padding: const EdgeInsets.symmetric(
                horizontal: 4,
                vertical: AppSpacing.xs,
              ),
              alignment: Alignment.center,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(icon, size: 20, color: foreground),
                  const SizedBox(height: 2),
                  Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.labelMedium.copyWith(
                      color: foreground,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Lifetime figures: what a creator has earned and taken out, or what a
/// brand has spent and withdrawn.
class _LifetimeStats extends StatelessWidget {
  const _LifetimeStats({required this.wallet, required this.isCreator});

  final Wallet wallet;
  final bool isCreator;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: isCreator
              ? StatCard(
                  label: 'Total earned',
                  value: Formatters.currency(wallet.lifetimeEarnings),
                  icon: Icons.trending_up_rounded,
                  tint: AppColors.success,
                  caption: wallet.monthlyEarnings > 0
                      ? '${Formatters.currency(wallet.monthlyEarnings)} this month'
                      : null,
                )
              : StatCard(
                  label: 'Total spent',
                  value: Formatters.currency(wallet.totalSpent),
                  icon: Icons.shopping_bag_outlined,
                  tint: AppColors.primary,
                ),
        ),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          child: StatCard(
            label: 'Withdrawn',
            value: Formatters.currency(wallet.withdrawn),
            icon: Icons.account_balance_outlined,
            tint: AppColors.secondary,
          ),
        ),
      ],
    );
  }
}

/// Pending payout requests, matching the settlement workflow.
class _WithdrawalsSection extends ConsumerWidget {
  const _WithdrawalsSection();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final withdrawals = ref.watch(withdrawalsProvider);

    return withdrawals.maybeWhen(
      data: (items) {
        final pending = items
            .where((w) => w.status == 'pending' || w.status == 'processing')
            .toList();
        if (pending.isEmpty) return const SizedBox.shrink();

        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const PanelHeading(
              'Withdrawal requests',
              subtitle: 'Reviewed by our team, usually within 48 hours.',
            ),
            for (final withdrawal in pending)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: _WithdrawalCard(withdrawal: withdrawal),
              ),
          ],
        );
      },
      orElse: () => const SizedBox.shrink(),
    );
  }
}

class _WithdrawalCard extends ConsumerWidget {
  const _WithdrawalCard({required this.withdrawal});

  final Withdrawal withdrawal;

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final confirmed = await showZConfirm(
      context,
      title: 'Cancel this withdrawal?',
      message: '${withdrawal.amountLabel} goes back to your available balance.',
      confirmLabel: 'Cancel withdrawal',
      cancelLabel: 'Keep it',
      destructive: true,
    );
    if (!confirmed) return;

    try {
      await ref.read(walletActionsProvider).cancelWithdrawal(withdrawal.id);
      if (context.mounted) showZSnack(context, 'Withdrawal cancelled.');
    } on ApiFailure catch (e) {
      if (context.mounted) showZSnack(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.sm),
      child: Row(
        children: [
          TintedIconTile(
            icon: Icons.account_balance_rounded,
            color: AppColors.warning,
            background: AppColors.warningContainer,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  withdrawal.amountLabel,
                  style: AppTypography.bodyMedium.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                Text(
                  [
                    withdrawal.destination,
                    if (withdrawal.createdAt != null)
                      Formatters.relative(withdrawal.createdAt),
                  ].where((s) => s.isNotEmpty).join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              StatusChip(
                label: withdrawal.statusLabel,
                tone: withdrawal.tone,
                dense: true,
              ),
              if (withdrawal.canCancel)
                TextButton(
                  onPressed: () => _cancel(context, ref),
                  style: TextButton.styleFrom(
                    minimumSize: const Size(
                      AppSpacing.minTouch,
                      AppSpacing.minTouch,
                    ),
                    foregroundColor: AppColors.error,
                  ),
                  child: Text(
                    'Cancel',
                    style: AppTypography.labelMedium.copyWith(
                      color: AppColors.error,
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Where money comes from and goes to: saved payout accounts, and the
/// gateways the platform takes payments through.
class _PaymentMethodsSection extends ConsumerWidget {
  const _PaymentMethodsSection();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final accounts = ref.watch(bankAccountsProvider);
    final methods = ref.watch(paymentMethodsProvider);

    final accountsLine = accounts.maybeWhen(
      data: (items) {
        if (items.isEmpty) return 'Add a bank account or UPI ID';
        final primary = items.firstWhere(
          (a) => a.isPrimary,
          orElse: () => items.first,
        );
        return items.length == 1
            ? primary.displayLabel
            : '${primary.displayLabel} · ${items.length - 1} more';
      },
      orElse: () => 'Bank accounts and UPI IDs',
    );

    final methodsLine = methods.maybeWhen(
      data: (items) {
        final enabled = items.where((m) => m.isEnabled).map((m) => m.label);
        return enabled.isEmpty
            ? 'None available right now'
            : enabled.join(' · ');
      },
      orElse: () => 'Cards, UPI, net banking',
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PanelHeading('Payment methods'),
        ZCard(
          padding: EdgeInsets.zero,
          clip: true,
          child: Column(
            children: [
              ZMenuTile(
                icon: Icons.account_balance_rounded,
                title: 'Payout accounts',
                subtitle: accountsLine,
                onTap: () => BankAccountsSheet.show(context),
              ),
              Divider(
                height: 1,
                indent: AppSpacing.md,
                endIndent: AppSpacing.md,
                color: AppColors.outlineVariant,
              ),
              ZMenuTile(
                icon: Icons.credit_card_rounded,
                title: 'Ways to pay',
                subtitle: methodsLine,
                onTap: () => PaymentMethodsSheet.show(context),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// The ledger, filtered by direction and grouped under day headings.
class _TransactionsSection extends ConsumerWidget {
  const _TransactionsSection();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final filter = ref.watch(transactionFilterProvider);
    final transactions = ref.watch(transactionsProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PanelHeading('Transactions'),
        ZFilterPills(
          padding: EdgeInsets.zero,
          labels: [for (final option in TransactionFilter.values) option.label],
          selected: TransactionFilter.values.indexOf(filter),
          onSelected: (i) =>
              ref.read(transactionFilterProvider.notifier).state =
                  TransactionFilter.values[i],
        ),
        const SizedBox(height: AppSpacing.sm),
        AsyncView(
          value: transactions,
          onRetry: () => ref.invalidate(transactionsProvider),
          loading: const ListSkeleton(
            count: 5,
            height: 64,
            padding: EdgeInsets.zero,
          ),
          builder: (page) {
            if (page.isEmpty) {
              return ZCard(
                child: EmptyState(
                  title: switch (filter) {
                    TransactionFilter.credit => 'No money in yet',
                    TransactionFilter.debit => 'No money out yet',
                    TransactionFilter.all => 'No transactions yet',
                  },
                  message: 'Payouts, top-ups, and refunds will show up here.',
                  icon: Icons.receipt_long_rounded,
                  compact: true,
                ),
              );
            }

            final groups = _groupByDay(page.items);

            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final group in groups) ...[
                  Padding(
                    padding: const EdgeInsets.only(
                      left: 4,
                      top: AppSpacing.xs,
                      bottom: AppSpacing.xs,
                    ),
                    child: Text(
                      group.$1,
                      style: AppTypography.labelMedium.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ),
                  ZCard(
                    padding: EdgeInsets.zero,
                    clip: true,
                    child: Column(
                      children: [
                        for (var i = 0; i < group.$2.length; i++) ...[
                          if (i > 0)
                            Divider(
                              height: 1,
                              indent: 68,
                              color: AppColors.outlineVariant,
                            ),
                          TransactionRow(transaction: group.$2[i]),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xs),
                ],
              ],
            );
          },
        ),
      ],
    );
  }

  /// Buckets rows under "Today", "Yesterday", a weekday, or a date — in the
  /// order the server sent them, which is newest first.
  static List<(String, List<WalletTransaction>)> _groupByDay(
    List<WalletTransaction> items,
  ) {
    final groups = <(String, List<WalletTransaction>)>[];

    for (final item in items) {
      final label = item.createdAt == null
          ? (item.dateLabel.isEmpty ? 'Earlier' : item.dateLabel)
          : Formatters.dayLabel(item.createdAt!);

      if (groups.isNotEmpty && groups.last.$1 == label) {
        groups.last.$2.add(item);
      } else {
        groups.add((label, [item]));
      }
    }

    return groups;
  }
}

/// One ledger row — icon, description, time, signed amount, running balance.
/// Money in reads green with a plus; money out stays neutral with a minus.
class TransactionRow extends StatelessWidget {
  const TransactionRow({super.key, required this.transaction});

  final WalletTransaction transaction;

  @override
  Widget build(BuildContext context) {
    final credit = transaction.isCredit;
    final pending =
        transaction.status == 'pending' || transaction.status == 'processing';

    final meta = [
      if (transaction.createdAt != null)
        Formatters.time(transaction.createdAt)
      else
        transaction.dateLabel,
      if (pending) 'Pending',
    ].where((s) => s.isNotEmpty).join(' · ');

    return Semantics(
      button: true,
      label:
          '${credit ? 'Money in' : 'Money out'}, '
          '${transaction.displayAmount}, ${transaction.description}',
      excludeSemantics: true,
      child: InkWell(
        onTap: () => context.push(Routes.transaction(transaction.id)),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.sm,
          ),
          child: Row(
            children: [
              TintedIconTile(
                icon: transaction.iconData,
                color: credit ? AppColors.success : AppColors.onSurfaceVariant,
                background: credit
                    ? AppColors.successContainer
                    : AppColors.surfaceContainerHigh,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      transaction.description,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodyMedium.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    if (meta.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        meta,
                        style: AppTypography.bodySmall.copyWith(
                          color: pending ? AppColors.warning : null,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: AppSpacing.xs),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    transaction.displayAmount,
                    style: AppTypography.labelLarge.copyWith(
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      color: credit ? AppColors.success : AppColors.onSurface,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Bal. ${Formatters.currency(transaction.balanceAfter)}',
                    style: AppTypography.labelSmall.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
