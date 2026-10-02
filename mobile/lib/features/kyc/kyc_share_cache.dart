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
/// deleted on the spot. That copy keeps the file's name, and every document
/// is shared under a name starting [fileNamePrefix], so [purge] can take the
/// verification copies out of that folder and leave other features' shares
/// (an invoice handed to a mail app) alone.
abstract final class KycShareCache {
  /// Our own folder under the temporary directory.
  static const folderName = 'kyc_share';

  /// share_plus's file-provider folder on Android.
  static const sharePlusFolder = 'share_plus';

  /// How every shared document's file name starts. share_plus's Android copy
  /// keeps the name, so this is what marks a copy in its folder as ours.
  static const fileNamePrefix = 'zusty-kyc-';

  /// How long a leftover from an earlier build must have sat untouched before
  /// the startup sweep removes it. Anything newer may belong to a share made
  /// moments ago, whose receiving app is still reading it.
  static const legacyCopyMinAge = Duration(hours: 1);

  /// Overridable so tests can point the cache at a scratch folder.
  @visibleForTesting
  static Future<Directory> Function() temporaryDirectory =
      getTemporaryDirectory;

  /// Overridable so tests can stand in for the platform share sheet.
  @visibleForTesting
  static Future<ShareResult> Function(ShareParams params) shareFiles =
      (params) => SharePlus.instance.share(params);

  /// Overridable so tests can age a leftover copy without waiting an hour.
  @visibleForTesting
  static DateTime Function() clock = DateTime.now;

  /// Whether this process has still to run the startup sweep.
  ///
  /// The first [purge] of the process takes it. That is the one `main()`
  /// starts before the first frame: every other caller (sign-out, the end of
  /// a session, leaving the Verification screen) needs a running app.
  @visibleForTesting
  static bool startupSweepPending = true;

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

      final path = _join(folder.path, sharedFileName(file.fileName));
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

  /// Deletes the decrypted copies this app leaves behind, and nothing else.
  ///
  /// Every call removes our own folder and the verification documents in
  /// share_plus's Android folder (named [fileNamePrefix]…). The first call
  /// of the process — at app start — also clears what earlier builds could
  /// not mark, once it is over [legacyCopyMinAge] old: the `<temp>/<uuid>/`
  /// copies of the old path-less share, and unmarked files in share_plus's
  /// folder, which by then no receiving app is still reading.
  ///
  /// Runs at app start, on sign-out, when the session ends and when the user
  /// leaves the Verification screen. Never throws — a sweep that fails must
  /// not stop the app starting or a user signing out.
  static Future<void> purge() async {
    if (kIsWeb) return;

    // Claimed before the first await, so a purge started meanwhile (a fast
    // sign-out) cannot run the startup sweep a second time.
    final atStartup = startupSweepPending;
    startupSweepPending = false;

    try {
      final temp = await temporaryDirectory();
      if (!await temp.exists()) return;

      await _delete(Directory(_join(temp.path, folderName)));

      final cutoff = clock().subtract(legacyCopyMinAge);
      await _purgeSharePlusCopies(
        Directory(_join(temp.path, sharePlusFolder)),
        unmarkedOlderThan: atStartup ? cutoff : null,
      );
      if (atStartup) await _purgeLegacyCopies(temp, cutoff);
    } catch (_) {
      // Best effort: nothing to recover, and nothing to show the user.
    }
  }

  /// The name a document is shared under: [fileNamePrefix] and a
  /// [safeFileName].
  @visibleForTesting
  static String sharedFileName(String name) =>
      '$fileNamePrefix${safeFileName(name)}';

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

  /// Removes our documents from share_plus's folder — and, when
  /// [unmarkedOlderThan] is given, any other file in it last changed before
  /// then. The folder itself stays: share_plus may be copying into it.
  static Future<void> _purgeSharePlusCopies(
    Directory folder, {
    DateTime? unmarkedOlderThan,
  }) async {
    if (!await folder.exists()) return;

    final doomed = <FileSystemEntity>[];
    await for (final entity in folder.list(followLinks: false)) {
      if (entity is! File) continue;

      if (_baseName(entity).startsWith(fileNamePrefix) ||
          (unmarkedOlderThan != null &&
              await _untouchedSince(entity, unmarkedOlderThan))) {
        doomed.add(entity);
      }
    }

    for (final entity in doomed) {
      await _delete(entity);
    }
  }

  /// Removes the `<temp>/<uuid>/` folders the old path-less share left,
  /// once nothing in them has changed since [cutoff].
  static Future<void> _purgeLegacyCopies(Directory temp, DateTime cutoff) async {
    final doomed = <FileSystemEntity>[];
    await for (final entity in temp.list(followLinks: false)) {
      if (entity is Directory &&
          _legacyCopy.hasMatch(_baseName(entity)) &&
          await _untouchedSince(entity, cutoff)) {
        doomed.add(entity);
      }
    }

    for (final entity in doomed) {
      await _delete(entity);
    }
  }

  /// Whether [entity] — and, for a folder, everything directly in it — was
  /// last changed before [cutoff]. Anything that cannot be read counts as
  /// recent: when in doubt, keep.
  static Future<bool> _untouchedSince(
    FileSystemEntity entity,
    DateTime cutoff,
  ) async {
    try {
      if ((await entity.stat()).modified.isAfter(cutoff)) return false;

      if (entity is Directory) {
        await for (final child in entity.list(followLinks: false)) {
          if ((await child.stat()).modified.isAfter(cutoff)) return false;
        }
      }
      return true;
    } catch (_) {
      return false;
    }
  }

  static Future<void> _delete(FileSystemEntity entity) async {
    try {
      if (await entity.exists()) await entity.delete(recursive: true);
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
