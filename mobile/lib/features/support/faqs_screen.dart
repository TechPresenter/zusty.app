import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/motion/motion.dart';
import '../../core/theme/app_theme.dart';
import '../../models/support.dart';
import '../../providers/core_providers.dart';
import '../../shared/motion/reveal.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_html.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_tabs.dart';
import '../../shared/widgets/z_text_field.dart';
import 'new_ticket.dart';
import 'support_providers.dart';

/// What search looks through for each FAQ, worked out once per FAQ.
final _searchText = Expando<String>('faq search text');

/// The question, the answer as the reader sees it, and the category name.
///
/// The answer is HTML from the admin editor, so it is searched as the text
/// [ZHtml] draws: a search for "help & support" finds "Help &amp; Support",
/// and one for "strong" finds nothing. The question and category name are
/// plain text and are never run through the HTML parser, which would mangle
/// a question such as "Fees for <10k followers?".
String _haystack(Faq faq) => _searchText[faq] ??= _normalised(
  '${faq.question}\n${htmlToPlainText(faq.answer)}\n${faq.categoryName}',
);

/// Lower case, with every run of whitespace (newlines included) one space.
String _normalised(String text) =>
    text.toLowerCase().replaceAll(RegExp(r'\s+'), ' ').trim();

/// Screen 28 — FAQs.
///
/// A searchable accordion grouped by category, with a topic filter and a
/// was-this-helpful vote wired to `POST /faqs/{id}/helpful`.
///
/// The categories are whatever the FAQs themselves carry, named by the admin
/// panel — not a separate list that could drift out of step and filter to
/// nothing. The help centre can open this screen on a topic, on one question
/// (expanded and scrolled to), or with the search box focused.
class FaqsScreen extends ConsumerStatefulWidget {
  const FaqsScreen({super.key});

  @override
  ConsumerState<FaqsScreen> createState() => _FaqsScreenState();
}

class _FaqsScreenState extends ConsumerState<FaqsScreen> {
  final _controller = TextEditingController();
  final _openKey = GlobalKey();

  String _query = '';

  /// Null shows every category.
  String? _category;

  /// The question the help centre asked to open, if any.
  int? _openFaqId;
  bool _focusSearch = false;
  bool _scrolledToOpen = false;

  @override
  void initState() {
    super.initState();

    final launch = ref.read(faqLaunchProvider);
    if (launch != null) {
      _category = launch.category;
      _openFaqId = launch.faqId;
      _focusSearch = launch.focusSearch;

      // Consumed once; clearing it during the first build would modify a
      // provider mid-build, so it waits for the frame to finish.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) ref.read(faqLaunchProvider.notifier).state = null;
      });
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  bool _matches(Faq faq) {
    if (_category != null && faq.category != _category) return false;
    if (_query.isEmpty) return true;

    return _haystack(faq).contains(_normalised(_query));
  }

