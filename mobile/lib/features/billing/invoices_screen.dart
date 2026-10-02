import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/invoice.dart';
import '../../providers/core_providers.dart';
import '../../providers/wallet_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../booking/widgets/booking_ui.dart';
import 'invoice_share.dart';

/// Invoices & Billing — every GST invoice Zusty has issued to the brand.
///
/// Satisfies FR-014: a GST-compliant invoice exists for every completed
/// transaction, and the brand can retrieve it for accounting. Totals first,
/// because that is what an accountant needs first; then the invoices by
/// month.
class InvoicesScreen extends ConsumerWidget {
  const InvoicesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final invoices = ref.watch(invoicesProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: ZAppBar(
        title: 'Invoices',
        actions: [
          IconButton(
            tooltip: 'Billing details',
            icon: const Icon(Icons.business_rounded, size: 20),
            onPressed: () => context.push(Routes.businessProfile),
          ),
        ],
      ),
      body: ZRefresh(
        onRefresh: () async {
          ref.invalidate(invoicesProvider);
          try {
            await ref.read(invoicesProvider.future);
          } catch (_) {}
        },
        child: AsyncView<List<Invoice>>(
          value: invoices,
          onRetry: () => ref.invalidate(invoicesProvider),
          loading: ListView(
            physics: const NeverScrollableScrollPhysics(),
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.xs,
              margin,
              AppSpacing.xl,
            ),
            children: const [
              ShimmerBlock(height: 150),
              SizedBox(height: AppSpacing.lg),
              ShimmerBlock(height: 92),
              SizedBox(height: AppSpacing.sm),
              ShimmerBlock(height: 92),
              SizedBox(height: AppSpacing.sm),
              ShimmerBlock(height: 92),
            ],
          ),
          builder: (list) {
            if (list.isEmpty) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  SizedBox(height: MediaQuery.sizeOf(context).height * 0.12),
                  const EmptyState(
                    icon: Icons.receipt_long_rounded,
                    title: 'No invoices yet',
                    message:
                        'An invoice is issued automatically each time a booking '
                        'is paid.',
                  ),
                ],
              );
            }

            final totalBilled = list
                .where((i) => i.status != 'cancelled')
                .fold<double>(0, (sum, i) => sum + i.total);
            final totalTax = list
                .where((i) => i.status != 'cancelled')
                .fold<double>(0, (sum, i) => sum + i.taxAmount);

            final groups = _groupByMonth(list);

