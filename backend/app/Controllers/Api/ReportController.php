<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\ReportService;

/**
 * Reports to the moderation queue.
 *
 * Routes: /api/v1/reports
 */
final class ReportController extends BaseApiController
{
    /**
     * POST /reports — report a profile, a message, a review, a booking, a
     * portfolio item or a campaign brief.
     *
     * `note` and `description` are the same optional free text; the app sends
     * `note`, older builds `description`.
     */
    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'reportable_type' => 'required|in:' . implode(',', ReportService::TYPES) . ',brief',
            'reportable_id'   => 'required|integer|min_value:1',
            'reason'          => 'required|in:' . implode(',', ReportService::REASONS),
            'note'            => 'nullable|string|max:2000',
            'description'     => 'nullable|string|max:2000',
        ]);

        $report = ReportService::file(
            $this->userId($request),
            (string) $data['reportable_type'],
            (int) $data['reportable_id'],
            (string) $data['reason'],
            $data['note'] ?? $data['description'] ?? null
        );

        Response::created(
            ['report' => $report],
            'Thank you. Our moderation team will review this report.'
        );
    }
}
