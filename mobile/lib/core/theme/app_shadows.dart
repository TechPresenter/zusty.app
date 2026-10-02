import 'package:flutter/material.dart';

/// Elevation: tonal layers plus very soft, warm ambient shadows. Never hard
/// drop shadows, and never stacked; a card gets one of these or a border.
///
/// The tint is the brand plum at a few percent rather than black, so a card
/// lifts off the blush background without greying it.
abstract final class AppShadows {
  /// Level 1: resting cards.
  static const List<BoxShadow> card = [
    BoxShadow(
      color: Color(0x0F5C1A3F),
      blurRadius: 24,
      offset: Offset(0, 6),
    ),
  ];

  /// A tighter shadow for small chips, avatars and inline controls.
  static const List<BoxShadow> subtle = [
    BoxShadow(
      color: Color(0x0D5C1A3F),
      blurRadius: 10,
      offset: Offset(0, 2),
    ),
  ];

  /// Level 2: modals, sheets, hero panels and raised action buttons.
  static const List<BoxShadow> raised = [
    BoxShadow(
      color: Color(0x1A5C1A3F),
      blurRadius: 32,
      offset: Offset(0, 12),
    ),
  ];

  /// The floating magenta "+" in the bottom navigation bar.
  static const List<BoxShadow> fab = [
    BoxShadow(
      color: Color(0x4DC9004F),
      blurRadius: 18,
      offset: Offset(0, 8),
    ),
  ];

  /// The hairline shadow under a pinned app bar.
  static const List<BoxShadow> appBar = [
    BoxShadow(
      color: Color(0x0A5C1A3F),
      blurRadius: 8,
      offset: Offset(0, 1),
    ),
  ];

  /// The upward hairline shadow above the bottom navigation bar.
  static const List<BoxShadow> bottomBar = [
    BoxShadow(
      color: Color(0x0F5C1A3F),
      blurRadius: 16,
      offset: Offset(0, -2),
    ),
  ];
}
