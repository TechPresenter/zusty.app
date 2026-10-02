<?php

declare(strict_types=1);

/**
 * Approval workflow regression test.
 *
 *     php scripts/approval_smoke.php
 *
 * The approval engine was fully built and wired to nothing: no code path
 * opened a request, and a fully approved request updated only its own row —
 * the subject was never touched. Three workflows (high-value payout, large
 * refund, creator approval) were configurable in the admin panel and had no
 * effect whatsoever, so one admin could release a payout of any size alone.
 *
 * This creates a payout above the configured threshold, walks it through every
 * step with distinct privileged admins, and asserts the WITHDRAWAL changed —
 * not merely the approval_requests row. Everything is rolled back.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.
");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Zuste\Core\Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

Zuste\Core\App::boot($basePath);

$D = Zuste\Core\Database::class;

$threshold = (float) $D::scalar(
    "SELECT `threshold_amount` FROM `approval_workflows` WHERE `entity_type` = 'withdrawal' AND `is_active` = 1 LIMIT 1"
);

echo 'workflow threshold: ' . number_format($threshold, 2) . "\n\n";

$amount    = $threshold + 25000;
$creatorId = (int) $D::scalar('SELECT `user_id` FROM `withdrawal_requests` LIMIT 1');
$bankId    = $D::scalar('SELECT `id` FROM `bank_accounts` LIMIT 1');

$withdrawalId = $D::insert('withdrawal_requests', [
    'reference'       => 'ZWD-E2E-PROBE',
    'user_id'         => $creatorId,
    'bank_account_id' => $bankId,
    'amount'          => $amount,
    'net_amount'      => $amount,
    'status'          => 'pending',
    'created_at'      => date('Y-m-d H:i:s'),
]);

echo "created withdrawal #{$withdrawalId} for " . number_format($amount, 2) . "\n\n";

$failed = 0;
$reqId  = null;

try {
    $wf = Zuste\Services\ApprovalService::workflowFor('withdrawal', $amount);
    echo '1. workflowFor() => ' . ($wf === null ? "NONE  <-- the gate does not apply\n" : "\"{$wf['name']}\"\n");

    if ($wf === null) {
        $failed++;
    } else {
        $reqId = Zuste\Services\ApprovalService::open('withdrawal', $withdrawalId, $amount, 'e2e probe', 0);
        echo '2. open() => request #' . var_export($reqId, true) . "\n";

        if ($reqId === null) {
            $failed++;
        } else {
            $steps = $D::select(
                'SELECT `step_order`, `name` FROM `approval_steps` WHERE `workflow_id` = ? ORDER BY `step_order`',
                [(int) $wf['id']]
            );

            echo '   steps: ' . implode(' -> ', array_column($steps, 'name')) . "\n";

            // The payout must stay pending while the request is open.
            $mid = $D::selectOne('SELECT `status` FROM `withdrawal_requests` WHERE `id` = ?', [$withdrawalId]);
            echo "3. status while awaiting approval: {$mid['status']}";
            echo $mid['status'] === 'pending' ? "  (correctly held)\n" : "  <-- RELEASED EARLY\n";

            if ($mid['status'] !== 'pending') {
                $failed++;
            }

            // Different admin per step — the engine refuses to let one person
            // satisfy consecutive steps alone.
            //
            // The second approver is looked up rather than hardcoded. This
            // used to be `[1, 27]`, and admin 27 was a leftover fixture from
            // an older session; once it was cleaned up the probe reported the
            // ENGINE as broken ("NOT APPLIED") when the real fault was that
            // its own actor had gone. A test that fails for a reason other
            // than the one it is testing is worse than no test.
            $secondApprover = (int) (Zuste\Core\Database::scalar(
                "SELECT a.`id`
                   FROM `admins` a
                   INNER JOIN `roles` r ON r.`id` = a.`role_id`
                  WHERE a.`id` <> 1
                    AND a.`deleted_at` IS NULL
                    AND a.`status` = 'active'
                    AND r.`level` >= 100
                  ORDER BY a.`id`
                  LIMIT 1"
            ) ?? 0);

            if ($secondApprover === 0) {
                echo "  no second admin can sign off step 2 — the two-person rule cannot be exercised\n";
                $failed++;

                return;
            }

            $admins = [1, $secondApprover];

            foreach ($steps as $i => $step) {
                $adminId = $admins[$i % count($admins)];
                $r = Zuste\Services\ApprovalService::act($reqId, $adminId, 'approved', 'e2e');
                printf("4.%d act(admin=%d) => %-9s %s\n", $i + 1, $adminId, $r['status'], $r['message']);

                if ($r['completed']) {
                    break;
                }
            }

            $after = $D::selectOne('SELECT `status` FROM `withdrawal_requests` WHERE `id` = ?', [$withdrawalId]);
            echo "\n5. withdrawal status after final approval: {$after['status']}";

            if ($after['status'] === 'approved') {
                echo "  <-- APPLIED\n";
            } else {
                echo "  <-- NOT APPLIED\n";
                $failed++;
            }
        }
    }
} finally {
    if ($reqId !== null) {
        $D::execute('DELETE FROM `approval_actions` WHERE `request_id` = ?', [$reqId]);
    }

    $D::execute("DELETE FROM `approval_requests` WHERE `entity_type` = 'withdrawal' AND `entity_id` = ?", [$withdrawalId]);
    $D::execute('DELETE FROM `withdrawal_requests` WHERE `id` = ?', [$withdrawalId]);

    echo "\n(probe rolled back)\n";
}

echo $failed === 0
    ? "\nRESULT: the gate holds and the decision is applied.\n"
    : "\nRESULT: {$failed} problem(s).\n";

exit($failed === 0 ? 0 : 1);
