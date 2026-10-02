<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Database;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Helpers\Money;
use Zuste\Services\AiService;
use Zuste\Services\CreatorService;

/**
 * AI writing and matching for the apps.
 *
 * Each endpoint builds the prompt's context on the server — from the caller's
 * own profile, or from the form values the app sends — and hands it to
 * AiService, which applies the admin's switches, budget and daily allowance.
 * Every response carries `remaining_today` (null when there is no limit).
 */
final class AiController extends BaseApiController
{
    /** The longest a campaign description may be (CampaignController). */
    private const BRIEF_MAX_CHARS = 5000;

    /** How many creators matching considers and returns. */
    private const MATCH_CANDIDATES = 30;
    private const MATCH_RESULTS    = 5;

    /** POST /ai/bio — draft the signed-in creator's profile bio. */
    public function bio(Request $request): never
    {
        $data = $this->validate($request, [
            'notes'     => 'nullable|string|max:500',
            'max_chars' => 'nullable|integer|min_value:80|max_value:1000',
        ]);

        $user     = $this->user($request);
        $maxChars = (int) ($data['max_chars'] ?? 300);

        $creator = Database::selectOne(
            'SELECT c.`id`, c.`display_name`, c.`headline`, c.`city`, cat.`name` AS `category_name`
               FROM `creators` c
               LEFT JOIN `categories` cat ON cat.`id` = c.`primary_category_id`
              WHERE c.`user_id` = :user AND c.`deleted_at` IS NULL',
            ['user' => (int) $user['id']]
        ) ?? [];

        $platforms = $creator === [] ? [] : self::platformSummaries([(int) $creator['id']])[(int) $creator['id']] ?? [];

        $result = AiService::run(
            'bio_writer',
            [
                'name'      => self::firstFilled($creator['display_name'] ?? null, $user['name'] ?? null),
                'niche'     => self::firstFilled(
                    implode(' — ', array_filter([
                        trim((string) ($creator['category_name'] ?? '')),
                        trim((string) ($creator['headline'] ?? '')),
                    ])),
                    'not specified'
                ),
                'city'      => self::firstFilled($creator['city'] ?? null, $user['city'] ?? null, 'not specified'),
                'platforms' => $platforms === [] ? 'not specified' : implode(', ', $platforms),
                'notes'     => self::firstFilled($data['notes'] ?? null, 'none'),
            ],
            (int) $user['id'],
            'Reply with the bio only: plain text, no title, no quotation marks, no hashtags and no Markdown. '
                . 'It must be at most ' . $maxChars . ' characters including spaces.'
        );

        Response::success(
            [
                'text'            => AiService::plainText($result['text'], $maxChars),
                'remaining_today' => $result['remaining_today'],
            ],
            'Here is a draft. Edit it so it sounds like you.'
        );
    }

    /** POST /ai/campaign-brief — turn a brand's short idea into a full brief. */
    public function campaignBrief(Request $request): never
    {
        $data = $this->validate($request, [
            'idea'             => 'required|string|min:10|max:1000',
            'title'            => 'nullable|string|max:191',
            'platform'         => 'nullable|in:instagram,youtube,tiktok,facebook,twitter,linkedin,multi',
            'deliverable_type' => 'nullable|in:post,reel,story,video,short,live,ugc,blog,review,bundle',
            'posts_count'      => 'nullable|integer|min_value:1|max_value:100',
            'budget_min'       => 'nullable|numeric|min_value:0',
            'budget_max'       => 'nullable|numeric|min_value:0',
            'category_id'      => 'nullable|integer|min_value:1',
        ]);

        $user     = $this->user($request);
        $category = self::categoryName($data['category_id'] ?? null);
        $budget   = self::budgetText($data['budget_min'] ?? null, $data['budget_max'] ?? null);

        $product = self::firstFilled($data['title'] ?? null, 'see the goal');

        if ($category !== '') {
            $product .= ' (' . $category . ')';
        }

        $deliverables = 'not specified';

        if (!empty($data['deliverable_type'])) {
            $deliverables = (int) ($data['posts_count'] ?? 1) . ' × ' . $data['deliverable_type'] . ' per creator';
        }

        $result = AiService::run(
            'campaign_brief',
            [
                'brand_name'   => self::firstFilled($user['company_name'] ?? null, $user['name'] ?? null),
                'product'      => $product,
                'goal'         => (string) $data['idea'],
                'platform'     => self::platformLabel($data['platform'] ?? null),
                'deliverables' => $deliverables,
                'currency'     => $budget === null ? '' : Money::symbol(),
                'budget'       => $budget ?? 'not specified',
            ],
            (int) $user['id'],
            'Write the brief in plain text. Put short section headings on their own lines and use simple dashes '
                . 'for lists; do not use Markdown symbols such as #, * or **. Keep the whole brief under 500 words.'
        );

        Response::success(
            [
                'text'            => AiService::plainText($result['text'], self::BRIEF_MAX_CHARS),
                'remaining_today' => $result['remaining_today'],
            ],
            'Here is a draft brief. Review it before you publish.'
        );
    }

