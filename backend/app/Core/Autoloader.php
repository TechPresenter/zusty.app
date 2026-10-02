<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * PSR-4 autoloader.
 *
 * Composer is not available on Hostinger shared hosting (and the brief forbids
 * it), so this is a small replacement. One registered prefix means exactly one
 * is_file() probe per class, and spl_autoload_register never asks twice for the
 * same class in a process, so no class map is needed.
 */
final class Autoloader
{
    /** @var array<string,string> namespace prefix => base directory */
    private array $prefixes = [];

    public function addNamespace(string $prefix, string $baseDirectory): self
    {
        $this->prefixes[trim($prefix, '\\') . '\\'] = rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR;

        return $this;
    }

    public function register(): self
    {
        spl_autoload_register([$this, 'load'], true, false);

        return $this;
    }

    public function load(string $class): bool
    {
        foreach ($this->prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file     = $directory . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require $file;

                return true;
            }
        }

        return false;
    }
}
