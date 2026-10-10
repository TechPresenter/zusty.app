import 'dart:typed_data';

import '../../core/network/api_endpoints.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/api_response.dart';
import '../../core/utils/json.dart';
import '../../models/creator.dart';
import '../../models/creator_tools.dart';
import 'base_repository.dart';

/// The creator's own packages, portfolio and linked social accounts
/// (`/me/creator/packages`, `/portfolio`, `/social`).
///
/// The read side of these rows for everyone else is the public profile, on
/// [CreatorRepository]. That payload is what a brand sees — active packages,
/// approved media — so the editors read their own lists, which also carry
/// hidden packages, items still in review, and the limits the API enforces.
///
/// Writes go straight to the API and are never replayed from a queue, for
/// the same reason as [CreatorFieldsRepository]: an administrator can lock a
/// section while the app is offline, and an edit refused minutes later, out
/// of sight, is worse than one refused now.
class CreatorToolsRepository extends BaseRepository {
  CreatorToolsRepository(super.client);

  /// For the reload after a write: a copy [ApiClient] replays from storage
  /// predates the write and would put back what was just changed, so with
  /// [fresh] it counts as a failure (which the reload ignores) instead.
  static void _requireLive(ApiResponse response, {required bool fresh}) {
    if (fresh && response.fromCache) throw const OfflineFailure();
  }

  // ---- Packages ----------------------------------------------------------

  Future<OwnPackages> packages({bool fresh = false}) => readThrough(
    () async {
      final response = await client.get(ApiEndpoints.creatorPackages);
      _requireLive(response, fresh: fresh);
      return OwnPackages.fromJson(response.dataMap);
    },
    'creator.mypackages',
    (json) => OwnPackages.fromJson(asMap(json)),
  );

  Future<OwnPackage> createPackage(PackageDraft draft) async {
    final response = await client.post(
      ApiEndpoints.creatorPackages,
      body: draft.toJson(),
    );
    return OwnPackage.fromJson(response.mapAt('package'));
  }

  /// Sent whole and unpruned: an emptied description goes up as null and an
  /// emptied "what's included" as `[]`, which is how the API is told to clear
  /// them rather than keep the old copy.
  Future<OwnPackage> updatePackage(int id, PackageDraft draft) async {
    final response = await client.put(
      ApiEndpoints.creatorPackage(id),
      body: draft.toJson(),
      prune: false,
    );
    return OwnPackage.fromJson(response.mapAt('package'));
  }

  /// Hides a package from the profile, or shows it again. A hidden package
  /// keeps its bookings; brands just cannot pick it for a new one.
  Future<OwnPackage> setPackageActive(int id, {required bool active}) async {
    final response = await client.put(
      ApiEndpoints.creatorPackage(id),
      body: {'is_active': active},
    );
    return OwnPackage.fromJson(response.mapAt('package'));
  }

  Future<void> deletePackage(int id) =>
      client.delete(ApiEndpoints.creatorPackage(id));

  // ---- Portfolio ---------------------------------------------------------

  /// Newest first, every state included.
  ///
  /// `locked` and `maxItems` on the result's side come from `meta`, which is
  /// where a paginated endpoint carries anything beside its rows. `maxItems`
  /// is `creator.max_portfolio_items`; 0 means no ceiling.
  Future<OwnPortfolioPage> portfolio({
    int page = 1,
    int perPage = 30,
    bool fresh = false,
  }) => readThrough(
    () async {
      final response = await client.get(
        ApiEndpoints.creatorPortfolioItems,
        query: {'page': page, 'per_page': perPage},
      );
      _requireLive(response, fresh: fresh);
      return (
        page: Paginated(
          items: response.dataList.map(OwnPortfolioItem.fromJson).toList(),
          pagination: response.pagination,
        ),
        locked: response.meta['locked'] == true,
        maxItems: Json.integer(response.meta['max_items']),
      );
    },
    'creator.myportfolio:$page',
    (json) {
      final items = asRows(json).map(OwnPortfolioItem.fromJson).toList();
      return (
        page: Paginated(
          items: items,
          pagination: Pagination.singlePage(items.length),
        ),
        locked: false,
        maxItems: 0,
      );
    },
  );

  /// Uploads the picture and files it in one request.
  ///
  /// The picture is what the grid and the viewer show, for every kind: a reel
  /// or a video arrives as its cover, and plays from [PortfolioDraft.externalUrl].
  /// Bytes rather than a path, so the same call works on the web build.
  Future<OwnPortfolioItem> addPortfolioItem(
    PortfolioDraft draft, {
    required Uint8List bytes,
    required String fileName,
  }) async {
    final response = await client.uploadBytes(
      ApiEndpoints.creatorPortfolioItems,
      field: 'media',
      bytes: bytes,
      fileName: fileName,
      fields: draft.toFields(),
    );
    return OwnPortfolioItem.fromJson(response.mapAt('item'));
  }

  Future<void> deletePortfolioItem(int id) =>
      client.delete(ApiEndpoints.creatorPortfolioItem(id));

  // ---- Linked social accounts ---------------------------------------------

  Future<SocialAccountsBook> socialAccounts() => readThrough(
    () async {
      final response = await client.get(ApiEndpoints.creatorSocial);
      return SocialAccountsBook.fromJson(response.dataMap);
    },
    'creator.mysocial',
    (json) => SocialAccountsBook.fromJson(asMap(json)),
  );

  /// Links an account, or updates the one [SocialAccountDraft.id] names.
  /// Every social write answers with the whole list, in order.
  Future<List<SocialAccount>> saveSocialAccount(SocialAccountDraft draft) async {
    final response = await client.put(
      ApiEndpoints.creatorSocial,
      body: draft.toJson(),
    );
    return response.listAt('social_accounts').map(SocialAccount.fromJson).toList();
  }

  Future<List<SocialAccount>> deleteSocialAccount(int id) async {
    final response = await client.delete(ApiEndpoints.creatorSocialAccount(id));
    return response.listAt('social_accounts').map(SocialAccount.fromJson).toList();
  }

  /// [ids] in the order they should appear on the profile.
  Future<List<SocialAccount>> reorderSocialAccounts(List<int> ids) async {
    final response = await client.put(
      ApiEndpoints.creatorSocialOrder,
      body: {'ids': ids},
    );
    return response.listAt('social_accounts').map(SocialAccount.fromJson).toList();
  }
}
