import '../core/utils/json.dart';
import 'creator.dart';

/// A draft written by one of the AI features: a profile bio or a campaign
/// brief. The user edits it before anything is saved.
class AiDraft {
  const AiDraft({required this.text, this.remainingToday});

  final String text;

  /// AI requests this account has left today; null when there is no limit.
  final int? remainingToday;

  factory AiDraft.fromJson(Map<String, dynamic> json) => AiDraft(
        text: Json.str(json['text']),
        remainingToday: Json.intOrNull(json['remaining_today']),
      );
}

/// One creator the AI picked for a brief, with the discovery card the rest of
/// the app already knows how to draw.
class CreatorMatch {
  const CreatorMatch({
    required this.creator,
    required this.score,
    required this.reason,
  });

  final Creator creator;

  /// Fit out of 100.
  final int score;

  /// One sentence on why they fit.
  final String reason;

  factory CreatorMatch.fromJson(Map<String, dynamic> json) => CreatorMatch(
        creator: Creator.fromJson(json),
        score: Json.integer(json['match_score']).clamp(0, 100).toInt(),
        reason: Json.str(json['match_reason']),
      );
}

/// The answer to one matching request, best match first.
class CreatorMatches {
  const CreatorMatches({required this.matches, this.remainingToday});

  final List<CreatorMatch> matches;

  /// AI requests this account has left today; null when there is no limit.
  final int? remainingToday;

  factory CreatorMatches.fromJson(Map<String, dynamic> json) => CreatorMatches(
        matches: Json.list(json['matches'], CreatorMatch.fromJson),
        remainingToday: Json.intOrNull(json['remaining_today']),
      );
}
