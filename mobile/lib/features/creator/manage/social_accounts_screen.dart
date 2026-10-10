import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../models/creator.dart';
import '../../../models/creator_tools.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/creator_tools_provider.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/platform_icon.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/z_feedback.dart';
import '../../../shared/widgets/z_scaffold.dart';
import '../../../shared/widgets/z_text_field.dart';
import '../../campaigns/widgets/campaign_form_parts.dart';
import 'creator_tools_rules.dart';
import 'widgets/creator_tools_common.dart';

/// The `social_platform` term that marks a direct upload to Zusty. It is in
/// the taxonomy for the portfolio's sake and is not a network to link.
const _uploadSource = 'upload';

/// The creator's linked accounts: Instagram, YouTube, a website — in the
/// order their profile shows them.
///
/// Follower and engagement figures are the creator's own. Nothing here or on
/// the server fetches them from the networks, and the screen says so rather
/// than letting a typed number pass for a measured one.
class SocialAccountsScreen extends ConsumerWidget {
  const SocialAccountsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final book = ref.watch(mySocialAccountsProvider);
    final data = book.valueOrNull;
    final hasAny = data?.accounts.isNotEmpty ?? false;

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Social accounts'),
      bottomBar: hasAny
          ? ZButton(
              label: 'Link an account',
              icon: Icons.add_link_rounded,
              onPressed: data!.isFull ? null : () => openSocialAccountForm(context, ref),
            )
          : null,
      body: CreatorToolsGate(
        child: book.when(
          skipLoadingOnRefresh: true,
          loading: () => Padding(
            padding: EdgeInsets.all(AppBreakpoints.pageMargin(context)),
            child: const ListSkeleton(count: 4, height: 72),
          ),
          error: (error, _) => CreatorToolsErrorView(
            error: error,
            onRetry: () => ref.invalidate(mySocialAccountsProvider),
          ),
          data: (data) => _AccountList(book: data),
        ),
      ),
    );
  }
}

class _AccountList extends ConsumerWidget {
  const _AccountList({required this.book});

  final SocialAccountsBook book;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final margin = AppBreakpoints.pageMargin(context);
    final accounts = book.accounts;

    final header = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (book.locked) const LockedSectionBanner(what: 'Your social accounts'),
        const ZInfoBanner(
          icon: Icons.info_outline_rounded,
          message:
              'Follower and engagement figures are the ones you enter. Zusty '
              'does not fetch them from the networks, so keep them current '
              'and be ready to show a brand where they come from.',
        ),
        const SizedBox(height: AppSpacing.md),
        if (accounts.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: Text(
              'Drag to set the order your profile shows them in.',
              style: AppTypography.bodySmall,
            ),
          ),
        if (book.isFull)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: Text(
              'You have linked the most accounts allowed '
              '(${book.maxAccounts}). Remove one to add another.',
              style: AppTypography.bodySmall,
            ),
          ),
      ],
    );

    final padding = EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xxl);

    return ZRefresh(
      onRefresh: () => ref.read(mySocialAccountsProvider.notifier).refresh(),
      child: accounts.isEmpty
          ? ListView(
              padding: padding,
              children: [
                header,
                EmptyState(
                  icon: Icons.alternate_email_rounded,
                  title: 'No accounts linked',
                  message:
                      'Link Instagram, YouTube and the other places your work '
                      'lives, so brands can see it before they book.',
                  actionLabel: 'Link an account',
                  onAction: () => openSocialAccountForm(context, ref),
                ),
              ],
            )
          : ReorderableListView.builder(
              padding: padding,
              header: header,
              buildDefaultDragHandles: false,
              itemCount: accounts.length,
              onReorder: (from, to) => _reorder(context, ref, from, to),
              itemBuilder: (_, i) => Padding(
                key: ValueKey(accounts[i].id),
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: _AccountTile(
                  account: accounts[i],
                  index: i,
                  canReorder: accounts.length > 1,
                ),
              ),
            ),
    );
  }

  Future<void> _reorder(BuildContext context, WidgetRef ref, int from, int to) async {
    final ordered = [...book.accounts];
    // ReorderableListView reports the slot AFTER removal for a downward move.
    final target = to > from ? to - 1 : to;
    if (target == from) return;

    ordered.insert(target, ordered.removeAt(from));

    try {
      await ref.read(mySocialAccountsProvider.notifier).reorder(ordered);
    } catch (error) {
      if (context.mounted) {
        showZSnack(context, failureMessage(error), isError: true);
      }
    }
  }
}

