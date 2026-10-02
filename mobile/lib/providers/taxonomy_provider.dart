import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/providers/core_providers.dart';
import '../data/repositories/taxonomy_defaults.dart';
import '../data/repositories/taxonomy_repository.dart';
import '../models/city.dart';
import '../models/taxonomy.dart';

final taxonomyRepositoryProvider = Provider<TaxonomyRepository>((ref) {
  return TaxonomyRepository(
    ref.watch(apiClientProvider),
    ref.watch(keyValueCacheProvider),
  );
});

/// Loads the option bundle once per app session.
///
/// Not `autoDispose`: every screen reads from it, and re-fetching on each
/// navigation would be wasteful when the payload only changes when an admin
/// edits it.
final taxonomyBundleProvider = FutureProvider<TaxonomyBundle>((ref) async {
  final bundle = await ref.watch(taxonomyRepositoryProvider).load();

  // `Cities` is read from static call sites (GPS snapping, the picker), so it
  // is hydrated here the moment the bundle lands rather than each caller
  // having to plumb the taxonomy through.
  Cities.hydrate(
    bundle.terms(Tax.city).map(City.fromTerm).toList(growable: false),
  );

  return bundle;
});

/// The bundle as a plain value, usable from `build` without an `AsyncValue`.
///
/// Falls back to the compiled defaults for the brief window before the first
/// fetch resolves, so no screen has to render a spinner just to draw a chip
/// row. The real bundle replaces it as soon as it lands.
final taxonomiesProvider = Provider<TaxonomyBundle>((ref) {
  return ref.watch(taxonomyBundleProvider).valueOrNull ??
      TaxonomyDefaults.bundle;
});

/// Terms for one list, e.g. `ref.watch(termsProvider(Tax.platform))`.
final termsProvider =
    Provider.family<List<TaxonomyTerm>, String>((ref, key) {
  return ref.watch(taxonomiesProvider).terms(key);
});

/// `value → label` for one list, which is what most chip rows want.
final termMapProvider =
    Provider.family<Map<String, String>, String>((ref, key) {
  return ref.watch(taxonomiesProvider).map(key);
});

/// Forces a refetch, e.g. from a pull-to-refresh on Settings.
final refreshTaxonomiesProvider = Provider<Future<void> Function()>((ref) {
  return () async {
    await ref.read(taxonomyRepositoryProvider).load(forceRefresh: true);
    ref.invalidate(taxonomyBundleProvider);
  };
});
