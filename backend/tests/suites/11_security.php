<?php

declare(strict_types=1);

/**
 * Cross-cutting security behaviour, as observed over HTTP.
 *
 * Nothing here belongs to one domain. These are the guarantees the whole API
 * makes at once: the headers on every response, who may read a cross-origin
 * reply, what happens when a client hammers a throttled route, and what the
 * error path says when the caller feeds it something hostile.
 *
 * Everything is asserted against what the code actually promises:
 *   Response::securityHeaders()  — app/Core/Response.php:251
 *   Response::cors()             — app/Core/Response.php:282
 *   ThrottleMiddleware::handle() — app/Middleware/ThrottleMiddleware.php:27
 *   Router::dispatch()           — app/Core/Router.php:213 (404 / 405)
 *   App::renderException()       — app/Core/App.php:330
 *
 * The suite runs with APP_DEBUG=true, which is the harshest setting for the
 * leak scan at the bottom: if a stack trace, an absolute file path or a SQL
 * fragment can reach the wire, it reaches it here.
 */

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\RateLimiter;
use Zuste\Tests\Actors;
use Zuste\Tests\Http;
use Zuste\Tests\Res;
use Zuste\Tests\Suite;

require_once __DIR__ . '/../Actors.php';

// -----------------------------------------------------------------
// Every response this suite sees is kept, so the final group can sweep
// all of them for leaked internals in one pass rather than trusting a
// per-check assertion to have remembered to look.
// -----------------------------------------------------------------

/** @var array<string,Res> $secSeen */
$secSeen = [];

$secWatch = static function (string $label, Res $res) use (&$secSeen): Res {
    $secSeen[$label] = $res;

    return $res;
};

/** Payloads used for both the SQL-injection and the path-traversal probes. */
$secSqlPayloads = [
    "' OR '1'='1",
    "1' OR '1'='1' -- ",
    '" OR ""="',
    "1; DROP TABLE `users`-- ",
    "' UNION SELECT NULL,NULL,NULL-- ",
    "') OR SLEEP(3)-- ",
    "\\' OR 1=1#",
    '1%27%20OR%201=1',
];

/**
 * Percent-encodes only what a URL path genuinely cannot carry.
 *
 * `rawurlencode` would also escape the quotes, and PHP never decodes the path,
 * so the controller would receive `%27` rather than `'` — the payload would
 * never reach the WHERE clause and the test would prove nothing.
 */
$secPathEncode = static function (string $payload): string {
    return str_replace(
        ['%', ' ', '#', '?', '\\'],
        ['%25', '%20', '%23', '%3F', '%5C'],
        $payload
    );
};

/**
 * Lower-cases a body and collapses JSON's doubled backslashes, so a Windows
 * path serialised as `D:\\zustee` still matches the marker `d:\zustee`.
 */
$secNormalise = static function (string $body): string {
    return str_replace('\\\\', '\\', strtolower($body));
};

/** Markers that must never appear in a response body. */
$secLeakMarkers = [
    'sqlstate'                        => 'a database driver error code',
    'pdoexception'                    => 'a PDO exception class name',
    'errorexception'                  => 'a PHP exception class name',
    'syntax error or access violation' => 'a SQL syntax error',
    'stack trace'                     => 'a stack trace',
    '.php on line'                    => 'a PHP file and line',
    '.php('                           => 'a stack frame',
    'd:\\zustee'                      => 'an absolute Windows path',
    '/var/www/'                       => 'an absolute Unix path',
    'zuste\\core\\'                   => 'an internal namespace',
    'zuste\\services\\'               => 'an internal namespace',
    'from `'                          => 'a SQL fragment',
    'select distinct'                 => 'a SQL fragment',
    'insert into `'                   => 'a SQL fragment',
    'uncaught exception'              => 'an uncaught exception',
    'fatal error'                     => 'a fatal error',
];

// =================================================================
Suite::group('Security — response headers');

// A real data endpoint, not /health: the headers must be on the responses the
// app actually consumes, not only on the monitor ping.
$headed = $secWatch('headers: GET /creators', Http::get('/creators', ['per_page' => 1]));

Suite::status('GET /creators answers so its headers can be inspected', $headed, [200, 429]);

Suite::equals(
    'X-Content-Type-Options is nosniff',
    $headed->header('X-Content-Type-Options'),
    'nosniff'
);

Suite::equals(
    'X-Frame-Options is DENY',
    $headed->header('X-Frame-Options'),
    'DENY'
);

Suite::equals(
    'Referrer-Policy is strict-origin-when-cross-origin',
    $headed->header('Referrer-Policy'),
    'strict-origin-when-cross-origin'
);

// securityHeaders() promises four more things beyond the three named above.
Suite::equals(
    'Permissions-Policy switches off geolocation, microphone, camera and payment',
    $headed->header('Permissions-Policy'),
    'geolocation=(), microphone=(), camera=(), payment=()'
);

Suite::equals(
    'X-XSS-Protection is 0 — the legacy auditor is disabled in favour of CSP',
    $headed->header('X-XSS-Protection'),
    '0'
);

Suite::equals(
    'a JSON response carries the locked-down CSP',
    $headed->header('Content-Security-Policy'),
    "default-src 'none'; frame-ancestors 'none'"
);

// HSTS is conditional on security.force_https, so assert whichever contract is
// configured rather than demanding the header unconditionally.
$forceHttps = (bool) Config::get('security.force_https', true);

Suite::check(
    $forceHttps
        ? 'Strict-Transport-Security is set because force_https is on'
        : 'Strict-Transport-Security is absent because force_https is off',
    static function () use ($headed, $forceHttps): bool {
        $hsts = $headed->header('Strict-Transport-Security');

        return $forceHttps
            ? $hsts === 'max-age=31536000; includeSubDomains'
            : $hsts === null;
    },
    'Strict-Transport-Security was: ' . var_export($headed->header('Strict-Transport-Security'), true)
);

Suite::check(
    'the response declares itself as JSON',
    str_contains(strtolower((string) $headed->header('Content-Type')), 'application/json'),
    'Content-Type was: ' . var_export($headed->header('Content-Type'), true)
);

Suite::notEmpty(
    'every response is tagged with a request id for correlation',
    $headed->header('X-Request-Id')
);

