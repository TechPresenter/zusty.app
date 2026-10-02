<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Thrown when validation fails. The kernel renders it as HTTP 422 with the
 * per-field error bag the Flutter forms and admin panel expect.
 */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.'
    ) {
        parent::__construct($message, 422);
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Convenience for raising a single-field failure from business logic. */
    public static function forField(string $field, string $message): self
    {
        return new self([$field => [$message]], $message);
    }
}