class _AccountTile extends ConsumerWidget {
  const _AccountTile({
    required this.account,
    required this.index,
    required this.canReorder,
  });

  final SocialAccount account;
  final int index;
  final bool canReorder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final figures = account.hasMetrics
        ? [
            '${Formatters.compact(account.followers)} followers',
            if (account.engagementRate > 0)
              '${account.engagementRate.toStringAsFixed(1)}% engagement',
          ].join(' · ')
        : null;

    return ZCard(
      onTap: () => openSocialAccountForm(context, ref, existing: account),
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.sm,
        AppSpacing.base,
        AppSpacing.sm,
      ),
      child: Row(
        children: [
          PlatformIcon(
            platform: account.platform,
            iconName: account.icon,
            color: account.color,
            size: 40,
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
                        account.platformLabel,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleSmall,
                      ),
                    ),
                    if (account.isVerified) ...[
                      const SizedBox(width: 4),
                      Tooltip(
                        message: 'Verified by our team',
                        child: Icon(
                          Icons.verified_rounded,
                          size: 16,
                          color: AppColors.success,
                        ),
                      ),
                    ],
                  ],
                ),
                Text(
                  account.displayHandle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
                if (figures != null) ...[
                  const SizedBox(height: 4),
                  Wrap(
                    spacing: AppSpacing.xs,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(figures, style: AppTypography.labelMedium),
                      const StatusChip(
                        label: 'Self-reported',
                        tone: StatusTone.neutral,
                        dense: true,
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
          IconButton(
            tooltip: 'Unlink ${account.platformLabel}',
            icon: const Icon(Icons.link_off_rounded, size: 20),
            color: AppColors.onSurfaceVariant,
            onPressed: () => _unlink(context, ref),
          ),
          if (canReorder)
            ReorderableDragStartListener(
              index: index,
              // Its own node, not folded into the card's "edit" button. The
              // list adds move up / move down actions for screen readers.
              child: Semantics(
                container: true,
                label: 'Drag to reorder ${account.platformLabel}',
                child: SizedBox(
                  width: 48,
                  height: 48,
                  child: Icon(
                    Icons.drag_indicator_rounded,
                    color: AppColors.onSurfaceVariant,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Future<void> _unlink(BuildContext context, WidgetRef ref) async {
    final confirmed = await confirmRemoval(
      context,
      title: 'Unlink ${account.platformLabel}?',
      message:
          '${account.displayHandle} will no longer show on your profile. You '
          'can link it again at any time.',
      confirmLabel: 'Unlink',
    );
    if (!confirmed || !context.mounted) return;

    try {
      await ref.read(mySocialAccountsProvider.notifier).remove(account.id);
      if (context.mounted) showZSnack(context, 'Account unlinked.');
    } catch (error) {
      if (context.mounted) {
        showZSnack(context, failureMessage(error), isError: true);
      }
    }
  }
}

/// Opens the link / edit form. Resolves once the sheet closes; the list
/// updates from the server's answer.
Future<void> openSocialAccountForm(
  BuildContext context,
  WidgetRef ref, {
  SocialAccount? existing,
}) async {
  final accounts = ref.read(mySocialAccountsProvider).valueOrNull?.accounts ?? const [];

  final saved = await showZSheet<bool>(
    context,
    title: existing == null ? 'Link an account' : 'Edit ${existing.platformLabel}',
    builder: (_) => SocialAccountForm(
      existing: existing,
      // A network with figures holds one account per creator; the server
      // refuses a second, so the form does not offer it.
      linked: {
        for (final account in accounts)
          if (account.hasMetrics) account.platform,
      },
      onSubmit: (draft) => ref.read(mySocialAccountsProvider.notifier).save(draft),
    ),
  );

  if (saved == true && context.mounted) {
    showZSnack(
      context,
      existing == null ? 'Account linked.' : 'Account updated.',
    );
  }
}

/// The form inside the sheet.
///
/// It saves itself, so a refusal is shown beside the field the server named
/// and the creator's typing is still there to correct; it closes, with true,
/// only once the server has accepted.
class SocialAccountForm extends ConsumerStatefulWidget {
  const SocialAccountForm({
    super.key,
    this.existing,
    this.linked = const {},
    required this.onSubmit,
  });

  final SocialAccount? existing;

  /// Networks with figures the creator has already linked. Each holds one
  /// account, so "Link an account" leaves them out and points to the list,
  /// where the linked one is edited.
  final Set<String> linked;
  final Future<void> Function(SocialAccountDraft draft) onSubmit;

  @override
  ConsumerState<SocialAccountForm> createState() => _SocialAccountFormState();
}

class _SocialAccountFormState extends ConsumerState<SocialAccountForm> {
  final _formKey = GlobalKey<FormState>();

  late final _handle = TextEditingController(
    text: widget.existing?.hasMetrics == true ? widget.existing!.handle : '',
  );
  late final _url = TextEditingController(text: widget.existing?.profileUrl ?? '');
  late final _label = TextEditingController(text: widget.existing?.label ?? '');
  late final _followers = TextEditingController(
    text: (widget.existing?.followers ?? 0) > 0 ? '${widget.existing!.followers}' : '',
  );
  late final _engagement = TextEditingController(
    text: (widget.existing?.engagementRate ?? 0) > 0
        ? _trim(widget.existing!.engagementRate)
        : '',
  );

  late String? _platform = widget.existing?.platform;

  /// Set once the creator types their own link, after which the handle no
  /// longer rewrites it.
  late bool _urlTouched = widget.existing != null;

  bool _submitted = false;
  bool _saving = false;
  Map<String, String> _serverErrors = const {};
  String? _formError;

  static const _fields = [
    'platform',
    'handle',
    'profile_url',
    'label',
    'followers',
    'engagement_rate',
  ];

  static String _trim(double value) => value == value.roundToDouble()
      ? value.round().toString()
      : value.toString();

  @override
  void dispose() {
    for (final controller in [_handle, _url, _label, _followers, _engagement]) {
      controller.dispose();
    }
    super.dispose();
  }

  TaxonomyTerm? _term(List<TaxonomyTerm> terms) {
    for (final term in terms) {
      if (term.value == _platform) return term;
    }
    return null;
  }

  /// Whether the network has follower figures. From the taxonomy term; when
  /// editing and the terms have not loaded (first launch, a failed fetch),
  /// from the account itself, which the server decorated from the same term.
  /// Guessing "link-only" there hid the handle, rewrote it as a link and
  /// sent no figures, so saving wiped them and the verified mark.
  bool _hasMetrics(TaxonomyTerm? term) => term != null
      ? term.metadata['metrics'] == true
      : widget.existing?.hasMetrics ?? false;

  bool _isCustom(TaxonomyTerm? term) => term != null
      ? term.metadata['custom_label'] == true
      : widget.existing?.isCustom ?? false;

  void _edited(String field) {
    if (_serverErrors.containsKey(field)) {
      setState(() => _serverErrors = {..._serverErrors}..remove(field));
    }
  }

  /// Fills the link from the network's URL pattern as the handle is typed —
  /// `https://instagram.com/{handle}` — until the creator edits it.
  void _syncUrl(TaxonomyTerm? term) {
    if (_urlTouched) return;

    final pattern = term?.metadata['url'];
    final handle = _handle.text.trim().replaceFirst(RegExp(r'^@+'), '');
    if (pattern is! String || !pattern.contains('{handle}') || pattern == '{handle}') {
      return;
    }

    _url.text = handle.isEmpty ? '' : pattern.replaceAll('{handle}', handle);
  }

  /// A link-only entry's "handle" is the link itself, without the scheme —
  /// what the profile prints under the label.
  static String _handleFromLink(String link) => link
      .trim()
      .replaceFirst(RegExp(r'^https?://', caseSensitive: false), '')
      .replaceFirst(RegExp(r'^www\.', caseSensitive: false), '')
      .replaceFirst(RegExp(r'/+$'), '');

  Future<void> _save(TaxonomyTerm? term) async {
    setState(() {
      _submitted = true;
      _serverErrors = const {};
      _formError = null;
    });

    final fieldsOk = _formKey.currentState?.validate() ?? false;
    final platform = _platform;
    if (!fieldsOk || platform == null) return;

    final metrics = _hasMetrics(term);
    final link = _url.text.trim();

    final draft = SocialAccountDraft(
      id: widget.existing?.id,
      platform: platform,
      handle: metrics
          ? _handle.text.trim().replaceFirst(RegExp(r'^@+'), '')
          : _handleFromLink(link),
      profileUrl: link,
      label: _isCustom(term) ? _label.text.trim() : null,
      followers: metrics && _followers.text.trim().isNotEmpty
          ? int.parse(_followers.text.trim().replaceAll(',', ''))
          : null,
      engagementRate: metrics && _engagement.text.trim().isNotEmpty
          ? double.parse(_engagement.text.trim().replaceAll('%', ''))
          : null,
    );

    setState(() => _saving = true);

    try {
      await widget.onSubmit(draft);
      if (mounted) Navigator.of(context).pop(true);
    } catch (error) {
      if (!mounted) return;

      final byField = fieldErrors(error, _fields);
      setState(() {
        // A link-only entry has no handle field of its own: the server's
        // complaint about the handle is a complaint about the link.
        _serverErrors = {
          ...byField,
          if (!metrics && byField['handle'] != null) 'profile_url': byField['handle']!,
        };
        _formError = byField.isEmpty ? failureMessage(error) : null;
      });
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final terms = ref
        .watch(termsProvider(Tax.socialPlatform))
        .where((term) => term.value != _uploadSource)
        .toList();
    final term = _term(terms);
    final metrics = _hasMetrics(term);
    final custom = _isCustom(term);
    final editing = widget.existing != null;
    final network = term?.label ?? widget.existing?.platformLabel ?? 'the network';
    // Linking a network already linked would replace that account, so in
    // add mode those networks are not offered.
    final offered = editing
        ? terms
        : terms
              .where((t) => !(widget.linked.contains(t.value) && _hasMetrics(t)))
              .toList();
    final alreadyLinked = editing
        ? const <String>[]
        : [
            for (final t in terms)
              if (widget.linked.contains(t.value) && _hasMetrics(t)) t.label,
          ];

    return Form(
      key: _formKey,
      autovalidateMode: _submitted
          ? AutovalidateMode.onUserInteraction
          : AutovalidateMode.disabled,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_formError != null) FormErrorBanner(message: _formError!),

          // -- Which network ----------------------------------------------
          if (editing)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.md),
              child: Row(
                children: [
                  PlatformIcon(
                    platform: widget.existing!.platform,
                    iconName: widget.existing!.icon,
                    color: widget.existing!.color,
                    size: 36,
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Text(
                      'To move this to another network, unlink it and add '
                      'the new one.',
                      style: AppTypography.bodySmall,
                    ),
                  ),
                ],
              ),
            )
          else ...[
            const FieldLabel('Network'),
            if (terms.isEmpty)
              Text(
                'Loading the networks you can link…',
                style: AppTypography.bodySmall,
              )
            else
              ChoiceChips(
                options: {for (final t in offered) t.value: t.label},
                value: _platform,
                onChanged: (value) {
                  setState(() {
                    _platform = value;
                    _urlTouched = false;
                  });
                  _syncUrl(_term(terms));
                  _edited('platform');
                },
              ),
            if (alreadyLinked.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(
                'Already linked: ${alreadyLinked.join(', ')}. To change '
                '${alreadyLinked.length == 1 ? 'it' : 'one'}, tap it in your list.',
                style: AppTypography.bodySmall,
              ),
            ],
            if (_serverErrors['platform'] != null ||
                (_submitted && _platform == null)) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(
                _serverErrors['platform'] ?? 'Choose a network.',
                style: AppTypography.bodySmall.copyWith(color: AppColors.error),
              ),
            ],
            const SizedBox(height: AppSpacing.md),
          ],

          if (_platform != null) ...[
            if (custom) ...[
              ZTextField(
                label: 'Name',
                hint: 'e.g. My newsletter',
                controller: _label,
                maxLength: CreatorToolsRules.labelMax,
                textCapitalization: TextCapitalization.words,
                textInputAction: TextInputAction.next,
                errorText: _serverErrors['label'],
                onChanged: (_) => _edited('label'),
                validator: CreatorToolsRules.label,
              ),
              const SizedBox(height: AppSpacing.md),
            ],

            if (metrics) ...[
              ZTextField(
                label: 'Handle',
                hint: 'yourname',
                controller: _handle,
                prefixIcon: Icons.alternate_email_rounded,
                maxLength: CreatorToolsRules.handleMax,
                textInputAction: TextInputAction.next,
                errorText: _serverErrors['handle'],
                onChanged: (_) {
                  _syncUrl(term);
                  _edited('handle');
                },
                validator: CreatorToolsRules.handle,
              ),
              const SizedBox(height: AppSpacing.md),
            ],

            ZTextField(
              label: metrics ? 'Profile link' : 'Link',
              hint: 'https://',
              helper: metrics ? 'Filled in from your handle. Change it if yours differs.' : null,
              controller: _url,
              keyboardType: TextInputType.url,
              prefixIcon: Icons.link_rounded,
              maxLength: CreatorToolsRules.urlMax,
              textInputAction: metrics ? TextInputAction.next : TextInputAction.done,
              errorText: _serverErrors['profile_url'],
              onChanged: (_) {
                _urlTouched = true;
                _edited('profile_url');
              },
              validator: (value) {
                final error = CreatorToolsRules.link(value);
                if (error != null || metrics) return error;
                // A link-only entry is stored by its address, which the
                // server caps like any handle.
                return _handleFromLink(value ?? '').length > CreatorToolsRules.handleMax
                    ? 'Use a shorter link.'
                    : null;
              },
            ),

            if (metrics) ...[
              const SizedBox(height: AppSpacing.md),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: ZTextField(
                      label: 'Followers',
                      hint: 'e.g. 12500',
                      controller: _followers,
                      keyboardType: TextInputType.number,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      textInputAction: TextInputAction.next,
                      errorText: _serverErrors['followers'],
                      onChanged: (_) => _edited('followers'),
                      validator: CreatorToolsRules.followers,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: ZTextField(
                      label: 'Engagement %',
                      hint: 'e.g. 3.5',
                      controller: _engagement,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      inputFormatters: [
                        FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                      ],
                      textInputAction: TextInputAction.done,
                      errorText: _serverErrors['engagement_rate'],
                      onChanged: (_) => _edited('engagement_rate'),
                      validator: CreatorToolsRules.engagementRate,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.xs),
              Text(
                'Optional, and self-reported: Zusty does not fetch these from '
                '$network. Brands may ask you to show where they come from.',
                style: AppTypography.bodySmall,
              ),
            ],
          ],

          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: editing ? 'Save changes' : 'Link account',
            isLoading: _saving,
            onPressed: _platform == null ? null : () => _save(term),
          ),
        ],
      ),
    );
  }
}