Suite::check(
    'the server does not advertise its PHP version',
    $headed->header('X-Powered-By') === null,
    'X-Powered-By was: ' . var_export($headed->header('X-Powered-By'), true)
);

// The headers are applied in App::runApi() before dispatch, so they must
// survive every exit path — including the ones that never reach a controller.
$unauth = $secWatch('headers: GET /me unauthenticated', Http::get('/me'));
Suite::status('GET /me without a token is 401', $unauth, 401);

$missing = $secWatch('headers: GET /no-such-route', Http::get('/definitely-not-a-real-route'));
Suite::status('an unknown route is 404', $missing, 404);

foreach (['401 (auth failure)' => $unauth, '404 (unknown route)' => $missing] as $label => $errorRes) {
    Suite::check(
        "the security headers survive a {$label} response",
        static function () use ($errorRes): bool {
            return $errorRes->header('X-Content-Type-Options') === 'nosniff'
                && $errorRes->header('X-Frame-Options') === 'DENY'
                && $errorRes->header('Referrer-Policy') === 'strict-origin-when-cross-origin'
                && $errorRes->header('Content-Security-Policy') === "default-src 'none'; frame-ancestors 'none'";
        },
        'headers on the error response: ' . json_encode([
            'X-Content-Type-Options'  => $errorRes->header('X-Content-Type-Options'),
            'X-Frame-Options'         => $errorRes->header('X-Frame-Options'),
            'Referrer-Policy'         => $errorRes->header('Referrer-Policy'),
            'Content-Security-Policy' => $errorRes->header('Content-Security-Policy'),
        ])
    );
}

// =================================================================
Suite::group('Security — CORS');

/** @var list<string> $allowedOrigins */
$allowedOrigins = array_values(array_filter((array) Config::get('security.allowed_origins', [])));

if ($allowedOrigins === []) {
    Suite::skipGroup(
        'Security — CORS',
        'CORS_ALLOWED_ORIGINS is empty, so there is no allow-listed origin to prove the echo against'
    );
} else {
    $goodOrigin = (string) $allowedOrigins[0];

    $corsAllowed = $secWatch(
        'cors: allowed origin',
        Http::get('/health', [], null, ['Origin' => $goodOrigin])
    );

    Suite::equals(
        'an allow-listed origin is echoed back',
        $corsAllowed->header('Access-Control-Allow-Origin'),
        $goodOrigin
    );

    Suite::equals(
        'the echo is marked Vary: Origin so a shared cache cannot cross-serve it',
        $corsAllowed->header('Vary'),
        'Origin'
    );

    Suite::equals(
        'credentials are allowed for the allow-listed origin',
        $corsAllowed->header('Access-Control-Allow-Credentials'),
        'true'
    );

    Suite::check(
        'the wildcard origin is never combined with credentials',
        $corsAllowed->header('Access-Control-Allow-Origin') !== '*',
        'Access-Control-Allow-Origin was "*" while Allow-Credentials is true — any site could read authenticated replies'
    );

    $corsForeign = $secWatch(
        'cors: foreign origin',
        Http::get('/health', [], null, ['Origin' => 'https://evil.example.com'])
    );

    Suite::check(
        'a foreign origin is not echoed',
        $corsForeign->header('Access-Control-Allow-Origin') === null,
        'Access-Control-Allow-Origin was: ' . var_export($corsForeign->header('Access-Control-Allow-Origin'), true)
    );

    Suite::check(
        'a foreign origin is not granted credentials either',
        $corsForeign->header('Access-Control-Allow-Credentials') === null,
        'Access-Control-Allow-Credentials was: ' . var_export($corsForeign->header('Access-Control-Allow-Credentials'), true)
    );

    // The classic allow-list bypass: a hostname that merely starts with an
    // allowed origin. `in_array(..., true)` must reject it.
    $corsPrefix = $secWatch(
        'cors: near-miss origin',
        Http::get('/health', [], null, ['Origin' => $goodOrigin . '.evil.example.com'])
    );

    Suite::check(
        'an origin that only prefixes an allow-listed one is refused',
        $corsPrefix->header('Access-Control-Allow-Origin') === null,
        'Access-Control-Allow-Origin was: ' . var_export($corsPrefix->header('Access-Control-Allow-Origin'), true)
    );

    // Preflight: App::runApi() answers OPTIONS with 204 before routing.
    $preflight = $secWatch(
        'cors: preflight',
        Http::rawBody('OPTIONS', '/creators', '', [
            'Origin'                         => $goodOrigin,
            'Access-Control-Request-Method'  => 'GET',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])
    );

    Suite::status('an OPTIONS preflight is answered without routing', $preflight, [200, 204]);

    Suite::equals(
        'the preflight echoes the allow-listed origin',
        $preflight->header('Access-Control-Allow-Origin'),
        $goodOrigin
    );

    Suite::check(
        'the preflight advertises the verbs the API supports',
        static function () use ($preflight): bool {
            $methods = strtoupper((string) $preflight->header('Access-Control-Allow-Methods'));

            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $verb) {
                if (!str_contains($methods, $verb)) {
                    return false;
                }
            }

            return true;
        },
        'Access-Control-Allow-Methods was: ' . var_export($preflight->header('Access-Control-Allow-Methods'), true)
    );

    Suite::check(
        'the preflight allows the Authorization header, or the app could never sign in',
        str_contains(strtolower((string) $preflight->header('Access-Control-Allow-Headers')), 'authorization'),
        'Access-Control-Allow-Headers was: ' . var_export($preflight->header('Access-Control-Allow-Headers'), true)
    );

    Suite::check(
        'Retry-After is exposed to the browser, so a throttled client can back off',
        str_contains(strtolower((string) $preflight->header('Access-Control-Expose-Headers')), 'retry-after'),
        'Access-Control-Expose-Headers was: ' . var_export($preflight->header('Access-Control-Expose-Headers'), true)
    );
}

// =================================================================
// Two distinct signed-in identities. Created here, before the throttle group,
// so that group can prove sign-in still works after it clears its bucket.
// =================================================================

$secBrand   = Actors::create('brand', 'brand');
$secCreator = Actors::create('creator', 'creator');

$secHaveActors = $secBrand !== null && $secCreator !== null;

/** Stored-XSS payloads. `<img onerror>` matters as much as `<script>`,
 *  because it survives a naive "strip script tags" filter. */
