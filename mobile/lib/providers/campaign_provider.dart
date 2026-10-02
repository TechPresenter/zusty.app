import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_response.dart';
import '../data/repositories/campaign_repository.dart';
import '../models/campaign.dart';
import 'core_providers.dart';

final campaignRepositoryProvider = Provider<CampaignRepository>((ref) {
  return CampaignRepository(ref.watch(apiClientProvider));
});

/// Tabs on the brand's campaigns screen.
enum CampaignTab {
  all,
  draft,
  open,
  inProgress,
  completed;

  String get label => switch (this) {
        CampaignTab.all => 'All',
        CampaignTab.draft => 'Draft',
        CampaignTab.open => 'Open',
        CampaignTab.inProgress => 'Running',
        CampaignTab.completed => 'Done',
      };

  String get query => switch (this) {
        CampaignTab.all => 'all',
        CampaignTab.inProgress => 'in_progress',
        _ => name,
      };
}

final campaignsProvider = FutureProvider.autoDispose
    .family<Paginated<Campaign>, CampaignTab>((ref, tab) {
  return ref.watch(campaignRepositoryProvider).list(status: tab.query);
});

final campaignDetailProvider =
    FutureProvider.autoDispose.family<Campaign, int>((ref, id) {
  return ref.watch(campaignRepositoryProvider).detail(id);
});

/// Campaign mutations that refresh the list and detail together.
class CampaignActions {
  CampaignActions(this._ref);

  final Ref _ref;

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
  }) async {
    final campaign = await _ref.read(campaignRepositoryProvider).save(
          id: id,
          title: title,
          description: description,
          platform: platform,
          deliverableType: deliverableType,
          postsCount: postsCount,
          budgetMin: budgetMin,
          budgetMax: budgetMax,
          categoryId: categoryId,
          startsOn: startsOn,
          endsOn: endsOn,
          requirements: requirements,
          referenceUrls: referenceUrls,
          isPublic: isPublic,
          publish: publish,
        );

    _refresh(id);
    return campaign;
  }

  Future<void> publish(int id) async {
    await _ref.read(campaignRepositoryProvider).publish(id);
    _refresh(id);
  }

  Future<void> close(int id) async {
    await _ref.read(campaignRepositoryProvider).close(id);
    _refresh(id);
  }

  Future<void> delete(int id) async {
    await _ref.read(campaignRepositoryProvider).delete(id);
    _refresh(null);
  }

  Future<void> invite(int campaignId, List<int> creatorIds) async {
    await _ref.read(campaignRepositoryProvider).invite(campaignId, creatorIds);
    _refresh(campaignId);
  }

  Future<void> setRecipientStatus(
    int campaignId,
    int recipientId,
    RecipientStatus status,
  ) async {
    await _ref
        .read(campaignRepositoryProvider)
        .setRecipientStatus(campaignId, recipientId, status);
    _refresh(campaignId);
  }

  void _refresh(int? id) {
    if (id != null) _ref.invalidate(campaignDetailProvider(id));
    for (final tab in CampaignTab.values) {
      _ref.invalidate(campaignsProvider(tab));
    }
  }
}

final campaignActionsProvider = Provider<CampaignActions>((ref) {
  return CampaignActions(ref);
});
