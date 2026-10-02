import '../../core/config/app_config.dart';
import '../../core/network/api_endpoints.dart';
import '../../models/ai.dart';
import 'base_repository.dart';

/// AI writing and matching (`/ai/*`).
///
/// The server holds the provider key and applies the admin's switches, budget
/// and daily allowance; this only sends what the screen knows. Every call waits
/// on a model, so each uses [AppConfig.aiReceiveTimeout] instead of the
/// client-wide timeout.
class AiRepository extends BaseRepository {
  AiRepository(super.client);

  /// A profile bio drafted from the creator's own profile. [maxChars] is the
  /// length of the field the draft goes into, so it never needs trimming.
  Future<AiDraft> writeBio({String? notes, int maxChars = 300}) => writeThrough(
        () async {
          final response = await client.post(
            ApiEndpoints.aiBio,
            body: {'notes': notes, 'max_chars': maxChars},
            receiveTimeout: AppConfig.aiReceiveTimeout,
          );
          return AiDraft.fromJson(response.dataMap);
        },
        'ai.bio',
        (json) => AiDraft.fromJson(asMap(json)),
      );

  /// A full campaign brief from a one-line [idea] plus whatever the form
  /// already holds.
  Future<AiDraft> writeCampaignBrief({
    required String idea,
    String? title,
    String? platform,
    String? deliverableType,
    int? postsCount,
    double? budgetMin,
    double? budgetMax,
    int? categoryId,
  }) =>
      writeThrough(
        () async {
          final response = await client.post(
            ApiEndpoints.aiCampaignBrief,
            body: {
              'idea': idea,
              'title': title,
              'platform': platform,
              'deliverable_type': deliverableType,
              'posts_count': postsCount,
              // Zero means "not set" on the form.
              'budget_min': (budgetMin ?? 0) > 0 ? budgetMin : null,
              'budget_max': (budgetMax ?? 0) > 0 ? budgetMax : null,
              'category_id': categoryId,
            },
            receiveTimeout: AppConfig.aiReceiveTimeout,
          );
          return AiDraft.fromJson(response.dataMap);
        },
        'ai.brief',
        (json) => AiDraft.fromJson(asMap(json)),
      );

  /// The creators who best fit [brief], ranked by the AI.
  Future<CreatorMatches> matchCreators({
    required String brief,
    int? categoryId,
    String? platform,
    String? city,
    double? budgetMax,
  }) =>
      writeThrough(
        () async {
          final response = await client.post(
            ApiEndpoints.aiCreatorMatch,
            body: {
              'brief': brief,
              'category_id': categoryId,
              'platform': platform,
              'city': city,
              'budget_max': (budgetMax ?? 0) > 0 ? budgetMax : null,
            },
            receiveTimeout: AppConfig.aiReceiveTimeout,
          );
          return CreatorMatches.fromJson(response.dataMap);
        },
        'ai.match',
        (json) => CreatorMatches.fromJson(asMap(json)),
      );
}