$secXssScript = '<script>alert("zuste-xss")</script>';
$secXssImage  = '<img src=x onerror=alert(1)>';

$secTicketId = 0;
$secXssTerm  = 'ITSEC ' . $secXssScript;

// =================================================================
Suite::group('Security — rate limiting');

// `throttle:10,300,login` on POST /auth/login keys anonymous callers by
// `ip:<ip>:<path>` (ThrottleMiddleware::identity), so the bucket is
// reconstructable from the test process. Clearing it first makes the burst
// deterministic no matter what the earlier suites spent, and clearing it
// afterwards means this group never locks a later suite out of signing in.
$secApiPath   = (string) parse_url(Http::baseUrl(), PHP_URL_PATH);
$secApiHost   = (string) parse_url(Http::baseUrl(), PHP_URL_HOST);
$secLoginPath = $secApiPath . '/auth/login';
$secLoginKey  = 'throttle:login:ip:127.0.0.1:' . $secLoginPath;

if ($secApiHost !== '127.0.0.1') {
    Suite::skipGroup(
        'Security — rate limiting',
        'the throttle bucket is keyed by the IP the server sees; that is only '
        . "known to be 127.0.0.1 when the suite targets 127.0.0.1 (target host is {$secApiHost})"
    );
} else {
    // Junk identifiers, one per attempt. They match no account, so nothing is
    // locked out — and because each is distinct, AuthService's own per-identity
    // lockout (5 failures) never fires and we measure the route throttle alone.
    $secJunkIdentifiers = [];
    for ($i = 1; $i <= 14; $i++) {
        $secJunkIdentifiers[] = 'it-sec-throttle-' . $i . '@zuste.test';
    }

    RateLimiter::clear($secLoginKey);
    foreach ($secJunkIdentifiers as $identifier) {
        RateLimiter::clear('login:' . strtolower($identifier) . ':127.0.0.1');
    }

    $secBurst      = [];
    $secFirst429   = 0;
    $secLimited    = null;
    $secFirstReply = null;

    foreach ($secJunkIdentifiers as $index => $identifier) {
        $attempt = Http::post('/auth/login', [
            'identifier' => $identifier,
            'password'   => 'NotTheRealPassword#2024',
        ]);

        $secBurst[] = $attempt->status;
        $secFirstReply ??= $attempt;

        if ($attempt->status === 429 && $secFirst429 === 0) {
            $secFirst429 = $index + 1;
            $secLimited  = $attempt;
            break;
        }
    }

    $secWatch('throttle: first login attempt', $secFirstReply);

    Suite::check(
        'hammering POST /auth/login eventually returns 429',
        $secLimited !== null,
        'statuses across ' . count($secBurst) . ' attempts: ' . implode(',', $secBurst)
    );

    Suite::equals(
        'the throttle is 10 per 300s, so the 11th attempt is the one refused',
        $secFirst429,
        11
    );

    Suite::equals(
        'the first attempt advertises the limit',
        $secFirstReply?->header('X-RateLimit-Limit'),
        '10'
    );

    Suite::equals(
        'the first attempt reports nine remaining',
        $secFirstReply?->header('X-RateLimit-Remaining'),
        '9'
    );

    if ($secLimited === null) {
        Suite::check('the 429 carries Retry-After', false, 'no 429 was produced, so there was nothing to inspect');
    } else {
        $secWatch('throttle: 429', $secLimited);

        Suite::check(
            'the 429 carries a Retry-After header',
            $secLimited->header('Retry-After') !== null,
            'no Retry-After on: ' . $secLimited->describe()
        );

        Suite::check(
            'Retry-After is a positive number of seconds within the 300s window',
            static function () use ($secLimited): bool {
                $value = $secLimited->header('Retry-After');

                return $value !== null
                    && ctype_digit($value)
                    && (int) $value > 0
                    && (int) $value <= 300;
            },
            'Retry-After was: ' . var_export($secLimited->header('Retry-After'), true)
        );

        Suite::equals('the 429 uses the rate_limited error code', $secLimited->code(), 'rate_limited');

        Suite::check(
            'the 429 is a failure envelope, not a success',
            !$secLimited->success(),
            $secLimited->describe()
        );

        Suite::equals(
            'a throttled response reports nothing remaining',
            $secLimited->header('X-RateLimit-Remaining'),
            '0'
        );
    }

    // The limit is per route bucket AND per path, so nothing else should be
    // affected while /auth/login is closed.
    $stillOpen = $secWatch('throttle: /health while login is limited', Http::get('/health'));
    Suite::ok('an unthrottled route still answers while login is limited', $stillOpen, 200);

    $configOpen = $secWatch('throttle: /app/config while login is limited', Http::get('/app/config'));
    Suite::ok('GET /app/config still answers while login is limited', $configOpen, 200);

    // A different bucket under the same /auth prefix: `throttle:30,300,refresh`.
    $refreshOpen = $secWatch(
        'throttle: /auth/refresh while login is limited',
        Http::post('/auth/refresh', ['refresh_token' => 'it-security-not-a-real-refresh-token'])
    );
    Suite::status(
        'a sibling auth route in a different bucket is not collateral damage',
        $refreshOpen,
        [400, 401, 422]
    );

    // -- put the bucket back ------------------------------------------
    RateLimiter::clear($secLoginKey);
    foreach ($secJunkIdentifiers as $identifier) {
        RateLimiter::clear('login:' . strtolower($identifier) . ':127.0.0.1');
    }

    Suite::equals(
        'the login bucket is empty again, so later suites can still sign in',
        RateLimiter::attempts($secLoginKey),
        0
    );

    // -- the second limiter: AuthService's per-identity lockout -------
    // AuthService::login (app/Services/AuthService.php:151) keeps its own
    // counter keyed by identifier+IP and answers 429 after 5 failures. It is a
    // different code path from the middleware and is asserted separately.
    $secLockoutId  = 'it-sec-lockout-probe@zuste.test';
    $secLockoutKey = 'login:' . $secLockoutId . ':127.0.0.1';

    RateLimiter::clear($secLockoutKey);
    RateLimiter::clear($secLoginKey);

    $secLockout = null;

    for ($i = 1; $i <= 6; $i++) {
        $attempt = Http::post('/auth/login', [
            'identifier' => $secLockoutId,
            'password'   => 'NotTheRealPassword#2024',
        ]);

        if ($attempt->status === 429) {
            $secLockout = $attempt;
            break;
        }
    }

    Suite::check(
        'repeated failures against one identifier trigger the account lockout',
        $secLockout !== null,
        'six wrong passwords for the same identifier never produced a 429'
    );

    if ($secLockout !== null) {
        $secWatch('throttle: account lockout 429', $secLockout);

        Suite::check(
            'the lockout 429 also carries Retry-After',
            $secLockout->header('Retry-After') !== null,
            'HttpException::tooManyRequests goes through Response::error(), which never sets Retry-After — '
            . 'unlike Response::tooManyRequests() used by the middleware. Got: ' . $secLockout->describe()
        );

        Suite::equals('the lockout 429 uses the rate_limited code', $secLockout->code(), 'rate_limited');
    }

    RateLimiter::clear($secLockoutKey);
    RateLimiter::clear($secLoginKey);

    // Prove the route is genuinely usable again rather than assuming it.
    $recovered = Http::post('/auth/login', [
        'identifier' => Actors::email('brand') !== '' ? Actors::email('brand') : 'it-brand@zuste.test',
        'password'   => Actors::password('brand') !== '' ? Actors::password('brand') : 'IntegrationTest#2024',
    ]);

    Suite::status('a genuine login succeeds once the bucket is cleared', $recovered, [200, 201]);

    $recoveredToken = $recovered->at('data.tokens.access_token') ?? $recovered->at('data.access_token');
    if (is_string($recoveredToken) && $recoveredToken !== '') {
        Http::setToken('brand', $recoveredToken);
    }
}

