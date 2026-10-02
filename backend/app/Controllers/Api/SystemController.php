<?php

declare(strict_types=1);

namespace Zuste\Controllers\Api;

use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Core\Logger;
use Zuste\Core\Request;
use Zuste\Core\Response;
use Zuste\Services\CronRunner;
use Zuste\Services\PaymentService;

/**
 * Gateway webhooks and HTTP cron triggers.
 *
 * Routes: /api/v1/webhooks/*, /api/v1/cron/{job}
 */
final class SystemController extends BaseApiController
{
    /**
     * POST /webhooks/razorpay
     *
     * Not authenticated by JWT — authenticity comes from the HMAC signature,
     * verified inside PaymentService before anything is acted upon.
     */
    public function razorpayWebhook(Request $request): never
    {
        $result = PaymentService::handleWebhook(
            'razorpay',
            $request->rawBody(),
            $request->header('x-razorpay-signature')
        );

        // Always answer 200 once the event is stored, so the gateway stops
        // retrying; failures are replayed from our own webhook table.
        Response::json(['success' => $result['handled'], 'message' => $result['message']], 200);
    }

    /** POST /webhooks/stripe */
    public function stripeWebhook(Request $request): never
    {
        $result = PaymentService::handleWebhook(
            'stripe',
            $request->rawBody(),
            $request->header('stripe-signature')
        );

        Response::json(['success' => $result['handled'], 'message' => $result['message']], 200);
    }

    /** POST /webhooks/cashfree */
    public function cashfreeWebhook(Request $request): never
    {
        // Cashfree signs `timestamp . body`, so both headers are needed for
        // the HMAC to reproduce.
        $result = PaymentService::handleWebhook(
            'cashfree',
            $request->rawBody(),
            $request->header('x-webhook-signature'),
            $request->header('x-webhook-timestamp')
        );

        Response::json(['success' => $result['handled'], 'message' => $result['message']], 200);
    }

    /**
     * GET|POST /cron/{job}
     *
     * Guarded by CronMiddleware (shared secret). Prefer the CLI runner
     * (`php scripts/cron.php <job>`) where the host allows it.
     */
    public function runCronJob(Request $request): never
    {
        $job = (string) $request->routeParam('job', '');

        if (!CronRunner::exists($job)) {
            throw HttpException::notFound('Unknown job: ' . $job);
        }

        $started = microtime(true);

        try {
            $result   = CronRunner::run($job);
            $duration = (int) round((microtime(true) - $started) * 1000);

            Response::success([
                'job'         => $job,
                'result'      => $result,
                'duration_ms' => $duration,
            ], 'Job completed.');
        } catch (\Throwable $e) {
            Logger::error('Cron job failed', ['job' => $job, 'error' => $e->getMessage()]);

            throw HttpException::server('Job failed: ' . $e->getMessage());
        }
    }

    /** GET /cron — list registered jobs and their last run. */
    public function cronStatus(Request $request): never
    {
        Response::success([
            'jobs' => Database::select(
                'SELECT `job_key`, `name`, `description`, `schedule_hint`, `is_enabled`,
                        `last_run_at`, `last_status`, `last_duration_ms`, `run_count`, `failure_count`
                 FROM `scheduled_jobs` ORDER BY `id`'
            ),
        ]);
    }
}
