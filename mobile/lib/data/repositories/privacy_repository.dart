import '../../core/network/api_endpoints.dart';
import '../../models/privacy.dart';
import 'base_repository.dart';

/// "Download my data" and "Delete my account" (`/me/privacy/*`).
///
/// Nothing here falls back to a fixture. An export that only pretended to be
/// prepared, or a deletion that only pretended to happen, would be worse than
/// an honest error. Every call goes to the server, and every failure surfaces
/// as an ApiFailure for the screen to show.
class PrivacyRepository extends BaseRepository {
  PrivacyRepository(super.client);

  /// Verification options, the latest export, and the deletion preflight.
  Future<PrivacyOverview> overview() async {
    final response = await client.get(ApiEndpoints.privacy);
    return PrivacyOverview.fromJson(response.dataMap);
  }

  /// Sends a one-time code to the account's own email or phone. The server
  /// picks the address, so all the app chooses is the channel.
  Future<SentCode> sendCode({
    required String purpose,
    required String channel,
  }) async {
    final response = await client.post(
      ApiEndpoints.privacyCode,
      body: {'purpose': purpose, 'channel': channel},
    );
    return SentCode.fromJson(response.dataMap);
  }

  /// Re-authenticates and builds the archive, returning it with its link.
  Future<DataExport> requestExport(Reauth proof) async {
    final response = await client.post(
      ApiEndpoints.privacyExports,
      body: proof.toJson(),
    );
    return DataExport.fromJson(response.maybeMapAt('export') ?? response.dataMap);
  }

  /// Erases the account. On success the server has already revoked every
  /// session, and the caller only has this device left to clear.
  Future<void> deleteAccount({
    required Reauth proof,
    required String confirmation,
    String? reasonCode,
    String? reason,
  }) async {
    await client.post(
      ApiEndpoints.privacyDeleteAccount,
      body: {
        ...proof.toJson(),
        'confirmation': confirmation,
        'reason_code': ?reasonCode,
        if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
      },
    );
  }
}