  /// Brings the launched question into view once its tile exists.
  void _scrollToOpen() {
    if (_scrolledToOpen || _openFaqId == null) return;
    _scrolledToOpen = true;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      final target = _openKey.currentContext;
      if (target != null) {
        Scrollable.ensureVisible(
          target,
          duration: Motion.of(context, Motion.slow),
          curve: Motion.standard,
          alignment: 0.1,
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final faqs = ref.watch(helpCentreFaqsProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      backgroundColor: AppColors.surface,
      padded: false,
      constrain: false,
      appBar: const ZAppBar(title: 'FAQs'),
      body: Column(
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.xs,
              margin,
              AppSpacing.sm,
            ),
            child: ZSearchField(
              controller: _controller,
              hint: 'Search questions...',
              autofocus: _focusSearch,
              showClear: _query.isNotEmpty,
              onClear: () {
                _controller.clear();
                setState(() => _query = '');
              },
              onChanged: (value) => setState(() => _query = value.trim()),
            ),
          ),
          Expanded(
            child: AsyncView(
              value: faqs,
              onRetry: () => ref.invalidate(helpCentreFaqsProvider),
              loading: Padding(
                padding: EdgeInsets.symmetric(horizontal: margin),
                child: const ListSkeleton(count: 6, height: 64),
              ),
              builder: (items) => _buildContent(items, margin),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildContent(List<Faq> items, double margin) {
    final topics = FaqTopic.from(items);

    // A launch topic the user cannot see (audience-filtered, or since
    // removed) falls back to everything rather than an empty screen.
    final category = topics.any((t) => t.value == _category) ? _category : null;

    final filtered = items.where(_matches).toList(growable: false);

    // Grouped in topic order, which is the admin's order.
    final groups = <FaqTopic, List<Faq>>{
      for (final topic in topics)
        if (category == null || topic.value == category)
          topic: filtered.where((f) => f.category == topic.value).toList(),
    }..removeWhere((_, list) => list.isEmpty);

    final selectedIndex = category == null
        ? 0
        : topics.indexWhere((t) => t.value == category) + 1;

    if (_openFaqId != null) _scrollToOpen();

    return Column(
      children: [
        if (topics.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: ZFilterPills(
              labels: ['All', for (final topic in topics) topic.label],
              selected: selectedIndex,
              padding: EdgeInsets.symmetric(horizontal: margin),
              onSelected: (index) => setState(
                () => _category = index == 0 ? null : topics[index - 1].value,
              ),
            ),
          ),
        Expanded(
          child: groups.isEmpty
              ? SingleChildScrollView(
                  child: EmptyState(
                    title: 'No answers found',
                    message: _query.isEmpty
                        ? 'There is nothing in this topic yet. Our team can '
                              'help directly.'
                        : 'Nothing matches "$_query". Try other words, or ask '
                              'our team directly.',
                    icon: Icons.help_outline_rounded,
                    actionLabel: 'Ask our team',
                    onAction: () => NewTicketSheet.show(context),
                  ),
                )
              // A column, not a lazy list: the whole set is a few dozen rows,
              // and every tile has to exist for the launched question to be
              // scrolled to.
              : SingleChildScrollView(
                  keyboardDismissBehavior:
                      ScrollViewKeyboardDismissBehavior.onDrag,
                  padding: EdgeInsets.fromLTRB(
                    margin,
                    AppSpacing.sm,
                    margin,
                    AppSpacing.xl,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (final (index, entry) in groups.entries.indexed) ...[
                        if (index > 0) const SizedBox(height: AppSpacing.lg),
                        Reveal(
                          index: index,
                          child: _FaqGroup(
                            topic: entry.key,
                            faqs: entry.value,
                            openFaqId: _openFaqId,
                            openKey: _openKey,
                            // While searching every match is opened, so the
                            // answer containing the words is on screen.
                            expandAll: _query.length >= 3,
                          ),
                        ),
                      ],
                      const SizedBox(height: AppSpacing.xl),
                      const _StillNeedHelp(),
                    ],
                  ),
                ),
        ),
      ],
    );
  }
}

/// One category: its name and count, then its questions on a single card.
class _FaqGroup extends StatelessWidget {
  const _FaqGroup({
    required this.topic,
    required this.faqs,
    required this.openKey,
    this.openFaqId,
    this.expandAll = false,
  });

  final FaqTopic topic;
  final List<Faq> faqs;
  final int? openFaqId;
  final GlobalKey openKey;
  final bool expandAll;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(topic.icon, size: 18, color: AppColors.primary),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: Text(
                topic.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.titleMedium.copyWith(fontSize: 17),
              ),
            ),
            Text(
              '${faqs.length}',
              style: AppTypography.labelMedium.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        ZCard(
          padding: EdgeInsets.zero,
          clip: true,
          child: Column(
            children: [
              for (var i = 0; i < faqs.length; i++) ...[
                if (i > 0)
                  Divider(
                    height: 1,
                    indent: AppSpacing.md,
                    endIndent: AppSpacing.md,
                    color: AppColors.outlineVariant,
                  ),
                _FaqTile(
                  key: faqs[i].id == openFaqId ? openKey : ValueKey(faqs[i].id),
                  faq: faqs[i],
                  initiallyExpanded: faqs[i].id == openFaqId,
                  forceExpanded: expandAll,
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _FaqTile extends ConsumerStatefulWidget {
  const _FaqTile({
    super.key,
    required this.faq,
    this.initiallyExpanded = false,
    this.forceExpanded = false,
  });

  final Faq faq;
  final bool initiallyExpanded;
  final bool forceExpanded;

  @override
  ConsumerState<_FaqTile> createState() => _FaqTileState();
}

class _FaqTileState extends ConsumerState<_FaqTile> {
  late bool _expanded = widget.initiallyExpanded;
  bool? _voted;

  @override
  void didUpdateWidget(covariant _FaqTile oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Opening every match for a search is a view of the results, not a
    // choice the user made; when the search clears, the tiles go back.
    if (oldWidget.forceExpanded && !widget.forceExpanded) {
      _expanded = widget.initiallyExpanded;
    }
  }

  void _vote(bool helpful) {
    setState(() => _voted = helpful);
    ref
        .read(accountRepositoryProvider)
        .rateFaq(widget.faq.id, helpful: helpful);
  }

  @override
  Widget build(BuildContext context) {
    final open = _expanded || widget.forceExpanded;
    final duration = Motion.of(context, Motion.medium);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          button: true,
          expanded: open,
          child: InkWell(
            onTap: () => setState(() => _expanded = !open),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 56),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.md,
                  vertical: AppSpacing.sm,
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        widget.faq.question,
                        style: AppTypography.titleSmall.copyWith(
                          color: open ? AppColors.primary : AppColors.onSurface,
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    AnimatedRotation(
                      turns: open ? 0.5 : 0,
                      duration: duration,
                      curve: Motion.standard,
                      child: Icon(
                        Icons.keyboard_arrow_down_rounded,
                        size: 24,
                        color: open
                            ? AppColors.primary
                            : AppColors.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
        AnimatedSize(
          duration: duration,
          curve: Motion.standard,
          alignment: Alignment.topCenter,
          child: !open
              ? const SizedBox(width: double.infinity)
              : Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.md,
                    0,
                    AppSpacing.md,
                    AppSpacing.sm,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Answers are written in the admin's rich-text editor:
                      // lists, links and emphasis render instead of being
                      // flattened to one paragraph.
                      ZHtml(
                        widget.faq.answer,
                        style: AppTypography.bodyMedium.copyWith(
                          color: AppColors.onSurfaceVariant,
                          height: 1.55,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              _voted == null
                                  ? 'Was this helpful?'
                                  : 'Thanks for the feedback.',
                              style: AppTypography.bodySmall,
                            ),
                          ),
                          if (_voted == null) ...[
                            _VoteButton(
                              icon: Icons.thumb_up_outlined,
                              tooltip: 'Helpful',
                              onTap: () => _vote(true),
                            ),
                            const SizedBox(width: AppSpacing.xs),
                            _VoteButton(
                              icon: Icons.thumb_down_outlined,
                              tooltip: 'Not helpful',
                              onTap: () => _vote(false),
                            ),
                          ] else
                            Padding(
                              padding: const EdgeInsets.all(AppSpacing.sm),
                              child: Icon(
                                _voted!
                                    ? Icons.thumb_up_rounded
                                    : Icons.thumb_down_rounded,
                                size: 18,
                                color: AppColors.primary,
                              ),
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
        ),
      ],
    );
  }
}

class _VoteButton extends StatelessWidget {
  const _VoteButton({
    required this.icon,
    required this.tooltip,
    required this.onTap,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Material(
        color: AppColors.surfaceContainerLow,
        shape: const CircleBorder(),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: SizedBox(
            width: AppSpacing.minTouch,
            height: AppSpacing.minTouch,
            child: Icon(icon, size: 18, color: AppColors.onSurfaceVariant),
          ),
        ),
      ),
    );
  }
}

/// The way out when the answers did not help.
class _StillNeedHelp extends StatelessWidget {
  const _StillNeedHelp();

  @override
  Widget build(BuildContext context) {
    return ZCard(
      color: AppColors.surfaceContainerLow,
      shadow: false,
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: AppColors.surfaceContainerLowest,
              shape: BoxShape.circle,
            ),
            child: Icon(
              Icons.support_agent_rounded,
              size: 22,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Still need help?', style: AppTypography.titleSmall),
                const SizedBox(height: 2),
                Text(
                  'Our team replies in a ticket, usually within a few hours.',
                  style: AppTypography.bodySmall,
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          ZButton.tonal(
            label: 'Ask',
            size: ZButtonSize.small,
            expand: false,
            onPressed: () => NewTicketSheet.show(context),
          ),
        ],
      ),
    );
  }
}
