import 'dart:async';
import 'dart:typed_data';

import 'package:collection/collection.dart';
import 'package:flutter/widgets.dart' show AppLifecycleListener;
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_failure.dart';
import '../data/repositories/kyc_repository.dart';
import '../features/kyc/kyc_share_cache.dart';
import '../models/kyc.dart';
import '../models/notification.dart';
import '../models/user.dart' show KycStatus;
import 'account_provider.dart' show notificationsProvider;
import 'auth_provider.dart';
import 'core_providers.dart';

/// The KYC overview, held as an [AsyncNotifier] rather than a `FutureProvider`
/// because every write returns a fresh overview: the screen replaces its whole
/// state from the server's answer instead of re-fetching after each action.
///
/// It is also kept current without the user pulling to refresh, because a
/// reviewer's decision (a re-upload request, a rejection, the blue tick
/// granted or removed) happens on the server while the app holds the old
/// answer. The overview is re-read in the background when the app comes back
/// to the foreground, when a new verification notification arrives, and when
/// the verification screen opens ([refreshQuietly]).
class KycController extends AsyncNotifier<KycOverview> {
  /// Writes in flight, and a counter every write bumps. A background read
  /// that started before a write finished must not replace the write's newer
  /// answer — an upload made while the file picker had the app in the
  /// background would otherwise flash back to "Missing".
  int _writesInFlight = 0;
  int _writeGeneration = 0;

  /// Bumped by every [build], so a read started for one session is dropped
  /// if the session changed under it.
  int _buildToken = 0;

  int? _userId;
  DateTime? _lastFetchedAt;
  Future<void>? _quietRefresh;

  /// The newest verification notification seen, so only a new one triggers
  /// a refresh.
  int? _lastKycNotificationId;

  /// Coming back to the app re-reads at most this often.
  static const _resumeInterval = Duration(seconds: 15);

  @override
  Future<KycOverview> build() {
    _buildToken++;

    // Rebuild for whoever is signed in. Without this the overview outlived a
    // sign-out, and the next account on the device opened the verification
    // screen — and its blue tick — of the one before.
    final userId = ref.watch(currentUserProvider.select((user) => user?.id));

    if (userId != _userId) _lastKycNotificationId = null;
    final previousUser = _userId;
    _userId = userId;

    // Signed out: nothing to show, and no point asking the server. Any copy
    // of a document handed to another app goes with the session.
    if (userId == null) {
      if (previousUser != null) unawaited(KycShareCache.purge());
      return Future.error(const UnauthorisedFailure());
    }

    _listenForResume();
    _listenForNotifications();

    final repository = ref.watch(kycRepositoryProvider);
    return repository.overview().then((overview) {
      _lastFetchedAt = DateTime.now();
      return overview;
    });
  }

  /// Saves only the fields in [details]; the form sends just what changed.
  ///
  /// [consent] is the processing consent the first save needs.
  Future<KycOverview> saveDetails(
    Map<String, dynamic> details, {
    bool consent = false,
  }) =>
      _apply(
        () => ref
            .read(kycRepositoryProvider)
            .saveDetails(details, consent: consent),
      );

  Future<KycOverview> uploadDocument({
    required String slot,
    required String filePath,
    String? fileName,
    String? expiresOn,
    int? replaceDocumentId,
    bool consent = false,
  }) =>
      _apply(
        () => ref.read(kycRepositoryProvider).uploadDocument(
              slot: slot,
              filePath: filePath,
              fileName: fileName,
              expiresOn: expiresOn,
              replaceDocumentId: replaceDocumentId,
              consent: consent,
            ),
      );

  Future<KycOverview> uploadDocumentBytes({
    required String slot,
    required Uint8List bytes,
    required String fileName,
    String? expiresOn,
    int? replaceDocumentId,
    bool consent = false,
  }) =>
      _apply(
        () => ref.read(kycRepositoryProvider).uploadDocumentBytes(
              slot: slot,
              bytes: bytes,
              fileName: fileName,
              expiresOn: expiresOn,
              replaceDocumentId: replaceDocumentId,
              consent: consent,
            ),
      );

