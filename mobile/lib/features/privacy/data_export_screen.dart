import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../data/repositories/privacy_repository.dart';
import '../../models/privacy.dart';
import '../../providers/privacy_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import 'widgets/privacy_widgets.dart';
import 'widgets/reauth_panel.dart';

/// "Download my data": a copy of the personal data on the account, as a ZIP
/// of JSON files.
///
/// The archive is built while the user waits, right after they confirm it is
/// them, and arrives with a link that works for a limited time and number of
/// downloads. The link is shown here, once: the overview never carries it,
/// and the server's email is only a security notice, with no link in it, so
/// an export the owner did not ask for never goes unnoticed. A copy that is
/// still available gets a fresh link after the same check, which also
/// cancels every earlier link to it.
class DataExportScreen extends ConsumerStatefulWidget {
  const DataExportScreen({super.key});

  @override
  ConsumerState<DataExportScreen> createState() => _DataExportScreenState();
}

class _DataExportScreenState extends ConsumerState<DataExportScreen> {
  /// The copy this visit holds a link to: one just prepared, or a fresh link
  /// to the latest copy.
  DataExport? _created;

  /// Catches the card's "downloads left" up once the browser has the file.
  Timer? _catchUp;

  @override
  void dispose() {
    _catchUp?.cancel();
    super.dispose();
  }

  /// Reloads the overview and waits for it, so pull-to-refresh spins until
  /// the page is current. A failure stays in the provider, where the page
  /// shows it beside the last good copy.
  Future<void> _refresh() async {
    try {
      await ref.refresh(privacyOverviewProvider.future).then((_) {});
    } catch (_) {
      // Shown from the provider's state.
    }
  }

  Future<void> _request(PrivacyOverview overview) async {
    final export = await showZSheet<DataExport>(
      context,
      title: 'Confirm it\'s you',
      subtitle:
          'For your security, confirm your identity before we prepare a copy '
          'of your data.',
      // A swipe is the one way out the sheet cannot refuse while it works.
      enableDrag: false,
      builder: (_) => _ExportConfirmSheet(
        options: overview.verification,
        submitLabel: 'Prepare my data',
        busyNote: 'Preparing your data. This usually takes a few seconds, so keep this open.',
        submit: (repository, proof) => repository.requestExport(proof),
      ),
    );

    if (!mounted) return;
    if (export != null) setState(() => _created = export);

    // Whatever closed the sheet, the server may have made a copy and started
    // the cooldown, so the page asks again.
    ref.invalidate(privacyOverviewProvider);
  }

  Future<void> _download(DataExport export, PrivacyOverview overview) async {
    final url = export.downloadUrl;
    if (url != null) {
      // Straight from the tap, with no wait before it, so a browser does not
      // treat the new tab as an unrequested popup.
      await _launch(url);
      return;
    }

    // The overview never carries a link. A fresh one needs the same check as
    // a new copy, and the Ready card then offers it for a tap of its own.
    final fresh = await showZSheet<DataExport>(
      context,
      title: 'Confirm it\'s you',
      subtitle:
          'For your security, confirm your identity before we give you a link '
          'to download your data.',
      enableDrag: false,
      builder: (_) => _ExportConfirmSheet(
        options: overview.verification,
        submitLabel: 'Get download link',
        busyNote: 'Getting your download link, so keep this open.',
        submit: (repository, proof) => repository.exportLink(export.id, proof),
      ),
    );

    if (!mounted) return;
    if (fresh != null) setState(() => _created = fresh);
    ref.invalidate(privacyOverviewProvider);
  }

