import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/motion/motion.dart';
import '../../../core/network/api_failure.dart';
import '../../../core/theme/app_theme.dart';
import '../../../data/repositories/kyc_repository.dart';
import '../../../models/kyc.dart';
import '../../../providers/kyc_provider.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../kyc_share_cache.dart';

/// Opens one of the user's own verification documents, inside the app.
///
/// The bytes are fetched on demand and held in memory only. Images are decoded
/// from memory with pinch-to-zoom and evicted on close. A PDF cannot be drawn
/// by this build, so the viewer says so and offers "Open in another app" — a
/// deliberate export, behind a warning, and the only path on which a decrypted
/// copy is written to the device (see [KycShareCache], which deletes it again).
/// "Preview" never opens the share sheet by itself.
Future<void> openKycDocument(
  BuildContext context,
  WidgetRef ref,
  KycDocument document,
) async {
  final load = ref.read(kycProvider.notifier).openDocument(document);

  // The viewer reports a failure itself; this only stops an early one from
  // surfacing as an unhandled error before the viewer is listening.
  load.ignore();

  await KycDocumentViewer.open(
    context,
    title: document.displayName,
    file: load,
  );
}

/// Asks the user to confirm that a decrypted copy of their document may
/// leave the app. Nothing is written or shared unless this returns true.
Future<bool> confirmKycShare(BuildContext context) => showZConfirm(
      context,
      title: 'Open in another app?',
      message:
          'This hands a decrypted copy of your document to the app you pick. '
          'Zusty deletes its own temporary copy straight afterwards, but the '
          'other app may keep one. Only choose an app you trust.',
      confirmLabel: 'Open',
      cancelLabel: 'Cancel',
      icon: Icons.warning_amber_rounded,
    );

/// One verification document, full screen.
///
/// Decoded from memory and evicted from the image cache on close, so the
/// picture of somebody's ID does not linger after they have looked at it.
class KycDocumentViewer extends StatefulWidget {
  const KycDocumentViewer({super.key, required this.title, required this.file});

  final String title;
  final Future<KycFile> file;

  static Future<void> open(
    BuildContext context, {
    required String title,
    required Future<KycFile> file,
  }) {
    final reduced = Motion.reduced(context);

    return Navigator.of(context, rootNavigator: true).push(
      PageRouteBuilder<void>(
        opaque: false,
        barrierColor: Colors.black,
        barrierLabel: 'Close document',
        transitionDuration: reduced ? Duration.zero : Motion.medium,
        reverseTransitionDuration: reduced ? Duration.zero : Motion.fast,
        pageBuilder: (_, _, _) => KycDocumentViewer(title: title, file: file),
        transitionsBuilder: (_, animation, _, child) =>
            FadeTransition(opacity: animation, child: child),
      ),
    );
  }

  @override
  State<KycDocumentViewer> createState() => _KycDocumentViewerState();
}

class _KycDocumentViewerState extends State<KycDocumentViewer> {
  MemoryImage? _image;
  bool _sharing = false;

  @override
  void dispose() {
    _image?.evict();
    super.dispose();
  }

  Future<void> _openElsewhere(KycFile file) async {
    if (_sharing) return;

    final confirmed = await confirmKycShare(context);
    if (!confirmed || !mounted) return;

    setState(() => _sharing = true);

    try {
      await KycShareCache.share(file, subject: widget.title);
    } catch (_) {
      if (mounted) {
        showZSnack(
          context,
          'This document could not be handed to another app.',
          isError: true,
        );
      }
    } finally {
      if (mounted) setState(() => _sharing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: Stack(
        children: [
          Positioned.fill(
            child: FutureBuilder<KycFile>(
              future: widget.file,
              builder: (context, snapshot) {
                if (snapshot.hasError) {
                  final error = snapshot.error;
                  return _ViewerMessage(
                    message: error is ApiFailure
                        ? error.message
                        : 'This document could not be opened.',
                  );
                }

                final file = snapshot.data;
                if (file == null) {
                  return const Center(
                    child: SizedBox.square(
                      dimension: 32,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.5,
                        color: Colors.white,
                      ),
                    ),
                  );
                }

                if (!file.isImage) {
                  final isPdf = file.mimeType == 'application/pdf';
                  return _ViewerMessage(
                    icon: isPdf
                        ? Icons.picture_as_pdf_rounded
                        : Icons.description_outlined,
                    message: isPdf
                        ? 'PDFs cannot be shown inside Zusty yet. Your '
                              'document is safe on our servers; you can open '
                              'this copy in another app on your phone.'
                        : 'This file cannot be shown inside Zusty. You can '
                              'open this copy in another app on your phone.',
                    actionLabel: 'Open in another app',
                    busy: _sharing,
                    onAction: () => _openElsewhere(file),
                  );
                }

                final image = _image ??= MemoryImage(file.bytes);

                return InteractiveViewer(
                  minScale: 1,
                  maxScale: 5,
                  child: Center(
                    child: Image(
                      image: image,
                      fit: BoxFit.contain,
                      gaplessPlayback: true,
                      semanticLabel: '${widget.title}. Pinch to zoom.',
                      errorBuilder: (_, _, _) => const _ViewerMessage(
                        message: 'This image could not be displayed.',
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(
                  AppSpacing.md,
                  AppSpacing.xs,
                  AppSpacing.xs,
                  AppSpacing.xs,
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        widget.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleSmall.copyWith(
                          color: Colors.white,
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    IconButton(
                      onPressed: () => Navigator.of(context).maybePop(),
                      tooltip: 'Close',
                      style: IconButton.styleFrom(
                        backgroundColor: Colors.black54,
                        foregroundColor: Colors.white,
                        minimumSize: const Size.square(AppSpacing.minTouch),
                      ),
                      icon: const Icon(Icons.close_rounded),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ViewerMessage extends StatelessWidget {
  const _ViewerMessage({
    required this.message,
    this.icon = Icons.description_outlined,
    this.actionLabel,
    this.onAction,
    this.busy = false,
  });

  final String message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: Colors.white70, size: 40),
            const SizedBox(height: AppSpacing.sm),
            Text(
              message,
              textAlign: TextAlign.center,
              style: AppTypography.bodyMedium.copyWith(color: Colors.white),
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AppSpacing.md),
              OutlinedButton.icon(
                onPressed: busy ? null : onAction,
                style: OutlinedButton.styleFrom(
                  foregroundColor: Colors.white,
                  side: const BorderSide(color: Colors.white54),
                  minimumSize: const Size(AppSpacing.minTouch, 44),
                ),
                icon: busy
                    ? const SizedBox.square(
                        dimension: 16,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Icon(Icons.open_in_new_rounded, size: 18),
                label: Text(actionLabel!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
