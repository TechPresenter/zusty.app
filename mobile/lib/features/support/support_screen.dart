import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/theme/app_theme.dart';
import '../../models/support.dart';
import '../../models/taxonomy.dart';
import '../../providers/account_provider.dart';
import '../../providers/app_config_provider.dart';
import '../../providers/taxonomy_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_image.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';
import 'new_ticket.dart';
import 'support_providers.dart';

/// Screen 18 — Support & Help.
///
/// Self-service first (search, topics, the most-read answers), then the
/// user's own tickets, then the human channels. The new-ticket action is
/// pinned at the bottom so it is never more than one tap away.
class SupportScreen extends ConsumerWidget {
  const SupportScreen({super.key});

  /// Used only until a config has been fetched, or when the operator has left
  /// the setting blank. Kept deliberately close to the seeded values so a cold
  /// start does not show a plausible-looking but wrong address.
  static const _fallbackEmail = 'support@zustee.com';
  static const _fallbackPhone = '+91 1800 123 456';

  /// Opens the FAQs screen on [launch] — a topic, a question, or search.
  static void _openFaqs(BuildContext context, WidgetRef ref, FaqLaunch launch) {
    ref.read(faqLaunchProvider.notifier).state = launch;
    context.push(Routes.faqs);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tickets = ref.watch(ticketsProvider);
    final faqs = ref.watch(helpCentreFaqsProvider);
    final margin = AppBreakpoints.pageMargin(context);

    // Settings -> General -> Support email / Support phone, delivered in
    // `/app/config` -> `app`. These used to be compiled in and contradicted the
    // configured values, so changing them in the panel had no effect.
    final identity = ref.watch(appIdentityProvider);
    final supportEmail = identity.supportEmail.isNotEmpty
        ? identity.supportEmail
        : _fallbackEmail;
    final supportPhone = identity.supportPhone.isNotEmpty
        ? identity.supportPhone
        : _fallbackPhone;

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'Support & Help'),
      bottomBar: ZButton(
        label: 'Start a new ticket',
        icon: Icons.support_agent_rounded,
        onPressed: () => NewTicketSheet.show(context),
      ),
      body: ZRefresh(
        onRefresh: () async {
          ref.invalidate(ticketsProvider);
          ref.invalidate(helpCentreFaqsProvider);
          try {
            await ref.read(ticketsProvider.future);
          } catch (_) {
            // The list shows its own error state; the spinner just stops.
          }
        },
        child: ListView(
          padding: EdgeInsets.fromLTRB(
            margin,
            AppSpacing.md,
            margin,
            AppSpacing.xl,
          ),
          children: [
            // -- Greeting and search -------------------------------------------
            Text('How can we help?', style: AppTypography.headlineMedium),
            const SizedBox(height: AppSpacing.base),
            Text(
              'Search the help centre, or reach our team directly.',
              style: AppTypography.bodyMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            ZSearchField(
              hint: 'Search help articles...',
              readOnly: true,
              onTap: () =>
                  _openFaqs(context, ref, const FaqLaunch(focusSearch: true)),
            ),

            // -- Topics ---------------------------------------------------------
            const SizedBox(height: AppSpacing.lg),
            const SectionHeader(title: 'Browse by topic'),
            const SizedBox(height: AppSpacing.sm),
            faqs.when(
              data: (items) {
                final topics = FaqTopic.from(items);
                if (topics.isEmpty) {
                  return Text(
                    'Help articles will appear here soon.',
                    style: AppTypography.bodySmall,
                  );
                }
                return _TopicGrid(
                  topics: topics,
                  onTap: (topic) =>
                      _openFaqs(context, ref, FaqLaunch(category: topic.value)),
                );
              },
              loading: () => const _TopicGridSkeleton(),
              error: (_, _) => Text(
                'Topics could not be loaded. Pull down to try again.',
                style: AppTypography.bodySmall,
              ),
            ),

            // -- Popular FAQs ---------------------------------------------------
            ...faqs.maybeWhen(
              data: (items) {
                if (items.isEmpty) return const <Widget>[];

                // The most-upvoted answers first; the admin's order breaks ties
                // (and is all there is when no votes have come in yet).
                final popular = [...items]
                  ..sort((a, b) => b.helpfulYes.compareTo(a.helpfulYes));

                return [
                  const SizedBox(height: AppSpacing.lg),
                  SectionHeader(
                    title: 'Popular questions',
                    actionLabel: 'View all',
                    onAction: () => _openFaqs(context, ref, const FaqLaunch()),
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  ZMenuSection(
                    children: [
                      for (final faq in popular.take(4))
                        ZMenuTile(
                          icon: Icons.help_outline_rounded,
                          title: faq.question,
                          onTap: () =>
                              _openFaqs(context, ref, FaqLaunch(faqId: faq.id)),
                        ),
                    ],
                  ),
                ];
              },
              orElse: () => const <Widget>[],
            ),

            // -- My tickets -------------------------------------------------------
            const SizedBox(height: AppSpacing.lg),
            const SectionHeader(
              title: 'My tickets',
              subtitle:
                  'Replies from our team arrive here and as a '
                  'notification.',
            ),
            const SizedBox(height: AppSpacing.sm),
            tickets.when(
              data: (items) => items.isEmpty
                  ? const _NoTickets()
                  : RevealScope(
                      child: Column(
                        children: [
                          for (var i = 0; i < items.length; i++)
                            Padding(
                              padding: const EdgeInsets.only(
                                bottom: AppSpacing.sm,
                              ),
                              child: Reveal(
                                index: i,
                                revealKey: items[i].id,
                                child: _TicketCard(ticket: items[i]),
                              ),
                            ),
                        ],
                      ),
                    ),
              loading: () => const ListSkeleton(
                count: 2,
                height: 92,
                padding: EdgeInsets.zero,
              ),
              error: (error, _) => ErrorState(
                error: error,
                onRetry: () => ref.invalidate(ticketsProvider),
              ),
            ),

            // -- Contact channels -------------------------------------------------
            const SizedBox(height: AppSpacing.lg),
            const SectionHeader(title: 'Other ways to reach us'),
            const SizedBox(height: AppSpacing.sm),
            ZMenuSection(
              children: [
                _ContactTile(
                  icon: Icons.mail_outline_rounded,
                  label: 'Email support',
                  value: supportEmail,
                  onTap: () =>
                      launchUrl(Uri(scheme: 'mailto', path: supportEmail)),
                ),
                _ContactTile(
                  icon: Icons.phone_outlined,
                  label: 'Call us',
                  value: supportPhone,
                  hint: 'Mon–Sat, 10am–7pm IST',
                  // The operator types the number for humans ("+91 90000
                  // 00000"); `tel:` wants it without the spacing.
                  onTap: () => launchUrl(
                    Uri(scheme: 'tel', path: supportPhone.replaceAll(' ', '')),
                  ),
                ),
                _ContactTile(
                  icon: Icons.description_outlined,
                  label: 'Terms & policies',
                  value: 'Terms of Service, Privacy Policy',
                  onTap: () => context.push(Routes.page('terms')),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Topic tiles in rows of equal height, as many columns as fit.
///
/// Built row by row rather than with a fixed-aspect grid: a tile's height
/// follows its label, so a two-line topic at a large text size grows the row
/// instead of overflowing it.
class _TopicGrid extends StatelessWidget {
  const _TopicGrid({required this.topics, required this.onTap});

  final List<FaqTopic> topics;
  final ValueChanged<FaqTopic> onTap;

  static int columnsFor(double width) => width >= 520
      ? 4
      : width >= 320
      ? 3
      : 2;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = columnsFor(constraints.maxWidth);
        final rows = <Widget>[];

        for (var start = 0; start < topics.length; start += columns) {
          final slice = topics.skip(start).take(columns).toList();

          rows.add(
            Padding(
              padding: EdgeInsets.only(
                bottom: start + columns < topics.length ? AppSpacing.xs : 0,
              ),
              child: IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (var i = 0; i < columns; i++) ...[
                      if (i > 0) const SizedBox(width: AppSpacing.xs),
                      Expanded(
                        child: i < slice.length
                            ? Reveal(
                                index: start + i,
                                child: _TopicTile(
                                  topic: slice[i],
                                  onTap: () => onTap(slice[i]),
                                ),
                              )
                            : const SizedBox.shrink(),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          );
        }

        return Column(children: rows);
      },
    );
  }
}

class _TopicTile extends StatelessWidget {
  const _TopicTile({required this.topic, required this.onTap});

  final FaqTopic topic;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label:
          '${topic.label}, ${topic.count} '
          '${topic.count == 1 ? 'article' : 'articles'}',
      excludeSemantics: true,
      onTap: onTap,
      child: ZCard(
        padding: const EdgeInsets.all(AppSpacing.sm),
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: AppColors.primaryFixed,
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(topic.icon, size: 20, color: AppColors.primary),
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              topic.label,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelLarge.copyWith(fontSize: 13),
            ),
            const SizedBox(height: 2),
            Text(
              '${topic.count} ${topic.count == 1 ? 'article' : 'articles'}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.labelSmall.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _TopicGridSkeleton extends StatelessWidget {
  const _TopicGridSkeleton();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        for (var i = 0; i < 3; i++) ...[
          if (i > 0) const SizedBox(width: AppSpacing.xs),
          const Expanded(
            child: ZShimmer(height: 104, borderRadius: AppRadius.xlAll),
          ),
        ],
      ],
    );
  }
}

class _NoTickets extends StatelessWidget {
  const _NoTickets();

  @override
  Widget build(BuildContext context) {
    return ZCard(
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: AppColors.surfaceContainerLow,
              shape: BoxShape.circle,
            ),
            child: Icon(
              Icons.inbox_rounded,
              size: 22,
              color: AppColors.onSurfaceVariant,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('No tickets yet', style: AppTypography.titleSmall),
                const SizedBox(height: 2),
                Text(
                  'When you raise one, you can follow the conversation here.',
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TicketCard extends ConsumerWidget {
  const _TicketCard({required this.ticket});

  final SupportTicket ticket;

  static IconData _iconFor(String category) => switch (category) {
    'booking_dispute' || 'booking' => Icons.gavel_rounded,
    'payment' || 'payouts' => Icons.payments_rounded,
    'account' => Icons.manage_accounts_rounded,
    'kyc' => Icons.verified_user_rounded,
    _ => Icons.support_agent_rounded,
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // The operator's name for the category when it is one of theirs.
    final category =
        ref.watch(termMapProvider(Tax.ticketCategory))[ticket.category] ??
        ticket.categoryLabel;

    return ZCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      onTap: () => context.push(Routes.ticket(ticket.id)),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.primaryFixed,
              borderRadius: AppRadius.mdAll,
            ),
            child: Icon(
              _iconFor(ticket.category),
              size: 20,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  ticket.subject,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.titleSmall,
                ),
                const SizedBox(height: 2),
                // Reference and category give way before anything else does
                // on a narrow phone.
                Text(
                  [
                    if (ticket.reference.isNotEmpty) ticket.reference,
                    category,
                  ].join('  ·  '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.labelSmall.copyWith(
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                Row(
                  children: [
                    Flexible(
                      child: Align(
                        alignment: Alignment.centerLeft,
                        child: StatusChip(
                          label: ticket.statusLabel,
                          tone: ticket.tone,
                          dense: true,
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    Text(
                      ticket.timeLabel,
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A contact channel inside a grouped card. Laid out like a [ZMenuTile] so the
/// group's dividers line up, with the address itself on its own line.
class _ContactTile extends StatelessWidget {
  const _ContactTile({
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
    this.hint,
  });

  final IconData icon;
  final String label;
  final String value;
  final VoidCallback onTap;

  /// An optional second line, for detail that is not the contact itself —
  /// opening hours, for instance.
  final String? hint;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 56),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.sm,
          ),
          child: Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: AppRadius.mdAll,
                ),
                child: Icon(icon, size: 20, color: AppColors.primary),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      style: AppTypography.titleSmall.copyWith(
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      value,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodySmall.copyWith(
                        color: AppColors.onSurface,
                      ),
                    ),
                    if (hint != null)
                      Text(
                        hint!,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
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
      ),
    );
  }
}
