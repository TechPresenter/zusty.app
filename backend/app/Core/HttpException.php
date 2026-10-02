<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * A domain error that maps cleanly onto an HTTP status.
 *
 * Services throw this instead of returning error arrays, so a controller
 * never has to branch on failure paths — the kernel converts it into the
 * standard JSON error envelope.
 */
class HttpException extends \RuntimeException
{
    /**
     * Seconds the caller should wait before retrying.
     *
     * Only meaningful on a 429. Not readonly because
     * {@see self::tooManyRequests()} sets it after construction.
     */
    private ?int $retryAfter = null;

    /** @param array<string,list<string>> $errors */
    public function __construct(
        string $message,
        int $status = 400,
        private readonly string $errorCode = 'error',
        private readonly array $errors = []
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode() >= 400 && $this->getCode() < 600 ? (int) $this->getCode() : 400;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function badRequest(string $message, string $code = 'bad_request'): self
    {
        return new self($message, 400, $code);
    }

    public static function unauthorized(string $message = 'Authentication required.', string $code = 'unauthenticated'): self
    {
        return new self($message, 401, $code);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self($message, 403, 'forbidden');
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self($message, 404, 'not_found');
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self($message, 409, $code);
    }

    public static function unprocessable(string $message, string $code = 'unprocessable'): self
    {
        return new self($message, 422, $code);
    }

    /**
     * @param int $retryAfter seconds the caller should wait, surfaced as the
     *                        `Retry-After` header by the exception renderer.
     */
    public static function tooManyRequests(string $message = 'Too many requests.', int $retryAfter = 60): self
    {
        $exception = new self($message, 429, 'rate_limited');
        $exception->retryAfter = max(1, $retryAfter);

        return $exception;
    }

    /** Seconds to wait, when this is a 429. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public static function server(string $message = 'An unexpected error occurred.'): self
    {
        return new self($message, 500, 'server_error');
    }
}