  Future<KycOverview> deleteDocument(int id) =>
      _apply(() => ref.read(kycRepositoryProvider).deleteDocument(id));

  /// Submits for review. [consent] must come from the box the user ticked;
  /// without it the server refuses the submission.
  Future<KycOverview> submit({bool consent = false}) =>
      _apply(() => ref.read(kycRepositoryProvider).submit(consent: consent));

  /// Re-reads the overview: "Try again" and pull-to-refresh.
  ///
  /// What is on screen stays on screen while it loads. If the read fails, the
  /// failure is kept beside the previous answer rather than in its place —
  /// the screens show a notice, not an error page over the user's checklist.
  Future<void> refresh() => _reload(showLoading: true);

  /// Re-reads the overview in the background, keeping what is on screen.
  ///
  /// Skipped while the first load or a write is in flight (each brings its
  /// own fresh answer), and, with [minInterval], when the last answer is
  /// newer than that.
  Future<void> refreshQuietly({Duration? minInterval}) {
    if (_userId == null || _writesInFlight > 0) return Future.value();

    if (!state.hasValue) {
      // A first load that failed (offline at the time) is simply tried
      // again — there is nothing on screen to keep. One still in flight
      // brings its own answer.
      if (!state.hasError || state.isLoading) return Future.value();
      return _quietRefresh ??= _reload(showLoading: true)
          .whenComplete(() => _quietRefresh = null);
    }

    final last = _lastFetchedAt;
    if (minInterval != null &&
        last != null &&
        DateTime.now().difference(last) < minInterval) {
      return Future.value();
    }

    return _quietRefresh ??= _reload().whenComplete(() => _quietRefresh = null);
  }

  Future<void> _reload({bool showLoading = false}) async {
    if (_userId == null) return;

    final previous = state;
    final token = _buildToken;
    final generation = _writeGeneration;
    final repository = ref.read(kycRepositoryProvider);

    if (showLoading) {
      state = previous.hasValue
          ? const AsyncLoading<KycOverview>().copyWithPrevious(previous)
          : const AsyncLoading<KycOverview>();
    }

    bool stillCurrent() =>
        token == _buildToken && generation == _writeGeneration;

    try {
      final fresh = await repository.overview();
      if (!stillCurrent()) return;

      _lastFetchedAt = DateTime.now();
      state = AsyncData(fresh);
    } catch (error, stack) {
      if (!stillCurrent()) return;

      state = previous.hasValue
          ? AsyncError<KycOverview>(error, stack).copyWithPrevious(previous)
          : AsyncError<KycOverview>(error, stack);
    }
  }

  /// Fetches one of the user's own documents for viewing.
  ///
  /// Preview links are signed for a few minutes, and the screen may have been
  /// open for longer. When the link (or the session) has gone stale, a fresh
  /// overview is fetched through the shared client — which also renews the
  /// session — and the fetch is tried once more with the new link.
  Future<KycFile> openDocument(KycDocument document) async {
    final loader = ref.read(kycDocumentLoaderProvider);

    try {
      return await loader.fetch(document);
    } on KycLinkExpired {
      final fresh = await ref.read(kycRepositoryProvider).overview();
      _lastFetchedAt = DateTime.now();
      state = AsyncValue.data(fresh);

      final renewed = fresh.documents.firstWhereOrNull(
        (doc) => doc.id == document.id,
      );
      if (renewed == null) {
        throw const NotFoundFailure(
          message: 'That document has been replaced or removed.',
        );
      }

      try {
        return await loader.fetch(renewed);
      } on KycLinkExpired {
        throw const ForbiddenFailure(
          message: 'This document cannot be opened right now. Please try '
              'again in a moment.',
        );
      }
    }
  }

