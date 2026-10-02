import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_response.dart';
import '../../core/theme/app_theme.dart';
import '../../models/campaign.dart';
import '../../providers/campaign_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/campaign_card.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import 'widgets/brand_campaign_card.dart';

/// Campaigns — the brand's open briefs.
///
/// A campaign is one-to-many: one brief, many creators applying or invited.
/// That is a different shape from a booking (one contract, one creator), so it
/// gets its own list rather than being folded into My Bookings.
///
/// The status filter is a row of pills rather than a segmented control: there
/// are five stages, and five equal segments stop fitting a 360dp phone (or any
/// phone at a larger text size) once each carries its count.
class CampaignsScreen extends ConsumerStatefulWidget {
  const CampaignsScreen({super.key});

  @override
  ConsumerState<CampaignsScreen> createState() => _CampaignsScreenState();
}

class _CampaignsScreenState extends ConsumerState<CampaignsScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(
    length: CampaignTab.values.length,
    vsync: this,
  )..addListener(_onTabChanged);

  int _index = 0;

  /// Keeps the pills in step when the list is swiped rather than tapped.
  void _onTabChanged() {
    if (_tabs.index != _index && mounted) setState(() => _index = _tabs.index);
  }

  @override
  void dispose() {
    _tabs
      ..removeListener(_onTabChanged)
      ..dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final all = ref.watch(campaignsProvider(CampaignTab.all)).valueOrNull;
    final counts = _countsFrom(all);

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Campaigns'),
      bottomBar: ZButton(
        label: 'Create campaign',
        icon: Icons.add_rounded,
        onPressed: () => context.push(Routes.newCampaign),
      ),
      body: Column(
        children: [
          const SizedBox(height: AppSpacing.xs),
          ZFilterPills(
            labels: [
              for (final tab in CampaignTab.values)
                counts == null || counts[tab] == null
                    ? _labelFor(tab)
                    : '${_labelFor(tab)} · ${counts[tab]}',
            ],
            selected: _index,
            padding: EdgeInsets.symmetric(
              horizontal: AppBreakpoints.pageMargin(context),
            ),
            onSelected: (i) {
              setState(() => _index = i);
              _tabs.animateTo(i);
            },
          ),
          const SizedBox(height: AppSpacing.xs),
          Expanded(
            child: TabBarView(
              controller: _tabs,
              children: [
                for (final tab in CampaignTab.values) _CampaignList(tab: tab),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// The brand's own words for each stage. `in_progress` reads as "Running"
  /// in the provider; here it keeps the server's "In progress".
  static String _labelFor(CampaignTab tab) => switch (tab) {
        CampaignTab.all => 'All',
        CampaignTab.draft => 'Drafts',
        CampaignTab.open => 'Open',
        CampaignTab.inProgress => 'In progress',
        CampaignTab.completed => 'Completed',
      };

  /// Per-stage counts, derived from the "All" page the screen loads anyway.
  ///
  /// Only offered when that page holds every campaign: a count taken from a
  /// partial page would be wrong, and a wrong count is worse than none.
  static Map<CampaignTab, int>? _countsFrom(Paginated<Campaign>? all) {
    if (all == null || all.hasMore) return null;

    int count(CampaignStatus status) =>
        all.items.where((c) => c.status == status).length;

    return {
      CampaignTab.all: all.items.length,
      CampaignTab.draft: count(CampaignStatus.draft),
      CampaignTab.open: count(CampaignStatus.open),
      CampaignTab.inProgress: count(CampaignStatus.inProgress),
      CampaignTab.completed: count(CampaignStatus.completed),
    };
  }
}

class _CampaignList extends ConsumerWidget {
  const _CampaignList({required this.tab});

  final CampaignTab tab;

  Future<void> _refresh(WidgetRef ref) async {
    try {
      ref.invalidate(campaignsProvider(tab));
      await ref.read(campaignsProvider(tab).future);
    } catch (_) {
      // The list renders the error itself; the pull just needs to finish.
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final campaigns = ref.watch(campaignsProvider(tab));
    final margin = AppBreakpoints.pageMargin(context);
    final padding = EdgeInsets.fromLTRB(
      margin,
      AppSpacing.xs,
      margin,
      AppSpacing.xl,
    );

    return ZRefresh(
      onRefresh: () => _refresh(ref),
      // Cards stagger in on first load; a refresh does not replay them.
      child: RevealScope(
        child: AsyncView<Paginated<Campaign>>(
          value: campaigns,
          onRetry: () => ref.invalidate(campaignsProvider(tab)),
          loading: ListView.separated(
            padding: padding,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: 2,
            separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.md),
            itemBuilder: (_, _) => const CampaignCardSkeleton(),
          ),
          builder: (page) {
            if (page.isEmpty) {
              final copy = _emptyCopy(tab);
              // Scrollable so pull-to-refresh still works on an empty tab.
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  SizedBox(height: MediaQuery.sizeOf(context).height * 0.08),
                  EmptyState(
                    icon: copy.icon,
                    title: copy.title,
                    message: copy.message,
                    actionLabel: 'Create a campaign',
                    onAction: () => context.push(Routes.newCampaign),
                  ),
                ],
              );
            }

            return ListView.separated(
              padding: padding,
              physics: const AlwaysScrollableScrollPhysics(),
              itemCount: page.items.length,
              separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.md),
              itemBuilder: (_, i) => Reveal(
                index: i,
                revealKey: page.items[i].id,
                child: BrandCampaignCard(campaign: page.items[i]),
              ),
            );
          },
        ),
      ),
    );
  }

  static ({IconData icon, String title, String message}) _emptyCopy(
    CampaignTab tab,
  ) =>
      switch (tab) {
        CampaignTab.all => (
            icon: Icons.campaign_outlined,
            title: 'No campaigns yet',
            message: 'Post a brief and let verified creators come to you, '
                'instead of reaching out one by one.',
          ),
        CampaignTab.draft => (
            icon: Icons.edit_note_rounded,
            title: 'No drafts',
            message: 'Briefs you save without publishing wait here until '
                'they are ready to go out.',
          ),
        CampaignTab.open => (
            icon: Icons.campaign_outlined,
            title: 'Nothing open right now',
            message: 'Publish a brief to start receiving applications from '
                'creators who match it.',
          ),
        CampaignTab.inProgress => (
            icon: Icons.autorenew_rounded,
            title: 'Nothing in progress',
            message: 'Campaigns with work under way will show here.',
          ),
        CampaignTab.completed => (
            icon: Icons.task_alt_rounded,
            title: 'Nothing completed yet',
            message: 'Closed campaigns are kept here, with their applicants '
                'and hires, for reference.',
          ),
      };
}
