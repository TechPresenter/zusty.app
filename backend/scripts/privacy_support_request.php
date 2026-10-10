<?php

declare(strict_types=1);

/**
 * Act on a privacy request someone could not make in the app: erase their
 * account, or email them a copy of its data. The command-line twin of Admin →
 * Privacy requests → Act on a request.
 *
 *   php scripts/privacy_support_request.php delete --user=<id|email> --admin=<id|email>
 *       [--reason=<code>] [--note=<text>] [--forfeit=<amount>] [--confirm]
 *   php scripts/privacy_support_request.php export --user=<id|email> --admin=<id|email>
 *   php scripts/privacy_support_request.php reapply-erasures
 *
 * First confirm that the request comes from the account's owner, for example
 * by writing to the account's own email address and getting an answer
 * (docs/ADMIN-MANUAL.md, "Privacy requests"). --admin names the staff member
 * acting, who must have an active admin account; both actions are recorded
 * against them.
 *
 * delete shows what the erasure would do (anything that blocks it, a wallet
 * balance that would be given up, what is erased and what is kept) and
 * changes nothing until it is run again with --confirm. It is the same
 * erasure the app runs, with the same blockers, recorded as a support request
 * (verified_via operator, platform admin). --note says how the request was
 * confirmed and is stored with the deletion record. --forfeit gives up a
 * small balance that cannot be paid out, on the owner's behalf; it must equal
 * the amount shown.
 *
 * export prepares the archive and emails its download link to the account's
 * own address. It prints only where the email went and until when the link
 * works: the link is a credential and is never printed.
 *
 * reapply-erasures erases again every account recorded in
 * storage/privacy/erasure-ledger.jsonl that a database restore brought back.
 * deploy.sh --rollback and a restore from the admin panel do this by
 * themselves; run it after any other restore.
 *
 * Exit codes: 0 done (or a delete preview), 1 refused or failed.
 */

use Zuste\Core\App;
use Zuste\Core\Autoloader;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;
use Zuste\Services\AccountDeletionService;
use Zuste\Services\DataExportService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

(new Autoloader($basePath . '/storage/cache/classmap.php'))
    ->addNamespace('Zuste', $basePath . '/app')
    ->register();

App::boot($basePath);

// -----------------------------------------------------------------
// Arguments
// -----------------------------------------------------------------

$arguments = array_slice($argv, 1);
$command   = (string) ($arguments[0] ?? '');
$options   = [];
$flags     = [];

foreach (array_slice($arguments, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $m) === 1) {
        $options[$m[1]] = trim($m[2]);
    } elseif (preg_match('/^--([a-z-]+)$/', $argument, $m) === 1) {
        $flags[$m[1]] = true;
    } else {
        refuse('Unexpected argument: ' . $argument);
    }
}

if (!in_array($command, ['delete', 'export', 'reapply-erasures'], true)) {
    fwrite(STDERR, "\nAct on a privacy request made outside the app\n\n");
    fwrite(STDERR, "  php scripts/privacy_support_request.php delete --user=<id|email> --admin=<id|email>\n");
    fwrite(STDERR, "      [--reason=<code>] [--note=<text>] [--forfeit=<amount>] [--confirm]\n");
    fwrite(STDERR, "  php scripts/privacy_support_request.php export --user=<id|email> --admin=<id|email>\n");
    fwrite(STDERR, "  php scripts/privacy_support_request.php reapply-erasures\n\n");
    exit(1);
}

if ($command === 'reapply-erasures') {
    try {
        $count = AccountDeletionService::reapplyAfterRestore();
    } catch (Throwable $e) {
        refuse('The erasures could not be re-applied: ' . $e->getMessage());
    }

    echo 'Erasures re-applied: ' . $count . ".\n";
    exit(0);
}

$user  = findUser((string) ($options['user'] ?? ''));
$admin = findAdmin((string) ($options['admin'] ?? ''));

echo "\n" . describe($user) . "\n";

// -----------------------------------------------------------------
// export
// -----------------------------------------------------------------

