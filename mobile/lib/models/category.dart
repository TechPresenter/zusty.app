import 'package:flutter/material.dart';

import '../core/theme/app_colors.dart';
import '../core/utils/json.dart';

/// A creator category (Lifestyle, Fashion, Travel …) from `GET /categories`.
class Category {
  const Category({
    required this.id,
    required this.name,
    required this.slug,
    this.icon,
    this.colorHex,
    this.coverImage,
    this.creatorCount = 0,
    this.description,
  });

  final int id;
  final String name;
  final String slug;

  /// Material Symbols icon name chosen by an admin, e.g. `checkroom`.
  final String? icon;
  final String? colorHex;
  final String? coverImage;
  final int creatorCount;
  final String? description;

  factory Category.fromJson(Map<String, dynamic> json) => Category(
        id: Json.integer(json['id']),
        name: Json.str(json['name']),
        slug: Json.str(json['slug']),
        icon: Json.strOrNull(json['icon']),
        colorHex: Json.strOrNull(json['color_hex']),
        coverImage: Json.strOrNull(json['cover_image'] ?? json['image_url']),
        creatorCount: Json.integer(json['creator_count']),
        description: Json.strOrNull(json['description']),
      );

  /// Admin-set accent colour, falling back to the brand primary.
  Color get color {
    final hex = colorHex?.replaceAll('#', '');
    if (hex == null || (hex.length != 6 && hex.length != 8)) {
      return AppColors.primary;
    }
    final value = int.tryParse(hex.length == 6 ? 'FF$hex' : hex, radix: 16);
    return value == null ? AppColors.primary : Color(value);
  }

  /// Maps the backend's icon name onto a bundled Material icon so the app
  /// never depends on the Material Symbols web font.
  ///
  /// The admin panel takes this as free text ("e.g. checkroom, flight_takeoff,
  /// restaurant"), so the names here are the Material Symbols spellings the
  /// panel hints at, not a private vocabulary — every seeded category resolves,
  /// including `flight_takeoff` from the hint itself, which used to fall
  /// through. The older short aliases (`fashion`, `travel`, …) are kept so a
  /// category an operator set up against the previous list still renders.
  ///
  /// Resolved through a switch rather than a codepoint lookup because Flutter
  /// tree-shakes unreferenced icons out of release builds: an IconData built
  /// from a codepoint the server sent renders an empty box.
  IconData get iconData => switch (icon) {
        // -- Seeded categories ------------------------------------------------
        'auto_awesome' => Icons.auto_awesome_rounded,
        'checkroom' || 'fashion' || 'shopping_bag' =>
          Icons.checkroom_rounded,
        'flight_takeoff' || 'flight' || 'travel' || 'luggage' =>
          Icons.flight_takeoff_rounded,
        'restaurant' || 'food' || 'restaurant_menu' || 'fastfood' =>
          Icons.restaurant_rounded,
        'fitness_center' || 'fitness' || 'sports_gymnastics' =>
          Icons.fitness_center_rounded,
        'devices' || 'tech' || 'memory' || 'computer' => Icons.devices_rounded,
        'face_retouching_natural' || 'face' || 'brush' =>
          Icons.face_retouching_natural_rounded,
        'sports_esports' || 'gaming' || 'videogame_asset' =>
          Icons.sports_esports_rounded,
        'spa' || 'beauty' || 'self_improvement' => Icons.spa_rounded,
        'music_note' || 'music' || 'headphones' || 'mic' =>
          Icons.music_note_rounded,
        'school' || 'education' || 'menu_book' || 'book' =>
          Icons.school_rounded,
        'savings' || 'attach_money' || 'finance' || 'account_balance' ||
        'payments' =>
          Icons.savings_rounded,
        'child_care' || 'parenting' || 'stroller' || 'family_restroom' =>
          Icons.child_care_rounded,
        'directions_car' || 'automotive' || 'two_wheeler' ||
        'directions_bike' =>
          Icons.directions_car_rounded,
        'theater_comedy' || 'comedy' || 'sentiment_very_satisfied' =>
          Icons.theater_comedy_rounded,
        'palette' || 'design' || 'color_lens' || 'draw' =>
          Icons.palette_rounded,

        // -- Names the panel does not seed but an operator may reasonably type
        'photo_camera' || 'photography' || 'camera_alt' =>
          Icons.photo_camera_rounded,
        'videocam' || 'movie' || 'theaters' => Icons.movie_rounded,
        'pets' || 'animals' => Icons.pets_rounded,
        'home' || 'lifestyle' => Icons.coffee_rounded,
        'home_work' || 'real_estate' || 'chair' => Icons.home_work_rounded,
        'sports_soccer' || 'sports' || 'sports_basketball' =>
          Icons.sports_soccer_rounded,
        'health_and_safety' || 'health' || 'medical_services' =>
          Icons.health_and_safety_rounded,
        'celebration' || 'events' || 'cake' => Icons.celebration_rounded,
        'business_center' || 'business' || 'work' =>
          Icons.business_center_rounded,
        'science' || 'biotech' => Icons.science_rounded,
        'code' || 'developer_mode' => Icons.code_rounded,
        'star' || 'grade' => Icons.star_rounded,
        'local_cafe' || 'coffee' => Icons.local_cafe_rounded,
        'hiking' || 'terrain' || 'outdoors' => Icons.hiking_rounded,
        'diamond' || 'luxury' => Icons.diamond_rounded,
        'volunteer_activism' || 'nonprofit' =>
          Icons.volunteer_activism_rounded,

        _ => Icons.category_rounded,
      };
}
