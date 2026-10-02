import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/formatters.dart';
import '../../models/privacy.dart';
import '../../providers/privacy_provider.dart';
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
/// downloads. The server also emails a security notice, so an export the
/// owner did not ask for never goes unnoticed.
class DataExportScreen extends ConsumerStatefulWidget {
  const DataExportScreen({super.key});

  @override
  ConsumerState<DataExportScreen> createState() => _DataExportScreenState();
}

class _DataExportScreenState extends ConsumerState<DataExportScreen> {
  /// The export made on this visit, for the "ready" moment at the top.
  DataExport? _created;

  Future<void> _request(PrivacyOverview overview) async {
    final export = await showZSheet<DataExport>(
      context,
      title: 'Confirm it\'s you',
      subtitle:
          'For your security, confirm your identity before we prepare a copy '
          'of your data.',
      builder: (_) => _ExportConfirmSheet(options: overview.verification),
    );

    if (export == null || !mounted) return;

    setState(() => _created = export);
    ref.invalidate(privacyOverviewProvider);
  }

  Future<void> _download(DataExport export) async {
    final url = export.downloadUrl;
    if (url == null) return;

    final opened = await launchUrl(
      Uri.parse(url),
      mode: LaunchMode.externalApplication,
      // A new tab on the web: the archive downloads there, and the app
      // stays where it is even if the link has just expired.
      webOnlyWindowName: '_blank',
    );

    if (!mounted) return;

    if (!opened) {
      showZSnack(context, 'Could not open the download link. Please try again.', isError: true);
      return;
    }

    // The server counts each download, so the card's "downloads left"
    // catches up once the browser has fetched the file.
    Future.delayed(const Duration(seconds: 3), () {
      if (mounted) ref.invalidate(privacyOverviewProvider);
    });
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(privacyOverviewProvider);
    final margin = AppBreakpoints.pageMargin(context);
    final data = overview.valueOrNull;

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Download my data'),
      bottomBar: data == null
          ? null
          : ZButton(
              label: _created != null ? 'Request another copy' : 'Request my data',
              icon: Icons.download_rounded,
              onPressed: data.export.canRequest && data.verification.canVerify
                  ? () => _request(data)
                  : null,
            ),
      body: AsyncView(
        value: overview,
        onRetry: () => ref.invalidate(privacyOverviewProvider),
        builder: (data) => ZRefresh(
          onRefresh: () async => ref.invalidate(privacyOverviewProvider),
          child: ListView(
            padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xxl),
            children: [
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
              if (_created != null && _created!.isReady) ...[
                const SizedBox(height: AppSpacing.lg),
                Reveal(
                  index: 1,
                  child: _ReadyCard(
                    export: _created!,
                    onDownload: () => _download(_created!),
                  ),
                ),
              ] else if (data.export.latest != null) ...[
                const PrivacySectionTitle('Your latest copy'),
                Reveal(
                  index: 1,
                  child: _LatestExportCard(
                    export: data.export.latest!,
                    onDownload: () => _download(data.export.latest!),
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
                      'Your latest copy can still be downloaded while its link works.',
                ),
              ],
              if (!data.verification.canVerify) ...[
                const SizedBox(height: AppSpacing.md),
                const ZInfoBanner(
                  tone: StatusTone.warning,
                  message:
                      'We can\'t confirm it\'s you in the app because your account '
                      'has no password, email or mobile number. Contact support '
                      'for a copy of your data.',
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
                      'account numbers, identity document numbers and images, and '
                      'messages other people sent you.',
                ),
              ),
              const PrivacySectionTitle('How it works'),
              Reveal(
                index: 3,
                child: _HowItWorks(
                  linkHours: data.export.linkHours,
                  maxDownloads: data.export.maxDownloads,
                  cooldownHours: data.export.cooldownHours,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The moment a fresh copy is ready.
class _ReadyCard extends StatelessWidget {
  const _ReadyCard({required this.export, required this.onDownload});

  final DataExport export;
  final VoidCallback onDownload;

  @override
  Widget build(BuildContext context) {
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
            'The link works until ${Formatters.dateTime(export.expiresAt)} and can '
            'be used ${export.maxDownloads} times. We\'ve also emailed it to you '
            'with a security notice.',
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
          'This link has been used the maximum number of times.',
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
          'This link has expired and the file was deleted. Request a new copy.',
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
  });

  final int linkHours;
  final int maxDownloads;
  final int cooldownHours;

  String _hours(int hours) =>
      hours % 24 == 0 && hours >= 48 ? '${hours ~/ 24} days' : '$hours hours';

  @override
  Widget build(BuildContext context) {
    final steps = [
      ('Confirm it\'s you', 'With your password, or a one-time code sent to your email or phone.'),
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

/// The confirmation sheet: proves it is the owner, then builds the export.
/// Errors about the password or code land beside the field, and the sheet
/// stays open to try again.
class _ExportConfirmSheet extends ConsumerStatefulWidget {
  const _ExportConfirmSheet({required this.options});

  final VerificationOptions options;

  @override
  ConsumerState<_ExportConfirmSheet> createState() => _ExportConfirmSheetState();
}

class _ExportConfirmSheetState extends ConsumerState<_ExportConfirmSheet> {
  late final ReauthController _reauth = ReauthController(
    options: widget.options,
    purpose: PrivacyPurpose.export,
    repository: ref.read(privacyRepositoryProvider),
  );

  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reauth.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final proof = _reauth.proof;
    if (proof == null || _busy) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final export = await ref.read(privacyRepositoryProvider).requestExport(proof);
      if (mounted) Navigator.of(context).pop(export);
    } on ApiFailure catch (failure) {
      if (!mounted) return;
      if (!_reauth.applyFailure(failure)) setState(() => _error = failure.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _reauth,
      builder: (context, _) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          ReauthPanel(controller: _reauth, onSubmitted: _submit),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            ZInfoBanner(message: _error!, tone: StatusTone.error),
          ],
          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: _busy ? 'Preparing your data…' : 'Prepare my data',
            icon: Icons.inventory_2_outlined,
            isLoading: _busy,
            onPressed: widget.options.canVerify && _reauth.isComplete ? _submit : null,
          ),
        ],
      ),
    );
  }
}