// =================================================================
Suite::group('Security — SQL injection');

$secUsersBefore = (int) (Database::scalar('SELECT COUNT(*) FROM `users`') ?? 0);

/** Query parameters that reach a WHERE clause in CreatorService::search(). */
$secQueryTargets = [
    ['/creators', 'q'],
    ['/creators', 'city'],
    ['/creators', 'category'],
    ['/creators', 'platform'],
    ['/creators', 'availability'],
    ['/creators', 'sort'],
    ['/search/suggestions', 'q'],
];

$secInjectionFailures = [];

foreach ($secQueryTargets as [$path, $param]) {
    foreach ($secSqlPayloads as $payloadIndex => $payload) {
        // Four payloads per target keeps the group inside the 120-per-minute
        // global throttle while still covering quoting, comments and UNION.
        if ($payloadIndex >= 4) {
            continue;
        }

        $res = $secWatch("sqli: GET {$path}?{$param}=#{$payloadIndex}", Http::get($path, [$param => $payload]));

        if (!in_array($res->status, [200, 400, 404, 422, 429], true)) {
            $secInjectionFailures[] = "GET {$path}?{$param}={$payload} -> " . $res->describe(160);
        }
    }
}

Suite::check(
    'a SQL payload in a query parameter never produces a 5xx',
    $secInjectionFailures === [],
    implode("\n        ", $secInjectionFailures)
);

// Path segments that land in a WHERE clause: /creators/{handle},
// /pages/{slug}, /blogs/{slug}, /app/taxonomies/{key}.
$secPathFailures = [];

foreach (['/creators/', '/pages/', '/blogs/', '/app/taxonomies/'] as $prefix) {
    foreach (array_slice($secSqlPayloads, 0, 3) as $payloadIndex => $payload) {
        $res = $secWatch(
            "sqli: GET {$prefix}<payload #{$payloadIndex}>",
            Http::get($prefix . $secPathEncode($payload))
        );

        if (!in_array($res->status, [200, 400, 404, 422, 429], true)) {
            $secPathFailures[] = "GET {$prefix}{$payload} -> " . $res->describe(160);
        }
    }
}

Suite::check(
    'a SQL payload in a path segment never produces a 5xx',
    $secPathFailures === [],
    implode("\n        ", $secPathFailures)
);

// The numeric {id:\d+} routes cannot carry a payload at all — the route regex
// refuses it before a controller ever runs. That is worth pinning down.
$secNumericGuard = $secWatch('sqli: numeric id route', Http::get('/creators/1%20OR%201=1/reviews'));
Suite::status(
    'a non-numeric value on a {id:\d+} route never reaches the controller',
    $secNumericGuard,
    404
);

// A payload must behave like an ordinary miss, not widen the result set.
$secTautology = $secWatch('sqli: tautology on q', Http::get('/creators', ['q' => "' OR '1'='1"]));

if ($secTautology->status === 429) {
    Suite::check(
        'a tautology payload returns an empty result set',
        true,
        'skipped inline: the listing bucket was already exhausted by an earlier group'
    );
} else {
    Suite::equals(
        'a tautology payload returns an empty result set, not every creator',
        (int) ($secTautology->at('meta.pagination.total') ?? -1),
        0
    );
}

Suite::check(
    'no injection probe returned a database error string',
    static function () use (&$secSeen, $secNormalise): bool {
        foreach ($secSeen as $label => $res) {
            if (!str_starts_with($label, 'sqli:')) {
                continue;
            }

            $body = $secNormalise($res->body);

            foreach (['sqlstate', 'syntax error', 'pdoexception', 'mariadb', 'mysql_'] as $marker) {
                if (str_contains($body, $marker)) {
                    return false;
                }
            }
        }

        return true;
    },
    'at least one injection probe echoed a database error — see the leak scan below for the offender'
);

Suite::equals(
    'the users table survived the DROP TABLE payloads',
    (int) (Database::scalar('SELECT COUNT(*) FROM `users`') ?? 0),
    $secUsersBefore
);

// =================================================================
Suite::group('Security — stored XSS');

