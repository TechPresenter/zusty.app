import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_response.dart';
import '../data/repositories/creator_tools_repository.dart';
import '../models/creator.dart';
import '../models/creator_tools.dart';
import 'core_providers.dart';
import 'discovery_provider.dart';

final creatorToolsRepositoryProvider = Provider<CreatorToolsRepository>((ref) {
  return CreatorToolsRepository(ref.watch(apiClientProvider));
});

/// Everything that shows the creator's packages, portfolio or accounts to
/// someone else, told to refetch after the creator changes one.
///
/// The server recomputes the "from" price, the follower total and the
/// completion score on every write, so the honest way to show the new
/// numbers is to ask again. Families are invalidated whole: the profile is
/// cached under both the handle and the numeric id, and the editor knows
/// neither for certain.
void refreshCreatorPublicViews(Ref ref) {
  ref
    ..invalidate(creatorProfileProvider)
    ..invalidate(creatorCompletionProvider)
    ..invalidate(portfolioProvider);
}

/// Reloads [load] into [state] without letting a failed refresh undo a
/// write that already succeeded: the change is on the server, so the list
/// keeps showing it, and pull-to-refresh is there to try again.
Future<void> _quietReload<T>(
  Future<T> Function() load,
  void Function(T value) apply,
) async {
  try {
    apply(await load());
  } catch (_) {
    // Deliberately quiet. See the doc comment above.
  }
}

// =====================================================================
// Packages
// =====================================================================

/// The creator's packages, hidden ones included, with the API's limits.
final myPackagesProvider =
    AsyncNotifierProvider.autoDispose<MyPackagesController, OwnPackages>(
      MyPackagesController.new,
    );

class MyPackagesController extends AutoDisposeAsyncNotifier<OwnPackages> {
  CreatorToolsRepository get _repo => ref.read(creatorToolsRepositoryProvider);

  @override
  Future<OwnPackages> build() =>
      ref.watch(creatorToolsRepositoryProvider).packages();

  Future<void> refresh() async {
    state = await AsyncValue.guard(_repo.packages);
  }

  /// Errors propagate untouched, so the form can put a validation message
  /// beside the field the server named.
  Future<OwnPackage> create(PackageDraft draft) async {
    final created = await _repo.createPackage(draft);
    _apply((list) => [...list, created]);
    await _afterWrite();
    return created;
  }

  Future<OwnPackage> save(int id, PackageDraft draft) async {
    final saved = await _repo.updatePackage(id, draft);
    _apply((list) => [for (final p in list) p.id == id ? saved : p]);
    await _afterWrite();
    return saved;
  }

  Future<void> setActive(int id, {required bool active}) async {
    final saved = await _repo.setPackageActive(id, active: active);
    _apply((list) => [for (final p in list) p.id == id ? saved : p]);
    await _afterWrite();
  }

  Future<void> remove(int id) async {
    await _repo.deletePackage(id);
    _apply((list) => list.where((p) => p.id != id).toList());
    await _afterWrite();
  }

  /// Shows the server's answer at once, before the reload lands.
  void _apply(List<OwnPackage> Function(List<OwnPackage>) change) {
    final current = state.valueOrNull;
    if (current == null) return;

    state = AsyncData(
      OwnPackages(
        packages: change(current.packages),
        locked: current.locked,
        limits: current.limits,
      ),
    );
  }

  Future<void> _afterWrite() async {
    refreshCreatorPublicViews(ref);
    await _quietReload(
      () => _repo.packages(fresh: true),
      (value) => state = AsyncData(value),
    );
  }
}

// =====================================================================
// Portfolio
// =====================================================================

/// The portfolio editor's state: the pages loaded so far.
class MyPortfolioState {
  const MyPortfolioState({
    this.page = const Paginated.empty(),
    this.locked = false,
    this.maxItems = 0,
    this.loadingMore = false,
  });

  /// The first page of [result], as a fresh state.
  factory MyPortfolioState.first(OwnPortfolioPage result) => MyPortfolioState(
    page: result.page,
    locked: result.locked,
    maxItems: result.maxItems,
  );

  final Paginated<OwnPortfolioItem> page;
  final bool locked;

  /// `creator.max_portfolio_items`; 0 means no ceiling.
  final int maxItems;
  final bool loadingMore;

  List<OwnPortfolioItem> get items => page.items;

  /// Every item the creator has, loaded or not — the server's count.
  int get total => page.total;

  /// At the ceiling: the server refuses another item until one is removed.
  bool get isFull => maxItems > 0 && total >= maxItems;

  MyPortfolioState copyWith({
    Paginated<OwnPortfolioItem>? page,
    bool? locked,
    int? maxItems,
    bool? loadingMore,
  }) => MyPortfolioState(
    page: page ?? this.page,
    locked: locked ?? this.locked,
    maxItems: maxItems ?? this.maxItems,
    loadingMore: loadingMore ?? this.loadingMore,
  );
}

