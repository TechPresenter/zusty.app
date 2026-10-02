import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_endpoints.dart';
import '../../../core/utils/json.dart';
import '../../../data/repositories/base_repository.dart';
import '../../../models/campaign.dart';
import '../../../providers/campaign_provider.dart';
import '../../../providers/core_providers.dart';

/// The brand-side campaign calls the shared [CampaignRepository] does not
/// make, kept beside the brand screens that need them.
///
/// Three gaps in the shared path made the brand form lose data:
///
///  * `brief_url` is part of the create/update contract but was never sent,
///    so a brief document link could not be set from the app at all.
///  * `requirements` is free JSON, and the shared save writes back only the
///    five targeting keys the old form owned. Saving a seeded or admin-made
///    brief silently dropped its shot list, usage rights, exclusivity, cover
///    image and the rest. [save] here merges instead — see [requirementsOf].
///  * Clearing the category or the dates on an existing brief was never sent,
///    because absent keys are "unchanged" on update. They are sent as null.
///
/// It also carries the decline reason, which the endpoint accepts and shows
/// to the creator, but the shared pipeline call never sent.
class BrandCampaignApi extends BaseRepository {
  BrandCampaignApi(super.client);

  /// The `requirements` keys the brand wizard edits. Every other key in that
  /// JSON is carried through untouched when the brief is saved.
  static const formRequirementKeys = {
    'min_followers',
    'cities',
    'gender',
    'min_rating',
    'languages',
  };

  /// The brief's `requirements` exactly as stored, including keys the app's
  /// model does not know about.
  Future<Map<String, dynamic>> requirementsOf(int id) => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.campaign(id));
          final campaign = response.maybeMapAt('campaign') ?? response.dataMap;
          return Json.map(campaign['requirements']);
        },
        'campaign.detail:$id',
        (json) => Json.map(asMap(json)['requirements']),
      );

  /// Creates (when [id] is null) or updates a brief, always as it stands —
  /// publishing is its own endpoint, so this never changes the status.
  ///
  /// [requirements] is sent whole; build it with [mergeRequirements] when
  /// editing so keys the form does not show survive the save.
  Future<Campaign> save({
    int? id,
    required String title,
    required String description,
    required String platform,
    required String deliverableType,
    required int postsCount,
    required double budgetMin,
    required double budgetMax,
    required Map<String, dynamic> requirements,
    int? categoryId,
    DateTime? startsOn,
    DateTime? endsOn,
    List<String> referenceUrls = const [],
    String? briefUrl,
    bool isPublic = false,
  }) {
    final body = <String, dynamic>{
      'title': title,
      'description': description,
      'platform': platform,
      'deliverable_type': deliverableType,
      'posts_count': postsCount,
      'budget_min': budgetMin,
      'budget_max': budgetMax,
      // Nulls are deliberate: on update an absent key means "unchanged", so
      // a cleared category, window or brief link has to be sent as null.
      'category_id': categoryId,
      'starts_on': startsOn == null ? null : _date(startsOn),
      'ends_on': endsOn == null ? null : _date(endsOn),
      'brief_url': (briefUrl?.trim().isEmpty ?? true) ? null : briefUrl!.trim(),
      'requirements': requirements,
      'reference_urls': referenceUrls,
      'is_public': isPublic,
    };

    return writeThrough(
      () async {
        final response = id == null
            ? await client.post(ApiEndpoints.campaigns, body: body)
            : await client.put(ApiEndpoints.campaign(id), body: body);
        return Campaign.fromJson(
          response.maybeMapAt('campaign') ?? response.dataMap,
        );
      },
      'campaign.detail:${id ?? 1}',
      (json) => Campaign.fromJson({
        ...asMap(json),
        ...body,
        'id': id ?? 99,
        'status': id == null ? 'draft' : asMap(json)['status'],
      }),
    );
  }

  /// Moves one applicant along the pipeline. [reason] only travels with a
  /// decline; the creator sees it on their application.
  Future<void> decide(
    int campaignId,
    int recipientId,
    RecipientStatus status, {
    String? reason,
  }) =>
      writeThrough(
        () async {
          final note = reason?.trim() ?? '';
          await client.put(
            ApiEndpoints.campaignRecipient(campaignId, recipientId),
            body: {
              'status': status.name,
              if (status == RecipientStatus.declined && note.isNotEmpty)
                'reason': note,
            },
          );
        },
        'noop',
        (_) {},
      );

  /// [stored] (the brief's requirements as the server has them) with the
  /// wizard's targeting keys replaced by [form]. A key the brand cleared is
  /// removed rather than left at its old value.
  static Map<String, dynamic> mergeRequirements(
    Map<String, dynamic> stored,
    CampaignRequirements form,
  ) =>
      {
        for (final entry in stored.entries)
          if (!formRequirementKeys.contains(entry.key)) entry.key: entry.value,
        ...form.toJson(),
      };

  static String _date(DateTime value) =>
      value.toIso8601String().split('T').first;
}

final brandCampaignApiProvider = Provider<BrandCampaignApi>((ref) {
  return BrandCampaignApi(ref.watch(apiClientProvider));
});

/// Brand campaign writes that refresh the same providers the shared
/// [CampaignActions] does, so the list, the dashboard and the detail screen
/// all agree after a save or a pipeline move.
class BrandCampaignActions {
  BrandCampaignActions(this._ref);

  final Ref _ref;

  BrandCampaignApi get _api => _ref.read(brandCampaignApiProvider);

  /// Saves the brief. When editing, the stored requirements are read first
  /// and merged, so nothing the form does not show is lost.
  Future<Campaign> save({
    int? id,
    required String title,
    required String description,
    required String platform,
    required String deliverableType,
    required int postsCount,
    required double budgetMin,
    required double budgetMax,
    required CampaignRequirements requirements,
    int? categoryId,
    DateTime? startsOn,
    DateTime? endsOn,
    List<String> referenceUrls = const [],
    String? briefUrl,
    bool isPublic = false,
  }) async {
    final stored = id == null
        ? const <String, dynamic>{}
        : await _api.requirementsOf(id);

    final campaign = await _api.save(
      id: id,
      title: title,
      description: description,
      platform: platform,
      deliverableType: deliverableType,
      postsCount: postsCount,
      budgetMin: budgetMin,
      budgetMax: budgetMax,
      requirements: BrandCampaignApi.mergeRequirements(stored, requirements),
      categoryId: categoryId,
      startsOn: startsOn,
      endsOn: endsOn,
      referenceUrls: referenceUrls,
      briefUrl: briefUrl,
      isPublic: isPublic,
    );

    _refresh(id ?? campaign.id);
    return campaign;
  }

  Future<void> decide(
    int campaignId,
    int recipientId,
    RecipientStatus status, {
    String? reason,
  }) async {
    await _api.decide(campaignId, recipientId, status, reason: reason);
    _refresh(campaignId);
  }

  void _refresh(int? id) {
    if (id != null && id > 0) _ref.invalidate(campaignDetailProvider(id));
    for (final tab in CampaignTab.values) {
      _ref.invalidate(campaignsProvider(tab));
    }
  }
}

final brandCampaignActionsProvider = Provider<BrandCampaignActions>((ref) {
  return BrandCampaignActions(ref);
});
