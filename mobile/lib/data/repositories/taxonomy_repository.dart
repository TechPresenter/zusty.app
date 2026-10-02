import 'package:flutter/foundation.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_endpoints.dart';
import '../../core/network/api_failure.dart';
import '../../core/storage/key_value_cache.dart';
import '../../models/taxonomy.dart';
import 'taxonomy_defaults.dart';

/// Fetches the admin-managed option lists.
///
/// Three layers, in order of preference:
///
///   1. **Network** — one call to `/app/taxonomies`, sent with the cached
///      version as `If-None-Match`. An unchanged bundle answers 304 with no
///      body, so checking for updates on every launch is nearly free.
///   2. **Device cache** — the last bundle that arrived, so the app opens
///      instantly and keeps working offline.
///   3. **Compiled defaults** — only on a first launch that has never reached
///      the server, so the very first frame is not a set of empty dropdowns.
class TaxonomyRepository {
  TaxonomyRepository(this._client, this._cache);

  final ApiClient _client;
  final KeyValueCache _cache;

  static const _cacheKey = 'taxonomies.bundle';

  /// Reads the bundle, revalidating against the server when it can.
  Future<TaxonomyBundle> load({bool forceRefresh = false}) async {
    final cached = _readCache();

    try {
      final response = await _client.get(
        ApiEndpoints.taxonomies,
        skipAuth: true,
        // Sending the version we hold lets an unchanged bundle answer 304.
        headers: forceRefresh || cached == null
            ? null
            : {'If-None-Match': '"${cached.version}"'},
      );

      if (response.isNotModified && cached != null) {
        return cached;
      }

      final bundle = TaxonomyBundle.fromJson(response.dataMap);

      // An empty payload would blank every dropdown in the app. Treat it as a
      // bad response and keep whatever we already have.
      if (bundle.isEmpty) {
        return cached ?? TaxonomyDefaults.bundle;
      }

      await _cache.writeDocument(_cacheKey, bundle.toJson());
      return bundle;
    } on ApiFailure catch (e) {
      debugPrint('[Zusty] taxonomies unavailable (${e.code}); using cache.');
      return cached ?? TaxonomyDefaults.bundle;
    } catch (e) {
      debugPrint('[Zusty] taxonomies failed: $e');
      return cached ?? TaxonomyDefaults.bundle;
    }
  }

  /// The bundle held on device, if it is readable.
  TaxonomyBundle? _readCache() {
    final document = _cache.readDocument(_cacheKey);
    if (document == null) return null;

    try {
      final bundle = TaxonomyBundle.fromJson(document.json);
      return bundle.isEmpty ? null : bundle;
    } catch (_) {
      // A corrupt cache must never break startup — ignore it and refetch.
      return null;
    }
  }

  /// Drops the cached bundle, forcing a full fetch on the next load.
  Future<void> clear() => _cache.remove(_cacheKey);
}
