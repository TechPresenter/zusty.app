import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_endpoints.dart';
import '../core/providers/core_providers.dart';
import '../models/onboarding_slide.dart';

/// The intro slides, from `GET /app/onboarding`.
///
/// Cached on device so the second launch is instant and the screen still works
/// with no connection — a first-run user offline is exactly the case where a
/// blank onboarding would be worst.
final onboardingSlidesProvider =
    FutureProvider<List<OnboardingSlide>>((ref) async {
  final client = ref.watch(apiClientProvider);
  final cache = ref.watch(keyValueCacheProvider);

  const cacheKey = 'onboarding.slides';

  try {
    final response = await client.get(ApiEndpoints.onboarding, skipAuth: true);
    final slides = response.listAt('slides');

    if (slides.isNotEmpty) {
      await cache.writeList(cacheKey, slides);
      return slides.map(OnboardingSlide.fromJson).toList();
    }
  } catch (_) {
    // Fall through to whatever is cached.
  }

  final cached = cache.readList(cacheKey);

  return cached == null || cached.isEmpty
      ? const [OnboardingSlide.fallback]
      : cached.map(OnboardingSlide.fromJson).toList();
});
