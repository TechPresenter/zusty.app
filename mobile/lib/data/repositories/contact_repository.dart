import '../../core/network/api_endpoints.dart';
import '../../models/contact.dart';
import '../../models/privacy.dart';
import 'base_repository.dart';

/// Contact points, verified changes and alternates (`/me/contacts/*`).
///
/// Every write here either sends a one-time code or spends a guess at one, so
/// the endpoints are throttled server-side and nothing is cached through
/// [writeThrough]'s offline queue — a contact change replayed later against a
/// code that has since expired would fail confusingly.
class ContactRepository extends BaseRepository {
  ContactRepository(super.client);

  Future<ContactBook> load() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.contacts);
          return ContactBook.fromJson(response.dataMap);
        },
        'contacts',
        (json) => ContactBook.fromJson(asMap(json)),
      );

  /// Add an email or phone alongside the primary one.
  ///
  /// Returns the refreshed book, so the caller never has to guess what the
  /// server did with a value it may have normalised.
  Future<ContactBook> addAlternate({
    required String channel,
    required String value,
    String? countryCode,
    String? label,
  }) async {
    final response = await client.post(
      ApiEndpoints.contactAlternates,
      body: {
        'channel': channel,
        'value': value,
        'country_code': ?countryCode,
        'label': ?label,
      },
    );

    return ContactBook.fromJson(response.dataMap);
  }

  /// Send a fresh code to an alternate that has not been proved yet.
  Future<ContactBook> reverifyAlternate(int id) async {
    final response = await client.post(ApiEndpoints.contactAlternateVerify(id));
    return ContactBook.fromJson(response.dataMap);
  }

  Future<ContactBook> removeAlternate(int id) async {
    final response = await client.delete(ApiEndpoints.contactAlternate(id));
    return ContactBook.fromJson(response.dataMap);
  }

  /// Start changing the primary email or phone.
  ///
  /// The live value does NOT move here — the server holds the new one until it
  /// is proved, which is what stops a mistyped address locking someone out.
  ///
  /// [proof] confirms the owner is the one asking: the account's password, a
  /// code sent for `confirm_contact_change`, or a fresh Google or Apple
  /// sign-in. The server requires one whenever the account can give one. A
  /// code's channel goes as `code_channel`, because `channel` already names
  /// the contact being changed.
  Future<ContactBook> requestChange({
    required String channel,
    required String value,
    String? countryCode,
    String? reason,
    Reauth? proof,
  }) async {
    final response = await client.post(
      ApiEndpoints.contactChange,
      body: {
        'channel': channel,
        'value': value,
        'country_code': ?countryCode,
        'reason_code': ?reason,
        'password': ?proof?.password,
        'code': ?proof?.code,
        'code_channel': ?proof?.channel,
        'id_token': ?proof?.idToken,
      },
    );

    return ContactBook.fromJson(response.dataMap);
  }

  /// Confirm a pending change with the code that was sent to the NEW address.
  Future<ContactBook> confirmChange(int requestId, String code) async {
    final response = await client.post(
      ApiEndpoints.contactRequestVerify(requestId),
      body: {'code': code},
    );

    return ContactBook.fromJson(response.dataMap);
  }

  Future<ContactBook> resendCode(int requestId) async {
    final response = await client.post(ApiEndpoints.contactRequestResend(requestId));
    return ContactBook.fromJson(response.dataMap);
  }

  Future<ContactBook> cancelChange(int requestId) async {
    final response = await client.post(ApiEndpoints.contactRequestCancel(requestId));
    return ContactBook.fromJson(response.dataMap);
  }
}
