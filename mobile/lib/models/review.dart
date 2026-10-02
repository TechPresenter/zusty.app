import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// A two-way review left after a booking reaches "Completed" (`reviews`).
class Review {
  const Review({
    required this.id,
    required this.rating,
    this.title = '',
    this.comment = '',
    this.reviewerName = '',
    this.reviewerAvatar,
    this.reply,
    this.repliedAt,
    this.bookingCode,
    this.createdAt,
  });

  final int id;
  final double rating;
  final String title;
  final String comment;
  final String reviewerName;
  final String? reviewerAvatar;

  /// The creator's public response, if they have replied.
  final String? reply;
  final DateTime? repliedAt;
  final String? bookingCode;
  final DateTime? createdAt;

  factory Review.fromJson(Map<String, dynamic> json) => Review(
        id: Json.integer(json['id']),
        rating: Json.decimal(json['rating']),
        title: Json.str(json['title']),
        comment: Json.str(json['comment']),
        reviewerName: Json.str(json['reviewer_name'], 'Zusty user'),
        reviewerAvatar: Json.strOrNull(json['reviewer_avatar']),
        reply: Json.strOrNull(json['reply']),
        repliedAt: Formatters.parseDate(json['replied_at']),
        bookingCode: Json.strOrNull(json['booking_code']),
        createdAt: Formatters.parseDate(json['created_at']),
      );

  String get initials => Formatters.initials(reviewerName);

  String get dateLabel => Formatters.relative(createdAt);

  bool get hasReply => reply != null && reply!.isNotEmpty;
}