            return RevealScope(
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: EdgeInsets.fromLTRB(
                  margin,
                  AppSpacing.xs,
                  margin,
                  AppSpacing.xl,
                ),
                children: [
                  Reveal(
                    revealKey: 'summary',
                    child: _SummaryCard(
                      billed: totalBilled,
                      tax: totalTax,
                      count: list.length,
                    ),
                  ),
                  for (final group in groups) ...[
                    PanelHeading(group.$1),
                    for (var i = 0; i < group.$2.length; i++)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Reveal(
                          index: i,
                          revealKey: group.$2[i].id,
                          child: _InvoiceCard(invoice: group.$2[i]),
                        ),
                      ),
                  ],
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  /// Buckets invoices under "Aug 2026"-style headings, keeping the server's
  /// order (newest first).
  static List<(String, List<Invoice>)> _groupByMonth(List<Invoice> invoices) {
    final groups = <(String, List<Invoice>)>[];

    for (final invoice in invoices) {
      final label = invoice.issuedAt == null
          ? 'Undated'
          : Formatters.monthYear(invoice.issuedAt);

      if (groups.isNotEmpty && groups.last.$1 == label) {
        groups.last.$2.add(invoice);
      } else {
        groups.add((label, [invoice]));
      }
    }

    return groups;
  }
}

/// Lifetime totals, which is what an accountant actually needs first.
class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.billed,
    required this.tax,
    required this.count,
  });

  final double billed;
  final double tax;
  final int count;

  @override
  Widget build(BuildContext context) {
    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md + 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              TintedIconTile(
                icon: Icons.account_balance_wallet_outlined,
                color: AppColors.secondary,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Total billed',
                      style: AppTypography.labelMedium.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: AnimatedCounter.text(
                        Formatters.currency(billed),
                        maxLines: 1,
                        style: AppTypography.headlineMedium.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: AppSpacing.xs + 2,
            ),
            decoration: BoxDecoration(
              color: AppColors.surfaceContainerLow,
              borderRadius: AppRadius.mdAll,
            ),
            child: Row(
              children: [
                Expanded(
                  child: LabeledValue(
                    label: 'GST paid',
                    value: Formatters.currency(tax),
                    emphasise: true,
                  ),
                ),
                Container(
                  width: 1,
                  height: 32,
                  margin: const EdgeInsets.symmetric(horizontal: AppSpacing.sm),
                  color: AppColors.outlineVariant,
                ),
                Expanded(
                  child: LabeledValue(
                    label: 'Invoices',
                    value: '$count',
                    emphasise: true,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _InvoiceCard extends StatelessWidget {
  const _InvoiceCard({required this.invoice});

  final Invoice invoice;

  @override
  Widget build(BuildContext context) {
    final payout = invoice.type == 'creator_payout';

    return ZCard(
      onTap: () => context.push(Routes.invoice(invoice.id)),
      child: Row(
        children: [
          TintedIconTile(
            icon: payout ? Icons.payments_outlined : Icons.description_outlined,
            color: payout ? AppColors.success : AppColors.primary,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  invoice.bookingTitle ?? invoice.bookingCode ?? invoice.number,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodyMedium.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  invoice.number,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
                const SizedBox(height: 4),
                // One line that gives way at the end: the date always shows,
                // the GSTIN ellipsizes when the card is narrow.
                Row(
                  children: [
                    Icon(
                      invoice.hasGst
                          ? Icons.verified_rounded
                          : Icons.event_rounded,
                      size: 13,
                      color: invoice.hasGst
                          ? AppColors.success
                          : AppColors.onSurfaceVariant,
                    ),
                    const SizedBox(width: 4),
                    Expanded(
                      child: Text(
                        [
                          invoice.dateLabel,
                          if (invoice.hasGst) 'GST ${invoice.billToGst}',
                        ].join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.labelSmall,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                invoice.totalLabel,
                style: AppTypography.titleSmall.copyWith(
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              StatusChip(
                label: invoice.statusLabel,
                tone: invoice.tone,
                dense: true,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// A single invoice, laid out like the PDF it mirrors.
class InvoiceDetailScreen extends ConsumerWidget {
  const InvoiceDetailScreen({super.key, required this.invoiceId});

  final int invoiceId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final invoice = ref.watch(invoiceProvider(invoiceId));
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Invoice'),
      bottomBar: invoice.maybeWhen(
        data: (data) => ZButton(
          label: 'Download invoice',
          icon: Icons.download_rounded,
          onPressed: () => shareInvoice(
            context,
            number: data.number,
            load: () =>
                ref.read(walletRepositoryProvider).invoiceHtml(invoiceId),
          ),
        ),
        orElse: () => null,
      ),
      body: AsyncView<Invoice>(
        value: invoice,
        onRetry: () => ref.invalidate(invoiceProvider(invoiceId)),
        loading: ListView(
          physics: const NeverScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.xs,
            margin,
            AppSpacing.xl,
          ),
          children: const [
            ShimmerBlock(height: 190),
            SizedBox(height: AppSpacing.lg),
            ShimmerBlock(height: 230),
          ],
        ),
        builder: (data) => ListView(
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.xs,
            margin,
            AppSpacing.xl,
          ),
          children: [
            ZCard(
              padding: const EdgeInsets.all(AppSpacing.md + 4),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              data.type == 'creator_payout'
                                  ? 'Payout statement'
                                  : 'Tax invoice',
                              style: AppTypography.labelMedium.copyWith(
                                color: AppColors.onSurfaceVariant,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(data.number, style: AppTypography.titleMedium),
                            Text(
                              'Issued ${data.dateLabel}',
                              style: AppTypography.bodySmall,
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: AppSpacing.xs),
                      StatusChip(label: data.statusLabel, tone: data.tone),
                    ],
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(
                      vertical: AppSpacing.md,
                    ),
                    child: Divider(height: 1, color: AppColors.outlineVariant),
                  ),
                  Text(
                    'Billed to',
                    style: AppTypography.labelMedium.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    data.billToName,
                    style: AppTypography.bodyMedium.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (data.billToAddress != null)
                    Text(data.billToAddress!, style: AppTypography.bodySmall),
                  if (data.billToEmail != null)
                    Text(data.billToEmail!, style: AppTypography.bodySmall),
                  if (data.hasGst) ...[
                    const SizedBox(height: AppSpacing.xs),
                    StatusChip(
                      label: 'GSTIN ${data.billToGst}',
                      tone: StatusTone.success,
                      icon: Icons.verified_rounded,
                      dense: true,
                    ),
                  ],
                ],
              ),
            ),
            const PanelHeading('Summary'),
            ZCard(
              child: Column(
                children: [
                  for (final line in data.lineItems)
                    ZDetailRow(
                      label: line.quantity > 1
                          ? '${line.label} × ${line.quantity}'
                          : line.label,
                      value: line.amountLabel,
                    ),
                  if (data.lineItems.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.symmetric(
                        vertical: AppSpacing.xs,
                      ),
                      child: Divider(
                        height: 1,
                        color: AppColors.outlineVariant,
                      ),
                    ),
                  ZDetailRow(label: 'Subtotal', value: data.subtotalLabel),
                  if (data.discountAmount > 0)
                    ZDetailRow(
                      label: 'Discount',
                      value: '− ${Formatters.currency(data.discountAmount)}',
                      valueColor: AppColors.success,
                    ),
                  ZDetailRow(
                    label: 'GST (${data.taxRate.toStringAsFixed(0)}%)',
                    value: data.taxLabel,
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  TotalPanel(
                    label: 'Total',
                    value: data.totalLabel,
                    caption: data.isPaid && data.paidAt != null
                        ? 'Paid ${Formatters.date(data.paidAt)}'
                        : null,
                  ),
                ],
              ),
            ),
            const PanelHeading('Related'),
            ZCard(
              onTap: () => context.push(Routes.booking(data.bookingId)),
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
                          data.bookingTitle ?? 'Related booking',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.bodyMedium.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        if (data.bookingCode != null)
                          Text(
                            'Booking ${data.bookingCode}',
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
        ),
      ),
    );
  }
}