/// [pagination] with its total moved by [delta], for the moment between a
/// write and the reload that brings the server's own count.
Pagination _shiftTotal(Pagination pagination, int delta) => Pagination(
  total: pagination.total + delta < 0 ? 0 : pagination.total + delta,
  perPage: pagination.perPage,
  currentPage: pagination.currentPage,
  lastPage: pagination.lastPage,
  hasMore: pagination.hasMore,
);

final myPortfolioProvider =
    AsyncNotifierProvider.autoDispose<MyPortfolioController, MyPortfolioState>(
      MyPortfolioController.new,
    );

class MyPortfolioController extends AutoDisposeAsyncNotifier<MyPortfolioState> {
  CreatorToolsRepository get _repo => ref.read(creatorToolsRepositoryProvider);

  @override
  Future<MyPortfolioState> build() async => MyPortfolioState.first(
    await ref.watch(creatorToolsRepositoryProvider).portfolio(),
  );

  Future<void> refresh() async {
    state = await AsyncValue.guard(
      () async => MyPortfolioState.first(await _repo.portfolio()),
    );
  }

  /// The next page, appended. A failure leaves what is shown and rethrows,
  /// so the screen can say so without losing the grid.
  Future<void> loadMore() async {
    final current = state.valueOrNull;
    if (current == null || current.loadingMore || !current.page.hasMore) return;

    state = AsyncData(current.copyWith(loadingMore: true));

    try {
      final next = await _repo.portfolio(page: current.page.nextPage);
      state = AsyncData(
        current.copyWith(page: current.page.merge(next.page), loadingMore: false),
      );
    } catch (_) {
      state = AsyncData(current.copyWith(loadingMore: false));
      rethrow;
    }
  }

  Future<OwnPortfolioItem> add(
    PortfolioDraft draft, {
    required Uint8List bytes,
    required String fileName,
  }) async {
    final item = await _repo.addPortfolioItem(
      draft,
      bytes: bytes,
      fileName: fileName,
    );

    final current = state.valueOrNull;
    if (current != null) {
      state = AsyncData(
        current.copyWith(
          page: Paginated(
            items: [item, ...current.items],
            pagination: _shiftTotal(current.page.pagination, 1),
          ),
        ),
      );
    }

    await _afterWrite();
    return item;
  }

  Future<void> remove(int id) async {
    await _repo.deletePortfolioItem(id);

    final current = state.valueOrNull;
    if (current != null) {
      final page = current.page.removeWhere((i) => i.id == id);
      state = AsyncData(
        current.copyWith(
          page: Paginated(
            items: page.items,
            pagination: _shiftTotal(page.pagination, -1),
          ),
        ),
      );
    }

    await _afterWrite();
  }

  /// The change above shows at once; then the first page is read again.
  ///
  /// Paging is by offset, newest first, so an add or a remove shifts every
  /// later page by one: kept as it was, "Show more" would repeat an item
  /// after an add and skip one after a remove. Starting again from page 1
  /// gives the server's own count and a cursor that matches it. A failed
  /// reload keeps the change, which is already on the server.
  Future<void> _afterWrite() async {
    refreshCreatorPublicViews(ref);
    await _quietReload(
      () => _repo.portfolio(fresh: true),
      (first) => state = AsyncData(MyPortfolioState.first(first)),
    );
  }
}

// =====================================================================
// Linked social accounts
// =====================================================================

final mySocialAccountsProvider =
    AsyncNotifierProvider.autoDispose<
      MySocialAccountsController,
      SocialAccountsBook
    >(MySocialAccountsController.new);

class MySocialAccountsController
    extends AutoDisposeAsyncNotifier<SocialAccountsBook> {
  CreatorToolsRepository get _repo => ref.read(creatorToolsRepositoryProvider);

  @override
  Future<SocialAccountsBook> build() =>
      ref.watch(creatorToolsRepositoryProvider).socialAccounts();

  Future<void> refresh() async {
    state = await AsyncValue.guard(_repo.socialAccounts);
  }

  Future<void> save(SocialAccountDraft draft) async {
    _adopt(await _repo.saveSocialAccount(draft));
  }

  Future<void> remove(int id) async {
    _adopt(await _repo.deleteSocialAccount(id));
  }

  /// Moves the row at once and sends the new order; on a refusal the old
  /// order comes back and the error is rethrown for the screen to show.
  Future<void> reorder(List<SocialAccount> ordered) async {
    final before = state.valueOrNull;
    if (before == null) return;

    state = AsyncData(before.withAccounts(ordered));

    try {
      _adopt(await _repo.reorderSocialAccounts([for (final a in ordered) a.id]));
    } catch (_) {
      state = AsyncData(before);
      rethrow;
    }
  }

  /// Every social write answers with the whole list, so it replaces ours.
  void _adopt(List<SocialAccount> accounts) {
    final current = state.valueOrNull ?? const SocialAccountsBook();
    state = AsyncData(current.withAccounts(accounts));
    refreshCreatorPublicViews(ref);
  }
}
