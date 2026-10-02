import 'dart:ui' show PlatformDispatcher;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/providers/core_providers.dart';

/// Light / Dark / follow-the-system, persisted across launches.
class ThemeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() =>
      _parse(ref.read(keyValueCacheProvider).themeModeName);

  Future<void> set(ThemeMode mode) async {
    state = mode;
    await ref.read(keyValueCacheProvider).setThemeModeName(mode.name);
  }

  /// Flips between light and dark. From `system`, jumps to whichever mode is
  /// the opposite of what the device is currently showing, so the toggle always
  /// visibly changes something.
  Future<void> toggle(Brightness current) => set(
        current == Brightness.dark ? ThemeMode.light : ThemeMode.dark,
      );

  static ThemeMode _parse(String? value) => switch (value) {
        'light' => ThemeMode.light,
        'dark' => ThemeMode.dark,
        _ => ThemeMode.system,
      };
}

final themeModeProvider =
    NotifierProvider<ThemeController, ThemeMode>(ThemeController.new);

/// The brightness actually in effect, resolving `system` against the platform.
final effectiveBrightnessProvider = Provider<Brightness>((ref) {
  final mode = ref.watch(themeModeProvider);
  return switch (mode) {
    ThemeMode.light => Brightness.light,
    ThemeMode.dark => Brightness.dark,
    ThemeMode.system =>
      PlatformDispatcher.instance.platformBrightness,
  };
});
