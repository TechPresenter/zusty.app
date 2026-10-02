<?php

declare(strict_types=1);

namespace Zuste\Helpers;

use Zuste\Core\Crypto;
use Zuste\Core\Session;

/**
 * Synchroniser-token CSRF protection for the admin panel.
 *
 * A per-session secret is kept server-side; every rendered form and AJAX call
 * carries a token derived from it. Tokens are compared in constant time.
 */
final class Csrf
{
    private const SESSION_KEY = '__csrf_token';
    private const FIELD_NAME  = '_csrf';
    private const HEADER_NAME = 'x-csrf-token';

    /** Current token, generated on first use. */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = Crypto::randomToken(32);
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** Hidden input to drop inside every admin `<form>`. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            Str::e(self::token())
        );
    }

    /** `<meta>` tag so the admin JS can attach the header to fetch() calls. */
    public static function metaTag(): string
    {
        return sprintf('<meta name="csrf-token" content="%s">', Str::e(self::token()));
    }

    public static function verify(?string $candidate): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '' || $candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    /** Rotate the token — call after login and after a privilege change. */
    public static function rotate(): string
    {
        $token = Crypto::randomToken(32);
        Session::set(self::SESSION_KEY, $token);

        return $token;
    }

    public static function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    public static function headerName(): string
    {
        return self::HEADER_NAME;
    }
}
