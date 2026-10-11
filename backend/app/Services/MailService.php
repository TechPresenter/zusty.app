<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\Logger;
use Zuste\Helpers\Str;

/**
 * SMTP mail delivery with no external dependency.
 *
 * Mail is queued to `email_queue` and drained by cron, so nothing in a user
 * request ever blocks on a mail server handshake. `sendNow()` exists for the
 * two cases that must be synchronous (password reset, OTP).
 *
 * The SMTP conversation is implemented directly over a stream socket with
 * STARTTLS/implicit-TLS support and AUTH LOGIN / PLAIN.
 */
final class MailService
{
    /**
     * The mailboxes used when settings name none. zusty.app is the mail
     * domain; zustee.com takes no mail, so it must never be a fallback.
     */
    public const DEFAULT_SUPPORT_ADDRESS = 'support@zusty.app';
    public const DEFAULT_FROM_ADDRESS    = 'no-reply@zusty.app';

    /** Used for absolute links in mail when app.url is not set. */
    private const DEFAULT_APP_URL = 'https://zusty.app';

    /**
     * Where people are told to write for help: Settings → General → support
     * email when it is a valid address, otherwise support@zusty.app. Never
     * empty, so a "contact …" sentence always names a mailbox.
     */
    public static function supportAddress(): string
    {
        $value = trim(Config::settingString('general', 'support_email', ''));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : self::DEFAULT_SUPPORT_ADDRESS;
    }

    /**
     * The envelope sender: Settings → Mail → from address, then MAIL_FROM_ADDRESS,
     * then no-reply@zusty.app.
     */
    public static function fromAddress(): string
    {
        $value = trim(self::setting('from_address', self::DEFAULT_FROM_ADDRESS));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : self::DEFAULT_FROM_ADDRESS;
    }

    /** Queue a message for the cron worker. */
    public static function queue(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        ?string $replyTo = null
    ): int {
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            Logger::warning('Refusing to queue mail to an invalid address', ['email' => Str::maskEmail($toEmail)]);

            return 0;
        }