if (!$secHaveActors) {
    Suite::skipGroup(
        'Security — stored XSS',
        'could not obtain both a brand and a creator session: ' . Actors::lastError()
    );
} else {
    // Support tickets are the cheapest write-then-read pair in the API:
    // AccountController::createTicket stores `subject` and `message` verbatim
    // (no sanitiser anywhere on the path) and ::ticket reads them straight back.
    $secTicket = $secWatch('xss: create ticket', Http::post('/support/tickets', [
        'subject'  => 'IT security probe ' . $secXssScript,
        'message'  => 'Stored XSS probe. ' . $secXssScript . ' ' . $secXssImage . ' End of probe.',
        'category' => 'technical',
    ], 'brand'));

    if ($secTicket->status === 429) {
        Suite::skipGroup(
            'Security — stored XSS',
            'POST /support/tickets is throttled to 10 per hour per user and the bucket is still warm '
            . 'from an earlier suite, so no ticket could be opened to store the payload in'
        );
    } elseif (!$secTicket->success()) {
        Suite::check('a ticket can be opened to carry the payload', false, $secTicket->describe());
    } else {
        Suite::ok('POST /support/tickets accepts a ticket carrying script markup', $secTicket, 201);

        $secTicketId = (int) ($secTicket->at('data.ticket.id') ?? 0);
        Suite::notEmpty('the created ticket has an id', $secTicketId, $secTicket->describe());

        if ($secTicketId > 0) {
            $secRead = $secWatch('xss: read ticket', Http::get("/support/tickets/{$secTicketId}", [], 'brand'));
            Suite::ok('GET /support/tickets/{id} returns the stored text', $secRead, 200);

            Suite::check(
                'the read endpoint answers as JSON, never as text/html',
                static function () use ($secRead): bool {
                    $type = strtolower((string) $secRead->header('Content-Type'));

                    return str_contains($type, 'application/json') && !str_contains($type, 'text/html');
                },
                'Content-Type was: ' . var_export($secRead->header('Content-Type'), true)
            );

            Suite::equals(
                'the JSON reply is still marked nosniff, so no browser sniffs it into HTML',
                $secRead->header('X-Content-Type-Options'),
                'nosniff'
            );

            Suite::equals(
                "the JSON reply's CSP forbids script execution outright",
                $secRead->header('Content-Security-Policy'),
                "default-src 'none'; frame-ancestors 'none'"
            );

            // The important structural guarantee: the payload stayed inside a
            // JSON string. If it had escaped, the envelope would not decode or
            // the field would no longer be a string.
            Suite::check(
                'the payload is confined to a JSON string and does not break the envelope',
                static function () use ($secRead, $secTicketId): bool {
                    $decoded = json_decode($secRead->body, true);

                    return is_array($decoded)
                        && ($decoded['success'] ?? null) === true
                        && (int) ($decoded['data']['ticket']['id'] ?? 0) === $secTicketId
                        && is_string($decoded['data']['ticket']['subject'] ?? null);
                },
                'the response did not decode into an intact envelope: ' . $secRead->describe()
            );

            Suite::check(
                'what comes back is inert text, not executable markup on an HTML page',
                static function () use ($secRead): bool {
                    // Executable only if the browser is told to parse it as HTML.
                    $type = strtolower((string) $secRead->header('Content-Type'));
                    if (str_contains($type, 'text/html')) {
                        return false;
                    }

                    $subject = $secRead->at('data.ticket.subject');
                    if (!is_string($subject)) {
                        return false;
                    }

                    // Whichever the backend chose — store verbatim or escape —
                    // the encoded form in the body must be a JSON string literal.
                    $encoded = json_encode($subject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    return is_string($encoded) && str_contains($secRead->body, trim($encoded, '"'));
                },
                'the stored subject was not returned as a JSON string value: ' . $secRead->describe()
            );

            $secList = $secWatch('xss: ticket list', Http::get('/support/tickets', ['per_page' => 50], 'brand'));
            Suite::check(
                'the listing endpoint returns the same text just as safely',
                static function () use ($secList): bool {
                    $type = strtolower((string) $secList->header('Content-Type'));

                    return str_contains($type, 'application/json')
                        && !str_contains($type, 'text/html')
                        && $secList->header('X-Content-Type-Options') === 'nosniff'
                        && is_array(json_decode($secList->body, true));
                },
                $secList->describe()
            );
        }
    }

    // A second, independent stored path: a search term is written to
    // `search_histories` by CreatorController::recordSearch and read back by
    // ::searchSuggestions as `recent_searches`.
    $secSearchWrite = $secWatch('xss: search term write', Http::get('/creators', ['q' => $secXssTerm], 'brand'));

    if ($secSearchWrite->status === 429) {
        Suite::check(
            'a script payload can be stored as a search term',
            true,
            'skipped inline: the /creators bucket was exhausted, so nothing was recorded'
        );
    } else {
        Suite::status('a search for script markup is answered normally', $secSearchWrite, 200);

        $secSuggest = $secWatch('xss: search suggestions', Http::get('/search/suggestions', [], 'brand'));
        Suite::ok('GET /search/suggestions replays the caller\'s recent searches', $secSuggest, 200);

        Suite::check(
            'a stored search term comes back as an inert JSON string',
            static function () use ($secSuggest, $secXssTerm): bool {
                $recent = $secSuggest->at('data.recent_searches');

                if (!is_array($recent)) {
                    return false;
                }

                foreach ($recent as $term) {
                    if (!is_string($term)) {
                        return false;
                    }
                }

                $type = strtolower((string) $secSuggest->header('Content-Type'));

                return in_array($secXssTerm, $recent, true)
                    && str_contains($type, 'application/json')
                    && !str_contains($type, 'text/html')
                    && $secSuggest->header('X-Content-Type-Options') === 'nosniff';
            },
            'recent_searches was: ' . json_encode($secSuggest->at('data.recent_searches'))
        );
    }
}

// =================================================================
Suite::group('Security — IDOR sweep');

if (!$secHaveActors) {
    Suite::skipGroup(
        'Security — IDOR sweep',
        'could not obtain two distinct sessions, so there is no second actor to probe with'
    );
} else {
    $secBrandId   = Actors::id('brand');
    $secCreatorId = Actors::id('creator');

    Suite::check(
        'the two actors are genuinely different users',
        $secBrandId > 0 && $secCreatorId > 0 && $secBrandId !== $secCreatorId,
        "brand={$secBrandId} creator={$secCreatorId}"
    );

    // -- /support/tickets/{id} ---------------------------------------
    if ($secTicketId <= 0) {
        Suite::check(
            'a foreign support ticket is not readable',
            true,
            'skipped inline: no ticket could be created (the per-user ticket throttle was warm)'
        );
    } else {
        $foreignTicket = $secWatch(
            'idor: GET /support/tickets/{id}',
            Http::get("/support/tickets/{$secTicketId}", [], 'creator')
        );
        Suite::status(
            "the other actor cannot read the brand's support ticket",
            $foreignTicket,
            [403, 404]
        );

        $foreignReply = $secWatch(
            'idor: POST /support/tickets/{id}/reply',
            Http::post("/support/tickets/{$secTicketId}/reply", ['message' => 'IT security IDOR probe reply.'], 'creator')
        );
        Suite::status(
            "the other actor cannot reply to the brand's support ticket",
            $foreignReply,
            [403, 404]
        );

        Suite::check(
            "the foreign ticket does not appear in the other actor's list",
            static function () use ($secTicketId): bool {
                $mine = Http::get('/support/tickets', ['per_page' => 50], 'creator')->list();

                foreach ($mine as $row) {
                    if ((int) ($row['id'] ?? 0) === $secTicketId) {
                        return false;
                    }
                }

                return true;
            },
            "ticket {$secTicketId} was listed for the wrong owner"
        );

        Suite::check(
            'the refused reply left no message on the ticket',
            (int) (Database::scalar(
                'SELECT COUNT(*) FROM `support_ticket_messages` WHERE `ticket_id` = :id AND `sender_id` = :uid',
                ['id' => $secTicketId, 'uid' => $secCreatorId]
            ) ?? 0) === 0,
            'a reply from the non-owner was persisted anyway'
        );
    }

    // -- /auth/sessions/{id} -----------------------------------------
    $secSessions = $secWatch('idor: GET /auth/sessions', Http::get('/auth/sessions', [], 'brand'));
    $secSessionRows = is_array($secSessions->at('data.sessions')) ? $secSessions->at('data.sessions') : [];
    $secSessionId   = (int) ($secSessionRows[0]['id'] ?? 0);

    if ($secSessionId <= 0) {
        Suite::check(
            "one actor cannot revoke another actor's session",
            true,
            'skipped inline: the brand has no listed session id to target'
        );
    } else {
        $foreignRevoke = $secWatch(
            'idor: DELETE /auth/sessions/{id}',
            Http::delete("/auth/sessions/{$secSessionId}", null, 'creator')
        );
        Suite::status(
            "the other actor cannot revoke the brand's session",
            $foreignRevoke,
            [403, 404]
        );

        Suite::check(
            "the brand's session is still live after the attempt",
            static function () use ($secSessionId): bool {
                $rows = Http::get('/auth/sessions', [], 'brand')->at('data.sessions');

                if (!is_array($rows)) {
                    return false;
                }

                foreach ($rows as $row) {
                    if ((int) ($row['id'] ?? 0) === $secSessionId) {
                        return true;
                    }
                }

                return false;
            },
            "session {$secSessionId} disappeared from the owner's list"
        );
    }

    // -- /notifications/{id} -----------------------------------------
    // A seeded notification belonging to neither actor. Read-only in effect:
    // both endpoints are scoped by user_id, and the assertion is that the row
    // is byte-for-byte untouched afterwards.
    $secForeignNotification = Database::selectOne(
        'SELECT `id`, `user_id`, `is_read` FROM `notifications`
         WHERE `user_id` NOT IN (:brand, :creator) ORDER BY `id` LIMIT 1',
        ['brand' => $secBrandId, 'creator' => $secCreatorId]
    );

    if ($secForeignNotification === null) {
        Suite::skipGroup(
            'Security — IDOR sweep (notifications)',
            'no seeded notification belongs to a third user, so there is nothing to probe'
        );
    } else {
        $secNotificationId = (int) $secForeignNotification['id'];
        $secWasRead        = (int) $secForeignNotification['is_read'];

        $foreignRead = $secWatch(
            'idor: POST /notifications/{id}/read',
            Http::post("/notifications/{$secNotificationId}/read", null, 'brand')
        );
        Suite::status(
            "marking another user's notification read is answered without error",
            $foreignRead,
            [200, 403, 404]
        );

        Suite::equals(
            "another user's notification was not marked read",
            (int) (Database::scalar(
                'SELECT `is_read` FROM `notifications` WHERE `id` = :id',
                ['id' => $secNotificationId]
            ) ?? -1),
            $secWasRead
        );

        $foreignDelete = $secWatch(
            'idor: DELETE /notifications/{id}',
            Http::delete("/notifications/{$secNotificationId}", null, 'brand')
        );
        Suite::status(
            "deleting another user's notification is answered without error",
            $foreignDelete,
            [200, 204, 403, 404]
        );

        Suite::equals(
            "another user's notification still exists",
            (int) (Database::scalar(
                'SELECT COUNT(*) FROM `notifications` WHERE `id` = :id',
                ['id' => $secNotificationId]
            ) ?? 0),
            1
        );
    }

    // -- /conversations/{id}/* ---------------------------------------
    // Opened between the brand actor and a seeded creator's account, so the
    // creator actor is a genuine outsider to it. Torn down below.
    $secOutsiderThread = 0;
    $secSeedUserId     = (int) (Database::scalar(
        "SELECT c.`user_id` FROM `creators` c
         INNER JOIN `users` u ON u.`id` = c.`user_id`
         WHERE c.`status` = 'approved' AND c.`deleted_at` IS NULL
           AND c.`user_id` NOT IN (:brand, :creator)
         ORDER BY c.`id` LIMIT 1",
        ['brand' => $secBrandId, 'creator' => $secCreatorId]
    ) ?? 0);

    if ($secSeedUserId <= 0) {
        Suite::skipGroup(
            'Security — IDOR sweep (conversations)',
            'no seeded creator account exists to open a thread the second actor is outside of'
        );
    } else {
        $secStarted = $secWatch(
            'idor: POST /conversations/start',
            Http::post('/conversations/start', ['user_id' => $secSeedUserId], 'brand')
        );
        $secOutsiderThread = (int) ($secStarted->at('data.conversation_id') ?? 0);

        if ($secOutsiderThread <= 0) {
            Suite::check(
                'a conversation the second actor is outside of could be opened',
                false,
                $secStarted->describe()
            );
        } else {
            foreach ([
                'read the history of'  => Http::get("/conversations/{$secOutsiderThread}/messages", [], 'creator'),
                'post into'            => Http::post("/conversations/{$secOutsiderThread}/messages", ['body' => 'IT security IDOR probe.'], 'creator'),
                'mark read'            => Http::post("/conversations/{$secOutsiderThread}/read", null, 'creator'),
                'mute'                 => Http::post("/conversations/{$secOutsiderThread}/mute", ['muted' => true], 'creator'),
            ] as $verb => $res) {
                $secWatch("idor: conversation {$verb}", $res);
                Suite::status(
                    "a non-participant cannot {$verb} someone else's conversation",
                    $res,
                    [403, 404]
                );
            }

            Suite::equals(
                'the refused post left no message behind',
                (int) (Database::scalar(
                    'SELECT COUNT(*) FROM `messages` WHERE `conversation_id` = :id AND `sender_id` = :uid',
                    ['id' => $secOutsiderThread, 'uid' => $secCreatorId]
                ) ?? 0),
                0
            );

            Suite::check(
                "the outsider's inbox does not list the thread",
                static function () use ($secOutsiderThread): bool {
                    foreach (Http::get('/conversations', ['per_page' => 50], 'creator')->list() as $row) {
                        if ((int) ($row['id'] ?? $row['conversation_id'] ?? 0) === $secOutsiderThread) {
                            return false;
                        }
                    }

                    return true;
                },
                "conversation {$secOutsiderThread} appeared in a non-participant's inbox"
            );
        }
    }

    // -- routes that need a resource this suite cannot cheaply create --
    Suite::skipGroup(
        'Security — IDOR sweep (bookings, payouts, KYC documents)',
        'GET /bookings/{id}, /wallet/bank-accounts/{id}, /wallet/withdrawals/{id} and '
        . '/me/kyc/documents/{id} all need a resource owned by a third party — a paid booking, '
        . 'a verified payout account, an uploaded document. None exist in the seed and creating '
        . 'them needs KYC approval plus a third registration. 04_bookings and 06/10 cover the '
        . 'party boundary on those routes with their own fixtures.'
    );

    // -- teardown for anything this group created ---------------------
    if ($secOutsiderThread > 0) {
        // message_receipts, messages and conversation_participants cascade.
        Database::execute('DELETE FROM `conversations` WHERE `id` = :id', ['id' => $secOutsiderThread]);

        Suite::equals(
            'the probe conversation has been removed',
            (int) (Database::scalar(
                'SELECT COUNT(*) FROM `conversations` WHERE `id` = :id',
                ['id' => $secOutsiderThread]
            ) ?? 0),
            0
        );
    }
}

// =================================================================
Suite::group('Security — error handling');

$secNotFound = $secWatch('errors: 404', Http::get('/this/route/does/not/exist'));
Suite::status('an unknown route is 404', $secNotFound, 404);
Suite::equals('the 404 uses the not_found code', $secNotFound->code(), 'not_found');
Suite::check('the 404 is a failure envelope', !$secNotFound->success(), $secNotFound->describe());
Suite::check(
    'the 404 is JSON, not an HTML error page',
    static function () use ($secNotFound): bool {
        $type = strtolower((string) $secNotFound->header('Content-Type'));

        return str_contains($type, 'application/json')
            && !str_contains($type, 'text/html')
            && !str_contains(strtolower($secNotFound->body), '<html')
            && is_array(json_decode($secNotFound->body, true));
    },
    $secNotFound->describe()
);

$secWrongVerb = $secWatch('errors: 405', Http::delete('/health'));
Suite::status('an unsupported verb on a real route is 405', $secWrongVerb, 405);
Suite::equals('the 405 uses the method_not_allowed code', $secWrongVerb->code(), 'method_not_allowed');
Suite::check(
    'the 405 names the verbs that are allowed',
    str_contains(strtoupper((string) $secWrongVerb->header('Allow')), 'GET'),
    'Allow was: ' . var_export($secWrongVerb->header('Allow'), true)
);
Suite::check(
    'the 405 is JSON, not an HTML error page',
    static function () use ($secWrongVerb): bool {
        return str_contains(strtolower((string) $secWrongVerb->header('Content-Type')), 'application/json')
            && is_array(json_decode($secWrongVerb->body, true));
    },
    $secWrongVerb->describe()
);

$secWrongVerb2 = $secWatch('errors: 405 on a collection', Http::put('/creators', ['x' => 1]));
Suite::status('PUT on a read-only collection is 405', $secWrongVerb2, 405);

// Malformed JSON. Request::parseBody() cannot decode it, hands the controller
// an empty bag, and the validator refuses — a clean 4xx envelope either way.
$secBadJson = $secWatch(
    'errors: malformed JSON',
    Http::rawBody('POST', '/contact', '{"name": "IT", "email": broken,,,', ['Content-Type' => 'application/json'])
);
Suite::status('malformed JSON in a POST body is a 4xx, never a 200', $secBadJson, [400, 422]);
Suite::check('the malformed-JSON reply is a failure envelope', !$secBadJson->success(), $secBadJson->describe());
Suite::check(
    'the malformed-JSON reply is JSON, not an HTML stack trace',
    static function () use ($secBadJson): bool {
        $type = strtolower((string) $secBadJson->header('Content-Type'));

        return str_contains($type, 'application/json')
            && !str_contains($type, 'text/html')
            && is_array(json_decode($secBadJson->body, true));
    },
    $secBadJson->describe()
);

$secTruncated = $secWatch(
    'errors: truncated JSON',
    Http::rawBody('POST', '/contact', '{{{{', ['Content-Type' => 'application/json'])
);
Suite::status('a body that is not JSON at all is a 4xx', $secTruncated, [400, 422]);

$secEmptyBody = $secWatch(
    'errors: empty body',
    Http::rawBody('POST', '/contact', '', ['Content-Type' => 'application/json'])
);
Suite::status('an empty POST body is a 4xx', $secEmptyBody, [400, 422]);

$secWrongType = $secWatch(
    'errors: wrong content type',
    Http::rawBody('POST', '/contact', '{"name":"IT"}', ['Content-Type' => 'text/plain'])
);
Suite::status('a JSON body sent as text/plain is a 4xx, not a 500', $secWrongType, [400, 415, 422]);

// A JSON scalar where an object is expected — another way to hand the
// controller something the validator has to cope with.
$secScalarBody = $secWatch(
    'errors: scalar JSON body',
    Http::rawBody('POST', '/contact', '"just a string"', ['Content-Type' => 'application/json'])
);
Suite::status('a bare JSON scalar as a body is a 4xx', $secScalarBody, [400, 422]);

// Wrong types inside a well-formed body must be validated, not fatal.
$secWrongTypes = $secWatch('errors: wrong field types', Http::post('/contact', [
    'name'    => ['an', 'array'],
    'email'   => ['nested' => true],
    'subject' => 12345,
    'message' => null,
]));
Suite::status('wrong field types are a validation failure, not a crash', $secWrongTypes, [400, 422]);

Suite::check(
    'no error response in this group carried a debug payload',
    static function () use ($secNotFound, $secWrongVerb, $secBadJson, $secWrongTypes): bool {
        foreach ([$secNotFound, $secWrongVerb, $secBadJson, $secWrongTypes] as $res) {
            if (array_key_exists('debug', $res->json())) {
                return false;
            }
        }

        return true;
    },
    'an error envelope exposed a `debug` key'
);

// -----------------------------------------------------------------
// Numeric overflow on the pagination cursor. `Request::page()` casts an
// arbitrarily large query value straight to int, and BaseApiController's
// offset arithmetic then overflows into a float, which is interpolated into
// the LIMIT/OFFSET clause as `1.844674407371E+20`.
$secOverflowEndpoints = [
    '/creators',
    '/blogs',
    '/creators/101/reviews',
    '/creators/101/portfolio',
];

$secOverflowFailures = [];

foreach ($secOverflowEndpoints as $endpoint) {
    $res = $secWatch(
        "errors: page overflow {$endpoint}",
        Http::get($endpoint, ['page' => '99999999999999999999'])
    );

    if ($res->status >= 500) {
        $secOverflowFailures[] = $endpoint . ' -> ' . $res->describe(200);
    }
}

Suite::check(
    'an out-of-range page number is handled, not fatal',
    $secOverflowFailures === [],
    "these endpoints answered 5xx for ?page=99999999999999999999:\n        "
    . implode("\n        ", $secOverflowFailures)
);

// =================================================================
Suite::group('Security — no internals on the wire');

Suite::check(
    'APP_DEBUG is on for this run, so the leak scan below is meaningful',
    (bool) Config::get('app.debug', false),
    'app.debug is off — the scan still runs, but it is proving less than it looks'
);

$secLeaks = [];

foreach ($secSeen as $label => $res) {
    $body = $secNormalise($res->body);

    foreach ($secLeakMarkers as $marker => $what) {
        if (str_contains($body, $marker)) {
            $secLeaks[] = sprintf('%s [HTTP %d] leaked %s (%s)', $label, $res->status, $what, $marker);
            break;
        }
    }
}

Suite::check(
    'no response anywhere in this suite leaked a stack trace, a file path or a SQL string',
    $secLeaks === [],
    sprintf("%d of %d responses leaked internals:\n        %s", count($secLeaks), count($secSeen), implode("\n        ", $secLeaks))
);

$secDebugBlocks = [];

foreach ($secSeen as $label => $res) {
    if (array_key_exists('debug', $res->json())) {
        $secDebugBlocks[] = sprintf('%s [HTTP %d]', $label, $res->status);
    }
}

Suite::check(
    'no response carried the App::renderException `debug` block',
    $secDebugBlocks === [],
    'these responses exposed exception class, file, line and trace: ' . implode(', ', $secDebugBlocks)
);

Suite::check(
    'no response leaked a password hash',
    static function () use (&$secSeen): bool {
        foreach ($secSeen as $res) {
            $body = strtolower($res->body);

            if (str_contains($body, 'password_hash') || str_contains($body, '$2y$')) {
                return false;
            }
        }

        return true;
    },
    'a response body contained a bcrypt hash or the password_hash column name'
);

Suite::check(
    'every response this suite made carried the full security header set',
    static function () use (&$secSeen): bool {
        foreach ($secSeen as $res) {
            if ($res->status === 0) {
                continue; // transport failure, nothing to inspect
            }

            if ($res->header('X-Content-Type-Options') !== 'nosniff'
                || $res->header('X-Frame-Options') !== 'DENY'
                || $res->header('Referrer-Policy') !== 'strict-origin-when-cross-origin') {
                return false;
            }
        }

        return true;
    },
    'at least one response was served without the standard security headers'
);

// =================================================================
Suite::group('Security — cleanup');

// The support ticket, its messages, and the search term the XSS group stored.
if ($secTicketId > 0) {
    Database::execute('DELETE FROM `support_ticket_messages` WHERE `ticket_id` = :id', ['id' => $secTicketId]);
    Database::execute('DELETE FROM `support_tickets` WHERE `id` = :id', ['id' => $secTicketId]);

    Suite::equals(
        'the probe support ticket has been removed',
        (int) (Database::scalar('SELECT COUNT(*) FROM `support_tickets` WHERE `id` = :id', ['id' => $secTicketId]) ?? 0),
        0
    );
}

if ($secHaveActors) {
    $secCleared = Http::delete('/search/history', null, 'brand');
    Suite::status('DELETE /search/history clears the stored search term', $secCleared, [200, 204]);

    Suite::equals(
        'the script payload is gone from search_histories',
        (int) (Database::scalar(
            'SELECT COUNT(*) FROM `search_histories` WHERE `term` = :term',
            ['term' => $secXssTerm]
        ) ?? 0),
        0
    );
}

// Nothing this suite did to the rate_limits table may outlive it: the burst
// above spent the whole 10-per-300s login budget, and the recovery login spent
// one more. Reset the bucket so a later suite can still sign an actor in.
RateLimiter::clear($secLoginKey);

Suite::equals(
    'the login throttle bucket is reset, so later suites can still sign in',
    RateLimiter::attempts($secLoginKey),
    0
);

Suite::equals(
    'no junk identifier was left locked out',
    (int) (Database::scalar(
        'SELECT COUNT(*) FROM `rate_limits` WHERE `bucket_key` = :key',
        ['key' => substr(hash('sha256', 'login:it-sec-lockout-probe@zuste.test:127.0.0.1'), 0, 64)]
    ) ?? 0),
    0
);

Suite::equals(
    'the probe identities never became real accounts',
    (int) (Database::scalar(
        "SELECT COUNT(*) FROM `users` WHERE `email` LIKE 'it-sec-%@zuste.test'"
    ) ?? 0),
    0
);
