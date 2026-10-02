<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\CreatorService;
use Zuste\Services\NotificationService;

/**
 * Reviews and abuse reports.
 *
 * A review can only be written by a party to a completed booking, exactly
 * once per direction — the unique index on (booking_id, direction) is the
 * hard guarantee behind that rule.
 *
 * Routes: /api/v1/reviews/*, /api/v1/reports
 */
final class ReviewController extends BaseApiController
{
    /** POST /bookings/{id}/review */
    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'rating'               => 'required|integer|min_value:1|max_value:5',
            'quality_rating'       => 'nullable|integer|min_value:1|max_value:5',
            'communication_rating' => 'nullable|integer|min_value:1|max_value:5',
            'timeliness_rating'    => 'nullable|integer|min_value:1|max_value:5',
            'title'                => 'nullable|string|max:191',
            'comment'              => 'nullable|string|max:2000',
        ]);

        $bookingId = $request->routeParamInt('id');
        $userId    = $this->userId($request);

        $booking = Database::selectOne(
            'SELECT * FROM `bookings` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        $isBrand = (int) $booking['brand_user_id'] === $userId;

        if (!$isBrand && (int) $booking['creator_user_id'] !== $userId) {
            throw HttpException::forbidden('You are not a party to this booking.');
        }

        if ($booking['status'] !== 'completed') {
            throw HttpException::unprocessable('You can review a collaboration once it is completed.');
        }

        $direction = $isBrand ? 'brand_to_creator' : 'creator_to_brand';

        $existing = Database::scalar(
            'SELECT 1 FROM `reviews` WHERE `booking_id` = :id AND `direction` = :direction',
            ['id' => $bookingId, 'direction' => $direction]
        );

        if ($existing !== null) {
            throw HttpException::conflict('You have already reviewed this collaboration.');
        }

        $revieweeId = $isBrand ? (int) $booking['creator_user_id'] : (int) $booking['brand_user_id'];

        $reviewId = Database::transaction(static function () use ($booking, $bookingId, $userId, $revieweeId, $direction, $isBrand, $data): int {
            $id = Database::insert('reviews', [
                'booking_id'           => $bookingId,
                'direction'            => $direction,
                'reviewer_id'          => $userId,
                'reviewee_id'          => $revieweeId,
                'creator_id'           => $isBrand ? (int) $booking['creator_id'] : null,
                'rating'               => (int) $data['rating'],
                'quality_rating'       => isset($data['quality_rating']) ? (int) $data['quality_rating'] : null,
                'communication_rating' => isset($data['communication_rating']) ? (int) $data['communication_rating'] : null,
                'timeliness_rating'    => isset($data['timeliness_rating']) ? (int) $data['timeliness_rating'] : null,
                'title'                => $data['title'] ?? null,
                'comment'              => $data['comment'] ?? null,
                'created_at'           => date('Y-m-d H:i:s'),
            ]);

            Database::update(
                'bookings',
                [$isBrand ? 'brand_reviewed' : 'creator_reviewed' => 1],
                ['id' => $bookingId]
            );

            return $id;
        });

        if ($isBrand) {
            CreatorService::refreshStatistics((int) $booking['creator_id']);
        }

        NotificationService::send($revieweeId, 'review.received', [
            'reviewer_name' => (string) (Database::scalar('SELECT `name` FROM `users` WHERE `id` = :id', ['id' => $userId]) ?? 'A client'),
            'rating'        => (string) $data['rating'],
        ], ['action_type' => 'booking', 'action_value' => (string) $bookingId]);

        Response::created(
            ['review' => Database::selectOne('SELECT * FROM `reviews` WHERE `id` = :id', ['id' => $reviewId])],
            'Thanks for the review!'
        );
    }

    /** POST /reviews/{id}/reply — the creator responds publicly. */
    public function reply(Request $request): never
    {
        $data = $this->validate($request, ['reply' => 'required|string|min:2|max:2000']);

        $reviewId = $request->routeParamInt('id');
        $userId   = $this->userId($request);

        $review = Database::selectOne(
            'SELECT * FROM `reviews` WHERE `id` = :id AND `deleted_at` IS NULL',
            ['id' => $reviewId]
        );

        if ($review === null) {
            throw HttpException::notFound('Review not found.');
        }

        if ((int) $review['reviewee_id'] !== $userId) {
            throw HttpException::forbidden('Only the person reviewed can reply.');
        }

        if ($review['reply'] !== null) {
            throw HttpException::conflict('You have already replied to this review.');
        }

        Database::update('reviews', [
            'reply'      => (string) $data['reply'],
            'replied_at' => date('Y-m-d H:i:s'),
        ], ['id' => $reviewId]);

        Response::success(null, 'Reply posted.');
    }

    /** GET /me/reviews — reviews written about the signed-in user. */
    public function received(Request $request): never
    {
        $pagination = $this->pagination($request, 20, 50);
        $userId     = $this->userId($request);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `reviews` WHERE `reviewee_id` = :id AND `is_published` = 1 AND `deleted_at` IS NULL',
            ['id' => $userId]
        );

        $rows = Database::select(
            'SELECT r.*, u.`name` AS `reviewer_name`, u.`avatar_url` AS `reviewer_avatar`,
                    b.`code` AS `booking_code`, b.`title` AS `booking_title`
             FROM `reviews` r
             INNER JOIN `users` u ON u.`id` = r.`reviewer_id`
             INNER JOIN `bookings` b ON b.`id` = r.`booking_id`
             WHERE r.`reviewee_id` = :id AND r.`is_published` = 1 AND r.`deleted_at` IS NULL
             ORDER BY r.`id` DESC
             LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
            ['id' => $userId]
        );

        Response::paginated($rows, $total, $pagination['page'], $pagination['per_page']);
    }

    /** POST /reports — report a user, creator, review or message. */
    public function report(Request $request): never
    {
        $data = $this->validate($request, [
            'reportable_type' => 'required|in:user,creator,booking,review,message,portfolio',
            'reportable_id'   => 'required|integer|min_value:1',
            'reason'          => 'required|in:spam,fraud,abuse,impersonation,inappropriate,copyright,payment_issue,other',
            'description'     => 'nullable|string|max:2000',
        ]);

        $userId = $this->userId($request);

        $duplicate = Database::scalar(
            "SELECT 1 FROM `reports`
             WHERE `reporter_id` = :reporter AND `reportable_type` = :type AND `reportable_id` = :id
               AND `status` IN ('open','investigating')
             LIMIT 1",
            [
                'reporter' => $userId,
                'type'     => (string) $data['reportable_type'],
                'id'       => (int) $data['reportable_id'],
            ]
        );

        if ($duplicate !== null) {
            throw HttpException::conflict('You have already reported this. Our team is reviewing it.');
        }

        Database::insert('reports', [
            'reporter_id'     => $userId,
            'reportable_type' => (string) $data['reportable_type'],
            'reportable_id'   => (int) $data['reportable_id'],
            'reason'          => (string) $data['reason'],
            'description'     => $data['description'] ?? null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        Response::created(null, 'Thank you. Our moderation team will review this report.');
    }
}
