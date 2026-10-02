import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/repositories/ai_repository.dart';
import 'core_providers.dart';

/// AI writing and matching. Which features to show comes from
/// `aiFeaturesProvider` (app_config_provider.dart).
final aiRepositoryProvider = Provider<AiRepository>((ref) {
  return AiRepository(ref.watch(apiClientProvider));
});