  /// Runs a write and swaps in the overview it returned.
  ///
  /// A failure leaves the state exactly as it was and is rethrown for the
  /// caller to show where it belongs — under the field, beside the upload,
  /// in a snackbar. Putting it into the state turned the whole Verification
  /// screen (or the details form, mid-edit) into an error page.
  Future<KycOverview> _apply(Future<KycOverview> Function() action) async {
    _writesInFlight++;
    _writeGeneration++;

    try {
      final fresh = await action();
      _lastFetchedAt = DateTime.now();
      state = AsyncValue.data(fresh);
      return fresh;
    } finally {
      _writesInFlight--;
    }
  }

  /// Re-reads when the app returns to the foreground: the reviewer may have
  /// decided while it was away.
  void _listenForResume() {
    try {
      final listener = AppLifecycleListener(
        onResume: () => refreshQuietly(minInterval: _resumeInterval),
      );
      ref.onDispose(listener.dispose);
    } catch (_) {
      // No widgets binding (a plain unit test): there is no lifecycle to
      // follow, and nothing else here depends on it.
    }
  }

  /// Re-reads when a new verification notification turns up in the inbox —
  /// the same event the user is being told about.
  void _listenForNotifications() {
    ref.listen(notificationsProvider, (_, next) {
      final newest = next.valueOrNull?.items
          .where(isKycNotification)
          .map((notification) => notification.id)
          .maxOrNull;
      if (newest == null) return;

      final seen = _lastKycNotificationId;
      if (seen == null || newest > seen) _lastKycNotificationId = newest;

      // The first list seen only sets the baseline. Deferred, because this
      // can run inside build() and the read belongs after it.
      if (seen != null && newest > seen) {
        unawaited(Future(() => refreshQuietly()));
      }
    }, fireImmediately: true);
  }
}

/// Whether [notification] is about the user's verification or blue tick.
bool isKycNotification(AppNotification notification) =>
    notification.type.toLowerCase().startsWith('kyc');

final kycProvider =
    AsyncNotifierProvider<KycController, KycOverview>(KycController.new);

/// Fetches document bytes with the signed-in session. See
/// [KycDocumentLoader] for why this is not the shared API client.
final kycDocumentLoaderProvider = Provider<KycDocumentLoader>((ref) {
  return KycDocumentLoader(secureStore: ref.watch(secureStoreProvider));
});

/// The public rulebook for a kind, used before the profile exists.
final kycRequirementsProvider =
    FutureProvider.autoDispose.family<List<KycRequirement>, String>((ref, kind) {
  return ref.watch(kycRepositoryProvider).requirements(kind);
});

/// Where the signed-in account's own verification stands, for the profile
/// and menu headers.
///
/// Null until the overview has loaded (or when it could not be). Callers fall
/// back to `User.kycStatus` for the label and show NO blue tick meanwhile —
/// the tick is only ever drawn once the server has said it was granted.
final kycSummaryProvider = Provider<KycSummary?>((ref) {
  final overview = ref.watch(kycProvider).valueOrNull;
  if (overview == null) return null;

  final profile = overview.profile;
  return KycSummary(
    status: profile.state,
    // "Draft" means something on the verification screen; on a profile
    // header "Not submitted" says it better.
    label: profile.state == KycStatus.notSubmitted
        ? KycStatus.notSubmitted.label
        : profile.displayLabel,
    tone: profile.statusTone,
    hasBlueTick: profile.hasBlueTick,
    blueTickGrantedAt: profile.badgeGrantedAt,
  );
});

/// A compact read of the verification state.
class KycSummary {
  const KycSummary({
    required this.status,
    required this.label,
    required this.tone,
    required this.hasBlueTick,
    this.blueTickGrantedAt,
  });

  final KycStatus status;

  /// The server's wording, e.g. "Under review".
  final String label;

  /// Chip tone name, e.g. `success`.
  final String tone;

  /// Granted by a person after approval — never inferred from approval.
  final bool hasBlueTick;
  final String? blueTickGrantedAt;
}