if ($command === 'export') {
    if ($user['deleted_at'] !== null) {
        refuse('This account has been deleted, so there is no copy of its data to make.');
    }

    try {
        $export = DataExportService::create(
            $user,
            'operator',
            ['user_agent' => 'scripts/privacy_support_request.php'],
            (int) $admin['id']
        );
    } catch (HttpException $e) {
        refuse('No copy was made. ' . $e->getMessage());
    }

    $notice  = is_array($export['security_notice'] ?? null) ? $export['security_notice'] : null;
    $expires = !empty($export['expires_at']) ? strtotime((string) $export['expires_at']) : false;

    if ($notice === null || (string) ($notice['destination'] ?? '') === '') {
        refuse('A copy of the data is ready, but the email with its download link could not be sent. The link is never shown here.');
    }

    echo 'A copy of the data is ready. The download link was emailed to ' . $notice['destination']
        . ($expires !== false ? ' and works until ' . date('j M Y, g:i A', $expires) : '') . ".\n\n";
    exit(0);
}

// -----------------------------------------------------------------
// delete
// -----------------------------------------------------------------

$reasons = array_column(AccountDeletionService::reasons((string) $user['account_type']), 'code');
$reason  = (string) ($options['reason'] ?? 'other');

if (!in_array($reason, $reasons, true)) {
    refuse('Unknown --reason "' . $reason . '". Use one of: ' . implode(', ', $reasons) . '.');
}

$note = (string) ($options['note'] ?? '');

if (mb_strlen($note) > 500) {
    refuse('--note can be at most 500 characters.');
}

$forfeitAmount = null;

if (isset($options['forfeit'])) {
    if (!is_numeric($options['forfeit']) || (float) $options['forfeit'] < 0) {
        refuse('--forfeit must be an amount of 0 or more.');
    }

    $forfeitAmount = (float) $options['forfeit'];
}

$erasure = Database::selectOne(
    'SELECT `id`, `created_at` FROM `account_deletion_requests` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 1',
    ['id' => (int) $user['id']]
);

if ($erasure !== null) {
    echo 'This account was already erased on ' . date('j M Y', (int) strtotime((string) $erasure['created_at']))
        . ' (deletion #' . (int) $erasure['id'] . "). Nothing to do.\n\n";
    exit(0);
}

$preflight = AccountDeletionService::preflight($user);

echo "\nWhat must be settled first:\n";

if (($preflight['blockers'] ?? []) === []) {
    echo "  nothing\n";
} else {
    foreach ($preflight['blockers'] as $blocker) {
        echo '  - ' . $blocker['title'] . ': ' . $blocker['message'] . "\n";
    }
}

$forfeit = is_array($preflight['forfeit'] ?? null) ? $preflight['forfeit'] : null;

if ($forfeit !== null) {
    echo "\nWallet balance that cannot be paid out: " . $forfeit['label'] . "\n";
    echo '  The erasure gives it up only with --forfeit=' . number_format((float) $forfeit['amount'], 2, '.', '') . ".\n";
}

$erasedTitles = array_column((array) ($preflight['erased'] ?? []), 'title');
$kept         = [];

foreach ((array) ($preflight['retained_counts'] ?? []) as $key => $count) {
    if ((int) $count > 0) {
        $kept[] = (int) $count . ' ' . str_replace('_', ' ', (string) $key);
    }
}

echo "\nErased (" . count($erasedTitles) . '): ' . implode(', ', $erasedTitles) . "\n";
echo 'Kept: ' . ($kept === [] ? 'no records' : implode(', ', $kept)) . "\n";

if (empty($flags['confirm'])) {
    echo "\nNothing was changed. Run again with --confirm to erase this account"
        . ($note === '' ? ', and add --note="how the request was confirmed"' : '') . ".\n\n";
    exit(0);
}

