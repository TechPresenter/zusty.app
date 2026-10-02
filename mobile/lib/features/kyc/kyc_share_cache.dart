import 'dart:io';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../data/repositories/kyc_repository.dart' show KycFile;

/// The only place a decrypted verification document may touch the disk, and
/// the sweep that wipes it.
///
/// Documents are fetched into memory and shown from memory. The one exception
/// is "Open in another app": the system share sheet needs a real file, so the
/// bytes are written to a folder of their own under the app's temporary
/// directory (`<temp>/kyc_share/<random>/`), handed over by path, and that
/// folder is deleted as soon as the share sheet returns.
///
/// Handing over a path matters: given bytes without one, share_plus writes its
/// own copy to `<temp>/<uuid>/` and never removes it. On Android the plugin
/// also copies every shared file into `<cache>/share_plus/` for its file
/// provider, and only clears that folder at the next share — the receiving
/// app may still be reading it when the share sheet returns, so it cannot be
/// deleted on the spot. [purge] removes both at app start and on sign-out.
abstract final class KycShareCache {
  /// Our own folder under the temporary directory.
  static const folderName = 'kyc_share';

  /// share_plus's file-provider folder on Android.
  static const sharePlusFolder = 'share_plus';

  /// Overridable so tests can point the cache at a scratch folder.
  @visibleForTesting
  static Future<Directory> Function() temporaryDirectory =
      getTemporaryDirectory;

  /// Overridable so tests can stand in for the platform share sheet.
  @visibleForTesting
  static Future<ShareResult> Function(ShareParams params) shareFiles =
      (params) => SharePlus.instance.share(params);

  /// The folder share_plus creates for a path-less file: a bare UUID v4.
  static final _legacyCopy = RegExp(
    r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
    caseSensitive: false,
  );

  /// Hands [file] to the share sheet, then deletes the temporary copy.
  ///
  /// Only ever called from an explicit "Open in another app" action, after
  /// the user has been told a decrypted copy leaves the app.
  static Future<void> share(KycFile file, {required String subject}) async {
    if (kIsWeb) {
      // No file system: the browser shares the bytes straight from memory.
      await shareFiles(
        ShareParams(
          files: [
            XFile.fromData(
              file.bytes,
              mimeType: file.mimeType,
              name: file.fileName,
            ),
          ],
          fileNameOverrides: [file.fileName],
          subject: subject,
        ),
      );
      return;
    }

    final temp = await temporaryDirectory();
    final folder = Directory(
      _join(_join(temp.path, folderName), _randomName()),
    );

    try {
      await folder.create(recursive: true);

      final path = _join(folder.path, safeFileName(file.fileName));
      await File(path).writeAsBytes(file.bytes, flush: true);

      await shareFiles(
        ShareParams(
          files: [XFile(path, mimeType: file.mimeType)],
          subject: subject,
        ),
      );
    } finally {
      await _delete(folder);
    }
  }

  /// Deletes every decrypted copy this app could have left behind: our own
  /// folder, share_plus's Android folder, and the `<temp>/<uuid>/` copies
  /// earlier builds let share_plus write.
  ///
  /// Never throws — a sweep that fails must not stop the app starting or a
  /// user signing out.
  static Future<void> purge() async {
    if (kIsWeb) return;

    try {
      final temp = await temporaryDirectory();
      if (!await temp.exists()) return;

      await _delete(Directory(_join(temp.path, folderName)));
      await _delete(Directory(_join(temp.path, sharePlusFolder)));

      await for (final entity in temp.list(followLinks: false)) {
        if (entity is Directory && _legacyCopy.hasMatch(_baseName(entity))) {
          await _delete(entity);
        }
      }
    } catch (_) {
      // Best effort: nothing to recover, and nothing to show the user.
    }
  }

  /// A file name that cannot leave its folder.
  @visibleForTesting
  static String safeFileName(String name) {
    final cleaned = name
        .replaceAll(RegExp(r'[\\/:*?"<>|\x00-\x1F]+'), '-')
        .trim();
    if (cleaned.isEmpty || cleaned == '.' || cleaned == '..') {
      return 'document';
    }
    return cleaned.length > 120 ? cleaned.substring(cleaned.length - 120) : cleaned;
  }

  static Future<void> _delete(Directory folder) async {
    try {
      if (await folder.exists()) await folder.delete(recursive: true);
    } catch (_) {
      // Locked by the platform for a moment; the next sweep gets it.
    }
  }

  static String _randomName() {
    final random = Random.secure();
    return List.generate(
      16,
      (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
    ).join();
  }

  static String _join(String parent, String child) =>
      parent.endsWith(Platform.pathSeparator)
          ? '$parent$child'
          : '$parent${Platform.pathSeparator}$child';

  static String _baseName(FileSystemEntity entity) {
    final segments = entity.uri.pathSegments.where((s) => s.isNotEmpty);
    return segments.isEmpty ? '' : segments.last;
  }
}