  Future<void> _launch(String url) async {
    bool opened;
    try {
      opened = await launchUrl(
        Uri.parse(url),
        mode: LaunchMode.externalApplication,
        // A new tab on the web: the archive downloads there, and the app
        // stays where it is even if the link has just expired.
        webOnlyWindowName: '_blank',
      );
    } catch (_) {
      opened = false;
    }

    if (!mounted) return;

    if (!opened) {
      showZSnack(context, 'Could not open the download link. Please try again.', isError: true);
      return;
    }

    // The server counts each download, so the card's "downloads left"
    // catches up once the browser has fetched the file.
    _catchUp?.cancel();
    _catchUp = Timer(const Duration(seconds: 3), () {
      if (mounted) ref.invalidate(privacyOverviewProvider);
    });
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(privacyOverviewProvider);
    final data = overview.valueOrNull;

    // Only a current overview may be acted on: not one still reloading, and
    // not the last good copy kept on screen after a failed refresh.
    final settled = !overview.isLoading && !overview.hasError;

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Download my data'),
      bottomBar: data == null
          ? null
          : ZButton(
              label: _created != null ? 'Request another copy' : 'Request my data',
              icon: Icons.download_rounded,
              onPressed: settled && data.export.canRequest && data.verification.canVerify
                  ? () => _request(data)
                  : null,
            ),
      // `skipError`: once there is an overview, a failed refresh is reported
      // above it instead of replacing the page with an error.
      body: overview.when(
        skipError: true,
        data: (data) => _body(data, staleError: overview.hasError ? overview.error : null),
        loading: () => const ListSkeleton(),
        error: (error, _) => ErrorState(error: error, onRetry: _refresh),
      ),
    );
  }

  Widget _body(PrivacyOverview data, {Object? staleError}) {
    final margin = AppBreakpoints.pageMargin(context);
    final latest = data.export.latest;
    final created = _created;

    // The copy this visit holds a link to, with the server's latest counts
    // and state, so a copy used up or expired meanwhile stops offering it.
    final current = created == null
        ? null
        : latest != null && latest.id == created.id
            ? created.mergedWith(latest)
            : created;

    return ZRefresh(
      onRefresh: _refresh,
      child: ListView(
        // A page shorter than the screen can still be pulled to refresh.
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xxl),
        children: [
          if (staleError != null) ...[
            ZInfoBanner(
              tone: StatusTone.warning,
              icon: staleError is OfflineFailure
                  ? Icons.wifi_off_rounded
                  : Icons.sync_problem_rounded,
              message: staleError is OfflineFailure
                  ? 'You are offline. This is the page as it was last loaded.'
                  : 'We couldn\'t refresh this page. This is how it was last loaded.',
              actionLabel: 'Retry',
              onAction: _refresh,
            ),
            const SizedBox(height: AppSpacing.md),
          ],
          const Reveal(
            index: 0,
            child: PrivacyHero(
              icon: Icons.inventory_2_outlined,
              title: 'Get a copy of your data',
              message:
                  'Download the personal data Zusty holds about your '
                  'account: your profile, bookings, messages, payments and '
                  'more, as a ZIP of JSON files you can open in any text '
                  'editor.',
            ),
          ),
          if (current != null && current.isReady) ...[
            const SizedBox(height: AppSpacing.lg),
            Reveal(
              index: 1,
              child: _ReadyCard(
                export: current,
                onDownload: () => _download(current, data),
              ),
            ),
          ] else if (latest != null) ...[
            const PrivacySectionTitle('Your latest copy'),
            Reveal(
              index: 1,
              child: _LatestExportCard(
                export: latest,
                onDownload: () => _download(latest, data),
              ),
            ),
          ],
          if (!data.export.canRequest && data.export.nextAvailableAt != null) ...[
            const SizedBox(height: AppSpacing.md),
            ZInfoBanner(
              tone: StatusTone.info,
              message:
                  'You can request a new copy after '
                  '${Formatters.dateTime(data.export.nextAvailableAt)}. '
                  'Your latest copy can still be downloaded until it expires '
                  'or its downloads run out.',
            ),
          ],
          if (!data.verification.canVerify) ...[
            const SizedBox(height: AppSpacing.md),
            ZInfoBanner(
              tone: StatusTone.warning,
              title: 'We can\'t confirm it\'s you in the app',
              message: reauthBlockedMessage(data.verification),
              actionLabel: 'Contact support',
              onAction: () => context.push(Routes.newTicket),
            ),
          ],
          const PrivacySectionTitle('What\'s included'),
          Reveal(
            index: 2,
            child: PrivacyListCard(
              items: data.export.includes,
              icon: Icons.check_circle_rounded,
              iconColor: AppColors.success,
              footer:
                  'Not included: your password, security codes, full bank '
                  'account numbers, identity document images and full document '
                  'numbers, messages other people sent you, and our team\'s '
                  'internal notes.',
            ),
          ),
          const PrivacySectionTitle('How it works'),
          Reveal(
            index: 3,
            child: _HowItWorks(
              linkHours: data.export.linkHours,
              maxDownloads: data.export.maxDownloads,
              cooldownHours: data.export.cooldownHours,
              providers: data.verification.appProviders,
            ),
          ),
        ],
      ),
    );
  }
}