        return Database::insert('email_queue', [
            'to_email'   => $toEmail,
            'to_name'    => mb_substr($toName, 0, 120),
            'subject'    => mb_substr($subject, 0, 255),
            'body_html'  => $htmlBody,
            'body_text'  => $textBody ?? self::htmlToText($htmlBody),
            'reply_to'   => $replyTo,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Send immediately, bypassing the queue. Returns success.
     *
     * @param bool $queueOnFailure false for mail that must arrive now or not at
     *                             all: a one-time code that turns up after the
     *                             app said it could not be sent is worse than
     *                             none, and may already have been replaced.
     */
    public static function sendNow(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        ?string $replyTo = null,
        bool $queueOnFailure = true
    ): bool {
        try {
            $sent = self::deliver($toEmail, $toName, $subject, $htmlBody, $textBody ?? self::htmlToText($htmlBody), $replyTo);

            // Only the 'mail' driver reports failure this way. Logged, not
            // queued: PHP's mail() has already handed the message on or
            // refused it, and a retry could deliver it twice.
            if (!$sent) {
                Logger::warning('Mail was not accepted for immediate delivery', ['to' => Str::maskEmail($toEmail)]);
            }

            return $sent;
        } catch (\Throwable $e) {
            Logger::error(
                $queueOnFailure
                    ? 'Immediate mail delivery failed; falling back to the queue'
                    : 'Immediate mail delivery failed; not queued',
                [
                    'to'    => Str::maskEmail($toEmail),
                    'error' => $e->getMessage(),
                ]
            );

            if (!$queueOnFailure) {
                return false;
            }

            // Fall back to the queue rather than dropping the message.
            //
            // Immediate delivery is a latency optimisation, not a different
            // kind of mail — a transient SMTP failure was silently discarding
            // the message, and callers that ignore the return value (the
            // password-reset flows do, deliberately, so they cannot be used to
            // enumerate addresses) reported success while nothing was sent.
            //
            // `email_queue` already carries attempts/available_at and
            // drainQueue() retries with backoff, so the message now survives an
            // outage and goes out on the next `queue:dispatch` tick.
            //
            // Still returns false: the caller asked for immediate delivery and
            // did not get it. What changes is that the message is no longer
            // lost — a caller that surfaces the failure still can.
            try {
                self::queue($toEmail, $toName, $subject, $htmlBody, $textBody, $replyTo);
            } catch (\Throwable $queueError) {
                Logger::error('Could not queue mail after immediate delivery failed', [
                    'to'    => Str::maskEmail($toEmail),
                    'error' => $queueError->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * Drain the queue.
     *
     * @return array{sent:int,failed:int}
     */
    public static function drainQueue(int $limit = 50): array
    {
        $jobs = Database::select(
            "SELECT * FROM `email_queue`
             WHERE `status` = 'pending' AND `available_at` <= NOW() AND `attempts` < 3
             ORDER BY `id` LIMIT " . max(1, $limit)
        );

        $sent   = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $jobId    = (int) $job['id'];
            $attempts = (int) $job['attempts'] + 1;

            Database::update('email_queue', ['status' => 'sending', 'attempts' => $attempts], ['id' => $jobId]);

            try {
                $ok = self::deliver(
                    (string) $job['to_email'],
                    (string) ($job['to_name'] ?? ''),
                    (string) $job['subject'],
                    (string) $job['body_html'],
                    (string) ($job['body_text'] ?? ''),
                    $job['reply_to'] ?? null
                );

                if ($ok) {
                    Database::update('email_queue', [
                        'status'  => 'sent',
                        'sent_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $jobId]);

                    $sent++;
                } else {
                    throw new \RuntimeException('The mail server rejected the message.');
                }
            } catch (\Throwable $e) {
                $failed++;

                Database::update('email_queue', [
                    'status'       => $attempts >= 3 ? 'failed' : 'pending',
                    'last_error'   => mb_substr($e->getMessage(), 0, 500),
                    // Exponential-ish backoff: 5min, 20min, then give up.
                    'available_at' => date('Y-m-d H:i:s', time() + (300 * $attempts * $attempts)),
                ], ['id' => $jobId]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    // -----------------------------------------------------------------
    // Delivery
    // -----------------------------------------------------------------

    private static function deliver(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        ?string $replyTo
    ): bool {
        $driver = self::setting('driver', 'smtp');

        if ($driver === 'log') {
            Logger::info('Mail (log driver)', ['to' => Str::maskEmail($toEmail), 'subject' => $subject]);

            return true;
        }

        $fromAddress = self::fromAddress();
        $fromName    = self::setting('from_name', 'Zusty');

        $boundary = 'zuste-' . bin2hex(random_bytes(12));

        $headers = [
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromAddress . '>',
            'To: ' . ($toName === '' ? $toEmail : self::encodeHeader($toName) . ' <' . $toEmail . '>'),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . self::hostname($fromAddress) . '>',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Zusty Platform',
        ];

        if ($replyTo !== null && $replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $body = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($textBody)) . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($htmlBody)) . "\r\n"
              . "--{$boundary}--\r\n";

        if ($driver === 'mail') {
            return mail(
                $toEmail,
                self::encodeHeader($subject),
                $body,
                implode("\r\n", array_slice($headers, 0, -1)) // mail() writes its own To/Subject.
            );
        }

        return self::smtpSend($fromAddress, $toEmail, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }

    /** Speak SMTP directly over a socket. */
    private static function smtpSend(string $from, string $to, string $message): bool
    {
        $host       = self::setting('host', '');
        $port       = (int) self::setting('port', '587');
        $encryption = strtolower(self::setting('encryption', 'tls'));
        $username   = self::setting('username', '');
        $password   = self::setting('password', '');
        $timeout    = (int) Config::get('mail.timeout', 20);

        if ($host === '') {
            throw new \RuntimeException('SMTP host is not configured.');
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : '';

        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException('Could not connect to the mail server: ' . (string) $errorMessage);
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::expect($socket, 220);

            $hostname = self::hostname($from);
            self::command($socket, 'EHLO ' . $hostname, 250);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', 220);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Failed to negotiate STARTTLS.');
                }

                // The EHLO must be repeated over the encrypted channel.
                self::command($socket, 'EHLO ' . $hostname, 250);
            }

            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', 334);
                self::command($socket, base64_encode($username), 334);
                self::command($socket, base64_encode($password), 235);
            }

            self::command($socket, 'MAIL FROM:<' . $from . '>', 250);
            self::command($socket, 'RCPT TO:<' . $to . '>', 250);
            self::command($socket, 'DATA', 354);

            // Dot-stuffing: a line that is just "." would end the message early.
            $payload = preg_replace('/^\./m', '..', $message) ?? $message;
            fwrite($socket, $payload . "\r\n.\r\n");
            self::expect($socket, 250);

            self::command($socket, 'QUIT', 221, false);

            return true;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** @param resource $socket */
    private static function command($socket, string $command, int $expected, bool $strict = true): string
    {
        fwrite($socket, $command . "\r\n");

        return self::expect($socket, $expected, $strict);
    }

    /** @param resource $socket */
    private static function expect($socket, int $expected, bool $strict = true): string
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            // Multi-line replies use "250-" for continuations, "250 " to end.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr(trim($response), 0, 3);

        if ($strict && $code !== $expected) {
            throw new \RuntimeException(
                sprintf('SMTP expected %d but received: %s', $expected, trim($response))
            );
        }

        return $response;
    }

    // -----------------------------------------------------------------
    // Templating
    // -----------------------------------------------------------------

    /**
     * Branded fallback template, styled with the Vibrant Influence palette.
     * Inline CSS only — every mail client strips <style> blocks.
     *
     * [$title] and [$body] are TEXT. They are escaped here, and line breaks in
     * the body become <br>: NotificationService renders every template with no
     * email_body of its own through this, and its values are user- and
     * admin-written (names, reasons), so "Name on PAN <RAHUL K>" must arrive
     * as text, never as markup. See {@see bodyHtml()} for the two formatting
     * tags older callers already rely on.
     */
    public static function renderDefault(
        string $title,
        string $body,
        string $recipientName = '',
        string $actionType = 'none',
        ?string $actionValue = null
    ): string {
        return self::layout(Str::e($title), self::bodyHtml($body), $recipientName, $actionType, $actionValue);
    }

    /**
     * The body text as HTML: everything escaped, newlines turned into <br>.
     *
     * Two admin callers hand over a fragment that is already escaped and uses
     * formatting tags — the admin invite (`<strong>` around the temporary
     * password) and the contact-form reply (nl2br(htmlspecialchars())). So
     * existing entities are not encoded twice, and exactly `<strong>`,
     * `</strong>` and `<br>` (in its `<br/>` / `<br />` spellings) survive,
     * with no attributes. Every other `<` is escaped, so nothing a user or
     * admin types can become markup.
     */
    private static function bodyHtml(string $body): string
    {
        $html = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);

        $html = (string) preg_replace('#&lt;(/?strong|br\s*/?)&gt;#i', '<$1>', $html);

        // A line break already following a <br> (nl2br output) is left alone.
        // CRLF is one break: its "\n" is never matched on its own.
        return (string) preg_replace(
            '#(?<!<br />)(?<!<br/>)(?<!<br>)(\r\n|\r(?!\n)|(?<!\r)\n)#i',
            '<br />$1',
            $html
        );
    }

    /**
     * The branded shell. [$titleHtml] and [$bodyHtml] are already HTML;
     * [$afterBodyHtml] is trusted markup placed under the body paragraph.
     */
    private static function layout(
        string $titleHtml,
        string $bodyHtml,
        string $recipientName = '',
        string $actionType = 'none',
        ?string $actionValue = null,
        string $afterBodyHtml = '',
        ?string $footerReason = null
    ): string {
        $title    = $titleHtml;
        $body     = $bodyHtml;
        $appName  = Config::settingString('general', 'app_name', 'Zusty');
        $appUrl   = trim((string) Config::get('app.url', '')) ?: self::DEFAULT_APP_URL;
        $support  = self::supportAddress();
        $year     = date('Y');

        // Operator settings are text too.
        $appNameHtml = Str::e($appName);
        $supportHtml = Str::e($support);
        $reasonHtml  = Str::e($footerReason ?? 'You are receiving this email because you have an account on ' . $appName . '.');

        // Absolute, because a mail client has no base URL. Flattened onto white
        // so a dark-mode client can't lose the navy ".app" against its own
        // background.
        $logoUrl  = Str::e(rtrim($appUrl, '/') . '/admin/assets/img/zusty-logo-email.png');

        $greeting = $recipientName === '' ? 'Hello,' : 'Hi ' . Str::e($recipientName) . ',';

        $button = '';
        if ($actionType !== 'none' && $actionValue !== null && $actionValue !== '') {
            $url = str_starts_with($actionValue, 'http') ? $actionValue : $appUrl;

            $button = '<tr><td style="padding:8px 32px 32px">'
                . '<a href="' . Str::e($url) . '" style="display:inline-block;background:#B80049;color:#ffffff;'
                . 'text-decoration:none;font-weight:600;font-size:15px;padding:14px 28px;border-radius:12px">'
                . 'Open ' . Str::e($appName) . '</a></td></tr>';
        }

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title></head>
<body style="margin:0;padding:0;background:#FFF8F7;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#28171A">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF8F7;padding:32px 16px">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:24px;overflow:hidden;box-shadow:0 4px 24px rgba(40,23,26,0.06)">
      <tr><td style="padding:32px 32px 8px">
        <img src="{$logoUrl}" width="166" height="36" alt="Zusty.app" style="display:block;border:0;outline:none;text-decoration:none;font-size:24px;font-weight:800;color:#28171A">
        <div style="font-size:10px;letter-spacing:0.18em;text-transform:uppercase;color:#8F6F73;font-weight:600;margin-top:8px">Shoot. Connect. Grow.</div>
      </td></tr>
      <tr><td style="padding:24px 32px 0">
        <p style="margin:0 0 12px;font-size:14px;color:#5B3F43">{$greeting}</p>
        <h1 style="margin:0 0 12px;font-size:22px;line-height:30px;font-weight:700;color:#28171A">{$title}</h1>
        <p style="margin:0;font-size:15px;line-height:24px;color:#5B3F43">{$body}</p>{$afterBodyHtml}
      </td></tr>
      {$button}
      <tr><td style="padding:0 32px 32px">
        <div style="height:1px;background:#FADBDE;margin:8px 0 20px"></div>
        <p style="margin:0;font-size:12px;line-height:18px;color:#8F6F73">
          Need help? Write to <a href="mailto:{$supportHtml}" style="color:#632CE5;text-decoration:none">{$supportHtml}</a>.
        </p>
        <p style="margin:12px 0 0;font-size:11px;line-height:16px;color:#8F6F73">
          &copy; {$year} {$appNameHtml}. All rights reserved.<br>
          {$reasonHtml}
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
    }

    /**
     * Purposes whose code confirms an action inside a signed-in account, and
     * what the code is for. A code nobody asked for there means somebody else
     * is signed in, so "ignore this email" is the wrong advice.
     */
    private const STEP_UP_OTP_PURPOSES = [
        'data_export'            => 'confirm your data export',
        'delete_account'         => 'confirm that you want to delete your account',
        'set_password'           => 'add a password to your account',
        'confirm_contact_change' => 'confirm the change to your email address or mobile number',
    ];

    /** OTP email with a large, copyable code. */
    public static function renderOtp(string $code, string $purpose, int $validMinutes): string
    {
        $key    = str_replace(' ', '_', strtolower(trim($purpose)));
        $stepUp = self::STEP_UP_OTP_PURPOSES[$key] ?? null;
        $login  = $key === 'login';

        // Plain text: the layout escapes it (this used to be pre-escaped and
        // then string-replaced back into the rendered page).
        // A sign-in code goes to whatever address was typed, account or not
        // (the send never says which), so its wording holds for both.
        $body = ($login
                ? 'Use this code to sign in to Zusty. '
                : 'Use the verification code below to ' . ($stepUp ?? str_replace('_', ' ', $purpose)) . '. ')
              . 'It expires in ' . $validMinutes . ' minutes. '
              . ($stepUp !== null
                  ? 'If you did not ask for this, someone may be signed in to your account: sign out of all devices and change your password.'
                  : ($login
                      ? "Never share it: nobody from Zusty will ask for it. If you don't have an account, or didn't try to sign in, you can ignore this email."
                      : 'If you did not request this, you can ignore this email.'));

        $codeBlock = '<div style="margin:20px 0;padding:20px;background:#FFF0F1;border-radius:16px;text-align:center">'
            . '<div style="font-size:34px;font-weight:800;letter-spacing:0.32em;color:#B80049;font-family:monospace">'
            . Str::e($code) . '</div></div>';

        return self::layout(
            Str::e($login ? 'Your sign-in code' : 'Your verification code'),
            self::bodyHtml($body),
            '',
            'none',
            null,
            $codeBlock,
            // Only a step-up code is sure to reach an account holder.
            $stepUp !== null ? null : 'You are receiving this email because this address was entered in Zusty.'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** DB settings win over .env so an operator can fix mail without a deploy. */
    private static function setting(string $key, string $default = ''): string
    {
        $value = Config::settingString('mail', $key, '');

        return $value !== '' ? $value : (string) Config::get('mail.' . $key, $default);
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[\x80-\xFF]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : '"' . str_replace(['"', "\r", "\n"], '', $value) . '"';
    }

    private static function hostname(string $email): string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? 'localhost';
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|div|h[1-6]|tr)>#i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
