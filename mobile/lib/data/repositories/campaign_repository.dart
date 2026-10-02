import '../../core/network/api_endpoints.dart';
import '../../core/network/api_response.dart';
import '../../core/utils/json.dart';
import '../../models/campaign.dart';
import 'base_repository.dart';

/// Brand campaigns — the one-to-many briefs creators apply to or are invited
/// onto (`campaigns` and `campaign_recipients`).
class CampaignRepository extends BaseRepository {
  CampaignRepository(super.client);

  /// [status] accepts a [CampaignStatus] value or `all`.
  Future<Paginated<Campaign>> list({String? status, int page = 1}) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.campaigns,
            query: {'status': status, 'page': page, 'per_page': 20},
          );
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('campaigns');
          return Paginated(
            items: rows.map(Campaign.fromJson).toList(),
            pagination: response.pagination,
          );
        },
        'campaigns',
        (json) {
          var rows = asRows(json).map(Campaign.fromJson).toList();
          if (status != null && status != 'all') {
            rows = rows.where((c) => c.status.value == status).toList();
          }
          return Paginated(
            items: rows,
            pagination: Pagination.singlePage(rows.length),
          );
        },
      );

  Future<Campaign> detail(int id) => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.campaign(id));
          return Campaign.fromJson(
            response.maybeMapAt('campaign') ?? response.dataMap,
          );
        },
        'campaign.detail:$id',
        (json) => Campaign.fromJson(asMap(json)),
      );

  Future<Campaign> save({
    int? id,
    required String title,
    required String description,
    required String platform,
    required String deliverableType,
    required int postsCount,
    required double budgetMin,
    required double budgetMax,
    int? categoryId,
    DateTime? startsOn,
    DateTime? endsOn,
    CampaignRequirements requirements = const CampaignRequirements(),
    List<String> referenceUrls = const [],
    bool isPublic = false,
    bool publish = false,
  }) {
    final body = {
      'title': title,
      'description': description,
      'platform': platform,
      'deliverable_type': deliverableType,
      'posts_count': postsCount,
      'budget_min': budgetMin,
      'budget_max': budgetMax,
      'category_id': ?categoryId,
      if (startsOn != null) 'starts_on': _date(startsOn),
      if (endsOn != null) 'ends_on': _date(endsOn),
      'requirements': requirements.toJson(),
      'reference_urls': referenceUrls,
      'is_public': isPublic,
      'status': publish ? 'open' : 'draft',
    };

    return writeThrough(
      () async {
        final response = id == null
            ? await client.post(ApiEndpoints.campaigns, body: body)
            : await client.put(ApiEndpoints.campaign(id), body: body);
        var campaign = Campaign.fromJson(
          response.maybeMapAt('campaign') ?? response.dataMap,
        );

        // `status` is not part of the create/update contract — the server
        // whitelists its columns and drops it, so a campaign saved with
        // publish:true stayed a draft while the form said it was live.
        // Publishing is its own endpoint.
        if (publish && campaign.id > 0 && campaign.status != CampaignStatus.open) {
          await this.publish(campaign.id);
          campaign = await detail(campaign.id);
        }

        return campaign;
      },
      'campaign.detail:${id ?? 1}',
      (json) => Campaign.fromJson({
        ...asMap(json),
        ...body,
        'id': id ?? 99,
        'code': id == null ? 'ZC1000${DateTime.now().second}' : 'ZC10004$id',
      }),
    );
  }

  Future<void> publish(int id) => writeThrough(
        () async {
          await client.post(ApiEndpoints.campaignPublish(id));
        },
        'noop',
        (_) {},
      );

  Future<void> close(int id) => writeThrough(
        () async {
          await client.post(ApiEndpoints.campaignClose(id));
        },
        'noop',
        (_) {},
      );

  Future<void> delete(int id) => writeThrough(
        () async {
          await client.delete(ApiEndpoints.campaign(id));
        },
        'noop',
        (_) {},
      );

  /// Invites creators onto a campaign.
  ///
  /// The endpoint invites one creator per call (`creator_id`), so a multi-select
  /// is issued as one call each. Sending `creator_ids` was rejected outright,
  /// which meant no invitation ever left the app.
  Future<void> invite(int campaignId, List<int> creatorIds) => writeThrough(
        () async {
          for (final creatorId in creatorIds) {
            await client.post(
              ApiEndpoints.campaignInvite(campaignId),
              body: {'creator_id': creatorId},
            );
          }
        },
        'noop',
        (_) {},
      );

  /// Moves one applicant along the pipeline (shortlist, hire, decline).
  Future<void> setRecipientStatus(
    int campaignId,
    int recipientId,
    RecipientStatus status,
  ) =>
      writeThrough(
        () async {
          await client.put(
            ApiEndpoints.campaignRecipient(campaignId, recipientId),
            body: {'status': status.name},
          );
        },
        'noop',
        (_) {},
      );

  // -- Creator side ----------------------------------------------------------
  //
  // The same `/campaigns` resource seen from the other side of the
  // marketplace: the public brief board, applying, and tracking what came of
  // it. Kept in this repository because it is the same table and the same
  // [Campaign] model; the brand methods above are untouched by it.

  /// The public brief board (`scope=open`): open and in-progress public
  /// campaigns. [sort] is one of `newest`, `ending_soon`, `budget_high`,
  /// `budget_low`, `popular`; the server maps it through a fixed list, so an
  /// unknown value falls back to newest rather than failing.
  Future<Paginated<Campaign>> openCampaigns({
    String? q,
    int? categoryId,
    String? platform,
    String sort = 'newest',
    int page = 1,
    int perPage = 20,
  }) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.campaigns,
            query: {
              'scope': 'open',
              'q': q?.trim(),
              'category_id': categoryId,
              'platform': platform,
              'sort': sort,
              'page': page,
              'per_page': perPage,
            },
          );
          return Paginated(
            items: response.dataList.map(Campaign.fromJson).toList(),
            pagination: response.pagination,
          );
        },
        'campaigns.open',
        (json) {
          final rows = asRows(json).map(Campaign.fromJson).toList();
          return Paginated(
            items: rows,
            pagination: Pagination.singlePage(rows.length),
          );
        },
      );

  /// Applies to campaign [id] and returns the application as stored.
  ///
  /// [portfolioLinks] is optional; blanks are dropped here so an empty
  /// optional field never reaches the server as an invalid URL. The server
  /// re-validates every link and reports a bad one as `portfolio_links.N`.
  Future<MyApplication> apply(
    int id, {
    required double quotedAmount,
    required String pitch,
    List<String> portfolioLinks = const [],
  }) {
    final links = [
      for (final link in portfolioLinks)
        if (link.trim().isNotEmpty) link.trim(),
    ];

    return writeThrough(
      () async {
        final response = await client.post(
          ApiEndpoints.campaignApply(id),
          body: {
            'quoted_amount': quotedAmount,
            'pitch': pitch.trim(),
            if (links.isNotEmpty) 'portfolio_links': links,
          },
        );
        return MyApplication.fromJson(
          response.maybeMapAt('application') ?? response.dataMap,
        );
      },
      'campaign.apply',
      (json) => MyApplication.fromJson(asMap(json)),
    );
  }

  /// The creator's applications, newest first.
  ///
  /// [statuses] narrows to one or more application statuses in a single call
  /// (the server takes them comma-separated), so a tab that groups several —
  /// "Active" is invited + applied + shortlisted — is one request.
  Future<ApplicationList> myApplications({
    Iterable<RecipientStatus> statuses = const [],
    int page = 1,
    int perPage = 50,
  }) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.myApplications,
            query: {
              'status': statuses.map((s) => s.name).join(','),
              'page': page,
              'per_page': perPage,
            },
          );
          final counts = <RecipientStatus, int>{};
          final rawCounts = response.meta['counts'];
          if (rawCounts is Map) {
            rawCounts.forEach((key, value) {
              final status = RecipientStatus.values
                  .where((s) => s.name == key.toString())
                  .firstOrNull;
              if (status != null) counts[status] = Json.integer(value);
            });
          }
          final pagination = response.pagination;
          return ApplicationList(
            items: response.dataList.map(CampaignApplication.fromJson).toList(),
            counts: counts,
            hasMore: pagination.hasMore,
            total: pagination.total,
          );
        },
        'me.applications',
        (json) {
          final rows = asRows(json).map(CampaignApplication.fromJson).toList();
          return ApplicationList(items: rows, total: rows.length);
        },
      );

  static String _date(DateTime value) =>
      value.toIso8601String().split('T').first;
}
