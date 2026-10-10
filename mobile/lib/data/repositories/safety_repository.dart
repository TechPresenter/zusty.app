import '../../core/network/api_endpoints.dart';
import '../../models/safety.dart';
import 'base_repository.dart';

/// Reports to the moderation queue (`POST /reports`) and the accounts the
/// user blocked (`/me/blocks`).
///
/// Nothing here goes through a fixture. A report that only pretended to be
/// filed would tell someone help was coming when it was not, and a made-up
/// block list would hide or show real people. Every failure surfaces to the
/// caller as an ApiFailure carrying the server's own message.
class SafetyRepository extends BaseRepository {
  SafetyRepository(super.client);

  /// Files a report and returns the server's acknowledgement, which is what
  /// the sheet shows. A repeat while the first report is still open comes
  /// back as a 409 `already_reported` with its own explanation.
  Future<String> report({
    required ReportTarget target,
    required int id,
    required String reason,
    String? note,
  }) async {
    final response = await client.post(
      ApiEndpoints.reports,
      body: {
        'reportable_type': target.apiValue,
        'reportable_id': id,
        'reason': reason,
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
      },
    );

    return response.message.isNotEmpty
        ? response.message
        : 'Thank you. Our moderation team will review this report.';
  }

  /// Pages of [blocksPageSize]: the most `GET /me/blocks` returns at once.
  static const blocksPageSize = 200;

  /// Never more pages than this. The server caps an account at 1000 blocks
  /// (BlockService::MAX_BLOCKS), five pages; the margin covers a cap raised
  /// later without letting a misbehaving server loop forever.
  static const blocksMaxPages = 10;

  /// Everyone the user blocked, newest first: every page, so the oldest
  /// blocks can still be seen and lifted. This list is the only way to
  /// unblock someone whose profile no longer opens (a creator who blocked
  /// the user back).
  Future<List<BlockedUser>> blocks() async {
    final users = <BlockedUser>[];
    final seen = <int>{};

    for (var page = 1; page <= blocksMaxPages; page++) {
      final response = await client.get(
        ApiEndpoints.myBlocks,
        query: {'page': page, 'per_page': blocksPageSize},
      );

      // A block made or lifted between two pages shifts the rest by one; the
      // id set keeps a shifted row from being listed twice.
      for (final user in response.dataList.map(BlockedUser.fromJson)) {
        if (seen.add(user.userId)) users.add(user);
      }

      if (response.dataList.isEmpty || !response.pagination.hasMore) break;
    }

    return users;
  }

  /// Blocks [userId] and returns the server's message. Blocking someone
  /// already blocked is not an error.
  Future<String> block(int userId) async {
    final response = await client.post(
      ApiEndpoints.myBlocks,
      body: {'user_id': userId},
    );

    return response.message.isNotEmpty ? response.message : 'Blocked.';
  }

  /// Lifts a block. Unblocking someone who is not blocked is not an error.
  Future<String> unblock(int userId) async {
    final response = await client.delete(ApiEndpoints.myBlock(userId));

    return response.message.isNotEmpty ? response.message : 'Unblocked.';
  }
}