    /** POST /ai/creator-match — rank the creators who best fit a brief. */
    public function creatorMatch(Request $request): never
    {
        $data = $this->validate($request, [
            'brief'       => 'required|string|min:20|max:5000',
            'category_id' => 'nullable|integer|min_value:1',
            'platform'    => 'nullable|in:instagram,youtube,tiktok,facebook,twitter,linkedin,multi',
            'city'        => 'nullable|string|max:100',
            'budget_max'  => 'nullable|numeric|min_value:0',
        ]);

        $user       = $this->user($request);
        $candidates = self::candidates($data, (int) $user['id']);

        // Nothing to rank costs nothing and uses none of the allowance.
        if ($candidates === []) {
            Response::success(
                ['matches' => [], 'remaining_today' => self::remainingToday((int) $user['id'])],
                'No creators fit these filters yet. Try widening them.'
            );
        }

        $platforms = self::platformSummaries(array_keys($candidates));
        $lines     = [];

        foreach ($candidates as $id => $card) {
            $lines[] = self::candidateLine($id, $card, $platforms[$id] ?? []);
        }

        $result = AiService::run(
            'creator_match',
            [
                'brief'      => (string) $data['brief'],
                'currency'   => empty($data['budget_max']) ? '' : Money::symbol(),
                'budget'     => empty($data['budget_max'])
                    ? 'not specified'
                    : Money::groupIndian((float) $data['budget_max'], 0) . ' per creator, at most',
                'platform'   => self::platformLabel($data['platform'] ?? null),
                'category'   => self::firstFilled(self::categoryName($data['category_id'] ?? null), 'any'),
                'candidates' => implode("\n", $lines),
            ],
            (int) $user['id'],
            'Reply with JSON only, in exactly this shape: {"matches": [{"id": <candidate id as a number>, '
                . '"score": <whole number 0-100>, "reason": "<one sentence, under 160 characters>"}]}. '
                . 'Include at most ' . self::MATCH_RESULTS . ' creators, best first, using only ids from the candidate list.',
            true,
            static fn (string $text): ?array => self::parseMatches($text, $candidates)
        );

        Response::success(
            ['matches' => $result['parsed'], 'remaining_today' => $result['remaining_today']],
            'Here are the best matches for your brief.'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Creators worth ranking, as discovery cards keyed by id. The brand's
     * filters are tried first; if they leave too few, city and budget are
     * relaxed so the AI still has something to choose from.
     *
     * @param  array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private static function candidates(array $data, int $viewerId): array
    {
        $filters = [
            'category_id' => (int) ($data['category_id'] ?? 0),
            'platform'    => (string) ($data['platform'] ?? '') === 'multi' ? '' : (string) ($data['platform'] ?? ''),
            'city'        => (string) ($data['city'] ?? ''),
            'max_price'   => (float) ($data['budget_max'] ?? 0),
            'sort'        => 'relevance',
            'per_page'    => self::MATCH_CANDIDATES,
        ];

        $cards = [];

        foreach ([$filters, ['city' => '', 'max_price' => 0.0] + $filters] as $attempt) {
            foreach (CreatorService::search($attempt, $viewerId)['items'] as $card) {
                $cards[(int) $card['id']] ??= $card;
            }

            if (count($cards) >= self::MATCH_RESULTS) {
                break;
            }
        }

        return array_slice($cards, 0, self::MATCH_CANDIDATES, true);
    }

    /**
     * One compact line per candidate. Only public profile facts go to the
     * provider — never contact details.
     *
     * @param array<string,mixed> $card
     * @param list<string>        $platforms
     */
    private static function candidateLine(int $id, array $card, array $platforms): string
    {
        $parts = [
            $id,
            trim((string) ($card['display_name'] ?? '')) . ' (@' . (string) ($card['handle'] ?? '') . ')',
            self::firstFilled($card['category_name'] ?? null, $card['category'] ?? null, 'no category'),
            self::firstFilled($card['location'] ?? null, $card['city'] ?? null, 'city not given'),
            $platforms === [] ? 'no linked platforms' : implode('; ', $platforms),
            self::firstFilled($card['price_label'] ?? null, 'price on request'),
        ];

        $rating = (float) ($card['rating'] ?? $card['rating_avg'] ?? 0);

        if ($rating > 0) {
            $parts[] = 'rated ' . number_format($rating, 1) . ' from ' . (int) ($card['rating_count'] ?? 0) . ' reviews';
        }

        if (!empty($card['headline'])) {
            $parts[] = mb_substr((string) $card['headline'], 0, 120);
        }

        return implode(' | ', $parts);
    }

    /**
     * The model's ranking, kept only where it names a real candidate, with the
     * card the app already knows how to draw.
     *
     * @param  array<int,array<string,mixed>> $candidates
     * @return list<array<string,mixed>>|null
     */
    private static function parseMatches(string $text, array $candidates): ?array
    {
        $start   = strpos($text, '{');
        $end     = strrpos($text, '}');
        $decoded = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        if (!is_array($decoded) || !is_array($decoded['matches'] ?? null)) {
            return null;
        }

        $matches = [];

        foreach ($decoded['matches'] as $match) {
            $id = is_array($match) ? (int) ($match['id'] ?? 0) : 0;

            if (!isset($candidates[$id]) || isset($matches[$id])) {
                continue;
            }

            $matches[$id] = $candidates[$id] + [
                'match_score'  => max(0, min(100, (int) ($match['score'] ?? 0))),
                'match_reason' => mb_substr(trim((string) ($match['reason'] ?? '')), 0, 200),
            ];
        }

        if ($matches === []) {
            return null;
        }

        $matches = array_values($matches);
        usort($matches, static fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score']);

        return array_slice($matches, 0, self::MATCH_RESULTS);
    }

    /**
     * "Instagram 45.2K followers, 3.1% engagement" per linked account, keyed
     * by creator id.
     *
     * @param  list<int> $creatorIds
     * @return array<int,list<string>>
     */
    private static function platformSummaries(array $creatorIds): array
    {
        if ($creatorIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($creatorIds), '?'));
        $rows         = Database::select(
            'SELECT `creator_id`, `platform`, `followers`, `engagement_rate`
               FROM `creator_social_accounts`
              WHERE `creator_id` IN (' . $placeholders . ')
              ORDER BY `is_primary` DESC, `sort_order` ASC',
            array_values($creatorIds)
        );

        $summaries = [];

        foreach ($rows as $row) {
            $line = self::platformLabel((string) $row['platform']);

            if ((int) $row['followers'] > 0) {
                $line .= ' ' . self::compactCount((int) $row['followers']) . ' followers';
            }

            if ((float) $row['engagement_rate'] > 0) {
                $line .= ', ' . rtrim(rtrim(number_format((float) $row['engagement_rate'], 1), '0'), '.') . '% engagement';
            }

            $summaries[(int) $row['creator_id']][] = $line;
        }

        return $summaries;
    }

    private static function remainingToday(int $userId): ?int
    {
        $limit = AiService::dailyLimit();

        return $limit > 0 ? max(0, $limit - AiService::usedToday($userId)) : null;
    }

    private static function categoryName(mixed $categoryId): string
    {
        if (empty($categoryId)) {
            return '';
        }

        return (string) (Database::scalar('SELECT `name` FROM `categories` WHERE `id` = :id', ['id' => (int) $categoryId]) ?? '');
    }

    private static function budgetText(mixed $min, mixed $max): ?string
    {
        $min = (float) ($min ?? 0);
        $max = (float) ($max ?? 0);

        return match (true) {
            $min > 0 && $max > 0 => Money::groupIndian($min, 0) . ' to ' . Money::groupIndian($max, 0) . ' per creator',
            $max > 0             => 'up to ' . Money::groupIndian($max, 0) . ' per creator',
            $min > 0             => 'from ' . Money::groupIndian($min, 0) . ' per creator',
            default              => null,
        };
    }

    private static function platformLabel(?string $platform): string
    {
        return match ((string) $platform) {
            ''        => 'any',
            'multi'   => 'several platforms',
            'youtube' => 'YouTube',
            'tiktok'  => 'TikTok',
            'linkedin' => 'LinkedIn',
            'twitter' => 'X (Twitter)',
            default   => ucfirst((string) $platform),
        };
    }

    private static function compactCount(int $count): string
    {
        return match (true) {
            $count >= 1_000_000 => rtrim(rtrim(number_format($count / 1_000_000, 1), '0'), '.') . 'M',
            $count >= 1_000     => rtrim(rtrim(number_format($count / 1_000, 1), '0'), '.') . 'K',
            default             => (string) $count,
        };
    }

    private static function firstFilled(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