try {
    $summary = AccountDeletionService::delete($user, 'operator', $reason, null, [
        'admin_id'        => (int) $admin['id'],
        'platform'        => 'admin',
        'note'            => $note === '' ? 'Support request, handled on the command line.' : $note,
        'via'             => 'cli',
        'forfeit_balance' => $forfeitAmount,
    ]);
} catch (HttpException $e) {
    $messages = [];

    foreach ($e->errors() as $lines) {
        foreach ((array) $lines as $line) {
            $messages[] = (string) $line;
        }
    }

    refuse('The account was not erased. ' . ($messages === [] ? $e->getMessage() : implode(' ', array_unique($messages))));
}

if (!empty($summary['already_deleted'])) {
    echo "\nThis account was already erased. Nothing was changed.\n\n";
    exit(0);
}

echo "\nAccount #" . (int) $user['id'] . ' erased (deletion #' . (int) $summary['deletion_id'] . ").\n";

if (is_array($summary['forfeited'] ?? null)) {
    echo 'Given up from the wallet: '
        . Money::format((float) $summary['forfeited']['amount'], isset($summary['forfeited']['currency']) ? (string) $summary['forfeited']['currency'] : null)
        . ".\n";
}

$notice = is_array($summary['notice'] ?? null) ? $summary['notice'] : null;

echo $notice !== null
    ? 'A confirmation was sent to ' . $notice['destination'] . ".\n\n"
    : "No confirmation could be sent: the account had no email address or reachable phone.\n\n";

exit(0);

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------

/** Print a reason to stderr and stop with exit code 1. */
function refuse(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * The brand or creator account named by --user, deleted or not: an account
 * staff soft-deleted earlier still holds its personal data.
 *
 * @return array<string,mixed>
 */
function findUser(string $value): array
{
    if ($value === '') {
        refuse('Name the account with --user=<id or email>.');
    }

    $user = ctype_digit($value)
        ? Database::selectOne('SELECT * FROM `users` WHERE `id` = :id', ['id' => (int) $value])
        // A deleted account frees its address for a new sign-up, so two rows
        // can share one: the live account wins.
        : Database::selectOne(
            'SELECT * FROM `users` WHERE `email` = :email ORDER BY (`deleted_at` IS NULL) DESC, `id` DESC LIMIT 1',
            ['email' => $value]
        );

    if ($user === null) {
        refuse('No account matches --user=' . $value . '.');
    }

    if (!in_array((string) $user['account_type'], ['brand', 'creator'], true)) {
        refuse('Account #' . (int) $user['id'] . ' is a guest account. Guests give no name or contact details, so there is nothing to export or erase.');
    }

    return $user;
}

/**
 * The staff member acting, named by --admin: an active admin account.
 *
 * @return array<string,mixed>
 */
function findAdmin(string $value): array
{
    if ($value === '') {
        refuse('Name yourself with --admin=<your admin id or email>.');
    }

    $admin = Database::selectOne(
        ctype_digit($value)
            ? 'SELECT `id`, `name`, `status`, `deleted_at` FROM `admins` WHERE `id` = :v'
            : 'SELECT `id`, `name`, `status`, `deleted_at` FROM `admins` WHERE `email` = :v',
        ['v' => ctype_digit($value) ? (int) $value : $value]
    );

    if ($admin === null || $admin['status'] !== 'active' || $admin['deleted_at'] !== null) {
        refuse('--admin=' . $value . ' is not an active admin account.');
    }

    return $admin;
}

/** One line naming the account, with its contact details masked. */
function describe(array $user): string
{
    $email = trim((string) ($user['email'] ?? ''));
    $phone = trim((string) ($user['phone'] ?? ''));

    return 'Account #' . (int) $user['id'] . ' · ' . $user['account_type'] . ' · ' . $user['name']
        . ($email !== '' ? ' · ' . Str::maskEmail($email) : '')
        . ($phone !== '' ? ' · ' . Str::maskPhone($phone) : '')
        . ' · ' . $user['status']
        . ($user['deleted_at'] !== null ? ' (deleted ' . date('j M Y', (int) strtotime((string) $user['deleted_at'])) . ')' : '');
}
