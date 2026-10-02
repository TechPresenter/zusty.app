import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart' show XFile;

import '../../../core/theme/app_theme.dart';
import '../../../models/message.dart';
import '../../../shared/motion/motion.dart';
import '../../../shared/widgets/z_image.dart';

/// The picture inside an image message: rounded, sized from the upload's own
/// proportions, and tappable to open full screen.
///
/// A photo that is still sending (or failed to) has no hosted URL yet — its
/// `mediaUrl` is the file on this device, or a blob URL on web. That copy is
/// drawn from the local bytes, so the sender sees their photo at once instead
/// of a broken-image tile until the upload finishes.
class ChatImage extends StatefulWidget {
  const ChatImage({super.key, required this.message, this.width = 220});

  final Message message;
  final double width;

  @override
  State<ChatImage> createState() => _ChatImageState();
}

class _ChatImageState extends State<ChatImage> {
  Future<Uint8List>? _localBytes;

  bool get _isLocal {
    final url = widget.message.mediaUrl ?? '';
    final pending =
        widget.message.isSending ||
        widget.message.failed ||
        widget.message.id < 0;
    // A server copy is always hosted, even when its URL is relative.
    return pending && url.isNotEmpty && !url.startsWith('http');
  }

  @override
  void initState() {
    super.initState();
    _resolve();
  }

  @override
  void didUpdateWidget(covariant ChatImage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.message.mediaUrl != widget.message.mediaUrl) _resolve();
  }

  void _resolve() {
    _localBytes = _isLocal
        ? XFile(widget.message.mediaUrl!).readAsBytes()
        : null;
  }

  String get _heroTag => 'chat-image-${widget.message.id}';

  @override
  Widget build(BuildContext context) {
    final width = widget.width;
    final height = width / (widget.message.aspectRatio ?? 4 / 3);
    const radius = AppRadius.lgAll;

    final Widget picture;
    final local = _localBytes;

    if (local != null) {
      picture = FutureBuilder<Uint8List>(
        future: local,
        builder: (context, snapshot) => snapshot.hasData
            ? ClipRRect(
                borderRadius: radius,
                child: Image.memory(
                  snapshot.data!,
                  width: width,
                  height: height,
                  fit: BoxFit.cover,
                  gaplessPlayback: true,
                ),
              )
            : ZShimmer(width: width, height: height, borderRadius: radius),
      );
    } else {
      picture = ZImage(
        url: widget.message.mediaUrl ?? widget.message.thumbnailUrl,
        width: width,
        height: height,
        borderRadius: radius,
        fallbackIcon: Icons.broken_image_outlined,
      );
    }

    return Semantics(
      button: true,
      label: 'Photo. Opens full screen',
      child: GestureDetector(
        onTap: () => ChatImageViewer.open(
          context,
          heroTag: _heroTag,
          url: local == null ? widget.message.mediaUrl : null,
          bytes: local,
        ),
        child: Hero(tag: _heroTag, child: picture),
      ),
    );
  }
}

/// Full-screen photo with pinch-to-zoom, over a dimmed backdrop.
class ChatImageViewer extends StatelessWidget {
  const ChatImageViewer({
    super.key,
    required this.heroTag,
    this.url,
    this.bytes,
  });

  final String heroTag;
  final String? url;
  final Future<Uint8List>? bytes;

  static Future<void> open(
    BuildContext context, {
    required String heroTag,
    String? url,
    Future<Uint8List>? bytes,
  }) {
    final reduced = Motion.reduced(context);
    return Navigator.of(context, rootNavigator: true).push(
      PageRouteBuilder(
        opaque: false,
        barrierColor: Colors.black,
        barrierLabel: 'Close photo',
        transitionDuration: reduced ? Duration.zero : Motion.medium,
        reverseTransitionDuration: reduced ? Duration.zero : Motion.fast,
        pageBuilder: (_, _, _) =>
            ChatImageViewer(heroTag: heroTag, url: url, bytes: bytes),
        transitionsBuilder: (_, animation, _, child) =>
            FadeTransition(opacity: animation, child: child),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final Widget image = bytes != null
        ? FutureBuilder<Uint8List>(
            future: bytes,
            builder: (context, snapshot) => snapshot.hasData
                ? Image.memory(snapshot.data!, fit: BoxFit.contain)
                : const SizedBox.shrink(),
          )
        : ZImage(url: url, fit: BoxFit.contain);

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: Stack(
        children: [
          Positioned.fill(
            child: InteractiveViewer(
              minScale: 1,
              maxScale: 4,
              child: Center(
                child: Hero(tag: heroTag, child: image),
              ),
            ),
          ),
          Positioned(
            top: 0,
            right: 0,
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.xs),
                child: IconButton(
                  onPressed: () => Navigator.of(context).maybePop(),
                  tooltip: 'Close',
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.black54,
                    foregroundColor: Colors.white,
                    minimumSize: const Size.square(AppSpacing.minTouch),
                  ),
                  icon: const Icon(Icons.close_rounded),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
