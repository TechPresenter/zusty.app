<?php

declare(strict_types=1);

/**
 * A one-shot SMTP server for the integration suite: it accepts mail and
 * writes every message down instead of delivering it.
 *
 *     php tests/fixtures/smtp_sink.php <state-file> [max-messages] [seconds]
 *
 * Listens on 127.0.0.1 on a free port and, once listening, writes
 * {"port": N} to <state-file>. Every message received is appended to the
 * same directory's "<state-file>.mail" as one JSON line: {from, to, data},
 * with `data` the raw message (headers and body) as the client sent it, dot
 * stuffing undone. Exits after [max-messages] messages (default 1) or
 * [seconds] seconds (default 60), whichever comes first.
 *
 * Plain SMTP only (no TLS): point MailService at it with driver smtp, host
 * 127.0.0.1, the port, and encryption none. AUTH LOGIN is accepted whatever
 * the credentials, and they are never written down.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$state   = (string) ($argv[1] ?? '');
$max     = max(1, (int) ($argv[2] ?? 1));
$seconds = max(1, (int) ($argv[3] ?? 60));

if ($state === '') {
    fwrite(STDERR, "usage: php smtp_sink.php <state-file> [max-messages] [seconds]\n");
    exit(2);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    file_put_contents($state, json_encode(['error' => $errstr]));
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);

file_put_contents($state, json_encode(['port' => $port]));

$deadline = time() + $seconds;
$received = 0;

while ($received < $max && time() < $deadline) {
    $client = @stream_socket_accept($server, 1);

    if ($client === false) {
        continue;
    }

    stream_set_timeout($client, 15);
    fwrite($client, "220 zuste-sink ESMTP\r\n");

    $from   = '';
    $to     = [];
    $inData = false;
    $data   = '';
    $auth   = 0; // AUTH LOGIN lines still to come (username, password)

    while (($line = fgets($client, 8192)) !== false) {
        if ($auth > 0) {
            // Any credentials are accepted and never written down.
            $auth--;
            fwrite($client, $auth > 0 ? "334 UGFzc3dvcmQ6\r\n" : "235 Authenticated\r\n");
            continue;
        }

        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                $received++;

                file_put_contents(
                    $state . '.mail',
                    json_encode(['from' => $from, 'to' => $to, 'data' => $data]) . "\n",
                    FILE_APPEND | LOCK_EX
                );

                fwrite($client, "250 OK: kept\r\n");
                continue;
            }

            // Undo dot-stuffing.
            $data .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            continue;
        }

        $command = strtoupper(substr(trim($line), 0, 4));

        switch ($command) {
            case 'EHLO':
            case 'HELO':
                fwrite($client, "250 zuste-sink\r\n");
                break;

            case 'MAIL':
                $from = trim((string) preg_replace('/^MAIL FROM:\s*/i', '', trim($line)), '<>');
                fwrite($client, "250 OK\r\n");
                break;

            case 'RCPT':
                $to[] = trim((string) preg_replace('/^RCPT TO:\s*/i', '', trim($line)), '<>');
                fwrite($client, "250 OK\r\n");
                break;

            case 'DATA':
                $inData = true;
                $data   = '';
                fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
                break;

            case 'AUTH':
                $auth = 2;
                fwrite($client, "334 VXNlcm5hbWU6\r\n");
                break;

            case 'RSET':
            case 'NOOP':
                fwrite($client, "250 OK\r\n");
                break;

            case 'QUIT':
                fwrite($client, "221 Bye\r\n");
                break 2;

            default:
                fwrite($client, "502 Not implemented\r\n");
        }
    }

    fclose($client);
}

fclose($server);
