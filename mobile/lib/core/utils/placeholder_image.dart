/// Deterministic placeholder imagery.
///
/// A URL builder, not fixture data: the app uses it wherever admin-managed
/// artwork is optional, so the composition never renders empty. Seeded, so a
/// given subject always gets the same picture everywhere it appears.
String placeholderPhoto(String seed, [int width = 600, int height = 750]) =>
    'https://picsum.photos/seed/$seed/$width/$height';
