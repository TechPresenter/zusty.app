import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/repositories/safety_repository.dart';
import '../models/safety.dart';
import 'auth_provider.dart';
import 'core_providers.dart';
import 'discovery_provider.dart';
import 'message_provider.dart';
import 'opportunity_provider.dart';

/// Reports and blocking.
final safetyRepositoryProvider = Provider<SafetyRepository>((ref) {
  return SafetyRepository(ref.watch(apiClientProvider));
});

/// The ids of the accounts the signed-in user has blocked, as far as this
/// session knows: filled when the Blocked accounts screen loads, and moved by
/// every block and unblock made here.
///
/// It never fetches anything itself, so a list can watch it to hide a person
/// the moment they are blocked without costing a request. The server already
/// leaves blocked accounts out of search, the inbox and the brief board; this
/// covers the lists already on screen, and responses replayed from the
/// offline cache, until they are fetched again. Belongs to one account: a
/// sign-out or a switch to another account starts it empty.
class BlockedIdsController extends Notifier<Set<int>> {
  @override
  Set<int> build() {
    ref.watch(currentUserProvider.select((user) => user?.id));
    return const <int>{};
  }

  void replaceAll(Iterable<int> ids) => state = Set.unmodifiable(ids);

  /// No change, no new state: every list watching this would otherwise ask
  /// the server again for nothing (a thread poll repeats what it knows).
  void add(int userId) {
    if (state.contains(userId)) return;
    state = Set.unmodifiable({...state, userId});
  }

  void remove(int userId) {
    if (!state.contains(userId)) return;
    state = Set.unmodifiable({...state}..remove(userId));
  }
}

final blockedIdsProvider = NotifierProvider<BlockedIdsController, Set<int>>(
  BlockedIdsController.new,
);

/// Whether [userId] is someone the signed-in user blocked, per
/// [blockedIdsProvider]. Null ids (a one-sided thread) are never blocked.
final isBlockedProvider = Provider.family<bool, int?>((ref, userId) {
  if (userId == null) return false;
  return ref.watch(blockedIdsProvider).contains(userId);
});

/// The Blocked accounts screen. Auto-disposed so each visit asks afresh; a
/// successful load also refreshes [blockedIdsProvider].
class BlockedUsersController
    extends AutoDisposeAsyncNotifier<List<BlockedUser>> {
  @override
  Future<List<BlockedUser>> build() async {
    final users = await ref.watch(safetyRepositoryProvider).blocks();
    ref
        .read(blockedIdsProvider.notifier)
        .replaceAll(users.map((user) => user.userId));
    return users;
  }
}

final blockedUsersProvider =
    AsyncNotifierProvider.autoDispose<BlockedUsersController, List<BlockedUser>>(
      BlockedUsersController.new,
    );

/// Report, block and unblock, refreshing every view a block changes.
class SafetyActions {
  SafetyActions(this._ref);

  final Ref _ref;

  SafetyRepository get _repository => _ref.read(safetyRepositoryProvider);

  Future<String> report({
    required ReportTarget target,
    required int id,
    required String reason,
    String? note,
  }) => _repository.report(target: target, id: id, reason: reason, note: note);

  Future<String> block(int userId) async {
    final message = await _repository.block(userId);
    _ref.read(blockedIdsProvider.notifier).add(userId);
    _refresh();
    return message;
  }

  Future<String> unblock(int userId) async {
    final message = await _repository.unblock(userId);
    _ref.read(blockedIdsProvider.notifier).remove(userId);
    _refresh();
    return message;
  }

  /// A block changes who appears in search, on the home rails, in the inbox
  /// and on the brief board, and what a profile offers. Every one of those
  /// lists asks the server again; the autoDispose ones only if on screen.
  void _refresh() {
    _ref
      ..invalidate(blockedUsersProvider)
      ..invalidate(conversationsProvider)
      ..invalidate(homeFeedProvider)
      ..invalidate(exploreFeedProvider)
      ..invalidate(searchResultsProvider)
      ..invalidate(creatorSearchProvider)
      ..invalidate(favoritesProvider)
      ..invalidate(creatorProfileProvider)
      ..invalidate(opportunityFeedProvider)
      ..invalidate(opportunityProvider);
  }
}

final safetyActionsProvider = Provider<SafetyActions>((ref) {
  return SafetyActions(ref);
});
