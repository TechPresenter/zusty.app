import '../../core/config/app_config.dart';
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
  ///
  /// The archive is built while the request waits, so it gets the slow
  /// timeout: giving up early would report a failure for an export that the
  /// server finishes anyway, after spending the code and starting the
  /// cooldown.
  Future<DataExport> requestExport(Reauth proof) async {
    final response = await client.post(
      ApiEndpoints.privacyExports,
      body: proof.toJson(),
      receiveTimeout: AppConfig.slowRequestTimeout,
    );
    return DataExport.fromJson(response.maybeMapAt('export') ?? response.dataMap);
  }

  /// A fresh download link for export [id], after confirming it is the owner.
  ///
  /// Issuing one cancels every earlier link to the same copy. It never
  /// extends the copy's expiry or resets its download count.
  Future<DataExport> exportLink(int id, Reauth proof) async {
    final response = await client.post(
      ApiEndpoints.privacyExportLink(id),
      body: proof.toJson(),
    );
    return DataExport.fromJson(response.maybeMapAt('export') ?? response.dataMap);
  }

  /// Erases the account. On success the server has already revoked every
  /// session, and the caller only has this device left to clear.
  ///
  /// [forfeitBalance] agrees to give up a wallet balance that can't be paid
  /// out: the preflight's `forfeit.amount`, sent back unchanged. Slow timeout
  /// for the same reason as [requestExport]: an abandoned request may still
  /// have erased the account.
  Future<DeletionResult> deleteAccount({
    required Reauth proof,
    required String confirmation,
    String? reasonCode,
    String? reason,
    double? forfeitBalance,
  }) async {
    final response = await client.post(
      ApiEndpoints.privacyDeleteAccount,
      body: {
        ...proof.toJson(),
        'confirmation': confirmation,
        'reason_code': ?reasonCode,
        if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
        'forfeit_balance': ?forfeitBalance,
      },
      receiveTimeout: AppConfig.slowRequestTimeout,
    );
    return DeletionResult.fromJson(response.dataMap);
  }

  /// Completes when the account still exists and this session still works.
  ///
  /// After a deletion whose answer never arrived, a 401 `account_deleted`
  /// here means it went through. `/me/privacy` is never answered from the
  /// response cache, so a stored copy cannot pass for a live account.
  Future<void> checkAccountExists() async {
    await client.get(ApiEndpoints.privacy);
  }
}