/// The moment a copy is ready, with the link this visit was handed.
class _ReadyCard extends StatelessWidget {
  const _ReadyCard({required this.export, required this.onDownload});

  final DataExport export;
  final VoidCallback onDownload;

  @override
  Widget build(BuildContext context) {
    final notice = export.securityNotice;

    return ZCard(
      shadow: false,
      color: AppColors.successContainer.withValues(alpha: 0.45),
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        children: [
          SuccessCheck(
            size: 72,
            color: AppColors.success,
            checkColor: Colors.white,
          ),
          const SizedBox(height: AppSpacing.md),
          Text('Your data is ready', style: AppTypography.headlineSmall),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'The link works until ${Formatters.dateTime(export.expiresAt)}, with '
            '${export.downloadsLeft} of ${export.maxDownloads} downloads left. '
            '${notice != null ? 'We\'ve sent a security notice to ${notice.destination}.' : 'Keep this link private: anyone who has it can download your data until it expires.'}',
            textAlign: TextAlign.center,
            style: AppTypography.bodyMedium.copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.md),
          ZButton(
            label: 'Download now${export.sizeLabel.isEmpty ? '' : ' (${export.sizeLabel})'}',
            icon: Icons.file_download_outlined,
            onPressed: onDownload,
          ),
        ],
      ),
    );
  }
}

class _LatestExportCard extends StatelessWidget {
  const _LatestExportCard({required this.export, required this.onDownload});

  final DataExport export;
  final VoidCallback onDownload;

  @override
  Widget build(BuildContext context) {
    final (String label, StatusTone tone, String detail) = switch (export.status) {
      'ready' when export.isReady => (
          'Ready',
          StatusTone.success,
          'Works until ${Formatters.dateTime(export.expiresAt)} · '
              '${export.downloadsLeft} of ${export.maxDownloads} downloads left',
        ),
      'ready' => (
          'Used up',
          StatusTone.neutral,
          'This copy has been downloaded the maximum number of times.',
        ),
      'failed' => (
          'Failed',
          StatusTone.error,
          'We couldn\'t prepare this copy. Request a new one.',
        ),
      'processing' => (
          'Preparing',
          StatusTone.info,
          'Your copy is being prepared.',
        ),
      _ => (
          'Expired',
          StatusTone.neutral,
          'This copy has expired and the file was deleted. Request a new copy.',
        ),
    };

    return ZCard(
      shadow: false,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.folder_zip_outlined, color: AppColors.primary),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  'Requested ${Formatters.dateTime(export.createdAt)}',
                  style: AppTypography.titleSmall,
                ),
              ),
              StatusChip(label: label, tone: tone, dense: true),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(detail, style: AppTypography.bodySmall.copyWith(height: 1.45)),
          if (export.isReady) ...[
            const SizedBox(height: AppSpacing.sm),
            ZButton.outlined(
              label: 'Download${export.sizeLabel.isEmpty ? '' : ' (${export.sizeLabel})'}',
              icon: Icons.file_download_outlined,
              size: ZButtonSize.medium,
              onPressed: onDownload,
            ),
          ],
        ],
      ),
    );
  }
}

class _HowItWorks extends StatelessWidget {
  const _HowItWorks({
    required this.linkHours,
    required this.maxDownloads,
    required this.cooldownHours,
    required this.providers,
  });

  final int linkHours;
  final int maxDownloads;
  final int cooldownHours;

  /// Google or Apple, when the account can confirm with them.
  final List<String> providers;

  String _hours(int hours) =>
      hours % 24 == 0 && hours >= 48
          ? '${hours ~/ 24} days'
          : Formatters.count(hours, 'hour');

