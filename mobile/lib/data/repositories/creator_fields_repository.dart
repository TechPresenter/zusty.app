import '../../core/network/api_endpoints.dart';
import '../../models/creator_fields.dart';
import 'base_repository.dart';

/// The creator's own extended profile (`/me/creator/*`).
///
/// The read side of this lives on [CreatorRepository.fields], which serves the
/// PUBLIC view of any creator. This is the editing counterpart, and it is
/// separate for a reason: every method here is a write that only the owner may
/// make, and several are refused outright when an administrator has locked the
/// matching profile section.
///
/// Nothing is queued for offline replay. A section can be locked between the
/// app going offline and coming back, and replaying an edit the server would
/// now refuse — silently, minutes later — is worse than failing at the time.
class CreatorFieldsRepository extends BaseRepository {
  CreatorFieldsRepository(super.client);

  /// Everything the creator can edit, in one call.
  Future<CreatorFields> load() => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.myCreatorFields);
          return CreatorFields.fromJson(
            response.maybeMapAt('fields') ?? response.dataMap,
          );
        },
        'creator.myfields',
        (json) => CreatorFields.fromJson(asMap(json)),
      );

  // ---- Education, certifications, awards --------------------------------

  /// [kind] is a `credential_type` term (`education`, `certification`,
  /// `award`, …), not a fixed set — the picker is built from the taxonomy the
  /// server sends, so a kind an operator adds needs no release here.
  Future<void> addCredential({
    required String title,
    String? kind,
    String? issuer,
    String? fieldOfStudy,
    String? grade,
    String? startedOn,
    String? endedOn,
    bool? isCurrent,
    String? description,
  }) =>
      client.post(ApiEndpoints.myCredentials, body: {
        'title': title,
        'kind': ?kind,
        'issuer': ?issuer,
        'field_of_study': ?fieldOfStudy,
        'grade': ?grade,
        'started_on': ?startedOn,
        'ended_on': ?endedOn,
        'is_current': ?isCurrent,
        'description': ?description,
      });

  Future<void> updateCredential(int id, Map<String, dynamic> changes) =>
      client.put(ApiEndpoints.myCredential(id), body: changes);

  Future<void> deleteCredential(int id) =>
      client.delete(ApiEndpoints.myCredential(id));

  Future<void> reorderCredentials(List<int> ids) =>
      client.put(ApiEndpoints.myCredentialsOrder, body: {'ids': ids});

  // ---- Work history and brand collaborations ----------------------------

  /// [bookingId] links the entry to a completed booking on this platform,
  /// which is what earns the "platform verified" mark. The server checks the
  /// booking is the creator's, completed, and not already claimed — passing an
  /// arbitrary id does not buy a badge.
  Future<void> addExperience({
    required String organisation,
    String? kind,
    String? role,
    String? location,
    String? startedOn,
    String? endedOn,
    bool? isCurrent,
    String? description,
    int? bookingId,
  }) =>
      client.post(ApiEndpoints.myExperience, body: {
        'organisation': organisation,
        'kind': ?kind,
        'role': ?role,
        'location': ?location,
        'started_on': ?startedOn,
        'ended_on': ?endedOn,
        'is_current': ?isCurrent,
        'description': ?description,
        'booking_id': ?bookingId,
      });

  Future<void> updateExperience(int id, Map<String, dynamic> changes) =>
      client.put(ApiEndpoints.myExperienceItem(id), body: changes);

  Future<void> deleteExperience(int id) =>
      client.delete(ApiEndpoints.myExperienceItem(id));

  Future<void> reorderExperience(List<int> ids) =>
      client.put(ApiEndpoints.myExperienceOrder, body: {'ids': ids});

  // ---- FAQs --------------------------------------------------------------

  Future<void> addFaq({required String question, required String answer}) =>
      client.post(ApiEndpoints.myFaqs, body: {
        'question': question,
        'answer': answer,
      });

  Future<void> updateFaq(int id, {String? question, String? answer}) =>
      client.put(ApiEndpoints.myFaq(id), body: {
        'question': ?question,
        'answer': ?answer,
      });

  Future<void> deleteFaq(int id) => client.delete(ApiEndpoints.myFaq(id));

  Future<void> reorderFaqs(List<int> ids) =>
      client.put(ApiEndpoints.myFaqsOrder, body: {'ids': ids});

  // ---- Working hours -----------------------------------------------------

  /// One window on one weekday. [weekday] is 1–7.
  ///
  /// Marking a day off is [isWorking] false with no times, which is a
  /// different statement from never having set the day at all — the profile
  /// distinguishes "closed Sundays" from "has not said".
  Future<void> addWorkingHour({
    required int weekday,
    String? startsAt,
    String? endsAt,
    bool? isWorking,
    String? note,
  }) =>
      client.post(ApiEndpoints.myWorkingHours, body: {
        'weekday': weekday,
        'starts_at': ?startsAt,
        'ends_at': ?endsAt,
        'is_working': ?isWorking,
        'note': ?note,
      });

  Future<void> updateWorkingHour(int id, Map<String, dynamic> changes) =>
      client.put(ApiEndpoints.myWorkingHour(id), body: changes);

  Future<void> deleteWorkingHour(int id) =>
      client.delete(ApiEndpoints.myWorkingHour(id));

  // ---- Skills, interests, languages, service areas -----------------------

  /// Replace everything in one section at once.
  ///
  /// [section] is a section name (`skills`, `interests`, …) and [values] are
  /// taxonomy term values. The server refuses a term that is not in the
  /// matching taxonomy, so a stale picker cannot write a value nothing can
  /// render.
  Future<void> syncTerms(String section, List<String> values) =>
      client.put(ApiEndpoints.myTerms(section), body: {'values': values});

  Future<void> attachTerm(String section, String value) =>
      client.post(ApiEndpoints.myTerms(section), body: {'value': value});

  Future<void> detachTerm(String section, String value) =>
      client.delete(ApiEndpoints.myTerm(section, value));

  Future<void> setPrimaryTerm(String section, String value) =>
      client.put(ApiEndpoints.myTermsPrimary(section), body: {'value': value});

  // ---- Scalar details ----------------------------------------------------

  /// Address, PIN code, timezone, intro video, media kit, resume, profession.
  Future<void> updateDetails(Map<String, dynamic> changes) =>
      client.put(ApiEndpoints.myFieldDetails, body: changes);
}
