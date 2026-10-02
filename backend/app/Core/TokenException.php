<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Thrown for every JWT failure.
 *
 * `$expired` lets the API answer 401 with the `token_expired` code so the
 * client knows to silently refresh instead of logging the user out.
 */
final class TokenException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $expired = false)
    {
        parent::__construct($message, 401);
    }
}