  @override
  Widget build(BuildContext context) {
    final signIn = providers.map(providerLabel).join(' or ');

    final steps = [
      (
        'Confirm it\'s you',
        providers.isEmpty
            ? 'With your password, or a one-time code sent to your email.'
            : 'With your password, a one-time code sent to your email, '
                'or your $signIn sign-in.',
      ),
      ('We prepare your file', 'Straight away. It usually takes a few seconds.'),
      (
        'Download it',
        'The link works for ${_hours(linkHours)} and $maxDownloads downloads, then the file is deleted. '
            'You can ask for a new copy every ${_hours(cooldownHours)}.',
      ),
    ];

    return ZCard(
      shadow: false,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        children: [
          for (var i = 0; i < steps.length; i++) ...[
            if (i > 0) const SizedBox(height: AppSpacing.sm),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 26,
                  height: 26,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: AppColors.primary.withValues(alpha: 0.12),
                    shape: BoxShape.circle,
                  ),
                  child: Text(
                    '${i + 1}',
                    style: AppTypography.labelMedium.copyWith(color: AppColors.primary),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(steps[i].$1, style: AppTypography.titleSmall),
                      const SizedBox(height: 2),
                      Text(steps[i].$2, style: AppTypography.bodySmall.copyWith(height: 1.45)),
                    ],
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The confirmation sheet: proves it is the owner, then runs [submit], which
/// prepares a new copy or issues a fresh link to the latest one. Errors about
/// the password or code land beside the field, and the sheet stays open to
/// try again.
///
/// Once the request is on its way the sheet cannot be closed (Back, the X, a
/// tap outside; the caller turns off the swipe), and the proof cannot change.
/// The page behind refreshes whenever the sheet closes.
class _ExportConfirmSheet extends ConsumerStatefulWidget {
  const _ExportConfirmSheet({
    required this.options,
    required this.submitLabel,
    required this.busyNote,
    required this.submit,
  });

  final VerificationOptions options;

  /// "Prepare my data" or "Get download link".
  final String submitLabel;

  /// Shown under the button while the request runs.
  final String busyNote;
  final Future<DataExport> Function(PrivacyRepository repository, Reauth proof) submit;

  @override
  ConsumerState<_ExportConfirmSheet> createState() => _ExportConfirmSheetState();
}

class _ExportConfirmSheetState extends ConsumerState<_ExportConfirmSheet> {
  late final ReauthController _reauth = ReauthController(
    options: widget.options,
    purpose: PrivacyPurpose.export,
    repository: ref.read(privacyRepositoryProvider),
    firebaseToken: ref.read(firebaseTokenSourceProvider),
  );

  bool _busy = false;
  String? _error;

  /// The server refused for a reason no other proof can fix (the cooldown,
  /// a copy that has gone), so the button stays off.
  bool _refused = false;

  @override
  void dispose() {
    _reauth.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final proof = _reauth.proof;
    if (proof == null || _busy || _refused) return;

    // Nothing may change the proof while it is checked.
    FocusManager.instance.primaryFocus?.unfocus();

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final export = await widget.submit(ref.read(privacyRepositoryProvider), proof);
      if (mounted) Navigator.of(context).pop(export);
    } on ApiFailure catch (failure) {
      if (!mounted) return;

      // Refused before the proof was checked: the code is still unused, and
      // the page behind says until when once it refreshes.
      if (failure.code == 'export_cooldown' || failure.code == 'export_unavailable') {
        ref.invalidate(privacyOverviewProvider);
        setState(() {
          _refused = true;
          _error = failure.message;
        });
        return;
      }

      if (failure is TimeoutFailure) {
        // The archive may still have been built, using the code and starting
        // the cooldown. The panel asks for a new code; the page checks again.
        _reauth.applyFailure(failure);
        ref.invalidate(privacyOverviewProvider);
        setState(() => _error =
            'This is taking longer than usual. Your copy may still be ready, '
            'so check this page again in a minute.');
        return;
      }

      if (!_reauth.applyFailure(failure)) setState(() => _error = failure.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !_busy,
      child: ListenableBuilder(
        listenable: _reauth,
        builder: (context, _) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            IgnorePointer(
              ignoring: _busy,
              child: ReauthPanel(controller: _reauth, onSubmitted: _submit),
            ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              ZInfoBanner(
                message: _error!,
                tone: _refused ? StatusTone.info : StatusTone.error,
              ),
            ],
            const SizedBox(height: AppSpacing.lg),
            ZButton(
              label: widget.submitLabel,
              icon: Icons.inventory_2_outlined,
              isLoading: _busy,
              onPressed: !_refused && _reauth.canVerify && _reauth.isComplete ? _submit : null,
            ),
            if (_busy) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(
                widget.busyNote,
                textAlign: TextAlign.center,
                style: AppTypography.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }
}
